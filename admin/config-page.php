<?php
if (!defined('ABSPATH')) {
    exit;
}

function ahx_wp_polylex_default_words() {
    return array(
        'apfel', 'blume', 'tiger', 'lampe', 'wolke', 'stern', 'feder', 'gabel', 'hafen', 'insel',
        'jacke', 'kanal', 'laser', 'mantel', 'nadel', 'pilze', 'quarz', 'radar', 'salat', 'tafel',
        'vogel', 'walze', 'xenon', 'yacht', 'zebra', 'anker', 'beere', 'dachs', 'engel', 'flock',
        'hebel', 'joker', 'kiste', 'linse', 'mauer', 'nacht', 'olive', 'probe', 'rauch', 'samen',
        'tempo', 'urban', 'wiese', 'zange', 'frost', 'glanz', 'kabel', 'minen', 'perle', 'regen'
    );
}

function ahx_wp_polylex_words_table() {
    global $wpdb;
    return $wpdb->prefix . 'ahx_wp_polylex_words';
}

function ahx_wp_polylex_history_table() {
    global $wpdb;
    return $wpdb->prefix . 'ahx_wp_polylex_history';
}

function ahx_wp_polylex_unknown_words_table() {
    global $wpdb;
    return $wpdb->prefix . 'ahx_wp_polylex_unknown_words';
}

function ahx_wp_polylex_reported_words_table() {
    global $wpdb;
    return $wpdb->prefix . 'ahx_wp_polylex_reported_words';
}

function ahx_wp_polylex_normalize_language_code($language_code) {
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

function ahx_wp_polylex_get_language_letter_class($language_code) {
    $language_code = ahx_wp_polylex_normalize_language_code($language_code);
    $base_language = strtolower(substr($language_code, 0, 2));

    if ($base_language === 'de') {
        return 'a-zäöüß';
    }

    return 'a-z';
}

function ahx_wp_polylex_mb_strlen($value) {
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen((string) $value, 'UTF-8');
    }

    return (int) strlen((string) $value);
}

function ahx_wp_polylex_mb_strtolower($value) {
    if (function_exists('mb_strtolower')) {
        return (string) mb_strtolower((string) $value, 'UTF-8');
    }

    return (string) strtolower((string) $value);
}

function ahx_wp_polylex_normalize_single_word($word, $language_code) {
    $letters = ahx_wp_polylex_get_language_letter_class($language_code);
    $normalized = ahx_wp_polylex_mb_strtolower(trim((string) $word));
    $normalized = preg_replace('/[^' . $letters . ']/u', '', $normalized);

    if (ahx_wp_polylex_mb_strlen($normalized) !== 5) {
        return '';
    }

    return $normalized;
}

function ahx_wp_polylex_parse_languages($raw) {
    $tokens = preg_split('/[\s,;]+/', (string) $raw);
    $languages = array();

    foreach ($tokens as $token) {
        $token = trim((string) $token);
        if ($token === '') {
            continue;
        }

        $code = ahx_wp_polylex_normalize_language_code($token);
        $languages[$code] = $code;
    }

    if (empty($languages)) {
        $languages['de_DE'] = 'de_DE';
    }

    return array_values($languages);
}

function ahx_wp_polylex_get_possible_languages() {
    $stored = get_option('ahx_wp_polylex_languages', 'de_DE');
    if (!is_string($stored) || trim($stored) === '') {
        $stored = 'de_DE';
    }

    return ahx_wp_polylex_parse_languages($stored);
}

function ahx_wp_polylex_set_possible_languages($languages) {
    $languages = ahx_wp_polylex_parse_languages(implode(', ', (array) $languages));
    update_option('ahx_wp_polylex_languages', implode(', ', $languages));

    $default_language = ahx_wp_polylex_normalize_language_code((string) get_option('ahx_wp_polylex_default_language', 'de_DE'));
    if (!in_array($default_language, $languages, true)) {
        update_option('ahx_wp_polylex_default_language', $languages[0]);
    }
}

function ahx_wp_polylex_get_language_usage($language_code) {
    global $wpdb;

    $language_code = ahx_wp_polylex_normalize_language_code($language_code);
    $words_table = ahx_wp_polylex_words_table();
    $history_table = ahx_wp_polylex_history_table();

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

function ahx_wp_polylex_track_unknown_word_entry($language_code, $word) {
    global $wpdb;

    $language_code = ahx_wp_polylex_normalize_language_code($language_code);
    $word = ahx_wp_polylex_normalize_single_word($word, $language_code);
    if ($word === '') {
        return false;
    }

    $words_table = ahx_wp_polylex_words_table();
    $unknown_table = ahx_wp_polylex_unknown_words_table();

    $exists = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$words_table} WHERE language_code = %s AND word = %s",
            $language_code,
            $word
        )
    );

    if ($exists > 0) {
        return false;
    }

    $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$unknown_table} (language_code, word, first_seen_at, last_seen_at, seen_count)
             VALUES (%s, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1)
             ON DUPLICATE KEY UPDATE seen_count = seen_count + 1, last_seen_at = UTC_TIMESTAMP()",
            $language_code,
            $word
        )
    );

    return true;
}

function ahx_wp_polylex_get_unknown_words($language_code, $limit = 200) {
    global $wpdb;

    $language_code = ahx_wp_polylex_normalize_language_code($language_code);
    $limit = max(1, min(500, (int) $limit));

    $unknown_table = ahx_wp_polylex_unknown_words_table();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, word, seen_count, first_seen_at, last_seen_at
             FROM {$unknown_table}
             WHERE language_code = %s
             ORDER BY seen_count DESC, last_seen_at DESC, word ASC
             LIMIT %d",
            $language_code,
            $limit
        ),
        ARRAY_A
    );

    return is_array($rows) ? $rows : array();
}

function ahx_wp_polylex_handle_add_language() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_polylex_manage_languages');

    $new_language = ahx_wp_polylex_normalize_language_code(wp_unslash($_POST['ahx_wp_polylex_new_language'] ?? ''));
    $languages = ahx_wp_polylex_get_possible_languages();

    $status = 'added';
    if (in_array($new_language, $languages, true)) {
        $status = 'exists';
    } else {
        $languages[] = $new_language;
        ahx_wp_polylex_set_possible_languages($languages);
    }

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-polylex-config',
            'lang_manage' => $status,
            'lang_code' => $new_language,
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_ahx_wp_polylex_add_language', 'ahx_wp_polylex_handle_add_language');

function ahx_wp_polylex_handle_delete_language() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_polylex_manage_languages');

    $language_code = ahx_wp_polylex_normalize_language_code(wp_unslash($_POST['ahx_wp_polylex_delete_language'] ?? ''));
    $languages = ahx_wp_polylex_get_possible_languages();

    $status = 'deleted';
    $usage = ahx_wp_polylex_get_language_usage($language_code);

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
        ahx_wp_polylex_set_possible_languages($languages);
    }

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-polylex-config',
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
add_action('admin_post_ahx_wp_polylex_delete_language', 'ahx_wp_polylex_handle_delete_language');

function ahx_wp_polylex_normalize_words($raw, $language_code = 'de_DE') {
    $lines = preg_split('/\R+/', (string) $raw);
    $valid = array();

    foreach ($lines as $line) {
        $word = ahx_wp_polylex_normalize_single_word($line, $language_code);

        if ($word !== '') {
            $valid[$word] = $word;
        }
    }

    return array_values($valid);
}

