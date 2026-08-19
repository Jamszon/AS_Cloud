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
        'comment.add', 'comment.delete',
        'note.save', 'file.upload', 'file.chunk', 'file.delete', 'file.assign',
        'meeting.create', 'meeting.update', 'meeting.delete',
        'meeting.join', 'meeting.leave', 'meeting.note',
        /* Odpytanie pokoju też zmienia dane: odświeża obecność i odbiera
           wiadomości ze skrzynki, więc idzie POST-em jak reszta zapisów. */
        'rtc.poll', 'rtc.signal',
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
        case 'task.mine':     return action_task_mine($me);
        case 'task.calendar': return action_task_calendar();

        case 'comment.list':   return action_comment_list();
        case 'comment.add':    return action_comment_add($me);
        case 'comment.delete': return action_comment_delete($me);

        case 'note.save':     return action_note_save($me);

        case 'meeting.list':   return action_meeting_list();
        case 'meeting.open':   return action_meeting_open();
        case 'meeting.create': return action_meeting_create($me);
        case 'meeting.update': return action_meeting_update($me);
        case 'meeting.delete': return action_meeting_delete($me);
        case 'meeting.join':   return action_meeting_join($me);
        case 'meeting.leave':  return action_meeting_leave($me);
        case 'meeting.note':   return action_meeting_note_save($me);

        /* Sygnalizacja WebRTC — obraz i dźwięk idą już poza serwerem. */
        case 'rtc.poll':       return action_rtc_poll($me);
        case 'rtc.signal':     return action_rtc_signal($me);

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
        'meetings' => meetings_all(),
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
    db()->prepare('DELETE FROM task_comments WHERE task_id IN (SELECT id FROM tasks WHERE folder_id = ?)')
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
    $termin = clean_date(body()['due_date'] ?? null);

    $stmt = db()->prepare(
        'INSERT INTO tasks (folder_id, title, description, status, priority, due_date, created_by, created_at, updated_by, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $folder['id'], $title, clean_text((string)(body()['description'] ?? ''), 5000),
        $status, $priority, $termin, $me['id'], now(), $me['id'], now(),
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
    if (array_key_exists('due_date', $body)) {
        $changes['due_date'] = clean_date($body['due_date']);
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
    } elseif (array_key_exists('due_date', $changes) && $changes['due_date'] !== ($task['due_date'] ?? null)) {
        $action  = 'task.due';
        $message = $changes['due_date'] === null
            ? 'Zdjęty termin zadania „' . $title . '”'
            : 'Termin zadania „' . $title . '”: ' . date('j.m.Y', (int)strtotime($changes['due_date']));
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
    db()->prepare('DELETE FROM task_comments WHERE task_id = ?')->execute([$task['id']]);
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

/**
 * Zadania przypisane do zalogowanej osoby — ze wszystkich folderów naraz.
 * Zrobione pomijamy: ten widok ma odpowiadać na pytanie „co mam do zrobienia”.
 */
function action_task_mine(array $me): array
{
    $stmt = db()->prepare(
        'SELECT t.*, f.name AS folder_name,
                cu.name AS created_by_name,
                uu.name AS updated_by_name
         FROM tasks t
         JOIN task_assignees ta ON ta.task_id = t.id AND ta.user_id = ?
         JOIN folders f ON f.id = t.folder_id
         LEFT JOIN users cu ON cu.id = t.created_by
         LEFT JOIN users uu ON uu.id = t.updated_by
         WHERE t.status <> \'done\'
         ORDER BY f.position, t.id'
    );
    $stmt->execute([$me['id']]);
    $rows = $stmt->fetchAll();

    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int)$row['id'];
    }

    $osoby      = assignees_for_tasks($ids);
    $zalaczniki = counts_for_tasks($ids, 'files', 'task_id');
    $komentarze = counts_for_tasks($ids, 'task_comments', 'task_id');

    $out = [];
    foreach ($rows as $row) {
        $out[] = map_task_row($row, $osoby, $zalaczniki, $komentarze) + [
            'folder_name' => $row['folder_name'],
        ];
    }

    return ['ok' => true, 'tasks' => $out];
}

/**
 * Zadania z terminem mieszczącym się w podanym zakresie dat — ze wszystkich
 * folderów naraz. Zakres wyznacza siatka kalendarza w przeglądarce, więc
 * obejmuje też kilka dni z sąsiednich miesięcy.
 *
 * Zrobione zadania zostają w wyniku: kalendarz pokazuje, co na kiedy było
 * zaplanowane, a nie tylko to, co jeszcze zostało do zrobienia.
 */
function action_task_calendar(): array
{
    $od = clean_range_date($_GET['from'] ?? null);
    $do = clean_range_date($_GET['to'] ?? null);
    if ($od > $do) {
        [$od, $do] = [$do, $od];
    }

    /* due_date trzymamy jako RRRR-MM-DD, więc porównanie tekstowe działa tu
       tak samo jak porównanie dat. Zadania bez terminu odpadają same:
       NULL nie przechodzi porównania, a pusty tekst jest mniejszy niż
       jakakolwiek data. */
    $stmt = db()->prepare(
        'SELECT t.*, f.name AS folder_name,
                cu.name AS created_by_name, cu.color AS created_by_color,
                uu.name AS updated_by_name
         FROM tasks t
         JOIN folders f ON f.id = t.folder_id
         LEFT JOIN users cu ON cu.id = t.created_by
         LEFT JOIN users uu ON uu.id = t.updated_by
         WHERE t.due_date >= ? AND t.due_date <= ?
         ORDER BY t.due_date, f.position, t.id'
    );
    $stmt->execute([$od, $do]);
    $rows = $stmt->fetchAll();

    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int)$row['id'];
    }

    $osoby      = assignees_for_tasks($ids);
    $zalaczniki = counts_for_tasks($ids, 'files', 'task_id');
    $komentarze = counts_for_tasks($ids, 'task_comments', 'task_id');

    $out = [];
    foreach ($rows as $row) {
        $out[] = map_task_row($row, $osoby, $zalaczniki, $komentarze) + [
            'folder_name' => $row['folder_name'],
        ];
    }

    return ['ok' => true, 'from' => $od, 'to' => $do, 'tasks' => $out];
}

