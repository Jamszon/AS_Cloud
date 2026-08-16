<?php
/**
 * db.php — konfiguracja, połączenie z SQLite, schemat bazy, sesja,
 *          ochrona CSRF, dziennik aktywności i drobne helpery.
 *
 * Plik dołączany przez index.php oraz api.php. Nie wywołuje się go bezpośrednio.
 */
declare(strict_types=1);

/* ================================================================== *
 *  KONFIGURACJA — to jedyne miejsce, które zwykle chcesz zmieniać
 * ================================================================== */

/** Pokazywać treść błędów PHP w odpowiedziach API? Na produkcji: false. */
const DEBUG = false;

const APP_NAME         = 'Panel zespołu';
const DEFAULT_PASSWORD = 'projekt123';   // hasło startowe dla wszystkich profili
const MAX_UPLOAD_BYTES = 15728640;       // 15 MB
const ACTIVITY_LIMIT   = 20;             // ile wpisów pokazuje dziennik zmian
const ACTIVITY_KEEP    = 500;            // ile wpisów trzymamy w bazie
const LOGIN_MAX_FAILS  = 8;              // blokada logowania po N nieudanych próbach
const LOGIN_LOCK_MIN   = 15;             // ...na ile minut
const SCHEMA_VERSION   = 4;

/* Żaden komunikat PHP nie może trafić do odpowiedzi: zepsułby JSON API,
   a przy okazji uniemożliwił wysłanie nagłówków sesji. Błędy idą do logu.
   (Ostrzeżenia z samego startu żądania wycisza dodatkowo plik .user.ini.) */
ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('log_errors', '1');

date_default_timezone_set('Europe/Warsaw');

define('BASE_DIR',   __DIR__);
define('DATA_DIR',   BASE_DIR . '/data');      // baza SQLite (niedostępna z www)
define('UPLOAD_DIR', BASE_DIR . '/uploads');   // załączniki (niedostępne z www)
define('DB_FILE',    DATA_DIR . '/panel.sqlite');
define('LOG_FILE',   DATA_DIR . '/error.log');

/** Zespół — kolejność wyznacza układ kafelków na ekranie logowania. */
const TEAM = [
    ['name' => 'Alan',   'color' => 'violet'],
    ['name' => 'Hubert', 'color' => 'cyan'],
    ['name' => 'Szymon', 'color' => 'emerald'],
    ['name' => 'Adrian', 'color' => 'orange'],
];

/**
 * Paleta akcentów użytkowników — przekazywana również do JavaScriptu.
 * Warianty "Dark" są używane, gdy panel działa w trybie ciemnym.
 */
const COLORS = [
    'violet'  => ['solid' => '#8b5cf6', 'soft' => '#f5f3ff', 'ink' => '#5b21b6', 'ring' => '#ddd6fe',
                  'softDark' => '#2e1065', 'inkDark' => '#c4b5fd'],
    'cyan'    => ['solid' => '#06b6d4', 'soft' => '#ecfeff', 'ink' => '#155e75', 'ring' => '#a5f3fc',
                  'softDark' => '#083344', 'inkDark' => '#67e8f9'],
    'emerald' => ['solid' => '#10b981', 'soft' => '#ecfdf5', 'ink' => '#065f46', 'ring' => '#a7f3d0',
                  'softDark' => '#022c22', 'inkDark' => '#6ee7b7'],
    'orange'  => ['solid' => '#f97316', 'soft' => '#fff7ed', 'ink' => '#9a3412', 'ring' => '#fed7aa',
                  'softDark' => '#431407', 'inkDark' => '#fdba74'],
];

/** Priorytety zadań — od najpilniejszego. */
const PRIORITIES = ['high', 'normal', 'low'];

/** Dozwolone rozszerzenia załączników => typ MIME zwracany przy pobieraniu. */
const ALLOWED_EXT = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'zip'  => 'application/zip',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

/** Kolumny tablicy kanban. */
const STATUSES = ['todo', 'doing', 'done'];

/* ================================================================== *
 *  WYJĄTKI
 * ================================================================== */

/** Błąd biznesowy — jego treść jest bezpieczna do pokazania użytkownikowi. */
class ApiError extends RuntimeException {}

/** Problem z instalacją (katalogi, uprawnienia) — pokazujemy zawsze. */
class SetupError extends RuntimeException {}

/* ================================================================== *
 *  SESJA I CSRF
 * ================================================================== */

