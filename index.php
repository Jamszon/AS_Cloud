<?php
/**
 * index.php — ekran logowania oraz panel (jednostronicowa aplikacja na Alpine.js).
 *
 * Wszystkie operacje na danych idą przez api.php; ten plik odpowiada za
 * uwierzytelnienie oraz wygenerowanie interfejsu.
 */
declare(strict_types=1);

require __DIR__ . '/db.php';

/* ------------------------------------------------------------------ *
 *  Start: sesja + instalacja bazy przy pierwszym uruchomieniu
 * ------------------------------------------------------------------ */
try {
    boot_session();
    db();
} catch (SetupError $e) {
    render_setup_error($e->getMessage());
    exit;
} catch (Throwable $e) {
    $ref = '';
    try {
        $ref = log_error($e);
    } catch (Throwable $ignored) {
        // Nie da się nawet zapisać logu — pokazujemy samą informację ogólną.
    }
    render_setup_error(DEBUG
        ? 'Nieoczekiwany błąd podczas startu aplikacji: ' . $e->getMessage()
        : 'Wystąpił nieoczekiwany błąd podczas startu aplikacji'
          . ($ref !== '' ? ' (nr ' . $ref . ')' : '') . '. Szczegóły zapisano w pliku data/error.log.');
    exit;
}

/* ------------------------------------------------------------------ *
 *  Wylogowanie / logowanie
 * ------------------------------------------------------------------ */
$isPost     = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$loginError = null;
$selectedId = 0;

if ($isPost && isset($_POST['logout'])) {
    if (csrf_valid(isset($_POST['csrf']) ? (string)$_POST['csrf'] : null)) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }
    header('Location: index.php');
    exit;
}

if ($isPost && isset($_POST['login'])) {
    $selectedId = (int)($_POST['user_id'] ?? 0);
    try {
        if (!csrf_valid(isset($_POST['csrf']) ? (string)$_POST['csrf'] : null)) {
            throw new ApiError('Formularz stracił ważność. Spróbuj jeszcze raz.');
        }
        if ($selectedId <= 0) {
            throw new ApiError('Wybierz swój profil.');
        }
        if (attempt_login($selectedId, (string)($_POST['password'] ?? '')) !== null) {
            header('Location: index.php');
            exit;
        }
        $loginError = 'Nieprawidłowe hasło. Spróbuj ponownie.';
    } catch (ApiError $e) {
        $loginError = $e->getMessage();
    }
}

$me    = current_user();
$users = all_users();
$csrf  = csrf_token();

/* ------------------------------------------------------------------ *
 *  Nagłówki bezpieczeństwa
 * ------------------------------------------------------------------ */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* Panel jest jednym plikiem z całym JavaScriptem w środku. Bez tego
   przeglądarka po aktualizacji potrafi trzymać starą wersję i wymagać
   ręcznego Ctrl+F5 — a wtedy działa kod sprzed poprawek. */
header('Cache-Control: no-cache, must-revalidate, max-age=0');

/* ------------------------------------------------------------------ *
 *  Helpery widoku
 * ------------------------------------------------------------------ */

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function color_of(string $key): array
{
    $palette = COLORS;
    return array_key_exists($key, $palette)
        ? $palette[$key]
        : ['solid' => '#64748b', 'soft' => '#f1f5f9', 'ink' => '#334155', 'ring' => '#e2e8f0',
           'softDark' => '#1e293b', 'inkDark' => '#94a3b8'];
}

/** Ikony (Heroicons, wariant outline) renderowane bez zewnętrznych zależności. */
function icon(string $name, string $class = 'h-5 w-5'): string
{
    static $paths = [
        'stack'    => '<path d="M6.4 9.8 2.3 12l4.1 2.3m0-4.5 5.6 3 5.6-3m-11.2 0L2.3 7.5 12 2.3l9.7 5.2-4.1 2.3m0 0L21.8 12l-4.1 2.3m0 0 4.1 2.3-9.8 5.2-9.7-5.2 4.1-2.3m11.2 0-5.6 3-5.6-3"/>',
        'folder'   => '<path d="M2.25 12.75V12a2.25 2.25 0 0 1 2.25-2.25h15A2.25 2.25 0 0 1 21.75 12v.75m-8.7-6.4-2.1-2.2a1.5 1.5 0 0 0-1.1-.4H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.4a1.5 1.5 0 0 1-1-.4Z"/>',
        'plus'     => '<path d="M12 4.5v15m7.5-7.5h-15"/>',
        'pencil'   => '<path d="m16.9 4.5 1.7-1.7a1.9 1.9 0 1 1 2.6 2.6L10.6 16.1a4.5 4.5 0 0 1-1.9 1.1L6 18l.8-2.7a4.5 4.5 0 0 1 1.1-1.9l8.9-8.9Zm0 0 2.6 2.6"/>',
        'trash'    => '<path d="M14.7 9l-.3 9m-4.8 0L9.3 9m9.9-3.2c.4.1.7.1 1 .2m-1-.2L18.2 19.7a2.3 2.3 0 0 1-2.2 2.1H8.1a2.3 2.3 0 0 1-2.3-2.1L4.8 5.8m14.4 0a48 48 0 0 0-3.5-.4m-12 .6c.3-.1.7-.1 1-.2m0 0a48 48 0 0 1 3.5-.4m7.5 0v-.9c0-1.2-.9-2.2-2.1-2.2a52 52 0 0 0-3.3 0c-1.2 0-2.1 1-2.1 2.2v.9m7.5 0a48.7 48.7 0 0 0-7.5 0"/>',
        'check'    => '<path d="m4.5 12.8 6 6 9-13.5"/>',
        'close'    => '<path d="M6 18 18 6M6 6l12 12"/>',
        'bell'     => '<path d="M14.9 17.1a23.8 23.8 0 0 0 5.4-1.3A9 9 0 0 1 18 9.8V9A6 6 0 0 0 6 9v.8a9 9 0 0 1-2.3 6c1.7.6 3.6 1.1 5.5 1.3m5.7 0a24.3 24.3 0 0 1-5.7 0m5.7 0a3 3 0 1 1-5.7 0"/>',
        'columns'  => '<path d="M9 4.5v15m6-15v15M4.1 19.5h15.8c.6 0 1.1-.5 1.1-1.1V5.6c0-.6-.5-1.1-1.1-1.1H4.1C3.5 4.5 3 5 3 5.6v12.8c0 .6.5 1.1 1.1 1.1Z"/>',
        'note'     => '<path d="M8.3 6.8h7.5M8.3 12h7.5m-7.5 5.3h4.5M6 20.3h12A2.3 2.3 0 0 0 20.3 18V6A2.3 2.3 0 0 0 18 3.8H6A2.3 2.3 0 0 0 3.8 6v12A2.3 2.3 0 0 0 6 20.3Z"/>',
        'clip'     => '<path d="m18.4 12.7-7.7 7.7a4.5 4.5 0 0 1-6.4-6.4l10.9-10.9a3 3 0 1 1 4.3 4.3L8.6 18.3m0 0 5.7-9.9"/>',
        'unlink'   => '<path d="M13.2 10.8 21 3m-3.5 6.2 1.4-1.4a3.6 3.6 0 0 0-5.1-5.1l-1.4 1.4M3 21l7.8-7.8m-4.3 1.6-1.4 1.4a3.6 3.6 0 0 0 5.1 5.1l1.4-1.4"/>',
        'upload'   => '<path d="M3 16.5v2.3A2.3 2.3 0 0 0 5.3 21h13.5a2.3 2.3 0 0 0 2.2-2.2v-2.3M16.5 9 12 4.5 7.5 9M12 4.5V16"/>',
        'download' => '<path d="M3 16.5v2.3A2.3 2.3 0 0 0 5.3 21h13.5a2.3 2.3 0 0 0 2.2-2.2v-2.3M7.5 12l4.5 4.5m0 0 4.5-4.5m-4.5 4.5V3"/>',
        'menu'     => '<path d="M3.8 6.8h16.5M3.8 12h16.5m-16.5 5.3h16.5"/>',
        'search'   => '<path d="m21 21-5.2-5.2m0 0a7.5 7.5 0 1 0-10.6-10.6 7.5 7.5 0 0 0 10.6 10.6Z"/>',
        'logout'   => '<path d="M15.8 9V5.3A2.3 2.3 0 0 0 13.5 3h-6a2.3 2.3 0 0 0-2.3 2.3v13.5A2.3 2.3 0 0 0 7.5 21h6a2.3 2.3 0 0 0 2.3-2.3V15M12 9l-3 3m0 0 3 3m-3-3h12.8"/>',
        'clock'    => '<path d="M12 6v6h4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
        'comment'  => '<path d="M8.6 15.5a19 19 0 0 0 3.4.3c1.2 0 2.3-.1 3.4-.3a2.8 2.8 0 0 0 2.3-2.3c.1-.9.2-1.8.2-2.7 0-.9-.1-1.8-.2-2.7a2.8 2.8 0 0 0-2.3-2.3A19 19 0 0 0 12 5c-1.2 0-2.3.1-3.4.3a2.8 2.8 0 0 0-2.3 2.3c-.1.9-.2 1.8-.2 2.7 0 1.5.2 2.4.4 3.3L4.5 19l4.1-3.5Z"/>',
        'calendar' => '<path d="M6.8 3v2.3m10.4-2.3v2.3M3.8 18.7V8.3a2.3 2.3 0 0 1 2.2-2.3h12a2.3 2.3 0 0 1 2.2 2.3v10.4a2.3 2.3 0 0 1-2.2 2.3H6a2.3 2.3 0 0 1-2.2-2.3Zm0-8.4h16.4"/>',
        'lock'     => '<path d="M16.5 10.5V6.8a4.5 4.5 0 1 0-9 0v3.7m-.8 11.3h10.5a2.3 2.3 0 0 0 2.3-2.3v-6.7a2.3 2.3 0 0 0-2.3-2.3H6.8a2.3 2.3 0 0 0-2.3 2.3v6.7a2.3 2.3 0 0 0 2.3 2.3Z"/>',
        'eye'      => '<path d="M2 12.3a1 1 0 0 1 0-.6C3.4 7.5 7.4 4.5 12 4.5c4.6 0 8.6 3 10 7.2a1 1 0 0 1 0 .6c-1.4 4.2-5.4 7.2-10 7.2-4.6 0-8.6-3-10-7.2Z"/><path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
        'arrow'    => '<path d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>',
        'inbox'    => '<path d="M2.3 15h3.9a2.3 2.3 0 0 1 2 1.2l.4.6a2.3 2.3 0 0 0 2 1.2h2.8a2.3 2.3 0 0 0 2-1.2l.4-.6a2.3 2.3 0 0 1 2-1.2h3.9m-16.5 0V6.8a2.3 2.3 0 0 1 2.3-2.3h12.8a2.3 2.3 0 0 1 2.2 2.3V15m-19.4 0v3.8a2.3 2.3 0 0 0 2.3 2.2h14.8a2.3 2.3 0 0 0 2.3-2.2V15"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2m0 15v2M4.6 4.6 6 6m12 12 1.4 1.4M2.5 12h2m15 0h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
        'moon'     => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
        'grip'     => '<path d="M4 9h16M4 15h16"/>',
        'chevronL' => '<path d="m15 19-7-7 7-7"/>',
        'video'    => '<path d="M4.5 6.8h9A2.3 2.3 0 0 1 15.8 9v6a2.3 2.3 0 0 1-2.3 2.3h-9A2.3 2.3 0 0 1 2.3 15V9a2.3 2.3 0 0 1 2.2-2.2Zm11.3 3.4 5.2-3v9.6l-5.2-3"/>',
        'videoOff' => '<path d="M4.5 6.8h6.8m4.5 3.4 5.2-3v9.6l-5.2-3v1.2a2.3 2.3 0 0 1-2.3 2.3h-9A2.3 2.3 0 0 1 2.3 15V9c0-.8.4-1.5 1-1.9M3 3l18 18"/>',
        'mic'      => '<path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Zm6-3.8a6 6 0 0 1-12 0M12 18v3m-3.4 0h6.8"/>',
        'micOff'   => '<path d="M9 9.3V6a3 3 0 0 1 5.9-.7M15 12.4a3 3 0 0 1-4.5 2.2M18 11.3a6 6 0 0 1-1 3.4M6 11.3A6 6 0 0 0 12 18m0 0v3m-3.4 0h6.8M3 3l18 18"/>',
        'screen'   => '<path d="M3.8 4.5h16.4v12H3.8zM8.3 20.3h7.5M12 16.5v3.8m0-13.9v6m0-6L9.8 8.6M12 6.4l2.3 2.2"/>',
        'copy'     => '<path d="M9 12a2.3 2.3 0 0 1 2.3-2.3h6A2.3 2.3 0 0 1 19.5 12v6a2.3 2.3 0 0 1-2.2 2.3h-6A2.3 2.3 0 0 1 9 18Zm-2.3 3H5.3A2.3 2.3 0 0 1 3 12.8v-6a2.3 2.3 0 0 1 2.3-2.3h6a2.3 2.3 0 0 1 2.2 2.3V8"/>',
        'chevronR' => '<path d="m9 5 7 7-7 7"/>',
        'prioHigh' => '<path d="M12 19V5m0 0-6 6m6-6 6 6"/>',
        'prioLow'  => '<path d="M12 5v14m0 0 6-6m-6 6-6-6"/>',
        'prioMid'  => '<path d="M5 12h14"/>',
    ];

    $d = $paths[$name] ?? '';
    return '<svg class="' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

/**
 * Wiersz zadania na liście zbiorczej — używany w „Moich zadaniach”
 * i w kalendarzu. Oba widoki pokazują zadania z różnych folderów naraz,
 * więc każdy wiersz musi sam mówić, skąd pochodzi.
 *
 * Oczekuje zmiennej Alpine `t` w otaczającym zakresie (x-for).
 */
function task_row(): string
{
    ob_start(); ?>
    <div @click="otworzZListy(t)"
         class="flex cursor-pointer items-start gap-3 border-b border-line px-4 py-3 transition last:border-0 hover:bg-surface2">

        <button @click.stop="toggleDone(t)" title="Oznacz jako zrobione"
                class="mt-0.5 flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded-md border-2 transition"
                :class="t.status === 'done'
                        ? 'border-emerald-500 bg-emerald-500 text-white'
                        : 'border-linestrong text-transparent hover:border-emerald-400'">
            <?= icon('check', 'h-3 w-3') ?>
        </button>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-1.5">
                <p class="text-sm font-medium text-ink"
                   :class="t.status === 'done' ? 'line-through text-muted' : ''" x-text="t.title"></p>
                <template x-if="t.priority !== 'normal'">
                    <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase"
                          :style="priorityStyle(t.priority)">
                        <span x-html="priorityIcon(t.priority)"></span>
                        <span x-text="priorityLabel(t.priority)"></span>
                    </span>
                </template>
                <template x-if="t.due_date">
                    <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold"
                          :style="terminStyle(t.due_date, t.status)"
                          x-text="terminEtykieta(t.due_date, t.status)"></span>
                </template>
            </div>

            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-faint">
                <span class="inline-flex items-center gap-1">
                    <?= icon('folder', 'h-3 w-3') ?>
                    <span class="text-muted" x-text="t.folder_name"></span>
                </span>
                <span x-text="'· ' + kolumnaEtykieta(t.status)"></span>
                <template x-if="t.comment_count > 0">
                    <span>· <span x-text="t.comment_count"></span> kom.</span>
                </template>
                <template x-if="t.file_count > 0">
                    <span>· <span x-text="t.file_count"></span> zał.</span>
                </template>
            </p>
        </div>

        <span class="flex shrink-0 -space-x-1.5">
            <template x-for="a in t.assignees" :key="a.id">
                <span class="flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold text-white ring-2 ring-surface"
                      :style="'background:' + accent(a.color).solid"
                      :title="a.name" x-text="initials(a.name)"></span>
            </template>
        </span>
    </div>
    <?php return (string)ob_get_clean();
}

/**
 * Edytor notatki ze spotkania. Ten sam blok obsługuje okno w historii
 * spotkań i panel boczny w trakcie rozmowy, więc pisownia, autozapis
 * i obsługa kolizji są w obu miejscach identyczne.
 *
 * Oczekuje obiektu `notatka` w stanie komponentu Alpine.
 */
function meeting_note_editor(string $wysokosc = ''): string
{
    /*
     * Dwa tryby wysokości i nie wolno ich mieszać:
     *
     *  - bez podanej wysokości edytor wypełnia rodzica (panel w pokoju,
     *    który ma określoną wysokość),
     *  - z podaną wysokością to edytor narzuca rozmiar, a okno rośnie do niego.
     *
     * Wcześniej pole miało naraz „flex-1” i klasę „h-[52vh]”. W kolumnie
     * flex baza 0% z flex-1 wygrywa z wysokością, więc w oknie modalnym cały
     * edytor zwijał się do zera i notatki po prostu nie było widać.
     */
    $rozciag = $wysokosc === '' ? 'min-h-0 flex-1' : $wysokosc;
    $korzen  = $wysokosc === '' ? 'flex h-full min-h-0 flex-col' : 'flex min-h-0 flex-col';

    ob_start(); ?>
    <div class="<?= h($korzen) ?>">

        <div class="mb-2 flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1 rounded-xl bg-surface3 p-1">
                <button type="button" @click="notatka.tryb = 'edit'"
                        :class="notatka.tryb === 'edit' ? 'bg-surface text-brandink shadow-sm' : 'text-muted hover:text-ink2'"
                        class="rounded-lg px-3 py-1.5 text-xs font-medium transition">Edycja</button>
                <button type="button" @click="notatka.tryb = 'view'"
                        :class="notatka.tryb === 'view' ? 'bg-surface text-brandink shadow-sm' : 'text-muted hover:text-ink2'"
                        class="rounded-lg px-3 py-1.5 text-xs font-medium transition">Podgląd</button>
            </div>

            <!-- Stan autozapisu: bez tego nie wiadomo, czy notatka jest bezpieczna. -->
            <span class="flex items-center gap-1.5 text-[11px]"
                  :class="{
                      'text-muted': notatka.stan === 'idle' || notatka.stan === 'saved',
                      'text-amber-600 dark:text-amber-400': notatka.stan === 'dirty' || notatka.stan === 'saving',
                      'text-red-600 dark:text-red-400': notatka.stan === 'error'
                  }">
                <span x-show="notatka.stan === 'saving'" class="h-1.5 w-1.5 animate-pulse rounded-full bg-current"></span>
                <span x-text="notatkaStanTekst()"></span>
            </span>

            <span class="ml-auto text-[11px] text-faint" x-show="notatka.updated_by_name" x-cloak>
                ostatnio: <span class="text-muted" x-text="notatka.updated_by_name"></span>
            </span>
        </div>

        <!-- Kolizja: ktoś zapisał swoją wersję, kiedy my pisaliśmy swoją.
             Nie wybieramy za użytkownika, która wersja jest ważniejsza. -->
        <div x-show="notatka.konflikt" x-cloak
             class="mb-2 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950">
            <p class="text-xs font-semibold text-amber-800 dark:text-amber-300">
                Notatkę zapisał w międzyczasie
                <span x-text="notatka.konflikt && notatka.konflikt.updated_by_name"></span>.
            </p>
            <p class="mt-1 text-[11px] leading-relaxed text-amber-700 dark:text-amber-400">
                Twoja wersja nie została nadpisana — wybierz, co zrobić.
            </p>
            <div class="mt-2 flex flex-wrap gap-2">
                <button type="button" @click="wczytajWersjeSerwera()"
                        class="rounded-lg border border-amber-400 px-2.5 py-1.5 text-[11px] font-semibold text-amber-800 transition hover:bg-amber-100 dark:text-amber-300 dark:hover:bg-amber-900">
                    Wczytaj wersję z serwera
                </button>
                <button type="button" @click="zapiszNotatkeSilowo()"
                        class="rounded-lg bg-amber-600 px-2.5 py-1.5 text-[11px] font-semibold text-white transition hover:bg-amber-700">
                    Zachowaj moją
                </button>
            </div>
        </div>

        <textarea x-show="notatka.tryb === 'edit'" x-model="notatka.draft"
                  @input="notatkaZmieniona()"
                  @keydown.ctrl.s.prevent="zapiszNotatke()" @keydown.meta.s.prevent="zapiszNotatke()"
                  placeholder="Ustalenia, decyzje, zadania do rozdzielenia…&#10;&#10;Markdown działa: # nagłówek, - lista, **pogrubienie**."
                  class="thin-scroll w-full resize-none rounded-xl border-line bg-surface2 px-3 py-2.5 font-mono text-[13px] leading-relaxed text-ink placeholder:text-faint focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900 <?= h($rozciag) ?>"></textarea>

        <!-- prose-invert jest tu obowiązkowe: bez niego Tailwind Typography
             maluje tekst ciemną szarością, która na ciemnym tle znika.
             Reszta panelu radzi sobie bez klas „dark:”, ale wtyczka prose
             trzyma własne kolory poza naszymi zmiennymi. -->
        <div x-show="notatka.tryb === 'view'"
             :class="dark ? 'prose-invert' : ''"
             class="thin-scroll prose prose-sm prose-slate max-w-none overflow-y-auto rounded-xl border border-line bg-surface2 px-4 py-3 prose-headings:font-semibold prose-a:text-brand-600 dark:prose-a:text-brand-400 <?= h($rozciag) ?>"
             x-html="notatka.draft.trim() ? mdToHtml(notatka.draft) : '<p class=\'text-faint\'>Notatka jest pusta — przełącz na „Edycja”, żeby zacząć pisać.</p>'"></div>
    </div>
    <?php return (string)ob_get_clean();
}

/** Ekran awaryjny — pokazywany, gdy katalogi nie mają praw zapisu. */
function render_setup_error(string $message): void
{
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
    <html lang="pl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Konfiguracja — <?= h(APP_NAME) ?></title>
        <style>
            body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f7fd;
                 font-family:'Segoe UI',system-ui,-apple-system,sans-serif;color:#1e293b;padding:24px}
            .box{max-width:620px;background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:32px}
            h1{margin:0 0 12px;font-size:20px}
            p{line-height:1.65;color:#475569}
            code{background:#f1f5f9;border-radius:6px;padding:2px 6px;font-size:13px}
            ol{color:#475569;line-height:1.9}
        </style>
    </head>
    <body>
    <div class="box">
        <h1>Aplikacja nie może wystartować</h1>
        <p><?= h($message) ?></p>
        <p>Jak to naprawić przez WinSCP / FileZillę:</p>
        <ol>
            <li>Upewnij się, że obok <code>index.php</code> istnieją katalogi <code>data</code> i <code>uploads</code>.</li>
            <li>Kliknij katalog prawym przyciskiem → <em>Właściwości / Prawa dostępu</em>.</li>
            <li>Ustaw <code>755</code>. Jeśli błąd nie zniknie, ustaw <code>777</code>.</li>
            <li>Odśwież tę stronę.</li>
        </ol>
    </div>
    </body>
    </html><?php
}
?>
<!DOCTYPE html>
<html lang="pl" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h(APP_NAME) ?></title>

    <script>
        /* Motyw ustawiamy przed pierwszym malowaniem strony, żeby przy odświeżeniu
           nie mignął jasny ekran. Wybór trzymamy w localStorage przeglądarki. */
        (function () {
            try {
                var zapis = localStorage.getItem('panel.motyw');
                var ciemny = zapis
                    ? zapis === 'dark'
                    : window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (ciemny) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) { /* prywatny tryb przeglądarki — zostaje motyw jasny */ }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* Cała paleta panelu siedzi w tych zmiennych. Tryb ciemny to ich podmiana —
           dzięki temu żaden element nie potrzebuje osobnych klas dla ciemnego motywu. */
        :root {
            --page:        244 247 253;
            --surface:     255 255 255;
            --surface2:    248 250 252;
            --surface3:    241 245 249;
            --line:        226 232 240;
            --linestrong:  203 213 225;
            --ink:          15  23  42;
            --ink2:         71  85 105;
            --muted:       100 116 139;
            --faint:       122 138 161;   /* ciemniejszy od slate-400: drobne podpisy muszą być czytelne */
            --brandsoft:   238 242 255;
            --brandink:     67  56 202;
            --cien:         30  41  59;
            --pasek:       203 213 225;
        }
        html.dark {
            --page:         15  23  42;
            --surface:      30  41  59;
            --surface2:     15  23  42;
            --surface3:     51  65  85;
            --line:         51  65  85;
            --linestrong:   71  85 105;
            --ink:         241 245 249;
            --ink2:        203 213 225;
            --muted:       148 163 184;
            --faint:       100 116 139;
            --brandsoft:    49  46 129;
            --brandink:    165 180 252;
            --cien:          0   0   0;
            --pasek:        71  85 105;
        }

        [x-cloak] { display: none !important; }

        body { font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif; }

        /* Na czas przełączania motywu gasimy wszystkie przejścia CSS. Bez tego
           elementy z klasą "transition" animują zmianę koloru i potrafią zostać
           w barwach poprzedniego motywu. Przy okazji przełączenie jest natychmiastowe. */
        html.motyw-zmiana *,
        html.motyw-zmiana *::before,
        html.motyw-zmiana *::after {
            transition: none !important;
        }

        .auth-bg {
            background:
                radial-gradient(900px 460px at 50% -12%, rgb(var(--brandsoft)) 0%, rgb(var(--page) / 0) 62%),
                rgb(var(--page));
        }

        /* Kafelki profili na ekranie logowania — bez JavaScriptu. */
        .profile input:checked + .tile {
            border-color: var(--acc);
            background: var(--soft);
            box-shadow: 0 0 0 3px var(--ring);
        }
        .profile input:checked + .tile .tick { opacity: 1; transform: scale(1); }
        .profile input:focus-visible + .tile { outline: 2px solid var(--acc); outline-offset: 2px; }

        .clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        .thin-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
        .thin-scroll::-webkit-scrollbar-thumb { background: rgb(var(--pasek)); border-radius: 99px; }
        .thin-scroll::-webkit-scrollbar-track { background: transparent; }
        .thin-scroll { scrollbar-width: thin; scrollbar-color: rgb(var(--pasek)) transparent; }

        .drag-ghost { opacity: .35; }

        body.ready #boot { display: none; }
        body:not(.ready) #panel { visibility: hidden; }
    </style>

    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif']
                    },
                    colors: {
                        brand: {
                            50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc', 400: '#818cf8',
                            500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 800: '#3730a3', 900: '#312e81'
                        },
                        page:       'rgb(var(--page) / <alpha-value>)',
                        surface:    'rgb(var(--surface) / <alpha-value>)',
                        surface2:   'rgb(var(--surface2) / <alpha-value>)',
                        surface3:   'rgb(var(--surface3) / <alpha-value>)',
                        line:       'rgb(var(--line) / <alpha-value>)',
                        linestrong: 'rgb(var(--linestrong) / <alpha-value>)',
                        ink:        'rgb(var(--ink) / <alpha-value>)',
                        ink2:       'rgb(var(--ink2) / <alpha-value>)',
                        muted:      'rgb(var(--muted) / <alpha-value>)',
                        faint:      'rgb(var(--faint) / <alpha-value>)',
                        brandsoft:  'rgb(var(--brandsoft) / <alpha-value>)',
                        brandink:   'rgb(var(--brandink) / <alpha-value>)'
                    },
                    boxShadow: {
                        card: '0 1px 2px rgb(var(--cien) / .04), 0 12px 32px -20px rgb(var(--cien) / .45)',
                        lift: '0 2px 8px rgb(var(--cien) / .06), 0 20px 44px -24px rgb(var(--cien) / .5)'
                    }
                }
            }
        };
    </script>
</head>

<?php if ($me === null): /* ================= EKRAN LOGOWANIA ================= */ ?>
<body class="auth-bg min-h-full text-ink antialiased">

<button type="button" id="motyw"
        class="fixed right-4 top-4 rounded-xl border border-line bg-surface p-2.5 text-muted transition hover:text-brand-600"
        title="Tryb jasny / ciemny" aria-label="Przełącz tryb jasny i ciemny">
    <span class="hidden dark:block"><?= icon('sun', 'h-[18px] w-[18px]') ?></span>
    <span class="block dark:hidden"><?= icon('moon', 'h-[18px] w-[18px]') ?></span>
</button>

<main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-5 py-10">

    <div class="mb-7 flex flex-col items-center text-center">
        <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-600 text-white shadow-lift">
            <?= icon('stack', 'h-7 w-7') ?>
        </div>
        <h1 class="text-2xl font-semibold tracking-tight text-ink"><?= h(APP_NAME) ?></h1>
        <p class="mt-1 text-sm text-muted">Zadania, notatki i pliki dla czteroosobowego zespołu</p>
    </div>

    <form method="post" action="index.php" class="rounded-3xl border border-line bg-surface p-6 shadow-card sm:p-7">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="login" value="1">

        <?php if ($loginError !== null): ?>
            <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
                <?= icon('close', 'mt-0.5 h-4 w-4 shrink-0') ?>
                <span><?= h($loginError) ?></span>
            </div>
        <?php endif; ?>

        <fieldset>
            <legend class="mb-3 block text-xs font-semibold uppercase tracking-wider text-faint">Wybierz profil</legend>
            <div class="grid grid-cols-2 gap-2.5">
                <?php foreach ($users as $index => $u):
                    $c = color_of((string)$u['color']);
                    $checked = $selectedId > 0 ? ($selectedId === (int)$u['id']) : ($index === 0);
                    ?>
                    <label class="profile block cursor-pointer"
                           style="--acc: <?= h($c['solid']) ?>; --soft: <?= h($c['soft']) ?>; --ring: <?= h($c['ring']) ?>;">
                        <input type="radio" name="user_id" value="<?= (int)$u['id'] ?>" class="sr-only"
                               required <?= $checked ? 'checked' : '' ?>>
                        <span class="tile relative flex items-center gap-3 rounded-2xl border-2 border-line bg-surface px-3 py-3 transition duration-150 hover:border-linestrong">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white"
                                  style="background: <?= h($c['solid']) ?>;">
                                <?= h(mb_substr((string)$u['name'], 0, 1)) ?>
                            </span>
                            <span class="text-sm font-medium text-ink2"><?= h($u['name']) ?></span>
                            <span class="tick absolute right-2.5 top-2.5 flex h-4 w-4 scale-75 items-center justify-center rounded-full text-white opacity-0 transition"
                                  style="background: <?= h($c['solid']) ?>;">
                                <?= icon('check', 'h-2.5 w-2.5') ?>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="mt-5">
            <label for="password" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Hasło</label>
            <div class="relative">
                <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-faint">
                    <?= icon('lock', 'h-[18px] w-[18px]') ?>
                </span>
                <input id="password" name="password" type="password" required autofocus autocomplete="current-password"
                       placeholder="••••••••"
                       class="w-full rounded-xl border-line bg-surface2 py-2.5 pl-11 pr-4 text-sm text-ink placeholder:text-faint focus:border-brand-500 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900">
            </div>
        </div>

        <button type="submit"
                class="mt-5 flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-3 text-sm font-semibold text-white shadow-lift transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-100 active:scale-[.99] dark:focus:ring-brand-900">
            Zaloguj się
            <?= icon('arrow', 'h-4 w-4') ?>
        </button>
    </form>

    <p class="mt-5 text-center text-xs leading-relaxed text-faint">
        Hasło startowe: <code class="rounded bg-surface px-1.5 py-0.5 font-mono text-muted"><?= h(DEFAULT_PASSWORD) ?></code><br>
        Zmienisz je w pliku <code class="font-mono">db.php</code> (patrz README.md).
    </p>
</main>

<script>
    document.getElementById('motyw').addEventListener('click', function () {
        var root = document.documentElement;
        root.classList.add('motyw-zmiana');
        var ciemny = root.classList.toggle('dark');
        void root.offsetHeight;
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { root.classList.remove('motyw-zmiana'); });
        });
        try { localStorage.setItem('panel.motyw', ciemny ? 'dark' : 'light'); } catch (e) {}
    });
