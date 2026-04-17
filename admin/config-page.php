<?php
if (!defined('ABSPATH')) {
    exit;
}

function ahx_wp_wordle_default_words() {
    return array(
        'apfel', 'blume', 'tiger', 'lampe', 'wolke', 'stern', 'feder', 'gabel', 'hafen', 'insel',
        'jacke', 'kanal', 'laser', 'mantel', 'nadel', 'pilze', 'quarz', 'radar', 'salat', 'tafel',
        'vogel', 'walze', 'xenon', 'yacht', 'zebra', 'anker', 'beere', 'dachs', 'engel', 'flock',
        'hebel', 'joker', 'kiste', 'linse', 'mauer', 'nacht', 'olive', 'probe', 'rauch', 'samen',
        'tempo', 'urban', 'wiese', 'zange', 'frost', 'glanz', 'kabel', 'minen', 'perle', 'regen'
    );
}

function ahx_wp_wordle_words_table() {
    global $wpdb;
    return $wpdb->prefix . 'ahx_wp_wordle_words';
}

function ahx_wp_wordle_history_table() {
    global $wpdb;
    return $wpdb->prefix . 'ahx_wp_wordle_history';
}

function ahx_wp_wordle_normalize_language_code($language_code) {
    $language_code = preg_replace('/[^A-Za-z_]/', '', (string) $language_code);
    if ($language_code === '') {
        $language_code = 'de_DE';
    }

    $parts = explode('_', $language_code);
    if (count($parts) === 2) {
        return strtolower($parts[0]) . '_' . strtoupper($parts[1]);
    }

    if (strlen($language_code) === 2) {
        return strtolower($language_code) . '_' . strtoupper($language_code);
    }

    return 'de_DE';
}

function ahx_wp_wordle_get_language_letter_class($language_code) {
    $language_code = ahx_wp_wordle_normalize_language_code($language_code);
    $base_language = strtolower(substr($language_code, 0, 2));

    if ($base_language === 'de') {
        return 'a-zäöüß';
    }

    return 'a-z';
}

function ahx_wp_wordle_mb_strlen($value) {
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen((string) $value, 'UTF-8');
    }

    return (int) strlen((string) $value);
}

function ahx_wp_wordle_mb_strtolower($value) {
    if (function_exists('mb_strtolower')) {
        return (string) mb_strtolower((string) $value, 'UTF-8');
    }

    return (string) strtolower((string) $value);
}

function ahx_wp_wordle_normalize_single_word($word, $language_code) {
    $letters = ahx_wp_wordle_get_language_letter_class($language_code);
    $normalized = ahx_wp_wordle_mb_strtolower(trim((string) $word));
    $normalized = preg_replace('/[^' . $letters . ']/u', '', $normalized);

    if (ahx_wp_wordle_mb_strlen($normalized) !== 5) {
        return '';
    }

    return $normalized;
}

function ahx_wp_wordle_parse_languages($raw) {
    $tokens = preg_split('/[\s,;]+/', (string) $raw);
    $languages = array();

    foreach ($tokens as $token) {
        $token = trim((string) $token);
        if ($token === '') {
            continue;
        }

        $code = ahx_wp_wordle_normalize_language_code($token);
        $languages[$code] = $code;
    }

    if (empty($languages)) {
        $languages['de_DE'] = 'de_DE';
    }

    return array_values($languages);
}

function ahx_wp_wordle_get_possible_languages() {
    $stored = get_option('ahx_wp_wordle_languages', 'de_DE');
    if (!is_string($stored) || trim($stored) === '') {
        $stored = 'de_DE';
    }

    return ahx_wp_wordle_parse_languages($stored);
}

function ahx_wp_wordle_set_possible_languages($languages) {
    $languages = ahx_wp_wordle_parse_languages(implode(', ', (array) $languages));
    update_option('ahx_wp_wordle_languages', implode(', ', $languages));

    $default_language = ahx_wp_wordle_normalize_language_code((string) get_option('ahx_wp_wordle_default_language', 'de_DE'));
    if (!in_array($default_language, $languages, true)) {
        update_option('ahx_wp_wordle_default_language', $languages[0]);
    }
}