/**
 * Czy ciasteczko sesji ma dostać flagę Secure?
 * Wykrywamy to ostrożnie: flaga Secure na stronie bez HTTPS powoduje, że
 * przeglądarka w ogóle nie odsyła ciasteczka, a wtedy logowanie kończy się
 * komunikatem „Formularz stracił ważność”. Dlatego opieramy się wyłącznie na
 * $_SERVER['HTTPS'] — nagłówki od proxy bywają ustawiane błędnie.
 *
 * Gdy panel działa wyłącznie po HTTPS, a serwer stoi za proxy kończącym TLS,
 * ustaw SECURE_COOKIE na true.
 */
const SECURE_COOKIE = null;   // null = automatycznie, true = wymuś, false = nigdy

function use_secure_cookie(): bool
{
    if (SECURE_COOKIE !== null) {
        return (bool)SECURE_COOKIE;
    }
    return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
}

/**
 * Ustala katalog na pliki sesji. Część hostingów współdzielonych ma
 * session.save_path wskazujący na katalog bez prawa zapisu — wtedy sesja
 * nie zapisuje się wcale. W takim wypadku przechodzimy na własny katalog
 * data/sessions (chroniony tym samym .htaccess co baza).
 */
function ensure_session_storage(): void
{
    $configured = (string)ini_get('session.save_path');

    /* Format bywa zapisany jako "N;/sciezka" albo "N;0600;/sciezka". */
    if (strpos($configured, ';') !== false) {
        $parts      = explode(';', $configured);
        $configured = (string)end($parts);
    }

    if ($configured !== '' && is_dir($configured) && is_writable($configured)) {
        return;
    }

    $own = DATA_DIR . '/sessions';
    if (!is_dir($own)) {
        @mkdir($own, 0700, true);
    }
    if (is_dir($own) && is_writable($own)) {
        session_save_path($own);
    }
}

function boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ensure_session_storage();

    $secure = use_secure_cookie();

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $secure,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }
    session_name('PANELSID');
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(?string $sent): bool
{
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

/* ================================================================== *
 *  PRZECHOWYWANIE DANYCH
 * ================================================================== */

/** Treść .htaccess blokującego dostęp do katalogu z przeglądarki. */
function htaccess_deny(): string
{
    return <<<'TXT'
# Katalog wewnętrzny aplikacji — brak dostępu z przeglądarki.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

# Gdyby serwer ignorował powyższe — nie uruchamiaj tu żadnych skryptów.
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .cgi .pl .py
RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar
Options -ExecCGI -Indexes
TXT;
}

/** Tworzy katalogi data/ i uploads/ wraz z plikami ochronnymi. */
function ensure_storage(): void
{
    foreach ([DATA_DIR, UPLOAD_DIR] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            throw new SetupError(
                'Nie udało się utworzyć katalogu "' . basename($dir) . '". ' .
                'Utwórz go ręcznie przez FTP obok index.php i nadaj mu prawa zapisu (chmod 755, a jeśli to nie pomoże — 777).'
            );
        }
        if (!is_writable($dir)) {
            throw new SetupError(
                'Katalog "' . basename($dir) . '" nie ma prawa zapisu. ' .
                'Ustaw mu przez FTP uprawnienia chmod 755 (na części hostingów potrzebne jest 777).'
            );
        }
        guard_file($dir . '/.htaccess', htaccess_deny());
        guard_file($dir . '/index.html', '');
    }
}

/** Zapisuje plik ochronny, jeśli go nie ma (nie nadpisuje ręcznych zmian). */
function guard_file(string $path, string $content): void
{
    if (!file_exists($path)) {
        @file_put_contents($path, $content);
    }
}

/** Połączenie z bazą; przy pierwszym uruchomieniu tworzy plik i schemat. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    ensure_storage();

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new SetupError('Ten serwer nie ma włączonego rozszerzenia PDO SQLite (pdo_sqlite). Poproś hosting o jego włączenie.');
    }

    $fresh = !file_exists(DB_FILE);

    try {
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        throw new SetupError('Nie udało się otworzyć bazy danych w katalogu "data". Sprawdź uprawnienia zapisu (chmod 755 lub 777).');
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    if ($fresh) {
        @chmod(DB_FILE, 0640);
    }

    install_schema($pdo);
    seed_users($pdo);

    return $pdo;
}

function install_schema(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version === SCHEMA_VERSION) {
        return;
    }
    $pdo->exec(schema_sql());   // nowe instalacje dostają komplet tabel od razu
    migrate_schema($pdo, $version);
    $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
}

/** Czy tabela ma już taką kolumnę? */
function column_exists(PDO $pdo, string $table, string $column): bool
{
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

function add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!column_exists($pdo, $table, $column)) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}