/**
 * Granica zakresu kalendarza. Osobno od clean_date(), bo tam pusta wartość
 * znaczy „bez terminu”, a tutaj brak daty to po prostu złe wywołanie.
 */
function clean_range_date($value): string
{
    $value = is_string($value) ? trim($value) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        throw new ApiError('Kalendarz wymaga zakresu dat w formacie RRRR-MM-DD.');
    }
    [$rok, $miesiac, $dzien] = array_map('intval', explode('-', $value));
    if (!checkdate($miesiac, $dzien, $rok)) {
        throw new ApiError('Podana data nie istnieje w kalendarzu.');
    }
    return $value;
}

/* ================================================================== *
 *  AKCJE — komentarze pod zadaniem
 * ================================================================== */

function action_comment_list(): array
{
    $task = task_or_fail((int)($_GET['task_id'] ?? 0));
    return ['ok' => true, 'comments' => task_comments((int)$task['id'])];
}

function action_comment_add(array $me): array
{
    $task = task_or_fail((int)(body()['task_id'] ?? 0));
    $body = clean_text((string)(body()['body'] ?? ''), 3000);

    if (trim($body) === '') {
        throw new ApiError('Napisz treść komentarza.');
    }

    db()->prepare('INSERT INTO task_comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$task['id'], $me['id'], $body, now()]);

    $folder = folder_or_fail((int)$task['folder_id']);
    log_activity($me['id'], 'comment.add', 'Komentarz do zadania „' . $task['title'] . '”',
        $folder['id'], $folder['name']);

    return [
        'ok'       => true,
        'comments' => task_comments((int)$task['id']),
        'tasks'    => folder_tasks($folder['id']),
        'activity' => activity_feed(),
    ];
}

/**
 * Komentarz może skasować tylko jego autor. Przy czterech zaufanych osobach
 * to nie kwestia uprawnień, tylko tego, żeby nikt nie zmieniał cudzej
 * wypowiedzi w dyskusji.
 */
function action_comment_delete(array $me): array
{
    $stmt = db()->prepare('SELECT * FROM task_comments WHERE id = ?');
    $stmt->execute([(int)(body()['id'] ?? 0)]);
    $comment = $stmt->fetch();
    if (!$comment) {
        throw new ApiError('Ten komentarz już nie istnieje.', 404);
    }
    if ((int)$comment['user_id'] !== (int)$me['id']) {
        throw new ApiError('Możesz usuwać tylko własne komentarze.', 403);
    }

    $task   = task_or_fail((int)$comment['task_id']);
    $folder = folder_or_fail((int)$task['folder_id']);

    db()->prepare('DELETE FROM task_comments WHERE id = ?')->execute([(int)$comment['id']]);

    return [
        'ok'       => true,
        'comments' => task_comments((int)$task['id']),
        'tasks'    => folder_tasks($folder['id']),
    ];
}

function task_comments(int $taskId): array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.body, c.created_at, c.user_id, u.name AS user_name, u.color AS user_color
         FROM task_comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.task_id = ?
         ORDER BY c.id'
    );
    $stmt->execute([$taskId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'id'         => (int)$row['id'],
            'user_id'    => (int)$row['user_id'],
            'user_name'  => $row['user_name'],
            'user_color' => $row['user_color'],
            'body'       => $row['body'],
            'at'         => iso($row['created_at']),
        ];
    }
    return $out;
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
 *  AKCJE — spotkania wideo
 * ================================================================== */

/**
 * Lista wszystkich spotkań zespołu, od najbliższego terminu.
 * Panel jest wspólną przestrzenią czterech osób, więc spotkania widzą
 * wszyscy — zarządza nimi ten, kto je umówił.
 */
function action_meeting_list(): array
{
    return ['ok' => true, 'meetings' => meetings_all()];
}

/** Jedno spotkanie wraz z notatką — do widoku szczegółów i do pokoju. */
function action_meeting_open(): array
{
    $meeting = meeting_or_fail_by_any($_GET['id'] ?? null, $_GET['room'] ?? null);

    return [
        'ok'      => true,
        'meeting' => map_meeting($meeting),
        /* Rzutowanie jest konieczne: PDO SQLite w PHP 7.4 oddaje kolumny
           jako łańcuchy, a strict_types nie wpuści ich do parametru int. */
        'note'    => meeting_note((int)$meeting['id']),
    ];
}