function ahx_wp_wordle_get_language_usage($language_code) {
    global $wpdb;

    $language_code = ahx_wp_wordle_normalize_language_code($language_code);
    $words_table = ahx_wp_wordle_words_table();
    $history_table = ahx_wp_wordle_history_table();

    $words_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$words_table} WHERE language_code = %s",
            $language_code
        )
    );

    $history_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$history_table} WHERE language_code = %s",
            $language_code
        )
    );

    return array(
        'words' => $words_count,
        'history' => $history_count,
    );
}

function ahx_wp_wordle_handle_add_language() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_wordle_manage_languages');

    $new_language = ahx_wp_wordle_normalize_language_code(wp_unslash($_POST['ahx_wp_wordle_new_language'] ?? ''));
    $languages = ahx_wp_wordle_get_possible_languages();

    $status = 'added';
    if (in_array($new_language, $languages, true)) {
        $status = 'exists';
    } else {
        $languages[] = $new_language;
        ahx_wp_wordle_set_possible_languages($languages);
    }

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-wordle-config',
            'lang_manage' => $status,
            'lang_code' => $new_language,
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_ahx_wp_wordle_add_language', 'ahx_wp_wordle_handle_add_language');

function ahx_wp_wordle_handle_delete_language() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_wordle_manage_languages');

    $language_code = ahx_wp_wordle_normalize_language_code(wp_unslash($_POST['ahx_wp_wordle_delete_language'] ?? ''));
    $languages = ahx_wp_wordle_get_possible_languages();

    $status = 'deleted';
    $usage = ahx_wp_wordle_get_language_usage($language_code);

    if (!in_array($language_code, $languages, true)) {
        $status = 'not_found';
    } elseif (count($languages) <= 1) {
        $status = 'last_language';
    } elseif ($usage['words'] > 0 || $usage['history'] > 0) {
        $status = 'blocked_in_use';
    } else {
        $languages = array_values(array_filter($languages, function ($lang) use ($language_code) {
            return $lang !== $language_code;
        }));
        ahx_wp_wordle_set_possible_languages($languages);
    }

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-wordle-config',
            'lang_manage' => $status,
            'lang_code' => $language_code,
            'lang_words' => (string) ((int) $usage['words']),
            'lang_history' => (string) ((int) $usage['history']),
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_ahx_wp_wordle_delete_language', 'ahx_wp_wordle_handle_delete_language');

function ahx_wp_wordle_normalize_words($raw, $language_code = 'de_DE') {
    $lines = preg_split('/\R+/', (string) $raw);
    $valid = array();

    foreach ($lines as $line) {
        $word = ahx_wp_wordle_normalize_single_word($line, $language_code);

        if ($word !== '') {
            $valid[$word] = $word;
        }
    }

    return array_values($valid);
}

function ahx_wp_wordle_parse_csv_words($tmp_file_path, $language_code = 'de_DE') {
    if (!file_exists($tmp_file_path)) {
        return array();
    }

    $content = file_get_contents($tmp_file_path);
    if (!is_string($content) || $content === '') {
        return array();
    }

    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $lines = preg_split('/\R+/', $content);
    $words = array();

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        $parsed = str_getcsv($line, ';');
        if (count($parsed) < 1) {
            continue;
        }

        $candidate = trim((string) ($parsed[0] ?? ''));
        if (strpos($candidate, ',') !== false && count($parsed) === 1) {
            $parsed = str_getcsv($line, ',');
            $candidate = trim((string) ($parsed[0] ?? ''));
        }

        $normalized = ahx_wp_wordle_normalize_words($candidate, $language_code);
        if (!empty($normalized)) {
            $words[$normalized[0]] = $normalized[0];
        }
    }

    return array_values($words);
}

