<?php
/*
Plugin Name: AHX WP Wordle
Description: Stellt ein Wordle-Spiel als Shortcode bereit.
Version: v1.0.0
Author: AHX
*/

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'admin/config-page.php';

function ahx_wp_wordle_activate() {
    ahx_wp_wordle_install_tables();
    ahx_wp_wordle_seed_default_words();
    ahx_wp_wordle_maybe_migrate_legacy_words();
}
register_activation_hook(__FILE__, 'ahx_wp_wordle_activate');

function ahx_wp_wordle_bootstrap() {
    ahx_wp_wordle_install_tables();
    ahx_wp_wordle_seed_default_words();
    ahx_wp_wordle_maybe_migrate_legacy_words();
}
add_action('plugins_loaded', 'ahx_wp_wordle_bootstrap');

function ahx_wp_wordle_get_storage_map() {
    $storage = array();

    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $meta = get_user_meta($user_id, 'ahx_wp_wordle_state', true);
        if (is_array($meta)) {
            $storage = $meta;
        }
    } else {
        $cookie = isset($_COOKIE['ahx_wp_wordle_state']) ? wp_unslash((string) $_COOKIE['ahx_wp_wordle_state']) : '';
        if ($cookie !== '') {
            $decoded = json_decode($cookie, true);
            if (is_array($decoded)) {
                $storage = $decoded;
            }
        }
    }

    return is_array($storage) ? $storage : array();
}

function ahx_wp_wordle_save_storage_map($storage) {
    if (!is_array($storage)) {
        return;
    }

    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        update_user_meta($user_id, 'ahx_wp_wordle_state', $storage);
        return;
    }

    $encoded = wp_json_encode($storage);
    if (!is_string($encoded)) {
        return;
    }

    $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

    setcookie('ahx_wp_wordle_state', $encoded, time() + YEAR_IN_SECONDS, $path, $domain, is_ssl(), true);
}

function ahx_wp_wordle_normalize_guess_list($guesses_raw, $language_code = 'de_DE') {
    $decoded = json_decode((string) $guesses_raw, true);
    if (!is_array($decoded)) {
        return array();
    }

    $normalized = array();
    foreach ($decoded as $guess) {
        if (!is_string($guess)) {
            continue;
        }

        $word = ahx_wp_wordle_normalize_single_word($guess, $language_code);
        if ($word === '') {
            continue;
        }

        $normalized[] = $word;
        if (count($normalized) >= 20) {
            break;
        }
    }

    return $normalized;
}

function ahx_wp_wordle_get_saved_guesses_for_day($day_key) {
    $storage = ahx_wp_wordle_get_storage_map();
    if (!isset($storage[$day_key]) || !is_array($storage[$day_key])) {
        return array();
    }

    $language_code = 'de_DE';
    if (is_string($day_key) && strpos($day_key, '|') !== false) {
        $parts = explode('|', $day_key);
        $language_code = ahx_wp_wordle_normalize_language_code((string) end($parts));
    }

    $guesses = array();
    foreach ($storage[$day_key] as $guess) {
        if (!is_string($guess)) {
            continue;
        }
        $word = ahx_wp_wordle_normalize_single_word($guess, $language_code);
        if ($word !== '') {
            $guesses[] = $word;
        }
    }

    return $guesses;
}