</script>

</body>

<?php else: /* ==================== PANEL ==================== */ ?>
<body class="h-full overflow-hidden bg-page text-ink antialiased">

<div id="boot" class="fixed inset-0 z-[60] grid place-items-center bg-page">
    <div class="flex flex-col items-center gap-3">
        <div class="h-9 w-9 animate-spin rounded-full border-[3px] border-brand-200 border-t-brand-600"></div>
        <p class="text-sm text-faint">Wczytywanie panelu…</p>
    </div>
</div>

<noscript>
    <div class="p-6 text-center text-sm text-red-700">Panel wymaga włączonej obsługi JavaScriptu.</div>
</noscript>

<!-- init() uruchamia Alpine automatycznie — nie dodawaj tu x-init, bo wykona się dwa razy. -->
<div id="panel" class="flex h-full" x-data="panel()" @keydown.escape.window="closeTop()">

    <!-- Przyciemnienie pod bocznym panelem (widok mobilny) -->
    <div x-show="sidebarOpen" x-cloak x-transition.opacity @click="sidebarOpen = false"
         class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"></div>

    <!-- ============================ SIDEBAR ============================ -->
    <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="fixed inset-y-0 left-0 z-40 flex w-72 shrink-0 flex-col border-r border-line bg-surface transition-transform duration-200 ease-out lg:static lg:translate-x-0">

        <div class="flex items-center gap-3 px-5 pb-4 pt-5">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 text-white shadow-lift">
                <?= icon('stack', 'h-5 w-5') ?>
            </div>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-ink"><?= h(APP_NAME) ?></p>
                <p class="text-xs text-faint"><span x-text="users.length"></span> osoby w zespole</p>
            </div>
            <button @click="sidebarOpen = false" class="ml-auto rounded-lg p-1.5 text-muted hover:bg-surface3 lg:hidden" aria-label="Zamknij menu">
                <?= icon('close', 'h-5 w-5') ?>
            </button>
        </div>

        <!-- Dodawanie folderu na samej górze, żeby było od razu widoczne -->
        <div class="px-4 pb-3">
            <button @click="newFolder()"
                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-3 py-2.5 text-sm font-semibold text-white shadow-lift transition hover:bg-brand-700">
                <?= icon('plus', 'h-4 w-4') ?>
                Nowy folder
            </button>
        </div>

        <!-- Zadania przypisane do mnie ze wszystkich folderów naraz -->
        <div class="px-4 pb-3">
            <button @click="pokazMoje()"
                    :class="view === 'mine' ? 'bg-brandsoft text-brandink ring-1 ring-brand-200 dark:ring-brand-800' : 'text-ink2 hover:bg-surface3'"
                    class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left transition">
                <span :class="view === 'mine' ? 'text-brand-500' : 'text-faint'">
                    <?= icon('users', 'h-[18px] w-[18px]') ?>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium">Moje zadania</span>
                    <span class="block text-[11px] text-faint">Ze wszystkich folderów</span>
                </span>
                <span x-show="mineCount > 0" x-cloak
                      :class="mineOverdue > 0 ? 'bg-red-500' : 'bg-brand-600'"
                      class="rounded-full px-2 py-0.5 text-[11px] font-semibold text-white"
                      x-text="mineCount"></span>
            </button>

            <button @click="pokazKalendarz()"
                    :class="view === 'calendar' ? 'bg-brandsoft text-brandink ring-1 ring-brand-200 dark:ring-brand-800' : 'text-ink2 hover:bg-surface3'"
                    class="mt-1 flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left transition">
                <span :class="view === 'calendar' ? 'text-brand-500' : 'text-faint'">
                    <?= icon('calendar', 'h-[18px] w-[18px]') ?>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium">Kalendarz</span>
                    <span class="block text-[11px] text-faint">Terminy w układzie miesiąca</span>
                </span>
            </button>

            <button @click="pokazSpotkania()"
                    :class="view === 'meetings' ? 'bg-brandsoft text-brandink ring-1 ring-brand-200 dark:ring-brand-800' : 'text-ink2 hover:bg-surface3'"
                    class="mt-1 flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left transition">
                <span :class="view === 'meetings' ? 'text-brand-500' : 'text-faint'">
                    <?= icon('video', 'h-[18px] w-[18px]') ?>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium">Spotkania</span>
                    <span class="block text-[11px] text-faint">Wideorozmowy i notatki</span>
                </span>

                <!-- Trwająca rozmowa musi rzucać się w oczy z każdego widoku. -->
                <span x-show="spotkaniaTrwajace.length" x-cloak
                      class="flex items-center gap-1 rounded-full bg-emerald-500 px-2 py-0.5 text-[11px] font-semibold text-white">
                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-white"></span>
                    <span x-text="spotkaniaTrwajace.length"></span>
                </span>
                <span x-show="!spotkaniaTrwajace.length && spotkaniaNadchodzace.length" x-cloak
                      class="rounded-full bg-surface3 px-2 py-0.5 text-[11px] font-semibold text-muted"
                      x-text="spotkaniaNadchodzace.length"></span>
            </button>
        </div>

        <div class="px-4 pb-3">
            <div class="relative">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint">
                    <?= icon('search', 'h-4 w-4') ?>
                </span>
                <input x-model="folderQuery" type="search" placeholder="Szukaj folderu…"
                       class="w-full rounded-xl border-line bg-surface2 py-2 pl-9 pr-3 text-sm text-ink placeholder:text-faint focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900">
            </div>
        </div>

        <div class="flex items-center justify-between px-5 pb-1.5">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-faint">Foldery</span>
            <span class="text-[11px] text-faint" x-text="visibleFolders.length"></span>
        </div>

        <nav class="thin-scroll min-h-0 flex-1 space-y-0.5 overflow-y-auto px-3 pb-2">
            <template x-for="f in visibleFolders" :key="f.id">
                <div class="group relative"
                     :draggable="folderQuery === ''"
                     @dragstart="startFolderDrag($event, f.id)"
                     @dragover.prevent="folderDragOver = f.id"
                     @dragleave="if (folderDragOver === f.id) folderDragOver = null"
                     @drop.prevent="dropFolder(f.id)"
                     @dragend="folderDrag = null; folderDragOver = null"
                     :class="folderDrag === f.id ? 'drag-ghost' : ''">

                    <button @click="selectFolder(f.id)"
                            :class="[
                                view === 'folder' && current && current.id === f.id ? 'bg-brandsoft text-brandink' : 'text-ink2 hover:bg-surface3',
                                folderDragOver === f.id && folderDrag !== f.id ? 'ring-2 ring-brand-400' : ''
                            ]"
                            :title="folderQuery === '' ? 'Przeciągnij, aby zmienić kolejność' : ''"
                            class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2.5 pr-16 text-left transition">
                        <span :class="view === 'folder' && current && current.id === f.id ? 'text-brand-500' : 'text-faint'">
                            <?= icon('folder', 'h-[18px] w-[18px]') ?>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium" x-text="f.name"></span>
                            <span class="block text-[11px] text-faint">
                                <span x-text="f.done_count"></span>/<span x-text="f.task_count"></span> zadań
                                <template x-if="f.file_count > 0">
                                    <span> · <span x-text="f.file_count"></span> plik(ów)</span>
                                </template>
                            </span>
                        </span>
                    </button>

                    <div class="absolute right-2 top-2.5 flex items-center gap-0.5 opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100 max-lg:opacity-100">
                        <span x-show="folderQuery === ''" class="cursor-grab pr-0.5 text-faint" title="Przeciągnij, aby zmienić kolejność">
                            <?= icon('grip', 'h-3.5 w-3.5') ?>
                        </span>
                        <button @click.stop="renameFolder(f)" title="Zmień nazwę"
                                class="rounded-lg p-1.5 text-faint hover:bg-surface hover:text-brand-600">
                            <?= icon('pencil', 'h-3.5 w-3.5') ?>
                        </button>
                        <button @click.stop="deleteFolder(f)" title="Usuń folder"
                                class="rounded-lg p-1.5 text-faint hover:bg-surface hover:text-red-600">
                            <?= icon('trash', 'h-3.5 w-3.5') ?>
                        </button>
                    </div>
                </div>
            </template>

            <p x-show="!visibleFolders.length && folderQuery" class="px-3 py-6 text-center text-xs text-faint">
                Brak folderów pasujących do wyszukiwania.
            </p>
            <p x-show="!folders.length && !folderQuery" class="px-3 py-6 text-center text-xs text-faint">
                Nie ma jeszcze żadnego folderu.
            </p>
        </nav>

        <div class="border-t border-line p-3">
            <div class="flex items-center gap-3 rounded-xl bg-surface2 px-3 py-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white"
                      :style="'background:' + accent(me.color).solid" x-text="initials(me.name)"></span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-ink2" x-text="me.name"></p>
                    <p class="text-[11px] text-faint">Zalogowany</p>
                </div>
                <form method="post" action="index.php">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="logout" value="1">
                    <button type="submit" title="Wyloguj się"
                            class="rounded-lg p-2 text-faint transition hover:bg-surface hover:text-red-600">
                        <?= icon('logout', 'h-[18px] w-[18px]') ?>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <!-- ========================= KOLUMNA GŁÓWNA ========================= -->
    <div class="flex min-w-0 flex-1 flex-col">

        <header class="z-20 border-b border-line bg-surface/90 backdrop-blur">
            <div class="flex items-center gap-3 px-4 py-3 sm:px-6">
                <button @click="sidebarOpen = true" class="rounded-lg p-2 text-muted hover:bg-surface3 lg:hidden" aria-label="Menu">
                    <?= icon('menu') ?>
                </button>

                <div class="min-w-0 flex-1">
                    <template x-if="view === 'mine'">
                        <div>
                            <h1 class="truncate text-lg font-semibold tracking-tight text-ink">Moje zadania</h1>
                            <p class="truncate text-xs text-faint">
                                <span x-text="odmianaZadan(mineCount)"></span> do zrobienia
                                <template x-if="mineOverdue > 0">
                                    <span class="font-semibold text-red-600 dark:text-red-400">
                                        · <span x-text="mineOverdue"></span> po terminie
                                    </span>
                                </template>
                            </p>
                        </div>
                    </template>
                    <template x-if="view === 'meetings'">
                        <div>
                            <h1 class="truncate text-lg font-semibold tracking-tight text-ink">Spotkania</h1>
                            <p class="truncate text-xs text-faint">
                                <template x-if="spotkaniaTrwajace.length">
                                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">
                                        <span x-text="spotkaniaTrwajace.length === 1 ? 'Trwa rozmowa' : spotkaniaTrwajace.length + ' trwające rozmowy'"></span> ·
                                    </span>
                                </template>
                                <span x-text="odmianaSpotkan(spotkaniaNadchodzace.length)"></span> w planie
                            </p>
                        </div>
                    </template>
                    <template x-if="view === 'calendar'">
                        <div>
                            <h1 class="truncate text-lg font-semibold tracking-tight text-ink">Kalendarz</h1>
                            <p class="truncate text-xs text-faint">
                                <span x-text="odmianaZadan(kalWidoczne.length)"></span> z terminem w tym miesiącu
                                <template x-if="kalPoTerminie > 0">
                                    <span class="font-semibold text-red-600 dark:text-red-400">
                                        · <span x-text="kalPoTerminie"></span> po terminie
                                    </span>
                                </template>
                            </p>
                        </div>
                    </template>
                    <template x-if="view === 'folder' && current">
                        <div class="flex items-center gap-1.5">
                            <h1 class="truncate text-lg font-semibold tracking-tight text-ink" x-text="current.name"></h1>
                            <button @click="renameFolder(current)" title="Zmień nazwę folderu"
                                    class="shrink-0 rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-brand-600">
                                <?= icon('pencil', 'h-4 w-4') ?>
                            </button>
                            <button @click="deleteFolder(current)" title="Usuń folder"
                                    class="shrink-0 rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-red-600">
                                <?= icon('trash', 'h-4 w-4') ?>
                            </button>
                        </div>
                    </template>
                    <template x-if="view === 'folder' && !current">
                        <h1 class="text-lg font-semibold tracking-tight text-ink">Panel</h1>
                    </template>
                    <p x-show="view === 'folder' && current" class="truncate text-xs text-faint">
                        Utworzył: <span x-text="current && current.created_by_name"></span>,
                        <span x-text="current && fmtFull(current.created_at)"></span>
                    </p>
                </div>

                <div class="hidden items-center -space-x-1.5 sm:flex" title="Zespół">
                    <template x-for="u in users" :key="u.id">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold text-white ring-2 ring-surface"
                              :style="'background:' + accent(u.color).solid" :title="u.name" x-text="initials(u.name)"></span>
                    </template>
                </div>

                <button @click="toggleTheme()"
                        class="rounded-xl border border-line bg-surface p-2.5 text-muted transition hover:border-brand-300 hover:text-brand-600"
                        :title="dark ? 'Przełącz na tryb jasny' : 'Przełącz na tryb ciemny'"
                        aria-label="Przełącz tryb jasny i ciemny">
                    <span x-show="dark"><?= icon('sun', 'h-[18px] w-[18px]') ?></span>
                    <span x-show="!dark"><?= icon('moon', 'h-[18px] w-[18px]') ?></span>
                </button>

                <button @click="feedOpen = true"
                        class="relative rounded-xl border border-line bg-surface p-2.5 text-muted transition hover:border-brand-300 hover:text-brand-600"
                        title="Dziennik zmian">
                    <?= icon('bell', 'h-[18px] w-[18px]') ?>
                    <span x-show="unseen > 0" x-cloak
                          class="absolute -right-1 -top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-semibold text-white"
                          x-text="unseen > 9 ? '9+' : unseen"></span>
                </button>
            </div>

            <div x-show="view === 'folder' && current" class="flex items-center gap-1 overflow-x-auto px-4 pb-3 sm:px-6">
                <div class="flex items-center gap-1 rounded-xl bg-surface3 p-1">
                    <button @click="tab = 'board'"
                            :class="tab === 'board' ? 'bg-surface text-brandink shadow-sm' : 'text-muted hover:text-ink2'"
                            class="flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition">
                        <?= icon('columns', 'h-4 w-4') ?> Tablica
                        <span class="rounded-md bg-surface2 px-1.5 text-[11px] font-semibold text-muted" x-text="tasks.length"></span>
                    </button>
                    <button @click="tab = 'note'"
                            :class="tab === 'note' ? 'bg-surface text-brandink shadow-sm' : 'text-muted hover:text-ink2'"
                            class="flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition">
                        <?= icon('note', 'h-4 w-4') ?> Notatka
                        <span x-show="noteDirty" class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>
                    </button>
                    <button @click="tab = 'files'"
                            :class="tab === 'files' ? 'bg-surface text-brandink shadow-sm' : 'text-muted hover:text-ink2'"
                            class="flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition">
                        <?= icon('clip', 'h-4 w-4') ?> Pliki
                        <span class="rounded-md bg-surface2 px-1.5 text-[11px] font-semibold text-muted" x-text="files.length"></span>
                    </button>
                </div>

                <!-- ---- Filtry tablicy ---- -->
                <div x-show="tab === 'board'" class="ml-2 flex items-center gap-2 border-l border-line pl-3">
                    <div class="relative">
                        <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-faint">
                            <?= icon('search', 'h-3.5 w-3.5') ?>
                        </span>
                        <input x-model="filtr.tekst" type="search" placeholder="Szukaj w zadaniach…"
                               class="w-44 rounded-lg border-line bg-surface2 py-1.5 pl-8 pr-2 text-xs text-ink placeholder:text-faint focus:border-brand-400 focus:ring-1 focus:ring-brand-200 dark:focus:ring-brand-800">
                    </div>

                    <div class="flex items-center gap-1" title="Filtruj po osobie">
                        <template x-for="u in users" :key="u.id">
                            <button @click="przelaczFiltrOsoby(u.id)"
                                    :title="u.name"
                                    :class="filtr.osoby.includes(u.id) ? 'ring-2 ring-offset-1 ring-brand-400 ring-offset-surface' : 'opacity-40 hover:opacity-100'"
                                    class="flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold text-white transition"
                                    :style="'background:' + accent(u.color).solid"
                                    x-text="initials(u.name)"></button>
                        </template>
                    </div>

                    <div class="flex items-center gap-1">
                        <template x-for="p in priorities" :key="p.key">
                            <button @click="przelaczFiltrPriorytetu(p.key)"
                                    :title="'Priorytet: ' + p.label"
                                    :class="filtr.priorytety.includes(p.key) ? 'ring-2 ring-brand-400' : 'opacity-40 hover:opacity-100'"
                                    :style="priorityStyle(p.key)"
                                    class="rounded-md px-1.5 py-1 text-[10px] font-bold uppercase transition"
                                    x-text="p.label.slice(0, 3)"></button>
                        </template>
                    </div>

                    <button @click="filtr.tylkoTerminy = !filtr.tylkoTerminy"
                            title="Pokaż tylko zadania z terminem"
                            :class="filtr.tylkoTerminy ? 'border-brand-400 bg-brandsoft text-brandink' : 'border-line text-muted hover:border-linestrong'"
                            class="rounded-lg border px-2 py-1 text-[10px] font-semibold uppercase transition">
                        Termin
                    </button>

                    <button x-show="filtrAktywny" x-cloak @click="wyczyscFiltry()"
                            title="Wyczyść filtry"
                            class="rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-red-600">
                        <?= icon('close', 'h-3.5 w-3.5') ?>
                    </button>
                </div>
            </div>

            <!-- ---- Sterowanie spotkaniami ---- -->
            <div x-show="view === 'meetings'" x-cloak class="flex flex-wrap items-center gap-2 px-4 pb-3 sm:px-6">
                <button @click="nowSpotkanie()"
                        class="flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white shadow-lift transition hover:bg-brand-700">
                    <?= icon('plus', 'h-4 w-4') ?> Umów spotkanie
                </button>

                <button @click="spotkaniaArchiwum = !spotkaniaArchiwum"
                        :class="spotkaniaArchiwum ? 'border-brand-400 bg-brandsoft text-brandink' : 'border-line text-muted hover:border-linestrong'"
                        class="rounded-xl border px-3 py-2 text-xs font-semibold transition">
                    Archiwum
                    <span class="ml-1 opacity-70" x-text="'(' + spotkaniaZakonczone.length + ')'"></span>
                </button>

                <span x-show="!bezpiecznyKontekst" x-cloak
                      class="flex items-center gap-1.5 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-[11px] font-medium text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300">
                    <?= icon('lock', 'h-3.5 w-3.5') ?>
                    Bez HTTPS przeglądarka nie udostępni kamery ani mikrofonu.
                </span>
            </div>

            <!-- ---- Sterowanie kalendarzem ---- -->
            <div x-show="view === 'calendar'" x-cloak class="flex flex-wrap items-center gap-2 px-4 pb-3 sm:px-6">
                <div class="flex items-center gap-1 rounded-xl bg-surface3 p-1">
                    <button @click="kalPrzesun(-1)" title="Poprzedni miesiąc" aria-label="Poprzedni miesiąc"
                            class="rounded-lg p-1.5 text-muted transition hover:bg-surface hover:text-brandink">
                        <?= icon('chevronL', 'h-4 w-4') ?>
                    </button>
                    <span class="min-w-[9rem] px-1 text-center text-sm font-semibold text-ink" x-text="kalTytul"></span>
                    <button @click="kalPrzesun(1)" title="Następny miesiąc" aria-label="Następny miesiąc"
                            class="rounded-lg p-1.5 text-muted transition hover:bg-surface hover:text-brandink">
                        <?= icon('chevronR', 'h-4 w-4') ?>
                    </button>
                </div>

                <button @click="kalDzis()"
                        class="rounded-xl border border-line px-3 py-1.5 text-xs font-semibold text-muted transition hover:border-brand-300 hover:text-brand-600">
                    Dziś
                </button>

                <div class="flex items-center gap-2 border-l border-line pl-3">
                    <button @click="kal.tylkoMoje = !kal.tylkoMoje"
                            :class="kal.tylkoMoje ? 'border-brand-400 bg-brandsoft text-brandink' : 'border-line text-muted hover:border-linestrong'"
                            class="rounded-lg border px-2 py-1 text-[10px] font-semibold uppercase transition">
                        Tylko moje
                    </button>
                    <button @click="kal.ukryjZrobione = !kal.ukryjZrobione"
                            :class="kal.ukryjZrobione ? 'border-brand-400 bg-brandsoft text-brandink' : 'border-line text-muted hover:border-linestrong'"
                            class="rounded-lg border px-2 py-1 text-[10px] font-semibold uppercase transition">
                        Ukryj zrobione
                    </button>
                </div>

                <span x-show="kal.ladowanie" x-cloak class="text-[11px] text-faint">Wczytywanie…</span>
            </div>
        </header>

        <main class="min-h-0 flex-1 overflow-hidden">

            <!-- ---------- Stan pusty ---------- -->
            <div x-show="view === 'folder' && !current" x-cloak class="grid h-full place-items-center p-6">
                <div class="max-w-sm text-center">
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-brandsoft text-brand-500">
                        <?= icon('inbox', 'h-8 w-8') ?>
                    </div>
                    <h2 class="text-base font-semibold text-ink">Wybierz folder z listy</h2>
                    <p class="mt-1.5 text-sm leading-relaxed text-muted">
                        Folder to jeden projekt: ma własną tablicę zadań, wspólną notatkę i załączniki.
                    </p>
                    <button @click="newFolder()"
                            class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lift transition hover:bg-brand-700">
                        <?= icon('plus', 'h-4 w-4') ?> Utwórz folder
                    </button>
                </div>
            </div>

            <!-- ---------- Moje zadania (wszystkie foldery) ---------- -->
            <div x-show="view === 'mine'" x-cloak class="thin-scroll h-full overflow-y-auto p-4 sm:p-6">
                <div class="mx-auto max-w-3xl space-y-3">

                    <div x-show="!mineTasks.length" class="rounded-2xl border border-line bg-surface p-10 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brandsoft text-brand-500">
                            <?= icon('check', 'h-7 w-7') ?>
                        </div>
                        <h2 class="text-base font-semibold text-ink">Nic na Ciebie nie czeka</h2>
                        <p class="mt-1.5 text-sm text-muted">
                            Nie masz otwartych zadań przypisanych do siebie. Zrobione znikają z tej listy.
                        </p>
                    </div>

                    <template x-for="grupa in mineGrouped" :key="grupa.klucz">
                        <section>
                            <h2 class="mb-2 flex items-center gap-2 px-1 text-xs font-semibold uppercase tracking-wider"
                                :class="grupa.pilne ? 'text-red-600 dark:text-red-400' : 'text-faint'">
                                <span x-text="grupa.etykieta"></span>
                                <span class="rounded-md bg-surface3 px-1.5 text-[11px] font-semibold text-muted"
                                      x-text="grupa.zadania.length"></span>
                            </h2>

                            <div class="overflow-hidden rounded-2xl border border-line bg-surface shadow-card">
                                <template x-for="t in grupa.zadania" :key="t.id">
                                    <?= task_row() ?>
                                                                    </template>
                            </div>
                        </section>
                    </template>
                </div>
            </div>

            <!-- ---------- Spotkania wideo ---------- -->
            <div x-show="view === 'meetings'" x-cloak class="thin-scroll h-full overflow-y-auto p-4 sm:p-6">
                <div class="mx-auto max-w-4xl space-y-5">

                    <div x-show="!spotkania.length" class="rounded-2xl border border-line bg-surface p-10 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brandsoft text-brand-500">
                            <?= icon('video', 'h-7 w-7') ?>
                        </div>
                        <h2 class="text-base font-semibold text-ink">Nie ma jeszcze żadnego spotkania</h2>
                        <p class="mx-auto mt-1.5 max-w-md text-sm leading-relaxed text-muted">
                            Umów rozmowę, a panel wygeneruje pokój wideo z własnym linkiem i miejscem
                            na wspólną notatkę. Rozmowa idzie bezpośrednio między przeglądarkami.
                        </p>
                        <button @click="nowSpotkanie()"
                                class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lift transition hover:bg-brand-700">
                            <?= icon('plus', 'h-4 w-4') ?> Umów spotkanie
                        </button>
                    </div>

                    <template x-for="grupa in spotkaniaWidoczne" :key="grupa.klucz">
                        <section class="space-y-3">
                            <h2 class="flex items-center gap-2 px-1 text-xs font-semibold uppercase tracking-wider"
                                :class="grupa.klucz === 'live' ? 'text-emerald-600 dark:text-emerald-400' : 'text-faint'">
                                <span x-text="grupa.etykieta"></span>
                                <span class="rounded-md bg-surface3 px-1.5 text-[11px] font-semibold text-muted"
                                      x-text="grupa.spotkania.length"></span>
                            </h2>

                        <template x-for="m in grupa.spotkania" :key="m.id">
                            <article class="rounded-2xl border bg-surface p-4 shadow-card transition"
                                     :class="m.status === 'live'
                                             ? 'border-emerald-400 ring-1 ring-emerald-200 dark:ring-emerald-900'
                                             : 'border-line'">

                                <div class="flex flex-wrap items-start gap-x-3 gap-y-2">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-sm font-semibold text-ink" x-text="m.title"></h3>
                                            <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase"
                                                  :style="statusSpotkaniaStyl(m.status)">
                                                <span x-show="m.status === 'live'" class="h-1.5 w-1.5 animate-pulse rounded-full bg-current"></span>
                                                <span x-text="statusSpotkaniaNazwa(m.status)"></span>
                                            </span>
                                            <template x-if="m.folder_name">
                                                <span class="inline-flex items-center gap-1 text-[11px] text-faint">
                                                    <?= icon('folder', 'h-3 w-3') ?>
                                                    <span x-text="m.folder_name"></span>
                                                </span>
                                            </template>
                                        </div>

                                        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-faint">
                                            <span class="inline-flex items-center gap-1 font-medium text-muted">
                                                <?= icon('calendar', 'h-3 w-3') ?>
                                                <span x-text="terminSpotkania(m)"></span>
                                            </span>
                                            <span x-text="'· ' + m.duration_min + ' min'"></span>
                                            <template x-if="m.in_room > 0">
                                                <span class="font-semibold text-emerald-600 dark:text-emerald-400">
                                                    · <span x-text="m.in_room"></span> w pokoju
                                                </span>
                                            </template>
                                            <template x-if="m.has_note">
                                                <span>· notatka jest</span>
                                            </template>
                                        </p>

                                        <p x-show="m.description" x-cloak
                                           class="mt-2 line-clamp-2 text-xs leading-relaxed text-muted" x-text="m.description"></p>
                                    </div>

                                    <span class="flex shrink-0 -space-x-1.5" title="Uczestnicy">
                                        <template x-for="u in m.participants.filter(x => x.user_id)" :key="u.user_id">
                                            <span class="flex h-7 w-7 items-center justify-center rounded-full text-[10px] font-bold text-white ring-2 ring-surface"
                                                  :style="'background:' + accent(u.color).solid"
                                                  :title="u.name + (u.role === 'host' ? ' (prowadzi)' : '')"
                                                  x-text="initials(u.name)"></span>
                                        </template>
                                        <template x-if="m.participants.filter(x => x.email).length">
                                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-surface3 text-[10px] font-bold text-muted ring-2 ring-surface"
                                                  :title="m.participants.filter(x => x.email).map(x => x.email).join(', ')"
                                                  x-text="'+' + m.participants.filter(x => x.email).length"></span>
                                        </template>
                                    </span>
                                </div>

                                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-line pt-3">
                                    <button x-show="m.can_join" @click="wejdzDoPokoju(m)"
                                            :class="m.status === 'live' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-brand-600 hover:bg-brand-700'"
                                            class="flex items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold text-white shadow-lift transition">
                                        <?= icon('video', 'h-4 w-4') ?>
                                        <span x-text="m.status === 'live' ? 'Dołącz teraz' : 'Wejdź do pokoju'"></span>
                                    </button>

                                    <span x-show="!m.can_join" x-cloak class="text-[11px] text-faint" x-text="m.join_hint"></span>

                                    <button @click="otworzNotatkeSpotkania(m)"
                                            class="flex items-center gap-1.5 rounded-xl border border-line px-3 py-2 text-xs font-medium text-muted transition hover:border-brand-300 hover:text-brand-600">
                                        <?= icon('note', 'h-3.5 w-3.5') ?> Notatka
                                    </button>

                                    <button @click="kopiujLinkSpotkania(m)" title="Skopiuj link do pokoju"
                                            class="flex items-center gap-1.5 rounded-xl border border-line px-3 py-2 text-xs font-medium text-muted transition hover:border-brand-300 hover:text-brand-600">
                                        <?= icon('copy', 'h-3.5 w-3.5') ?> Link
                                    </button>

                                    <div class="ml-auto flex items-center gap-1">
                                        <template x-if="m.created_by === me.id">
                                            <div class="flex items-center gap-1">
                                                <button @click="edytujSpotkanie(m)" title="Zmień szczegóły"
                                                        class="rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-brand-600">
                                                    <?= icon('pencil', 'h-3.5 w-3.5') ?>
                                                </button>
                                                <button x-show="m.status !== 'cancelled' && m.status !== 'ended'"
                                                        @click="zmienStatusSpotkania(m, 'cancelled')" title="Odwołaj spotkanie"
                                                        class="rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-amber-600">
                                                    <?= icon('close', 'h-3.5 w-3.5') ?>
                                                </button>
                                                <button x-show="m.status === 'cancelled'"
                                                        @click="zmienStatusSpotkania(m, 'scheduled')" title="Przywróć spotkanie"
                                                        class="rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-emerald-600">
                                                    <?= icon('check', 'h-3.5 w-3.5') ?>
                                                </button>
                                                <button @click="usunSpotkanie(m)" title="Usuń spotkanie"
                                                        class="rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-red-600">
                                                    <?= icon('trash', 'h-3.5 w-3.5') ?>
                                                </button>
                                            </div>
                                        </template>
                                        <span x-show="m.created_by !== me.id" class="text-[11px] text-faint">
                                            umówił: <span class="text-muted" x-text="m.created_by_name"></span>
                                        </span>
                                    </div>
                                </div>
                            </article>
                        </template>
                        </section>
                    </template>
                </div>
            </div>

            <!-- ---------- Kalendarz terminów ---------- -->
            <div x-show="view === 'calendar'" x-cloak class="thin-scroll h-full overflow-y-auto p-4 sm:p-6">
                <div class="mx-auto max-w-6xl space-y-4">

                    <!-- Siatka miesiąca. Na telefonie zastępuje ją lista dni niżej:
                         siedem kolumn na wąskim ekranie nie daje się czytać. -->
                    <div class="hidden overflow-hidden rounded-2xl border border-line bg-surface shadow-card sm:block">
                        <div class="grid grid-cols-7 border-b border-line bg-surface2">
                            <!-- Weekendu nie wyróżniamy przygaszonym tekstem: przy 11 px
                                 spada poniżej progu czytelności, a kolejność dni i tak
                                 jest oczywista. -->
                            <template x-for="d in ['pon', 'wt', 'śr', 'czw', 'pt', 'sob', 'niedz']" :key="d">
                                <div class="px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wider text-faint"
                                     x-text="d"></div>
                            </template>
                        </div>

                        <div class="grid grid-cols-7 gap-px bg-line">
                            <template x-for="c in kalSiatka" :key="c.iso">
                                <div @click="kalWybierzDzien(c.iso)"
                                     :class="[
                                         c.wMiesiacu ? 'bg-surface' : 'bg-surface2',
                                         kal.dzien === c.iso ? 'ring-2 ring-inset ring-brand-400' : ''
                                     ]"
                                     :title="c.zadania.length ? odmianaZadan(c.zadania.length) + ' tego dnia' : 'Brak zadań tego dnia'"
                                     class="flex min-h-[104px] cursor-pointer flex-col gap-1 p-1.5 transition hover:brightness-[0.98] dark:hover:brightness-125">

                                    <div class="flex items-center justify-between px-0.5">
                                        <span class="text-[11px]"
                                              :class="c.dzis
                                                      ? 'flex h-5 w-5 items-center justify-center rounded-full bg-brand-600 font-semibold text-white'
                                                      : (c.wMiesiacu ? 'text-ink2' : 'text-faint')"
                                              x-text="c.numer"></span>
                                        <span x-show="c.zadania.length > 3"
                                              class="text-[10px] font-semibold text-faint"
                                              x-text="'+' + (c.zadania.length - 3)"></span>
                                    </div>

                                    <template x-for="t in c.zadania.slice(0, 3)" :key="t.id">
                                        <button @click.stop="otworzZListy(t)"
                                                :style="kalChipStyle(t)"
                                                :title="t.title + ' — ' + t.folder_name"
                                                class="flex w-full items-center gap-1 rounded-md px-1.5 py-0.5 text-left text-[11px] font-medium">
                                            <span class="h-1.5 w-1.5 shrink-0 rounded-full"
                                                  :style="'background:' + kalKropka(t)"></span>
                                            <span class="truncate"
                                                  :class="t.status === 'done' ? 'line-through' : ''"
                                                  x-text="t.title"></span>
                                        </button>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Wersja na telefon: tylko dni, w których coś wypada. -->
                    <div class="space-y-3 sm:hidden">
                        <template x-for="c in kalAgenda" :key="c.iso">
                            <section class="overflow-hidden rounded-2xl border border-line bg-surface shadow-card">
                                <div class="flex items-center gap-2 border-b border-line px-4 py-2"
                                     :class="c.dzis ? 'bg-brandsoft' : 'bg-surface2'">
                                    <span class="text-xs font-semibold"
                                          :class="c.dzis ? 'text-brandink' : 'text-ink2'" x-text="c.naglowek"></span>
                                    <span class="ml-auto rounded-md bg-surface3 px-1.5 text-[11px] font-semibold text-muted"
                                          x-text="c.zadania.length"></span>
                                </div>
                                <template x-for="t in c.zadania" :key="t.id">
                                    <?= task_row() ?>
                                </template>
                            </section>
                        </template>

                        <p x-show="!kalAgenda.length" class="rounded-2xl border border-line bg-surface px-4 py-10 text-center text-sm text-muted">
                            W tym miesiącu nie ma zadań z terminem.
                        </p>
                    </div>

                    <!-- Szczegóły wybranego dnia — komplet zadań, także tych,
                         które nie zmieściły się w kratce. -->
                    <div x-show="kal.dzien" x-cloak class="overflow-hidden rounded-2xl border border-line bg-surface shadow-card">
                        <div class="flex items-center gap-2 border-b border-line px-4 py-3">
                            <span class="text-brand-500"><?= icon('calendar', 'h-4 w-4') ?></span>
                            <h2 class="text-sm font-semibold text-ink" x-text="kalNaglowekDnia"></h2>
                            <span class="rounded-md bg-surface3 px-1.5 text-[11px] font-semibold text-muted"
                                  x-text="kalZadaniaDnia.length"></span>
                            <button @click="kal.dzien = null" title="Zamknij podgląd dnia"
                                    class="ml-auto rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-red-600">
                                <?= icon('close', 'h-3.5 w-3.5') ?>
                            </button>
                        </div>

                        <p x-show="!kalZadaniaDnia.length" class="px-4 py-8 text-center text-xs text-faint">
                            Na ten dzień nie ma zaplanowanego żadnego zadania.
                        </p>

                        <template x-for="t in kalZadaniaDnia" :key="t.id">
                            <?= task_row() ?>
                        </template>
                    </div>

                    <div x-show="!kal.ladowanie && !kalWidoczne.length" x-cloak
                         class="hidden rounded-2xl border border-line bg-surface p-10 text-center sm:block">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brandsoft text-brand-500">
                            <?= icon('calendar', 'h-7 w-7') ?>
                        </div>
                        <h2 class="text-base font-semibold text-ink">Pusty miesiąc</h2>
                        <p class="mt-1.5 text-sm text-muted">
                            Żadne zadanie nie ma tu terminu. Termin ustawisz w oknie zadania
                            albo od razu przy dodawaniu go do kolumny.
                        </p>
                    </div>
                </div>
            </div>

            <!-- ---------- Tablica kanban ---------- -->
            <div x-show="view === 'folder' && current && tab === 'board'" x-cloak class="thin-scroll h-full overflow-x-auto p-4 sm:p-6">
                <div class="grid h-full grid-cols-[repeat(3,minmax(276px,1fr))] gap-4">
                    <template x-for="col in columns" :key="col.key">
                        <section @dragover.prevent="dragOver = col.key"
                                 @dragleave="if (dragOver === col.key) dragOver = null"
                                 @drop.prevent="dropOn(col.key)"
                                 :class="dragOver === col.key ? 'border-brand-400 bg-brandsoft/40' : 'border-line bg-surface/60'"
                                 class="flex min-h-0 flex-col rounded-2xl border transition-colors">

                            <header class="flex items-center gap-2 border-b border-line px-4 py-3">
                                <span class="h-2 w-2 rounded-full" :style="'background:' + col.dot"></span>
                                <h2 class="text-sm font-semibold text-ink2" x-text="col.label"></h2>
                                <span class="rounded-md bg-surface3 px-1.5 text-[11px] font-semibold text-muted"
                                      x-text="tasksIn(col.key).length"></span>
                            </header>

                            <!-- Dodawanie zadania na górze kolumny — od razu rzuca się w oczy -->
                            <div class="border-b border-line p-3">
                                <div class="rounded-xl border border-line bg-surface p-2 focus-within:border-brand-400 focus-within:ring-2 focus-within:ring-brand-100 dark:focus-within:ring-brand-900">
                                    <input x-model="quick[col.key].title" @focus="quick[col.key].open = true"
                                           @keydown.enter.prevent="addTask(col.key)"
                                           type="text" :placeholder="'Nowe zadanie: ' + col.label.toLowerCase() + '…'"
                                           class="w-full border-0 bg-transparent p-1 text-sm text-ink placeholder:text-faint focus:ring-0">

                                    <div x-show="quick[col.key].open" x-cloak class="mt-2 space-y-2 border-t border-line pt-2">
                                        <div class="flex items-center gap-1">
                                            <span class="mr-0.5 text-[10px] font-semibold uppercase tracking-wider text-faint">Kto</span>
                                            <template x-for="u in users" :key="u.id">
                                                <button @click="toggleQuickAssignee(col.key, u.id)"
                                                        :title="(quick[col.key].assignee_ids.includes(u.id) ? 'Usuń: ' : 'Dodaj: ') + u.name"
                                                        :class="quick[col.key].assignee_ids.includes(u.id) ? 'ring-2 ring-offset-1 ring-brand-400 ring-offset-surface' : 'opacity-45 hover:opacity-100'"
                                                        class="flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-bold text-white transition"
                                                        :style="'background:' + accent(u.color).solid"
                                                        x-text="initials(u.name)"></button>
                                            </template>
                                            <span x-show="!quick[col.key].assignee_ids.length" class="ml-1 text-[10px] text-faint">nikt</span>
                                        </div>

                                        <div class="flex items-center gap-1">
                                            <span class="mr-0.5 text-[10px] font-semibold uppercase tracking-wider text-faint">Prio</span>
                                            <template x-for="p in priorities" :key="p.key">
                                                <button @click="quick[col.key].priority = p.key" :title="'Priorytet: ' + p.label"
                                                        :class="quick[col.key].priority === p.key ? 'ring-2 ring-brand-400' : 'opacity-50 hover:opacity-100'"
                                                        :style="priorityStyle(p.key)"
                                                        class="rounded-md px-2 py-1 text-[11px] font-semibold transition"
                                                        x-text="p.label"></button>
                                            </template>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-faint">Termin</span>
                                            <input x-model="quick[col.key].due_date" type="date"
                                                   class="rounded-lg border-line bg-surface2 px-2 py-1 text-[11px] text-ink focus:border-brand-400 focus:ring-1 focus:ring-brand-200 dark:focus:ring-brand-800">
                                            <button x-show="quick[col.key].due_date" @click="quick[col.key].due_date = ''"
                                                    title="Bez terminu" class="rounded p-1 text-faint hover:text-red-600">
                                                <?= icon('close', 'h-3 w-3') ?>
                                            </button>
                                            <button @click="addTask(col.key)" :disabled="!quick[col.key].title.trim()"
                                                    class="ml-auto rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-surface3 disabled:text-faint">
                                                Dodaj
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="thin-scroll min-h-0 flex-1 space-y-2.5 overflow-y-auto p-3">
                                <template x-for="t in tasksIn(col.key)" :key="t.id">
                                    <article draggable="true"
                                             @dragstart="dragId = t.id; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', String(t.id))"
                                             @dragend="dragId = null; dragOver = null"
                                             @click="openTask(t)"
                                             :class="dragId === t.id ? 'drag-ghost' : ''"
                                             class="group cursor-pointer rounded-xl border border-line bg-surface p-3 shadow-sm transition hover:-translate-y-px hover:border-brand-300 hover:shadow-card">

                                        <div class="flex items-start gap-2.5">
                                            <button @click.stop="toggleDone(t)"
                                                    :title="t.status === 'done' ? 'Cofnij do zrobienia' : 'Oznacz jako zrobione'"
                                                    :class="t.status === 'done'
                                                        ? 'border-emerald-500 bg-emerald-500 text-white'
                                                        : 'border-linestrong text-transparent hover:border-emerald-400'"
                                                    class="mt-px flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded-md border-2 transition">
                                                <?= icon('check', 'h-3 w-3') ?>
                                            </button>
                                            <p class="min-w-0 flex-1 text-sm font-medium leading-snug"
                                               :class="t.status === 'done' ? 'text-faint line-through' : 'text-ink'"
                                               x-text="t.title"></p>
                                        </div>

                                        <p x-show="t.description" class="clamp-2 mt-1.5 pl-[30px] text-xs leading-relaxed text-muted"
                                           x-text="t.description"></p>

                                        <div class="mt-2.5 flex flex-wrap items-center gap-1.5 pl-[30px]">
                                            <template x-if="t.priority !== 'normal'">
                                                <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                                                      :style="priorityStyle(t.priority)">
                                                    <span x-html="priorityIcon(t.priority)"></span>
                                                    <span x-text="priorityLabel(t.priority)"></span>
                                                </span>
                                            </template>

                                            <template x-if="t.due_date">
                                                <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold"
                                                      :style="terminStyle(t.due_date, t.status)"
                                                      :title="'Termin: ' + fmtData(t.due_date)">
                                                    <?= icon('clock', 'h-3 w-3') ?>
                                                    <span x-text="terminEtykieta(t.due_date, t.status)"></span>
                                                </span>
                                            </template>

                                            <template x-if="t.assignees.length === 1">
                                                <span class="inline-flex items-center gap-1.5 rounded-full py-0.5 pl-0.5 pr-2.5 text-[11px] font-medium"
                                                      :style="chipStyle(t.assignees[0].color)">
                                                    <span class="flex h-[18px] w-[18px] items-center justify-center rounded-full text-[10px] font-bold text-white"
                                                          :style="'background:' + accent(t.assignees[0].color).solid"
                                                          x-text="initials(t.assignees[0].name)"></span>
                                                    <span x-text="t.assignees[0].name"></span>
                                                </span>
                                            </template>

                                            <template x-if="t.assignees.length > 1">
                                                <span class="inline-flex items-center gap-1 rounded-full bg-surface3 py-0.5 pl-0.5 pr-2 text-[11px] font-medium text-muted"
                                                      :title="t.assignees.map(a => a.name).join(', ')">
                                                    <span class="flex -space-x-1.5">
                                                        <template x-for="a in t.assignees" :key="a.id">
                                                            <span class="flex h-[18px] w-[18px] items-center justify-center rounded-full text-[9px] font-bold text-white ring-2 ring-surface"
                                                                  :style="'background:' + accent(a.color).solid"
                                                                  x-text="initials(a.name)"></span>
                                                        </template>
                                                    </span>
                                                    <span class="ml-0.5" x-text="ileOsob(t.assignees.length)"></span>
                                                </span>
                                            </template>

                                            <template x-if="!t.assignees.length">
                                                <span class="rounded-full bg-surface3 px-2 py-0.5 text-[11px] text-faint">Nieprzypisane</span>
                                            </template>

                                            <template x-if="t.file_count > 0">
                                                <span class="inline-flex items-center gap-1 rounded-md bg-surface3 px-1.5 py-0.5 text-[10px] font-semibold text-muted"
                                                      :title="t.file_count + ' załącznik(ów)'">
                                                    <?= icon('clip', 'h-3 w-3') ?>
                                                    <span x-text="t.file_count"></span>
                                                </span>
                                            </template>

                                            <template x-if="t.comment_count > 0">
                                                <span class="inline-flex items-center gap-1 rounded-md bg-surface3 px-1.5 py-0.5 text-[10px] font-semibold text-muted"
                                                      :title="t.comment_count + ' komentarz(y)'">
                                                    <?= icon('comment', 'h-3 w-3') ?>
                                                    <span x-text="t.comment_count"></span>
                                                </span>
                                            </template>
                                        </div>

                                        <p class="mt-2 border-t border-line pl-[30px] pt-2 text-[11px] leading-relaxed text-faint">
                                            Dodane przez <span class="font-medium text-muted" x-text="t.created_by_name"></span>
                                            <template x-if="t.updated_by_name">
                                                <span class="block">
                                                    Ostatnia zmiana: <span class="font-medium text-muted" x-text="t.updated_by_name"></span>,
                                                    <span x-text="fmtShort(t.updated_at)"></span>
                                                </span>
                                            </template>
                                        </p>
                                    </article>
                                </template>

                                <p x-show="!tasksIn(col.key).length" class="rounded-xl border border-dashed border-line px-3 py-6 text-center text-xs text-faint">
                                    Przeciągnij tutaj zadanie
                                </p>
                            </div>
                        </section>
                    </template>
                </div>
            </div>

            <!-- ---------- Notatka ---------- -->
            <div x-show="view === 'folder' && current && tab === 'note'" x-cloak class="thin-scroll h-full overflow-y-auto p-4 sm:p-6">
                <div class="mx-auto max-w-3xl">
                    <div class="overflow-hidden rounded-2xl border border-line bg-surface shadow-card">

                        <div class="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3 sm:px-5">
                            <div class="flex items-center gap-1 rounded-lg bg-surface3 p-0.5">
                                <button @click="noteMode = 'view'"
                                        :class="noteMode === 'view' ? 'bg-surface text-brandink shadow-sm' : 'text-muted'"
                                        class="rounded-md px-3 py-1.5 text-xs font-medium transition">Podgląd</button>
                                <button @click="noteMode = 'edit'"
                                        :class="noteMode === 'edit' ? 'bg-surface text-brandink shadow-sm' : 'text-muted'"
                                        class="rounded-md px-3 py-1.5 text-xs font-medium transition">Edycja</button>
                            </div>
                            <span class="text-xs text-faint">Obsługuje Markdown</span>

                            <div class="ml-auto flex items-center gap-3">
                                <span x-show="noteDirty" x-cloak class="text-xs font-medium text-amber-600 dark:text-amber-400">Niezapisane zmiany</span>
                                <button @click="saveNote()" :disabled="!noteDirty || noteSaving"
                                        class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-surface3 disabled:text-faint">
                                    <span x-text="noteSaving ? 'Zapisywanie…' : 'Zapisz notatkę'"></span>
                                </button>
                            </div>
                        </div>

                        <div class="p-4 sm:p-5">
                            <textarea x-show="noteMode === 'edit'" x-model="noteDraft"
                                      @input="noteDirty = true"
                                      @keydown.ctrl.s.prevent="saveNote()" @keydown.meta.s.prevent="saveNote()"
                                      rows="16" placeholder="Ustalenia, linki, plan działania…&#10;&#10;## Nagłówek&#10;- punkt listy&#10;**pogrubienie**"
                                      class="w-full resize-y rounded-xl border-line bg-surface2 p-4 font-mono text-[13px] leading-relaxed text-ink2 placeholder:text-faint focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900"></textarea>

                            <div x-show="noteMode === 'view'"
                                 :class="dark ? 'prose-invert' : ''"
                                 class="prose prose-sm prose-slate min-h-[16rem] max-w-none prose-headings:font-semibold prose-a:text-brand-500"
                                 x-html="noteDraft.trim() ? mdToHtml(noteDraft) : pustaNotatka()"></div>
                        </div>

                        <div class="flex items-center gap-2 border-t border-line bg-surface2 px-4 py-3 text-xs text-muted sm:px-5">
                            <?= icon('clock', 'h-3.5 w-3.5 text-faint') ?>
                            <template x-if="note.updated_at">
                                <span>
                                    Ostatnia edycja: <span class="font-semibold text-ink2" x-text="note.updated_by_name"></span>,
                                    <span x-text="fmtFull(note.updated_at)"></span>
                                </span>
                            </template>
                            <template x-if="!note.updated_at">
                                <span>Notatka nie była jeszcze edytowana.</span>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ---------- Pliki ---------- -->
            <div x-show="view === 'folder' && current && tab === 'files'" x-cloak class="thin-scroll h-full overflow-y-auto p-4 sm:p-6">
                <div class="mx-auto max-w-3xl space-y-4">

                    <div @dragover.prevent="dropActive = true"
                         @dragleave.prevent="dropActive = false"
                         @drop.prevent="onFileDrop($event)"
                         @click="$refs.fileInput.click()"
                         :class="dropActive ? 'border-brand-400 bg-brandsoft' : 'border-linestrong bg-surface hover:border-brand-300'"
                         class="cursor-pointer rounded-2xl border-2 border-dashed p-8 text-center transition">
                        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-brandsoft text-brand-500">
                            <?= icon('upload', 'h-6 w-6') ?>
                        </div>
                        <p class="text-sm font-medium text-ink2">Przeciągnij pliki tutaj lub kliknij, aby wybrać</p>
                        <p class="mt-1 text-xs text-faint">
                            <span x-text="limits.allowed_ext.join(', ').toUpperCase()"></span> ·
                            maksymalnie <span x-text="fmtSize(limits.max_upload)"></span> na plik
                        </p>
                        <p x-show="limits.server_upload < limits.max_upload" x-cloak class="mt-2 text-xs font-medium text-amber-600 dark:text-amber-400">
                            Uwaga: ustawienia serwera ograniczają wysyłkę do <span x-text="fmtSize(limits.server_upload)"></span> (patrz README.md).
                        </p>
                    </div>

                    <input type="file" x-ref="fileInput" class="hidden" multiple @change="onFilePick($event)">

                    <div x-show="uploading" x-cloak class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                        <div class="mb-2 flex items-center justify-between text-xs">
                            <span class="truncate font-medium text-ink2" x-text="uploadName"></span>
                            <span class="ml-3 shrink-0 font-semibold text-brand-500" x-text="uploadPct + '%'"></span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-surface3">
                            <div class="h-full rounded-full bg-brand-600 transition-all duration-150" :style="'width:' + uploadPct + '%'"></div>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-line bg-surface shadow-card">
                        <template x-for="f in files" :key="f.id">
                            <div class="flex items-center gap-3 border-b border-line px-4 py-3 last:border-0 hover:bg-surface2">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-[10px] font-bold uppercase"
                                      :style="fileBadge(f.ext)" x-text="f.ext"></span>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="truncate text-sm font-medium text-ink" x-text="f.name"></p>
                                        <template x-if="f.task_title">
                                            <span class="inline-flex max-w-[220px] items-center gap-1 rounded-md bg-brandsoft px-1.5 py-0.5 text-[10px] font-semibold text-brandink"
                                                  :title="'Załącznik zadania: ' + f.task_title">
                                                <?= icon('columns', 'h-3 w-3') ?>
                                                <span class="truncate" x-text="f.task_title"></span>
                                            </span>
                                        </template>
                                    </div>
                                    <p class="mt-0.5 truncate text-[11px] text-faint">
                                        <span x-text="fmtSize(f.size)"></span> ·
                                        wrzucił(a) <span class="font-medium text-muted" x-text="f.uploaded_by_name"></span>,
                                        <span x-text="fmtFull(f.uploaded_at)"></span>
                                    </p>
                                </div>

                                <div class="flex shrink-0 items-center gap-1">
                                    <template x-if="f.preview">
                                        <a :href="f.preview" target="_blank" rel="noopener" title="Podgląd w nowej karcie"
                                           class="rounded-lg p-2 text-faint transition hover:bg-surface3 hover:text-brand-600">
                                            <?= icon('eye', 'h-[18px] w-[18px]') ?>
                                        </a>
                                    </template>
                                    <template x-if="f.viewer === 'docx'">
                                        <button @click="pokazDocx(f)" title="Podgląd dokumentu Worda"
                                                class="rounded-lg p-2 text-faint transition hover:bg-surface3 hover:text-brand-600">
                                            <?= icon('eye', 'h-[18px] w-[18px]') ?>
                                        </button>
                                    </template>
                                    <a :href="f.url" title="Pobierz"
                                       class="rounded-lg p-2 text-faint transition hover:bg-surface3 hover:text-brand-600">
                                        <?= icon('download', 'h-[18px] w-[18px]') ?>
                                    </a>
                                    <button @click="deleteFile(f)" title="Usuń plik"
                                            class="rounded-lg p-2 text-faint transition hover:bg-surface3 hover:text-red-600">
                                        <?= icon('trash', 'h-[18px] w-[18px]') ?>
                                    </button>
                                </div>
                            </div>
                        </template>

                        <p x-show="!files.length" class="px-4 py-12 text-center text-sm text-faint">
                            W tym folderze nie ma jeszcze żadnych załączników.
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- ====================== DZIENNIK AKTYWNOŚCI ====================== -->
    <div x-show="feedOpen" x-cloak class="fixed inset-0 z-50">
        <div x-show="feedOpen" x-transition.opacity @click="feedOpen = false" class="absolute inset-0 bg-slate-900/40"></div>

        <aside x-show="feedOpen"
               x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
               class="absolute inset-y-0 right-0 flex w-full max-w-sm flex-col border-l border-line bg-surface shadow-lift">

            <header class="flex items-center gap-3 border-b border-line px-5 py-4">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brandsoft text-brandink">
                    <?= icon('clock', 'h-[18px] w-[18px]') ?>
                </span>
                <div class="flex-1">
                    <h2 class="text-sm font-semibold text-ink">Dziennik zmian</h2>
                    <p class="text-[11px] text-faint">Ostatnie <span x-text="activity.length"></span> akcji zespołu</p>
                </div>
                <button @click="feedOpen = false" class="rounded-lg p-2 text-faint hover:bg-surface3" aria-label="Zamknij">
                    <?= icon('close', 'h-[18px] w-[18px]') ?>
                </button>
            </header>

            <div class="thin-scroll min-h-0 flex-1 overflow-y-auto">
                <template x-for="a in activity" :key="a.id">
                    <div class="flex gap-3 border-b border-line px-5 py-3.5 last:border-0">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white"
                              :style="'background:' + accent(a.user_color).solid" x-text="initials(a.user_name)"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] leading-relaxed text-muted">
                                <span class="font-semibold text-ink" x-text="a.user_name"></span>
                                <span class="text-faint">·</span>
                                <span x-text="a.message"></span>
                            </p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-[11px] text-faint">
                                <span x-text="fmtRel(a.at)"></span>
                                <template x-if="a.folder_name">
                                    <span>· <span class="text-muted" x-text="a.folder_name"></span></span>
                                </template>
                            </p>
                        </div>
                    </div>
                </template>

                <p x-show="!activity.length" class="px-5 py-12 text-center text-sm text-faint">
                    Dziennik jest pusty.
                </p>
            </div>
        </aside>
    </div>

    <!-- ========================= MODAL ZADANIA ========================= -->
    <div x-show="task.open" x-cloak class="fixed inset-0 z-50 grid place-items-end sm:place-items-center">
        <div x-show="task.open" x-transition.opacity @click="task.open = false" class="absolute inset-0 bg-slate-900/50"></div>

        <div x-show="task.open"
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-6 opacity-0 sm:scale-95" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
             class="relative m-0 flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-3xl bg-surface shadow-lift sm:m-4 sm:max-w-lg sm:rounded-3xl">

            <header class="flex items-center justify-between border-b border-line px-5 py-4">
                <h2 class="text-sm font-semibold text-ink">Szczegóły zadania</h2>
                <button @click="task.open = false" class="rounded-lg p-1.5 text-faint hover:bg-surface3" aria-label="Zamknij">
                    <?= icon('close', 'h-[18px] w-[18px]') ?>
                </button>
            </header>

            <div class="thin-scroll min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Treść zadania</label>
                    <input x-model="task.title" type="text" maxlength="200"
                           class="w-full rounded-xl border-line bg-surface2 px-3.5 py-2.5 text-sm font-medium text-ink focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Opis (opcjonalnie)</label>
                    <textarea x-model="task.description" rows="4" maxlength="5000" placeholder="Szczegóły, linki, ustalenia…"
                              class="w-full resize-y rounded-xl border-line bg-surface2 px-3.5 py-2.5 text-sm leading-relaxed text-ink2 placeholder:text-faint focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900"></textarea>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Status</label>
                    <div class="grid grid-cols-3 gap-1 rounded-xl bg-surface3 p-1">
                        <template x-for="col in columns" :key="col.key">
                            <button @click="task.status = col.key"
                                    :class="task.status === col.key ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink2'"
                                    class="flex items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-medium transition">
                                <span class="h-1.5 w-1.5 rounded-full" :style="'background:' + col.dot"></span>
                                <span x-text="col.label"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Priorytet</label>
                    <div class="grid grid-cols-3 gap-1.5">
                        <template x-for="p in priorities" :key="p.key">
                            <button @click="task.priority = p.key"
                                    :style="task.priority === p.key ? priorityStyle(p.key) : ''"
                                    :class="task.priority === p.key ? 'border-transparent ring-2 ring-brand-400' : 'border-line text-muted hover:border-linestrong'"
                                    class="flex items-center justify-center gap-1.5 rounded-xl border px-2 py-2 text-xs font-semibold transition">
                                <span x-html="priorityIcon(p.key)"></span>
                                <span x-text="p.label"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Termin wykonania</label>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint">
                                <?= icon('calendar', 'h-4 w-4') ?>
                            </span>
                            <input x-model="task.due_date" type="date"
                                   class="rounded-xl border-line bg-surface2 py-2 pl-10 pr-3 text-sm text-ink focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900">
                        </div>

                        <button @click="task.due_date = dataZa(0)"
                                class="rounded-lg border border-line px-2.5 py-1.5 text-xs font-medium text-muted transition hover:border-brand-300 hover:text-brand-600">Dziś</button>
                        <button @click="task.due_date = dataZa(1)"
                                class="rounded-lg border border-line px-2.5 py-1.5 text-xs font-medium text-muted transition hover:border-brand-300 hover:text-brand-600">Jutro</button>
                        <button @click="task.due_date = dataZa(7)"
                                class="rounded-lg border border-line px-2.5 py-1.5 text-xs font-medium text-muted transition hover:border-brand-300 hover:text-brand-600">Za tydzień</button>
                        <button x-show="task.due_date" @click="task.due_date = ''"
                                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-faint transition hover:text-red-600">Bez terminu</button>
                    </div>
                    <p x-show="task.due_date" class="mt-1.5 text-[11px]"
                       :class="terminMinal(task.due_date) ? 'font-semibold text-red-600 dark:text-red-400' : 'text-faint'"
                       x-text="terminOpis(task.due_date)"></p>
                </div>

                <div>
                    <label class="mb-1.5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-faint">
                        Osoby odpowiedzialne
                        <span class="normal-case tracking-normal text-faint">— można zaznaczyć kilka</span>
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="u in users" :key="u.id">
                            <button @click="toggleTaskAssignee(u.id)"
                                    :class="task.assignee_ids.includes(u.id) ? 'border-transparent' : 'border-line text-ink2 hover:border-linestrong'"
                                    :style="task.assignee_ids.includes(u.id) ? chipStyle(u.color) + ';border-color:' + accent(u.color).solid : ''"
                                    class="flex items-center gap-2 rounded-xl border px-3 py-2 text-xs font-medium transition">
                                <span class="relative flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold text-white"
                                      :style="'background:' + accent(u.color).solid" x-text="initials(u.name)"></span>
                                <span x-text="u.name"></span>
                                <span x-show="task.assignee_ids.includes(u.id)"><?= icon('check', 'h-3.5 w-3.5') ?></span>
                            </button>
                        </template>
                    </div>
                    <p x-show="!task.assignee_ids.length" class="mt-1.5 text-[11px] text-faint">
                        Nikt nie jest przypisany — kliknij imię, żeby dodać.
                    </p>
                </div>

                <!-- ---- Załączniki podpięte pod to zadanie ---- -->
                <div x-show="task.id">
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Załączniki zadania</label>

                    <div class="overflow-hidden rounded-xl border border-line">
                        <template x-for="f in taskFiles" :key="f.id">
                            <div class="flex items-center gap-2.5 border-b border-line px-3 py-2 last:border-0">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[9px] font-bold uppercase"
                                      :style="fileBadge(f.ext)" x-text="f.ext"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs font-medium text-ink" x-text="f.name"></p>
                                    <p class="truncate text-[10px] text-faint">
                                        <span x-text="fmtSize(f.size)"></span> ·
                                        <span x-text="f.uploaded_by_name"></span>, <span x-text="fmtShort(f.uploaded_at)"></span>
                                    </p>
                                </div>
                                <template x-if="f.viewer === 'docx'">
                                    <button @click="pokazDocx(f)" title="Podgląd dokumentu Worda"
                                            class="rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-brand-600">
                                        <?= icon('eye', 'h-4 w-4') ?>
                                    </button>
                                </template>
                                <template x-if="f.preview">
                                    <a :href="f.preview" target="_blank" rel="noopener" title="Podgląd w nowej karcie"
                                       class="rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-brand-600">
                                        <?= icon('eye', 'h-4 w-4') ?>
                                    </a>
                                </template>
                                <a :href="f.url" title="Pobierz" class="rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-brand-600">
                                    <?= icon('download', 'h-4 w-4') ?>
                                </a>
                                <button @click="detachFile(f)" title="Odepnij od zadania (plik zostaje w folderze)"
                                        class="rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-amber-600">
                                    <?= icon('unlink', 'h-4 w-4') ?>
                                </button>
                                <button @click="deleteFile(f)" title="Usuń plik z serwera"
                                        class="rounded-lg p-1.5 text-faint transition hover:bg-surface3 hover:text-red-600">
                                    <?= icon('trash', 'h-4 w-4') ?>
                                </button>
                            </div>
                        </template>

                        <p x-show="!taskFiles.length" class="px-3 py-4 text-center text-xs text-faint">
                            Brak załączników. Wgraj nowy plik albo podepnij taki, który już jest w folderze.
                        </p>

                        <div class="grid grid-cols-2 gap-px border-t border-line bg-line">
                            <button @click="$refs.taskFileInput.click()" :disabled="taskUploading"
                                    class="flex items-center justify-center gap-2 bg-surface px-3 py-2.5 text-xs font-medium text-muted transition hover:bg-surface2 hover:text-brand-600 disabled:cursor-not-allowed">
                                <?= icon('upload', 'h-4 w-4') ?>
                                <span x-text="taskUploading ? 'Wysyłanie… ' + taskUploadPct + '%' : 'Wgraj nowy'"></span>
                            </button>
                            <button @click="filePickerOpen = !filePickerOpen"
                                    :class="filePickerOpen ? 'text-brandink' : 'text-muted'"
                                    class="flex items-center justify-center gap-2 bg-surface px-3 py-2.5 text-xs font-medium transition hover:bg-surface2 hover:text-brand-600">
                                <?= icon('clip', 'h-4 w-4') ?>
                                Podepnij istniejący
                                <span class="rounded bg-surface3 px-1 text-[10px] font-semibold" x-text="attachableFiles.length"></span>
                            </button>
                        </div>
                    </div>

                    <input type="file" x-ref="taskFileInput" class="hidden" multiple @change="onTaskFilePick($event)">

                    <div x-show="taskUploading" class="mt-2 h-1 overflow-hidden rounded-full bg-surface3">
                        <div class="h-full rounded-full bg-brand-600 transition-all duration-150" :style="'width:' + taskUploadPct + '%'"></div>
                    </div>

                    <!-- Lista plików folderu, które można podpiąć pod to zadanie -->
                    <div x-show="filePickerOpen" x-cloak class="mt-2 overflow-hidden rounded-xl border border-brand-300 bg-surface2">
                        <p class="border-b border-line px-3 py-2 text-[11px] text-muted">
                            Kliknij plik, żeby podpiąć go pod to zadanie.
                        </p>

                        <div class="thin-scroll max-h-52 overflow-y-auto">
                            <template x-for="f in attachableFiles" :key="f.id">
                                <button @click="attachFile(f)"
                                        class="flex w-full items-center gap-2.5 border-b border-line px-3 py-2 text-left transition last:border-0 hover:bg-surface">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-[9px] font-bold uppercase"
                                          :style="fileBadge(f.ext)" x-text="f.ext"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-xs font-medium text-ink" x-text="f.name"></span>
                                        <span class="block truncate text-[10px] text-faint">
                                            <span x-text="fmtSize(f.size)"></span>
                                            <template x-if="f.task_title">
                                                <span class="text-amber-600 dark:text-amber-400">
                                                    · podpięty pod „<span x-text="f.task_title"></span>” — przeniesiemy go tutaj
                                                </span>
                                            </template>
                                        </span>
                                    </span>
                                    <span class="shrink-0 text-faint"><?= icon('plus', 'h-4 w-4') ?></span>
                                </button>
                            </template>

                            <p x-show="!attachableFiles.length" class="px-3 py-5 text-center text-[11px] text-faint">
                                W tym folderze nie ma innych plików. Wgraj je w zakładce „Pliki” albo przyciskiem obok.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ---- Dyskusja pod zadaniem ---- -->
                <div x-show="task.id">
                    <label class="mb-1.5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-faint">
                        Komentarze
                        <span x-show="comments.length" class="rounded-md bg-surface3 px-1.5 normal-case tracking-normal text-muted"
                              x-text="comments.length"></span>
                    </label>

                    <div class="space-y-2">
                        <p x-show="commentsLoading" class="px-1 text-xs text-faint">Wczytywanie…</p>

                        <p x-show="!commentsLoading && !comments.length" class="rounded-xl border border-dashed border-line px-3 py-4 text-center text-xs text-faint">
                            Brak komentarzy. Napisz pierwszy — opis zadania zostanie nietknięty.
                        </p>

                        <template x-for="k in comments" :key="k.id">
                            <div class="group flex gap-2.5 rounded-xl bg-surface2 px-3 py-2.5">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white"
                                      :style="'background:' + accent(k.user_color).solid"
                                      x-text="initials(k.user_name)"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-baseline gap-x-2 text-[11px]">
                                        <span class="font-semibold text-ink" x-text="k.user_name"></span>
                                        <span class="text-faint" x-text="fmtRel(k.at)"></span>
                                    </p>
                                    <p class="mt-0.5 whitespace-pre-wrap break-words text-[13px] leading-relaxed text-ink2"
                                       x-text="k.body"></p>
                                </div>
                                <button x-show="k.user_id === me.id" @click="usunKomentarz(k)"
                                        title="Usuń swój komentarz"
                                        class="h-fit shrink-0 rounded-lg p-1.5 text-faint opacity-0 transition hover:text-red-600 group-hover:opacity-100 max-lg:opacity-100">
                                    <?= icon('trash', 'h-3.5 w-3.5') ?>
                                </button>
                            </div>
                        </template>

                        <div class="rounded-xl border border-line bg-surface p-2 focus-within:border-brand-400 focus-within:ring-2 focus-within:ring-brand-100 dark:focus-within:ring-brand-900">
                            <textarea x-model="commentDraft" rows="2" maxlength="3000"
                                      @keydown.ctrl.enter.prevent="dodajKomentarz()"
                                      @keydown.meta.enter.prevent="dodajKomentarz()"
                                      placeholder="Napisz komentarz…"
                                      class="w-full resize-y border-0 bg-transparent p-1 text-[13px] leading-relaxed text-ink placeholder:text-faint focus:ring-0"></textarea>
                            <div class="flex items-center gap-2 border-t border-line pt-2">
                                <span class="text-[10px] text-faint">Ctrl+Enter wysyła</span>
                                <button @click="dodajKomentarz()" :disabled="!commentDraft.trim() || commentSaving"
                                        class="ml-auto rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-surface3 disabled:text-faint">
                                    <span x-text="commentSaving ? 'Wysyłanie…' : 'Dodaj komentarz'"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div x-show="task.id" class="rounded-xl bg-surface2 px-4 py-3 text-[11px] leading-relaxed text-muted">
                    <p>Dodane przez <span class="font-semibold text-ink2" x-text="task.meta.created_by_name"></span>,
                        <span x-text="fmtFull(task.meta.created_at)"></span></p>
                    <p x-show="task.meta.updated_by_name">Ostatnia zmiana:
                        <span class="font-semibold text-ink2" x-text="task.meta.updated_by_name"></span>,
                        <span x-text="fmtFull(task.meta.updated_at)"></span></p>
                </div>
            </div>

            <footer class="flex items-center gap-2 border-t border-line bg-surface2 px-5 py-4">
                <button x-show="task.id" @click="deleteTask()"
                        class="rounded-xl border border-line bg-surface p-2.5 text-faint transition hover:border-red-300 hover:text-red-600" title="Usuń zadanie">
                    <?= icon('trash', 'h-[18px] w-[18px]') ?>
                </button>
                <button @click="task.open = false" class="ml-auto rounded-xl px-4 py-2.5 text-sm font-medium text-muted transition hover:bg-surface3">
                    Anuluj
                </button>
                <button @click="saveTask()" :disabled="!task.title.trim() || task.saving"
                        class="rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-surface3 disabled:text-faint">
                    <span x-text="task.saving ? 'Zapisywanie…' : 'Zapisz'"></span>
                </button>
            </footer>
        </div>
    </div>

    <!-- ========================= POKÓJ WIDEO ========================= -->
    <!-- z-[70]: pokój przykrywa wszystko, także dziennik i okna modalne. -->
    <div x-show="room.open" x-cloak class="fixed inset-0 z-[70] flex flex-col bg-slate-950 text-slate-100">

        <header class="flex shrink-0 items-center gap-3 border-b border-slate-800 px-4 py-3">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-800 text-brand-400">
                <?= icon('video', 'h-[18px] w-[18px]') ?>
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-sm font-semibold" x-text="room.title"></h2>
                <p class="flex items-center gap-2 text-[11px] text-slate-400">
                    <span class="flex items-center gap-1">
                        <span class="h-1.5 w-1.5 rounded-full"
                              :class="room.polaczenie === 'ok' ? 'bg-emerald-400' : (room.polaczenie === 'blad' ? 'bg-red-400' : 'bg-amber-400 animate-pulse')"></span>
                        <span x-text="room.statusTekst"></span>
                    </span>
                    <span x-show="room.czas" x-cloak>· <span x-text="room.czas"></span></span>
                    <span>· <span x-text="odmianaOsob(room.peers.length)"></span></span>
                </p>
            </div>

            <button @click="room.listaOpen = !room.listaOpen" title="Lista obecnych"
                    :class="room.listaOpen ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-800'"
                    class="rounded-xl p-2.5 transition">
                <?= icon('users', 'h-[18px] w-[18px]') ?>
            </button>
            <button @click="room.notatkiOpen = !room.notatkiOpen" title="Notatka ze spotkania"
                    :class="room.notatkiOpen ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-800'"
                    class="rounded-xl p-2.5 transition">
                <?= icon('note', 'h-[18px] w-[18px]') ?>
            </button>
        </header>

        <!-- Ostrzeżenia infrastrukturalne — zanim ktoś zacznie szukać winy w sobie. -->
        <div x-show="room.ostrzezenie" x-cloak
             class="shrink-0 border-b border-amber-800 bg-amber-950 px-4 py-2 text-[11px] leading-relaxed text-amber-200">
            <span x-text="room.ostrzezenie"></span>
        </div>

        <div class="flex min-h-0 flex-1 flex-col lg:flex-row">

            <!-- ---- Kafelki uczestników ---- -->
            <main class="flex min-h-0 flex-1 flex-col">
                <div class="thin-scroll min-h-0 flex-1 overflow-y-auto p-3">
                    <div class="grid h-full gap-3" :class="ukladKafelkow">
                        <template x-for="p in room.peers" :key="p.peer_id">
                            <div class="relative flex min-h-[140px] items-center justify-center overflow-hidden rounded-2xl bg-slate-900 ring-1"
                                 :class="p.mowi ? 'ring-emerald-400' : 'ring-slate-800'">

                                <!-- Widok domyślny: rozmawiamy głosem, więc kafelek
                                     pokazuje inicjały. Pierścień pulsuje, gdy ktoś mówi. -->
                                <div x-show="!p.cam && !p.sharing" class="flex flex-col items-center gap-2.5">
                                    <span class="flex h-20 w-20 items-center justify-center rounded-full text-2xl font-bold text-white transition-shadow"
                                          :class="p.mowi ? 'ring-4 ring-emerald-400/70' : 'ring-2 ring-slate-700'"
                                          :style="'background:' + accent(p.color).solid" x-text="initials(p.name)"></span>
                                    <span class="text-[11px] text-slate-500" x-text="p.mic ? 'bez kamery' : 'mikrofon wyciszony'"></span>
                                </div>

                                <video x-show="p.cam || p.sharing"
                                       x-effect="room.strumienTik; podepnijWideo($el, p.peer_id)"
                                       autoplay playsinline
                                       class="h-full w-full bg-black object-contain"
                                       :class="p.me && !p.sharing ? 'scale-x-[-1]' : ''"></video>

                                <div class="pointer-events-none absolute inset-x-0 bottom-0 flex items-center gap-1.5 bg-gradient-to-t from-slate-950/90 to-transparent px-3 pb-2 pt-6">
                                    <span class="truncate text-xs font-medium"
                                          x-text="p.name + (p.me ? ' (Ty)' : '')"></span>
                                    <span x-show="!p.mic" class="text-red-400" title="mikrofon wyciszony"><?= icon('micOff', 'h-3.5 w-3.5') ?></span>
                                    <span x-show="p.sharing" class="text-brand-400" title="udostępnia ekran"><?= icon('screen', 'h-3.5 w-3.5') ?></span>
                                    <span x-show="p.mowi && p.mic" class="ml-auto flex h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                                </div>

                                <!-- Stan łącza z konkretną osobą, a nie ogólny. -->
                                <span x-show="!p.me && p.stan && p.stan !== 'connected'" x-cloak
                                      class="absolute right-2 top-2 rounded-md bg-slate-950/80 px-1.5 py-0.5 text-[10px] font-medium text-amber-300"
                                      x-text="stanPolaczeniaTekst(p.stan)"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- ---- Pasek sterowania ---- -->
                <div class="shrink-0 border-t border-slate-800 px-3 py-3">
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        <button @click="przelaczMikrofon()" :disabled="room.mikrofonCzeka"
                                :title="room.mic ? 'Wycisz mikrofon' : 'Włącz mikrofon'"
                                :class="room.mic ? 'bg-slate-800 text-slate-100 hover:bg-slate-700' : 'bg-red-600 text-white hover:bg-red-700'"
                                class="flex h-12 w-12 items-center justify-center rounded-full transition disabled:opacity-60">
                            <span x-show="room.mikrofonCzeka" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                            <span x-show="!room.mikrofonCzeka && room.mic"><?= icon('mic', 'h-5 w-5') ?></span>
                            <span x-show="!room.mikrofonCzeka && !room.mic"><?= icon('micOff', 'h-5 w-5') ?></span>
                        </button>

                        <!-- Kamera jest dodatkiem, nie warunkiem rozmowy: wyłączona
                             wygląda zwyczajnie, włączona jest wyróżniona kolorem. -->
                        <button @click="przelaczKamere()" :disabled="room.kameraCzeka"
                                :title="room.cam ? 'Wyłącz kamerę' : 'Włącz kamerę (opcjonalnie)'"
                                :class="room.cam ? 'bg-brand-600 text-white hover:bg-brand-700' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                                class="flex h-12 w-12 items-center justify-center rounded-full transition disabled:opacity-60">
                            <span x-show="room.kameraCzeka" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                            <span x-show="!room.kameraCzeka && room.cam"><?= icon('video', 'h-5 w-5') ?></span>
                            <span x-show="!room.kameraCzeka && !room.cam"><?= icon('videoOff', 'h-5 w-5') ?></span>
                        </button>

                        <button @click="przelaczEkran()" x-show="room.mozeUdostepniac"
                                :title="room.sharing ? 'Zakończ udostępnianie' : 'Udostępnij ekran'"
                                :class="room.sharing ? 'bg-brand-600 text-white hover:bg-brand-700' : 'bg-slate-800 text-slate-100 hover:bg-slate-700'"
                                class="flex h-12 w-12 items-center justify-center rounded-full transition">
                            <?= icon('screen', 'h-5 w-5') ?>
                        </button>

                        <button @click="room.notatkiOpen = !room.notatkiOpen"
                                title="Notatka ze spotkania"
                                :class="room.notatkiOpen ? 'bg-brand-600 text-white hover:bg-brand-700' : 'bg-slate-800 text-slate-100 hover:bg-slate-700'"
                                class="flex h-12 w-12 items-center justify-center rounded-full transition lg:hidden">
                            <?= icon('note', 'h-5 w-5') ?>
                        </button>

                        <button @click="opuscPokoj()" title="Opuść spotkanie"
                                class="ml-2 flex items-center gap-2 rounded-full bg-red-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-red-700">
                            <?= icon('logout', 'h-5 w-5') ?> Wyjdź
                        </button>
                    </div>

                    <p x-show="!room.cam && !room.sharing" x-cloak class="mt-2 text-center text-[11px] text-slate-500">
                        Rozmawiacie głosem. Kamerę włączysz przyciskiem obok — panel prosi o nią
                        dopiero wtedy, więc do tej chwili jest wolna dla innych programów.
                    </p>
                </div>
            </main>

            <!-- ---- Lista obecnych ---- -->
            <aside x-show="room.listaOpen" x-cloak
                   class="shrink-0 border-t border-slate-800 lg:w-64 lg:border-l lg:border-t-0">
                <div class="p-3">
                    <h3 class="mb-2 px-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">W pokoju</h3>
                    <div class="space-y-1">
                        <template x-for="p in room.peers" :key="'lista-' + p.peer_id">
                            <div class="flex items-center gap-2.5 rounded-xl px-2 py-2 hover:bg-slate-900">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white"
                                      :style="'background:' + accent(p.color).solid" x-text="initials(p.name)"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-xs font-medium" x-text="p.name + (p.me ? ' (Ty)' : '')"></span>
                                    <span class="block text-[10px] text-slate-500"
                                          x-text="p.me ? 'to Ty' : stanPolaczeniaTekst(p.stan)"></span>
                                </span>
                                <span class="flex shrink-0 items-center gap-1">
                                    <span :class="p.mic ? 'text-slate-500' : 'text-red-400'">
                                        <span x-show="p.mic"><?= icon('mic', 'h-3.5 w-3.5') ?></span>
                                        <span x-show="!p.mic"><?= icon('micOff', 'h-3.5 w-3.5') ?></span>
                                    </span>
                                    <span :class="p.cam ? 'text-slate-500' : 'text-red-400'">
                                        <span x-show="p.cam"><?= icon('video', 'h-3.5 w-3.5') ?></span>
                                        <span x-show="!p.cam"><?= icon('videoOff', 'h-3.5 w-3.5') ?></span>
                                    </span>
                                </span>
                            </div>
                        </template>
                    </div>

                    <p x-show="room.peers.length === 1" class="mt-3 rounded-xl bg-slate-900 px-3 py-3 text-[11px] leading-relaxed text-slate-400">
                        Jesteś w pokoju sam. Wyślij pozostałym link — przycisk <em>Link</em> na karcie spotkania.
                    </p>
                </div>
            </aside>

            <!-- ---- Notatka na żywo ---- -->
            <aside x-show="room.notatkiOpen" x-cloak
                   class="flex max-h-[45vh] min-h-0 shrink-0 flex-col border-t border-slate-800 bg-surface p-3 text-ink lg:max-h-none lg:w-96 lg:border-l lg:border-t-0">
                <div class="mb-2 flex items-center gap-2">
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-faint">Notatka ze spotkania</h3>
                    <button @click="room.notatkiOpen = false" class="ml-auto rounded-lg p-1 text-faint hover:bg-surface3" aria-label="Zamknij notatkę">
                        <?= icon('close', 'h-4 w-4') ?>
                    </button>
                </div>
                <div class="min-h-0 flex-1">
                    <?= meeting_note_editor('') ?>
                </div>
            </aside>
        </div>
    </div>

    <!-- ==================== UMAWIANIE SPOTKANIA ==================== -->
    <div x-show="form.open" x-cloak class="fixed inset-0 z-50 grid place-items-end sm:place-items-center">
        <div x-show="form.open" x-transition.opacity @click="form.open = false" class="absolute inset-0 bg-slate-900/50"></div>

        <!-- Komplet klas przejścia jest obowiązkowy: przy samym „enter”
             i „enter-start”, bez „enter-end”, Alpine zostawia oknu
             display:none i mimo otwarcia nic nie widać. -->
        <form @submit.prevent="zapiszSpotkanie()" x-show="form.open"
              x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-6 opacity-0 sm:scale-95" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
              class="thin-scroll relative max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-t-3xl border border-line bg-surface shadow-lift sm:rounded-3xl">

            <div class="sticky top-0 z-10 flex items-center gap-3 border-b border-line bg-surface px-5 py-4">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brandsoft text-brand-500">
                    <?= icon('video', 'h-[18px] w-[18px]') ?>
                </span>
                <h2 class="text-base font-semibold text-ink" x-text="form.id ? 'Szczegóły spotkania' : 'Nowe spotkanie'"></h2>
                <button type="button" @click="form.open = false" class="ml-auto rounded-lg p-1.5 text-faint hover:bg-surface3" aria-label="Zamknij">
                    <?= icon('close', 'h-5 w-5') ?>
                </button>
            </div>

            <div class="space-y-5 px-5 py-5">

                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Temat</label>
                    <input x-model="form.title" type="text" maxlength="120" required
                           placeholder="np. Przegląd tygodnia"
                           class="w-full rounded-xl border-line bg-surface2 px-3 py-2.5 text-sm text-ink placeholder:text-faint focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900">
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Data</label>
                        <input x-model="form.date" type="date" required
                               class="w-full rounded-xl border-line bg-surface2 px-3 py-2.5 text-sm text-ink focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Godzina</label>
                        <input x-model="form.time" type="time" required
                               class="w-full rounded-xl border-line bg-surface2 px-3 py-2.5 text-sm text-ink focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Czas trwania</label>
                        <select x-model.number="form.duration_min"
                                class="w-full rounded-xl border-line bg-surface2 px-3 py-2.5 text-sm text-ink focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900">
                            <template x-for="d in [15, 30, 45, 60, 90, 120]" :key="d">
                                <option :value="d" x-text="d + ' min'"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[11px] text-faint">Szybko:</span>
                    <button type="button" @click="terminSpotkaniaZa(0)"
                            class="rounded-lg border border-line px-2.5 py-1.5 text-xs font-medium text-muted transition hover:border-brand-300 hover:text-brand-600">Za chwilę</button>
                    <button type="button" @click="terminSpotkaniaZa(60)"
                            class="rounded-lg border border-line px-2.5 py-1.5 text-xs font-medium text-muted transition hover:border-brand-300 hover:text-brand-600">Za godzinę</button>
                    <button type="button" @click="terminSpotkaniaJutro()"
                            class="rounded-lg border border-line px-2.5 py-1.5 text-xs font-medium text-muted transition hover:border-brand-300 hover:text-brand-600">Jutro 9:00</button>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Agenda / opis</label>
                    <textarea x-model="form.description" rows="3" maxlength="4000"
                              placeholder="Co chcemy ustalić?"
                              class="thin-scroll w-full resize-y rounded-xl border-line bg-surface2 px-3 py-2.5 text-sm leading-relaxed text-ink placeholder:text-faint focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900"></textarea>
                </div>

                <div>
                    <label class="mb-1.5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-faint">
                        Uczestnicy z zespołu
                        <span class="normal-case tracking-normal text-faint">— Ty jesteś dodany zawsze</span>
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="u in users" :key="u.id">
                            <button type="button" @click="przelaczUczestnika(u.id)"
                                    :disabled="u.id === (form.owner_id || me.id)"
                                    :class="form.user_ids.includes(u.id) ? 'border-transparent' : 'border-line text-ink2 hover:border-linestrong'"
                                    :style="form.user_ids.includes(u.id) ? chipStyle(u.color) + ';border-color:' + accent(u.color).solid : ''"
                                    class="flex items-center gap-2 rounded-xl border px-3 py-2 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-70">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold text-white"
                                      :style="'background:' + accent(u.color).solid" x-text="initials(u.name)"></span>
                                <span x-text="u.name"></span>
                                <span x-show="form.user_ids.includes(u.id)"><?= icon('check', 'h-3.5 w-3.5') ?></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Goście spoza panelu</label>
                    <div class="flex gap-2">
                        <input x-model="form.emailDraft" type="email" placeholder="adres@example.com"
                               @keydown.enter.prevent="dodajEmail()"
                               class="min-w-0 flex-1 rounded-xl border-line bg-surface2 px-3 py-2.5 text-sm text-ink placeholder:text-faint focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900">
                        <button type="button" @click="dodajEmail()"
                                class="rounded-xl border border-line px-3 py-2.5 text-xs font-semibold text-muted transition hover:border-brand-300 hover:text-brand-600">
                            Dodaj
                        </button>
                    </div>

                    <div x-show="form.emails.length" x-cloak class="mt-2 flex flex-wrap gap-1.5">
                        <template x-for="adres in form.emails" :key="adres">
                            <span class="flex items-center gap-1.5 rounded-lg bg-surface3 px-2 py-1 text-[11px] text-ink2">
                                <span x-text="adres"></span>
                                <button type="button" @click="usunEmail(adres)" class="text-faint transition hover:text-red-600" aria-label="Usuń adres">
                                    <?= icon('close', 'h-3 w-3') ?>
                                </button>
                            </span>
                        </template>
                    </div>

                    <p class="mt-1.5 text-[11px] leading-relaxed text-faint">
                        Panel nie wysyła poczty — adresy zapisujemy jako listę zaproszonych.
                        Link do pokoju skopiujesz przyciskiem <em>Link</em> na karcie spotkania.
                        Do samego pokoju wchodzą wyłącznie osoby zalogowane w panelu.
                    </p>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-faint">Powiąż z folderem</label>
                    <select x-model="form.folder_id"
                            class="w-full rounded-xl border-line bg-surface2 px-3 py-2.5 text-sm text-ink focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900">
                        <option value="">Bez folderu</option>
                        <template x-for="f in folders" :key="f.id">
                            <option :value="f.id" x-text="f.name"></option>
                        </template>
                    </select>
                </div>

                <div x-show="form.room_id" x-cloak class="rounded-xl border border-line bg-surface2 p-3">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-faint">Link do pokoju</p>
                    <div class="mt-1.5 flex items-center gap-2">
                        <code class="min-w-0 flex-1 truncate rounded-lg bg-surface px-2 py-1.5 text-[11px] text-ink2" x-text="linkPokoju(form.room_id)"></code>
                        <button type="button" @click="kopiujLinkSpotkania({ room_id: form.room_id, title: form.title })"
                                class="shrink-0 rounded-lg border border-line p-1.5 text-faint transition hover:border-brand-300 hover:text-brand-600" title="Skopiuj">
                            <?= icon('copy', 'h-3.5 w-3.5') ?>
                        </button>
                    </div>
                </div>
            </div>

            <div class="sticky bottom-0 flex items-center gap-2 border-t border-line bg-surface px-5 py-4">
                <button type="button" @click="form.open = false"
                        class="ml-auto rounded-xl px-4 py-2.5 text-sm font-medium text-muted transition hover:bg-surface3">Anuluj</button>
                <button type="submit" :disabled="form.saving"
                        class="rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lift transition hover:bg-brand-700 disabled:opacity-60">
                    <span x-text="form.saving ? 'Zapisywanie…' : (form.id ? 'Zapisz zmiany' : 'Umów spotkanie')"></span>
                </button>
            </div>
        </form>
    </div>

    <!-- ==================== NOTATKA ZE SPOTKANIA ==================== -->
    <div x-show="notatka.open" x-cloak class="fixed inset-0 z-50 grid place-items-end sm:place-items-center">
        <div x-show="notatka.open" x-transition.opacity @click="zamknijNotatkeSpotkania()" class="absolute inset-0 bg-slate-900/50"></div>

        <div x-show="notatka.open"
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-6 opacity-0 sm:scale-95" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
             class="relative flex max-h-[92vh] w-full max-w-3xl flex-col rounded-t-3xl border border-line bg-surface shadow-lift sm:rounded-3xl">

            <div class="flex items-center gap-3 border-b border-line px-5 py-4">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brandsoft text-brand-500">
                    <?= icon('note', 'h-[18px] w-[18px]') ?>
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-base font-semibold text-ink" x-text="notatka.title"></h2>
                    <p class="truncate text-[11px] text-faint" x-text="notatka.podtytul"></p>
                </div>
                <button @click="zamknijNotatkeSpotkania()" class="rounded-lg p-1.5 text-faint hover:bg-surface3" aria-label="Zamknij">
                    <?= icon('close', 'h-5 w-5') ?>
                </button>
            </div>

            <!-- Bez flex-1: w kolumnie o wysokości „auto” taki element
                 zwija się do zera. Wysokość okna wyznacza edytor. -->
            <div class="min-h-0 overflow-hidden p-5">
                <?= meeting_note_editor('h-[52vh]') ?>
            </div>
        </div>
    </div>

    <!-- ==================== PODGLĄD DOKUMENTU WORDA ==================== -->
    <div x-show="docx.open" x-cloak class="fixed inset-0 z-[56] grid place-items-end sm:place-items-center">
        <div x-show="docx.open" x-transition.opacity @click="docx.open = false" class="absolute inset-0 bg-slate-900/50"></div>

        <div x-show="docx.open"
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-6 opacity-0 sm:scale-95" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
             class="relative m-0 flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-3xl bg-surface shadow-lift sm:m-4 sm:max-w-3xl sm:rounded-3xl">

            <header class="flex items-center gap-3 border-b border-line px-5 py-4">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-[10px] font-bold uppercase"
                      :style="fileBadge('docx')">docx</span>
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-sm font-semibold text-ink" x-text="docx.name"></h2>
                    <p class="text-[11px] text-faint">Podgląd treści — bez obrazów i zaawansowanego formatowania</p>
                </div>
                <a :href="docx.url" title="Pobierz oryginał"
                   class="rounded-lg p-2 text-faint transition hover:bg-surface3 hover:text-brand-600">
                    <?= icon('download', 'h-[18px] w-[18px]') ?>
                </a>
                <button @click="docx.open = false" class="rounded-lg p-2 text-faint hover:bg-surface3" aria-label="Zamknij">
                    <?= icon('close', 'h-[18px] w-[18px]') ?>
                </button>
            </header>

            <div class="thin-scroll min-h-0 flex-1 overflow-y-auto p-5 sm:p-7">
                <div x-show="docx.loading" class="flex flex-col items-center gap-3 py-16">
                    <div class="h-8 w-8 animate-spin rounded-full border-[3px] border-brand-200 border-t-brand-600"></div>
                    <p class="text-sm text-faint">Przetwarzanie dokumentu…</p>
                </div>

                <div x-show="docx.error" x-cloak
                     class="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
                    <p x-text="docx.error"></p>
                    <p class="mt-1.5 text-[13px] opacity-80">Plik możesz zawsze pobrać i otworzyć w Wordzie.</p>
                </div>

                <div x-show="!docx.loading && !docx.error"
                     :class="dark ? 'prose-invert' : ''"
                     class="prose prose-sm prose-slate max-w-none prose-headings:font-semibold prose-a:text-brand-500 prose-img:rounded-lg prose-table:text-[13px]"
                     x-html="docx.html"></div>
            </div>
        </div>
    </div>

    <!-- =================== OKIENKO: NAZWA / POTWIERDZENIE =================== -->
    <div x-show="ask.open" x-cloak class="fixed inset-0 z-[55] grid place-items-center p-4">
        <div x-show="ask.open" x-transition.opacity @click="ask.open = false" class="absolute inset-0 bg-slate-900/50"></div>
        <form @submit.prevent="ask.submit()" x-show="ask.open"
              x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="scale-95 opacity-0" x-transition:enter-end="scale-100 opacity-100"
              class="relative w-full max-w-sm rounded-3xl bg-surface p-6 shadow-lift">
            <h2 class="text-base font-semibold text-ink" x-text="ask.title"></h2>
            <p x-show="ask.message" class="mt-1.5 text-sm leading-relaxed text-muted" x-text="ask.message"></p>

            <input x-show="ask.input" x-model="ask.value" x-ref="askInput" type="text" maxlength="80"
                   class="mt-4 w-full rounded-xl border-line bg-surface2 px-3.5 py-2.5 text-sm text-ink focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:focus:ring-brand-900">

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" @click="ask.open = false"
                        class="rounded-xl px-4 py-2.5 text-sm font-medium text-muted transition hover:bg-surface3">Anuluj</button>
                <button type="submit"
                        :class="ask.danger ? 'bg-red-600 hover:bg-red-700' : 'bg-brand-600 hover:bg-brand-700'"
                        class="rounded-xl px-5 py-2.5 text-sm font-semibold text-white transition" x-text="ask.confirmText"></button>
            </div>
        </form>
    </div>

    <!-- ============================ POWIADOMIENIA ============================ -->
    <div class="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-full max-w-xs flex-col gap-2">
        <template x-for="t in toasts" :key="t.id">
            <div x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-2 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
                 :class="t.type === 'error'
                     ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300'
                     : 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300'"
                 class="pointer-events-auto flex items-start gap-2.5 rounded-xl border px-4 py-3 text-sm shadow-card">
                <span class="mt-0.5 shrink-0">
                    <template x-if="t.type === 'error'"><span><?= icon('close', 'h-4 w-4') ?></span></template>
                    <template x-if="t.type !== 'error'"><span><?= icon('check', 'h-4 w-4') ?></span></template>
                </span>
                <span class="flex-1 leading-snug" x-text="t.text"></span>
                <button @click="toasts = toasts.filter(x => x.id !== t.id)" class="shrink-0 opacity-50 hover:opacity-100" aria-label="Zamknij">
                    <?= icon('close', 'h-3.5 w-3.5') ?>
                </button>
            </div>
        </template>
    </div>