function ahx_wp_wordle_parse_bulk_words($raw, $language_code = 'de_DE') {
    $tokens = preg_split('/[\s,;]+/u', (string) $raw);
    $words = array();

    if (!is_array($tokens)) {
        return array();
    }

    foreach ($tokens as $token) {
        $normalized = ahx_wp_wordle_normalize_single_word($token, $language_code);
        if ($normalized === '') {
            continue;
        }

        $words[$normalized] = $normalized;
    }

    return array_values($words);
}

function ahx_wp_wordle_install_tables() {
    global $wpdb;

    $words_table = ahx_wp_wordle_words_table();
    $history_table = ahx_wp_wordle_history_table();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql_words = "CREATE TABLE {$words_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        language_code varchar(16) NOT NULL,
        word varchar(5) NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_language_word (language_code, word),
        KEY idx_language (language_code)
    ) {$charset_collate};";

    $sql_history = "CREATE TABLE {$history_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        language_code varchar(16) NOT NULL,
        play_date date NOT NULL,
        word_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_language_playdate (language_code, play_date),
        KEY idx_language_date (language_code, play_date),
        KEY idx_language_word (language_code, word_id)
    ) {$charset_collate};";

    dbDelta($sql_words);
    dbDelta($sql_history);
}

function ahx_wp_wordle_insert_words($language_code, $words) {
    global $wpdb;

    $language_code = ahx_wp_wordle_normalize_language_code($language_code);
    $words_table = ahx_wp_wordle_words_table();

    $inserted = 0;
    $duplicates = 0;

    foreach ($words as $word) {
        $word = ahx_wp_wordle_normalize_single_word($word, $language_code);
        if ($word === '') {
            continue;
        }

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$words_table} (language_code, word, created_at) VALUES (%s, %s, UTC_TIMESTAMP())",
                $language_code,
                $word
            )
        );

        if ($result === 1) {
            $inserted++;
        } else {
            $duplicates++;
        }
    }

    return array(
        'inserted' => $inserted,
        'duplicates' => $duplicates,
    );
}

function ahx_wp_wordle_seed_default_words() {
    $stats = ahx_wp_wordle_insert_words('de_DE', ahx_wp_wordle_default_words());
    return $stats;
}

function ahx_wp_wordle_get_words_by_language($language_code) {
    global $wpdb;

    $language_code = ahx_wp_wordle_normalize_language_code($language_code);
    $table = ahx_wp_wordle_words_table();

    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT word FROM {$table} WHERE language_code = %s ORDER BY word ASC",
            $language_code
        )
    );

    if (!is_array($rows)) {
        return array();
    }

    return array_values(array_map('strval', $rows));
}

function ahx_wp_wordle_get_languages_with_counts() {
    global $wpdb;

    $table = ahx_wp_wordle_words_table();
    $rows = $wpdb->get_results("SELECT language_code, COUNT(*) AS total FROM {$table} GROUP BY language_code ORDER BY language_code ASC", ARRAY_A);

    return is_array($rows) ? $rows : array();
}

function ahx_wp_wordle_pick_daily_word($language_code, $play_date) {
    global $wpdb;

    $language_code = ahx_wp_wordle_normalize_language_code($language_code);
    $words_table = ahx_wp_wordle_words_table();
    $history_table = ahx_wp_wordle_history_table();

    $all_words = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, word FROM {$words_table} WHERE language_code = %s ORDER BY id ASC",
            $language_code
        ),
        ARRAY_A
    );

    if (!is_array($all_words) || empty($all_words)) {
        return null;
    }

    $total_words = count($all_words);
    $recent_limit = min(365, $total_words);

    $recent_rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT word_id FROM {$history_table} WHERE language_code = %s ORDER BY play_date DESC, id DESC LIMIT %d",
            $language_code,
            $recent_limit
        )
    );

    $recent_ids = array();
    if (is_array($recent_rows)) {
        foreach ($recent_rows as $word_id) {
            $recent_ids[(int) $word_id] = true;
        }
    }

    $eligible = array();
    foreach ($all_words as $row) {
        $word_id = (int) $row['id'];
        if (!isset($recent_ids[$word_id])) {
            $eligible[] = $row;
        }
    }

    if (empty($eligible)) {
        $eligible = $all_words;
    }

    $hash = abs((int) crc32($language_code . '|' . $play_date));
    $selected = $eligible[$hash % count($eligible)];

    $wpdb->query(
        $wpdb->prepare(
            "INSERT IGNORE INTO {$history_table} (language_code, play_date, word_id, created_at) VALUES (%s, %s, %d, UTC_TIMESTAMP())",
            $language_code,
            $play_date,
            (int) $selected['id']
        )
    );

    $stored = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT w.id, w.word
             FROM {$history_table} h
             INNER JOIN {$words_table} w ON w.id = h.word_id
             WHERE h.language_code = %s AND h.play_date = %s
             LIMIT 1",
            $language_code,
            $play_date
        ),
        ARRAY_A
    );

    return is_array($stored) ? $stored : $selected;
}