/**
 * Dostawia to, czego brakuje w bazie utworzonej starszą wersją panelu.
 * Dane użytkowników zostają nietknięte.
 */
function migrate_schema(PDO $pdo, int $from): void
{
    /* v1 -> v2: priorytety zadań oraz ręczna kolejność folderów. */
    if ($from < 2) {
        add_column($pdo, 'tasks', 'priority', "TEXT NOT NULL DEFAULT 'normal'");
        add_column($pdo, 'folders', 'position', 'INTEGER NOT NULL DEFAULT 0');

        /* Istniejącym folderom nadajemy kolejność alfabetyczną — czyli taką,
           jaką użytkownicy widzieli do tej pory. */
        $ids  = $pdo->query('SELECT id FROM folders ORDER BY name COLLATE NOCASE, id')->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare('UPDATE folders SET position = ? WHERE id = ?');
        foreach ($ids as $index => $id) {
            $stmt->execute([$index, (int)$id]);
        }
    }

    /* v2 -> v3: wiele osób na zadaniu oraz załączniki podpięte pod zadanie. */
    if ($from < 3) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS task_assignees (
                task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id),
                PRIMARY KEY (task_id, user_id)
            )'
        );

        /* Dotychczasowe pojedyncze przypisania przenosimy do nowej tabeli. */
        if (column_exists($pdo, 'tasks', 'assignee_id')) {
            $pdo->exec(
                'INSERT OR IGNORE INTO task_assignees (task_id, user_id)
                 SELECT id, assignee_id FROM tasks WHERE assignee_id IS NOT NULL'
            );
        }

        add_column($pdo, 'files', 'task_id', 'INTEGER');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_files_task ON files(task_id)');
    }

    /* v3 -> v4: termin wykonania zadania oraz komentarze pod zadaniem. */
    if ($from < 4) {
        add_column($pdo, 'tasks', 'due_date', 'TEXT');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS task_comments (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id    INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                user_id    INTEGER NOT NULL REFERENCES users(id),
                body       TEXT    NOT NULL,
                created_at TEXT    NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_task ON task_comments(task_id)');
    }
}

function schema_sql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL UNIQUE,
    color         TEXT    NOT NULL,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS folders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    position   INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT    NOT NULL,
    updated_by INTEGER REFERENCES users(id),
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS tasks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    folder_id   INTEGER NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
    title       TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    status      TEXT    NOT NULL DEFAULT 'todo',
    priority    TEXT    NOT NULL DEFAULT 'normal',
    due_date    TEXT,
    assignee_id INTEGER REFERENCES users(id),
    created_by  INTEGER NOT NULL REFERENCES users(id),
    created_at  TEXT    NOT NULL,
    updated_by  INTEGER REFERENCES users(id),
    updated_at  TEXT
);

CREATE TABLE IF NOT EXISTS notes (
    folder_id  INTEGER PRIMARY KEY REFERENCES folders(id) ON DELETE CASCADE,
    content    TEXT    NOT NULL DEFAULT '',
    updated_by INTEGER REFERENCES users(id),
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS files (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    folder_id     INTEGER NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
    task_id       INTEGER REFERENCES tasks(id),
    original_name TEXT    NOT NULL,
    stored_name   TEXT    NOT NULL UNIQUE,
    ext           TEXT    NOT NULL,
    size          INTEGER NOT NULL,
    uploaded_by   INTEGER NOT NULL REFERENCES users(id),
    uploaded_at   TEXT    NOT NULL
);

-- Zadanie może mieć wiele osób odpowiedzialnych.
CREATE TABLE IF NOT EXISTS task_assignees (
    task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id),
    PRIMARY KEY (task_id, user_id)
);

-- Dyskusja pod zadaniem, oddzielona od opisu.
CREATE TABLE IF NOT EXISTS task_comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id    INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_comments_task ON task_comments(task_id);

CREATE TABLE IF NOT EXISTS activity (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id),
    action      TEXT    NOT NULL,
    message     TEXT    NOT NULL,
    folder_id   INTEGER,
    folder_name TEXT,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
    ip       TEXT    PRIMARY KEY,
    fails    INTEGER NOT NULL DEFAULT 0,
    last_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_tasks_folder    ON tasks(folder_id);
