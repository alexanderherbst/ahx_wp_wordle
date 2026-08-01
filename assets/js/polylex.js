(function () {
    var config = window.AHXPolyLexConfig || {};
    var words = Array.isArray(config.words) ? config.words.filter(function (word) {
        return typeof word === 'string' && word.length === 5;
    }) : [];

    if (!words.length) {
        return;
    }

    var rows = Number(config.rows) || 6;
    var cols = Number(config.cols) || 5;
    var today = new Date();
    var dayKey = config.dayKey || (today.getUTCFullYear() + '-' + String(today.getUTCMonth() + 1).padStart(2, '0') + '-' + String(today.getUTCDate()).padStart(2, '0'));
    var puzzleKey = config.puzzleKey || dayKey;
    var isLoggedIn = !!config.isLoggedIn;
    var persistenceMode = String(config.persistenceMode || 'auto');
    var localStorageKey = String(config.localStorageKey || ('ahx_wp_polylex_state|' + puzzleKey));
    var localStoragePrefix = String(config.localStoragePrefix || 'ahx_wp_polylex_state|');
    var currentLanguage = String(config.language || '').trim();
    var baseLanguage = currentLanguage.slice(0, 2).toLowerCase();
    var isGerman = baseLanguage === 'de';

    function toDisplayWord(word) {
        var source = String(word || '');
        if (isGerman) {
            return source.replace(/ß/g, 'ẞ').toUpperCase();
        }
        return source.toUpperCase();
    }

    function normalizeStorageWord(raw) {
        var source = String(raw || '').trim();
        source = source.toLowerCase();

        if (isGerman) {
            source = source.replace(/[^a-zäöüß]/g, '');
        } else {
            source = source.replace(/[^a-z]/g, '');
        }

        if (source.length !== cols) {
            return '';
        }

        return toDisplayWord(source);
    }

    function normalizeInputChar(raw) {
        var source = String(raw || '');
        if (source.length !== 1) {
            return '';
        }

        var lower = source.toLowerCase();
        if (isGerman) {
            if (lower === 'ä') return 'Ä';
            if (lower === 'ö') return 'Ö';
            if (lower === 'ü') return 'Ü';
            if (lower === 'ß') return 'ẞ';
        }

        var upper = source.toUpperCase();
        if (/^[A-Z]$/.test(upper)) {
            return upper;
        }

        return '';
    }

    function isAllowedLetterChar(char) {
        if (isGerman) {
            return /^[A-ZÄÖÜẞ]$/.test(char);
        }

        return /^[A-Z]$/.test(char);
    }

    var targetWord = String(config.targetWord || '').toUpperCase();
    if (!targetWord || targetWord.length !== cols) {
        var seed = Number(dayKey.replace(/-/g, ''));
        targetWord = toDisplayWord(words[seed % words.length]);
    } else {
        targetWord = normalizeStorageWord(targetWord) || toDisplayWord(targetWord);
    }
    var savedGuesses = [];

    var root = document.querySelector('.ahx-polylex');
    if (!root) {
        return;
    }

    root.addEventListener('dblclick', function (event) {
        event.preventDefault();
    });

    var lastRootTouchEndAt = 0;
    root.addEventListener('touchend', function (event) {
        if (event.touches && event.touches.length > 1) {
            return;
        }

        var now = Date.now();
        if (now - lastRootTouchEndAt < 350) {
            event.preventDefault();
        }
        lastRootTouchEndAt = now;
    }, { passive: false });

    var statusEl = root.querySelector('.ahx-polylex__status');
    var boardEl = root.querySelector('.ahx-polylex__board');
    var keyboardEl = root.querySelector('.ahx-polylex__keyboard');
    var statsEl = root.querySelector('.ahx-polylex__stats');
    var countdownEl = root.querySelector('.ahx-polylex__countdown');
    var languageSelectEl = root.querySelector('.ahx-polylex__language-select');

    var board = [];
    var guesses = [];
    var currentGuess = new Array(cols).fill('');
    var activeCellIndex = 0;
    var gameOver = false;
    var isAnimating = false;
    var pendingStatusAction = null;

    function isLocalStorageAvailable() {
        try {
            var testKey = '__ahx_polylex_test__';
            window.localStorage.setItem(testKey, '1');
            window.localStorage.removeItem(testKey);
            return true;
        } catch (error) {
            return false;
        }
    }

    function getActivePersistenceMode() {
        if (persistenceMode === 'server') {
            return 'server';
        }

        if (persistenceMode === 'local_storage') {
            return isLocalStorageAvailable() ? 'local_storage' : 'server';
        }

        if (isLoggedIn) {
            return 'server';
        }

        return isLocalStorageAvailable() ? 'local_storage' : 'server';
    }

    var activePersistenceMode = getActivePersistenceMode();

    function setStatus(message) {
        if (pendingStatusAction && pendingStatusAction.parentNode) {
            pendingStatusAction.parentNode.removeChild(pendingStatusAction);
        }
        pendingStatusAction = null;
        statusEl.textContent = message || '';
    }

    function setStatusWithAction(message, buttonLabel, onClick) {
        setStatus(message);

        if (!buttonLabel || typeof onClick !== 'function') {
            return;
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'ahx-polylex__status-action';
        button.textContent = buttonLabel;
        button.addEventListener('click', function () {
            onClick(button);
        });

        pendingStatusAction = button;
        statusEl.appendChild(document.createTextNode(' '));
        statusEl.appendChild(button);
    }

    function getWonMessage() {
        var i18n = config.i18n || {};
        var template = String(i18n.won_with_word || 'Stark! Du hast das Wort {word} gefunden.');
        return template.replace('{word}', targetWord);
    }

    function formatPercent(value) {
        var numeric = Number(value || 0);
        return numeric.toFixed(1).replace('.0', '') + ' %';
    }

    function renderStatistics() {
        if (!statsEl) {
            return;
        }

        var i18n = config.i18n || {};
        var stats = config.statistics || {};
        var attempts = Array.isArray(stats.attempts) ? stats.attempts : [];
        var maxRelative = attempts.reduce(function (maxValue, row) {
            var value = Number(row && row.relative ? row.relative : 0);
            return value > maxValue ? value : maxValue;
        }, 0);

        var cardsHtml = '' +
            '<div class="ahx-polylex__stats-grid">' +
                '<div class="ahx-polylex__stat-card"><span class="ahx-polylex__stat-label">' + (i18n.stats_played || 'Gespielte Spiele') + '</span><span class="ahx-polylex__stat-value">' + Number(stats.playedGames || 0) + '</span></div>' +
                '<div class="ahx-polylex__stat-card"><span class="ahx-polylex__stat-label">' + (i18n.stats_longest_streak || 'Längste Siegesfolge') + '</span><span class="ahx-polylex__stat-value">' + Number(stats.longestWinStreak || 0) + '</span></div>' +
                '<div class="ahx-polylex__stat-card"><span class="ahx-polylex__stat-label">' + (i18n.stats_current_streak || 'Aktuelle Siegesfolge') + '</span><span class="ahx-polylex__stat-value">' + Number(stats.currentWinStreak || 0) + '</span></div>' +
                '<div class="ahx-polylex__stat-card"><span class="ahx-polylex__stat-label">' + (i18n.stats_win_rate || 'Gewinnrate') + '</span><span class="ahx-polylex__stat-value">' + formatPercent(stats.winRate || 0) + '</span></div>' +
            '</div>';

        var attemptsRows = attempts.map(function (row) {
            var absolute = Number(row.absolute || 0);
            var relative = Number(row.relative || 0);
            var barWidth = maxRelative > 0
                ? Math.max(0, Math.min(100, (relative / maxRelative) * 100))
                : 0;

            return '' +
                '<tr>' +
                    '<td>' + Number(row.attempt || 0) + '</td>' +
                    '<td><div class="ahx-polylex__attempt-bar-wrap"><div class="ahx-polylex__attempt-bar" style="width:' + barWidth + '%"></div></div></td>' +
                    '<td>' + absolute + '</td>' +
                    '<td>' + formatPercent(relative) + '</td>' +
                '</tr>';
        }).join('');

        statsEl.innerHTML = '' +
            '<h3 class="ahx-polylex__stats-title">' + (i18n.stats_title || 'Statistik') + '</h3>' +
            cardsHtml +
            '<h4 class="ahx-polylex__attempts-title">' + (i18n.stats_attempts_title || 'Übersicht der Versuche') + '</h4>' +
            '<table class="ahx-polylex__attempts-table">' +
                '<thead><tr>' +
                    '<th>' + (i18n.stats_attempts_col_try || 'Versuch') + '</th>' +
                    '<th>' + (i18n.stats_attempts_col_bar || 'Visualisierung') + '</th>' +
                    '<th>' + (i18n.stats_attempts_col_abs || 'Absolut') + '</th>' +
                    '<th>' + (i18n.stats_attempts_col_rel || 'Relativ') + '</th>' +
                '</tr></thead>' +
                '<tbody>' + attemptsRows + '</tbody>' +
            '</table>';
    }

    function formatCountdown(seconds) {
        var safeSeconds = Math.max(0, Number(seconds || 0));
        var hours = Math.floor(safeSeconds / 3600);
        var minutes = Math.floor((safeSeconds % 3600) / 60);
        var secs = safeSeconds % 60;

        return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }

    function initCountdown() {
        if (!countdownEl) {
            return;
        }

        var i18n = config.i18n || {};
        var targetTimestamp = Number(config.nextPuzzleTimestamp || 0);
        if (!targetTimestamp) {
            countdownEl.textContent = '';
            return;
        }

        function render() {
            var nowTs = Math.floor(Date.now() / 1000);
            var diff = Math.max(0, targetTimestamp - nowTs);
            countdownEl.textContent = (i18n.countdown_label || 'Nächstes Rätsel in') + ': ' + formatCountdown(diff);
        }

        render();
        window.setInterval(render, 1000);
    }

    function clearLanguageStateFromLocalStorage(languageCode) {
        if (!isLocalStorageAvailable()) {
            return;
        }

        var suffix = '|' + languageCode;
        var keysToRemove = [];

        for (var i = 0; i < window.localStorage.length; i++) {
            var key = window.localStorage.key(i);
            if (!key) {
                continue;
            }

            if (key.indexOf(localStoragePrefix) === 0 && key.slice(-suffix.length) === suffix) {
                keysToRemove.push(key);
            }
        }

        keysToRemove.forEach(function (key) {
            window.localStorage.removeItem(key);
        });
    }

    function resetStatistics() {
        var i18n = config.i18n || {};
        var confirmText = i18n.stats_reset_confirm || 'Möchtest du die Statistik für diese Sprache wirklich zurücksetzen?';

        if (!window.confirm(confirmText)) {
            return;
        }

        if (currentLanguage) {
            clearLanguageStateFromLocalStorage(currentLanguage);
        }

        if (!config.ajaxUrl || !config.nonce) {
            window.location.reload();
            return;
        }

        var params = new URLSearchParams();
        params.set('action', 'ahx_wp_polylex_reset_stats');
        params.set('nonce', config.nonce);
        params.set('language', currentLanguage);

        fetch(config.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString()
        }).finally(function () {
            window.location.reload();
        });
    }

    function initStatsActions() {
        var resetFooterBtn = root.querySelector('#ahx-polylex-stats-reset-footer');
        if (resetFooterBtn) {
            resetFooterBtn.addEventListener('click', function () {
                resetStatistics();
            });
        }

        if (!statsEl) {
            return;
        }

        var resetButton = statsEl.querySelector('.ahx-polylex__stats-reset');
        if (!resetButton) {
            return;
        }

        resetButton.addEventListener('click', function () {
            resetStatistics();
        });
    }

    function normalizeGuessArray(input) {
        if (!Array.isArray(input)) {
            return [];
        }

        var normalized = [];
        for (var i = 0; i < input.length; i++) {
            var guess = normalizeStorageWord(input[i]);
            if (guess !== '') {
                normalized.push(guess.toLowerCase());
            }
            if (normalized.length >= rows) {
                break;
            }
        }

        return normalized;
    }

    function loadSavedGuessesFromLocalStorage() {
        if (!isLocalStorageAvailable()) {
            return [];
        }

        try {
            var raw = window.localStorage.getItem(localStorageKey);
            if (!raw) {
                return [];
            }

            var parsed = JSON.parse(raw);
            return normalizeGuessArray(parsed);
        } catch (error) {
            return [];
        }
    }

    function saveGuessesToLocalStorage(guessList) {
        if (!isLocalStorageAvailable()) {
            return false;
        }

        try {
            window.localStorage.setItem(localStorageKey, JSON.stringify(guessList));
            return true;
        } catch (error) {
            return false;
        }
    }

    function resolveInitialSavedGuesses() {
        var serverGuesses = normalizeGuessArray(Array.isArray(config.savedGuesses) ? config.savedGuesses : []);

        if (activePersistenceMode !== 'local_storage') {
            return serverGuesses;
        }

        var localGuesses = loadSavedGuessesFromLocalStorage();
        if (localGuesses.length > 0) {
            return localGuesses;
        }

        if (serverGuesses.length > 0) {
            saveGuessesToLocalStorage(serverGuesses);
            return serverGuesses;
        }

        return [];
    }

    function initLanguageSelector() {
        if (!languageSelectEl) {
            return;
        }

        languageSelectEl.addEventListener('change', function () {
            var selectedLanguage = String(languageSelectEl.value || '');
            if (!selectedLanguage) {
                return;
            }

            var paramName = config.languageParam || 'ahx_polylex_lang';
            var url = new URL(window.location.href);
            url.searchParams.set(paramName, selectedLanguage);
            window.location.href = url.toString();
        });
    }

    function createBoard() {
        boardEl.innerHTML = '';
        board = [];

        for (var r = 0; r < rows; r++) {
            var row = document.createElement('div');
            row.className = 'ahx-polylex__row';
            row.setAttribute('role', 'row');
            board[r] = [];

            for (var c = 0; c < cols; c++) {
                var cell = document.createElement('div');
                cell.className = 'ahx-polylex__cell';
                cell.setAttribute('role', 'gridcell');
                cell.dataset.row = String(r);
                cell.dataset.col = String(c);
                row.appendChild(cell);
                board[r][c] = cell;
            }

            boardEl.appendChild(row);
        }
    }

    function createKeyboard() {
        keyboardEl.innerHTML = '';
        var rowsLayout = isGerman
            ? [
                ['Q', 'W', 'E', 'R', 'T', 'Z', 'U', 'I', 'O', 'P'],
                ['A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L'],
                ['Y', 'X', 'C', 'V', 'B', 'N', 'M'],
                ['ENTER', 'Ä', 'Ö', 'Ü', 'ẞ', '←']
            ]
            : [
                ['Q', 'W', 'E', 'R', 'T', 'Z', 'U', 'I', 'O', 'P'],
                ['A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L'],
                ['Y', 'X', 'C', 'V', 'B', 'N', 'M'],
                ['ENTER', '←']
            ];

        var standardKeyColumns = 0;
        rowsLayout.forEach(function (tokens) {
            var standardKeyCount = tokens.filter(function (token) {
                return token !== 'ENTER' && token !== '←';
            }).length;

            if (standardKeyCount > standardKeyColumns) {
                standardKeyColumns = standardKeyCount;
            }
        });

        keyboardEl.style.setProperty('--ahx-key-columns', String(Math.max(standardKeyColumns, 1)));

        rowsLayout.forEach(function (tokens) {
            var rowEl = document.createElement('div');
            rowEl.className = 'ahx-polylex__keys-row';

            tokens.forEach(function (token) {
                var key = document.createElement('button');
                key.type = 'button';
                key.className = 'ahx-polylex__key';
                key.dataset.key = token;
                key.textContent = token === 'ENTER' ? 'SET' : token;
                if (token === 'ENTER' || token === '←') {
                    key.classList.add('ahx-polylex__key--wide');
                }
                rowEl.appendChild(key);
            });

            keyboardEl.appendChild(rowEl);
        });
    }

    function renderCurrentGuess() {
        var rowIndex = guesses.length;
        if (rowIndex >= rows) {
            return;
        }

        var activeCells = boardEl.querySelectorAll('.ahx-polylex__cell--active');
        for (var i = 0; i < activeCells.length; i++) {
            activeCells[i].classList.remove('ahx-polylex__cell--active');
        }

        for (var c = 0; c < cols; c++) {
            board[rowIndex][c].textContent = currentGuess[c] || '';
            if (!gameOver && c === activeCellIndex) {
                board[rowIndex][c].classList.add('ahx-polylex__cell--active');
            } else {
                board[rowIndex][c].classList.remove('ahx-polylex__cell--active');
            }
        }
    }

    function getCurrentGuessString() {
        return currentGuess.join('');
    }

    function isCurrentGuessComplete() {
        return currentGuess.every(function (char) {
            return char && char.length === 1;
        });
    }

    function evaluateGuess(guess, target) {
        var result = new Array(cols).fill('absent');
        var remaining = {};

        for (var i = 0; i < cols; i++) {
            if (guess[i] === target[i]) {
                result[i] = 'correct';
            } else {
                remaining[target[i]] = (remaining[target[i]] || 0) + 1;
            }
        }

        for (var j = 0; j < cols; j++) {
            if (result[j] !== 'correct') {
                var letter = guess[j];
                if (remaining[letter]) {
                    result[j] = 'present';
                    remaining[letter]--;
                }
            }
        }

        return result;
    }

    function updateKeyboard(guess, result) {
        var priority = { absent: 0, present: 1, correct: 2 };

        for (var i = 0; i < guess.length; i++) {
            var letter = guess[i];
            var key = keyboardEl.querySelector('.ahx-polylex__key[data-key="' + letter + '"]');
            if (!key) {
                continue;
            }
            var current = key.dataset.state || '';
            var next = result[i];
            if (!current || priority[next] > priority[current]) {
                key.dataset.state = next;
            }
        }
    }

    function lockRow(guess, result) {
        var rowIndex = guesses.length;
        for (var i = 0; i < cols; i++) {
            var cell = board[rowIndex][i];
            cell.textContent = guess[i];
            cell.dataset.state = result[i];
        }
    }

    function shakeActiveRow() {
        var rowIndex = guesses.length;
        if (rowIndex < 0 || rowIndex >= rows) {
            return;
        }

        for (var i = 0; i < cols; i++) {
            var cell = board[rowIndex][i];
            if (!cell) {
                continue;
            }

            cell.classList.remove('ahx-polylex__cell--shake');
            void cell.offsetWidth;
            cell.classList.add('ahx-polylex__cell--shake');

            var onShakeEnd = function (event) {
                if (event.animationName !== 'ahx-polylex-invalid-shake') {
                    return;
                }

                cell.classList.remove('ahx-polylex__cell--shake');
                cell.removeEventListener('animationend', onShakeEnd);
            };

            cell.addEventListener('animationend', onShakeEnd);
        }
    }

    function revealRowAnimated(guess, result) {
        var rowIndex = guesses.length;
        var rowCells = board[rowIndex] || [];
        var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (prefersReducedMotion || !rowCells.length) {
            lockRow(guess, result);
            return Promise.resolve();
        }

        var revealDurationMs = 700;
        var revealStepDelayMs = 280;

        return new Promise(function (resolve) {
            var finishedCells = 0;

            rowCells.forEach(function (cell, index) {
                cell.textContent = guess[index];
                cell.dataset.state = '';
                cell.classList.remove('ahx-polylex__cell--shake');
                cell.style.setProperty('--flip-delay', String(index * revealStepDelayMs) + 'ms');
                cell.classList.remove('ahx-polylex__cell--flip');

                window.setTimeout(function () {
                    cell.dataset.state = result[index];
                }, (index * revealStepDelayMs) + Math.floor(revealDurationMs / 2));

                var onAnimationEnd = function (event) {
                    if (event.animationName !== 'ahx-polylex-reveal-flip') {
                        return;
                    }

                    cell.removeEventListener('animationend', onAnimationEnd);
                    cell.classList.remove('ahx-polylex__cell--flip');
                    cell.style.removeProperty('--flip-delay');
                    finishedCells++;

                    if (finishedCells === cols) {
                        resolve();
                    }
                };

                cell.addEventListener('animationend', onAnimationEnd);
                void cell.offsetWidth;
                cell.classList.add('ahx-polylex__cell--flip');
            });
        });
    }

    function refreshStatisticsFromServer() {
        if (!config.ajaxUrl || !config.nonce) {
            return Promise.resolve(false);
        }

        var params = new URLSearchParams();
        params.set('action', 'ahx_wp_polylex_get_stats');
        params.set('nonce', config.nonce);
        params.set('language', currentLanguage);
        params.set('rows', String(rows));

        return fetch(config.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (!payload || !payload.success || !payload.data || !payload.data.statistics) {
                return false;
            }

            config.statistics = payload.data.statistics;
            renderStatistics();
            initStatsActions();
            return true;
        }).catch(function () {
            return false;
        });
    }

    function scrollToStatsView() {
        if (!statsEl || typeof statsEl.scrollIntoView !== 'function') {
            return;
        }

        window.requestAnimationFrame(function () {
            statsEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    function applyPostGameFooterLabels() {
        var i18n = config.i18n || {};
        var languageLabelEl = root.querySelector('.ahx-polylex__language label');
        var reportBtn = root.querySelector('#ahx-polylex-report-btn');
        var resetFooterBtn = root.querySelector('#ahx-polylex-stats-reset-footer');

        if (languageLabelEl) {
            languageLabelEl.textContent = String(i18n.language_label_short || i18n.language_label || languageLabelEl.textContent || 'Sprache');
        }

        if (reportBtn) {
            reportBtn.textContent = String(i18n.report_word_short || reportBtn.textContent || 'Melden');
        }

        if (resetFooterBtn) {
            resetFooterBtn.textContent = String(i18n.stats_reset_short || i18n.stats_reset || resetFooterBtn.textContent || 'Reset');
        }
    }

    function finish(win, options) {
        options = options || {};
        var hideGameArea = !!options.hideGameArea;
        var refreshStats = !!options.refreshStats;

        gameOver = true;
        renderCurrentGuess();

        if (hideGameArea) {
            root.classList.add('ahx-polylex--post-game-stats');
            applyPostGameFooterLabels();

            var gameViewRoot = root.closest('.ahx-polylex-view--game');
            if (gameViewRoot) {
                if (boardEl) {
                    boardEl.style.display = 'none';
                }
                if (keyboardEl) {
                    keyboardEl.style.display = 'none';
                }
            }

            if (statsEl) {
                statsEl.style.display = refreshStats ? 'none' : 'block';
            }

            var reportBtn = root.querySelector('#ahx-polylex-report-btn');
            var resetFooterBtn = root.querySelector('#ahx-polylex-stats-reset-footer');
            if (reportBtn) reportBtn.style.display = '';
            if (resetFooterBtn) resetFooterBtn.style.display = '';

            scrollToStatsView();
        }

        if (win) {
            setStatus(getWonMessage());
        } else {
            var lostText = (config.i18n && config.i18n.lost) || 'Das gesuchte Wort war: ';
            setStatus(lostText + targetWord);
        }

        if (hideGameArea && refreshStats) {
            refreshStatisticsFromServer().finally(function () {
                if (statsEl) {
                    statsEl.style.display = 'block';
                }

                scrollToStatsView();
            });
        }
    }

    function persistState() {
        if (activePersistenceMode === 'local_storage') {
            var localSaved = saveGuessesToLocalStorage(guesses.map(function (guess) {
                return guess.toLowerCase();
            }));

            if (localSaved) {
                if (!isLoggedIn) {
                    return persistStateToServer();
                }
                return Promise.resolve(true);
            }
        }

        return persistStateToServer();
    }

    function persistStateToServer() {
        if (!config.ajaxUrl || !config.nonce) {
            return Promise.resolve(false);
        }

        var params = new URLSearchParams();
        params.set('action', 'ahx_wp_polylex_save_state');
        params.set('nonce', config.nonce);
        params.set('puzzle_key', puzzleKey);
        params.set('guesses', JSON.stringify(guesses.map(function (guess) {
            return guess.toLowerCase();
        })));

        return fetch(config.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            return !!(payload && payload.success);
        }).catch(function () {
            return false;
        });
    }

    function trackUnknownWord(guess) {
        if (!config.ajaxUrl || !config.nonce || config.isAdmin) {
            return;
        }

        var params = new URLSearchParams();
        params.set('action', 'ahx_wp_polylex_track_unknown_word');
        params.set('nonce', config.nonce);
        params.set('language', currentLanguage);
        params.set('word', guess.toLowerCase());

        fetch(config.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString(),
            credentials: 'same-origin'
        }).catch(function () {
            return null;
        });
    }

    function addWordAsAdmin(guess, buttonEl) {
        if (!config.ajaxUrl || !config.nonce) {
            setStatus((config.i18n && config.i18n.add_word_error) || 'Wort konnte nicht hinzugefügt werden.');
            return;
        }

        if (buttonEl) {
            buttonEl.disabled = true;
        }

        var params = new URLSearchParams();
        params.set('action', 'ahx_wp_polylex_add_word');
        params.set('nonce', config.nonce);
        params.set('language', currentLanguage);
        params.set('word', guess.toLowerCase());

        fetch(config.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString()
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (!payload || !payload.success) {
                throw new Error('add_word_failed');
            }

            var wordLower = guess.toLowerCase();
            if (words.indexOf(wordLower) === -1) {
                words.push(wordLower);
            }

            setStatus((config.i18n && config.i18n.add_word_success) || 'Wort wurde hinzugefügt. Der Versuch wird jetzt gewertet.');
            processAcceptedGuess(guess);
        }).catch(function () {
            setStatus((config.i18n && config.i18n.add_word_error) || 'Wort konnte nicht hinzugefügt werden.');
            if (buttonEl) {
                buttonEl.disabled = false;
            }
        });
    }

    function applySavedGuesses() {
        if (!savedGuesses.length) {
            return;
        }

        for (var i = 0; i < savedGuesses.length; i++) {
            if (guesses.length >= rows) {
                break;
            }

            var guess = normalizeStorageWord(savedGuesses[i]);
            if (guess === '') {
                continue;
            }

            if (words.indexOf(guess.toLowerCase()) === -1) {
                continue;
            }

            var result = evaluateGuess(guess, targetWord);
            lockRow(guess, result);
            updateKeyboard(guess, result);
            guesses.push(guess);

            if (guess === targetWord) {
                finish(true, { hideGameArea: false, refreshStats: false });
                return;
            }
        }

        if (guesses.length >= rows) {
            finish(false, { hideGameArea: false, refreshStats: false });
            return;
        }

        currentGuess = new Array(cols).fill('');
        activeCellIndex = 0;
    }

    function submitGuess() {
        if (isAnimating) {
            return;
        }

        if (!isCurrentGuessComplete()) {
            setStatus((config.i18n && config.i18n.not_enough_letters) || 'Zu wenige Buchstaben.');
            return;
        }

        var guess = getCurrentGuessString().toUpperCase();
        if (words.indexOf(guess.toLowerCase()) === -1) {
            shakeActiveRow();

            if (config.isAdmin) {
                setStatusWithAction(
                    (config.i18n && config.i18n.add_word_prompt) || 'Dieses Wort ist nicht in der Liste. Als Administrator kannst du es hinzufügen.',
                    (config.i18n && config.i18n.add_word_button) || 'Wort hinzufügen',
                    function (buttonEl) {
                        addWordAsAdmin(guess, buttonEl);
                    }
                );
                return;
            }

            trackUnknownWord(guess);

            setStatus((config.i18n && config.i18n.not_in_list) || 'Wort nicht erlaubt.');
            return;
        }

        processAcceptedGuess(guess);
    }

    function processAcceptedGuess(guess) {
        var result = evaluateGuess(guess, targetWord);
        isAnimating = true;

        revealRowAnimated(guess, result).then(function () {
            isAnimating = false;
            updateKeyboard(guess, result);
            guesses.push(guess);
            currentGuess = new Array(cols).fill('');
            activeCellIndex = 0;
            persistState().finally(function () {
                if (guess === targetWord) {
                    finish(true, { hideGameArea: true, refreshStats: true });
                    return;
                }

                if (guesses.length >= rows) {
                    finish(false, { hideGameArea: true, refreshStats: true });
                    return;
                }

                renderCurrentGuess();
            });
        });
    }

    function handleInput(key) {
        if (gameOver || isAnimating) {
            return;
        }

        setStatus('');

        if (key === 'ARROWLEFT') {
            setActiveCell(activeCellIndex - 1);
            return;
        }

        if (key === 'ARROWRIGHT') {
            setActiveCell(activeCellIndex + 1);
            return;
        }

        if (key === 'ENTER') {
            submitGuess();
            return;
        }

        if (key === 'BACKSPACE' || key === '←') {
            if (currentGuess[activeCellIndex]) {
                currentGuess[activeCellIndex] = '';
            } else if (activeCellIndex > 0) {
                activeCellIndex--;
                currentGuess[activeCellIndex] = '';
            }
            renderCurrentGuess();
            return;
        }

        if (isAllowedLetterChar(key)) {
            currentGuess[activeCellIndex] = key;
            if (activeCellIndex < cols - 1) {
                activeCellIndex++;
            }
            renderCurrentGuess();
        }
    }

    function setActiveCell(colIndex) {
        if (gameOver) {
            return;
        }

        if (colIndex < 0) {
            colIndex = 0;
        }
        if (colIndex > cols - 1) {
            colIndex = cols - 1;
        }

        activeCellIndex = colIndex;
        renderCurrentGuess();
    }

    document.addEventListener('keydown', function (event) {
        var key = event.key;
        if (key === 'Backspace') {
            handleInput('BACKSPACE');
        } else if (key === 'ArrowLeft') {
            handleInput('ARROWLEFT');
        } else if (key === 'ArrowRight') {
            handleInput('ARROWRIGHT');
        } else if (key === 'Enter') {
            handleInput('ENTER');
        } else {
            var normalized = normalizeInputChar(key);
            if (normalized !== '') {
                handleInput(normalized);
            }
        }
    });

    keyboardEl.addEventListener('click', function (event) {
        var button = event.target.closest('.ahx-polylex__key');
        if (!button) {
            return;
        }
        handleInput(button.dataset.key);
    });

    keyboardEl.addEventListener('dblclick', function (event) {
        event.preventDefault();
    });

    var lastKeyboardTouchEndAt = 0;
    keyboardEl.addEventListener('touchend', function (event) {
        var now = Date.now();
        if (now - lastKeyboardTouchEndAt < 350) {
            event.preventDefault();
        }
        lastKeyboardTouchEndAt = now;
    }, { passive: false });

    boardEl.addEventListener('click', function (event) {
        var cell = event.target.closest('.ahx-polylex__cell');
        if (!cell || !boardEl.contains(cell)) {
            return;
        }

        var rowIndex = Number(cell.dataset.row);
        var colIndex = Number(cell.dataset.col);

        if (rowIndex !== guesses.length) {
            return;
        }

        setActiveCell(colIndex);
    });

    function initReportModal() {
        var overlay = document.getElementById('ahx-polylex-report-modal');
        var reportBtn = document.getElementById('ahx-polylex-report-btn');
        var closeBtn = document.getElementById('ahx-polylex-report-close');
        var cancelBtn = document.getElementById('ahx-polylex-report-cancel');
        var form = document.getElementById('ahx-polylex-report-form');

        if (!overlay || !reportBtn || !closeBtn || !cancelBtn || !form) {
            return;
        }

        var modalTitle = document.getElementById('ahx-polylex-modal-title');
        var modalTitleBase = (config.i18n && config.i18n.report_word_title) || 'Lösungswort melden';

        function openModal() {
            if (modalTitle) {
                modalTitle.textContent = modalTitleBase + ': ' + targetWord.toUpperCase();
            }
            overlay.classList.add('show');
        }

        function closeModal() {
            overlay.classList.remove('show');
        }

        reportBtn.addEventListener('click', function (e) {
            e.preventDefault();
            openModal();
        });

        closeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            closeModal();
        });

        cancelBtn.addEventListener('click', function (e) {
            e.preventDefault();
            closeModal();
        });

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                closeModal();
            }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var reason = document.querySelector('input[name="reason"]:checked');
            if (!reason) {
                return;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', config.ajaxUrl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function () {
                if (xhr.status === 200) {
                    closeModal();
                    setTimeout(function () {
                        var statusEl = document.querySelector('.ahx-polylex__status') || document.querySelector('.ahx-polylex');
                        if (statusEl) {
                            var msg = document.createElement('div');
                            msg.textContent = 'Vielen Dank für deine Meldung!';
                            msg.style.cssText = 'color: #10b981; font-weight: 600; margin: 8px 0;';
                            statusEl.parentNode.insertBefore(msg, statusEl);
                            setTimeout(function () {
                                msg.remove();
                            }, 3000);
                        }
                    }, 200);
                }
            };

            xhr.send('action=ahx_wp_polylex_report_word' +
                '&nonce=' + encodeURIComponent(config.nonce) +
                '&language=' + encodeURIComponent(currentLanguage) +
                '&word=' + encodeURIComponent(targetWord) +
                '&reason=' + encodeURIComponent(reason.value));
        });
    }

    savedGuesses = resolveInitialSavedGuesses();

    createBoard();
    createKeyboard();
    renderStatistics();
    initStatsActions();
    initCountdown();
    initLanguageSelector();
    initReportModal();
    applySavedGuesses();
    renderCurrentGuess();

    // If rendered via [ahx_polylex_stats] shortcode, show reset button and hide report button
    if (root.closest('.ahx-polylex-view--stats')) {
        var reportBtn = root.querySelector('#ahx-polylex-report-btn');
        var resetFooterBtn = root.querySelector('#ahx-polylex-stats-reset-footer');
        if (reportBtn) reportBtn.style.display = 'none';
        if (resetFooterBtn) resetFooterBtn.style.display = '';
    }
})();