function action_meeting_create(array $me): array
{
    $dane = meeting_input_or_fail();

    /* room_id trafia do linku, więc musi być nie do odgadnięcia. Kolizja
       jest skrajnie mało prawdopodobna, ale unikalność pilnuje i tak baza. */
    $roomId = new_room_id();
    for ($proba = 0; $proba < 5; $proba++) {
        $zajete = db()->prepare('SELECT 1 FROM meetings WHERE room_id = ?');
        $zajete->execute([$roomId]);
        if (!$zajete->fetchColumn()) {
            break;
        }
        $roomId = new_room_id();
    }

    db()->prepare(
        'INSERT INTO meetings
            (room_id, title, description, folder_id, starts_at, duration_min,
             status, created_by, created_at, updated_by, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, \'scheduled\', ?, ?, ?, ?)'
    )->execute([
        $roomId, $dane['title'], $dane['description'], $dane['folder_id'],
        $dane['starts_at'], $dane['duration_min'], $me['id'], now(), $me['id'], now(),
    ]);
    $id = (int)db()->lastInsertId();

    set_meeting_participants($id, $dane['user_ids'], $dane['emails'], (int)$me['id']);

    $folder = $dane['folder_id'] ? folder_or_fail($dane['folder_id']) : null;
    log_activity($me['id'], 'meeting.create', 'Umówił spotkanie „' . $dane['title'] . '”',
        $folder ? (int)$folder['id'] : null, $folder ? $folder['name'] : null);

    return ['ok' => true, 'meeting_id' => $id, 'meetings' => meetings_all(), 'activity' => activity_feed()];
}

function action_meeting_update(array $me): array
{
    $meeting = meeting_or_fail((int)(body()['id'] ?? 0));
    meeting_owner_or_fail($meeting, $me);

    $body = body();

    /* Odwołanie i wznowienie idą osobną ścieżką: nie zmieniają terminu
       ani uczestników, więc formularz nie musi ich odsyłać. */
    if (array_key_exists('status', $body) && !array_key_exists('title', $body)) {
        $nowy = (string)$body['status'];
        if (!in_array($nowy, ['scheduled', 'ended', 'cancelled'], true)) {
            throw new ApiError('Nieznany status spotkania.');
        }

        $pola = ['status' => $nowy, 'updated_by' => $me['id'], 'updated_at' => now()];
        $pola['ended_at'] = $nowy === 'ended' ? now() : null;

        db()->prepare('UPDATE meetings SET status = ?, ended_at = ?, updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute([$pola['status'], $pola['ended_at'], $pola['updated_by'], $pola['updated_at'], $meeting['id']]);

        if ($nowy !== 'scheduled') {
            db()->prepare('DELETE FROM meeting_presence WHERE meeting_id = ?')->execute([$meeting['id']]);
        }

        $slowo = $nowy === 'cancelled' ? 'Odwołał' : ($nowy === 'ended' ? 'Zakończył' : 'Przywrócił');
        log_activity($me['id'], 'meeting.update', $slowo . ' spotkanie „' . $meeting['title'] . '”');

        return ['ok' => true, 'meetings' => meetings_all(), 'activity' => activity_feed()];
    }

    $dane = meeting_input_or_fail();

    db()->prepare(
        'UPDATE meetings
            SET title = ?, description = ?, folder_id = ?, starts_at = ?, duration_min = ?,
                updated_by = ?, updated_at = ?
          WHERE id = ?'
    )->execute([
        $dane['title'], $dane['description'], $dane['folder_id'], $dane['starts_at'],
        $dane['duration_min'], $me['id'], now(), $meeting['id'],
    ]);

    set_meeting_participants((int)$meeting['id'], $dane['user_ids'], $dane['emails'], (int)$meeting['created_by']);

    log_activity($me['id'], 'meeting.update', 'Zmienił spotkanie „' . $dane['title'] . '”');

    return ['ok' => true, 'meetings' => meetings_all(), 'activity' => activity_feed()];
}

function action_meeting_delete(array $me): array
{
    $meeting = meeting_or_fail((int)(body()['id'] ?? 0));
    meeting_owner_or_fail($meeting, $me);

    /* Kaskady w schemacie sprzątają uczestników, notatkę, obecność i sygnały. */
    db()->prepare('DELETE FROM meetings WHERE id = ?')->execute([$meeting['id']]);

    log_activity($me['id'], 'meeting.delete', 'Usunął spotkanie „' . $meeting['title'] . '”');

    return ['ok' => true, 'meetings' => meetings_all(), 'activity' => activity_feed()];
}

/**
 * Wejście do pokoju. Zakłada wpis obecności dla tej karty przeglądarki
 * i zwraca wszystko, czego potrzebuje warstwa WebRTC.
 */
function action_meeting_join(array $me): array
{
    $meeting = meeting_or_fail_by_any(body()['id'] ?? null, body()['room'] ?? null);
    $peerId  = clean_peer_id(body()['peer_id'] ?? '');

    $stan = meeting_state($meeting);
    if (!$stan['can_join']) {
        throw new ApiError($stan['join_hint']);
    }

    sprzataj_pokoje();

    /* Ta sama karta wchodząca ponownie (odświeżenie strony) nadpisuje
       swój poprzedni wpis, zamiast dokładać drugiego uczestnika. */
    db()->prepare('DELETE FROM meeting_presence WHERE peer_id = ?')->execute([$peerId]);
    db()->prepare(
        /* Wchodzimy z mikrofonem, bez kamery — przeglądarka i tak przyśle
           swój stan przy pierwszym odpytaniu, ale lista obecnych ma być
           prawdziwa od pierwszej chwili. */
        'INSERT INTO meeting_presence (meeting_id, peer_id, user_id, mic, cam, sharing, joined_at, seen_at)
         VALUES (?, ?, ?, 1, 0, 0, ?, ?)'
    )->execute([$meeting['id'], $peerId, $me['id'], now(), now()]);

    /* Pierwsze wejście uruchamia spotkanie — status „w trakcie” bierze się
       stąd, a nie z samego zegara. */
    if ($meeting['started_at'] === null) {
        db()->prepare('UPDATE meetings SET started_at = ? WHERE id = ?')->execute([now(), $meeting['id']]);
        log_activity($me['id'], 'meeting.join', 'Rozpoczął spotkanie „' . $meeting['title'] . '”');
    }

    $swieze = meeting_or_fail((int)$meeting['id']);

    return [
        'ok'          => true,
        'meeting'     => map_meeting($swieze),
        'note'        => meeting_note((int)$meeting['id']),
        'peers'       => meeting_peers((int)$meeting['id'], $peerId),
        'ice_servers' => ice_servers(),
        'has_turn'    => count(TURN_SERVERS) > 0,
        'cursor'      => (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM meeting_signals')->fetchColumn(),
        'meetings'    => meetings_all(),
    ];
}

/** Wyjście z pokoju. Pozostali zauważą to przy najbliższym odpytaniu. */
function action_meeting_leave(array $me): array
{
    $peerId = clean_peer_id(body()['peer_id'] ?? '');

    $wpis = db()->prepare('SELECT meeting_id FROM meeting_presence WHERE peer_id = ?');
    $wpis->execute([$peerId]);
    $meetingId = (int)$wpis->fetchColumn();

    db()->prepare('DELETE FROM meeting_presence WHERE peer_id = ?')->execute([$peerId]);
    db()->prepare('DELETE FROM meeting_signals WHERE from_peer = ? OR to_peer = ?')->execute([$peerId, $peerId]);

    /* Gdy wychodzi ostatnia osoba, spotkanie samo się domyka — ale tylko
       takie, które faktycznie się zaczęło. Ktoś, kto zajrzał do pokoju
       kwadrans przed czasem i wyszedł, nie kończy spotkania, które ma się
       dopiero odbyć. */
    if ($meetingId > 0) {
        $zostali = db()->prepare('SELECT COUNT(*) FROM meeting_presence WHERE meeting_id = ?');
        $zostali->execute([$meetingId]);

        if ((int)$zostali->fetchColumn() === 0) {
            $meeting = meeting_or_fail($meetingId);
            $zaczete = $meeting['started_at'] !== null && time() >= (int)strtotime($meeting['starts_at']);

            if ($meeting['status'] === 'scheduled' && $zaczete) {
                db()->prepare('UPDATE meetings SET status = \'ended\', ended_at = ?, updated_by = ?, updated_at = ? WHERE id = ?')
                    ->execute([now(), $me['id'], now(), $meetingId]);
                log_activity($me['id'], 'meeting.end', 'Zakończył spotkanie „' . $meeting['title'] . '”');
            }
        }
    }

    return ['ok' => true, 'meetings' => meetings_all()];
}

/**
 * Jedno odpytanie robi trzy rzeczy naraz: podtrzymuje obecność, zwraca
 * listę osób w pokoju i odbiera zaadresowane do nas wiadomości WebRTC.
 * Dzięki temu pokój na czteroosobowy zespół kosztuje jedno żądanie na
 * sekundę na osobę, a po zestawieniu połączeń jeszcze mniej.
 */
function action_rtc_poll(array $me): array
{
    $peerId = clean_peer_id(body()['peer_id'] ?? '');
    $since  = max(0, (int)(body()['since'] ?? 0));

    $wpis = db()->prepare('SELECT * FROM meeting_presence WHERE peer_id = ? AND user_id = ?');
    $wpis->execute([$peerId, $me['id']]);
    $obecnosc = $wpis->fetch();

    if (!$obecnosc) {
        /* Wypadliśmy z pokoju — przeglądarka ma się przyłączyć od nowa. */
        return ['ok' => true, 'rejoin' => true];
    }

    $meetingId = (int)$obecnosc['meeting_id'];

    /* Stan mikrofonu i kamery jedzie razem z odpytaniem, żeby nie mnożyć żądań.
       Bierzemy pod uwagę tylko te pola, które faktycznie przyszły: odpytanie
       bez nich (na przykład ponowione po błędzie sieci) nie może po cichu
       wyciszyć komuś mikrofonu. Nazwy kolumn pochodzą z zamkniętej listy,
       więc doklejenie ich do zapytania jest bezpieczne. */
    $body  = body();
    $ustaw = ['seen_at = ?'];
    $dane  = [now()];

    foreach (['mic', 'cam', 'sharing'] as $pole) {
        if (array_key_exists($pole, $body)) {
            $ustaw[] = $pole . ' = ?';
            $dane[]  = !empty($body[$pole]) ? 1 : 0;
        }
    }
    $dane[] = $peerId;

    db()->prepare('UPDATE meeting_presence SET ' . implode(', ', $ustaw) . ' WHERE peer_id = ?')
        ->execute($dane);

    sprzataj_pokoje();

    $skrzynka = db()->prepare(
        'SELECT id, from_peer, kind, payload FROM meeting_signals
          WHERE to_peer = ? AND id > ? ORDER BY id LIMIT 60'
    );
    $skrzynka->execute([$peerId, $since]);
    $wiadomosci = $skrzynka->fetchAll();

    $kursor = $since;
    $out    = [];
    foreach ($wiadomosci as $w) {
        $kursor = max($kursor, (int)$w['id']);
        $out[]  = [
            'from'    => $w['from_peer'],
            'kind'    => $w['kind'],
            'payload' => json_decode($w['payload'], true),
        ];
    }

    $meeting = meeting_or_fail($meetingId);

    return [
        'ok'      => true,
        'peers'   => meeting_peers($meetingId, $peerId),
        'signals' => $out,
        'cursor'  => $kursor,
        'closed'  => in_array($meeting['status'], ['ended', 'cancelled'], true),
    ];
}

/** Odkłada wiadomość WebRTC do skrzynki drugiej strony. */
function action_rtc_signal(array $me): array
{
    $body   = body();
    $peerId = clean_peer_id($body['peer_id'] ?? '');
    $doKogo = clean_peer_id($body['to'] ?? '');
    $rodzaj = (string)($body['kind'] ?? '');

    if (!in_array($rodzaj, ['offer', 'answer', 'ice'], true)) {
        throw new ApiError('Nieznany rodzaj wiadomości sygnalizacyjnej.');
    }

    $wpis = db()->prepare('SELECT meeting_id FROM meeting_presence WHERE peer_id = ? AND user_id = ?');
    $wpis->execute([$peerId, $me['id']]);
    $meetingId = (int)$wpis->fetchColumn();

    if ($meetingId === 0) {
        throw new ApiError('Nie jesteś w tym pokoju.', 403);
    }

    /* Wiadomość wolno wysłać wyłącznie do kogoś, kto siedzi w tym samym
       pokoju — inaczej sesja w panelu pozwalałaby zaczepiać obce pokoje. */
    $cel = db()->prepare('SELECT 1 FROM meeting_presence WHERE peer_id = ? AND meeting_id = ?');
    $cel->execute([$doKogo, $meetingId]);
    if (!$cel->fetchColumn()) {
        throw new ApiError('Odbiorca nie jest już w pokoju.', 409);
    }

    $payload = json_encode($body['payload'] ?? null, JSON_UNESCAPED_UNICODE);
    if ($payload === false || strlen($payload) > 200000) {
        throw new ApiError('Wiadomość sygnalizacyjna jest nieprawidłowa.');
    }

    db()->prepare(
        'INSERT INTO meeting_signals (meeting_id, from_peer, to_peer, kind, payload, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$meetingId, $peerId, $doKogo, $rodzaj, $payload, now()]);

    return ['ok' => true];
}

/**
 * Zapis notatki. Numer wersji rośnie z każdym zapisem — gdy przyjdzie
 * zapis oparty o starszą wersję, znaczy to, że ktoś pisał równolegle.
 * Wtedy nie nadpisujemy po cichu, tylko oddajemy obie wersje decyzji
 * użytkownika (chyba że wprost prosi o nadpisanie).
 */
function action_meeting_note_save(array $me): array
{
    $meeting = meeting_or_fail((int)(body()['meeting_id'] ?? 0));
    $tresc   = clean_text((string)(body()['content'] ?? ''));
    $wersja  = (int)(body()['revision'] ?? 0);
    $silowo  = !empty(body()['force']);

    $obecna = meeting_note((int)$meeting['id']);

    if (!$silowo && $wersja !== (int)$obecna['revision']) {
        return [
            'ok'       => true,
            'saved'    => false,
            'conflict' => true,
            'note'     => $obecna,
        ];
    }

    $nowaWersja = (int)$obecna['revision'] + 1;

    /* Bez UPSERT-a: składnia ON CONFLICT wymaga SQLite 3.24+, a nie wiadomo,
       co siedzi na hostingu. Reszta panelu zapisuje notatki tak samo. */
    $istnieje = db()->prepare('SELECT 1 FROM meeting_notes WHERE meeting_id = ?');
    $istnieje->execute([$meeting['id']]);

    if ($istnieje->fetchColumn()) {
        db()->prepare('UPDATE meeting_notes SET content = ?, revision = ?, updated_by = ?, updated_at = ? WHERE meeting_id = ?')
            ->execute([$tresc, $nowaWersja, $me['id'], now(), $meeting['id']]);
    } else {
        db()->prepare('INSERT INTO meeting_notes (meeting_id, content, revision, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$meeting['id'], $tresc, $nowaWersja, $me['id'], now()]);
    }

    return ['ok' => true, 'saved' => true, 'note' => meeting_note((int)$meeting['id'])];
}

/* ================================================================== *
 *  SPOTKANIA — pomocnicze
 * ================================================================== */

/** Odczyt i walidacja formularza spotkania. */
function meeting_input_or_fail(): array
{
    $body = body();

    $title = clean_line((string)($body['title'] ?? ''), 120);
    if ($title === '') {
        throw new ApiError('Podaj temat spotkania.');
    }

    $data    = clean_date($body['date'] ?? null);
    if ($data === null) {
        throw new ApiError('Podaj datę spotkania.');
    }
    $godzina = clean_time($body['time'] ?? '');

    $czas = (int)($body['duration_min'] ?? 30);
    if ($czas < 5 || $czas > 480) {
        throw new ApiError('Czas trwania musi mieścić się między 5 minutami a 8 godzinami.');
    }

    $folderId = isset($body['folder_id']) && $body['folder_id'] !== null && $body['folder_id'] !== ''
        ? (int)$body['folder_id']
        : null;
    if ($folderId !== null) {
        folder_or_fail($folderId);   // rzuci, jeśli folder zniknął
    }

    /* Adresy spoza zespołu zapisujemy jako listę zaproszonych. Panel nie
       wysyła poczty, więc link trzeba przekazać ręcznie — mówi o tym
       podpowiedź przy formularzu. */
    $maile = [];
    foreach ((array)($body['emails'] ?? []) as $adres) {
        $adres = mb_strtolower(clean_line((string)$adres, 190));
        if ($adres === '') {
            continue;
        }
        if (!filter_var($adres, FILTER_VALIDATE_EMAIL)) {
            throw new ApiError('To nie wygląda na adres e-mail: ' . $adres);
        }
        if (!in_array($adres, $maile, true)) {
            $maile[] = $adres;
        }
    }
    if (count($maile) > 20) {
        throw new ApiError('Maksymalnie 20 adresów e-mail na spotkanie.');
    }

    return [
        'title'        => $title,
        'description'  => clean_text((string)($body['description'] ?? ''), 4000),
        'folder_id'    => $folderId,
        'starts_at'    => $data . ' ' . $godzina . ':00',
        'duration_min' => $czas,
        'user_ids'     => valid_user_ids($body['user_ids'] ?? []),
        'emails'       => $maile,
    ];
}

/** Ustawia listę uczestników; twórca spotkania zawsze zostaje na liście. */
function set_meeting_participants(int $meetingId, array $userIds, array $emails, int $ownerId): void
{
    db()->prepare('DELETE FROM meeting_participants WHERE meeting_id = ?')->execute([$meetingId]);

    if (!in_array($ownerId, $userIds, true)) {
        array_unshift($userIds, $ownerId);
    }

    $osoba = db()->prepare(
        'INSERT INTO meeting_participants (meeting_id, user_id, role, invited_at) VALUES (?, ?, ?, ?)'
    );
    foreach ($userIds as $userId) {
        $osoba->execute([$meetingId, $userId, $userId === $ownerId ? 'host' : 'guest', now()]);
    }

    $mail = db()->prepare(
        'INSERT INTO meeting_participants (meeting_id, email, role, invited_at) VALUES (?, ?, \'guest\', ?)'
    );
    foreach ($emails as $adres) {
        $mail->execute([$meetingId, $adres, now()]);
    }
}

/** Zapytanie bazowe: spotkanie razem z nazwą folderu i danymi autora. */
function meeting_select(): string
{
    return 'SELECT m.*, f.name AS folder_name,
                   cu.name AS created_by_name, cu.color AS created_by_color
              FROM meetings m
              LEFT JOIN folders f ON f.id = m.folder_id
              LEFT JOIN users cu ON cu.id = m.created_by';
}

function meeting_or_fail(int $id): array
{
    $stmt = db()->prepare(meeting_select() . ' WHERE m.id = ?');
    $stmt->execute([$id]);
    $meeting = $stmt->fetch();

    if (!$meeting) {
        throw new ApiError('Nie ma takiego spotkania.', 404);
    }
    return $meeting;
}

/** Spotkanie po identyfikatorze liczbowym albo po identyfikatorze pokoju. */
function meeting_or_fail_by_any($id, $room): array
{
    if ($id !== null && $id !== '' && (int)$id > 0) {
        return meeting_or_fail((int)$id);
    }

    $room = clean_line((string)$room, 40);
    if ($room === '') {
        throw new ApiError('Podaj, o które spotkanie chodzi.');
    }

    $stmt = db()->prepare(meeting_select() . ' WHERE m.room_id = ?');
    $stmt->execute([$room]);
    $meeting = $stmt->fetch();

    if (!$meeting) {
        throw new ApiError('Nie ma pokoju o takim adresie.', 404);
    }
    return $meeting;
}

/** Zmieniać i kasować spotkanie może ten, kto je umówił. */
function meeting_owner_or_fail(array $meeting, array $me): void
{
    if ((int)$meeting['created_by'] !== (int)$me['id']) {
        throw new ApiError('Spotkaniem zarządza osoba, która je umówiła.', 403);
    }
}

/** Identyfikator karty przeglądarki — losowy ciąg wygenerowany po stronie klienta. */
function clean_peer_id($value): string
{
    $value = trim((string)$value);
    if (!preg_match('/^[a-z0-9]{8,40}$/', $value)) {
        throw new ApiError('Nieprawidłowy identyfikator uczestnika.');
    }
    return $value;
}

/**
 * Wyrzuca z pokoi osoby, które przestały się odzywać (zamknięta karta,
 * zerwany internet), i kasuje przeterminowane wiadomości sygnalizacyjne.
 * Wiadomości żyją minutę: dłużej i tak są bezużyteczne, bo dotyczą
 * połączenia, które w tym czasie zdążyło się już zestawić albo zerwać.
 */
function sprzataj_pokoje(): void
{
    $prog = date('Y-m-d H:i:s', time() - MEETING_PEER_TIMEOUT);
    db()->prepare('DELETE FROM meeting_presence WHERE seen_at < ?')->execute([$prog]);

    $stare = date('Y-m-d H:i:s', time() - 60);
    db()->prepare('DELETE FROM meeting_signals WHERE created_at < ?')->execute([$stare]);
}

/** Osoby obecne w pokoju; „ja” dostaje znacznik, żeby klient się nie mnożył. */
function meeting_peers(int $meetingId, string $peerId): array
{
    $stmt = db()->prepare(
        'SELECT p.peer_id, p.user_id, p.mic, p.cam, p.sharing, p.joined_at,
                u.name, u.color
           FROM meeting_presence p
           JOIN users u ON u.id = p.user_id
          WHERE p.meeting_id = ?
          ORDER BY p.joined_at, p.id'
    );
    $stmt->execute([$meetingId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'peer_id' => $row['peer_id'],
            'user_id' => (int)$row['user_id'],
            'name'    => $row['name'],
            'color'   => $row['color'],
            'mic'     => (int)$row['mic'] === 1,
            'cam'     => (int)$row['cam'] === 1,
            'sharing' => (int)$row['sharing'] === 1,
            'me'      => $row['peer_id'] === $peerId,
        ];
    }
    return $out;
}

function meeting_note(int $meetingId): array
{
    $stmt = db()->prepare(
        'SELECT n.content, n.revision, n.updated_at, u.name AS updated_by_name
           FROM meeting_notes n
           LEFT JOIN users u ON u.id = n.updated_by
          WHERE n.meeting_id = ?'
    );
    $stmt->execute([$meetingId]);
    $row = $stmt->fetch();

    return [
        'content'         => $row['content'] ?? '',
        'revision'        => (int)($row['revision'] ?? 0),
        'updated_by_name' => $row['updated_by_name'] ?? null,
        'updated_at'      => iso($row['updated_at'] ?? null),
    ];
}

/** Wszystkie spotkania z uczestnikami i stanem — jednym kompletem zapytań. */
function meetings_all(): array
{
    sprzataj_pokoje();

    $rows = db()->query(meeting_select() . ' ORDER BY m.starts_at DESC, m.id DESC')->fetchAll();

    if (!$rows) {
        return [];
    }

    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int)$row['id'];
    }
    $miejsca = implode(',', array_fill(0, count($ids), '?'));

    /* Uczestnicy wszystkich spotkań naraz — bez zapytania na wiersz. */
    $stmt = db()->prepare(
        'SELECT mp.meeting_id, mp.user_id, mp.email, mp.role, u.name, u.color
           FROM meeting_participants mp
           LEFT JOIN users u ON u.id = mp.user_id
          WHERE mp.meeting_id IN (' . $miejsca . ')
          ORDER BY mp.role DESC, mp.id'
    );
    $stmt->execute($ids);

    $uczestnicy = [];
    foreach ($stmt->fetchAll() as $row) {
        $uczestnicy[(int)$row['meeting_id']][] = [
            'user_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'email'   => $row['email'],
            'name'    => $row['name'] ?? $row['email'],
            'color'   => $row['color'],
            'role'    => $row['role'],
        ];
    }

    $stmt = db()->prepare(
        'SELECT meeting_id, COUNT(*) AS ile FROM meeting_presence
          WHERE meeting_id IN (' . $miejsca . ') GROUP BY meeting_id'
    );
    $stmt->execute($ids);
    $wPokoju = [];
    foreach ($stmt->fetchAll() as $row) {
        $wPokoju[(int)$row['meeting_id']] = (int)$row['ile'];
    }

    $stmt = db()->prepare(
        'SELECT meeting_id, LENGTH(content) AS dlugosc, updated_at FROM meeting_notes
          WHERE meeting_id IN (' . $miejsca . ')'
    );
    $stmt->execute($ids);
    $notatki = [];
    foreach ($stmt->fetchAll() as $row) {
        $notatki[(int)$row['meeting_id']] = [
            'has_note'   => (int)$row['dlugosc'] > 0,
            'updated_at' => iso($row['updated_at']),
        ];
    }

    $out = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $out[] = map_meeting($row, $uczestnicy[$id] ?? [], $wPokoju[$id] ?? 0, $notatki[$id] ?? null);
    }
    return $out;
}

/** Wiersz spotkania => kształt oczekiwany przez interfejs. */
function map_meeting(array $row, ?array $uczestnicy = null, ?int $wPokoju = null, ?array $notatka = null): array
{
    $id = (int)$row['id'];

    if ($uczestnicy === null) {
        $stmt = db()->prepare(
            'SELECT mp.user_id, mp.email, mp.role, u.name, u.color
               FROM meeting_participants mp
               LEFT JOIN users u ON u.id = mp.user_id
              WHERE mp.meeting_id = ? ORDER BY mp.role DESC, mp.id'
        );
        $stmt->execute([$id]);
        $uczestnicy = [];
        foreach ($stmt->fetchAll() as $u) {
            $uczestnicy[] = [
                'user_id' => $u['user_id'] !== null ? (int)$u['user_id'] : null,
                'email'   => $u['email'],
                'name'    => $u['name'] ?? $u['email'],
                'color'   => $u['color'],
                'role'    => $u['role'],
            ];
        }
    }

    if ($wPokoju === null) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM meeting_presence WHERE meeting_id = ?');
        $stmt->execute([$id]);
        $wPokoju = (int)$stmt->fetchColumn();
    }

    $stan = meeting_state($row, $wPokoju);

    return [
        'id'               => $id,
        'room_id'          => $row['room_id'],
        'title'            => $row['title'],
        'description'      => $row['description'],
        'folder_id'        => $row['folder_id'] !== null ? (int)$row['folder_id'] : null,
        'folder_name'      => $row['folder_name'] ?? null,
        'starts_at'        => iso($row['starts_at']),
        'starts_at_local'  => $row['starts_at'],
        'duration_min'     => (int)$row['duration_min'],
        'status'           => $stan['status'],
        'stored_status'    => $row['status'],
        'can_join'         => $stan['can_join'],
        'join_hint'        => $stan['join_hint'],
        'in_room'          => $wPokoju,
        'participants'     => $uczestnicy,
        'has_note'         => $notatka['has_note'] ?? null,
        'note_updated_at'  => $notatka['updated_at'] ?? null,
        'created_by'       => (int)$row['created_by'],
        'created_by_name'  => $row['created_by_name'] ?? null,
        'created_by_color' => $row['created_by_color'] ?? null,
        'started_at'       => iso($row['started_at']),
        'ended_at'         => iso($row['ended_at']),
    ];
}

/**
 * Stan spotkania liczony na bieżąco. Baza pamięta tylko decyzje ludzi
 * (odwołane, zakończone); reszta wynika z zegara i z tego, czy ktoś
 * siedzi w pokoju. Dzięki temu status nie „zawiesza się” na nadchodzącym
 * spotkaniu sprzed miesiąca, na które nikt nie przyszedł.
 */
function meeting_state(array $row, ?int $wPokoju = null): array
{
    if ($wPokoju === null) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM meeting_presence WHERE meeting_id = ?');
        $stmt->execute([(int)$row['id']]);
        $wPokoju = (int)$stmt->fetchColumn();
    }

    $teraz  = time();
    $start  = (int)strtotime($row['starts_at']);
    $koniec = $start + ((int)$row['duration_min'] * 60);

    $otwarcie   = $start - MEETING_JOIN_EARLY_MIN * 60;
    $zamkniecie = $koniec + MEETING_JOIN_LATE_MIN * 60;

    if ($row['status'] === 'cancelled') {
        return ['status' => 'cancelled', 'can_join' => false, 'join_hint' => 'Spotkanie zostało odwołane.'];
    }

    if ($row['status'] === 'ended') {
        return ['status' => 'ended', 'can_join' => false, 'join_hint' => 'Spotkanie jest już zakończone.'];
    }

    if ($wPokoju > 0) {
        return ['status' => 'live', 'can_join' => true, 'join_hint' => ''];
    }

    if ($teraz > $zamkniecie) {
        return ['status' => 'ended', 'can_join' => false, 'join_hint' => 'Termin tego spotkania dawno minął.'];
    }

    if ($teraz < $otwarcie) {
        $status = 'scheduled';
        $hint   = 'Pokój otwiera się ' . MEETING_JOIN_EARLY_MIN . ' minut przed startem.';
        return ['status' => $status, 'can_join' => false, 'join_hint' => $hint];
    }

    return [
        'status'    => $teraz >= $start ? 'open' : 'soon',
        'can_join'  => true,
        'join_hint' => '',
    ];
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

    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int)$row['id'];
    }

    $osoby      = assignees_for_tasks($ids);
    $zalaczniki = counts_for_tasks($ids, 'files', 'task_id');
    $komentarze = counts_for_tasks($ids, 'task_comments', 'task_id');

    $out = [];
    foreach ($rows as $row) {
        $out[] = map_task_row($row, $osoby, $zalaczniki, $komentarze);
    }
    return $out;
}

