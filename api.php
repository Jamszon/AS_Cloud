<?php
/**
 * api.php — jedyny endpoint AJAX aplikacji.
 *
 * Wywołania:  api.php?action=nazwa.akcji
 * Odpowiedź:  JSON  { ok: true, ... }  albo  { ok: false, error: "..." }
 * Wyjątek:    action=download zwraca plik binarny.
 *
 * Każda akcja zmieniająca dane wymaga nagłówka X-CSRF-Token.
 */
declare(strict_types=1);

require __DIR__ . '/db.php';

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

/* Pobieranie załącznika obsługujemy przed nagłówkami JSON. */
if ($action === 'download') {
    try {
        boot_session();
        action_download();
    } catch (Throwable $e) {
        http_response_code($e instanceof ApiError ? 400 : 500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $e instanceof ApiError || $e instanceof SetupError
            ? $e->getMessage()
            : 'Błąd serwera (' . log_error($e) . ').';
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    boot_session();
    json_out(route($action));
} catch (ApiError $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], $e->getCode() >= 400 ? (int)$e->getCode() : 400);
} catch (SetupError $e) {
    json_out(['ok' => false, 'error' => $e->getMessage(), 'setup' => true], 500);
} catch (Throwable $e) {
    $ref = log_error($e);
    json_out([
        'ok'    => false,
        'error' => DEBUG
            ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
            : 'Błąd serwera. Szczegóły zapisano w data/error.log (nr ' . $ref . ').',
    ], 500);
}

/* ================================================================== *
 *  ROUTER
 * ================================================================== */

function route(string $action): array
{
    /* Logowanie i wylogowanie obsługuje index.php — tu tylko stan sesji. */
    if ($action === 'session') {
        $me = current_user();
        return ['ok' => true, 'logged_in' => $me !== null];
    }

    /*
     * Sonda transportu dla diagnostyki: mówi wyłącznie, ile bajtów treści
     * żądania faktycznie dotarło do PHP. Nie wymaga zalogowania, bo służy do
     * zbadania, czemu nic nie dochodzi — a nie ujawnia żadnych danych.
     */
    if ($action === 'probe') {
        $surowe = (string)@file_get_contents('php://input');
        $zPola  = isset($_POST['d']) ? strlen((string)$_POST['d']) : -1;
        $zPliku = isset($_FILES['f']) && (int)$_FILES['f']['error'] === UPLOAD_ERR_OK
            ? (int)$_FILES['f']['size']
            : -1;

        return [
            'ok'             => true,
            'content_type'   => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
            'content_length' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
            'input_bytes'    => strlen($surowe),
            'post_bytes'     => $zPola,
            'file_bytes'     => $zPliku,
        ];
    }

    $me = current_user();
    if ($me === null) {
        throw new ApiError('Sesja wygasła. Zaloguj się ponownie.', 401);
    }

    /* Akcje modyfikujące dane wymagają POST + poprawnego tokenu CSRF. */
    $writes = [
        'folder.create', 'folder.rename', 'folder.delete', 'folder.reorder',
        'task.create', 'task.update', 'task.delete',
        'note.save', 'file.upload', 'file.chunk', 'file.delete', 'file.assign',
    ];
    if (in_array($action, $writes, true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new ApiError('Ta operacja wymaga metody POST.', 405);
        }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null);
        if (!csrf_valid(is_string($token) ? $token : null)) {
            throw new ApiError('Token bezpieczeństwa wygasł. Odśwież stronę (F5) i spróbuj ponownie.', 419);
        }
    }

    switch ($action) {
        case 'bootstrap':     return action_bootstrap($me);
        case 'ping':          return action_ping();
        case 'activity':      return ['ok' => true, 'activity' => activity_feed()];

        case 'folder.create':  return action_folder_create($me);
        case 'folder.rename':  return action_folder_rename($me);
        case 'folder.delete':  return action_folder_delete($me);
        case 'folder.reorder': return action_folder_reorder();
        case 'folder.open':    return action_folder_open();

        case 'task.create':   return action_task_create($me);
        case 'task.update':   return action_task_update($me);
        case 'task.delete':   return action_task_delete($me);

        case 'note.save':     return action_note_save($me);

        case 'file.upload':   return action_file_upload($me);
        case 'file.chunk':    return action_file_chunk($me);
        case 'file.delete':   return action_file_delete($me);
        case 'file.assign':   return action_file_assign($me);
    }

    throw new ApiError('Nieznana akcja: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8'), 404);
}

/* ================================================================== *
 *  AKCJE — dane startowe
 * ================================================================== */

function action_bootstrap(array $me): array
{
    return [
        'ok'       => true,
        'me'       => $me,
        'users'    => all_users(),
        'folders'  => folders_with_counts(),
        'activity' => activity_feed(),
        'stamp'    => last_activity_id(),
        'csrf'     => csrf_token(),
        'limits'   => [
            'max_upload'    => MAX_UPLOAD_BYTES,
            'allowed_ext'   => array_keys(ALLOWED_EXT),
            'server_upload' => min(bytes_from_ini('upload_max_filesize'), bytes_from_ini('post_max_size')),
        ],
    ];
}

/** Lekkie odpytanie — frontend sprawdza co ~30 s, czy ktoś coś zmienił. */
function action_ping(): array
{
    return ['ok' => true, 'stamp' => last_activity_id()];
}

/* ================================================================== *
 *  AKCJE — foldery
 * ================================================================== */