CREATE INDEX IF NOT EXISTS idx_files_folder    ON files(folder_id);
CREATE INDEX IF NOT EXISTS idx_activity_recent ON activity(id DESC);
-- Uwaga: indeksu na files(task_id) NIE zakładamy tutaj. Przy bazie z wcześniejszej
-- wersji panelu ta kolumna jeszcze nie istnieje, a nieudane CREATE INDEX przerwałoby
-- cały blok, zanim migracja zdążyłaby ją dostawić. Zakłada go migrate_schema().
SQL;
}

/** Dodaje brakujące profile z listy TEAM (hasło hashujemy tylko gdy trzeba). */
function seed_users(PDO $pdo): void
{
    $existing = $pdo->query('SELECT name FROM users')->fetchAll(PDO::FETCH_COLUMN);

    $missing = [];
    foreach (TEAM as $member) {
        if (!in_array($member['name'], $existing, true)) {
            $missing[] = $member;
        }
    }
    if (!$missing) {
        return;
    }

    $hash = password_hash(DEFAULT_PASSWORD, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO users (name, color, password_hash, created_at) VALUES (?, ?, ?, ?)');
    foreach ($missing as $member) {
        $stmt->execute([$member['name'], $member['color'], $hash, now()]);
    }
}

/* ================================================================== *
 *  UŻYTKOWNICY
 * ================================================================== */

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $cache = null;
    $uid = (int)$_SESSION['uid'];
    if ($cache !== null && $cache['id'] === $uid) {
        return $cache;
    }
    $stmt = db()->prepare('SELECT id, name, color FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['uid']);
        return null;
    }
    $row['id'] = (int)$row['id'];
    $cache = $row;
    return $cache;
}

/** Lista profili do ekranu logowania i do przypisywania zadań. */
function all_users(): array
{
    $rows = db()->query('SELECT id, name, color FROM users ORDER BY id')->fetchAll();
    foreach ($rows as $i => $row) {
        $rows[$i]['id'] = (int)$row['id'];
    }
    return $rows;
}

/* ------------------------- ochrona logowania ---------------------- */

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Ile sekund pozostało do końca blokady logowania (0 = brak blokady). */
function login_lock_seconds(): int
{
    $stmt = db()->prepare('SELECT fails, last_at FROM login_attempts WHERE ip = ?');
    $stmt->execute([client_ip()]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['fails'] < LOGIN_MAX_FAILS) {
        return 0;
    }
    $elapsed = time() - (int)strtotime((string)$row['last_at']);
    $left    = (LOGIN_LOCK_MIN * 60) - $elapsed;

    if ($left <= 0) {
        /* Okno blokady minęło — licznik startuje od zera, żeby jedna
           literówka nie zamykała panelu na kolejne 15 minut. */
        login_reset_failures();
        return 0;
    }
    return $left;
}

function login_note_failure(): void
{
    /* Bez składni UPSERT — działa również na starszych bibliotekach SQLite. */
    $update = db()->prepare('UPDATE login_attempts SET fails = fails + 1, last_at = ? WHERE ip = ?');
    $update->execute([now(), client_ip()]);

    if ($update->rowCount() === 0) {
        db()->prepare('INSERT OR IGNORE INTO login_attempts (ip, fails, last_at) VALUES (?, 1, ?)')
            ->execute([client_ip(), now()]);
    }
}

function login_reset_failures(): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
}

/**
 * Ustawienie DEFAULT_PASSWORD z db.php jest nadrzędne wobec bazy.
 * Gdy zmienisz hasło w konfiguracji, hashe wszystkich profili są przeliczane
 * przy najbliższej próbie logowania — zmiana hasła to edycja jednej linijki.
 */
function sync_passwords_to_config(): void
{
    db()->prepare('UPDATE users SET password_hash = ?')
        ->execute([password_hash(DEFAULT_PASSWORD, PASSWORD_DEFAULT)]);
}

/**
 * Próba logowania. Zwraca dane użytkownika lub null.
 * Rzuca ApiError, gdy adres IP jest chwilowo zablokowany.
 */