/** Wiersz zadania z bazy => kształt oczekiwany przez interfejs. */
function map_task_row(array $row, array $osoby, array $zalaczniki, array $komentarze): array
{
    $id = (int)$row['id'];
    return [
        'id'               => $id,
        'folder_id'        => (int)$row['folder_id'],
        'title'            => $row['title'],
        'description'      => $row['description'],
        'status'           => $row['status'],
        'priority'         => $row['priority'] ?? 'normal',
        'due_date'         => (isset($row['due_date']) && $row['due_date'] !== '') ? $row['due_date'] : null,
        'assignees'        => $osoby[$id] ?? [],
        'file_count'       => $zalaczniki[$id] ?? 0,
        'comment_count'    => $komentarze[$id] ?? 0,
        'created_by_name'  => $row['created_by_name'] ?? null,
        'created_by_color' => $row['created_by_color'] ?? null,
        'created_at'       => iso($row['created_at']),
        'updated_by_name'  => $row['updated_by_name'] ?? null,
        'updated_at'       => iso($row['updated_at']),
    ];
}

/** Osoby przypisane do wskazanych zadań — jednym zapytaniem. */
function assignees_for_tasks(array $taskIds): array
{
    if (!$taskIds) {
        return [];
    }
    $miejsca = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = db()->prepare(
        'SELECT ta.task_id, u.id, u.name, u.color
         FROM task_assignees ta
         JOIN users u ON u.id = ta.user_id
         WHERE ta.task_id IN (' . $miejsca . ')
         ORDER BY u.id'
    );
    $stmt->execute($taskIds);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['task_id']][] = [
            'id'    => (int)$row['id'],
            'name'  => $row['name'],
            'color' => $row['color'],
        ];
    }
    return $out;
}

/** Zliczenia powiązanych rekordów (załączniki, komentarze) dla zadań. */
function counts_for_tasks(array $taskIds, string $table, string $column): array
{
    if (!$taskIds) {
        return [];
    }
    $miejsca = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = db()->prepare(
        'SELECT ' . $column . ' AS tid, COUNT(*) AS ile FROM ' . $table . '
         WHERE ' . $column . ' IN (' . $miejsca . ') GROUP BY ' . $column
    );
    $stmt->execute($taskIds);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['tid']] = (int)$row['ile'];
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
