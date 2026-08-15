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
        'lock'     => '<path d="M16.5 10.5V6.8a4.5 4.5 0 1 0-9 0v3.7m-.8 11.3h10.5a2.3 2.3 0 0 0 2.3-2.3v-6.7a2.3 2.3 0 0 0-2.3-2.3H6.8a2.3 2.3 0 0 0-2.3 2.3v6.7a2.3 2.3 0 0 0 2.3 2.3Z"/>',
        'eye'      => '<path d="M2 12.3a1 1 0 0 1 0-.6C3.4 7.5 7.4 4.5 12 4.5c4.6 0 8.6 3 10 7.2a1 1 0 0 1 0 .6c-1.4 4.2-5.4 7.2-10 7.2-4.6 0-8.6-3-10-7.2Z"/><path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
        'arrow'    => '<path d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>',
        'inbox'    => '<path d="M2.3 15h3.9a2.3 2.3 0 0 1 2 1.2l.4.6a2.3 2.3 0 0 0 2 1.2h2.8a2.3 2.3 0 0 0 2-1.2l.4-.6a2.3 2.3 0 0 1 2-1.2h3.9m-16.5 0V6.8a2.3 2.3 0 0 1 2.3-2.3h12.8a2.3 2.3 0 0 1 2.2 2.3V15m-19.4 0v3.8a2.3 2.3 0 0 0 2.3 2.2h14.8a2.3 2.3 0 0 0 2.3-2.2V15"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2m0 15v2M4.6 4.6 6 6m12 12 1.4 1.4M2.5 12h2m15 0h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
        'moon'     => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
        'grip'     => '<path d="M4 9h16M4 15h16"/>',
        'prioHigh' => '<path d="M12 19V5m0 0-6 6m6-6 6 6"/>',
        'prioLow'  => '<path d="M12 5v14m0 0 6-6m-6 6-6-6"/>',
        'prioMid'  => '<path d="M5 12h14"/>',
    ];

    $d = $paths[$name] ?? '';
    return '<svg class="' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
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
                                current && current.id === f.id ? 'bg-brandsoft text-brandink' : 'text-ink2 hover:bg-surface3',
                                folderDragOver === f.id && folderDrag !== f.id ? 'ring-2 ring-brand-400' : ''
                            ]"
                            :title="folderQuery === '' ? 'Przeciągnij, aby zmienić kolejność' : ''"
                            class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2.5 pr-16 text-left transition">
                        <span :class="current && current.id === f.id ? 'text-brand-500' : 'text-faint'">
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
                    <template x-if="current">
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
                    <template x-if="!current">
                        <h1 class="text-lg font-semibold tracking-tight text-ink">Panel</h1>
                    </template>
                    <p x-show="current" class="truncate text-xs text-faint">
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

            <div x-show="current" class="flex items-center gap-1 overflow-x-auto px-4 pb-3 sm:px-6">
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
            </div>
        </header>

        <main class="min-h-0 flex-1 overflow-hidden">

            <!-- ---------- Stan pusty ---------- -->
            <div x-show="!current" x-cloak class="grid h-full place-items-center p-6">
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

            <!-- ---------- Tablica kanban ---------- -->
            <div x-show="current && tab === 'board'" x-cloak class="thin-scroll h-full overflow-x-auto p-4 sm:p-6">
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
            <div x-show="current && tab === 'note'" x-cloak class="thin-scroll h-full overflow-y-auto p-4 sm:p-6">
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
            <div x-show="current && tab === 'files'" x-cloak class="thin-scroll h-full overflow-y-auto p-4 sm:p-6">
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
                                        <a :href="f.preview" target="_blank" rel="noopener" title="Podgląd"
                                           class="rounded-lg p-2 text-faint transition hover:bg-surface3 hover:text-brand-600">
                                            <?= icon('eye', 'h-[18px] w-[18px]') ?>
                                        </a>
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
                todo:  { title: '', assignee_ids: [], priority: 'normal', open: false },
                doing: { title: '', assignee_ids: [], priority: 'normal', open: false },
                done:  { title: '', assignee_ids: [], priority: 'normal', open: false }
            },

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

            task: { open: false, id: null, title: '', description: '', status: 'todo', priority: 'normal', assignee_ids: [], saving: false, meta: {} },
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

                this.ask.submit = () => {
                    const fn = this.ask.onOk;
                    const value = this.ask.value;
                    this.ask.open = false;
                    if (fn) fn(value);
                };

                try {
                    const d = await this.api('bootstrap');
                    this.users    = d.users;
                    this.folders  = d.folders;
                    this.activity = d.activity;
                    this.stamp    = d.stamp;
                    this.limits   = d.limits;
                    this.csrf     = d.csrf;
                    this.seenStamp = Number(localStorage.getItem('panel.seen') || 0) || this.stamp;

                    const remembered = Number(localStorage.getItem('panel.folder') || 0);
                    const target = this.folders.find(f => f.id === remembered) || this.folders[0];
                    if (target) await this.loadFolder(target.id);
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
                    if (this.noteDirty) { e.preventDefault(); e.returnValue = ''; }
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
                if (d.tasks)    this.tasks    = d.tasks;
                if (d.files)    this.files    = d.files;
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
                this.folders  = d.folders;
                this.activity = d.activity;
                this.stamp    = d.stamp;
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
                if (this.current && this.current.id === id) { this.sidebarOpen = false; return; }
                if (this.noteDirty) await this.saveNote(true);
                await this.loadFolder(id);
                this.sidebarOpen = false;
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
            tasksIn(status) {
                const waga = { high: 0, normal: 1, low: 2 };
                const list = this.tasks.filter(t => t.status === status);
                list.sort((a, b) => {
                    const roznica = (waga[a.priority] ?? 1) - (waga[b.priority] ?? 1);
                    if (roznica !== 0) return roznica;
                    return status === 'done'
                        ? String(b.updated_at || '').localeCompare(String(a.updated_at || ''))
                        : a.id - b.id;
                });
                return list;
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
                    assignee_ids: (t.assignees || []).map(a => a.id),
                    saving: false,
                    meta: {
                        created_by_name: t.created_by_name,
                        created_at: t.created_at,
                        updated_by_name: t.updated_by_name,
                        updated_at: t.updated_at
                    }
                };
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
            async sendFile(file, taskId = null) {
                /* Kolejność prób: strumień → base64 → klasyczny formularz.
                   Pierwsza działa wszędzie tam, gdzie hosting nie ma katalogu
                   tymczasowego; druga dodatkowo nie wymaga php://input;
                   trzecia to klasyka na wypadek, gdyby zawiodły obie. */
                const proby = [
                    () => this.wyslijStrumien(file, taskId),
                    () => this.wyslijBase64(file, taskId),
                    () => this.wyslijFormularz(file, taskId)
                ];

                let ostatniBlad = null;
                for (const probuj of proby) {
                    try {
                        return await probuj();
                    } catch (e) {
                        if (!e.mozliwaPonownaProba) throw e;   // błąd merytoryczny — nie ma co powtarzać
                        ostatniBlad = e;
                    }
                }
                throw ostatniBlad || new Error('Nie udało się wysłać pliku.');
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
                    const reader = new FileReader();

                    reader.onerror = () => reject(new Error('Nie udało się odczytać pliku z dysku.'));
                    reader.onload = () => {
                        const wynik = String(reader.result);
                        const b64 = wynik.slice(wynik.indexOf(',') + 1);
                        /* Wariant base64url — bez znaków wymagających kodowania. */
                        const dane = b64.replace(/\+/g, '-').replace(/\//g, '_');

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
                    };

                    reader.readAsDataURL(file);
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
                if (this.ask.open) { this.ask.open = false; return; }
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