function ahx_wp_polylex_parse_csv_words($tmp_file_path, $language_code = 'de_DE') {
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

        $normalized = ahx_wp_polylex_normalize_words($candidate, $language_code);
        if (!empty($normalized)) {
            $words[$normalized[0]] = $normalized[0];
        }
    }

    return array_values($words);
}

function ahx_wp_polylex_parse_bulk_words($raw, $language_code = 'de_DE') {
    $tokens = preg_split('/[\s,;]+/u', (string) $raw);
    $words = array();

    if (!is_array($tokens)) {
        return array();
    }

    foreach ($tokens as $token) {
        $normalized = ahx_wp_polylex_normalize_single_word($token, $language_code);
        if ($normalized === '') {
            continue;
        }

        $words[$normalized] = $normalized;
    }

    return array_values($words);
}

function ahx_wp_polylex_install_tables() {
    global $wpdb;

    $words_table = ahx_wp_polylex_words_table();
    $history_table = ahx_wp_polylex_history_table();
    $unknown_table = ahx_wp_polylex_unknown_words_table();
    $reported_table = ahx_wp_polylex_reported_words_table();
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

    $sql_unknown = "CREATE TABLE {$unknown_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        language_code varchar(16) NOT NULL,
        word varchar(5) NOT NULL,
        seen_count bigint(20) unsigned NOT NULL DEFAULT 1,
        first_seen_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_language_word (language_code, word),
        KEY idx_language_seen (language_code, seen_count),
        KEY idx_language_last_seen (language_code, last_seen_at)
    ) {$charset_collate};";

    $sql_reported = "CREATE TABLE {$reported_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        language_code varchar(16) NOT NULL,
        word varchar(5) NOT NULL,
        reason varchar(50) NOT NULL,
        report_count bigint(20) unsigned NOT NULL DEFAULT 1,
        first_reported_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_reported_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_language_word_reason (language_code, word, reason),
        KEY idx_language_count (language_code, report_count),
        KEY idx_last_reported (last_reported_at)
    ) {$charset_collate};";

    dbDelta($sql_words);
    dbDelta($sql_history);
    dbDelta($sql_unknown);
    dbDelta($sql_reported);
}

function ahx_wp_polylex_insert_words($language_code, $words) {
    global $wpdb;

    $language_code = ahx_wp_polylex_normalize_language_code($language_code);
    $words_table = ahx_wp_polylex_words_table();

    $inserted = 0;
    $duplicates = 0;

    foreach ($words as $word) {
        $word = ahx_wp_polylex_normalize_single_word($word, $language_code);
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

function ahx_wp_polylex_seed_default_words() {
    $stats = ahx_wp_polylex_insert_words('de_DE', ahx_wp_polylex_default_words());
    return $stats;
}

function ahx_wp_polylex_get_words_by_language($language_code) {
    global $wpdb;

    $language_code = ahx_wp_polylex_normalize_language_code($language_code);
    $table = ahx_wp_polylex_words_table();

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

function ahx_wp_polylex_get_languages_with_counts() {
    global $wpdb;

    $table = ahx_wp_polylex_words_table();
    $rows = $wpdb->get_results("SELECT language_code, COUNT(*) AS total FROM {$table} GROUP BY language_code ORDER BY language_code ASC", ARRAY_A);

    return is_array($rows) ? $rows : array();
}

function ahx_wp_polylex_pick_daily_word($language_code, $play_date) {
    global $wpdb;

    $language_code = ahx_wp_polylex_normalize_language_code($language_code);
    $words_table = ahx_wp_polylex_words_table();
    $history_table = ahx_wp_polylex_history_table();

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

function ahx_wp_polylex_get_or_create_daily_word($language_code, $play_date) {
    global $wpdb;

    $language_code = ahx_wp_polylex_normalize_language_code($language_code);
    $words_table = ahx_wp_polylex_words_table();
    $history_table = ahx_wp_polylex_history_table();

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

    return ahx_wp_polylex_pick_daily_word($language_code, $play_date);
}

function ahx_wp_polylex_maybe_migrate_wordle_data() {
    $done = get_option('ahx_wp_polylex_wordle_data_migrated', '0');
    if ($done === '1') {
        return;
    }

    global $wpdb;

    $new_words_table = ahx_wp_polylex_words_table();
    $new_history_table = ahx_wp_polylex_history_table();
    $new_unknown_table = ahx_wp_polylex_unknown_words_table();
    $new_reported_table = ahx_wp_polylex_reported_words_table();

    $old_words_table = $wpdb->prefix . 'ahx_wp_wordle_words';
    $old_history_table = $wpdb->prefix . 'ahx_wp_wordle_history';
    $old_unknown_table = $wpdb->prefix . 'ahx_wp_wordle_unknown_words';
    $old_reported_table = $wpdb->prefix . 'ahx_wp_wordle_reported_words';
    $legacy_source_found = false;
    $migration_details = array(
        'tables' => array(),
        'options' => array(),
        'user_meta_rows' => 0,
    );

    $table_exists = function ($table_name) use ($wpdb) {
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        return is_string($found) && $found === $table_name;
    };

    if ($table_exists($old_words_table)) {
        $legacy_source_found = true;
        $migration_details['tables'][] = 'words';
        $wpdb->query(
            "INSERT IGNORE INTO {$new_words_table} (language_code, word, created_at)
             SELECT language_code, word, created_at
             FROM {$old_words_table}"
        );
    }

    if ($table_exists($old_history_table) && $table_exists($old_words_table)) {
        $legacy_source_found = true;
        $migration_details['tables'][] = 'history';
        $wpdb->query(
            "INSERT IGNORE INTO {$new_history_table} (language_code, play_date, word_id, created_at)
             SELECT h.language_code, h.play_date, nw.id, h.created_at
             FROM {$old_history_table} h
             INNER JOIN {$old_words_table} ow ON ow.id = h.word_id
             INNER JOIN {$new_words_table} nw ON nw.language_code = ow.language_code AND nw.word = ow.word"
        );
    }

    if ($table_exists($old_unknown_table)) {
        $legacy_source_found = true;
        $migration_details['tables'][] = 'unknown_words';
        $wpdb->query(
            "INSERT INTO {$new_unknown_table} (language_code, word, seen_count, first_seen_at, last_seen_at)
             SELECT language_code, word, seen_count, first_seen_at, last_seen_at
             FROM {$old_unknown_table}
             ON DUPLICATE KEY UPDATE
                seen_count = GREATEST(seen_count, VALUES(seen_count)),
                first_seen_at = LEAST(first_seen_at, VALUES(first_seen_at)),
                last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at))"
        );
    }

    if ($table_exists($old_reported_table)) {
        $legacy_source_found = true;
        $migration_details['tables'][] = 'reported_words';
        $wpdb->query(
            "INSERT INTO {$new_reported_table} (language_code, word, reason, report_count, first_reported_at, last_reported_at)
             SELECT language_code, word, reason, report_count, first_reported_at, last_reported_at
             FROM {$old_reported_table}
             ON DUPLICATE KEY UPDATE
                report_count = GREATEST(report_count, VALUES(report_count)),
                first_reported_at = LEAST(first_reported_at, VALUES(first_reported_at)),
                last_reported_at = GREATEST(last_reported_at, VALUES(last_reported_at))"
        );
    }

    $option_map = array(
        'ahx_wp_wordle_rows' => 'ahx_wp_polylex_rows',
        'ahx_wp_wordle_default_language' => 'ahx_wp_polylex_default_language',
        'ahx_wp_wordle_persistence_mode' => 'ahx_wp_polylex_persistence_mode',
        'ahx_wp_wordle_languages' => 'ahx_wp_polylex_languages',
        'ahx_wp_wordle_word_list' => 'ahx_wp_polylex_word_list',
    );

    foreach ($option_map as $old_option => $new_option) {
        $new_value = get_option($new_option, null);
        if ($new_value !== null) {
            continue;
        }

        $old_value = get_option($old_option, null);
        if ($old_value === null) {
            continue;
        }

        $legacy_source_found = true;
        $migration_details['options'][] = $old_option;
        update_option($new_option, $old_value);
    }

    $legacy_states = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            'ahx_wp_wordle_state'
        )
    );

    if (is_array($legacy_states)) {
        foreach ($legacy_states as $state_row) {
            $user_id = isset($state_row->user_id) ? (int) $state_row->user_id : 0;
            if ($user_id <= 0) {
                continue;
            }

            $new_state = get_user_meta($user_id, 'ahx_wp_polylex_state', true);
            if (is_array($new_state) && !empty($new_state)) {
                continue;
            }

            $old_state = maybe_unserialize($state_row->meta_value);
            if (is_array($old_state)) {
                $legacy_source_found = true;
                $migration_details['user_meta_rows']++;
                update_user_meta($user_id, 'ahx_wp_polylex_state', $old_state);
            }
        }
    }

    if ($legacy_source_found) {
        update_option('ahx_wp_polylex_migration_notice_pending', '1', false);
        update_option('ahx_wp_polylex_migration_notice_details', $migration_details, false);
    }

    update_option('ahx_wp_polylex_wordle_data_migrated', '1', false);
}