function ahx_wp_wordle_get_or_create_daily_word($language_code, $play_date) {
    global $wpdb;

    $language_code = ahx_wp_wordle_normalize_language_code($language_code);
    $words_table = ahx_wp_wordle_words_table();
    $history_table = ahx_wp_wordle_history_table();

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT w.id, w.word
             FROM {$history_table} h
             INNER JOIN {$words_table} w ON w.id = h.word_id
             WHERE h.language_code = %s AND h.play_date = %s
             LIMIT 1",
            $language_code,
            $play_date
        ),
        ARRAY_A
    );

    if (is_array($row) && !empty($row['word'])) {
        return $row;
    }

    return ahx_wp_wordle_pick_daily_word($language_code, $play_date);
}

function ahx_wp_wordle_maybe_migrate_legacy_words() {
    $done = get_option('ahx_wp_wordle_legacy_migrated', '0');
    if ($done === '1') {
        return;
    }

    $legacy = get_option('ahx_wp_wordle_word_list', '');
    if (is_string($legacy) && trim($legacy) !== '') {
        $words = ahx_wp_wordle_normalize_words($legacy, 'de_DE');
        if (!empty($words)) {
            ahx_wp_wordle_insert_words('de_DE', $words);
        }
    }

    update_option('ahx_wp_wordle_legacy_migrated', '1');
}

function ahx_wp_wordle_handle_csv_import() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_wordle_import_csv');

    $language_code = ahx_wp_wordle_normalize_language_code(wp_unslash($_POST['ahx_wp_wordle_import_language'] ?? 'de_DE'));
    $possible_languages = ahx_wp_wordle_get_possible_languages();
    if (!in_array($language_code, $possible_languages, true)) {
        $language_code = $possible_languages[0];
    }
    $file = $_FILES['ahx_wp_wordle_csv'] ?? null;

    $message = array(
        'inserted' => 0,
        'duplicates' => 0,
        'language' => $language_code,
        'error' => '',
    );

    if (!$file || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $message['error'] = 'Bitte eine CSV-Datei auswählen.';
    } else {
        $words = ahx_wp_wordle_parse_csv_words($file['tmp_name'], $language_code);
        if (empty($words)) {
            $message['error'] = 'Keine gültigen 5-Buchstaben-Wörter für die gewählte Sprache in der CSV gefunden.';
        } else {
            $stats = ahx_wp_wordle_insert_words($language_code, $words);
            $message['inserted'] = (int) $stats['inserted'];
            $message['duplicates'] = (int) $stats['duplicates'];
        }
    }

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-wordle-config',
            'import_done' => '1',
            'import_inserted' => (string) $message['inserted'],
            'import_duplicates' => (string) $message['duplicates'],
            'import_language' => $message['language'],
            'import_error' => rawurlencode($message['error']),
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_ahx_wp_wordle_import_csv', 'ahx_wp_wordle_handle_csv_import');