add_action('wp_ajax_ahx_wp_wordle_save_state', 'ahx_wp_wordle_save_state');
add_action('wp_ajax_nopriv_ahx_wp_wordle_save_state', 'ahx_wp_wordle_save_state');
function ahx_wp_wordle_save_state() {
    $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
    if (!wp_verify_nonce($nonce, 'ahx_wp_wordle_state')) {
        wp_send_json_error(array('message' => 'Ungültiger Nonce'), 403);
    }

    $puzzle_key = sanitize_text_field(wp_unslash($_POST['puzzle_key'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}\|[A-Za-z_]{2,16}$/', $puzzle_key)) {
        wp_send_json_error(array('message' => 'Ungültiger Puzzle-Schlüssel'), 400);
    }

    $parts = explode('|', $puzzle_key);
    $language_code = ahx_wp_wordle_normalize_language_code((string) end($parts));

    $guesses = ahx_wp_wordle_normalize_guess_list(wp_unslash($_POST['guesses'] ?? '[]'), $language_code);

    $storage = ahx_wp_wordle_get_storage_map();
    $storage[$puzzle_key] = $guesses;

    if (count($storage) > 400) {
        ksort($storage);
        $storage = array_slice($storage, -400, null, true);
    }

    ahx_wp_wordle_save_storage_map($storage);
    wp_send_json_success(array('saved' => true));
}

add_action('wp_ajax_ahx_wp_wordle_add_word', 'ahx_wp_wordle_add_word');
function ahx_wp_wordle_add_word() {
    $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
    if (!wp_verify_nonce($nonce, 'ahx_wp_wordle_state')) {
        wp_send_json_error(array('message' => 'Ungültiger Nonce'), 403);
    }

    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Keine Berechtigung'), 403);
    }

    $language_code = ahx_wp_wordle_normalize_language_code(wp_unslash($_POST['language'] ?? 'de_DE'));
    $word = ahx_wp_wordle_normalize_single_word(wp_unslash($_POST['word'] ?? ''), $language_code);

    if ($word === '') {
        wp_send_json_error(array('message' => 'Ungültiges Wort'), 400);
    }

    $insert_result = ahx_wp_wordle_insert_words($language_code, array($word));

    wp_send_json_success(array(
        'word' => $word,
        'displayWord' => ahx_wp_wordle_to_display_word($word, $language_code),
        'inserted' => ((int) ($insert_result['inserted'] ?? 0)) > 0,
        'alreadyExists' => ((int) ($insert_result['duplicates'] ?? 0)) > 0,
    ));
}

add_action('wp_ajax_ahx_wp_wordle_reset_stats', 'ahx_wp_wordle_reset_stats');
add_action('wp_ajax_nopriv_ahx_wp_wordle_reset_stats', 'ahx_wp_wordle_reset_stats');
function ahx_wp_wordle_reset_stats() {
    $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
    if (!wp_verify_nonce($nonce, 'ahx_wp_wordle_state')) {
        wp_send_json_error(array('message' => 'Ungültiger Nonce'), 403);
    }

    $language_code = ahx_wp_wordle_normalize_language_code(wp_unslash($_POST['language'] ?? 'de_DE'));

    $storage = ahx_wp_wordle_get_storage_map();
    $updated = array();

    foreach ($storage as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        if (substr($key, -strlen('|' . $language_code)) === '|' . $language_code) {
            continue;
        }

        $updated[$key] = $value;
    }

    ahx_wp_wordle_save_storage_map($updated);
    wp_send_json_success(array('reset' => true));
}

function ahx_wp_wordle_add_admin_menu() {
    add_menu_page(
        'AHX WP Wordle',
        'AHX WP Wordle',
        'manage_options',
        'ahx-wp-wordle-config',
        'ahx_wp_wordle_settings_page',
        'dashicons-games',
        4
    );

    add_submenu_page(
        'ahx-wp-wordle-config',
        'AHX WP Wordle Einstellungen',
        'Einstellungen',
        'manage_options',
        'ahx-wp-wordle-config',
        'ahx_wp_wordle_settings_page'
    );
}
add_action('admin_menu', 'ahx_wp_wordle_add_admin_menu');

function ahx_wp_wordle_enqueue_assets() {
    wp_register_style(
        'ahx-wp-wordle-style',
        plugin_dir_url(__FILE__) . 'assets/css/wordle.css',
        array(),
        '0.1.0'
    );

    wp_register_script(
        'ahx-wp-wordle-script',
        plugin_dir_url(__FILE__) . 'assets/js/wordle.js',
        array(),
        '0.1.0',
        true
    );
}
add_action('wp_enqueue_scripts', 'ahx_wp_wordle_enqueue_assets');