function ahx_wp_polylex_maybe_migrate_legacy_words() {
    ahx_wp_polylex_maybe_migrate_wordle_data();

    $done = get_option('ahx_wp_polylex_legacy_migrated', '0');
    if ($done === '1') {
        return;
    }

    $legacy = get_option('ahx_wp_polylex_word_list', '');
    if (!is_string($legacy) || trim($legacy) === '') {
        $legacy = get_option('ahx_wp_wordle_word_list', '');
    }

    if (is_string($legacy) && trim($legacy) !== '') {
        $words = ahx_wp_polylex_normalize_words($legacy, 'de_DE');
        if (!empty($words)) {
            ahx_wp_polylex_insert_words('de_DE', $words);
        }
    }

    update_option('ahx_wp_polylex_legacy_migrated', '1');
}

function ahx_wp_polylex_handle_csv_import() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_polylex_import_csv');

    $language_code = ahx_wp_polylex_normalize_language_code(wp_unslash($_POST['ahx_wp_polylex_import_language'] ?? 'de_DE'));
    $possible_languages = ahx_wp_polylex_get_possible_languages();
    if (!in_array($language_code, $possible_languages, true)) {
        $language_code = $possible_languages[0];
    }
    $file = $_FILES['ahx_wp_polylex_csv'] ?? null;

    $message = array(
        'inserted' => 0,
        'duplicates' => 0,
        'language' => $language_code,
        'error' => '',
    );

    if (!$file || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $message['error'] = 'Bitte eine CSV-Datei auswählen.';
    } else {
        $words = ahx_wp_polylex_parse_csv_words($file['tmp_name'], $language_code);
        if (empty($words)) {
            $message['error'] = 'Keine gültigen 5-Buchstaben-Wörter für die gewählte Sprache in der CSV gefunden.';
        } else {
            $stats = ahx_wp_polylex_insert_words($language_code, $words);
            $message['inserted'] = (int) $stats['inserted'];
            $message['duplicates'] = (int) $stats['duplicates'];
        }
    }

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-polylex-config',
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
add_action('admin_post_ahx_wp_polylex_import_csv', 'ahx_wp_polylex_handle_csv_import');

function ahx_wp_polylex_handle_bulk_import() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_polylex_import_bulk');

    $language_code = ahx_wp_polylex_normalize_language_code(wp_unslash($_POST['ahx_wp_polylex_bulk_language'] ?? 'de_DE'));
    $possible_languages = ahx_wp_polylex_get_possible_languages();
    if (!in_array($language_code, $possible_languages, true)) {
        $language_code = $possible_languages[0];
    }

    $raw_words = wp_unslash($_POST['ahx_wp_polylex_bulk_words'] ?? '');
    $message = array(
        'inserted' => 0,
        'duplicates' => 0,
        'language' => $language_code,
        'error' => '',
    );

    if (!is_string($raw_words) || trim($raw_words) === '') {
        $message['error'] = 'Bitte mindestens ein Wort im Textfeld eingeben.';
    } else {
        $words = ahx_wp_polylex_parse_bulk_words($raw_words, $language_code);

        if (empty($words)) {
            $message['error'] = 'Keine gültigen 5-Buchstaben-Wörter für die gewählte Sprache im Textfeld gefunden.';
        } else {
            $stats = ahx_wp_polylex_insert_words($language_code, $words);
            $message['inserted'] = (int) $stats['inserted'];
            $message['duplicates'] = (int) $stats['duplicates'];
        }
    }

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-polylex-config',
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
add_action('admin_post_ahx_wp_polylex_import_bulk', 'ahx_wp_polylex_handle_bulk_import');