</div>

<script>
    const COLORS  = <?= json_encode(COLORS, JSON_UNESCAPED_UNICODE) ?>;
    const ME      = <?= json_encode($me, JSON_UNESCAPED_UNICODE) ?>;
    const CSRF    = <?= json_encode($csrf) ?>;
    const NEUTRAL = { solid: '#64748b', soft: '#f1f5f9', ink: '#334155', ring: '#e2e8f0', softDark: '#1e293b', inkDark: '#94a3b8' };

    /*
     * Wszystko, co należy do WebRTC, trzyma się poza stanem Alpine.
     * Alpine opakowuje dane w Proxy, a obiekty przeglądarki (MediaStream,
     * RTCPeerConnection, AudioContext) tego nie znoszą — przypisanie
     * opakowanego strumienia do <video> po prostu nie zadziała.
     *
     * Interfejs dowiaduje się o zmianach przez licznik room.strumienTik.
     */
    const MEDIA = {
        local: null,               // strumień wysyłany dalej (mikrofon + ewentualnie kamera)
        mikrofon: null,            // surowy strumień z mikrofonu
        kamera: null,              // surowy strumień z kamery — tylko gdy włączona
        ekran: null,               // strumień z udostępniania ekranu
        ice: [],                   // konfiguracja STUN/TURN z serwera
        zdalne: new Map(),         // peer_id -> MediaStream
        pc: new Map(),             // peer_id -> RTCPeerConnection
        kolejkaIce: new Map(),     // kandydaci, którzy przyszli przed ofertą
        glosy: new Map(),          // peer_id -> czy właśnie mówi
        analizatory: new Map(),    // peer_id -> { kontekst, timer }
        petla: null,               // uchwyt pętli odpytującej
        zegar: null                // uchwyt zegara rozmowy
    };

    document.addEventListener('alpine:init', () => {
        Alpine.data('panel', () => ({

            /* ---------------------------- stan ---------------------------- */
            me: ME,
            csrf: CSRF,
            users: [],
            folders: [],
            activity: [],
            stamp: 0,
            seenStamp: 0,
            limits: { max_upload: 15728640, allowed_ext: ['pdf', 'png', 'jpg', 'jpeg', 'zip', 'docx'], server_upload: 15728640 },

            current: null,
            tasks: [],
            files: [],
            note: { content: '', updated_by_name: null, updated_at: null },

            tab: 'board',
            dark: false,
            sidebarOpen: false,
            feedOpen: false,
            folderQuery: '',
            toasts: [],
            toastSeq: 0,

            dragId: null,
            dragOver: null,
            folderDrag: null,
            folderDragOver: null,

            /* Osobne pole dodawania w każdej kolumnie tablicy. */
            quick: {
                todo:  { title: '', assignee_ids: [], priority: 'normal', due_date: '', open: false },
                doing: { title: '', assignee_ids: [], priority: 'normal', due_date: '', open: false },
                done:  { title: '', assignee_ids: [], priority: 'normal', due_date: '', open: false }
            },

            /* 'folder' — widok folderu, 'mine' — moje zadania zbiorczo,
               'calendar' — terminy wszystkich folderów w układzie miesiąca. */
            view: 'folder',
            mineTasks: [],

            /* Kalendarz. Zadania trzymamy osobno od this.tasks, bo pochodzą
               z wielu folderów naraz i obejmują zakres, a nie jeden folder.
               rok = 0 znaczy „kalendarza jeszcze nie otwierano”. */
            kal: {
                rok: 0,
                miesiac: 0,          // 0 = styczeń, jak w Date
                zadania: [],
                dzien: null,         // rozwinięty dzień, RRRR-MM-DD
                tylkoMoje: false,
                ukryjZrobione: false,
                ladowanie: false
            },

            /* Filtry tablicy — działają po stronie przeglądarki, bez zapytań. */
            filtr: { tekst: '', osoby: [], priorytety: [], tylkoTerminy: false },


            /* ------------------------- spotkania -------------------------- */

            spotkania: [],
            spotkaniaArchiwum: false,

            /* WebRTC i getUserMedia działają wyłącznie w bezpiecznym kontekście.
               Sprawdzamy to raz i mówimy o tym wprost, zamiast pokazywać
               tajemniczy błąd dopiero przy próbie włączenia kamery. */
            bezpiecznyKontekst: true,

            form: {
                open: false, id: null, owner_id: null, room_id: '',
                title: '', description: '', date: '', time: '', duration_min: 30,
                folder_id: '', user_ids: [], emails: [], emailDraft: '', saving: false
            },

            notatka: {
                open: false, meetingId: null, title: '', podtytul: '',
                draft: '', revision: 0, tryb: 'edit', stan: 'idle',
                konflikt: null, updated_by_name: null, updated_at: null, timer: null
            },

            room: {
                open: false, meetingId: null, roomId: '', title: '',
                peerId: '', cursor: 0, peers: [],
                mic: true, cam: false, sharing: false, mozeUdostepniac: false,
                kameraCzeka: false, mikrofonCzeka: false,
                polaczenie: 'laczenie', statusTekst: 'Łączenie…', ostrzezenie: '',
                czas: '', listaOpen: false, notatkiOpen: false,
                strumienTik: 0, hasTurn: false, start: 0
            },

            comments: [],
            commentDraft: '',
            commentsLoading: false,
            commentSaving: false,

            noteDraft: '',
            noteMode: 'view',
            noteDirty: false,
            noteSaving: false,

            dropActive: false,
            uploading: false,
            uploadPct: 0,
            uploadName: '',
            taskUploading: false,
            taskUploadPct: 0,
            filePickerOpen: false,
            metodaWysylki: null,   // zapamiętana metoda, która działa na tym hostingu

            task: { open: false, id: null, title: '', description: '', status: 'todo', priority: 'normal', due_date: '', assignee_ids: [], saving: false, meta: {} },
            docx: { open: false, name: '', url: '', html: '', loading: false, error: '' },
            ask: { open: false, input: true, title: '', message: '', value: '', confirmText: 'Zapisz', danger: false, onOk: null, submit() {} },

            columns: [
                { key: 'todo',  label: 'Do zrobienia', dot: '#94a3b8' },
                { key: 'doing', label: 'W trakcie',    dot: '#6366f1' },
                { key: 'done',  label: 'Zrobione',     dot: '#10b981' }
            ],

            priorities: [
                { key: 'high',   label: 'Wysoki' },
                { key: 'normal', label: 'Normalny' },
                { key: 'low',    label: 'Niski' }
            ],

            /* --------------------------- start --------------------------- */
            async init() {
                this.dark = document.documentElement.classList.contains('dark');
                this.bezpiecznyKontekst = !!window.isSecureContext;
                try { this.metodaWysylki = localStorage.getItem('panel.wysylka'); } catch (e) {}

                this.ask.submit = () => {
                    const fn = this.ask.onOk;
                    const value = this.ask.value;
                    this.ask.open = false;
                    if (fn) fn(value);
                };

                try {
                    const d = await this.api('bootstrap');
                    this.users     = d.users;
                    this.folders   = d.folders;
                    this.spotkania = d.meetings || [];
                    this.activity  = d.activity;
                    this.stamp    = d.stamp;
                    this.limits   = d.limits;
                    this.csrf     = d.csrf;
                    this.seenStamp = Number(localStorage.getItem('panel.seen') || 0) || this.stamp;

                    const remembered = Number(localStorage.getItem('panel.folder') || 0);
                    const target = this.folders.find(f => f.id === remembered) || this.folders[0];
                    if (target) await this.loadFolder(target.id);

                    await this.odswiezMoje();

                    /* Wejście z linku „…?room=abc-defg-hij” prowadzi prosto
                       do pokoju, o ile jest jeszcze otwarty. */
                    const zAdresu = new URLSearchParams(location.search).get('room');
                    if (zAdresu) {
                        history.replaceState({}, '', location.pathname);
                        await this.otworzZLinku(zAdresu);
                    }
                } catch (e) {
                    this.toast(e.message, 'error');
                } finally {
                    document.body.classList.add('ready');
                }

                this.$watch('feedOpen', open => {
                    if (open) {
                        this.seenStamp = this.stamp;
                        localStorage.setItem('panel.seen', String(this.stamp));
                    }
                });

                setInterval(() => this.poll(), 30000);

                window.addEventListener('beforeunload', e => {
                    if (this.noteDirty || this.room.open) { e.preventDefault(); e.returnValue = ''; }
                });

                /* Zamknięcie karty w trakcie rozmowy: zdejmujemy siebie z listy
                   obecnych od razu, żeby pozostali nie patrzyli w martwy kafelek
                   przez kolejne dwadzieścia sekund. keepalive pozwala żądaniu
                   dojść, mimo że strona już się zamyka. */
                window.addEventListener('pagehide', () => {
                    if (!this.room.open || !this.room.peerId) return;
                    try {
                        fetch('api.php?action=meeting.leave', {
                            method: 'POST',
                            keepalive: true,
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                            body: JSON.stringify({ peer_id: this.room.peerId })
                        });
                    } catch (e) {}
                });
            },

            /* --------------------------- motyw ---------------------------- */
            toggleTheme() {
                const root = document.documentElement;

                root.classList.add('motyw-zmiana');
                this.dark = !this.dark;
                root.classList.toggle('dark', this.dark);
                void root.offsetHeight;              // wymuszamy natychmiastowe przeliczenie
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => root.classList.remove('motyw-zmiana'));
                });

                try { localStorage.setItem('panel.motyw', this.dark ? 'dark' : 'light'); } catch (e) {}
            },

            /* -------------------------- właściwości ----------------------- */
            get visibleFolders() {
                const q = this.folderQuery.trim().toLowerCase();
                return q ? this.folders.filter(f => f.name.toLowerCase().includes(q)) : this.folders;
            },

            get unseen() {
                return this.activity.filter(a => a.id > this.seenStamp).length;
            },

            get filtrAktywny() {
                const f = this.filtr;
                return f.tekst.trim() !== '' || f.osoby.length > 0 || f.priorytety.length > 0 || f.tylkoTerminy;
            },

            get mineCount() {
                return this.mineTasks.length;
            },

            get mineOverdue() {
                return this.mineTasks.filter(t => this.terminMinal(t.due_date, t.status)).length;
            },

            /**
             * Moje zadania w grupach według pilności terminu — od zaległych
             * po te bez terminu. Puste grupy nie są pokazywane.
             */
            get mineGrouped() {
                const grupy = [
                    { klucz: 'po',      etykieta: 'Po terminie',   pilne: true,  zadania: [] },
                    { klucz: 'dzis',    etykieta: 'Na dziś',       pilne: true,  zadania: [] },
                    { klucz: 'tydzien', etykieta: 'Najbliższe dni', pilne: false, zadania: [] },
                    { klucz: 'pozniej', etykieta: 'Później',       pilne: false, zadania: [] },
                    { klucz: 'brak',    etykieta: 'Bez terminu',   pilne: false, zadania: [] }
                ];

                for (const t of this.mineTasks) {
                    const dni = this.dniDoTerminu(t.due_date);
                    if (dni === null)     grupy[4].zadania.push(t);
                    else if (dni < 0)     grupy[0].zadania.push(t);
                    else if (dni === 0)   grupy[1].zadania.push(t);
                    else if (dni <= 7)    grupy[2].zadania.push(t);
                    else                  grupy[3].zadania.push(t);
                }

                const waga = { high: 0, normal: 1, low: 2 };
                for (const g of grupy) {
                    g.zadania.sort((a, b) => {
                        if (a.due_date && b.due_date && a.due_date !== b.due_date) {
                            return a.due_date < b.due_date ? -1 : 1;
                        }
                        return (waga[a.priority] ?? 1) - (waga[b.priority] ?? 1);
                    });
                }
                return grupy.filter(g => g.zadania.length);
            },

            /* ----------------------- kalendarz ---------------------------- */

            /** Nagłówek miesiąca, np. „Sierpień 2026”. */
            get kalTytul() {
                if (!this.kal.rok) return '';
                const nazwa = new Date(this.kal.rok, this.kal.miesiac, 1)
                    .toLocaleDateString('pl-PL', { month: 'long', year: 'numeric' });
                return nazwa.charAt(0).toUpperCase() + nazwa.slice(1);
            },

            /** Poniedziałek otwierający siatkę — bywa w poprzednim miesiącu. */
            get kalStart() {
                const pierwszy = new Date(this.kal.rok, this.kal.miesiac, 1, 12);
                const doTylu = (pierwszy.getDay() + 6) % 7;      // 0 = poniedziałek
                pierwszy.setDate(pierwszy.getDate() - doTylu);
                return pierwszy;
            },

            /** Ile wierszy zajmie miesiąc — 4, 5 albo 6, bez pustego zapasu. */
            get kalTygodnie() {
                const dni = new Date(this.kal.rok, this.kal.miesiac + 1, 0).getDate();
                const doTylu = (new Date(this.kal.rok, this.kal.miesiac, 1).getDay() + 6) % 7;
                return Math.ceil((doTylu + dni) / 7);
            },

            /** Zadania po odjęciu tego, co ukryły przełączniki nad kalendarzem. */
            get kalWidoczne() {
                return this.kal.zadania.filter(t => {
                    if (this.kal.ukryjZrobione && t.status === 'done') return false;
                    if (this.kal.tylkoMoje && !t.assignees.some(a => a.id === this.me.id)) return false;
                    return true;
                });
            },

            get kalPoTerminie() {
                return this.kalWidoczne.filter(t => this.terminMinal(t.due_date, t.status)).length;
            },

            /** Mapa data → zadania tego dnia, uporządkowane wewnątrz dnia. */
            get kalWedlugDni() {
                const waga = { high: 0, normal: 1, low: 2 };
                const mapa = {};

                for (const t of this.kalWidoczne) {
                    if (!mapa[t.due_date]) mapa[t.due_date] = [];
                    mapa[t.due_date].push(t);
                }

                for (const klucz in mapa) {
                    mapa[klucz].sort((a, b) => {
                        /* Zrobione spadają na dół dnia — zostają dla porządku,
                           ale nie przesłaniają tego, co wciąż czeka. */
                        const zrA = a.status === 'done' ? 1 : 0;
                        const zrB = b.status === 'done' ? 1 : 0;
                        if (zrA !== zrB) return zrA - zrB;

                        const roznica = (waga[a.priority] ?? 1) - (waga[b.priority] ?? 1);
                        return roznica !== 0 ? roznica : a.id - b.id;
                    });
                }
                return mapa;
            },

            /** Kratki siatki: pełne tygodnie od poniedziałku. */
            get kalSiatka() {
                if (!this.kal.rok) return [];

                const start = this.kalStart;
                const ile   = this.kalTygodnie * 7;
                const dzis  = this.dataZa(0);
                const wgDni = this.kalWedlugDni;

                const out = [];
                for (let i = 0; i < ile; i++) {
                    const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i, 12);
                    const iso = this.isoZDaty(d);
                    out.push({
                        iso,
                        numer: d.getDate(),
                        wMiesiacu: d.getMonth() === this.kal.miesiac,
                        dzis: iso === dzis,
                        zadania: wgDni[iso] || []
                    });
                }
                return out;
            },

            /** Widok na telefon: same dni miesiąca, w których coś wypada. */
            get kalAgenda() {
                return this.kalSiatka
                    .filter(c => c.wMiesiacu && c.zadania.length)
                    .map(c => ({ ...c, naglowek: this.kalOpisDnia(c.iso) }));
            },

            get kalZadaniaDnia() {
                if (!this.kal.dzien) return [];
                return this.kalWedlugDni[this.kal.dzien] || [];
            },

            get kalNaglowekDnia() {
                return this.kal.dzien ? this.kalOpisDnia(this.kal.dzien) : '';
            },


            /* ------------------------- spotkania -------------------------- */

            get spotkaniaTrwajace() {
                return this.spotkania.filter(m => m.status === 'live');
            },

            /** Wszystko, co jeszcze przed nami: zaplanowane, wkrótce i otwarte. */
            get spotkaniaNadchodzace() {
                return this.spotkania
                    .filter(m => ['scheduled', 'soon', 'open'].includes(m.status))
                    .sort((a, b) => String(a.starts_at_local).localeCompare(String(b.starts_at_local)));
            },

            get spotkaniaZakonczone() {
                return this.spotkania
                    .filter(m => ['ended', 'cancelled'].includes(m.status))
                    .sort((a, b) => String(b.starts_at_local).localeCompare(String(a.starts_at_local)));
            },

            /** Sekcje listy. Archiwum pokazujemy dopiero na życzenie. */
            get spotkaniaWidoczne() {
                const grupy = [
                    { klucz: 'live',      etykieta: 'W trakcie',    spotkania: this.spotkaniaTrwajace },
                    { klucz: 'upcoming',  etykieta: 'Nadchodzące',  spotkania: this.spotkaniaNadchodzace }
                ];
                if (this.spotkaniaArchiwum) {
                    grupy.push({ klucz: 'archive', etykieta: 'Zakończone', spotkania: this.spotkaniaZakonczone });
                }
                return grupy.filter(g => g.spotkania.length);
            },

            /** Siatka kafelków rośnie z liczbą osób, ale nie robi się drobna. */
            get ukladKafelkow() {
                const ile = this.room.peers.length;
                if (ile <= 1) return 'grid-cols-1';
                if (ile <= 4) return 'grid-cols-1 sm:grid-cols-2';
                return 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-3';
            },

            /** Załączniki podpięte pod zadanie otwarte w oknie szczegółów. */
            get taskFiles() {
                if (!this.task.id) return [];
                return this.files.filter(f => f.task_id === this.task.id);
            },

            /** Pozostałe pliki folderu — te, które można podpiąć pod to zadanie. */
            get attachableFiles() {
                if (!this.task.id) return [];
                const inne = this.files.filter(f => f.task_id !== this.task.id);
                /* Najpierw luźne pliki folderu, potem te zajęte przez inne zadania. */
                return inne.sort((a, b) => (a.task_id ? 1 : 0) - (b.task_id ? 1 : 0));
            },

            /* ---------------------------- API ----------------------------- */
            async api(action, payload = null, method = 'GET', query = '') {
                const options = { method, headers: {}, credentials: 'same-origin' };
                if (method !== 'GET') {
                    options.headers['Content-Type'] = 'application/json';
                    options.headers['X-CSRF-Token'] = this.csrf;
                    options.body = JSON.stringify(payload || {});
                }

                let response;
                try {
                    response = await fetch('api.php?action=' + encodeURIComponent(action) + query, options);
                } catch (e) {
                    throw new Error('Brak połączenia z serwerem.');
                }

                const raw = await response.text();
                let data;
                try {
                    data = JSON.parse(raw);
                } catch (e) {
                    throw new Error('Serwer zwrócił nieoczekiwaną odpowiedź (HTTP ' + response.status + ').');
                }

                if (!response.ok || data.ok === false) {
                    if (response.status === 401) {
                        window.location.reload();
                    }
                    throw new Error(data.error || ('Błąd serwera (HTTP ' + response.status + ').'));
                }
                return data;
            },

            /** Wstawia do stanu te fragmenty odpowiedzi, które przyszły z serwera. */
            apply(d) {
                if (d.folders)  this.folders  = d.folders;
                if (d.meetings) this.spotkania = d.meetings;
                if (d.files)    this.files    = d.files;
                if (d.tasks) {
                    this.tasks = d.tasks;
                    /* Licznik „Moje zadania” i widoczny kalendarz muszą nadążać
                       za zmianami — odświeżamy je w tle, bez blokowania akcji.
                       Kalendarz tylko wtedy, gdy jest na ekranie: przy wejściu
                       w ten widok i tak pobiera dane od nowa. */
                    this.odswiezMoje();
                    if (this.view === 'calendar') this.kalWczytaj(true);
                }
                if (d.activity) {
                    this.activity = d.activity;
                    if (d.activity.length) this.stamp = d.activity[0].id;
                }
                if (d.note) {
                    this.note = d.note;
                    if (!this.noteDirty) this.noteDraft = d.note.content;
                }
            },

            async poll() {
                if (document.hidden || this.uploading) return;
                try {
                    const d = await this.api('ping');
                    if (d.stamp !== this.stamp) await this.refresh();
                } catch (e) { /* sieć chwilowo niedostępna — próbujemy przy następnym cyklu */ }
            },

            async refresh() {
                const d = await this.api('bootstrap');
                this.folders   = d.folders;
                this.spotkania = d.meetings || this.spotkania;
                this.activity  = d.activity;
                this.stamp     = d.stamp;

                await this.odswiezMoje();
                if (this.view === 'calendar') await this.kalWczytaj(true);

                if (this.current) {
                    if (this.folders.some(f => f.id === this.current.id)) {
                        await this.loadFolder(this.current.id, true);
                    } else {
                        this.current = null;
                        this.tasks = [];
                        this.files = [];
                    }
                }
            },

            /* --------------------------- foldery -------------------------- */
            async loadFolder(id, quiet = false) {
                try {
                    const d = await this.api('folder.open', null, 'GET', '&id=' + encodeURIComponent(id));
                    const zmianaFolderu = !this.current || this.current.id !== id;

                    this.current = d.folder;
                    this.tasks   = d.tasks;
                    this.files   = d.files;
                    this.note    = d.note;
                    if (!this.noteDirty) this.noteDraft = d.note.content;

                    /* Notatkę pokazujemy domyślnie w podglądzie, żeby treść była widoczna
                       od razu; do edycji przełącza się ręcznie. Pustą otwieramy w edycji. */
                    if (zmianaFolderu) {
                        this.noteMode = (d.note.content || '').trim() ? 'view' : 'edit';
                    }

                    localStorage.setItem('panel.folder', String(id));
                } catch (e) {
                    if (!quiet) this.toast(e.message, 'error');
                }
            },

            async selectFolder(id) {
                const tenSam = this.view === 'folder' && this.current && this.current.id === id;
                this.view = 'folder';
                if (tenSam) { this.sidebarOpen = false; return; }
                if (this.noteDirty) await this.saveNote(true);
                await this.loadFolder(id);
                this.sidebarOpen = false;
            },

            /* ------------------- widok „Moje zadania” --------------------- */

            async pokazMoje() {
                this.view = 'mine';
                this.sidebarOpen = false;
                if (this.noteDirty) await this.saveNote(true);
                await this.odswiezMoje();
            },

            async odswiezMoje(quiet = true) {
                try {
                    const d = await this.api('task.mine');
                    this.mineTasks = d.tasks;
                } catch (e) {
                    if (!quiet) this.toast(e.message, 'error');
                }
            },

            /**
             * Zadanie z listy zbiorczej (moje zadania, kalendarz) otwieramy w jego
             * własnym folderze — dzięki temu okno ma komplet kontekstu:
             * załączniki, komentarze i pliki, które można pod nie podpiąć.
             */
            async otworzZListy(t) {
                this.view = 'folder';
                await this.loadFolder(t.folder_id);
                const swieze = this.tasks.find(x => x.id === t.id);
                if (swieze) {
                    this.tab = 'board';
                    this.openTask(swieze);
                } else {
                    this.toast('Tego zadania już nie ma.', 'error');
                }
            },

            /* ------------------------- kalendarz -------------------------- */

            async pokazKalendarz() {
                this.view = 'calendar';
                this.sidebarOpen = false;
                if (this.noteDirty) await this.saveNote(true);

                if (!this.kal.rok) {
                    const teraz = new Date();
                    this.kal.rok = teraz.getFullYear();
                    this.kal.miesiac = teraz.getMonth();
                }
                await this.kalWczytaj();
            },

            /**
             * Pobiera zadania na cały widoczny zakres — łącznie z dniami
             * z sąsiednich miesięcy, które dopełniają pierwszy i ostatni
             * tydzień siatki.
             */
            async kalWczytaj(quiet = false) {
                if (!this.kal.rok) return;

                const start = this.kalStart;
                const koniec = new Date(
                    start.getFullYear(), start.getMonth(),
                    start.getDate() + this.kalTygodnie * 7 - 1, 12
                );

                this.kal.ladowanie = true;
                try {
                    const d = await this.api('task.calendar', null, 'GET',
                        '&from=' + this.isoZDaty(start) + '&to=' + this.isoZDaty(koniec));
                    this.kal.zadania = d.tasks;
                } catch (e) {
                    if (!quiet) this.toast(e.message, 'error');
                } finally {
                    this.kal.ladowanie = false;
                }
            },

            kalPrzesun(krok) {
                const d = new Date(this.kal.rok, this.kal.miesiac + krok, 1);
                this.kal.rok = d.getFullYear();
                this.kal.miesiac = d.getMonth();
                this.kal.dzien = null;
                this.kalWczytaj();
            },

            kalDzis() {
                const teraz = new Date();
                this.kal.rok = teraz.getFullYear();
                this.kal.miesiac = teraz.getMonth();
                this.kal.dzien = this.dataZa(0);
                this.kalWczytaj();
            },

            /** Kliknięcie w kratkę rozwija dzień; ponowne kliknięcie zwija. */
            kalWybierzDzien(iso) {
                this.kal.dzien = this.kal.dzien === iso ? null : iso;
            },

            /** Opis dnia w nagłówku, np. „poniedziałek, 18 sierpnia”. */
            kalOpisDnia(iso) {
                return new Date(iso + 'T12:00:00')
                    .toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long' });
            },

            /** Pasek zadania w kratce — ten sam język kolorów co plakietki terminu. */
            kalChipStyle(t) {
                const dni = this.dniDoTerminu(t.due_date);

                let paleta;
                if (t.status === 'done')          paleta = { jasny: ['#f1f5f9', '#475569'], ciemny: ['#1e293b', '#94a3b8'] };
                else if (dni !== null && dni < 0) paleta = { jasny: ['#fee2e2', '#b91c1c'], ciemny: ['#450a0a', '#fca5a5'] };
                else if (dni === 0)               paleta = { jasny: ['#fef3c7', '#b45309'], ciemny: ['#451a03', '#fcd34d'] };
                else                              paleta = { jasny: ['#eef2ff', '#4338ca'], ciemny: ['#1e1b4b', '#a5b4fc'] };

                const [tlo, tekst] = this.dark ? paleta.ciemny : paleta.jasny;
                return 'background:' + tlo + ';color:' + tekst;
            },

            /** Kropka przy pasku — kolor pierwszej osoby odpowiedzialnej. */
            kalKropka(t) {
                return t.assignees.length ? this.accent(t.assignees[0].color).solid : '#94a3b8';
            },


            /* ===================== SPOTKANIA — lista ====================== */

            async pokazSpotkania() {
                this.view = 'meetings';
                this.sidebarOpen = false;
                if (this.noteDirty) await this.saveNote(true);
                await this.odswiezSpotkania(false);
            },

            async odswiezSpotkania(quiet = true) {
                try {
                    const d = await this.api('meeting.list');
                    this.spotkania = d.meetings;
                } catch (e) {
                    if (!quiet) this.toast(e.message, 'error');
                }
            },

            znajdzSpotkanie(id) {
                return this.spotkania.find(m => m.id === id) || null;
            },

            /**
             * Wejście z linku „…?room=abc-defg-hij”. Link nie omija logowania:
             * do tego miejsca dochodzą wyłącznie zalogowane osoby, bo cały
             * panel jest za formularzem logowania.
             */
            async otworzZLinku(roomId) {
                try {
                    const d = await this.api('meeting.open', null, 'GET', '&room=' + encodeURIComponent(roomId));
                    this.view = 'meetings';
                    await this.odswiezSpotkania();

                    if (d.meeting.can_join) {
                        await this.wejdzDoPokoju(d.meeting);
                    } else {
                        this.toast(d.meeting.join_hint || 'Ten pokój jest zamknięty.', 'error');
                    }
                } catch (e) {
                    this.toast(e.message, 'error');
                }
            },

            /* --------------------------- formularz ------------------------ */

            nowSpotkanie() {
                const teraz = new Date();
                teraz.setMinutes(teraz.getMinutes() + 30 - (teraz.getMinutes() % 15), 0, 0);

                this.form = {
                    open: true, id: null, owner_id: this.me.id, room_id: '',
                    title: '', description: '',
                    date: this.isoZDaty(teraz),
                    time: this.godzinaZDaty(teraz),
                    duration_min: 30,
                    folder_id: this.view === 'folder' && this.current ? String(this.current.id) : '',
                    user_ids: [this.me.id], emails: [], emailDraft: '', saving: false
                };
            },

            edytujSpotkanie(m) {
                const [data, godzina] = String(m.starts_at_local || '').split(' ');

                this.form = {
                    open: true, id: m.id, owner_id: m.created_by, room_id: m.room_id,
                    title: m.title, description: m.description || '',
                    date: data || '', time: (godzina || '').slice(0, 5),
                    duration_min: m.duration_min,
                    folder_id: m.folder_id ? String(m.folder_id) : '',
                    user_ids: m.participants.filter(u => u.user_id).map(u => u.user_id),
                    emails: m.participants.filter(u => u.email).map(u => u.email),
                    emailDraft: '', saving: false
                };
            },

            async zapiszSpotkanie() {
                if (this.form.saving) return;

                if (!this.form.title.trim()) { this.toast('Podaj temat spotkania.', 'error'); return; }
                if (!this.form.date)         { this.toast('Podaj datę spotkania.', 'error'); return; }
                if (!this.form.time)         { this.toast('Podaj godzinę spotkania.', 'error'); return; }

                /* Adres wpisany, ale niezatwierdzony Enterem, i tak ma trafić na listę —
                   inaczej użytkownik traci go bez ostrzeżenia. */
                if (this.form.emailDraft.trim()) this.dodajEmail();

                const dane = {
                    title: this.form.title,
                    description: this.form.description,
                    date: this.form.date,
                    time: this.form.time,
                    duration_min: Number(this.form.duration_min) || 30,
                    folder_id: this.form.folder_id === '' ? null : Number(this.form.folder_id),
                    user_ids: this.form.user_ids,
                    emails: this.form.emails
                };

                this.form.saving = true;
                try {
                    if (this.form.id) {
                        dane.id = this.form.id;
                        this.apply(await this.api('meeting.update', dane, 'POST'));
                        this.toast('Spotkanie zaktualizowane.');
                    } else {
                        this.apply(await this.api('meeting.create', dane, 'POST'));
                        this.toast('Spotkanie umówione.');
                    }
                    this.form.open = false;
                } catch (e) {
                    this.toast(e.message, 'error');
                } finally {
                    this.form.saving = false;
                }
            },

            usunSpotkanie(m) {
                this.confirm({
                    title: 'Usunąć spotkanie?',
                    message: 'Zniknie razem z notatką i listą uczestników. Tego nie da się cofnąć.',
                    confirmText: 'Usuń',
                    onOk: async () => {
                        try {
                            this.apply(await this.api('meeting.delete', { id: m.id }, 'POST'));
                            this.toast('Spotkanie usunięte.');
                        } catch (e) { this.toast(e.message, 'error'); }
                    }
                });
            },

            async zmienStatusSpotkania(m, status) {
                try {
                    this.apply(await this.api('meeting.update', { id: m.id, status }, 'POST'));
                    this.toast(status === 'cancelled' ? 'Spotkanie odwołane.' : 'Spotkanie przywrócone.');
                } catch (e) { this.toast(e.message, 'error'); }
            },

            przelaczUczestnika(id) {
                /* Twórcy nie da się usunąć — i tak zostanie dopisany po stronie serwera. */
                if (id === (this.form.owner_id || this.me.id)) return;

                const i = this.form.user_ids.indexOf(id);
                if (i === -1) this.form.user_ids.push(id);
                else this.form.user_ids.splice(i, 1);
            },

            dodajEmail() {
                const adres = this.form.emailDraft.trim().toLowerCase();
                if (!adres) return;

                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(adres)) {
                    this.toast('To nie wygląda na adres e-mail.', 'error');
                    return;
                }
                if (!this.form.emails.includes(adres)) this.form.emails.push(adres);
                this.form.emailDraft = '';
            },

            usunEmail(adres) {
                this.form.emails = this.form.emails.filter(a => a !== adres);
            },

            /* --------------------- skróty terminu ------------------------- */

            godzinaZDaty(d) {
                return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
            },

            terminSpotkaniaZa(minut) {
                const d = new Date();
                d.setMinutes(d.getMinutes() + minut, 0, 0);
                this.form.date = this.isoZDaty(d);
                this.form.time = this.godzinaZDaty(d);
            },

            terminSpotkaniaJutro() {
                const d = new Date();
                d.setDate(d.getDate() + 1);
                d.setHours(9, 0, 0, 0);
                this.form.date = this.isoZDaty(d);
                this.form.time = '09:00';
            },

            /* --------------------------- prezentacja ---------------------- */

            linkPokoju(roomId) {
                return location.origin + location.pathname + '?room=' + encodeURIComponent(roomId);
            },

            async kopiujLinkSpotkania(m) {
                const link = this.linkPokoju(m.room_id);
                try {
                    await navigator.clipboard.writeText(link);
                    this.toast('Link skopiowany do schowka.');
                } catch (e) {
                    /* Schowek bywa zablokowany (brak HTTPS, odmowa uprawnień) —
                       wtedy pokazujemy link do ręcznego skopiowania. */
                    this.prompt({
                        title: 'Link do pokoju',
                        message: 'Skopiuj i wyślij pozostałym uczestnikom.',
                        value: link,
                        confirmText: 'Gotowe',
                        onOk: () => {}
                    });
                }
            },

            /** „dziś 14:30”, „jutro 9:00”, „pt, 22 sie, 10:00”. */
            terminSpotkania(m) {
                const surowa = String(m.starts_at_local || '');
                const [data, godzina] = surowa.split(' ');
                const hhmm = (godzina || '').slice(0, 5);
                const dni = this.dniDoTerminu(data);

                if (dni === 0)  return 'dziś ' + hhmm;
                if (dni === 1)  return 'jutro ' + hhmm;
                if (dni === -1) return 'wczoraj ' + hhmm;

                const opis = new Date(data + 'T12:00:00')
                    .toLocaleDateString('pl-PL', { weekday: 'short', day: 'numeric', month: 'short' });
                return opis + ', ' + hhmm;
            },

            statusSpotkaniaNazwa(status) {
                const nazwy = {
                    live: 'trwa', open: 'pokój otwarty', soon: 'wkrótce',
                    scheduled: 'zaplanowane', ended: 'zakończone', cancelled: 'odwołane'
                };
                return nazwy[status] || status;
            },

            statusSpotkaniaStyl(status) {
                const paleta = {
                    live:      { jasny: ['#ecfdf5', '#047857'], ciemny: ['#022c22', '#6ee7b7'] },
                    open:      { jasny: ['#eef2ff', '#4338ca'], ciemny: ['#1e1b4b', '#a5b4fc'] },
                    soon:      { jasny: ['#fffbeb', '#b45309'], ciemny: ['#451a03', '#fcd34d'] },
                    scheduled: { jasny: ['#f1f5f9', '#475569'], ciemny: ['#334155', '#cbd5e1'] },
                    ended:     { jasny: ['#f1f5f9', '#475569'], ciemny: ['#334155', '#cbd5e1'] },
                    cancelled: { jasny: ['#fef2f2', '#b91c1c'], ciemny: ['#450a0a', '#fca5a5'] }
                };
                const c = paleta[status] || paleta.scheduled;
                const [tlo, tekst] = this.dark ? c.ciemny : c.jasny;
                return 'background:' + tlo + ';color:' + tekst;
            },

            odmianaSpotkan(n) {
                if (n === 1) return '1 spotkanie';
                const setki = n % 100;
                const jednosci = n % 10;
                return (jednosci >= 2 && jednosci <= 4 && (setki < 12 || setki > 14))
                    ? n + ' spotkania'
                    : n + ' spotkań';
            },

            odmianaOsob(n) {
                if (n === 1) return '1 osoba';
                const setki = n % 100;
                const jednosci = n % 10;
                return (jednosci >= 2 && jednosci <= 4 && (setki < 12 || setki > 14))
                    ? n + ' osoby'
                    : n + ' osób';
            },

            /* ================= SPOTKANIA — notatka ======================== */

            async otworzNotatkeSpotkania(m) {
                this.notatka.open = true;
                this.notatka.meetingId = m.id;
                this.notatka.title = m.title;
                this.notatka.podtytul = this.terminSpotkania(m) + ' · ' + m.duration_min + ' min';
                this.notatka.konflikt = null;
                this.notatka.stan = 'idle';

                await this.wczytajNotatke(m.id);

                /* Pusta notatka otwiera się od razu do pisania, wypełniona
                   w podglądzie — tak samo jak notatka folderu. */
                this.notatka.tryb = this.notatka.draft.trim() ? 'view' : 'edit';
            },

            async wczytajNotatke(meetingId) {
                try {
                    const d = await this.api('meeting.open', null, 'GET', '&id=' + encodeURIComponent(meetingId));
                    this.przyjmijNotatke(d.note);
                } catch (e) {
                    this.toast(e.message, 'error');
                }
            },

            przyjmijNotatke(note) {
                this.notatka.draft = note.content || '';
                this.notatka.revision = note.revision || 0;
                this.notatka.updated_by_name = note.updated_by_name;
                this.notatka.updated_at = note.updated_at;
            },

            async zamknijNotatkeSpotkania() {
                /* Zamknięcie okna nie może zgubić tego, co czeka na autozapis. */
                if (this.notatka.stan === 'dirty') {
                    clearTimeout(this.notatka.timer);
                    await this.zapiszNotatke();
                }
                this.notatka.open = false;
                await this.odswiezSpotkania();
            },

            /** Każde uderzenie w klawisz odsuwa zapis; zapisujemy po chwili ciszy. */
            notatkaZmieniona() {
                this.notatka.stan = 'dirty';
                clearTimeout(this.notatka.timer);
                this.notatka.timer = setTimeout(() => this.zapiszNotatke(), 1200);
            },

            async zapiszNotatke(force = false) {
                if (!this.notatka.meetingId) return;
                if (this.notatka.stan === 'saving') return;

                this.notatka.stan = 'saving';
                try {
                    const d = await this.api('meeting.note', {
                        meeting_id: this.notatka.meetingId,
                        content: this.notatka.draft,
                        revision: this.notatka.revision,
                        force: force
                    }, 'POST');

                    if (d.saved) {
                        this.notatka.revision = d.note.revision;
                        this.notatka.updated_by_name = d.note.updated_by_name;
                        this.notatka.updated_at = d.note.updated_at;
                        this.notatka.konflikt = null;
                        this.notatka.stan = 'saved';
                    } else {
                        /* Ktoś zapisał swoją wersję w międzyczasie. Jeśli sami nic
                           nie zmienialiśmy, po prostu przyjmujemy jego tekst —
                           dzięki temu notatka jest żywa dla wszystkich patrzących.
                           Gdy mamy własne zmiany, decyzję zostawiamy człowiekowi. */
                        const nasze = this.notatka.draft;
                        if (nasze === '' || nasze === d.note.content) {
                            this.przyjmijNotatke(d.note);
                            this.notatka.stan = 'saved';
                        } else {
                            this.notatka.konflikt = d.note;
                            this.notatka.stan = 'error';
                        }
                    }
                } catch (e) {
                    this.notatka.stan = 'error';
                    this.toast('Nie udało się zapisać notatki: ' + e.message, 'error');
                }
            },

            zapiszNotatkeSilowo() {
                this.notatka.revision = this.notatka.konflikt ? this.notatka.konflikt.revision : this.notatka.revision;
                this.notatka.konflikt = null;
                this.zapiszNotatke(true);
            },

            wczytajWersjeSerwera() {
                if (!this.notatka.konflikt) return;
                this.przyjmijNotatke(this.notatka.konflikt);
                this.notatka.konflikt = null;
                this.notatka.stan = 'saved';
            },

            notatkaStanTekst() {
                const stany = {
                    idle:   'autozapis włączony',
                    dirty:  'niezapisane zmiany…',
                    saving: 'zapisywanie…',
                    saved:  'zapisano',
                    error:  'zapis się nie powiódł'
                };
                return stany[this.notatka.stan] || '';
            },

            /** Notatka w pokoju odświeża się w tle, żeby wszyscy widzieli to samo. */
            async odswiezNotatkeWTle() {
                if (!this.notatka.meetingId) return;
                if (this.notatka.stan === 'dirty' || this.notatka.stan === 'saving') return;
                if (this.notatka.konflikt) return;

                try {
                    const d = await this.api('meeting.open', null, 'GET',
                        '&id=' + encodeURIComponent(this.notatka.meetingId));
                    if (d.note.revision !== this.notatka.revision) {
                        this.przyjmijNotatke(d.note);
                    }
                } catch (e) { /* przy następnym cyklu */ }
            },

            /* ==================== POKÓJ WIDEO (WebRTC) ==================== *
             *
             * Obraz i dźwięk idą bezpośrednio między przeglądarkami — serwer
             * pośredniczy wyłącznie w nawiązaniu połączenia. Skrzynka na
             * wiadomości sygnalizacyjne leży w bazie, a przeglądarki
             * odpytują ją przez api.php; hosting współdzielony nie daje
             * WebSocketów, a przy czteroosobowym zespole odpytywanie co
             * sekundę jest w zupełności wystarczające.
             *
             * Połączenie każdy z każdym (siatka): przy czterech osobach to
             * trzy strumienie wychodzące na osobę — bez serwera przekazującego.
             * ------------------------------------------------------------- */

            async wejdzDoPokoju(m) {
                if (this.room.open) return;

                if (!window.isSecureContext) {
                    this.toast('Wideorozmowy wymagają HTTPS. Włącz certyfikat SSL w panelu hostingu.', 'error');
                    return;
                }
                if (!navigator.mediaDevices || !window.RTCPeerConnection) {
                    this.toast('Ta przeglądarka nie obsługuje wideorozmów.', 'error');
                    return;
                }

                this.room.open = true;
                this.room.meetingId = m.id;
                this.room.roomId = m.room_id;
                this.room.title = m.title;
                this.room.peerId = this.nowyPeerId();
                this.room.peers = [];
                this.room.cursor = 0;
                this.room.mic = true;
                this.room.cam = false;      // kamerę włącza się świadomie, przyciskiem
                this.room.sharing = false;
                this.room.kameraCzeka = false;
                this.room.mikrofonCzeka = false;
                this.room.ostrzezenie = '';
                this.room.polaczenie = 'laczenie';
                this.room.statusTekst = 'Pytam o mikrofon…';
                this.room.mozeUdostepniac = !!(navigator.mediaDevices.getDisplayMedia);
                this.room.notatkiOpen = window.innerWidth >= 1024;
                this.room.listaOpen = false;

                await this.pobierzMedia();

                try {
                    this.room.statusTekst = 'Wchodzę do pokoju…';
                    const d = await this.api('meeting.join',
                        { id: m.id, peer_id: this.room.peerId }, 'POST');

                    MEDIA.ice = d.ice_servers || [];
                    this.room.hasTurn = !!d.has_turn;
                    this.room.cursor = d.cursor || 0;
                    this.room.start = Date.now();
                    this.spotkania = d.meetings;

                    /* Notatka jedzie razem z pokojem — panel boczny ma być
                       gotowy do pisania od pierwszej sekundy rozmowy. */
                    this.notatka.meetingId = m.id;
                    this.notatka.title = m.title;
                    this.notatka.podtytul = 'notatka na żywo';
                    this.notatka.konflikt = null;
                    this.notatka.stan = 'idle';
                    this.notatka.tryb = 'edit';
                    this.przyjmijNotatke(d.note);

                    this.zsynchronizujPeery(d.peers);
                    this.room.polaczenie = 'ok';
                    this.room.statusTekst = 'W pokoju';

                    this.startPetli();
                    this.startZegara();
                } catch (e) {
                    this.toast(e.message, 'error');
                    await this.opuscPokoj();
                }
            },

            nowyPeerId() {
                const bufor = new Uint8Array(12);
                crypto.getRandomValues(bufor);
                return Array.from(bufor).map(b => b.toString(16).padStart(2, '0')).join('');
            },

            /**
             * Wejście do pokoju bierze wyłącznie mikrofon. Kamera zostaje
             * wyłączona i włącza się ją przyciskiem — dzięki temu rozmowa
             * rusza nawet wtedy, gdy kamerę trzyma inny program, a przy
             * okazji panel nie zajmuje jej bez potrzeby.
             *
             * Brak mikrofonu też nie zamyka drogi do pokoju: można wejść,
             * słuchać i pisać notatkę.
             */
            async pobierzMedia() {
                /* Stały pojemnik na to, co wysyłamy dalej. Trzymamy go nawet
                   pusty, bo do niego przypina się identyfikator strumienia
                   po stronie odbiorców. */
                MEDIA.local = new MediaStream();

                try {
                    MEDIA.mikrofon = await navigator.mediaDevices.getUserMedia({
                        audio: { echoCancellation: true, noiseSuppression: true }
                    });
                    MEDIA.mikrofon.getAudioTracks().forEach(t => MEDIA.local.addTrack(t));
                    this.sledzGlos(this.room.peerId, MEDIA.local);
                    this.room.mic = true;
                } catch (e) {
                    this.room.mic = false;
                    this.room.ostrzezenie = this.opisBleduMedia(e, 'mikrofonu')
                        + ' Możesz zostać w pokoju — będziesz słyszeć innych i pisać notatkę.';
                }
                return true;
            },

            /**
             * Komunikat dopasowany do przyczyny. „Odmowa”, „urządzenie zajęte”
             * i „brak sprzętu” wymagają zupełnie różnych działań, więc nie ma
             * sensu zbywać ich jednym zdaniem.
             */
            opisBleduMedia(e, czego) {
                const nazwa = e && e.name ? e.name : '';

                if (nazwa === 'NotAllowedError') {
                    return 'Przeglądarka nie dostała zgody na użycie ' + czego
                        + '. Kliknij kłódkę obok adresu strony i zezwól na dostęp.';
                }
                if (nazwa === 'NotReadableError' || nazwa === 'AbortError') {
                    return 'Urządzenie jest zajęte przez inny program (Teams, Zoom, Skype, OBS, aparat systemowy). '
                        + 'Zamknij go i spróbuj jeszcze raz.';
                }
                if (nazwa === 'NotFoundError' || nazwa === 'OverconstrainedError') {
                    return 'Nie znaleziono urządzenia: ' + czego + '.';
                }
                if (nazwa === 'SecurityError') {
                    return 'Dostęp jest zablokowany ustawieniami strony (nagłówek Permissions-Policy w pliku .htaccess).';
                }
                return 'Nie udało się użyć ' + czego + (nazwa ? ' (' + nazwa + ')' : '') + '.';
            },

            /* ---------------------- pętla sygnalizacji -------------------- */

            startPetli() {
                clearTimeout(MEDIA.petla);

                const tik = async () => {
                    if (!this.room.open) return;

                    let odstep = 3000;
                    try {
                        const d = await this.api('rtc.poll', {
                            peer_id: this.room.peerId,
                            since: this.room.cursor,
                            mic: this.room.mic,
                            cam: this.room.cam,
                            sharing: this.room.sharing
                        }, 'POST');

                        if (d.rejoin) {
                            this.toast('Połączenie z pokojem wygasło.', 'error');
                            await this.opuscPokoj();
                            return;
                        }
                        if (d.closed) {
                            this.toast('Spotkanie zostało zakończone.');
                            await this.opuscPokoj();
                            return;
                        }

                        this.room.cursor = d.cursor;
                        this.room.polaczenie = 'ok';
                        this.zsynchronizujPeery(d.peers);

                        for (const sygnal of d.signals) {
                            await this.obsluzSygnal(sygnal);
                        }

                        /* Dopóki cokolwiek się jeszcze zestawia, pytamy częściej. */
                        const czekaja = this.room.peers.some(p => !p.me && p.stan !== 'connected');
                        odstep = czekaja ? 1000 : 3000;
                    } catch (e) {
                        this.room.polaczenie = 'blad';
                        this.room.statusTekst = 'Brak łączności z serwerem';
                        odstep = 4000;
                    }

                    MEDIA.petla = setTimeout(tik, odstep);
                };

                MEDIA.petla = setTimeout(tik, 700);
            },

            startZegara() {
                clearInterval(MEDIA.zegar);
                MEDIA.zegar = setInterval(() => {
                    if (!this.room.open || !this.room.start) return;

                    const sekundy = Math.floor((Date.now() - this.room.start) / 1000);
                    const mm = String(Math.floor(sekundy / 60)).padStart(2, '0');
                    const ss = String(sekundy % 60).padStart(2, '0');
                    this.room.czas = mm + ':' + ss;

                    /* Notatkę dociągamy rzadziej niż sygnalizację — wystarczy,
                       żeby wszyscy widzieli mniej więcej to samo. */
                    if (sekundy % 5 === 0 && this.room.notatkiOpen) this.odswiezNotatkeWTle();

                    if (sekundy === 20) this.sprawdzJakoscPolaczen();
                }, 1000);
            },

            /**
             * Po kilkunastu sekundach wiadomo już, czy siatka się zestawiła.
             * Jeżeli nie — najczęstszą przyczyną jest sieć, która nie
             * przepuszcza połączeń bezpośrednich, a lekarstwem serwer TURN.
             */
            sprawdzJakoscPolaczen() {
                const zepsute = this.room.peers.filter(p => !p.me && p.stan !== 'connected');
                if (!zepsute.length) return;

                this.room.ostrzezenie = this.room.hasTurn
                    ? 'Część połączeń nie chce się zestawić. Sprawdź, czy serwer TURN w db.php odpowiada.'
                    : 'Nie udało się połączyć bezpośrednio z każdym. Taka sieć wymaga serwera TURN — wpisz go w stałej TURN_SERVERS w db.php (opis w README, rozdział o spotkaniach).';
            },

            /* --------------------- utrzymanie siatki ---------------------- */

            zsynchronizujPeery(lista) {
                const obecni = lista.map(p => p.peer_id);

                /* Ktoś wyszedł — zamykamy połączenie i zwalniamy strumień. */
                for (const peerId of Array.from(MEDIA.pc.keys())) {
                    if (!obecni.includes(peerId)) this.rozlacz(peerId);
                }

                this.room.peers = lista.map(p => {
                    const pc = MEDIA.pc.get(p.peer_id);
                    return Object.assign({}, p, {
                        stan: p.me ? 'connected' : (pc ? pc.connectionState : 'new'),
                        mowi: MEDIA.glosy.get(p.peer_id) || false
                    });
                });

                /* Nowi: łączymy się z każdym, kogo jeszcze nie mamy.
                   Ofertę składa ten o mniejszym identyfikatorze — bez tej
                   umowy obie strony wysłałyby ofertę naraz i połączenie
                   rozsypałoby się na kolizji. */
                for (const p of lista) {
                    if (p.me || MEDIA.pc.has(p.peer_id)) continue;
                    this.polaczZ(p.peer_id, this.room.peerId < p.peer_id);
                }
            },

            polaczZ(peerId, inicjuje) {
                const pc = new RTCPeerConnection({ iceServers: MEDIA.ice });
                MEDIA.pc.set(peerId, pc);
                MEDIA.kolejkaIce.set(peerId, []);

                /* Układ torów ustala wyłącznie strona składająca ofertę:
                   najpierw dźwięk, potem obraz. Obie strony zakładające tory
                   niezależnie od siebie kończą tak, że jedna nadaje na torze,
                   którego druga w ogóle nie czyta — i kamera włączona w trakcie
                   rozmowy nigdzie nie dociera.

                   Tory powstają od razu, nawet gdy kamera jest wyłączona,
                   a mikrofonu nie udało się dostać. Dzięki temu włączenie
                   czegokolwiek później to sama podmiana ścieżki, bez
                   negocjacji od nowa — która przez sygnalizację opartą
                   o odpytywanie byłaby wolna i zawodna. */
                if (inicjuje) {
                    pc.addTransceiver('audio', { direction: 'sendrecv', streams: [MEDIA.local] });
                    pc.addTransceiver('video', { direction: 'sendrecv', streams: [MEDIA.local] });
                    this.wyslijNaszeSciezki(pc);
                }

                pc.onicecandidate = (e) => {
                    if (e.candidate) this.wyslijSygnal(peerId, 'ice', e.candidate.toJSON());
                };

                pc.ontrack = (e) => {
                    /* Ścieżka dołożona przez replaceTrack do wcześniej
                       utworzonego toru potrafi przyjść bez przypisanego
                       strumienia — wtedy składamy go sami. */
                    let strumien = e.streams[0];
                    if (!strumien) {
                        strumien = MEDIA.zdalne.get(peerId) || new MediaStream();
                        if (!strumien.getTracks().includes(e.track)) strumien.addTrack(e.track);
                    }

                    MEDIA.zdalne.set(peerId, strumien);
                    if (e.track.kind === 'audio') this.sledzGlos(peerId, strumien);
                    this.room.strumienTik++;
                };

                pc.onconnectionstatechange = () => {
                    const wpis = this.room.peers.find(p => p.peer_id === peerId);
                    if (wpis) wpis.stan = pc.connectionState;

                    /* Zerwane łącze potrafi wrócić samo; przy „failed” trzeba
                       zestawić połączenie od nowa. */
                    if (pc.connectionState === 'failed') {
                        this.rozlacz(peerId);
                        setTimeout(() => {
                            if (this.room.open && this.room.peers.some(p => p.peer_id === peerId)) {
                                this.polaczZ(peerId, this.room.peerId < peerId);
                            }
                        }, 1200);
                    }
                };

                if (inicjuje) {
                    pc.onnegotiationneeded = async () => {
                        try {
                            const oferta = await pc.createOffer();
                            await pc.setLocalDescription(oferta);
                            this.wyslijSygnal(peerId, 'offer', { type: oferta.type, sdp: oferta.sdp });
                        } catch (e) { /* przy kolejnej próbie */ }
                    };
                }

                return pc;
            },

            rozlacz(peerId) {
                const pc = MEDIA.pc.get(peerId);
                if (pc) {
                    pc.onicecandidate = null;
                    pc.ontrack = null;
                    pc.onconnectionstatechange = null;
                    pc.onnegotiationneeded = null;
                    try { pc.close(); } catch (e) {}
                }
                MEDIA.pc.delete(peerId);
                MEDIA.zdalne.delete(peerId);
                MEDIA.kolejkaIce.delete(peerId);
                MEDIA.glosy.delete(peerId);
                this.zamknijAnalizator(peerId);
                this.room.strumienTik++;
            },

            /**
             * Wkłada to, co akurat nadajemy, na istniejące tory połączenia.
             * Wywoływane po zestawieniu torów oraz po każdej zmianie źródła.
             */
            wyslijNaszeSciezki(pc) {
                const dzwiek = MEDIA.local ? MEDIA.local.getAudioTracks()[0] : null;
                const obraz = MEDIA.ekran
                    ? MEDIA.ekran.getVideoTracks()[0]
                    : (MEDIA.kamera ? MEDIA.kamera.getVideoTracks()[0] : null);

                for (const tor of pc.getTransceivers()) {
                    const czego = (tor.sender.track && tor.sender.track.kind)
                        || (tor.receiver.track && tor.receiver.track.kind);

                    if (czego === 'audio') tor.sender.replaceTrack(dzwiek || null).catch(() => {});
                    if (czego === 'video') tor.sender.replaceTrack(obraz || null).catch(() => {});
                }
            },

            async wyslijSygnal(doKogo, kind, payload) {
                try {
                    await this.api('rtc.signal', {
                        peer_id: this.room.peerId, to: doKogo, kind, payload
                    }, 'POST');
                } catch (e) {
                    /* Adresat mógł właśnie wyjść — pętla i tak to wychwyci. */
                }
            },

            async obsluzSygnal(sygnal) {
                const peerId = sygnal.from;
                let pc = MEDIA.pc.get(peerId);

                if (!pc) {
                    /* Oferta od kogoś, kogo jeszcze nie widzieliśmy na liście. */
                    if (sygnal.kind !== 'offer') return;
                    pc = this.polaczZ(peerId, false);
                }

                try {
                    if (sygnal.kind === 'offer') {
                        await pc.setRemoteDescription(new RTCSessionDescription(sygnal.payload));
                        await this.wypuscKolejkeIce(peerId, pc);

                        /* Tory powstałe z oferty są jednokierunkowe. Zgłaszamy
                           na nich gotowość do nadawania jeszcze przed złożeniem
                           odpowiedzi — inaczej włączenie kamery w trakcie
                           rozmowy wymagałoby negocjacji od nowa. */
                        for (const tor of pc.getTransceivers()) {
                            if (tor.direction === 'recvonly') tor.direction = 'sendrecv';
                        }
                        this.wyslijNaszeSciezki(pc);

                        const odpowiedz = await pc.createAnswer();
                        await pc.setLocalDescription(odpowiedz);
                        this.wyslijSygnal(peerId, 'answer', { type: odpowiedz.type, sdp: odpowiedz.sdp });
                    } else if (sygnal.kind === 'answer') {
                        if (pc.signalingState === 'have-local-offer') {
                            await pc.setRemoteDescription(new RTCSessionDescription(sygnal.payload));
                            await this.wypuscKolejkeIce(peerId, pc);
                        }
                    } else if (sygnal.kind === 'ice') {
                        /* Kandydaci potrafią przyjść przed ofertą — wtedy
                           odkładamy je do czasu ustawienia opisu zdalnego. */
                        if (pc.remoteDescription && pc.remoteDescription.type) {
                            await pc.addIceCandidate(new RTCIceCandidate(sygnal.payload));
                        } else {
                            (MEDIA.kolejkaIce.get(peerId) || []).push(sygnal.payload);
                        }
                    }
                } catch (e) {
                    /* Pojedynczy zgubiony sygnał nie może wywrócić całej rozmowy. */
                }
            },

            async wypuscKolejkeIce(peerId, pc) {
                const kolejka = MEDIA.kolejkaIce.get(peerId) || [];
                for (const kandydat of kolejka) {
                    try { await pc.addIceCandidate(new RTCIceCandidate(kandydat)); } catch (e) {}
                }
                MEDIA.kolejkaIce.set(peerId, []);
            },

            /* ------------------------- sterowanie ------------------------- */

            /** Odbicie własnego stanu na kafelku — bez czekania na odpytanie. */
            odswiezSwojKafelek() {
                const ja = this.room.peers.find(p => p.me);
                if (!ja) return;
                ja.mic = this.room.mic;
                ja.cam = this.room.cam;
                ja.sharing = this.room.sharing;
            },

            async przelaczMikrofon() {
                /* Mikrofonu mogło zabraknąć przy wejściu (zajęty, brak zgody).
                   Kliknięcie przycisku to naturalny moment, żeby spróbować
                   jeszcze raz — na przykład po zamknięciu drugiego programu. */
                if (!MEDIA.mikrofon) { await this.wlaczMikrofon(); return; }

                this.room.mic = !this.room.mic;
                MEDIA.local.getAudioTracks().forEach(t => { t.enabled = this.room.mic; });
                this.odswiezSwojKafelek();
            },

            async wlaczMikrofon() {
                if (this.room.mikrofonCzeka) return;
                this.room.mikrofonCzeka = true;

                try {
                    MEDIA.mikrofon = await navigator.mediaDevices.getUserMedia({
                        audio: { echoCancellation: true, noiseSuppression: true }
                    });
                } catch (e) {
                    this.toast(this.opisBleduMedia(e, 'mikrofonu'), 'error');
                    return;
                } finally {
                    this.room.mikrofonCzeka = false;
                }

                const sciezka = MEDIA.mikrofon.getAudioTracks()[0];
                MEDIA.local.addTrack(sciezka);
                this.podmienSciezke('audio', sciezka);
                this.sledzGlos(this.room.peerId, MEDIA.local);

                this.room.mic = true;
                this.room.ostrzezenie = '';
                this.odswiezSwojKafelek();
            },

            przelaczKamere() {
                return this.room.cam ? this.wylaczKamere() : this.wlaczKamere();
            },

            /**
             * Kamerę bierzemy dopiero tutaj — przy wejściu do pokoju panel
             * jej nie dotyka. Nieudana próba nie psuje trwającej rozmowy:
             * dźwięk leci dalej, a użytkownik dostaje konkretną podpowiedź.
             */
            async wlaczKamere() {
                if (this.room.kameraCzeka || MEDIA.kamera) return;
                this.room.kameraCzeka = true;

                try {
                    MEDIA.kamera = await navigator.mediaDevices.getUserMedia({
                        video: { width: { ideal: 1280 }, height: { ideal: 720 } }
                    });
                } catch (e) {
                    this.toast(this.opisBleduMedia(e, 'kamery'), 'error');
                    return;
                } finally {
                    this.room.kameraCzeka = false;
                }

                const sciezka = MEDIA.kamera.getVideoTracks()[0];
                MEDIA.local.addTrack(sciezka);

                /* Przy udostępnianym ekranie kamera czeka w kolejce —
                   podmienimy tor dopiero po zakończeniu udostępniania. */
                if (!this.room.sharing) this.podmienSciezke('video', sciezka);

                /* Wyciągnięcie kabelka z kamery też ma zdjąć jej stan. */
                sciezka.onended = () => { if (this.room.cam) this.wylaczKamere(); };

                this.room.cam = true;
                this.room.strumienTik++;
                this.odswiezSwojKafelek();
            },

            /** Wyłączenie zwalnia kamerę dla innych programów, nie tylko gasi obraz. */
            wylaczKamere() {
                if (!this.room.sharing) this.podmienSciezke('video', null);

                if (MEDIA.kamera) {
                    MEDIA.kamera.getTracks().forEach(t => {
                        MEDIA.local.removeTrack(t);
                        t.stop();
                    });
                    MEDIA.kamera = null;
                }

                this.room.cam = false;
                this.room.strumienTik++;
                this.odswiezSwojKafelek();
            },

            async przelaczEkran() {
                if (this.room.sharing) { this.zakonczUdostepnianie(); return; }

                try {
                    MEDIA.ekran = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
                } catch (e) {
                    /* Anulowanie okna wyboru to nie błąd — nie zawracamy głowy. */
                    if (e.name !== 'NotAllowedError') {
                        this.toast('Nie udało się udostępnić ekranu: ' + e.message, 'error');
                    }
                    return;
                }

                const sciezka = MEDIA.ekran.getVideoTracks()[0];
                this.podmienSciezke('video', sciezka);
                this.room.sharing = true;
                this.room.strumienTik++;
                this.odswiezSwojKafelek();

                /* Zatrzymanie z paska przeglądarki też musi zdjąć udostępnianie. */
                sciezka.onended = () => this.zakonczUdostepnianie();
            },

            zakonczUdostepnianie() {
                if (MEDIA.ekran) {
                    MEDIA.ekran.getTracks().forEach(t => t.stop());
                    MEDIA.ekran = null;
                }

                /* Wracamy do kamery, jeśli była włączona; jeśli nie —
                   przestajemy nadawać obraz i zostają same inicjały. */
                const kamera = MEDIA.kamera ? MEDIA.kamera.getVideoTracks()[0] : null;
                this.podmienSciezke('video', kamera || null);

                this.room.sharing = false;
                this.room.strumienTik++;
                this.odswiezSwojKafelek();
            },

            /**
             * Podmienia to, co wysyłamy na danym torze, u wszystkich rozmówców.
             * Tor istnieje od chwili zestawienia połączenia, więc ani włączenie
             * kamery, ani udostępnienie ekranu nie wymaga nowych negocjacji.
             * `null` znaczy „przestań nadawać na tym torze”.
             */
            podmienSciezke(rodzaj, sciezka) {
                for (const pc of MEDIA.pc.values()) {
                    for (const tor of pc.getTransceivers()) {
                        /* Gdy nic nie nadajemy, rodzaj toru zdradza dopiero
                           ścieżka odbiorcza — ta powstaje razem z torem. */
                        const czego = (tor.sender.track && tor.sender.track.kind)
                            || (tor.receiver.track && tor.receiver.track.kind);

                        if (czego === rodzaj) {
                            tor.sender.replaceTrack(sciezka).catch(() => {});
                            break;
                        }
                    }
                }
            },

            /* --------------------------- wyjście -------------------------- */

            async opuscPokoj() {
                const peerId = this.room.peerId;

                clearTimeout(MEDIA.petla);
                clearInterval(MEDIA.zegar);

                if (this.notatka.stan === 'dirty') {
                    clearTimeout(this.notatka.timer);
                    await this.zapiszNotatke();
                }

                for (const id of Array.from(MEDIA.pc.keys())) this.rozlacz(id);

                this.zakonczUdostepnianie();

                for (const strumien of [MEDIA.kamera, MEDIA.mikrofon, MEDIA.local]) {
                    if (strumien) strumien.getTracks().forEach(t => t.stop());
                }
                MEDIA.kamera = null;
                MEDIA.mikrofon = null;
                MEDIA.local = null;
                this.zamknijAnalizator(this.room.peerId);

                this.room.open = false;
                this.room.peers = [];
                this.room.czas = '';
                this.room.start = 0;
                this.notatka.meetingId = null;

                if (peerId) {
                    try {
                        this.apply(await this.api('meeting.leave', { peer_id: peerId }, 'POST'));
                    } catch (e) { /* serwer i tak posprząta po czasie */ }
                }
                await this.odswiezSpotkania();
            },

            /* ---------------------- podpięcie obrazu ---------------------- */

            podepnijWideo(el, peerId) {
                const swoj = peerId === this.room.peerId;

                /* Własnego głosu nie odtwarzamy — natychmiastowe sprzężenie. */
                el.muted = swoj;

                const strumien = swoj ? (MEDIA.ekran || MEDIA.kamera) : MEDIA.zdalne.get(peerId);
                if (el.srcObject !== strumien) {
                    el.srcObject = strumien || null;
                    if (strumien) el.play().catch(() => {});
                }
            },

            stanPolaczeniaTekst(stan) {
                const opisy = {
                    new: 'łączenie…', connecting: 'łączenie…', connected: 'połączono',
                    disconnected: 'przerwa w łączu', failed: 'brak połączenia', closed: 'rozłączono'
                };
                return opisy[stan] || 'łączenie…';
            },

            /* ------------------- kto teraz mówi --------------------------- */

            /**
             * Prosty wskaźnik mówienia: mierzymy głośność strumienia i po
             * przekroczeniu progu podświetlamy kafelek. Przy wyłączonych
             * kamerach to jedyna informacja, kto właśnie zabiera głos.
             */
            sledzGlos(peerId, strumien) {
                if (!strumien.getAudioTracks().length) return;
                if (!window.AudioContext && !window.webkitAudioContext) return;

                this.zamknijAnalizator(peerId);
                try {
                    const Kontekst = window.AudioContext || window.webkitAudioContext;
                    const kontekst = new Kontekst();
                    const zrodlo = kontekst.createMediaStreamSource(strumien);
                    const analizator = kontekst.createAnalyser();
                    analizator.fftSize = 512;
                    zrodlo.connect(analizator);

                    const dane = new Uint8Array(analizator.frequencyBinCount);
                    const timer = setInterval(() => {
                        if (!this.room.open) return;
                        analizator.getByteFrequencyData(dane);

                        let suma = 0;
                        for (let i = 0; i < dane.length; i++) suma += dane[i];
                        const glosno = (suma / dane.length) > 12;

                        if (MEDIA.glosy.get(peerId) !== glosno) {
                            MEDIA.glosy.set(peerId, glosno);
                            const wpis = this.room.peers.find(p => p.peer_id === peerId);
                            if (wpis) wpis.mowi = glosno;
                        }
                    }, 250);

                    MEDIA.analizatory.set(peerId, { kontekst, timer });
                } catch (e) { /* wskaźnik mówienia to dodatek, nie warunek rozmowy */ }
            },

            zamknijAnalizator(peerId) {
                const wpis = MEDIA.analizatory.get(peerId);
                if (!wpis) return;
                clearInterval(wpis.timer);
                try { wpis.kontekst.close(); } catch (e) {}
                MEDIA.analizatory.delete(peerId);
            },

            /* --------------------- terminy wykonania ---------------------- */

            /**
             * Data jako RRRR-MM-DD w czasie lokalnym. Świadomie nie używamy
             * toISOString(): ono przelicza na UTC i w naszej strefie potrafi
             * cofnąć datę o jeden dzień.
             */
            isoZDaty(d) {
                return d.getFullYear() + '-'
                    + String(d.getMonth() + 1).padStart(2, '0') + '-'
                    + String(d.getDate()).padStart(2, '0');
            },

            /** Data w formacie RRRR-MM-DD, przesunięta o podaną liczbę dni. */
            dataZa(dni) {
                const d = new Date();
                d.setHours(12, 0, 0, 0);
                d.setDate(d.getDate() + dni);
                return this.isoZDaty(d);
            },

            /** Ile dni dzieli nas od terminu: 0 = dziś, ujemne = po terminie. */
            dniDoTerminu(data) {
                if (!data) return null;
                const cel = new Date(data + 'T12:00:00');
                if (isNaN(cel)) return null;
                const dzis = new Date();
                dzis.setHours(12, 0, 0, 0);
                return Math.round((cel - dzis) / 86400000);
            },

            /** Czy termin minął. Zrobione zadania nigdy nie są spóźnione. */
            terminMinal(data, status) {
                if (status === 'done') return false;
                const dni = this.dniDoTerminu(data);
                return dni !== null && dni < 0;
            },

            fmtData(data) {
                const dni = this.dniDoTerminu(data);
                if (dni === null) return '—';
                return new Date(data + 'T12:00:00')
                    .toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' });
            },

            /** Krótka etykieta na kartę: „dziś”, „za 3 dni”, „5 dni po terminie”. */
            terminEtykieta(data, status) {
                const dni = this.dniDoTerminu(data);
                if (dni === null) return '';

                /* Zrobionego zadania nie straszymy zaległością — pokazujemy
                   samą datę, żeby było wiadomo, na kiedy było planowane. */
                if (status === 'done') {
                    return new Date(data + 'T12:00:00').toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' });
                }

                if (dni === 0)  return 'dziś';
                if (dni === 1)  return 'jutro';
                if (dni === -1) return 'wczoraj';
                if (dni < 0)    return this.odmianaDni(-dni) + ' po terminie';
                if (dni <= 7)   return 'za ' + this.odmianaDni(dni);
                return new Date(data + 'T12:00:00').toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' });
            },

            /** Pełny opis pod polem daty w oknie zadania. */
            terminOpis(data) {
                const dni = this.dniDoTerminu(data);
                if (dni === null) return '';
                const pelna = this.fmtData(data);
                if (dni === 0)  return pelna + ' — termin mija dzisiaj';
                if (dni < 0)    return pelna + ' — ' + this.odmianaDni(-dni) + ' po terminie';
                return pelna + ' — zostało ' + this.odmianaDni(dni);
            },

            odmianaDni(n) {
                return n === 1 ? '1 dzień' : n + ' dni';
            },

            /** „1 zadanie”, „3 zadania”, „5 zadań”, „22 zadania”, „12 zadań”. */
            odmianaZadan(n) {
                if (n === 1) return '1 zadanie';
                const setki = n % 100;
                const jednosci = n % 10;
                return (jednosci >= 2 && jednosci <= 4 && (setki < 12 || setki > 14))
                    ? n + ' zadania'
                    : n + ' zadań';
            },

            /** Kolory plakietki terminu: czerwony po terminie, bursztyn na dziś. */
            terminStyle(data, status) {
                const dni = this.dniDoTerminu(data);
                if (dni === null) return '';

                let paleta;
                if (status === 'done') {
                    paleta = { jasny: ['#f1f5f9', '#475569'], ciemny: ['#334155', '#cbd5e1'] };
                } else if (dni < 0) {
                    paleta = { jasny: ['#fef2f2', '#b91c1c'], ciemny: ['#450a0a', '#fca5a5'] };
                } else if (dni <= 1) {
                    paleta = { jasny: ['#fffbeb', '#b45309'], ciemny: ['#451a03', '#fcd34d'] };
                } else {
                    paleta = { jasny: ['#f1f5f9', '#475569'], ciemny: ['#334155', '#cbd5e1'] };
                }
                const [tlo, tekst] = this.dark ? paleta.ciemny : paleta.jasny;
                return 'background:' + tlo + ';color:' + tekst;
            },

            kolumnaEtykieta(status) {
                const col = this.columns.find(c => c.key === status);
                return col ? col.label : status;
            },

            newFolder() {
                this.prompt({
                    title: 'Nowy folder',
                    message: 'Nazwij projekt, np. „Strona firmowa” albo „Rekrutacja 2026”.',
                    value: '',
                    confirmText: 'Utwórz',
                    onOk: async (name) => {
                        if (!name.trim()) return;
                        try {
                            const d = await this.api('folder.create', { name }, 'POST');
                            this.apply(d);
                            await this.loadFolder(d.folder_id);
                            this.tab = 'board';
                            this.toast('Folder „' + name.trim() + '” utworzony.');
                        } catch (e) { this.toast(e.message, 'error'); }
                    }
                });
            },

            renameFolder(folder) {
                this.prompt({
                    title: 'Zmiana nazwy folderu',
                    value: folder.name,
                    confirmText: 'Zapisz',
                    onOk: async (name) => {
                        if (!name.trim() || name === folder.name) return;
                        try {
                            this.apply(await this.api('folder.rename', { id: folder.id, name }, 'POST'));
                            if (this.current && this.current.id === folder.id) this.current.name = name.trim();
                            this.toast('Nazwa zmieniona.');
                        } catch (e) { this.toast(e.message, 'error'); }
                    }
                });
            },

            deleteFolder(folder) {
                this.confirm({
                    title: 'Usunąć folder „' + folder.name + '”?',
                    message: 'Znikną również wszystkie zadania, notatka i załączniki z tego folderu. Tej operacji nie da się cofnąć.',
                    confirmText: 'Usuń folder',
                    onOk: async () => {
                        try {
                            const d = await this.api('folder.delete', { id: folder.id }, 'POST');
                            this.apply(d);
                            if (this.current && this.current.id === folder.id) {
                                this.current = null;
                                this.tasks = [];
                                this.files = [];
                                this.noteDirty = false;
                                localStorage.removeItem('panel.folder');
                                if (this.folders.length) await this.loadFolder(this.folders[0].id);
                            }
                            this.toast('Folder usunięty.');
                        } catch (e) { this.toast(e.message, 'error'); }
                    }
                });
            },

            /* ---------------- kolejność folderów (przeciąganie) ------------ */
            startFolderDrag(event, id) {
                if (this.folderQuery !== '') return;      // przy filtrze kolejność nie ma sensu
                this.folderDrag = id;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(id));
            },

            dropFolder(targetId) {
                const source = this.folderDrag;
                this.folderDrag = null;
                this.folderDragOver = null;
                if (!source || source === targetId || this.folderQuery !== '') return;

                const list = this.folders.slice();
                const from = list.findIndex(f => f.id === source);
                const to   = list.findIndex(f => f.id === targetId);
                if (from < 0 || to < 0) return;

                const [moved] = list.splice(from, 1);
                list.splice(to, 0, moved);
                this.folders = list;                      // kolejność zmienia się od razu
                this.saveFolderOrder();
            },

            async saveFolderOrder() {
                try {
                    await this.api('folder.reorder', { ids: this.folders.map(f => f.id) }, 'POST');
                } catch (e) {
                    this.toast(e.message, 'error');
                    await this.refresh();                 // wracamy do kolejności z serwera
                }
            },

            /* --------------------------- zadania -------------------------- */
            /** Czy zadanie przechodzi przez aktywne filtry tablicy. */
            pasujeDoFiltrow(t) {
                const f = this.filtr;

                if (f.osoby.length && !t.assignees.some(a => f.osoby.includes(a.id))) return false;
                if (f.priorytety.length && !f.priorytety.includes(t.priority)) return false;
                if (f.tylkoTerminy && !t.due_date) return false;

                const szukane = f.tekst.trim().toLowerCase();
                if (szukane) {
                    const stog = (t.title + ' ' + (t.description || '')).toLowerCase();
                    if (!stog.includes(szukane)) return false;
                }
                return true;
            },

            tasksIn(status) {
                const waga = { high: 0, normal: 1, low: 2 };
                const list = this.tasks.filter(t => t.status === status && this.pasujeDoFiltrow(t));

                list.sort((a, b) => {
                    /* Zadania po terminie idą na samą górę — to najpilniejsza informacja. */
                    const spozA = this.terminMinal(a.due_date, a.status) ? 0 : 1;
                    const spozB = this.terminMinal(b.due_date, b.status) ? 0 : 1;
                    if (spozA !== spozB) return spozA - spozB;

                    const roznica = (waga[a.priority] ?? 1) - (waga[b.priority] ?? 1);
                    if (roznica !== 0) return roznica;

                    /* Przy równym priorytecie decyduje bliższy termin. */
                    if (a.due_date && b.due_date && a.due_date !== b.due_date) {
                        return a.due_date < b.due_date ? -1 : 1;
                    }
                    if (a.due_date && !b.due_date) return -1;
                    if (!a.due_date && b.due_date) return 1;

                    return status === 'done'
                        ? String(b.updated_at || '').localeCompare(String(a.updated_at || ''))
                        : a.id - b.id;
                });
                return list;
            },

            przelaczFiltrOsoby(id) {
                const i = this.filtr.osoby.indexOf(id);
                if (i === -1) this.filtr.osoby.push(id);
                else this.filtr.osoby.splice(i, 1);
            },

            przelaczFiltrPriorytetu(key) {
                const i = this.filtr.priorytety.indexOf(key);
                if (i === -1) this.filtr.priorytety.push(key);
                else this.filtr.priorytety.splice(i, 1);
            },

            wyczyscFiltry() {
                this.filtr.tekst = '';
                this.filtr.osoby = [];
                this.filtr.priorytety = [];
                this.filtr.tylkoTerminy = false;
            },

            /** Kliknięcie w awatar dodaje albo usuwa osobę z listy. */
            toggleQuickAssignee(status, userId) {
                const lista = this.quick[status].assignee_ids;
                const i = lista.indexOf(userId);
                if (i === -1) lista.push(userId);
                else lista.splice(i, 1);
            },

            toggleTaskAssignee(userId) {
                const i = this.task.assignee_ids.indexOf(userId);
                if (i === -1) this.task.assignee_ids.push(userId);
                else this.task.assignee_ids.splice(i, 1);
            },

            async addTask(status) {
                const pole  = this.quick[status];
                const title = pole.title.trim();
                if (!title || !this.current) return;
                try {
                    this.apply(await this.api('task.create', {
                        folder_id: this.current.id,
                        title,
                        status,
                        priority: pole.priority,
                        due_date: pole.due_date || null,
                        assignee_ids: pole.assignee_ids.slice()
                    }, 'POST'));
                    pole.title = '';
                } catch (e) { this.toast(e.message, 'error'); }
            },

            openTask(t) {
                this.filePickerOpen = false;
                this.task = {
                    open: true,
                    id: t.id,
                    title: t.title,
                    description: t.description || '',
                    status: t.status,
                    priority: t.priority || 'normal',
                    due_date: t.due_date || '',
                    assignee_ids: (t.assignees || []).map(a => a.id),
                    saving: false,
                    meta: {
                        created_by_name: t.created_by_name,
                        created_at: t.created_at,
                        updated_by_name: t.updated_by_name,
                        updated_at: t.updated_at
                    }
                };

                this.comments = [];
                this.commentDraft = '';
                this.wczytajKomentarze(t.id);
            },

            /* ------------------------- komentarze ------------------------- */

            async wczytajKomentarze(taskId) {
                this.commentsLoading = true;
                try {
                    const d = await this.api('comment.list', null, 'GET', '&task_id=' + encodeURIComponent(taskId));
                    /* Okno mogło się w międzyczasie przełączyć na inne zadanie. */
                    if (this.task.id === taskId) this.comments = d.comments;
                } catch (e) {
                    if (this.task.id === taskId) this.toast(e.message, 'error');
                } finally {
                    this.commentsLoading = false;
                }
            },

            async dodajKomentarz() {
                const tresc = this.commentDraft.trim();
                if (!tresc || !this.task.id || this.commentSaving) return;

                this.commentSaving = true;
                try {
                    const d = await this.api('comment.add', { task_id: this.task.id, body: tresc }, 'POST');
                    this.comments = d.comments;
                    this.commentDraft = '';
                    this.apply(d);
                } catch (e) {
                    this.toast(e.message, 'error');
                } finally {
                    this.commentSaving = false;
                }
            },

            usunKomentarz(k) {
                this.confirm({
                    title: 'Usunąć komentarz?',
                    message: 'Twoja wypowiedź zniknie z dyskusji na stałe.',
                    confirmText: 'Usuń',
                    onOk: async () => {
                        try {
                            const d = await this.api('comment.delete', { id: k.id }, 'POST');
                            this.comments = d.comments;
                            this.apply(d);
                        } catch (e) { this.toast(e.message, 'error'); }
                    }
                });
            },

            async saveTask() {
                if (!this.task.title.trim()) return;
                this.task.saving = true;
                try {
                    this.apply(await this.api('task.update', {
                        id: this.task.id,
                        title: this.task.title,
                        description: this.task.description,
                        status: this.task.status,
                        priority: this.task.priority,
                        due_date: this.task.due_date || null,
                        assignee_ids: this.task.assignee_ids.slice()
                    }, 'POST'));
                    this.task.open = false;
                    this.toast('Zadanie zapisane.');
                } catch (e) {
                    this.toast(e.message, 'error');
                } finally {
                    this.task.saving = false;
                }
            },

            deleteTask() {
                const id = this.task.id;
                const title = this.task.title;
                const zalaczniki = this.taskFiles.length;
                this.task.open = false;
                this.confirm({
                    title: 'Usunąć zadanie?',
                    message: '„' + title + '” zniknie z tablicy na stałe.'
                        + (zalaczniki
                            ? ' Jego ' + zalaczniki + ' załącznik(i) nie przepadną — trafią do zakładki Pliki.'
                            : ''),
                    confirmText: 'Usuń',
                    onOk: async () => {
                        try {
                            const d = await this.api('task.delete', { id }, 'POST');
                            this.apply(d);
                            this.toast(d.detached
                                ? 'Zadanie usunięte. Załączniki (' + d.detached + ') przeniesione do zakładki Pliki.'
                                : 'Zadanie usunięte.');
                        } catch (e) { this.toast(e.message, 'error'); }
                    }
                });
            },

            async setStatus(t, status) {
                const previous = t.status;
                t.status = status;                       // od razu przesuwamy kafelek
                try {
                    this.apply(await this.api('task.update', { id: t.id, status }, 'POST'));
                } catch (e) {
                    t.status = previous;
                    this.toast(e.message, 'error');
                }
            },

            toggleDone(t) {
                this.setStatus(t, t.status === 'done' ? 'todo' : 'done');
            },

            dropOn(status) {
                const id = this.dragId;
                this.dragId = null;
                this.dragOver = null;
                if (!id) return;
                const t = this.tasks.find(x => x.id === id);
                if (t && t.status !== status) this.setStatus(t, status);
            },

            /* --------------------------- notatka -------------------------- */
            async saveNote(silent = false) {
                if (!this.current || this.noteSaving) return;
                this.noteSaving = true;
                try {
                    const d = await this.api('note.save', { folder_id: this.current.id, content: this.noteDraft }, 'POST');
                    this.noteDirty = false;
                    this.apply(d);
                    if (!silent) {
                        this.noteMode = this.noteDraft.trim() ? 'view' : 'edit';
                        this.toast('Notatka zapisana.');
                    }
                } catch (e) {
                    this.toast(e.message, 'error');
                } finally {
                    this.noteSaving = false;
                }
            },

            pustaNotatka() {
                return '<p class="text-faint">Notatka jest pusta — przełącz na „Edycja”, aby ją napisać.</p>';
            },

            /* ---------------------------- pliki --------------------------- */
            onFilePick(event) {
                this.queueUploads(Array.from(event.target.files || []));
                event.target.value = '';
            },

            onFileDrop(event) {
                this.dropActive = false;
                this.queueUploads(Array.from(event.dataTransfer.files || []));
            },

            onTaskFilePick(event) {
                const pliki = Array.from(event.target.files || []);
                event.target.value = '';
                this.queueUploads(pliki, this.task.id);
            },

            /** Sprawdza plik po stronie przeglądarki, zanim ruszy wysyłka. */
            fileProblem(file) {
                const ext = (file.name.split('.').pop() || '').toLowerCase();
                if (!file.name.includes('.') || !this.limits.allowed_ext.includes(ext)) {
                    return '„' + file.name + '” — niedozwolony typ pliku. Dozwolone: '
                        + this.limits.allowed_ext.join(', ').toUpperCase() + '.';
                }
                if (file.size === 0) {
                    return '„' + file.name + '” jest pusty.';
                }
                if (file.size > this.limits.max_upload) {
                    return '„' + file.name + '” waży ' + this.fmtSize(file.size)
                        + ', a limit to ' + this.fmtSize(this.limits.max_upload) + '.';
                }
                if (file.size > this.limits.server_upload) {
                    return '„' + file.name + '” waży ' + this.fmtSize(file.size)
                        + ', a ten hosting przyjmuje najwyżej ' + this.fmtSize(this.limits.server_upload)
                        + '. Instrukcja podniesienia limitu jest w README.md, punkt 6.';
                }
                return null;
            },

            /** taskId = null → załącznik folderu; liczba → załącznik zadania. */
            async queueUploads(list, taskId = null) {
                if (!this.current || !list.length) return;

                for (const file of list) {
                    const problem = this.fileProblem(file);
                    if (problem) { this.toast(problem, 'error'); continue; }

                    try {
                        if (taskId) { this.taskUploading = true; this.taskUploadPct = 0; }
                        else        { this.uploading = true; this.uploadName = file.name; this.uploadPct = 0; }

                        this.apply(await this.sendFile(file, taskId));
                        this.toast('Wysłano: ' + file.name);
                    } catch (e) {
                        this.toast(e.message, 'error');
                    } finally {
                        this.taskUploading = false;
                        this.taskUploadPct = 0;
                        this.uploading = false;
                        this.uploadPct = 0;
                    }
                }
            },

            /**
             * Wysyłka pliku. Domyślnie idzie surowym strumieniem, bo część
             * hostingów nie ma sprawnego katalogu tymczasowego PHP i klasyczny
             * formularz multipart kończy się tam błędem zapisu. Gdyby serwer
             * odrzucił strumień z powodów infrastrukturalnych, panel próbuje
             * jeszcze raz klasycznie — użytkownik nic o tym nie wie.
             */
            /**
             * Cztery metody wysyłki, próbowane po kolei, aż któraś zadziała:
             *
             *   strumień   — jedno żądanie, plik jako surowa treść
             *   base64     — jedno żądanie, plik w polu formularza
             *   fragmenty  — wiele małych żądań JSON (najpewniejsze)
             *   formularz  — klasyczny multipart
             *
             * Metodę, która się sprawdziła, zapamiętujemy w przeglądarce,
             * żeby kolejne wysyłki nie zaczynały od nieudanych prób.
             */
            async sendFile(file, taskId = null) {
                const metody = {
                    strumien:  () => this.wyslijStrumien(file, taskId),
                    base64:    () => this.wyslijBase64(file, taskId),
                    fragmenty: () => this.wyslijFragmentami(file, taskId),
                    formularz: () => this.wyslijFormularz(file, taskId)
                };

                const kolejnosc = ['strumien', 'base64', 'fragmenty', 'formularz'];
                const znana = this.metodaWysylki;
                if (znana && kolejnosc.indexOf(znana) > 0) {
                    kolejnosc.splice(kolejnosc.indexOf(znana), 1);
                    kolejnosc.unshift(znana);
                }

                let ostatniBlad = null;
                for (const nazwa of kolejnosc) {
                    try {
                        const wynik = await metody[nazwa]();
                        if (this.metodaWysylki !== nazwa) {
                            this.metodaWysylki = nazwa;
                            try { localStorage.setItem('panel.wysylka', nazwa); } catch (e) {}
                        }
                        return wynik;
                    } catch (e) {
                        if (!e.mozliwaPonownaProba) throw e;   // błąd merytoryczny — nie ma co powtarzać
                        ostatniBlad = e;
                    }
                }
                throw ostatniBlad || new Error('Nie udało się wysłać pliku.');
            },

            losowyId() {
                let id = '';
                for (let i = 0; i < 32; i++) {
                    id += Math.floor(Math.random() * 16).toString(16);
                }
                return id;
            },

            /** Zamienia fragment pliku na base64url (bez znaków wymagających kodowania). */
            doBase64url(blob) {
                return new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onerror = () => reject(new Error('Nie udało się odczytać pliku z dysku.'));
                    reader.onload = () => {
                        const wynik = String(reader.result);
                        resolve(wynik.slice(wynik.indexOf(',') + 1).replace(/\+/g, '-').replace(/\//g, '_'));
                    };
                    reader.readAsDataURL(blob);
                });
            },

            /**
             * Wysyłka w małych porcjach. Każdy fragment leci osobnym żądaniem
             * JSON — tak samo jak reszta panelu, więc przechodzi nawet przez
             * serwery obcinające duże żądania. Gdy pierwszy fragment nie
             * dojdzie, zmniejszamy porcję i próbujemy jeszcze raz.
             */
            async wyslijFragmentami(file, taskId) {
                /* Schodzimy aż do 4 kB — bywają serwery obcinające naprawdę
                   drobne żądania, a lepiej wysłać wolno niż wcale. */
                const MIN = 4 * 1024;
                let rozmiarPorcji = 256 * 1024;
                const id = this.losowyId();

                let pozycja = 0;
                let indeks = 0;

                for (;;) {
                    const koniec = Math.min(pozycja + rozmiarPorcji, file.size);
                    const ostatni = koniec >= file.size;
                    const dane = await this.doBase64url(file.slice(pozycja, koniec));

                    let odpowiedz;
                    try {
                        odpowiedz = await this.api('file.chunk', {
                            upload_id: id,
                            index: indeks,
                            name: file.name,
                            folder_id: this.current.id,
                            task_id: taskId || null,
                            data: dane,
                            final: ostatni
                        }, 'POST');
                    } catch (e) {
                        /* Pierwszy fragment jeszcze nic nie zapisał, więc możemy
                           bezpiecznie spróbować z mniejszą porcją. */
                        if (indeks === 0 && rozmiarPorcji > MIN) {
                            rozmiarPorcji = Math.floor(rozmiarPorcji / 2);
                            continue;
                        }
                        if (indeks === 0) {
                            e.mozliwaPonownaProba = true;   // niech zadziała następna metoda
                        }
                        throw e;
                    }

                    pozycja = koniec;
                    indeks++;

                    const pct = Math.round((pozycja / Math.max(1, file.size)) * 100);
                    if (taskId) this.taskUploadPct = pct;
                    else        this.uploadPct = pct;

                    if (ostatni) return odpowiedz;
                }
            },

            /** Wspólna obsługa odpowiedzi obu metod wysyłki. */
            odbierzOdpowiedz(xhr, resolve, reject) {
                let data;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (err) {
                    const blad = new Error(
                        xhr.status === 413
                            ? 'Serwer odrzucił plik jako zbyt duży (HTTP 413). Podnieś limity PHP — README.md, punkt 6.'
                            : xhr.status === 0
                                ? 'Połączenie przerwane w trakcie wysyłania.'
                                : 'Serwer zwrócił nieoczekiwaną odpowiedź (HTTP ' + xhr.status
                                  + '). Uruchom diagnostyka.php, żeby sprawdzić konfigurację hostingu.'
                    );
                    blad.mozliwaPonownaProba = true;   // brak JSON-a = problem po stronie serwera
                    reject(blad);
                    return;
                }
                if (xhr.status >= 200 && xhr.status < 300 && data.ok !== false) {
                    resolve(data);
                    return;
                }

                /* Serwer odpowiedział sensownie — to zwykle błąd merytoryczny
                   i powtarzanie niczego nie zmieni. Wyjątkiem jest znacznik
                   [retry:...], którym API prosi o inną metodę wysyłki. */
                const tresc = String(data.error || 'Nie udało się wysłać pliku.');
                const blad = new Error(tresc.replace(/\s*\[retry:[a-z0-9]+\]/i, ''));
                if (/\[retry:[a-z0-9]+\]/i.test(tresc)) {
                    blad.mozliwaPonownaProba = true;
                }
                reject(blad);
            },

            postep(taskId) {
                return (e) => {
                    if (!e.lengthComputable) return;
                    const pct = Math.round((e.loaded / e.total) * 100);
                    if (taskId) this.taskUploadPct = pct;
                    else        this.uploadPct = pct;
                };
            },

            /** Surowy strumień — PHP nie tworzy pliku tymczasowego. */
            wyslijStrumien(file, taskId) {
                return new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', 'api.php?action=file.upload');
                    xhr.setRequestHeader('Content-Type', 'application/octet-stream');
                    xhr.setRequestHeader('X-CSRF-Token', this.csrf);
                    xhr.setRequestHeader('X-Folder-Id', String(this.current.id));
                    if (taskId) xhr.setRequestHeader('X-Task-Id', String(taskId));
                    /* Nagłówki muszą być ASCII, więc nazwę kodujemy procentowo. */
                    xhr.setRequestHeader('X-File-Name', encodeURIComponent(file.name));

                    xhr.upload.onprogress = this.postep(taskId);
                    xhr.onload = () => this.odbierzOdpowiedz(xhr, resolve, reject);
                    xhr.onerror = () => {
                        const blad = new Error('Połączenie zostało przerwane podczas wysyłania.');
                        blad.mozliwaPonownaProba = true;
                        reject(blad);
                    };
                    xhr.send(file);
                });
            },

            /**
             * Plik zakodowany w base64 jako pole zwykłego formularza.
             * Nie wymaga ani katalogu tymczasowego, ani php://input — działa
             * tam, gdzie dwie pozostałe metody się wykładają.
             */
            wyslijBase64(file, taskId) {
                return new Promise((resolve, reject) => {
                    this.doBase64url(file).then((dane) => {
                        const tresc = 'b64_name=' + encodeURIComponent(file.name)
                            + '&folder_id=' + encodeURIComponent(this.current.id)
                            + (taskId ? '&task_id=' + encodeURIComponent(taskId) : '')
                            + '&csrf=' + encodeURIComponent(this.csrf)
                            + '&b64_data=' + dane;

                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', 'api.php?action=file.upload');
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.setRequestHeader('X-CSRF-Token', this.csrf);

                        xhr.upload.onprogress = this.postep(taskId);
                        xhr.onload = () => this.odbierzOdpowiedz(xhr, resolve, reject);
                        xhr.onerror = () => {
                            const blad = new Error('Połączenie zostało przerwane podczas wysyłania.');
                            blad.mozliwaPonownaProba = true;
                            reject(blad);
                        };
                        xhr.send(tresc);
                    }).catch(reject);
                });
            },

            /** Klasyczny formularz multipart — używany tylko awaryjnie. */
            wyslijFormularz(file, taskId) {
                return new Promise((resolve, reject) => {
                    const form = new FormData();
                    form.append('folder_id', String(this.current.id));
                    form.append('csrf', this.csrf);
                    if (taskId) form.append('task_id', String(taskId));
                    form.append('file', file);

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', 'api.php?action=file.upload');
                    xhr.setRequestHeader('X-CSRF-Token', this.csrf);

                    xhr.upload.onprogress = this.postep(taskId);
                    xhr.onload = () => this.odbierzOdpowiedz(xhr, resolve, reject);
                    xhr.onerror = () => reject(new Error('Połączenie zostało przerwane podczas wysyłania.'));
                    xhr.send(form);
                });
            },

            /** Podpina plik, który już leży w folderze, pod otwarte zadanie. */
            async attachFile(file) {
                if (!this.task.id) return;
                try {
                    this.apply(await this.api('file.assign', { id: file.id, task_id: this.task.id }, 'POST'));
                    this.filePickerOpen = false;
                    this.toast('Podpięto: ' + file.name);
                } catch (e) { this.toast(e.message, 'error'); }
            },

            /** Odpina plik od zadania — zostaje w folderze, nic nie ginie. */
            async detachFile(file) {
                try {
                    this.apply(await this.api('file.assign', { id: file.id, task_id: null }, 'POST'));
                    this.toast('Odpięto: ' + file.name + '. Plik został w zakładce Pliki.');
                } catch (e) { this.toast(e.message, 'error'); }
            },

            /* ------------------- podgląd dokumentu Worda ------------------- */

            /**
             * Zamienia .docx na HTML w przeglądarce. Plik pobieramy z naszego
             * serwera (po sprawdzeniu sesji) i przerabiamy lokalnie — dokument
             * nigdzie nie jest wysyłany.
             */
            async pokazDocx(file) {
                this.docx = { open: true, name: file.name, url: file.url, html: '', loading: true, error: '' };
                try {
                    const odpowiedz = await fetch(file.url, { credentials: 'same-origin' });
                    if (!odpowiedz.ok) {
                        throw new Error('Nie udało się pobrać pliku z serwera (HTTP ' + odpowiedz.status + ').');
                    }

                    const xml = await this.wyjmijTrescDocx(await odpowiedz.arrayBuffer());
                    const czysty = this.oczyscHtml(this.docxNaHtml(xml));

                    this.docx.html = czysty.trim()
                        ? czysty
                        : '<p class="text-faint">Ten dokument nie zawiera tekstu możliwego do wyświetlenia.</p>';
                } catch (e) {
                    this.docx.error = e.message || 'Nie udało się otworzyć dokumentu.';
                } finally {
                    this.docx.loading = false;
                }
            },

            /**
             * Wyciąga word/document.xml z pliku .docx (czyli z archiwum ZIP).
             *
             * Czytamy archiwum samodzielnie i rozpakowujemy wbudowanym w
             * przeglądarkę DecompressionStream. Bez zewnętrznej biblioteki:
             * jedna zależność mniej, działa też bez dostępu do CDN.
             */
            async wyjmijTrescDocx(bufor) {
                const widok = new DataView(bufor);
                const bajty = new Uint8Array(bufor);

                if (bajty.length < 22 || widok.getUint32(0, true) !== 0x04034b50) {
                    throw new Error('To nie wygląda na poprawny plik .docx.');
                }

                /* Koniec katalogu centralnego szukamy od końca pliku. */
                let eocd = -1;
                const granica = Math.max(0, bajty.length - 65558);
                for (let i = bajty.length - 22; i >= granica; i--) {
                    if (widok.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
                }
                if (eocd < 0) {
                    throw new Error('Uszkodzona struktura pliku .docx.');
                }

                const wpisow = widok.getUint16(eocd + 10, true);
                let pozycja = widok.getUint32(eocd + 16, true);
                let wpis = null;

                for (let i = 0; i < wpisow; i++) {
                    if (pozycja + 46 > bajty.length || widok.getUint32(pozycja, true) !== 0x02014b50) break;

                    const dlNazwy = widok.getUint16(pozycja + 28, true);
                    const nazwa = new TextDecoder('utf-8').decode(bajty.subarray(pozycja + 46, pozycja + 46 + dlNazwy));

                    if (nazwa === 'word/document.xml') {
                        wpis = {
                            metoda: widok.getUint16(pozycja + 10, true),
                            rozmiarSpakowany: widok.getUint32(pozycja + 20, true),
                            offset: widok.getUint32(pozycja + 42, true)
                        };
                        break;
                    }
                    pozycja += 46 + dlNazwy + widok.getUint16(pozycja + 30, true) + widok.getUint16(pozycja + 32, true);
                }

                if (!wpis) {
                    throw new Error('W dokumencie brakuje głównej treści (word/document.xml).');
                }
                if (widok.getUint32(wpis.offset, true) !== 0x04034b50) {
                    throw new Error('Uszkodzona struktura pliku .docx.');
                }

                const start = wpis.offset + 30
                    + widok.getUint16(wpis.offset + 26, true)
                    + widok.getUint16(wpis.offset + 28, true);
                const spakowane = bajty.subarray(start, start + wpis.rozmiarSpakowany);

                if (wpis.metoda === 0) {
                    return new TextDecoder('utf-8').decode(spakowane);
                }
                if (wpis.metoda !== 8) {
                    throw new Error('Dokument użył nieobsługiwanej kompresji. Pobierz plik i otwórz w Wordzie.');
                }
                if (typeof DecompressionStream === 'undefined') {
                    throw new Error('Ta przeglądarka nie potrafi rozpakować dokumentu. Pobierz plik i otwórz w Wordzie.');
                }

                try {
                    const strumien = new Blob([spakowane]).stream()
                        .pipeThrough(new DecompressionStream('deflate-raw'));
                    return new TextDecoder('utf-8').decode(await new Response(strumien).arrayBuffer());
                } catch (e) {
                    /* Rozpakowanie wykłada się tylko na uszkodzonych danych. */
                    throw new Error('Dokument jest uszkodzony i nie da się go rozpakować. Pobierz plik i sprawdź go w Wordzie.');
                }
            },

            /** Zamienia treść dokumentu Worda (OOXML) na czytelny HTML. */
            docxNaHtml(xml) {
                const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
                const dokument = new DOMParser().parseFromString(xml, 'application/xml');
                if (dokument.getElementsByTagName('parsererror').length) {
                    throw new Error('Nie udało się odczytać treści dokumentu.');
                }

                const esc = (s) => String(s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                const dziecko = (el, nazwa) => {
                    for (const w of Array.from(el.childNodes)) {
                        if (w.localName === nazwa && w.namespaceURI === W) return w;
                    }
                    return null;
                };

                /* Składa tekst akapitu, zachowując pogrubienie i kursywę. */
                const trescAkapitu = (p) => {
                    let out = '';
                    for (const r of Array.from(p.getElementsByTagNameNS(W, 'r'))) {
                        const wlasciwosci = dziecko(r, 'rPr');
                        const pogrubienie = wlasciwosci && dziecko(wlasciwosci, 'b');
                        const kursywa = wlasciwosci && dziecko(wlasciwosci, 'i');

                        let tekst = '';
                        for (const w of Array.from(r.childNodes)) {
                            if (w.namespaceURI !== W) continue;
                            if (w.localName === 't') tekst += w.textContent;
                            else if (w.localName === 'tab') tekst += '\t';
                            else if (w.localName === 'br' || w.localName === 'cr') tekst += '\n';
                        }
                        if (tekst === '') continue;

                        let fragment = esc(tekst).replace(/\n/g, '<br>').replace(/\t/g, '&nbsp;&nbsp;&nbsp;&nbsp;');
                        if (pogrubienie) fragment = '<strong>' + fragment + '</strong>';
                        if (kursywa) fragment = '<em>' + fragment + '</em>';
                        out += fragment;
                    }
                    return out;
                };

                /* Poziom nagłówka bierzemy ze stylu albo z poziomu konspektu. */
                const poziomNaglowka = (p) => {
                    const wlasciwosci = dziecko(p, 'pPr');
                    if (!wlasciwosci) return 0;

                    const styl = dziecko(wlasciwosci, 'pStyle');
                    if (styl) {
                        const wartosc = styl.getAttributeNS(W, 'val') || '';
                        const trafienie = wartosc.match(/^(?:heading|Nag)\D*(\d)/i);
                        if (trafienie) return Math.min(6, parseInt(trafienie[1], 10));
                    }
                    const konspekt = dziecko(wlasciwosci, 'outlineLvl');
                    if (konspekt) {
                        const lvl = parseInt(konspekt.getAttributeNS(W, 'val') || '', 10);
                        if (!isNaN(lvl) && lvl < 6) return lvl + 1;
                    }
                    return 0;
                };

                const czyPunktListy = (p) => {
                    const wlasciwosci = dziecko(p, 'pPr');
                    return !!(wlasciwosci && dziecko(wlasciwosci, 'numPr'));
                };

                const cialo = dokument.getElementsByTagNameNS(W, 'body')[0];
                if (!cialo) throw new Error('Dokument nie zawiera treści.');

                let out = '';
                let listaOtwarta = false;
                const zamknijListe = () => { if (listaOtwarta) { out += '</ul>'; listaOtwarta = false; } };

                for (const el of Array.from(cialo.children)) {
                    if (el.namespaceURI !== W) continue;

                    if (el.localName === 'p') {
                        const tresc = trescAkapitu(el);
                        if (!tresc) continue;

                        if (czyPunktListy(el)) {
                            if (!listaOtwarta) { out += '<ul>'; listaOtwarta = true; }
                            out += '<li>' + tresc + '</li>';
                            continue;
                        }
                        zamknijListe();

                        const poziom = poziomNaglowka(el);
                        out += poziom
                            ? '<h' + poziom + '>' + tresc + '</h' + poziom + '>'
                            : '<p>' + tresc + '</p>';
                        continue;
                    }

                    if (el.localName === 'tbl') {
                        zamknijListe();
                        out += '<table>';
                        let pierwszyWiersz = true;
                        for (const wiersz of Array.from(el.getElementsByTagNameNS(W, 'tr'))) {
                            const znacznik = pierwszyWiersz ? 'th' : 'td';
                            out += '<tr>';
                            for (const komorka of Array.from(wiersz.getElementsByTagNameNS(W, 'tc'))) {
                                const czesci = Array.from(komorka.getElementsByTagNameNS(W, 'p'))
                                    .map(trescAkapitu).filter(Boolean);
                                out += '<' + znacznik + '>' + (czesci.join('<br>') || '&nbsp;') + '</' + znacznik + '>';
                            }
                            out += '</tr>';
                            pierwszyWiersz = false;
                        }
                        out += '</table>';
                    }
                }
                zamknijListe();

                return out;
            },

            /**
             * Przepuszcza tylko znaczniki, które mogą wyjść z dokumentu tekstowego.
             * Dokument bywa cudzy, więc traktujemy go jak treść niezaufaną.
             */
            oczyscHtml(html) {
                const pojemnik = document.createElement('div');
                pojemnik.innerHTML = String(html || '');

                const dozwolone = /^(P|BR|B|STRONG|I|EM|U|S|SUP|SUB|H1|H2|H3|H4|H5|H6|UL|OL|LI|TABLE|THEAD|TBODY|TFOOT|TR|TD|TH|A|IMG|BLOCKQUOTE|PRE|CODE|HR|SPAN|DIV)$/;

                Array.from(pojemnik.querySelectorAll('*')).forEach((el) => {
                    if (!dozwolone.test(el.tagName)) { el.remove(); return; }

                    Array.from(el.attributes).forEach((atrybut) => {
                        const nazwa = atrybut.name.toLowerCase();

                        if (nazwa.startsWith('on')) { el.removeAttribute(atrybut.name); return; }
                        if (nazwa === 'href') {
                            if (/^https?:/i.test(atrybut.value)) {
                                el.setAttribute('target', '_blank');
                                el.setAttribute('rel', 'noopener noreferrer');
                            } else {
                                el.removeAttribute(atrybut.name);
                            }
                            return;
                        }
                        if (nazwa === 'src') {
                            /* Obrazy z dokumentu przychodzą jako data:image — nic innego nie wpuszczamy. */
                            if (!/^data:image\//i.test(atrybut.value)) el.removeAttribute(atrybut.name);
                            return;
                        }
                        if (['colspan', 'rowspan', 'alt'].indexOf(nazwa) === -1) {
                            el.removeAttribute(atrybut.name);
                        }
                    });
                });

                return pojemnik.innerHTML;
            },

            deleteFile(file) {
                this.confirm({
                    title: 'Usunąć plik?',
                    message: '„' + file.name + '” zostanie skasowany z serwera na stałe. '
                        + 'Jeśli chcesz go tylko odłączyć od zadania, użyj przycisku odpinania.',
                    confirmText: 'Usuń',
                    onOk: async () => {
                        try {
                            this.apply(await this.api('file.delete', { id: file.id }, 'POST'));
                            this.toast('Plik usunięty.');
                        } catch (e) { this.toast(e.message, 'error'); }
                    }
                });
            },

            /* ------------------------- okienka / UI ------------------------ */
            prompt(config) {
                this.ask = Object.assign({}, this.ask, {
                    open: true, input: true, danger: false, message: '',
                    confirmText: 'Zapisz'
                }, config);
                this.$nextTick(() => this.$refs.askInput && this.$refs.askInput.select());
            },

            confirm(config) {
                this.ask = Object.assign({}, this.ask, {
                    open: true, input: false, danger: true, value: '',
                    confirmText: 'Usuń'
                }, config);
            },

            closeTop() {
                /* W pokoju Escape zamyka panele boczne, ale nigdy nie kończy
                   rozmowy — wyjście musi być świadomym kliknięciem. */
                if (this.room.open) {
                    if (this.notatka.konflikt) return;
                    if (this.room.notatkiOpen) { this.room.notatkiOpen = false; return; }
                    if (this.room.listaOpen)   { this.room.listaOpen = false; return; }
                    return;
                }
                if (this.ask.open) { this.ask.open = false; return; }
                if (this.notatka.open) { this.zamknijNotatkeSpotkania(); return; }
                if (this.form.open) { this.form.open = false; return; }
                if (this.docx.open) { this.docx.open = false; return; }
                if (this.filePickerOpen) { this.filePickerOpen = false; return; }
                if (this.task.open) { this.task.open = false; return; }
                if (this.feedOpen) { this.feedOpen = false; return; }
                if (this.sidebarOpen) { this.sidebarOpen = false; }
            },

            toast(text, type = 'success') {
                const id = ++this.toastSeq;
                this.toasts.push({ id, text, type });
                setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, type === 'error' ? 7000 : 3500);
            },

            /* --------------------------- formatery ------------------------- */
            accent(key) { return COLORS[key] || NEUTRAL; },

            chipStyle(key) {
                const c = this.accent(key);
                return this.dark
                    ? 'background:' + c.softDark + ';color:' + c.inkDark
                    : 'background:' + c.soft + ';color:' + c.ink;
            },

            priorityLabel(key) {
                const p = this.priorities.find(x => x.key === key);
                return p ? p.label : key;
            },

            priorityStyle(key) {
                const paleta = {
                    high:   { jasny: ['#fef2f2', '#b91c1c'], ciemny: ['#450a0a', '#fca5a5'] },
                    normal: { jasny: ['#f1f5f9', '#475569'], ciemny: ['#334155', '#cbd5e1'] },
                    low:    { jasny: ['#f0f9ff', '#0369a1'], ciemny: ['#082f49', '#7dd3fc'] }
                };
                const c = paleta[key] || paleta.normal;
                const [tlo, tekst] = this.dark ? c.ciemny : c.jasny;
                return 'background:' + tlo + ';color:' + tekst;
            },

            priorityIcon(key) {
                const sciezki = {
                    high:   '<path d="M12 19V5m0 0-6 6m6-6 6 6"/>',
                    normal: '<path d="M5 12h14"/>',
                    low:    '<path d="M12 5v14m0 0 6-6m-6 6-6-6"/>'
                };
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" '
                    + 'stroke-linecap="round" stroke-linejoin="round" class="h-3 w-3" aria-hidden="true">'
                    + (sciezki[key] || sciezki.normal) + '</svg>';
            },

            fileBadge(ext) {
                const map = {
                    pdf:  { jasny: ['#fef2f2', '#b91c1c'], ciemny: ['#450a0a', '#fca5a5'] },
                    docx: { jasny: ['#eff6ff', '#1d4ed8'], ciemny: ['#172554', '#93c5fd'] },
                    zip:  { jasny: ['#fffbeb', '#b45309'], ciemny: ['#451a03', '#fcd34d'] },
                    png:  { jasny: ['#ecfdf5', '#047857'], ciemny: ['#022c22', '#6ee7b7'] },
                    jpg:  { jasny: ['#ecfdf5', '#047857'], ciemny: ['#022c22', '#6ee7b7'] },
                    jpeg: { jasny: ['#ecfdf5', '#047857'], ciemny: ['#022c22', '#6ee7b7'] }
                };
                const c = map[String(ext).toLowerCase()] || { jasny: ['#f1f5f9', '#475569'], ciemny: ['#334155', '#cbd5e1'] };
                const [tlo, tekst] = this.dark ? c.ciemny : c.jasny;
                return 'background:' + tlo + ';color:' + tekst;
            },

            initials(name) {
                return String(name || '?').trim().charAt(0).toUpperCase();
            },

            /** Polska odmiana: 2–4 „osoby”, 5+ „osób” (liczy się końcówka liczby). */
            ileOsob(n) {
                const koncowka = n % 10;
                const dziesiatki = n % 100;
                const osoby = koncowka >= 2 && koncowka <= 4 && (dziesiatki < 12 || dziesiatki > 14);
                return n + (osoby ? ' osoby' : ' osób');
            },

            fmtSize(bytes) {
                bytes = Number(bytes) || 0;
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
                return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
            },

            hhmm(d) {
                return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
            },

            fmtFull(value) {
                if (!value) return '—';
                const d = new Date(value);
                if (isNaN(d)) return '—';
                return d.toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ', ' + this.hhmm(d);
            },

            fmtShort(value) {
                if (!value) return '—';
                const d = new Date(value);
                if (isNaN(d)) return '—';
                return d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' }) + ' ' + this.hhmm(d);
            },

            fmtRel(value) {
                if (!value) return '';
                const d = new Date(value);
                if (isNaN(d)) return '';
                const s = Math.round((Date.now() - d.getTime()) / 1000);
                if (s < 60)     return 'przed chwilą';
                if (s < 3600)   return Math.floor(s / 60) + ' min temu';
                if (s < 86400)  return Math.floor(s / 3600) + ' godz. temu';
                if (s < 172800) return 'wczoraj, ' + this.hhmm(d);
                if (s < 604800) return Math.floor(s / 86400) + ' dni temu';
                return this.fmtFull(value);
            },

            /** Minimalny, bezpieczny renderer Markdown (najpierw escapujemy HTML). */
            mdToHtml(src) {
                const esc = (s) => String(s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');

                const inline = (t) => t
                    .replace(/`([^`]+)`/g, '<code>$1</code>')
                    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                    .replace(/(^|[^*\w])\*([^*]+)\*/g, '$1<em>$2</em>')
                    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

                const lines = esc(src).split('\n');
                let out = '', list = null, fenced = false, buffer = [];
                const closeList = () => { if (list) { out += '</' + list + '>'; list = null; } };
                const openList = (kind) => { if (list !== kind) { closeList(); out += '<' + kind + '>'; list = kind; } };

                for (const raw of lines) {
                    if (raw.trim().startsWith('```')) {
                        if (fenced) { out += '<pre><code>' + buffer.join('\n') + '</code></pre>'; buffer = []; fenced = false; }
                        else { closeList(); fenced = true; }
                        continue;
                    }
                    if (fenced) { buffer.push(raw); continue; }

                    const line = raw.trim();
                    if (!line) { closeList(); continue; }

                    let m;
                    if ((m = line.match(/^(#{1,4})\s+(.+)$/))) {
                        closeList(); out += '<h' + m[1].length + '>' + inline(m[2]) + '</h' + m[1].length + '>'; continue;
                    }
                    if (/^([-*_]\s*){3,}$/.test(line)) { closeList(); out += '<hr>'; continue; }
                    if ((m = line.match(/^&gt;\s?(.*)$/))) { closeList(); out += '<blockquote><p>' + inline(m[1]) + '</p></blockquote>'; continue; }
                    if ((m = line.match(/^[-*+]\s+\[([ xX])\]\s+(.+)$/))) {
                        openList('ul');
                        out += '<li><input type="checkbox" disabled' + (m[1].toLowerCase() === 'x' ? ' checked' : '') + '> ' + inline(m[2]) + '</li>';
                        continue;
                    }
                    if ((m = line.match(/^[-*+]\s+(.+)$/)))   { openList('ul'); out += '<li>' + inline(m[1]) + '</li>'; continue; }
                    if ((m = line.match(/^\d+[.)]\s+(.+)$/))) { openList('ol'); out += '<li>' + inline(m[1]) + '</li>'; continue; }

                    closeList();
                    out += '<p>' + inline(line) + '</p>';
                }

                if (fenced && buffer.length) out += '<pre><code>' + buffer.join('\n') + '</code></pre>';
                closeList();
                return out;
            }
        }));
    });

    /* Gdyby CDN z Alpine.js był niedostępny — nie zostawiamy pustego ekranu. */
    setTimeout(function () {
        if (!document.body.classList.contains('ready')) {
            document.body.classList.add('ready');
            var boot = document.getElementById('boot');
            if (boot) {
                boot.style.display = 'flex';
                boot.innerHTML = '<div style="max-width:380px;text-align:center;padding:24px;font-size:14px;color:#475569">'
                    + '<p style="font-weight:600;margin-bottom:8px">Nie udało się wczytać biblioteki interfejsu</p>'
                    + '<p>Panel pobiera Tailwind CSS i Alpine.js z internetu. Sprawdź połączenie sieciowe i odśwież stronę (F5).</p></div>';
            }
        }
    }, 10000);
</script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js"></script>

</body>
<?php endif; ?>
</html>