function ahx_wp_wordle_get_i18n_messages($language_code) {
    $language_code = ahx_wp_wordle_normalize_language_code($language_code);
    $base_language = strtolower(substr($language_code, 0, 2));

    $messages = array(
        'de' => array(
            'won' => 'Stark! Du hast das Wort gefunden.',
            'lost' => 'Schade! Das gesuchte Wort war: ',
            'not_in_list' => 'Dieses Wort ist nicht in der Liste.',
            'not_enough_letters' => 'Bitte gib ein vollständiges Wort mit 5 Buchstaben ein.',
            'language_label' => 'Sprache:',
            'stats_title' => 'Statistik',
            'stats_played' => 'Gespielte Spiele',
            'stats_longest_streak' => 'Längste Siegesfolge',
            'stats_current_streak' => 'Aktuelle Siegesfolge',
            'stats_win_rate' => 'Gewinnrate',
            'stats_attempts_title' => 'Übersicht der Versuche',
            'stats_attempts_col_try' => 'Versuch',
            'stats_attempts_col_bar' => 'Visualisierung',
            'stats_attempts_col_abs' => 'Absolut',
            'stats_attempts_col_rel' => 'Relativ',
            'countdown_label' => 'Nächstes Rätsel in',
            'stats_reset' => 'Statistik zurücksetzen',
            'stats_reset_confirm' => 'Möchtest du die Statistik für diese Sprache wirklich zurücksetzen?',
            'add_word_button' => 'Wort hinzufügen',
            'add_word_prompt' => 'Dieses Wort ist nicht in der Liste. Als Administrator kannst du es hinzufügen.',
            'add_word_success' => 'Wort wurde hinzugefügt. Der Versuch wird jetzt gewertet.',
            'add_word_error' => 'Wort konnte nicht hinzugefügt werden.',
        ),
        'en' => array(
            'won' => 'Great! You found the word.',
            'lost' => 'Too bad! The word was: ',
            'not_in_list' => 'This word is not in the list.',
            'not_enough_letters' => 'Please enter a full 5-letter word.',
            'language_label' => 'Language:',
            'stats_title' => 'Statistics',
            'stats_played' => 'Played games',
            'stats_longest_streak' => 'Longest win streak',
            'stats_current_streak' => 'Current win streak',
            'stats_win_rate' => 'Win rate',
            'stats_attempts_title' => 'Attempts overview',
            'stats_attempts_col_try' => 'Attempt',
            'stats_attempts_col_bar' => 'Visualization',
            'stats_attempts_col_abs' => 'Absolute',
            'stats_attempts_col_rel' => 'Relative',
            'countdown_label' => 'Next puzzle in',
            'stats_reset' => 'Reset statistics',
            'stats_reset_confirm' => 'Do you really want to reset the statistics for this language?',
            'add_word_button' => 'Add word',
            'add_word_prompt' => 'This word is not in the list. As an admin you can add it.',
            'add_word_success' => 'Word added. The attempt will now be evaluated.',
            'add_word_error' => 'Word could not be added.',
        ),
        'fr' => array(
            'won' => 'Bravo ! Vous avez trouvé le mot.',
            'lost' => 'Dommage ! Le mot était : ',
            'not_in_list' => 'Ce mot n\'est pas dans la liste.',
            'not_enough_letters' => 'Veuillez saisir un mot complet de 5 lettres.',
            'language_label' => 'Langue :',
        ),
        'es' => array(
            'won' => '¡Genial! Has encontrado la palabra.',
            'lost' => '¡Qué pena! La palabra era: ',
            'not_in_list' => 'Esta palabra no está en la lista.',
            'not_enough_letters' => 'Introduce una palabra completa de 5 letras.',
            'language_label' => 'Idioma:',
        ),
        'it' => array(
            'won' => 'Ottimo! Hai trovato la parola.',
            'lost' => 'Peccato! La parola era: ',
            'not_in_list' => 'Questa parola non è nella lista.',
            'not_enough_letters' => 'Inserisci una parola completa di 5 lettere.',
            'language_label' => 'Lingua:',
        ),
    );

    if (isset($messages[$base_language])) {
        return $messages[$base_language];
    }

    return $messages['de'];
}

function ahx_wp_wordle_get_next_berlin_midnight_timestamp() {
    $tz = new DateTimeZone('Europe/Berlin');
    $now = new DateTime('now', $tz);
    $next_midnight = clone $now;
    $next_midnight->modify('tomorrow')->setTime(0, 0, 0);

    return $next_midnight->getTimestamp();
}