function ahx_wp_polylex_handle_import_tracked_words() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_polylex_import_tracked_words');

    $language_code = ahx_wp_polylex_normalize_language_code(wp_unslash($_POST['ahx_wp_polylex_tracked_language'] ?? 'de_DE'));
    $possible_languages = ahx_wp_polylex_get_possible_languages();
    if (!in_array($language_code, $possible_languages, true)) {
        $language_code = $possible_languages[0];
    }

    $selected_ids_raw = wp_unslash($_POST['ahx_wp_polylex_tracked_ids'] ?? array());
    $selected_ids = array();

    if (is_array($selected_ids_raw)) {
        foreach ($selected_ids_raw as $raw_id) {
            $id = (int) $raw_id;
            if ($id > 0) {
                $selected_ids[$id] = $id;
            }
        }
    }

    $message = array(
        'language' => $language_code,
        'selected' => count($selected_ids),
        'inserted' => 0,
        'duplicates' => 0,
        'discarded' => 0,
        'error' => '',
    );
    $operation = sanitize_key((string) wp_unslash($_POST['ahx_wp_polylex_tracked_operation'] ?? 'import'));
    if (!in_array($operation, array('import', 'discard'), true)) {
        $operation = 'import';
    }

    if (empty($selected_ids)) {
        $message['error'] = 'Bitte mindestens ein getracktes Wort auswählen.';
    } else {
        global $wpdb;

        $unknown_table = ahx_wp_polylex_unknown_words_table();
        $ids_placeholder = implode(',', array_fill(0, count($selected_ids), '%d'));
        $params = array_merge(array($language_code), array_values($selected_ids));

        if ($operation === 'discard') {
            $deleted = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$unknown_table}
                     WHERE language_code = %s AND id IN ({$ids_placeholder})",
                    $params
                )
            );

            $message['discarded'] = max(0, $deleted);
        } else {
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT word
                     FROM {$unknown_table}
                     WHERE language_code = %s AND id IN ({$ids_placeholder})",
                    $params
                )
            );

            $words = array();
            if (is_array($rows)) {
                foreach ($rows as $word) {
                    $normalized = ahx_wp_polylex_normalize_single_word((string) $word, $language_code);
                    if ($normalized !== '') {
                        $words[$normalized] = $normalized;
                    }
                }
            }

            if (empty($words)) {
                $message['error'] = 'Keine gültigen Wörter in der Auswahl gefunden.';
            } else {
                $stats = ahx_wp_polylex_insert_words($language_code, array_values($words));
                $message['inserted'] = (int) ($stats['inserted'] ?? 0);
                $message['duplicates'] = (int) ($stats['duplicates'] ?? 0);

                $deleted = (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$unknown_table}
                         WHERE language_code = %s AND id IN ({$ids_placeholder})",
                        $params
                    )
                );
                $message['discarded'] = max(0, $deleted);
            }
        }
    }

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-polylex-config',
            'tracked_done' => '1',
            'tracked_language' => $message['language'],
            'tracked_selected' => (string) $message['selected'],
            'tracked_inserted' => (string) $message['inserted'],
            'tracked_duplicates' => (string) $message['duplicates'],
            'tracked_discarded' => (string) $message['discarded'],
            'tracked_operation' => $operation,
            'tracked_error' => rawurlencode($message['error']),
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_ahx_wp_polylex_import_tracked_words', 'ahx_wp_polylex_handle_import_tracked_words');

function ahx_wp_polylex_report_word() {
    $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
    if (!wp_verify_nonce($nonce, 'ahx_wp_polylex_state')) {
        wp_send_json_error(array('message' => 'Ungültiger Nonce'), 403);
    }

    $language_code = ahx_wp_polylex_normalize_language_code(wp_unslash($_POST['language'] ?? 'de_DE'));
    $word = ahx_wp_polylex_normalize_single_word(wp_unslash($_POST['word'] ?? ''), $language_code);
    $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));

    if ($word === '') {
        wp_send_json_error(array('message' => 'Ungültiges Wort'), 400);
    }

    if (!in_array($reason, array('not_base_form', 'not_singular', 'invalid', 'spelling', 'offensive', 'other'), true)) {
        $reason = 'other';
    }

    global $wpdb;
    $reported_table = ahx_wp_polylex_reported_words_table();

    $result = $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$reported_table} (language_code, word, reason, report_count, first_reported_at, last_reported_at)
             VALUES (%s, %s, %s, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE report_count = report_count + 1, last_reported_at = UTC_TIMESTAMP()",
            $language_code,
            $word,
            $reason
        )
    );

    wp_send_json_success(array('reported' => (bool) $result));
}
add_action('wp_ajax_ahx_wp_polylex_report_word', 'ahx_wp_polylex_report_word');
add_action('wp_ajax_nopriv_ahx_wp_polylex_report_word', 'ahx_wp_polylex_report_word');

function ahx_wp_polylex_get_reported_words($language_code, $limit = 200) {
    global $wpdb;

    $language_code = ahx_wp_polylex_normalize_language_code($language_code);
    $limit = max(1, min(500, (int) $limit));

    $reported_table = ahx_wp_polylex_reported_words_table();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, word, reason, report_count, first_reported_at, last_reported_at
             FROM {$reported_table}
             WHERE language_code = %s
             ORDER BY report_count DESC, last_reported_at DESC, word ASC
             LIMIT %d",
            $language_code,
            $limit
        ),
        ARRAY_A
    );

    return is_array($rows) ? $rows : array();
}

function ahx_wp_polylex_handle_delete_reported_words() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_polylex_delete_reported_words');

    $language_code = ahx_wp_polylex_normalize_language_code(wp_unslash($_POST['ahx_wp_polylex_reported_language'] ?? 'de_DE'));
    $reported_ids_raw = wp_unslash($_POST['ahx_wp_polylex_reported_ids'] ?? array());
    $reported_ids = array();

    if (is_array($reported_ids_raw)) {
        foreach ($reported_ids_raw as $raw_id) {
            $id = (int) $raw_id;
            if ($id > 0) {
                $reported_ids[$id] = $id;
            }
        }
    }

    $deleted_count = 0;
    if (!empty($reported_ids)) {
        global $wpdb;
        $reported_table = ahx_wp_polylex_reported_words_table();
        $ids_placeholder = implode(',', array_fill(0, count($reported_ids), '%d'));
        $params = array_merge(array($language_code), array_values($reported_ids));

        $deleted_count = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$reported_table}
                 WHERE language_code = %s AND id IN ({$ids_placeholder})",
                $params
            )
        );
    }

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-polylex-config',
            'reported_deleted' => (string) $deleted_count,
            'reported_language' => $language_code,
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_ahx_wp_polylex_delete_reported_words', 'ahx_wp_polylex_handle_delete_reported_words');

function ahx_wp_polylex_handle_delete_single_report() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_polylex_delete_single_report');

    $report_id = (int) wp_unslash($_POST['report_id'] ?? 0);
    if ($report_id <= 0) {
        wp_safe_redirect(admin_url('admin.php?page=ahx-wp-polylex-config'));
        exit;
    }

    global $wpdb;
    $reported_table = ahx_wp_polylex_reported_words_table();

    $deleted = (int) $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$reported_table} WHERE id = %d LIMIT 1",
            $report_id
        )
    );

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-polylex-config',
            'report_single_deleted' => $deleted > 0 ? '1' : '0',
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_ahx_wp_polylex_delete_single_report', 'ahx_wp_polylex_handle_delete_single_report');