function ahx_wp_wordle_handle_bulk_import() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_wordle_import_bulk');

    $language_code = ahx_wp_wordle_normalize_language_code(wp_unslash($_POST['ahx_wp_wordle_bulk_language'] ?? 'de_DE'));
    $possible_languages = ahx_wp_wordle_get_possible_languages();
    if (!in_array($language_code, $possible_languages, true)) {
        $language_code = $possible_languages[0];
    }

    $raw_words = wp_unslash($_POST['ahx_wp_wordle_bulk_words'] ?? '');
    $message = array(
        'inserted' => 0,
        'duplicates' => 0,
        'language' => $language_code,
        'error' => '',
    );

    if (!is_string($raw_words) || trim($raw_words) === '') {
        $message['error'] = 'Bitte mindestens ein Wort im Textfeld eingeben.';
    } else {
        $words = ahx_wp_wordle_parse_bulk_words($raw_words, $language_code);

        if (empty($words)) {
            $message['error'] = 'Keine gültigen 5-Buchstaben-Wörter für die gewählte Sprache im Textfeld gefunden.';
        } else {
            $stats = ahx_wp_wordle_insert_words($language_code, $words);
            $message['inserted'] = (int) $stats['inserted'];
            $message['duplicates'] = (int) $stats['duplicates'];
        }
    }

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-wordle-config',
            'bulk_done' => '1',
            'bulk_inserted' => (string) $message['inserted'],
            'bulk_duplicates' => (string) $message['duplicates'],
            'bulk_language' => $message['language'],
            'bulk_error' => rawurlencode($message['error']),
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_ahx_wp_wordle_import_bulk', 'ahx_wp_wordle_handle_bulk_import');

function ahx_wp_wordle_sanitize_default_language($value) {
    $requested = ahx_wp_wordle_normalize_language_code($value);
    $allowed = ahx_wp_wordle_get_possible_languages();

    if (isset($_POST['ahx_wp_wordle_languages'])) {
        $allowed = ahx_wp_wordle_parse_languages(wp_unslash($_POST['ahx_wp_wordle_languages']));
    }

    if (in_array($requested, $allowed, true)) {
        return $requested;
    }

    return $allowed[0];
}

function ahx_wp_wordle_sanitize_languages($value) {
    $languages = ahx_wp_wordle_parse_languages($value);
    return implode(', ', $languages);
}

function ahx_wp_wordle_sanitize_rows($value) {
    $rows = (int) $value;

    if ($rows < 4) {
        $rows = 4;
    }
    if ($rows > 10) {
        $rows = 10;
    }

    return $rows;
}

function ahx_wp_wordle_sanitize_persistence_mode($value) {
    $mode = sanitize_key((string) $value);
    $allowed = array('auto', 'server', 'local_storage');

    if (!in_array($mode, $allowed, true)) {
        return 'auto';
    }

    return $mode;
}

function ahx_wp_wordle_register_settings() {
    register_setting('ahx_wp_wordle_settings_group', 'ahx_wp_wordle_rows', array(
        'type' => 'integer',
        'sanitize_callback' => 'ahx_wp_wordle_sanitize_rows',
        'default' => 6,
    ));

    register_setting('ahx_wp_wordle_settings_group', 'ahx_wp_wordle_default_language', array(
        'type' => 'string',
        'sanitize_callback' => 'ahx_wp_wordle_sanitize_default_language',
        'default' => 'de_DE',
    ));

    register_setting('ahx_wp_wordle_settings_group', 'ahx_wp_wordle_persistence_mode', array(
        'type' => 'string',
        'sanitize_callback' => 'ahx_wp_wordle_sanitize_persistence_mode',
        'default' => 'auto',
    ));

    add_settings_section(
        'ahx_wp_wordle_main',
        'Allgemeine Einstellungen',
        '__return_null',
        'ahx_wp_wordle_settings'
    );

    add_settings_field(
        'ahx_wp_wordle_rows',
        'Anzahl Versuche',
        'ahx_wp_wordle_rows_field',
        'ahx_wp_wordle_settings',
        'ahx_wp_wordle_main'
    );

    add_settings_field(
        'ahx_wp_wordle_default_language',
        'Standard-Sprache',
        'ahx_wp_wordle_default_language_field',
        'ahx_wp_wordle_settings',
        'ahx_wp_wordle_main'
    );

    add_settings_field(
        'ahx_wp_wordle_persistence_mode',
        'Persistenzmodus',
        'ahx_wp_wordle_persistence_mode_field',
        'ahx_wp_wordle_settings',
        'ahx_wp_wordle_main'
    );

}
add_action('admin_init', 'ahx_wp_wordle_register_settings');