function ahx_wp_wordle_to_display_word($word, $language_code) {
    $language_code = ahx_wp_wordle_normalize_language_code($language_code);
    $base_language = strtolower(substr($language_code, 0, 2));
    $value = (string) $word;

    if ($base_language === 'de') {
        $value = str_replace('ß', 'ẞ', $value);
    }

    if (function_exists('mb_strtoupper')) {
        return (string) mb_strtoupper($value, 'UTF-8');
    }

    return (string) strtoupper($value);
}

function ahx_wp_wordle_get_user_language_statistics($language_code, $rows) {
    global $wpdb;

    $language_code = ahx_wp_wordle_normalize_language_code($language_code);
    $rows = max(4, min(10, (int) $rows));

    $storage = ahx_wp_wordle_get_storage_map();
    $words_table = ahx_wp_wordle_words_table();
    $history_table = ahx_wp_wordle_history_table();

    $history = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT h.play_date, w.word
             FROM {$history_table} h
             INNER JOIN {$words_table} w ON w.id = h.word_id
             WHERE h.language_code = %s
             ORDER BY h.play_date ASC",
            $language_code
        ),
        ARRAY_A
    );

    $played = 0;
    $wins = 0;
    $longest_streak = 0;
    $current_streak = 0;
    $running_streak = 0;
    $per_attempt = array();

    for ($attempt = 1; $attempt <= $rows; $attempt++) {
        $per_attempt[$attempt] = 0;
    }

    $played_results = array();

    if (is_array($history)) {
        foreach ($history as $row) {
            $play_date = (string) ($row['play_date'] ?? '');
            $target = ahx_wp_wordle_normalize_single_word((string) ($row['word'] ?? ''), $language_code);
            if ($play_date === '' || $target === '') {
                continue;
            }

            $puzzle_key = $play_date . '|' . $language_code;
            if (!isset($storage[$puzzle_key]) || !is_array($storage[$puzzle_key])) {
                continue;
            }

            $guesses = array();
            foreach ($storage[$puzzle_key] as $guess) {
                if (!is_string($guess)) {
                    continue;
                }
                $normalized = ahx_wp_wordle_normalize_single_word($guess, $language_code);
                if ($normalized !== '') {
                    $guesses[] = $normalized;
                }
                if (count($guesses) >= $rows) {
                    break;
                }
            }

            if (empty($guesses)) {
                continue;
            }

            $played++;
            $won = false;
            $attempt_index = 0;

            foreach ($guesses as $index => $guess_word) {
                if ($guess_word === $target) {
                    $won = true;
                    $attempt_index = $index + 1;
                    break;
                }
            }

            if ($won) {
                $wins++;
                if (isset($per_attempt[$attempt_index])) {
                    $per_attempt[$attempt_index]++;
                }
                $running_streak++;
                if ($running_streak > $longest_streak) {
                    $longest_streak = $running_streak;
                }
                $played_results[] = true;
            } else {
                $running_streak = 0;
                $played_results[] = false;
            }
        }
    }

    $current_streak = 0;
    for ($i = count($played_results) - 1; $i >= 0; $i--) {
        if ($played_results[$i] === true) {
            $current_streak++;
        } else {
            break;
        }
    }

    $attempts_distribution = array();
    for ($attempt = 1; $attempt <= $rows; $attempt++) {
        $absolute = (int) $per_attempt[$attempt];
        $relative = $played > 0 ? round(($absolute / $played) * 100, 1) : 0;
        $attempts_distribution[] = array(
            'attempt' => $attempt,
            'absolute' => $absolute,
            'relative' => $relative,
        );
    }

    $win_rate = $played > 0 ? round(($wins / $played) * 100, 1) : 0;

    return array(
        'playedGames' => $played,
        'wins' => $wins,
        'longestWinStreak' => $longest_streak,
        'currentWinStreak' => $current_streak,
        'winRate' => $win_rate,
        'attempts' => $attempts_distribution,
    );
}