function action_folder_create(array $me): array
{
    $name = clean_line((string)(body()['name'] ?? ''), 80);
    if ($name === '') {
        throw new ApiError('Podaj nazwę folderu.');
    }

    /* Nowy folder ląduje na końcu listy — kolejność zmienia się przeciąganiem. */
    $position = (int)db()->query('SELECT COALESCE(MAX(position), -1) + 1 FROM folders')->fetchColumn();

    $stmt = db()->prepare('INSERT INTO folders (name, position, created_by, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $position, $me['id'], now()]);
    $id = (int)db()->lastInsertId();

    db()->prepare('INSERT INTO notes (folder_id, content) VALUES (?, \'\')')->execute([$id]);

    log_activity($me['id'], 'folder.create', 'Nowy folder: „' . $name . '”', $id, $name);

    return ['ok' => true, 'folder_id' => $id, 'folders' => folders_with_counts(), 'activity' => activity_feed()];
}

function action_folder_rename(array $me): array
{
    $folder = folder_or_fail((int)(body()['id'] ?? 0));
    $name   = clean_line((string)(body()['name'] ?? ''), 80);
    if ($name === '') {
        throw new ApiError('Podaj nazwę folderu.');
    }
    if ($name === $folder['name']) {
        return ['ok' => true, 'folders' => folders_with_counts(), 'activity' => activity_feed()];
    }

    db()->prepare('UPDATE folders SET name = ?, updated_by = ?, updated_at = ? WHERE id = ?')
        ->execute([$name, $me['id'], now(), $folder['id']]);

    log_activity($me['id'], 'folder.rename', 'Zmiana nazwy folderu: „' . $folder['name'] . '” → „' . $name . '”', $folder['id'], $name);

    return ['ok' => true, 'folders' => folders_with_counts(), 'activity' => activity_feed()];
}

function action_folder_delete(array $me): array
{
    $folder = folder_or_fail((int)(body()['id'] ?? 0));

    /* Najpierw kasujemy załączniki z dysku — baza o nich zaraz zapomni. */
    $stmt = db()->prepare('SELECT stored_name FROM files WHERE folder_id = ?');
    $stmt->execute([$folder['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stored) {
        delete_stored_file((string)$stored);
    }

    /* Kasujemy jawnie — nie każdy build SQLite ma włączone klucze obce. */
    db()->prepare('DELETE FROM task_assignees WHERE task_id IN (SELECT id FROM tasks WHERE folder_id = ?)')
        ->execute([$folder['id']]);
    db()->prepare('DELETE FROM files   WHERE folder_id = ?')->execute([$folder['id']]);
    db()->prepare('DELETE FROM tasks   WHERE folder_id = ?')->execute([$folder['id']]);
    db()->prepare('DELETE FROM notes   WHERE folder_id = ?')->execute([$folder['id']]);
    db()->prepare('DELETE FROM folders WHERE id = ?')->execute([$folder['id']]);

    log_activity($me['id'], 'folder.delete', 'Usunięty folder: „' . $folder['name'] . '” (wraz z zawartością)', null, $folder['name']);

    return ['ok' => true, 'folders' => folders_with_counts(), 'activity' => activity_feed()];
}

/**
 * Zapisuje nową kolejność folderów po przeciągnięciu na liście.
 * Celowo nie trafia do dziennika zmian — to porządkowanie widoku,
 * a nie zmiana treści, i zaśmiecałoby feed przy każdym przesunięciu.
 */
function action_folder_reorder(): array
{
    $ids = body()['ids'] ?? null;
    if (!is_array($ids) || !$ids) {
        throw new ApiError('Brak kolejności do zapisania.');
    }

    $stmt     = db()->prepare('UPDATE folders SET position = ? WHERE id = ?');
    $position = 0;
    foreach ($ids as $id) {
        $stmt->execute([$position++, (int)$id]);
    }

    return ['ok' => true, 'folders' => folders_with_counts()];
}

/** Pełna zawartość folderu: zadania, notatka, załączniki. */
function action_folder_open(): array
{
    $folder = folder_or_fail((int)($_GET['id'] ?? 0));

    return [
        'ok'     => true,
        'folder' => $folder,
        'tasks'  => folder_tasks($folder['id']),
        'note'   => folder_note($folder['id']),
        'files'  => folder_files($folder['id']),
    ];
}

/* ================================================================== *
 *  AKCJE — zadania
 * ================================================================== */

function action_task_create(array $me): array
{
    $folder = folder_or_fail((int)(body()['folder_id'] ?? 0));
    $title  = clean_line((string)(body()['title'] ?? ''), 200);
    if ($title === '') {
        throw new ApiError('Podaj treść zadania.');
    }

    $osoby  = valid_user_ids(body()['assignee_ids'] ?? []);
    $status = (string)(body()['status'] ?? 'todo');
    if (!in_array($status, STATUSES, true)) {
        $status = 'todo';
    }
    $priority = (string)(body()['priority'] ?? 'normal');
    if (!in_array($priority, PRIORITIES, true)) {
        $priority = 'normal';
    }

    $stmt = db()->prepare(
        'INSERT INTO tasks (folder_id, title, description, status, priority, created_by, created_at, updated_by, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $folder['id'], $title, clean_text((string)(body()['description'] ?? ''), 5000),
        $status, $priority, $me['id'], now(), $me['id'], now(),
    ]);
    set_task_assignees((int)db()->lastInsertId(), $osoby);

    log_activity($me['id'], 'task.create', 'Nowe zadanie: „' . $title . '”', $folder['id'], $folder['name']);

    return [
        'ok'       => true,
        'tasks'    => folder_tasks($folder['id']),
        'folders'  => folders_with_counts(),
        'activity' => activity_feed(),
    ];
}

function action_task_update(array $me): array
{
    $task   = task_or_fail((int)(body()['id'] ?? 0));
    $folder = folder_or_fail((int)$task['folder_id']);
    $body   = body();

    $changes = [];
    if (array_key_exists('title', $body)) {
        $title = clean_line((string)$body['title'], 200);
        if ($title === '') {
            throw new ApiError('Treść zadania nie może być pusta.');
        }
        $changes['title'] = $title;
    }
    if (array_key_exists('description', $body)) {
        $changes['description'] = clean_text((string)$body['description'], 5000);
    }
    if (array_key_exists('status', $body)) {
        $status = (string)$body['status'];
        if (!in_array($status, STATUSES, true)) {
            throw new ApiError('Nieznany status zadania.');
        }
        $changes['status'] = $status;
    }
    if (array_key_exists('priority', $body)) {
        $priority = (string)$body['priority'];
        if (!in_array($priority, PRIORITIES, true)) {
            throw new ApiError('Nieznany priorytet zadania.');
        }
        $changes['priority'] = $priority;
    }
    /* Lista osób trzymana jest w osobnej tabeli, więc obsługujemy ją oddzielnie. */
    $osobyPrzed = task_assignee_ids((int)$task['id']);
    $osobyPo    = null;
    if (array_key_exists('assignee_ids', $body)) {
        $osobyPo = valid_user_ids($body['assignee_ids']);
    }

    if (!$changes && $osobyPo === null) {
        throw new ApiError('Brak zmian do zapisania.');
    }

    $set    = [];
    $params = [];
    foreach ($changes as $column => $value) {
        $set[]    = $column . ' = ?';
        $params[] = $value;
    }
    $set[]    = 'updated_by = ?';
    $params[] = $me['id'];
    $set[]    = 'updated_at = ?';
    $params[] = now();
    $params[] = $task['id'];

    db()->prepare('UPDATE tasks SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

    if ($osobyPo !== null) {
        set_task_assignees((int)$task['id'], $osobyPo);
    }

    /* Opis zmiany w dzienniku dobieramy do tego, co faktycznie się zmieniło. */
    $title = $changes['title'] ?? (string)$task['title'];
    if (isset($changes['status']) && $changes['status'] !== $task['status']) {
        $action  = 'task.status';
        $message = 'Zadanie „' . $title . '” → ' . status_label($changes['status']);
    } elseif ($osobyPo !== null && $osobyPo !== $osobyPrzed) {
        $imiona  = [];
        foreach ($osobyPo as $id) {
            $imiona[] = user_name($id);
        }
        $action  = 'task.assign';
        $message = 'Zadanie „' . $title . '” przypisane do: ' . ($imiona ? implode(', ', $imiona) : 'nikogo');
    } elseif (isset($changes['priority']) && $changes['priority'] !== ($task['priority'] ?? 'normal')) {
        $action  = 'task.priority';
        $message = 'Priorytet zadania „' . $title . '”: ' . priority_label($changes['priority']);
    } else {
        $action  = 'task.update';
        $message = 'Edycja zadania: „' . $title . '”';
    }
    log_activity($me['id'], $action, $message, $folder['id'], $folder['name']);

    return [
        'ok'       => true,
        'tasks'    => folder_tasks($folder['id']),
        'folders'  => folders_with_counts(),
        'activity' => activity_feed(),
    ];
}

function action_task_delete(array $me): array
{
    $task   = task_or_fail((int)(body()['id'] ?? 0));
    $folder = folder_or_fail((int)$task['folder_id']);

    /* Załączników nie kasujemy razem z zadaniem — odpinamy je do folderu,
       żeby usunięcie zadania nigdy nie zabrało komuś pliku po cichu. */
    $stmt = db()->prepare('SELECT COUNT(*) FROM files WHERE task_id = ?');
    $stmt->execute([$task['id']]);
    $odpiete = (int)$stmt->fetchColumn();

    db()->prepare('UPDATE files SET task_id = NULL WHERE task_id = ?')->execute([$task['id']]);
    db()->prepare('DELETE FROM task_assignees WHERE task_id = ?')->execute([$task['id']]);
    db()->prepare('DELETE FROM tasks WHERE id = ?')->execute([$task['id']]);

    log_activity($me['id'], 'task.delete', 'Usunięte zadanie: „' . $task['title'] . '”', $folder['id'], $folder['name']);

    return [
        'ok'         => true,
        'detached'   => $odpiete,
        'tasks'      => folder_tasks($folder['id']),
        'files'      => folder_files($folder['id']),
        'folders'    => folders_with_counts(),
        'activity'   => activity_feed(),
    ];
}

/* ================================================================== *
 *  AKCJE — notatka folderu
 * ================================================================== */

function action_note_save(array $me): array
{
    $folder  = folder_or_fail((int)(body()['folder_id'] ?? 0));
    $content = clean_text((string)(body()['content'] ?? ''), 50000);

    $stmt = db()->prepare('SELECT folder_id FROM notes WHERE folder_id = ?');
    $stmt->execute([$folder['id']]);

    if ($stmt->fetch()) {
        db()->prepare('UPDATE notes SET content = ?, updated_by = ?, updated_at = ? WHERE folder_id = ?')
            ->execute([$content, $me['id'], now(), $folder['id']]);
    } else {
        db()->prepare('INSERT INTO notes (folder_id, content, updated_by, updated_at) VALUES (?, ?, ?, ?)')
            ->execute([$folder['id'], $content, $me['id'], now()]);
    }

    log_activity($me['id'], 'note.save', 'Aktualizacja notatki w folderze „' . $folder['name'] . '”', $folder['id'], $folder['name']);

    return ['ok' => true, 'note' => folder_note($folder['id']), 'activity' => activity_feed()];
}

/* ================================================================== *
 *  AKCJE — załączniki
 * ================================================================== */

/**
 * Wysyłka pliku jako surowe ciało żądania (bez formularza multipart).
 *
 * To domyślna ścieżka panelu i lekarstwo na hostingi, gdzie PHP nie ma
 * sprawnego katalogu tymczasowego — przy multipart kończyło się to błędem
 * „Serwer nie mógł zapisać pliku tymczasowego”. Tutaj PHP nie tworzy żadnego
 * pliku pośredniego: czytamy strumień i od razu zapisujemy do uploads/.
 *
 * Metadane (nazwa, folder, zadanie) przychodzą w nagłówkach X-*.
 */
function action_file_upload_raw(array $me): array
{
    $folder = folder_or_fail((int)($_SERVER['HTTP_X_FOLDER_ID'] ?? 0));

    $original = clean_line(rawurldecode((string)($_SERVER['HTTP_X_FILE_NAME'] ?? '')), 180);
    $original = basename(str_replace('\\', '/', $original));
    if ($original === '') {
        throw new ApiError('Brak nazwy pliku w żądaniu.');
    }

    $allowed = ALLOWED_EXT;
    $ext     = strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
    if (!array_key_exists($ext, $allowed)) {
        throw new ApiError('Niedozwolony typ pliku. Dozwolone rozszerzenia: ' . implode(', ', array_keys($allowed)) . '.');
    }

    $zapowiedziany = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($zapowiedziany > MAX_UPLOAD_BYTES) {
        throw new ApiError('Plik jest większy niż dozwolone 15 MB.');
    }

    $target = prepare_upload_target($ext, $stored);

    $wejscie = @fopen('php://input', 'rb');
    if ($wejscie === false) {
        throw new ApiError('Nie udało się odczytać przesyłanych danych.');
    }
    $wyjscie = @fopen($target, 'wb');
    if ($wyjscie === false) {
        fclose($wejscie);
        throw new ApiError('Nie udało się utworzyć pliku w katalogu uploads/. Sprawdź uprawnienia (chmod 755 lub 777).');
    }

    /* Kopiujemy porcjami — nawet 15 MB nie obciąża pamięci. */
    $zapisano = 0;
    while (!feof($wejscie)) {
        $kawalek = fread($wejscie, 262144);
        if ($kawalek === false) {
            break;
        }
        if ($kawalek === '') {
            continue;
        }
        $zapisano += strlen($kawalek);
        if ($zapisano > MAX_UPLOAD_BYTES) {
            fclose($wejscie);
            fclose($wyjscie);
            @unlink($target);
            throw new ApiError('Plik jest większy niż dozwolone 15 MB.');
        }
        if (fwrite($wyjscie, $kawalek) === false) {
            fclose($wejscie);
            fclose($wyjscie);
            @unlink($target);
            throw new ApiError('Zapis pliku został przerwany. Sprawdź limit miejsca na koncie hostingowym.');
        }
    }
    fclose($wejscie);
    fclose($wyjscie);

    if ($zapisano <= 0) {
        @unlink($target);
        /* Sygnał dla przeglądarki: ten hosting nie oddaje php://input,
           panel powinien sam przejść na wysyłkę zakodowaną w base64. */
        throw new ApiError('Serwer nie otrzymał zawartości pliku strumieniem [retry:b64].');
    }

    try {
        verify_upload_content($target, $ext);
    } catch (ApiError $e) {
        @unlink($target);
        throw $e;
    }

    @chmod($target, 0640);

    return finalize_upload($me, $folder, $stored, $original, $ext, $zapisano,
        $_SERVER['HTTP_X_TASK_ID'] ?? null, $target);
}

/**
 * Wysyłka pliku zakodowanego w base64, przesłanego jako zwykłe pole
 * formularza. Trzecia droga na wypadek hostingu, gdzie nie działa ani
 * katalog tymczasowy (multipart), ani odczyt php://input (strumień).
 * Pola formularza PHP trzyma w pamięci, więc żaden plik pośredni nie powstaje.
 */
function action_file_upload_b64(array $me, ?array $pola = null): array
{
    $pola   = $pola ?? $_POST;
    $folder = folder_or_fail((int)($pola['folder_id'] ?? 0));

    $original = clean_line(basename(str_replace('\\', '/', (string)($pola['b64_name'] ?? ''))), 180);
    if ($original === '') {
        throw new ApiError('Brak nazwy pliku w żądaniu.');
    }

    $allowed = ALLOWED_EXT;
    $ext     = strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
    if (!array_key_exists($ext, $allowed)) {
        throw new ApiError('Niedozwolony typ pliku. Dozwolone rozszerzenia: ' . implode(', ', array_keys($allowed)) . '.');
    }

    /* Przeglądarka wysyła wariant base64url (- i _ zamiast + i /), żeby nic
       nie wymagało dodatkowego kodowania w treści formularza. */
    $zakodowane = strtr((string)($pola['b64_data'] ?? ''), '-_', '+/');
    if ($zakodowane === '') {
        throw new ApiError('Serwer nie otrzymał zawartości pliku — pole b64_data dotarło puste. [retry:multipart]');
    }

    $bajty = base64_decode($zakodowane, true);
    if ($bajty === false || $bajty === '') {
        throw new ApiError('Zawartość pliku dotarła uszkodzona. Spróbuj ponownie.');
    }
    if (strlen($bajty) > MAX_UPLOAD_BYTES) {
        throw new ApiError('Plik jest większy niż dozwolone 15 MB.');
    }

    $target = prepare_upload_target($ext, $stored);
    if (@file_put_contents($target, $bajty) === false) {
        @unlink($target);
        throw new ApiError('Nie udało się zapisać pliku w katalogu uploads/. Sprawdź uprawnienia (chmod 755 lub 777).');
    }

    try {
        verify_upload_content($target, $ext);
    } catch (ApiError $e) {
        @unlink($target);
        throw $e;
    }
    @chmod($target, 0640);

    return finalize_upload($me, $folder, $stored, $original, $ext, strlen($bajty),
        $pola['task_id'] ?? null, $target);
}

/**
 * Wysyłka pliku w małych porcjach, każda osobnym żądaniem JSON.
 *
 * Najpewniejsza z metod: idzie tym samym kanałem, co reszta panelu
 * (zwykły POST z ciałem JSON), więc działa nawet tam, gdzie serwer obcina
 * duże żądania — a to typowe ustawienie zapór aplikacyjnych na hostingach
 * współdzielonych. Kolejne fragmenty dopisujemy do pliku roboczego
 * `.part_<id>` w uploads/, a przy ostatnim sprawdzamy sygnaturę i nadajemy
 * plikowi docelową nazwę.
 */
function action_file_chunk(array $me): array
{
    $dane     = body();
    $uploadId = (string)($dane['upload_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
        throw new ApiError('Nieprawidłowy identyfikator wysyłki.');
    }

    $folder = folder_or_fail((int)($dane['folder_id'] ?? 0));
    $indeks = (int)($dane['index'] ?? -1);
    if ($indeks < 0 || $indeks > 5000) {
        throw new ApiError('Nieprawidłowy numer fragmentu.');
    }

    $original = clean_line(basename(str_replace('\\', '/', (string)($dane['name'] ?? ''))), 180);
    $allowed  = ALLOWED_EXT;
    $ext      = strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
    if (!array_key_exists($ext, $allowed)) {
        throw new ApiError('Niedozwolony typ pliku. Dozwolone rozszerzenia: ' . implode(', ', array_keys($allowed)) . '.');
    }

    if (!is_dir(UPLOAD_DIR)) {
        throw new ApiError('Na serwerze nie ma katalogu uploads/. Utwórz go przez FTP obok index.php.');
    }
    if (!is_writable(UPLOAD_DIR)) {
        throw new ApiError('Katalog uploads/ nie ma prawa zapisu. Ustaw mu przez FTP chmod 755, a jeśli to nie pomoże — 777.');
    }

    $fragment = base64_decode(strtr((string)($dane['data'] ?? ''), '-_', '+/'), true);
    if ($fragment === false) {
        throw new ApiError('Fragment pliku dotarł uszkodzony. Spróbuj ponownie.');
    }

    $roboczy = UPLOAD_DIR . '/.part_' . $uploadId;

    if ($indeks === 0) {
        @unlink($roboczy);
        clean_stale_parts();
    } elseif (!is_file($roboczy)) {
        throw new ApiError('Brakuje wcześniejszych fragmentów pliku — zacznij wysyłkę od nowa.');
    }

    if (@file_put_contents($roboczy, $fragment, FILE_APPEND | LOCK_EX) === false) {
        @unlink($roboczy);
        throw new ApiError('Nie udało się dopisać fragmentu w katalogu uploads/. Sprawdź uprawnienia i limit miejsca.');
    }

    clearstatcache(true, $roboczy);
    $zebrane = (int)@filesize($roboczy);
    if ($zebrane > MAX_UPLOAD_BYTES) {
        @unlink($roboczy);
        throw new ApiError('Plik jest większy niż dozwolone 15 MB.');
    }

    if (empty($dane['final'])) {
        return ['ok' => true, 'done' => false, 'received' => $zebrane];
    }

    /* Ostatni fragment — dopiero teraz mamy komplet do sprawdzenia. */
    try {
        verify_upload_content($roboczy, $ext);
    } catch (ApiError $e) {
        @unlink($roboczy);
        throw $e;
    }

    $target = prepare_upload_target($ext, $stored);
    if (!@rename($roboczy, $target)) {
        @unlink($roboczy);
        throw new ApiError('Nie udało się zapisać gotowego pliku w katalogu uploads/.');
    }
    @chmod($target, 0640);

    $wynik = finalize_upload($me, $folder, $stored, $original, $ext, $zebrane,
        $dane['task_id'] ?? null, $target);
    $wynik['done'] = true;
    return $wynik;
}

/** Usuwa porzucone fragmenty starsze niż 6 godzin. */
function clean_stale_parts(): void
{
    $granica = time() - 6 * 3600;
    foreach ((array)@glob(UPLOAD_DIR . '/.part_*') as $plik) {
        if (is_file($plik) && (int)@filemtime($plik) < $granica) {
            @unlink($plik);
        }
    }
}

/** Sprawdza katalog uploads/ i zwraca ścieżkę docelową z losową nazwą. */
function prepare_upload_target(string $ext, ?string &$stored): string
{
    if (!is_dir(UPLOAD_DIR)) {
        throw new ApiError('Na serwerze nie ma katalogu uploads/. Utwórz go przez FTP obok index.php.');
    }
    if (!is_writable(UPLOAD_DIR)) {
        throw new ApiError('Katalog uploads/ nie ma prawa zapisu. Ustaw mu przez FTP chmod 755, a jeśli to nie pomoże — 777.');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    return UPLOAD_DIR . '/' . $stored;
}

/**
 * Wspólne zakończenie wszystkich ścieżek wysyłki: przypisanie do zadania,
 * wpis w bazie, dziennik i odpowiedź.
 */
function finalize_upload(array $me, array $folder, string $stored, string $original,
                         string $ext, int $size, $rawTaskId, string $target): array
{
    $taskId = null;
    $task   = null;
    if ($rawTaskId !== null && $rawTaskId !== '' && (int)$rawTaskId > 0) {
        $task = task_or_fail((int)$rawTaskId);
        if ((int)$task['folder_id'] !== $folder['id']) {
            @unlink($target);
            throw new ApiError('To zadanie należy do innego folderu.');
        }
        $taskId = (int)$task['id'];
    }

    $stmt = db()->prepare(
        'INSERT INTO files (folder_id, task_id, original_name, stored_name, ext, size, uploaded_by, uploaded_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$folder['id'], $taskId, $original, $stored, $ext, $size, $me['id'], now()]);

    $opis = $taskId !== null
        ? 'Nowy plik: ' . $original . ' (zadanie „' . $task['title'] . '”)'
        : 'Nowy plik: ' . $original;
    log_activity($me['id'], 'file.upload', $opis, $folder['id'], $folder['name']);

    return [
        'ok'       => true,
        'files'    => folder_files($folder['id']),
        'tasks'    => folder_tasks($folder['id']),
        'activity' => activity_feed(),
    ];
}

function action_file_upload(array $me): array
{
    /* Nagłówek X-File-Name oznacza wysyłkę surowym strumieniem. */
    if (isset($_SERVER['HTTP_X_FILE_NAME']) && $_SERVER['HTTP_X_FILE_NAME'] !== '') {
        return action_file_upload_raw($me);
    }

    /*
     * Część hostingów nie wypełnia $_POST przy zwykłym formularzu — bywa
     * wyłączone enable_post_data_reading albo SAPI nie rozbiera treści.
     * W takim wypadku czytamy ciało żądania i rozbieramy je samodzielnie,
     * żeby wysyłka base64 działała niezależnie od kaprysów serwera.
     */
    $pola = $_POST;
    if (!$pola && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'x-www-form-urlencoded') !== false) {
        $surowe = (string)@file_get_contents('php://input');
        if ($surowe !== '') {
            parse_str($surowe, $pola);
        }
    }

    /* Pole b64_name oznacza wysyłkę zakodowaną w base64. */
    if (isset($pola['b64_name']) && $pola['b64_name'] !== '') {
        return action_file_upload_b64($me, $pola);
    }

    /* Brak jakichkolwiek danych mimo niezerowej długości żądania. Powodów
       może być kilka, więc zamiast zgadywać wypisujemy fakty. */
    if (!$pola && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $dlugosc = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        throw new ApiError(
            'Serwer nie przekazał PHP żadnych danych z tego żądania. '
            . 'Wysłano ' . number_format($dlugosc / 1048576, 2, ',', ' ') . ' MB, '
            . 'typ treści: ' . (($_SERVER['CONTENT_TYPE'] ?? '') !== '' ? $_SERVER['CONTENT_TYPE'] : 'brak') . ', '
            . 'post_max_size = ' . ini_get('post_max_size') . ', '
            . 'enable_post_data_reading = ' . (ini_get('enable_post_data_reading') ? 'on' : 'OFF') . '. '
            . 'Otwórz diagnostyka.php i sprawdź sekcję „Transport wysyłki”. [retry:multipart]'
        );
    }

    $folder = folder_or_fail((int)($_POST['folder_id'] ?? 0));

    if (!isset($_FILES['file']) || is_array($_FILES['file']['name'] ?? null)) {
        throw new ApiError('Serwer nie otrzymał pliku. Spróbuj ponownie lub sprawdź limity PHP (README.md, punkt 6).');
    }

    $upload = $_FILES['file'];
    switch ((int)$upload['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new ApiError('Plik przekracza limit serwera (upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Instrukcja jest w README.md.');
        case UPLOAD_ERR_NO_FILE:
            throw new ApiError('Nie wybrano żadnego pliku.');
        case UPLOAD_ERR_PARTIAL:
            throw new ApiError('Przesyłanie zostało przerwane. Spróbuj ponownie.');
        case UPLOAD_ERR_NO_TMP_DIR:
            throw new ApiError(
                'PHP nie ma katalogu na pliki tymczasowe (upload_tmp_dir = "'
                . ini_get('upload_tmp_dir') . '", systemowy = "' . sys_get_temp_dir() . '"). '
                . 'Panel wysyła pliki strumieniowo z pominięciem tego katalogu — odśwież stronę (Ctrl+F5) i spróbuj ponownie.'
            );
        case UPLOAD_ERR_CANT_WRITE:
            throw new ApiError(
                'PHP nie mógł zapisać pliku w katalogu tymczasowym ("'
                . (ini_get('upload_tmp_dir') !== '' ? ini_get('upload_tmp_dir') : sys_get_temp_dir()) . '"). '
                . 'Panel wysyła pliki strumieniowo z pominięciem tego katalogu — odśwież stronę (Ctrl+F5) i spróbuj ponownie.'
            );
        default:
            throw new ApiError('Nie udało się przesłać pliku (kod błędu ' . (int)$upload['error'] . ').');
    }

    if (!is_uploaded_file((string)$upload['tmp_name'])) {
        throw new ApiError('Nieprawidłowe przesłanie pliku.');
    }

    $size = (int)$upload['size'];
    if ($size <= 0) {
        throw new ApiError('Plik jest pusty.');
    }
    if ($size > MAX_UPLOAD_BYTES) {
        throw new ApiError('Plik jest większy niż dozwolone 15 MB.');
    }

    $allowed  = ALLOWED_EXT;
    $original = clean_line(basename(str_replace('\\', '/', (string)$upload['name'])), 180);
    $ext      = strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
    if (!array_key_exists($ext, $allowed)) {
        throw new ApiError('Niedozwolony typ pliku. Dozwolone rozszerzenia: ' . implode(', ', array_keys($allowed)) . '.');
    }
    if ($original === '' || $original === '.' . $ext) {
        $original = 'zalacznik.' . $ext;
    }
    verify_upload_content((string)$upload['tmp_name'], $ext);

    /* Zanim zaczniemy zapis — sprawdzamy katalog, żeby błąd był konkretny. */
    if (!is_dir(UPLOAD_DIR)) {
        throw new ApiError('Na serwerze nie ma katalogu uploads/. Utwórz go przez FTP obok index.php.');
    }
    if (!is_writable(UPLOAD_DIR)) {
        throw new ApiError('Katalog uploads/ nie ma prawa zapisu. Ustaw mu przez FTP chmod 755, a jeśli to nie pomoże — 777.');
    }

    /* Nazwa na dysku jest losowa — nie da się jej zgadnąć ani podmienić. */
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $target = UPLOAD_DIR . '/' . $stored;

    /* Część hostingów z open_basedir blokuje move_uploaded_file — wtedy
       próbujemy zwykłego kopiowania, które zwykle przechodzi. */
    $zapisano = @move_uploaded_file((string)$upload['tmp_name'], $target);
    if (!$zapisano) {
        $zapisano = @copy((string)$upload['tmp_name'], $target);
    }
    if (!$zapisano || !is_file($target)) {
        throw new ApiError(
            'Nie udało się zapisać pliku w katalogu uploads/. Sprawdź uprawnienia (chmod 755 lub 777) '
            . 'oraz limit miejsca na koncie hostingowym.'
        );
    }
    @chmod($target, 0640);

    return finalize_upload($me, $folder, $stored, $original, $ext, $size,
        $_POST['task_id'] ?? null, $target);
}

function action_file_delete(array $me): array
{
    $id   = (int)(body()['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM files WHERE id = ?');
    $stmt->execute([$id]);
    $file = $stmt->fetch();
    if (!$file) {
        throw new ApiError('Ten plik już nie istnieje.', 404);
    }
    $folder = folder_or_fail((int)$file['folder_id']);

    delete_stored_file((string)$file['stored_name']);
    db()->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);

    log_activity($me['id'], 'file.delete', 'Usunięty plik: ' . $file['original_name'], $folder['id'], $folder['name']);

    return [
        'ok'       => true,
        'files'    => folder_files($folder['id']),
        'tasks'    => folder_tasks($folder['id']),
        'activity' => activity_feed(),
    ];
}

/**
 * Podpina istniejący już plik pod zadanie albo go od niego odpina.
 * Plik nigdzie się nie przemieszcza — zmienia się tylko przypisanie,
 * więc operacja jest w pełni odwracalna.
 */
function action_file_assign(array $me): array
{
    $stmt = db()->prepare('SELECT * FROM files WHERE id = ?');
    $stmt->execute([(int)(body()['id'] ?? 0)]);
    $file = $stmt->fetch();
    if (!$file) {
        throw new ApiError('Ten plik już nie istnieje — odśwież stronę.', 404);
    }

    $folder = folder_or_fail((int)$file['folder_id']);

    $zadane = body()['task_id'] ?? null;
    $taskId = null;
    $task   = null;
    if ($zadane !== null && $zadane !== '' && (int)$zadane > 0) {
        $task = task_or_fail((int)$zadane);
        if ((int)$task['folder_id'] !== $folder['id']) {
            throw new ApiError('To zadanie należy do innego folderu.');
        }
        $taskId = (int)$task['id'];
    }

    if ($taskId === (isset($file['task_id']) && $file['task_id'] !== null ? (int)$file['task_id'] : null)) {
        throw new ApiError('Ten plik jest już tak przypisany.');
    }

    db()->prepare('UPDATE files SET task_id = ? WHERE id = ?')->execute([$taskId, (int)$file['id']]);

    $message = $taskId !== null
        ? 'Plik ' . $file['original_name'] . ' podpięty pod zadanie „' . $task['title'] . '”'
        : 'Plik ' . $file['original_name'] . ' odpięty od zadania';
    log_activity($me['id'], 'file.assign', $message, $folder['id'], $folder['name']);

    return [
        'ok'       => true,
        'files'    => folder_files($folder['id']),
        'tasks'    => folder_tasks($folder['id']),
        'activity' => activity_feed(),
    ];
}

/** Pobieranie / podgląd załącznika (jedyna akcja zwracająca nie-JSON). */
function action_download(): void
{
    if (current_user() === null) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Zaloguj się, aby pobrać plik.';
        return;
    }

    $stmt = db()->prepare('SELECT * FROM files WHERE id = ?');
    $stmt->execute([(int)($_GET['id'] ?? 0)]);
    $file = $stmt->fetch();

    $stored = $file ? (string)$file['stored_name'] : '';
    $path   = UPLOAD_DIR . '/' . $stored;

    /* Nazwa pochodzi z bazy, ale i tak sprawdzamy jej kształt — zero ryzyka ../ */
    if (!$file || !preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $stored) || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Nie znaleziono pliku.';
        return;
    }

    $types  = ALLOWED_EXT;
    $ext    = strtolower((string)$file['ext']);
    $mime   = array_key_exists($ext, $types) ? $types[$ext] : 'application/octet-stream';
    $inline = !empty($_GET['inline']) && in_array($ext, ['png', 'jpg', 'jpeg', 'pdf'], true);
    $name   = (string)$file['original_name'];
    $ascii  = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
    if (!is_string($ascii) || trim($ascii) === '') {
        $ascii = 'zalacznik.' . $ext;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
        . '; filename="' . $ascii . '"'
        . "; filename*=UTF-8''" . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Cache-Control: private, max-age=0, must-revalidate');

    readfile($path);
}

/* ================================================================== *
 *  ZAPYTANIA POMOCNICZE
 * ================================================================== */

function folders_with_counts(): array
{
    $rows = db()->query(
        'SELECT f.id, f.name, f.created_at, f.updated_at,
                cu.name AS created_by_name,
                uu.name AS updated_by_name,
                (SELECT COUNT(*) FROM tasks t WHERE t.folder_id = f.id)                        AS task_count,
                (SELECT COUNT(*) FROM tasks t WHERE t.folder_id = f.id AND t.status = \'done\') AS done_count,
                (SELECT COUNT(*) FROM files x WHERE x.folder_id = f.id)                        AS file_count
         FROM folders f
         LEFT JOIN users cu ON cu.id = f.created_by
         LEFT JOIN users uu ON uu.id = f.updated_by
         ORDER BY f.position, f.name COLLATE NOCASE, f.id'
    )->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id'              => (int)$row['id'],
            'name'            => $row['name'],
            'task_count'      => (int)$row['task_count'],
            'done_count'      => (int)$row['done_count'],
            'file_count'      => (int)$row['file_count'],
            'created_by_name' => $row['created_by_name'],
            'created_at'      => iso($row['created_at']),
            'updated_by_name' => $row['updated_by_name'],
            'updated_at'      => iso($row['updated_at']),
        ];
    }
    return $out;
}

function folder_or_fail(int $id): array
{
    $stmt = db()->prepare(
        'SELECT f.*, cu.name AS created_by_name, uu.name AS updated_by_name
         FROM folders f
         LEFT JOIN users cu ON cu.id = f.created_by
         LEFT JOIN users uu ON uu.id = f.updated_by
         WHERE f.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new ApiError('Ten folder już nie istnieje — odśwież stronę.', 404);
    }
    return [
        'id'              => (int)$row['id'],
        'name'            => $row['name'],
        'created_by_name' => $row['created_by_name'],
        'created_at'      => iso($row['created_at']),
        'updated_by_name' => $row['updated_by_name'],
        'updated_at'      => iso($row['updated_at']),
    ];
}

function folder_tasks(int $folderId): array
{
    $stmt = db()->prepare(
        'SELECT t.*,
                cu.name AS created_by_name, cu.color AS created_by_color,
                uu.name AS updated_by_name
         FROM tasks t
         LEFT JOIN users cu ON cu.id = t.created_by
         LEFT JOIN users uu ON uu.id = t.updated_by
         WHERE t.folder_id = ?
         ORDER BY t.id'
    );
    $stmt->execute([$folderId]);
    $rows = $stmt->fetchAll();

    /* Osoby przypisane do zadań pobieramy jednym zapytaniem dla całego folderu. */
    $osoby = [];
    $stmt = db()->prepare(
        'SELECT ta.task_id, u.id, u.name, u.color
         FROM task_assignees ta
         JOIN users u ON u.id = ta.user_id
         JOIN tasks t ON t.id = ta.task_id
         WHERE t.folder_id = ?
         ORDER BY u.id'
    );
    $stmt->execute([$folderId]);
    foreach ($stmt->fetchAll() as $row) {
        $osoby[(int)$row['task_id']][] = [
            'id'    => (int)$row['id'],
            'name'  => $row['name'],
            'color' => $row['color'],
        ];
    }

    /* Podobnie liczba załączników podpiętych pod poszczególne zadania. */
    $zalaczniki = [];
    $stmt = db()->prepare(
        'SELECT task_id, COUNT(*) AS ile FROM files
         WHERE folder_id = ? AND task_id IS NOT NULL GROUP BY task_id'
    );
    $stmt->execute([$folderId]);
    foreach ($stmt->fetchAll() as $row) {
        $zalaczniki[(int)$row['task_id']] = (int)$row['ile'];
    }

    $out = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $out[] = [
            'id'               => $id,
            'folder_id'        => (int)$row['folder_id'],
            'title'            => $row['title'],
            'description'      => $row['description'],
            'status'           => $row['status'],
            'priority'         => $row['priority'] ?? 'normal',
            'assignees'        => $osoby[$id] ?? [],
            'file_count'       => $zalaczniki[$id] ?? 0,
            'created_by_name'  => $row['created_by_name'],
            'created_by_color' => $row['created_by_color'],
            'created_at'       => iso($row['created_at']),
            'updated_by_name'  => $row['updated_by_name'],
            'updated_at'       => iso($row['updated_at']),
        ];
    }
    return $out;
}

/** Odsiewa nieistniejące i powtórzone identyfikatory osób. */
function valid_user_ids($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $istniejace = db()->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $istniejace = array_map('intval', $istniejace);

    $out = [];
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0 && in_array($id, $istniejace, true) && !in_array($id, $out, true)) {
            $out[] = $id;
        }
    }
    return $out;
}

/** Ustawia listę osób odpowiedzialnych; zwraca ich imiona w kolejności. */
function set_task_assignees(int $taskId, array $userIds): array
{
    db()->prepare('DELETE FROM task_assignees WHERE task_id = ?')->execute([$taskId]);

    $stmt  = db()->prepare('INSERT OR IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)');
    $imiona = [];
    foreach ($userIds as $userId) {
        $stmt->execute([$taskId, $userId]);
        $imiona[] = user_name($userId);
    }
    return $imiona;
}

function task_assignee_ids(int $taskId): array
{
    $stmt = db()->prepare('SELECT user_id FROM task_assignees WHERE task_id = ? ORDER BY user_id');
    $stmt->execute([$taskId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function folder_note(int $folderId): array
{
    $stmt = db()->prepare(
        'SELECT n.content, n.updated_at, u.name AS updated_by_name, u.color AS updated_by_color
         FROM notes n
         LEFT JOIN users u ON u.id = n.updated_by
         WHERE n.folder_id = ?'
    );
    $stmt->execute([$folderId]);
    $row = $stmt->fetch();

    return [
        'content'          => $row ? (string)$row['content'] : '',
        'updated_by_name'  => $row ? $row['updated_by_name'] : null,
        'updated_by_color' => $row ? $row['updated_by_color'] : null,
        'updated_at'       => $row ? iso($row['updated_at']) : null,
    ];
}

function folder_files(int $folderId): array
{
    $stmt = db()->prepare(
        'SELECT f.*, u.name AS uploaded_by_name, u.color AS uploaded_by_color, t.title AS task_title
         FROM files f
         LEFT JOIN users u ON u.id = f.uploaded_by
         LEFT JOIN tasks t ON t.id = f.task_id
         WHERE f.folder_id = ?
         ORDER BY f.id DESC'
    );
    $stmt->execute([$folderId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'id'                 => (int)$row['id'],
            'task_id'            => isset($row['task_id']) && $row['task_id'] !== null ? (int)$row['task_id'] : null,
            'task_title'         => $row['task_title'] ?? null,
            'name'               => $row['original_name'],
            'ext'                => $row['ext'],
            'size'               => (int)$row['size'],
            'uploaded_by_name'   => $row['uploaded_by_name'],
            'uploaded_by_color'  => $row['uploaded_by_color'],
            'uploaded_at'        => iso($row['uploaded_at']),
            'url'                => 'api.php?action=download&id=' . (int)$row['id'],
            'preview'            => in_array($row['ext'], ['png', 'jpg', 'jpeg', 'pdf'], true)
                ? 'api.php?action=download&inline=1&id=' . (int)$row['id']
                : null,
            /* Dokumenty Worda przeglądarka pokazuje dopiero po przerobieniu
               na HTML — robi to panel, w oknie podglądu. */
            'viewer'             => $row['ext'] === 'docx' ? 'docx' : null,
        ];
    }
    return $out;
}

function task_or_fail(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new ApiError('To zadanie już nie istnieje — odśwież stronę.', 404);
    }
    $row['id']        = (int)$row['id'];
    $row['folder_id'] = (int)$row['folder_id'];
    return $row;
}

function user_name(int $id): string
{
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn();
    return $name === false ? '—' : (string)$name;
}

function status_label(string $status): string
{
    $labels = ['todo' => 'Do zrobienia', 'doing' => 'W trakcie', 'done' => 'Zrobione'];
    return $labels[$status] ?? $status;
}

function priority_label(string $priority): string
{
    $labels = ['high' => 'Wysoki', 'normal' => 'Normalny', 'low' => 'Niski'];
    return $labels[$priority] ?? $priority;
}

/* ================================================================== *
 *  PLIKI — walidacja i kasowanie
 * ================================================================== */

/**
 * Sprawdza, czy zawartość pliku pasuje do rozszerzenia — po sygnaturze
 * bajtowej z początku pliku (tzw. magic bytes).
 *
 * Celowo NIE opieramy się na rozszerzeniu fileinfo: jego baza wzorców różni
 * się między hostingami i potrafi odrzucać poprawne pliki (np. DOCX widziany
 * jako zwykłe archiwum). Sygnatury są stałe i identyczne wszędzie, a przy tym
 * skuteczniej blokują skrypt przebrany za obrazek.
 */
function verify_upload_content(string $tmpPath, string $ext): void
{
    $handle = @fopen($tmpPath, 'rb');
    if ($handle === false) {
        throw new ApiError('Nie udało się odczytać przesłanego pliku.');
    }
    $head = (string)fread($handle, 8);
    fclose($handle);

    /* ZIP i DOCX to ten sam kontener: PK\x03\x04 (albo puste/dzielone archiwum). */
    $zipHeads = ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"];

    $ok = true;
    switch ($ext) {
        case 'pdf':
            $ok = strncmp($head, '%PDF', 4) === 0;
            break;
        case 'png':
            $ok = strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0;
            break;
        case 'jpg':
        case 'jpeg':
            $ok = strncmp($head, "\xFF\xD8\xFF", 3) === 0;
            break;
        case 'zip':
        case 'docx':
            $ok = false;
            foreach ($zipHeads as $sygnatura) {
                if (strncmp($head, $sygnatura, 4) === 0) {
                    $ok = true;
                    break;
                }
            }
            break;
    }

    if (!$ok) {
        throw new ApiError(
            'Zawartość pliku nie odpowiada rozszerzeniu .' . $ext
            . '. Upewnij się, że plik nie jest uszkodzony ani przemianowany.'
        );
    }

    /* Dla obrazów dokładamy kontrolę nagłówka — odsiewa uszkodzone pliki. */
    if (in_array($ext, ['png', 'jpg', 'jpeg'], true) && @getimagesize($tmpPath) === false) {
        throw new ApiError('Ten plik nie jest prawidłowym obrazem albo jest uszkodzony.');
    }
}

function delete_stored_file(string $storedName): void
{
    if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $storedName)) {
        return;
    }
    $path = UPLOAD_DIR . '/' . $storedName;
    if (is_file($path)) {
        @unlink($path);
    }
}

/* ================================================================== *
 *  WEJŚCIE / WYJŚCIE
 * ================================================================== */

/** Ciało żądania w formacie JSON (parsowane raz). */
function body(): array
{
    static $parsed = null;
    if ($parsed !== null) {
        return $parsed;
    }
    $raw    = file_get_contents('php://input');
    $json   = json_decode($raw === false ? '' : $raw, true);
    $parsed = is_array($json) ? $json : [];
    return $parsed;
}

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** "8M" => 8388608. Do pokazania realnego limitu serwera w interfejsie. */
function bytes_from_ini(string $key): int
{
    $raw = (string)ini_get($key);
    if ($raw === '') {
        return MAX_UPLOAD_BYTES;
    }
    $value = (int)$raw;
    switch (strtolower(substr(trim($raw), -1))) {
        case 'g': $value *= 1024 * 1024 * 1024; break;
        case 'm': $value *= 1024 * 1024; break;
        case 'k': $value *= 1024; break;
    }
    return $value > 0 ? $value : MAX_UPLOAD_BYTES;
}