function ahx_wp_wordle_rows_field() {
    $value = (int) get_option('ahx_wp_wordle_rows', 6);
    echo '<input type="number" name="ahx_wp_wordle_rows" value="' . esc_attr((string) $value) . '" min="4" max="10">';
}

function ahx_wp_wordle_default_language_field() {
    $value = ahx_wp_wordle_normalize_language_code((string) get_option('ahx_wp_wordle_default_language', 'de_DE'));
    $possible_languages = ahx_wp_wordle_get_possible_languages();

    echo '<select name="ahx_wp_wordle_default_language">';
    foreach ($possible_languages as $language_code) {
        echo '<option value="' . esc_attr($language_code) . '" ' . selected($value, $language_code, false) . '>' . esc_html($language_code) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">Diese Sprache wird standardmäßig genutzt, wenn im Shortcode kein <code>lang</code> gesetzt ist.</p>';
}

function ahx_wp_wordle_persistence_mode_field() {
    $value = ahx_wp_wordle_sanitize_persistence_mode((string) get_option('ahx_wp_wordle_persistence_mode', 'auto'));

    echo '<select name="ahx_wp_wordle_persistence_mode">';
    echo '<option value="auto" ' . selected($value, 'auto', false) . '>Auto (empfohlen)</option>';
    echo '<option value="server" ' . selected($value, 'server', false) . '>Server</option>';
    echo '<option value="local_storage" ' . selected($value, 'local_storage', false) . '>localStorage</option>';
    echo '</select>';
    echo '<p class="description">' . esc_html__('Auto: Benutzer angemeldet = Server, Gäste = localStorage mit Server-Fallback.', 'ahx_wp_wordle') . '</p>';
}

function ahx_wp_wordle_settings_page() {
    $import_done = isset($_GET['import_done']) && (string) $_GET['import_done'] === '1';
    $import_error = isset($_GET['import_error']) ? rawurldecode((string) $_GET['import_error']) : '';
    $import_inserted = isset($_GET['import_inserted']) ? (int) $_GET['import_inserted'] : 0;
    $import_duplicates = isset($_GET['import_duplicates']) ? (int) $_GET['import_duplicates'] : 0;
    $import_language = isset($_GET['import_language']) ? ahx_wp_wordle_normalize_language_code(wp_unslash($_GET['import_language'])) : 'de_DE';
    $bulk_done = isset($_GET['bulk_done']) && (string) $_GET['bulk_done'] === '1';
    $bulk_error = isset($_GET['bulk_error']) ? rawurldecode((string) $_GET['bulk_error']) : '';
    $bulk_inserted = isset($_GET['bulk_inserted']) ? (int) $_GET['bulk_inserted'] : 0;
    $bulk_duplicates = isset($_GET['bulk_duplicates']) ? (int) $_GET['bulk_duplicates'] : 0;
    $bulk_language = isset($_GET['bulk_language']) ? ahx_wp_wordle_normalize_language_code(wp_unslash($_GET['bulk_language'])) : 'de_DE';
    $lang_manage = isset($_GET['lang_manage']) ? sanitize_key((string) $_GET['lang_manage']) : '';
    $lang_code = isset($_GET['lang_code']) ? ahx_wp_wordle_normalize_language_code(wp_unslash($_GET['lang_code'])) : '';
    $lang_words = isset($_GET['lang_words']) ? (int) $_GET['lang_words'] : 0;
    $lang_history = isset($_GET['lang_history']) ? (int) $_GET['lang_history'] : 0;
    $possible_languages = ahx_wp_wordle_get_possible_languages();
    $default_language = ahx_wp_wordle_normalize_language_code((string) get_option('ahx_wp_wordle_default_language', 'de_DE'));

    if (!in_array($import_language, $possible_languages, true)) {
        $import_language = $default_language;
    }
    if (!in_array($bulk_language, $possible_languages, true)) {
        $bulk_language = $default_language;
    }

    ?>
    <div class="wrap">
        <h2><?php echo esc_html__('AHX WP Wordle Einstellungen', 'ahx_wp_wordle'); ?></h2>

        <?php if ($import_done) : ?>
            <?php if ($import_error !== '') : ?>
                <div class="notice notice-error"><p><?php echo esc_html($import_error); ?></p></div>
            <?php else : ?>
                <div class="notice notice-success"><p><?php echo esc_html('CSV-Import abgeschlossen für ' . $import_language . '. Neu: ' . $import_inserted . ', Dubletten übersprungen: ' . $import_duplicates . '.'); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($bulk_done) : ?>
            <?php if ($bulk_error !== '') : ?>
                <div class="notice notice-error"><p><?php echo esc_html($bulk_error); ?></p></div>
            <?php else : ?>
                <div class="notice notice-success"><p><?php echo esc_html('Text-Import abgeschlossen für ' . $bulk_language . '. Neu: ' . $bulk_inserted . ', Dubletten übersprungen: ' . $bulk_duplicates . '.'); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($lang_manage !== '') : ?>
            <?php if ($lang_manage === 'added') : ?>
                <div class="notice notice-success"><p><?php echo esc_html('Sprache hinzugefügt: ' . $lang_code); ?></p></div>
            <?php elseif ($lang_manage === 'exists') : ?>
                <div class="notice notice-warning"><p><?php echo esc_html('Sprache existiert bereits: ' . $lang_code); ?></p></div>
            <?php elseif ($lang_manage === 'deleted') : ?>
                <div class="notice notice-success"><p><?php echo esc_html('Sprache gelöscht: ' . $lang_code); ?></p></div>
            <?php elseif ($lang_manage === 'blocked_in_use') : ?>
                <div class="notice notice-error"><p><?php echo esc_html('Sprache kann nicht gelöscht werden. Vorhandene Daten - Wörter: ' . $lang_words . ', Statistik: ' . $lang_history . '.'); ?></p></div>
            <?php elseif ($lang_manage === 'last_language') : ?>
                <div class="notice notice-error"><p><?php echo esc_html__('Die letzte verbleibende Sprache kann nicht gelöscht werden.', 'ahx_wp_wordle'); ?></p></div>
            <?php elseif ($lang_manage === 'not_found') : ?>
                <div class="notice notice-warning"><p><?php echo esc_html__('Die Sprache wurde nicht gefunden.', 'ahx_wp_wordle'); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php
            settings_fields('ahx_wp_wordle_settings_group');
            do_settings_sections('ahx_wp_wordle_settings');
            submit_button();
            ?>
        </form>

        <hr>
        <h3><?php echo esc_html__('Sprachverwaltung', 'ahx_wp_wordle'); ?></h3>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 12px;">
            <?php wp_nonce_field('ahx_wp_wordle_manage_languages'); ?>
            <input type="hidden" name="action" value="ahx_wp_wordle_add_language">
            <label for="ahx_wp_wordle_new_language"><strong><?php echo esc_html__('Sprache hinzufügen:', 'ahx_wp_wordle'); ?></strong></label>
            <input id="ahx_wp_wordle_new_language" type="text" name="ahx_wp_wordle_new_language" placeholder="z. B. en_US" class="regular-text" required>
            <?php submit_button('Sprache hinzufügen', 'secondary', 'submit', false); ?>
        </form>

        <table class="wp-list-table widefat fixed striped" style="max-width: 700px; margin-bottom: 20px;">
            <thead>
                <tr><th>Sprache</th><th>Wörter</th><th><?php echo esc_html__('Statistik', 'ahx_wp_wordle'); ?></th><th>Aktion</th></tr>
            </thead>
            <tbody>
            <?php foreach ($possible_languages as $language_code) : ?>
                <?php $usage = ahx_wp_wordle_get_language_usage($language_code); ?>
                <tr>
                    <td><?php echo esc_html($language_code); ?><?php echo $default_language === $language_code ? ' (Standard)' : ''; ?></td>
                    <td><?php echo esc_html((string) $usage['words']); ?></td>
                    <td><?php echo esc_html((string) $usage['history']); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('ahx_wp_wordle_manage_languages'); ?>
                            <input type="hidden" name="action" value="ahx_wp_wordle_delete_language">
                            <input type="hidden" name="ahx_wp_wordle_delete_language" value="<?php echo esc_attr($language_code); ?>">
                            <?php submit_button('Löschen', 'delete', 'submit', false); ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <hr>
        <h3><?php echo esc_html__('CSV-Import Wörter', 'ahx_wp_wordle'); ?></h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('ahx_wp_wordle_import_csv'); ?>
            <input type="hidden" name="action" value="ahx_wp_wordle_import_csv">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ahx_wp_wordle_import_language"><?php echo esc_html__('Sprache', 'ahx_wp_wordle'); ?></label></th>
                    <td>
                        <select id="ahx_wp_wordle_import_language" name="ahx_wp_wordle_import_language">
                            <?php foreach ($possible_languages as $language_code) : ?>
                                <option value="<?php echo esc_attr($language_code); ?>" <?php selected($import_language, $language_code); ?>><?php echo esc_html($language_code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ahx_wp_wordle_csv"><?php echo esc_html__('CSV-Datei', 'ahx_wp_wordle'); ?></label></th>
                    <td><input id="ahx_wp_wordle_csv" type="file" name="ahx_wp_wordle_csv" accept=".csv,text/csv"></td>
                </tr>
            </table>

            <?php submit_button('CSV importieren'); ?>
            <p class="description"><?php echo esc_html__('Es wird das erste Feld je Zeile gelesen. Erlaubt sind genau 5 Buchstaben gemäß gewählter Sprache (für Deutsch inkl. ä, ö, ü, ß). Dubletten werden nicht erneut importiert.', 'ahx_wp_wordle'); ?></p>
        </form>

        <hr>
        <h3><?php echo esc_html__('Wörter per Textfeld importieren', 'ahx_wp_wordle'); ?></h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('ahx_wp_wordle_import_bulk'); ?>
            <input type="hidden" name="action" value="ahx_wp_wordle_import_bulk">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ahx_wp_wordle_bulk_language"><?php echo esc_html__('Sprache', 'ahx_wp_wordle'); ?></label></th>
                    <td>
                        <select id="ahx_wp_wordle_bulk_language" name="ahx_wp_wordle_bulk_language">
                            <?php foreach ($possible_languages as $language_code) : ?>
                                <option value="<?php echo esc_attr($language_code); ?>" <?php selected($bulk_language, $language_code); ?>><?php echo esc_html($language_code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ahx_wp_wordle_bulk_words"><?php echo esc_html__('Wörter', 'ahx_wp_wordle'); ?></label></th>
                    <td>
                        <textarea id="ahx_wp_wordle_bulk_words" name="ahx_wp_wordle_bulk_words" rows="8" class="large-text" placeholder="apfel&#10;blume&#10;tiger"></textarea>
                        <p class="description"><?php echo esc_html__('Mehrere Wörter möglich, getrennt durch Zeilenumbrüche, Leerzeichen, Kommas oder Semikolons. Es werden nur gültige Wörter mit 5 Buchstaben importiert.', 'ahx_wp_wordle'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Wörter importieren'); ?>
        </form>
        <hr>

        <p><strong><?php echo esc_html__('Shortcode:', 'ahx_wp_wordle'); ?></strong> <code>[ahx_wordle]</code></p>
        <p><strong><?php echo esc_html__('Sprache überschreiben:', 'ahx_wp_wordle'); ?></strong> <code>[ahx_wordle lang="en_US"]</code></p>
    </div>
    <?php
}