function ahx_wp_wordle_render_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'title' => '',
            'lang' => '',
        ),
        $atts,
        'ahx_wordle'
    );

    wp_enqueue_style('ahx-wp-wordle-style');
    wp_enqueue_script('ahx-wp-wordle-script');

    $configured_title = (string) get_option('ahx_wp_wordle_title', 'AHX Wordle');
    $title = trim((string) $atts['title']) !== '' ? (string) $atts['title'] : $configured_title;

    $configured_language = (string) get_option('ahx_wp_wordle_default_language', 'de_DE');
    $query_language = isset($_GET['ahx_wordle_lang']) ? (string) wp_unslash($_GET['ahx_wordle_lang']) : '';
    $shortcode_language = trim((string) $atts['lang']) !== '' ? (string) $atts['lang'] : '';

    $requested_language = $query_language !== '' ? $query_language : ($shortcode_language !== '' ? $shortcode_language : $configured_language);
    $requested_language = ahx_wp_wordle_normalize_language_code($requested_language);

    $rows = (int) get_option('ahx_wp_wordle_rows', 6);
    if ($rows < 4 || $rows > 10) {
        $rows = 6;
    }

    $possible_languages = ahx_wp_wordle_get_possible_languages();
    $available_languages = array();
    $words_by_language = array();

    foreach ($possible_languages as $possible_language) {
        $possible_language = ahx_wp_wordle_normalize_language_code($possible_language);
        $lang_words = ahx_wp_wordle_get_words_by_language($possible_language);
        if (!empty($lang_words)) {
            $available_languages[] = $possible_language;
            $words_by_language[$possible_language] = $lang_words;
        }
    }

    if (empty($available_languages)) {
        $fallback_words = ahx_wp_wordle_get_words_by_language('de_DE');
        if (!empty($fallback_words)) {
            $available_languages[] = 'de_DE';
            $words_by_language['de_DE'] = $fallback_words;
        }
    }

    if (empty($available_languages)) {
        return '<div class="ahx-wordle">Für diese Sprache sind keine Wörter verfügbar.</div>';
    }

    $language_code = in_array($requested_language, $available_languages, true) ? $requested_language : $available_languages[0];
    $words = $words_by_language[$language_code];

    $language_options = array_map(function ($lang_code) {
        return array(
            'code' => $lang_code,
            'label' => $lang_code,
        );
    }, $available_languages);

    $day_key = gmdate('Y-m-d');
    $puzzle_key = $day_key . '|' . $language_code;

    $daily_word = ahx_wp_wordle_get_or_create_daily_word($language_code, $day_key);
    if (!is_array($daily_word) || empty($daily_word['word'])) {
        return '<div class="ahx-wordle">Kein Tageswort verfügbar.</div>';
    }

    $saved_guesses = array_slice(ahx_wp_wordle_get_saved_guesses_for_day($puzzle_key), 0, $rows);
    $persistence_mode = ahx_wp_wordle_sanitize_persistence_mode((string) get_option('ahx_wp_wordle_persistence_mode', 'auto'));
    $statistics = ahx_wp_wordle_get_user_language_statistics($language_code, $rows);
    $next_midnight_ts = ahx_wp_wordle_get_next_berlin_midnight_timestamp();

    $payload = array(
        'title' => sanitize_text_field($title),
        'rows' => $rows,
        'cols' => 5,
        'dayKey' => $day_key,
        'puzzleKey' => $puzzle_key,
        'language' => $language_code,
        'languageParam' => 'ahx_wordle_lang',
        'languageOptions' => $language_options,
        'targetWord' => ahx_wp_wordle_to_display_word((string) $daily_word['word'], $language_code),
        'savedGuesses' => $saved_guesses,
        'statistics' => $statistics,
        'nextPuzzleTimestamp' => $next_midnight_ts,
        'countdownTimezone' => 'Europe/Berlin',
        'words' => $words,
        'isLoggedIn' => is_user_logged_in(),
        'isAdmin' => current_user_can('manage_options'),
        'persistenceMode' => $persistence_mode,
        'localStorageKey' => 'ahx_wp_wordle_state|' . $puzzle_key,
        'localStoragePrefix' => 'ahx_wp_wordle_state|',
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ahx_wp_wordle_state'),
        'i18n' => ahx_wp_wordle_get_i18n_messages($language_code),
    );

    wp_add_inline_script('ahx-wp-wordle-script', 'window.AHXWordleConfig = ' . wp_json_encode($payload) . ';', 'before');

    ob_start();
    ?>
    <?php $selector_id = 'ahx-wordle-language-' . wp_rand(1000, 99999); ?>
    <div class="ahx-wordle" data-cols="5" data-rows="<?php echo esc_attr((string) $rows); ?>">
        <h2 class="ahx-wordle__title"><?php echo esc_html($payload['title']); ?></h2>
        <div class="ahx-wordle__language">
            <label for="<?php echo esc_attr($selector_id); ?>"><?php echo esc_html((string) $payload['i18n']['language_label']); ?></label>
            <select id="<?php echo esc_attr($selector_id); ?>" class="ahx-wordle__language-select" aria-label="Wordle Sprache wählen">
                <?php foreach ($language_options as $option) : ?>
                    <option value="<?php echo esc_attr((string) $option['code']); ?>" <?php selected($language_code, (string) $option['code']); ?>>
                        <?php echo esc_html((string) $option['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ahx-wordle__status" aria-live="polite"></div>
        <div class="ahx-wordle__board" role="grid" aria-label="Wordle Spielfeld"></div>
        <div class="ahx-wordle__keyboard" role="group" aria-label="Wordle Tastatur"></div>
        <div class="ahx-wordle__stats"></div>
        <div class="ahx-wordle__countdown"></div>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode('ahx_wordle', 'ahx_wp_wordle_render_shortcode');

function ahx_wp_wordle_render_game_shortcode($atts) {
    return '<div class="ahx-wordle-view ahx-wordle-view--game">' . ahx_wp_wordle_render_shortcode($atts) . '</div>';
}
add_shortcode('ahx_wordle_game', 'ahx_wp_wordle_render_game_shortcode');

function ahx_wp_wordle_render_stats_shortcode($atts) {
    return '<div class="ahx-wordle-view ahx-wordle-view--stats">' . ahx_wp_wordle_render_shortcode($atts) . '</div>';
}
add_shortcode('ahx_wordle_stats', 'ahx_wp_wordle_render_stats_shortcode');

function ahx_wp_wordle_render_help_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'lang' => '',
        ),
        $atts,
        'ahx_wordle_help'
    );

    wp_enqueue_style('ahx-wp-wordle-style');

    $configured_language = (string) get_option('ahx_wp_wordle_default_language', 'de_DE');
    $query_language = isset($_GET['ahx_wordle_lang']) ? (string) wp_unslash($_GET['ahx_wordle_lang']) : '';
    $shortcode_language = trim((string) $atts['lang']) !== '' ? (string) $atts['lang'] : '';
    $language_code = $query_language !== '' ? $query_language : ($shortcode_language !== '' ? $shortcode_language : $configured_language);
    $language_code = ahx_wp_wordle_normalize_language_code($language_code);
    $base_language = strtolower(substr($language_code, 0, 2));

    if ($base_language === 'en') {
        $title = 'How to play';
        $items = array(
            'Guess the hidden 5-letter word in up to 6 tries.',
            'Each guess must be a valid word in the selected language.',
            'Green = correct letter in correct position.',
            'Yellow = correct letter in wrong position.',
            'Gray = letter is not in the word.',
            'A new puzzle is available every day at midnight (Europe/Berlin).',
        );
    } else {
        $title = 'Anleitung';
        $items = array(
            'Errate das versteckte Wort mit 5 Buchstaben in maximal 6 Versuchen.',
            'Jeder Versuch muss ein gültiges Wort in der gewählten Sprache sein.',
            'Grün = richtiger Buchstabe an der richtigen Position.',
            'Gelb = richtiger Buchstabe an falscher Position.',
            'Grau = Buchstabe ist nicht im Zielwort enthalten.',
            'Täglich um Mitternacht (Europe/Berlin) gibt es ein neues Rätsel.',
        );
    }

    ob_start();
    ?>
    <div class="ahx-wordle-help">
        <h3 class="ahx-wordle-help__title"><?php echo esc_html($title); ?></h3>
        <ul class="ahx-wordle-help__list">
            <?php foreach ($items as $item) : ?>
                <li><?php echo esc_html((string) $item); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode('ahx_wordle_help', 'ahx_wp_wordle_render_help_shortcode');