function ahx_wp_polylex_handle_delete_word() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    check_admin_referer('ahx_wp_polylex_delete_word');

    $language_code = ahx_wp_polylex_normalize_language_code(wp_unslash($_POST['ahx_wp_polylex_words_language'] ?? 'de_DE'));
    $word_ids_raw = wp_unslash($_POST['ahx_wp_polylex_word_ids'] ?? array());
    $word_ids = array();

    if (is_array($word_ids_raw)) {
        foreach ($word_ids_raw as $raw_id) {
            $id = (int) $raw_id;
            if ($id > 0) {
                $word_ids[$id] = $id;
            }
        }
    }

    $deleted_count = 0;
    if (!empty($word_ids)) {
        global $wpdb;
        $words_table = ahx_wp_polylex_words_table();
        $ids_placeholder = implode(',', array_fill(0, count($word_ids), '%d'));
        $params = array_merge(array($language_code), array_values($word_ids));

        $deleted_count = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$words_table}
                 WHERE language_code = %s AND id IN ({$ids_placeholder})",
                $params
            )
        );
    }

    $redirect = add_query_arg(
        array(
            'page' => 'ahx-wp-polylex-config',
            'words_deleted' => (string) $deleted_count,
            'words_language' => $language_code,
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_ahx_wp_polylex_delete_word', 'ahx_wp_polylex_handle_delete_word');

function ahx_wp_polylex_sanitize_default_language($value) {
    $requested = ahx_wp_polylex_normalize_language_code($value);
    $allowed = ahx_wp_polylex_get_possible_languages();

    if (isset($_POST['ahx_wp_polylex_languages'])) {
        $allowed = ahx_wp_polylex_parse_languages(wp_unslash($_POST['ahx_wp_polylex_languages']));
    }

    if (in_array($requested, $allowed, true)) {
        return $requested;
    }

    return $allowed[0];
}

function ahx_wp_polylex_sanitize_languages($value) {
    $languages = ahx_wp_polylex_parse_languages($value);
    return implode(', ', $languages);
}

function ahx_wp_polylex_sanitize_rows($value) {
    $rows = (int) $value;

    if ($rows < 4) {
        $rows = 4;
    }
    if ($rows > 10) {
        $rows = 10;
    }

    return $rows;
}

function ahx_wp_polylex_sanitize_persistence_mode($value) {
    $mode = sanitize_key((string) $value);
    $allowed = array('auto', 'server', 'local_storage');

    if (!in_array($mode, $allowed, true)) {
        return 'auto';
    }

    return $mode;
}

function ahx_wp_polylex_register_settings() {
    register_setting('ahx_wp_polylex_settings_group', 'ahx_wp_polylex_rows', array(
        'type' => 'integer',
        'sanitize_callback' => 'ahx_wp_polylex_sanitize_rows',
        'default' => 6,
    ));

    register_setting('ahx_wp_polylex_settings_group', 'ahx_wp_polylex_default_language', array(
        'type' => 'string',
        'sanitize_callback' => 'ahx_wp_polylex_sanitize_default_language',
        'default' => 'de_DE',
    ));

    register_setting('ahx_wp_polylex_settings_group', 'ahx_wp_polylex_persistence_mode', array(
        'type' => 'string',
        'sanitize_callback' => 'ahx_wp_polylex_sanitize_persistence_mode',
        'default' => 'auto',
    ));

    add_settings_section(
        'ahx_wp_polylex_main',
        'Allgemeine Einstellungen',
        '__return_null',
        'ahx_wp_polylex_settings'
    );

    add_settings_field(
        'ahx_wp_polylex_rows',
        'Anzahl Versuche',
        'ahx_wp_polylex_rows_field',
        'ahx_wp_polylex_settings',
        'ahx_wp_polylex_main'
    );

    add_settings_field(
        'ahx_wp_polylex_default_language',
        'Standard-Sprache',
        'ahx_wp_polylex_default_language_field',
        'ahx_wp_polylex_settings',
        'ahx_wp_polylex_main'
    );

    add_settings_field(
        'ahx_wp_polylex_persistence_mode',
        'Persistenzmodus',
        'ahx_wp_polylex_persistence_mode_field',
        'ahx_wp_polylex_settings',
        'ahx_wp_polylex_main'
    );

}
add_action('admin_init', 'ahx_wp_polylex_register_settings');

function ahx_wp_polylex_rows_field() {
    $value = (int) get_option('ahx_wp_polylex_rows', 6);
    echo '<input type="number" name="ahx_wp_polylex_rows" value="' . esc_attr((string) $value) . '" min="4" max="10">';
}

function ahx_wp_polylex_default_language_field() {
    $value = ahx_wp_polylex_normalize_language_code((string) get_option('ahx_wp_polylex_default_language', 'de_DE'));
    $possible_languages = ahx_wp_polylex_get_possible_languages();

    echo '<select name="ahx_wp_polylex_default_language">';
    foreach ($possible_languages as $language_code) {
        echo '<option value="' . esc_attr($language_code) . '" ' . selected($value, $language_code, false) . '>' . esc_html($language_code) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . esc_html__('Diese Sprache wird standardmäßig genutzt, wenn im Shortcode kein <code>lang</code> gesetzt ist.</p>', 'ahx_wp_polylex');
}

function ahx_wp_polylex_persistence_mode_field() {
    $value = ahx_wp_polylex_sanitize_persistence_mode((string) get_option('ahx_wp_polylex_persistence_mode', 'auto'));

    echo '<select name="ahx_wp_polylex_persistence_mode">';
    echo '<option value="auto" ' . selected($value, 'auto', false) . '>Auto (empfohlen)</option>';
    echo '<option value="server" ' . selected($value, 'server', false) . '>Server</option>';
    echo '<option value="local_storage" ' . selected($value, 'local_storage', false) . '>localStorage</option>';
    echo '</select>';
    echo '<p class="description">' . esc_html__('Auto: Benutzer angemeldet = Server, Gäste = localStorage mit Server-Fallback.', 'ahx_wp_polylex') . '</p>';
}

function ahx_wp_polylex_settings_page() {
    $import_done = isset($_GET['import_done']) && (string) $_GET['import_done'] === '1';
    $import_error = isset($_GET['import_error']) ? rawurldecode((string) $_GET['import_error']) : '';
    $import_inserted = isset($_GET['import_inserted']) ? (int) $_GET['import_inserted'] : 0;
    $import_duplicates = isset($_GET['import_duplicates']) ? (int) $_GET['import_duplicates'] : 0;
    $import_language = isset($_GET['import_language']) ? ahx_wp_polylex_normalize_language_code(wp_unslash($_GET['import_language'])) : 'de_DE';
    $bulk_done = isset($_GET['bulk_done']) && (string) $_GET['bulk_done'] === '1';
    $bulk_error = isset($_GET['bulk_error']) ? rawurldecode((string) $_GET['bulk_error']) : '';
    $bulk_inserted = isset($_GET['bulk_inserted']) ? (int) $_GET['bulk_inserted'] : 0;
    $bulk_duplicates = isset($_GET['bulk_duplicates']) ? (int) $_GET['bulk_duplicates'] : 0;
    $bulk_language = isset($_GET['bulk_language']) ? ahx_wp_polylex_normalize_language_code(wp_unslash($_GET['bulk_language'])) : 'de_DE';
    $lang_manage = isset($_GET['lang_manage']) ? sanitize_key((string) $_GET['lang_manage']) : '';
    $lang_code = isset($_GET['lang_code']) ? ahx_wp_polylex_normalize_language_code(wp_unslash($_GET['lang_code'])) : '';
    $lang_words = isset($_GET['lang_words']) ? (int) $_GET['lang_words'] : 0;
    $lang_history = isset($_GET['lang_history']) ? (int) $_GET['lang_history'] : 0;
    $tracked_done = isset($_GET['tracked_done']) && (string) $_GET['tracked_done'] === '1';
    $tracked_error = isset($_GET['tracked_error']) ? rawurldecode((string) $_GET['tracked_error']) : '';
    $tracked_selected = isset($_GET['tracked_selected']) ? (int) $_GET['tracked_selected'] : 0;
    $tracked_inserted = isset($_GET['tracked_inserted']) ? (int) $_GET['tracked_inserted'] : 0;
    $tracked_duplicates = isset($_GET['tracked_duplicates']) ? (int) $_GET['tracked_duplicates'] : 0;
    $tracked_discarded = isset($_GET['tracked_discarded']) ? (int) $_GET['tracked_discarded'] : 0;
    $tracked_operation = isset($_GET['tracked_operation']) ? sanitize_key((string) $_GET['tracked_operation']) : 'import';
    $tracked_language = isset($_GET['tracked_language']) ? ahx_wp_polylex_normalize_language_code(wp_unslash($_GET['tracked_language'])) : '';
    $tracked_filter_language = isset($_GET['tracked_filter_language']) ? ahx_wp_polylex_normalize_language_code(wp_unslash($_GET['tracked_filter_language'])) : '';
    $reported_deleted = isset($_GET['reported_deleted']) ? (int) $_GET['reported_deleted'] : 0;
    $reported_language = isset($_GET['reported_language']) ? ahx_wp_polylex_normalize_language_code(wp_unslash($_GET['reported_language'])) : '';
    $report_single_deleted = isset($_GET['report_single_deleted']) ? (int) $_GET['report_single_deleted'] : 0;
    $words_deleted = isset($_GET['words_deleted']) ? (int) $_GET['words_deleted'] : 0;
    $words_language = isset($_GET['words_language']) ? ahx_wp_polylex_normalize_language_code(wp_unslash($_GET['words_language'])) : '';
    $possible_languages = ahx_wp_polylex_get_possible_languages();
    $default_language = ahx_wp_polylex_normalize_language_code((string) get_option('ahx_wp_polylex_default_language', 'de_DE'));

    if (!in_array($import_language, $possible_languages, true)) {
        $import_language = $default_language;
    }
    if (!in_array($bulk_language, $possible_languages, true)) {
        $bulk_language = $default_language;
    }
    if (!in_array($tracked_language, $possible_languages, true)) {
        $tracked_language = $default_language;
    }
    if (!in_array($tracked_filter_language, $possible_languages, true)) {
        $tracked_filter_language = $tracked_language;
    }
    if (!in_array($reported_language, $possible_languages, true)) {
        $reported_language = $default_language;
    }
    if (!in_array($words_language, $possible_languages, true)) {
        $words_language = $default_language;
    }

    $tracked_unknown_words = ahx_wp_polylex_get_unknown_words($tracked_filter_language, 300);
    $reported_words = ahx_wp_polylex_get_reported_words($reported_language, 300);
    $all_words = ahx_wp_polylex_get_words_by_language($words_language);

    ?>
    <div class="wrap">
        <h2><?php echo esc_html__('AHX WP PolyLex Einstellungen', 'ahx_wp_polylex'); ?></h2>

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
                <div class="notice notice-error"><p><?php echo esc_html__('Die letzte verbleibende Sprache kann nicht gelöscht werden.', 'ahx_wp_polylex'); ?></p></div>
            <?php elseif ($lang_manage === 'not_found') : ?>
                <div class="notice notice-warning"><p><?php echo esc_html__('Die Sprache wurde nicht gefunden.', 'ahx_wp_polylex'); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($tracked_done) : ?>
            <?php if ($tracked_error !== '') : ?>
                <div class="notice notice-error"><p><?php echo esc_html($tracked_error); ?></p></div>
            <?php else : ?>
                <?php if ($tracked_operation === 'discard') : ?>
                    <div class="notice notice-success"><p><?php echo esc_html('Getrackte Wörter verworfen für ' . $tracked_language . '. Ausgewählt: ' . $tracked_selected . ', entfernt: ' . $tracked_discarded . '.'); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-success"><p><?php echo esc_html('Getrackte Wörter verarbeitet für ' . $tracked_language . '. Ausgewählt: ' . $tracked_selected . ', übernommen: ' . $tracked_inserted . ', Dubletten: ' . $tracked_duplicates . ', aus Tracking entfernt: ' . $tracked_discarded . '.'); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($reported_deleted > 0) : ?>
            <div class="notice notice-success"><p><?php echo esc_html('Gemeldete Wörter gelöscht: ' . $reported_deleted); ?></p></div>
        <?php endif; ?>

        <?php if ($report_single_deleted > 0) : ?>
            <div class="notice notice-success"><p><?php echo esc_html__('Gemeldete Wort-Meldung gelöscht.', 'ahx_wp_polylex'); ?></p></div>
        <?php endif; ?>

        <?php if ($words_deleted > 0) : ?>
            <div class="notice notice-success"><p><?php echo esc_html('Wörter gelöscht: ' . $words_deleted); ?></p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php
            settings_fields('ahx_wp_polylex_settings_group');
            do_settings_sections('ahx_wp_polylex_settings');
            submit_button();
            ?>
        </form>

        <hr>
        <h3><?php echo esc_html__('Sprachverwaltung', 'ahx_wp_polylex'); ?></h3>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 12px;">
            <?php wp_nonce_field('ahx_wp_polylex_manage_languages'); ?>
            <input type="hidden" name="action" value="ahx_wp_polylex_add_language">
            <label for="ahx_wp_polylex_new_language"><strong><?php echo esc_html__('Sprache hinzufügen:', 'ahx_wp_polylex'); ?></strong></label>
            <input id="ahx_wp_polylex_new_language" type="text" name="ahx_wp_polylex_new_language" placeholder="z. B. en_US" class="regular-text" required>
            <?php submit_button('Sprache hinzufügen', 'secondary', 'submit', false); ?>
        </form>

        <table class="wp-list-table widefat fixed striped" style="max-width: 700px; margin-bottom: 20px;">
            <thead>
                <tr><th><?php echo esc_html__('Sprache', 'ahx_wp_polylex'); ?></th><th><?php echo esc_html__('Wörter', 'ahx_wp_polylex'); ?></th><th><?php echo esc_html__('Statistik', 'ahx_wp_polylex'); ?></th><th><?php echo esc_html__('Aktion', 'ahx_wp_polylex'); ?></th></tr>
            </thead>
            <tbody>
            <?php foreach ($possible_languages as $language_code) : ?>
                <?php $usage = ahx_wp_polylex_get_language_usage($language_code); ?>
                <tr>
                    <td><?php echo esc_html($language_code); ?><?php echo $default_language === $language_code ? ' (Standard)' : ''; ?></td>
                    <td><?php echo esc_html((string) $usage['words']); ?></td>
                    <td><?php echo esc_html((string) $usage['history']); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('ahx_wp_polylex_manage_languages'); ?>
                            <input type="hidden" name="action" value="ahx_wp_polylex_delete_language">
                            <input type="hidden" name="ahx_wp_polylex_delete_language" value="<?php echo esc_attr($language_code); ?>">
                            <?php submit_button('Löschen', 'delete', 'submit', false); ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <hr>
        <h3><?php echo esc_html__('CSV-Import Wörter', 'ahx_wp_polylex'); ?></h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('ahx_wp_polylex_import_csv'); ?>
            <input type="hidden" name="action" value="ahx_wp_polylex_import_csv">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ahx_wp_polylex_import_language"><?php echo esc_html__('Sprache', 'ahx_wp_polylex'); ?></label></th>
                    <td>
                        <select id="ahx_wp_polylex_import_language" name="ahx_wp_polylex_import_language">
                            <?php foreach ($possible_languages as $language_code) : ?>
                                <option value="<?php echo esc_attr($language_code); ?>" <?php selected($import_language, $language_code); ?>><?php echo esc_html($language_code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ahx_wp_polylex_csv"><?php echo esc_html__('CSV-Datei', 'ahx_wp_polylex'); ?></label></th>
                    <td><input id="ahx_wp_polylex_csv" type="file" name="ahx_wp_polylex_csv" accept=".csv,text/csv"></td>
                </tr>
            </table>

            <?php submit_button('CSV importieren'); ?>
            <p class="description"><?php echo esc_html__('Es wird das erste Feld je Zeile gelesen. Erlaubt sind genau 5 Buchstaben gemäß gewählter Sprache (für Deutsch inkl. ä, ö, ü, ß). Dubletten werden nicht erneut importiert.', 'ahx_wp_polylex'); ?></p>
        </form>

        <hr>
        <h3><?php echo esc_html__('Wörter per Textfeld importieren', 'ahx_wp_polylex'); ?></h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('ahx_wp_polylex_import_bulk'); ?>
            <input type="hidden" name="action" value="ahx_wp_polylex_import_bulk">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ahx_wp_polylex_bulk_language"><?php echo esc_html__('Sprache', 'ahx_wp_polylex'); ?></label></th>
                    <td>
                        <select id="ahx_wp_polylex_bulk_language" name="ahx_wp_polylex_bulk_language">
                            <?php foreach ($possible_languages as $language_code) : ?>
                                <option value="<?php echo esc_attr($language_code); ?>" <?php selected($bulk_language, $language_code); ?>><?php echo esc_html($language_code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ahx_wp_polylex_bulk_words"><?php echo esc_html__('Wörter', 'ahx_wp_polylex'); ?></label></th>
                    <td>
                        <textarea id="ahx_wp_polylex_bulk_words" name="ahx_wp_polylex_bulk_words" rows="8" class="large-text" placeholder="apfel&#10;blume&#10;tiger"></textarea>
                        <p class="description"><?php echo esc_html__('Mehrere Wörter möglich, getrennt durch Zeilenumbrüche, Leerzeichen, Kommas oder Semikolons. Es werden nur gültige Wörter mit 5 Buchstaben importiert.', 'ahx_wp_polylex'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Wörter importieren'); ?>
        </form>

        <hr>
        <h3><?php echo esc_html__('Getrackte unbekannte Wörter', 'ahx_wp_polylex'); ?></h3>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom: 12px;">
            <input type="hidden" name="page" value="ahx-wp-polylex-config">
            <label for="ahx_wp_polylex_tracked_filter_language"><strong><?php echo esc_html__('Sprache:', 'ahx_wp_polylex'); ?></strong></label>
            <select id="ahx_wp_polylex_tracked_filter_language" name="tracked_filter_language">
                <?php foreach ($possible_languages as $language_code) : ?>
                    <option value="<?php echo esc_attr($language_code); ?>" <?php selected($tracked_filter_language, $language_code); ?>><?php echo esc_html($language_code); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Anzeigen', 'ahx_wp_polylex'), 'secondary', 'submit', false); ?>
        </form>

        <?php if (empty($tracked_unknown_words)) : ?>
            <p><?php echo esc_html__('Keine getrackten Wörter für die gewählte Sprache vorhanden.', 'ahx_wp_polylex'); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ahx_wp_polylex_import_tracked_words'); ?>
                <input type="hidden" name="action" value="ahx_wp_polylex_import_tracked_words">
                <input type="hidden" name="ahx_wp_polylex_tracked_language" value="<?php echo esc_attr($tracked_filter_language); ?>">

                <table class="wp-list-table widefat fixed striped" style="max-width: 900px; margin-bottom: 12px;">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="ahx_wp_polylex_toggle_all_tracked"></th>
                            <th><?php echo esc_html__('Wort', 'ahx_wp_polylex'); ?></th>
                            <th><?php echo esc_html__('Vorkommen', 'ahx_wp_polylex'); ?></th>
                            <th><?php echo esc_html__('Erstmalig', 'ahx_wp_polylex'); ?></th>
                            <th><?php echo esc_html__('Zuletzt', 'ahx_wp_polylex'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tracked_unknown_words as $tracked_row) : ?>
                        <?php
                        $tracked_id = (int) ($tracked_row['id'] ?? 0);
                        $tracked_word = (string) ($tracked_row['word'] ?? '');
                        $tracked_count = (int) ($tracked_row['seen_count'] ?? 0);
                        $tracked_first_seen = (string) ($tracked_row['first_seen_at'] ?? '');
                        $tracked_last_seen = (string) ($tracked_row['last_seen_at'] ?? '');
                        ?>
                        <tr>
                            <td><input type="checkbox" name="ahx_wp_polylex_tracked_ids[]" value="<?php echo esc_attr((string) $tracked_id); ?>"></td>
                            <td><code><?php echo esc_html($tracked_word); ?></code></td>
                            <td><?php echo esc_html((string) $tracked_count); ?></td>
                            <td><?php echo esc_html($tracked_first_seen); ?></td>
                            <td><?php echo esc_html($tracked_last_seen); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p>
                    <button type="submit" name="ahx_wp_polylex_tracked_operation" value="import" class="button button-primary">
                        <?php echo esc_html__('Ausgewählte in Wortliste übernehmen', 'ahx_wp_polylex'); ?>
                    </button>
                    <button type="submit" name="ahx_wp_polylex_tracked_operation" value="discard" class="button button-secondary" onclick="return window.confirm('<?php echo esc_js(__('Ausgewählte Wörter wirklich verwerfen?', 'ahx_wp_polylex')); ?>');">
                        <?php echo esc_html__('Ausgewählte verwerfen', 'ahx_wp_polylex'); ?>
                    </button>
                </p>
            </form>

            <script>
            (function () {
                var toggleAll = document.getElementById('ahx_wp_polylex_toggle_all_tracked');
                if (!toggleAll) {
                    return;
                }

                toggleAll.addEventListener('change', function () {
                    var checkboxes = document.querySelectorAll('input[name="ahx_wp_polylex_tracked_ids[]"]');
                    checkboxes.forEach(function (checkbox) {
                        checkbox.checked = !!toggleAll.checked;
                    });
                });
            })();
            </script>
        <?php endif; ?>
        <hr>

        <h3><?php echo esc_html__('Gemeldete Lösungswörter', 'ahx_wp_polylex'); ?></h3>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom: 12px;">
            <input type="hidden" name="page" value="ahx-wp-polylex-config">
            <label for="ahx_wp_polylex_reported_filter_language"><strong><?php echo esc_html__('Sprache:', 'ahx_wp_polylex'); ?></strong></label>
            <select id="ahx_wp_polylex_reported_filter_language" name="reported_language">
                <?php foreach ($possible_languages as $language_code) : ?>
                    <option value="<?php echo esc_attr($language_code); ?>" <?php selected($reported_language, $language_code); ?>><?php echo esc_html($language_code); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Anzeigen', 'ahx_wp_polylex'), 'secondary', 'submit', false); ?>
        </form>

        <?php if (empty($reported_words)) : ?>
            <p><?php echo esc_html__('Keine gemeldeten Wörter für die gewählte Sprache vorhanden.', 'ahx_wp_polylex'); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ahx_wp_polylex_delete_reported_words'); ?>
                <input type="hidden" name="action" value="ahx_wp_polylex_delete_reported_words">
                <input type="hidden" name="ahx_wp_polylex_reported_language" value="<?php echo esc_attr($reported_language); ?>">

                <table class="wp-list-table widefat fixed striped" style="max-width: 900px; margin-bottom: 12px;">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="ahx_wp_polylex_toggle_all_reported"></th>
                            <th><?php echo esc_html__('Wort', 'ahx_wp_polylex'); ?></th>
                            <th><?php echo esc_html__('Grund', 'ahx_wp_polylex'); ?></th>
                            <th><?php echo esc_html__('Meldungen', 'ahx_wp_polylex'); ?></th>
                            <th><?php echo esc_html__('Erstmalig', 'ahx_wp_polylex'); ?></th>
                            <th><?php echo esc_html__('Zuletzt', 'ahx_wp_polylex'); ?></th>
                            <th style="width: 40px;"><?php echo esc_html__('Aktion', 'ahx_wp_polylex'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reported_words as $report_row) : ?>
                        <?php
                        $report_id = (int) ($report_row['id'] ?? 0);
                        $report_word = (string) ($report_row['word'] ?? '');
                        $report_reason = (string) ($report_row['reason'] ?? '');
                        $report_count = (int) ($report_row['report_count'] ?? 0);
                        $report_first = (string) ($report_row['first_reported_at'] ?? '');
                        $report_last = (string) ($report_row['last_reported_at'] ?? '');
                        $reason_labels = array(
                            'not_base_form' => 'Nicht in Grundform',
                            'not_singular' => 'Nicht singular',
                            'invalid' => 'Ungültiges Wort',
                            'spelling' => 'Schreibfehler',
                            'offensive' => 'Anstößig',
                            'other' => 'Sonstiges',
                        );
                        $reason_label = $reason_labels[$report_reason] ?? $report_reason;
                        ?>
                        <tr>
                            <td><input type="checkbox" name="ahx_wp_polylex_reported_ids[]" value="<?php echo esc_attr((string) $report_id); ?>"></td>
                            <td><code><?php echo esc_html($report_word); ?></code></td>
                            <td><?php echo esc_html($reason_label); ?></td>
                            <td><?php echo esc_html((string) $report_count); ?></td>
                            <td><?php echo esc_html($report_first); ?></td>
                            <td><?php echo esc_html($report_last); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                    <?php wp_nonce_field('ahx_wp_polylex_delete_single_report'); ?>
                                    <input type="hidden" name="action" value="ahx_wp_polylex_delete_single_report">
                                    <input type="hidden" name="report_id" value="<?php echo esc_attr((string) $report_id); ?>">
                                    <button type="submit" class="button button-small" onclick="return window.confirm('<?php echo esc_js(__('Diese Meldung wirklich löschen?', 'ahx_wp_polylex')); ?>');" style="padding: 2px 6px; font-size: 12px;">
                                        <?php echo esc_html__('×', 'ahx_wp_polylex'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p>
                    <button type="submit" name="submit" class="button button-secondary" onclick="return window.confirm('<?php echo esc_js(__('Ausgewählte gemeldete Wörter wirklich löschen?', 'ahx_wp_polylex')); ?>');">
                        <?php echo esc_html__('Ausgewählte löschen', 'ahx_wp_polylex'); ?>
                    </button>
                </p>
            </form>

            <script>
            (function () {
                var toggleAll = document.getElementById('ahx_wp_polylex_toggle_all_reported');
                if (!toggleAll) {
                    return;
                }

                toggleAll.addEventListener('change', function () {
                    var checkboxes = document.querySelectorAll('input[name="ahx_wp_polylex_reported_ids[]"]');
                    checkboxes.forEach(function (checkbox) {
                        checkbox.checked = !!toggleAll.checked;
                    });
                });
            })();
            </script>
        <?php endif; ?>

        <hr>

        <h3><?php echo esc_html__('Wörter pro Sprache verwalten', 'ahx_wp_polylex'); ?></h3>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom: 12px;">
            <input type="hidden" name="page" value="ahx-wp-polylex-config">
            <label for="ahx_wp_polylex_words_filter_language"><strong><?php echo esc_html__('Sprache:', 'ahx_wp_polylex'); ?></strong></label>
            <select id="ahx_wp_polylex_words_filter_language" name="words_language">
                <?php foreach ($possible_languages as $language_code) : ?>
                    <option value="<?php echo esc_attr($language_code); ?>" <?php selected($words_language, $language_code); ?>><?php echo esc_html($language_code); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Anzeigen', 'ahx_wp_polylex'), 'secondary', 'submit', false); ?>
        </form>

        <?php if (empty($all_words)) : ?>
            <p><?php echo esc_html__('Keine Wörter für die gewählte Sprache vorhanden.', 'ahx_wp_polylex'); ?></p>
        <?php else : ?>
            <p style="margin-bottom: 12px;">
                <strong><?php echo esc_html(sprintf(__('Wörter: %d', 'ahx_wp_polylex'), count($all_words))); ?></strong>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ahx_wp_polylex_delete_word'); ?>
                <input type="hidden" name="action" value="ahx_wp_polylex_delete_word">
                <input type="hidden" name="ahx_wp_polylex_words_language" value="<?php echo esc_attr($words_language); ?>">

                <div style="margin-bottom: 12px;">
                    <label style="display: inline-block; margin-right: 12px;">
                        <input type="checkbox" id="ahx_wp_polylex_toggle_all_words">
                        <strong><?php echo esc_html__('Alle auswählen', 'ahx_wp_polylex'); ?></strong>
                    </label>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px; margin-bottom: 16px; padding: 12px; background: #f9fafb; border-radius: 6px;">
                    <?php foreach ($all_words as $word_idx => $word_val) : ?>
                        <?php
                        global $wpdb;
                        $words_table = ahx_wp_polylex_words_table();
                        $word_id = (int) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT id FROM {$words_table} WHERE language_code = %s AND word = %s LIMIT 1",
                                $words_language,
                                $word_val
                            )
                        );
                        ?>
                        <label style="display: flex; align-items: center; gap: 6px; padding: 6px 8px; background: white; border: 1px solid #d1d5db; border-radius: 4px; cursor: pointer; font-size: 13px;">
                            <input type="checkbox" name="ahx_wp_polylex_word_ids[]" value="<?php echo esc_attr((string) $word_id); ?>" style="margin: 0; cursor: pointer;">
                            <code style="margin: 0; flex: 1; white-space: nowrap;"><?php echo esc_html($word_val); ?></code>
                        </label>
                    <?php endforeach; ?>
                </div>

                <p>
                    <button type="submit" name="submit" class="button button-secondary" onclick="return window.confirm('<?php echo esc_js(__('Ausgewählte Wörter wirklich löschen?', 'ahx_wp_polylex')); ?>');">
                        <?php echo esc_html__('Ausgewählte löschen', 'ahx_wp_polylex'); ?>
                    </button>
                </p>
            </form>

            <script>
            (function () {
                var toggleAll = document.getElementById('ahx_wp_polylex_toggle_all_words');
                if (!toggleAll) {
                    return;
                }

                toggleAll.addEventListener('change', function () {
                    var checkboxes = document.querySelectorAll('input[name="ahx_wp_polylex_word_ids[]"]');
                    checkboxes.forEach(function (checkbox) {
                        checkbox.checked = !!toggleAll.checked;
                    });
                });
            })();
            </script>
        <?php endif; ?>
        <hr>
        <p><strong><?php echo esc_html__('Sprache überschreiben:', 'ahx_wp_polylex'); ?></strong> <code>[ahx_polylex lang="en_US"]</code></p>
    </div>
    <?php
}