function attempt_login(int $userId, string $password): ?array
{
    $lock = login_lock_seconds();
    if ($lock > 0) {
        throw new ApiError('Zbyt wiele nieudanych prób. Spróbuj ponownie za ' . (int)ceil($lock / 60) . ' min.');
    }

    $stmt = db()->prepare('SELECT id, name, color, password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row) {
        login_note_failure();
        return null;
    }

    $matchesConfig = hash_equals(DEFAULT_PASSWORD, $password);
    $matchesStored = password_verify($password, (string)$row['password_hash']);

    /* Baza rozjechała się z konfiguracją — przeliczamy hashe i sprawdzamy raz jeszcze. */
    if ($matchesConfig !== $matchesStored) {
        sync_passwords_to_config();
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $matchesStored = $row && password_verify($password, (string)$row['password_hash']);
    }

    if (!$matchesStored) {
        login_note_failure();
        return null;
    }

    login_reset_failures();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];

    log_activity((int)$row['id'], 'auth.login', 'Logowanie do panelu');

    return ['id' => (int)$row['id'], 'name' => $row['name'], 'color' => $row['color']];
}

/* ================================================================== *
 *  DZIENNIK AKTYWNOŚCI
 * ================================================================== */

function log_activity(int $userId, string $action, string $message, ?int $folderId = null, ?string $folderName = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO activity (user_id, action, message, folder_id, folder_name, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $action, mb_substr($message, 0, 300), $folderId, $folderName, now()]);

    // Co jakiś czas przycinamy dziennik, żeby baza nie puchła.
    if (random_int(1, 25) === 1) {
        db()->exec(
            'DELETE FROM activity WHERE id NOT IN (SELECT id FROM activity ORDER BY id DESC LIMIT ' . ACTIVITY_KEEP . ')'
        );
    }
}

function activity_feed(int $limit = ACTIVITY_LIMIT): array
{
    $limit = max(1, min(100, $limit));
    $rows  = db()->query(
        'SELECT a.id, a.action, a.message, a.folder_id, a.folder_name, a.created_at,
                u.name AS user_name, u.color AS user_color
         FROM activity a
         JOIN users u ON u.id = a.user_id
         ORDER BY a.id DESC
         LIMIT ' . $limit
    )->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id'          => (int)$row['id'],
            'action'      => $row['action'],
            'message'     => $row['message'],
            'folder_id'   => $row['folder_id'] !== null ? (int)$row['folder_id'] : null,
            'folder_name' => $row['folder_name'],
            'user_name'   => $row['user_name'],
            'user_color'  => $row['user_color'],
            'at'          => iso($row['created_at']),
        ];
    }
    return $out;
}

function last_activity_id(): int
{
    return (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM activity')->fetchColumn();
}

/* ================================================================== *
 *  HELPERY
 * ================================================================== */

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** Czas z bazy => ISO 8601 (JavaScript parsuje to jednoznacznie). */
function iso(?string $sqlTime): ?string
{
    if (!$sqlTime) {
        return null;
    }
    $date = date_create_from_format('Y-m-d H:i:s', $sqlTime);
    return $date ? $date->format('c') : null;
}

/** Naprawia kodowanie, jeśli wejście nie jest poprawnym UTF-8. */
function utf8(string $value): string
{
    return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
}

/** Jedna linia tekstu: bez znaków sterujących, bez podwójnych spacji. */
function clean_line(string $value, int $max = 200): string
{
    $value = preg_replace('/\s+/u', ' ', utf8($value));
    return mb_substr(trim((string)$value), 0, $max);
}

/** Wielolinijkowy tekst: zachowuje entery, usuwa znaki sterujące. */
function clean_text(string $value, int $max = 50000): string
{
    $value = str_replace(["\r\n", "\r"], "\n", utf8($value));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    return mb_substr((string)$value, 0, $max);
}

/**
 * Sprawdza datę w formacie RRRR-MM-DD. Zwraca ją albo null (brak terminu).
 * Rzuca ApiError przy wartości, która datą nie jest — lepiej powiedzieć
 * wprost, niż po cichu wyczyścić komuś termin.
 */
function clean_date($value): ?string
{
    if ($value === null || $value === '' || $value === false) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        throw new ApiError('Termin musi być datą w formacie RRRR-MM-DD.');
    }
    [$rok, $miesiac, $dzien] = array_map('intval', explode('-', $value));
    if (!checkdate($miesiac, $dzien, $rok)) {
        throw new ApiError('Podana data nie istnieje w kalendarzu.');
    }
    return $value;
}

/** Zapisuje szczegóły błędu do data/error.log i zwraca krótki identyfikator. */
function log_error(Throwable $e): string
{
    $ref  = substr(bin2hex(random_bytes(4)), 0, 8);
    $line = sprintf(
        "[%s] %s  %s: %s @ %s:%d%s",
        now(),
        $ref,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        PHP_EOL
    );
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    return $ref;
}
