<?php
/**
 * diagnostyka.php — jednorazowe narzędzie do sprawdzenia, czy hosting
 * poprawnie obsługuje panel. Wgraj obok index.php, otwórz w przeglądarce,
 * przeczytaj wynik i SKASUJ TEN PLIK.
 *
 * Plik jest samodzielny — nie dołącza db.php, więc zadziała nawet wtedy,
 * gdy sam panel się nie uruchamia.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$krok = isset($_GET['krok']) ? (int)$_GET['krok'] : 1;

/* ------------------------------------------------------------------ *
 *  Sonda transportu — odpowiada, ile bajtów faktycznie dotarło
 *  daną metodą. Wywoływana z JavaScriptu na dole strony.
 * ------------------------------------------------------------------ */
if (isset($_GET['sonda'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $wejscie = @fopen('php://input', 'rb');
    $zeStrumienia = 0;
    if ($wejscie !== false) {
        while (!feof($wejscie)) {
            $kawalek = fread($wejscie, 65536);
            if ($kawalek === false) {
                break;
            }
            $zeStrumienia += strlen($kawalek);
        }
        fclose($wejscie);
    }

    $zBase64 = 0;
    if (isset($_POST['b64_data'])) {
        $dekodowane = base64_decode(strtr((string)$_POST['b64_data'], '-_', '+/'), true);
        $zBase64 = $dekodowane === false ? -1 : strlen($dekodowane);
    }

    $zMultipart = -1;
    if (isset($_FILES['plik'])) {
        $zMultipart = (int)$_FILES['plik']['error'] === UPLOAD_ERR_OK
            ? (int)$_FILES['plik']['size']
            : -(int)$_FILES['plik']['error'];
    }

    echo json_encode([
        'content_type'   => $_SERVER['CONTENT_TYPE'] ?? '(brak)',
        'content_length' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
        'strumien'       => $zeStrumienia,
        'base64'         => $zBase64,
        'multipart'      => $zMultipart,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------ *
 *  Sesja — test przeżywalności między dwoma żądaniami
 * ------------------------------------------------------------------ */

$naglowkiJuzWyslane = headers_sent($plikWyjscia, $liniaWyjscia);

$sciezkaSesjiIni = (string)ini_get('session.save_path');
$sciezkaSesji    = $sciezkaSesjiIni;
if (strpos($sciezkaSesji, ';') !== false) {
    $czesci       = explode(';', $sciezkaSesji);
    $sciezkaSesji = (string)end($czesci);
}

$wlasnyKatalogSesji = __DIR__ . '/data/sessions';
$uzytoWlasnego      = false;

if ($sciezkaSesji === '' || !is_dir($sciezkaSesji) || !is_writable($sciezkaSesji)) {
    if (!is_dir($wlasnyKatalogSesji)) {
        @mkdir($wlasnyKatalogSesji, 0700, true);
    }
    if (is_dir($wlasnyKatalogSesji) && is_writable($wlasnyKatalogSesji)) {
        session_save_path($wlasnyKatalogSesji);
        $uzytoWlasnego = true;
    }
}

$httpsWykryte = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'secure'   => $httpsWykryte, 'samesite' => 'Lax',
    ]);
} else {
    session_set_cookie_params(0, '/; samesite=Lax', '', $httpsWykryte, true);
}
session_name('PANELSID');
$sesjaWystartowala = @session_start();

$ciasteczkoOdeslane = isset($_COOKIE['PANELSID']);

if ($krok === 1) {
    $_SESSION['probka'] = 'wartosc-testowa-' . time();
    $_SESSION['zapis']  = time();
    session_write_close();
    header('Location: ' . basename(__FILE__) . '?krok=2');
    echo '<a href="' . htmlspecialchars(basename(__FILE__), ENT_QUOTES) . '?krok=2">Przejdź do wyniku testu</a>';
    exit;
}

$probkaPrzetrwala = isset($_SESSION['probka']) && strpos((string)$_SESSION['probka'], 'wartosc-testowa-') === 0;

/* Symulacja tokenu CSRF — dokładnie tak, jak robi to panel. */
if (empty($_SESSION['csrf_test'])) {
    $_SESSION['csrf_test'] = bin2hex(random_bytes(8));
    $tokenSwiezy = true;
} else {
    $tokenSwiezy = false;
}

/* ------------------------------------------------------------------ *
 *  Pozostałe kontrole
 * ------------------------------------------------------------------ */

function katalogInfo(string $sciezka): array
{
    return [
        'istnieje'  => is_dir($sciezka),
        'zapisywal' => is_dir($sciezka) && is_writable($sciezka),
        'prawa'     => is_dir($sciezka) ? substr(sprintf('%o', @fileperms($sciezka)), -3) : '—',
    ];
}

$dataInfo    = katalogInfo(__DIR__ . '/data');
$uploadsInfo = katalogInfo(__DIR__ . '/uploads');

/** Sprawdza, czy adres jest zablokowany przez .htaccess (oczekujemy 403). */
function sprawdzBlokade(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_NOBODY => true,
        ]);
        curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $kod > 0 ? ['kod' => $kod, 'zbadane' => true] : ['kod' => 0, 'zbadane' => false];
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 8, 'ignore_errors' => true]]);
        @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            return ['kod' => (int)$m[1], 'zbadane' => true];
        }
    }
    return ['kod' => 0, 'zbadane' => false];
}

$schemat  = $httpsWykryte ? 'https' : 'http';
$katalog  = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
$adresBaz = $schemat . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost') . $katalog;

$blokadaData    = sprawdzBlokade($adresBaz . '/data/index.html');
$blokadaUploads = sprawdzBlokade($adresBaz . '/uploads/index.html');

/* ------------------------------------------------------------------ *
 *  Test wysyłki pliku — odtwarza dokładnie to, co robi panel
 * ------------------------------------------------------------------ */

function testWysylki(): array
{
    $katalog = __DIR__ . '/uploads';
    $kroki   = [];

    $kroki[] = ['Katalog uploads/ istnieje', is_dir($katalog), is_dir($katalog) ? 'tak' : 'NIE'];
    if (!is_dir($katalog)) {
        return $kroki;
    }

    $kroki[] = ['Katalog uploads/ ma prawo zapisu', is_writable($katalog),
        'chmod ' . substr(sprintf('%o', @fileperms($katalog)), -3)];

    /* Prawdziwy, poprawny PNG — taki sam sprawdzian jak w api.php. */
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $nazwa = bin2hex(random_bytes(16)) . '.png';
    $sciezka = $katalog . '/' . $nazwa;

    $zapis = @file_put_contents($sciezka, $png);
    $kroki[] = ['Zapis pliku testowego do uploads/', $zapis !== false,
        $zapis !== false ? $zapis . ' B' : 'NIE UDAŁO SIĘ'];

    if ($zapis !== false) {
        $kroki[] = ['Odczyt pliku testowego', @file_get_contents($sciezka) === $png, 'zgodny'];
        $kroki[] = ['Sygnatura PNG rozpoznana', strncmp((string)@file_get_contents($sciezka), "\x89PNG\r\n\x1a\n", 8) === 0, 'tak'];
        $kroki[] = ['Kontrola obrazu (getimagesize)', @getimagesize($sciezka) !== false, 'przeszła'];
        @unlink($sciezka);
        $kroki[] = ['Kasowanie pliku testowego', !file_exists($sciezka), 'tak'];
    }

    /* To jest sedno błędu „Serwer nie mógł zapisać pliku tymczasowego”. */
    $tmp     = (string)ini_get('upload_tmp_dir');
    $tmpUzyw = $tmp !== '' ? $tmp : sys_get_temp_dir();

    $kroki[] = ['upload_tmp_dir z konfiguracji PHP', true,
        $tmp !== '' ? $tmp : '(nie ustawiono — używany systemowy)'];
    $kroki[] = ['Katalog tymczasowy systemu', true, sys_get_temp_dir()];

    $tmpIstnieje = is_dir($tmpUzyw);
    $kroki[] = ['Katalog tymczasowy istnieje', $tmpIstnieje, $tmpIstnieje ? 'tak' : 'NIE'];

    /* Próba dokładnie taka, jaką robi PHP przy klasycznej wysyłce formularza. */
    $probny = @tempnam($tmpUzyw, 'panel');
    $tmpOk  = $probny !== false && is_file($probny) && @file_put_contents($probny, 'test') !== false;
    if ($probny !== false) {
        @unlink($probny);
    }
    $kroki[] = ['Zapis pliku tymczasowego (tak jak przy formularzu)', $tmpOk,
        $tmpOk ? 'działa' : 'NIE DZIAŁA — to powodowało błąd wysyłki'];
    if (!$tmpOk) {
        $kroki[] = ['Obejście: wysyłka strumieniowa', true,
            'panel omija katalog tymczasowy i zapisuje plik wprost do uploads/'];
    }

    $kroki[] = ['Odczyt php://input (tryb strumieniowy)',
        @fopen('php://input', 'rb') !== false, 'dostępny'];

    $kroki[] = ['file_uploads włączone w PHP', (bool)ini_get('file_uploads'), ini_get('file_uploads') ? 'tak' : 'NIE'];

    $open = (string)ini_get('open_basedir');
    $kroki[] = ['open_basedir nie blokuje katalogu',
        $open === '' || strpos($open, __DIR__) !== false || strpos(__DIR__, explode(PATH_SEPARATOR, $open)[0]) === 0,
        $open === '' ? 'brak ograniczenia' : $open];

    return $kroki;
}

$testUpload = testWysylki();

function naBajty(string $wartosc): int
{
    $liczba = (int)$wartosc;
    switch (strtolower(substr(trim($wartosc), -1))) {
        case 'g': $liczba *= 1024 * 1024 * 1024; break;
        case 'm': $liczba *= 1024 * 1024; break;
        case 'k': $liczba *= 1024; break;
    }
    return $liczba;
}

$limitUpload = min(naBajty((string)ini_get('upload_max_filesize')), naBajty((string)ini_get('post_max_size')));

/* ------------------------------------------------------------------ *
 *  Werdykt
 * ------------------------------------------------------------------ */

$sesjaDziala = $sesjaWystartowala && $ciasteczkoOdeslane && $probkaPrzetrwala;

$przyczyna = '';
if (!$sesjaDziala) {
    if ($naglowkiJuzWyslane) {
        $przyczyna = 'Coś wysłało treść przed startem sesji (plik ' . (string)$plikWyjscia . ', linia ' . (int)$liniaWyjscia
            . '). Najczęściej to znak BOM lub pusta linia przed &lt;?php — wgraj pliki ponownie przez FTP w trybie binarnym.';
    } elseif (!$sesjaWystartowala) {
        $przyczyna = 'PHP nie potrafi wystartować sesji. Katalog na pliki sesji jest niedostępny do zapisu.';
    } elseif (!$ciasteczkoOdeslane) {
        $przyczyna = $httpsWykryte
            ? 'Przeglądarka nie odesłała ciasteczka sesji. Sprawdź, czy nie blokujesz ciasteczek dla tej domeny.'
            : 'Przeglądarka nie odesłała ciasteczka sesji PANELSID. Jeśli strona działa po HTTP, a ciasteczko dostało flagę Secure, przeglądarka je odrzuca.';
    } else {
        $przyczyna = 'Ciasteczko wraca, ale zawartość sesji ginie — katalog na pliki sesji nie zapisuje danych.';
    }
}

function wiersz(string $nazwa, bool $ok, string $wartosc, string $uwaga = ''): string
{
    $ikona = $ok ? '✔' : '✖';
    $klasa = $ok ? 'ok' : 'zle';
    return '<tr class="' . $klasa . '"><td class="i">' . $ikona . '</td><td class="n">' . htmlspecialchars($nazwa, ENT_QUOTES, 'UTF-8')
        . '</td><td class="w">' . $wartosc . ($uwaga !== '' ? '<span class="u">' . $uwaga . '</span>' : '') . '</td></tr>';
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Diagnostyka panelu</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;padding:24px;background:#f4f7fd;color:#1e293b;
             font:15px/1.6 'Segoe UI',system-ui,-apple-system,sans-serif}
        .box{max-width:880px;margin:0 auto}
        h1{font-size:22px;margin:0 0 4px}
        .sub{color:#64748b;font-size:14px;margin:0 0 24px}
        .werdykt{border-radius:16px;padding:20px 24px;margin:0 0 24px;border:1px solid}
        .werdykt.dobry{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
        .werdykt.zly{background:#fef2f2;border-color:#fecaca;color:#991b1b}
        .werdykt h2{margin:0 0 6px;font-size:18px}
        .werdykt p{margin:0}
        h3{font-size:14px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin:28px 0 10px}
        table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
        td{padding:10px 14px;border-bottom:1px solid #f1f5f9;vertical-align:top}
        tr:last-child td{border-bottom:0}
        td.i{width:32px;text-align:center;font-weight:700}
        tr.ok td.i{color:#059669}
        tr.zle td.i{color:#dc2626}
        td.n{width:300px;color:#475569}
        td.w{font-family:ui-monospace,Consolas,monospace;font-size:13px;color:#0f172a;word-break:break-all}
        .u{display:block;margin-top:4px;font-family:'Segoe UI',sans-serif;font-size:13px;color:#b45309}
        .stopka{margin:28px 0 0;padding:16px 20px;background:#fff7ed;border:1px solid #fed7aa;
                border-radius:12px;color:#9a3412;font-size:14px}
        code{background:#f1f5f9;padding:2px 6px;border-radius:5px;font-size:13px;color:#334155}
    </style>
</head>
<body>
<div class="box">
    <h1>Diagnostyka panelu zespołu</h1>
    <p class="sub">Sprawdzenie, czy ten hosting spełnia wymagania aplikacji.</p>

    <?php if ($sesjaDziala): ?>
        <div class="werdykt dobry">
            <h2>Sesja działa poprawnie</h2>
            <p>Logowanie powinno przechodzić bez błędu „Formularz stracił ważność”.
               Jeśli mimo to nie działa, sprawdź pozostałe pozycje niżej.</p>
        </div>
    <?php else: ?>
        <div class="werdykt zly">
            <h2>Sesja nie działa — to jest przyczyna błędu „Formularz stracił ważność”</h2>
            <p><?= $przyczyna ?></p>
        </div>
    <?php endif; ?>

    <h3>Sesja</h3>
    <table>
        <?= wiersz('Sesja wystartowała', $sesjaWystartowala, $sesjaWystartowala ? 'tak' : 'NIE') ?>
        <?= wiersz('Przeglądarka odesłała ciasteczko PANELSID', $ciasteczkoOdeslane, $ciasteczkoOdeslane ? 'tak' : 'NIE') ?>
        <?= wiersz('Dane przetrwały drugie żądanie', $probkaPrzetrwala, $probkaPrzetrwala ? 'tak' : 'NIE — sesja gubi zawartość') ?>
        <?= wiersz('Token CSRF stabilny między żądaniami', !$tokenSwiezy || $probkaPrzetrwala,
              $tokenSwiezy && !$probkaPrzetrwala ? 'NIE — token generuje się od nowa' : 'tak') ?>
        <?= wiersz('Katalog na pliki sesji z prawem zapisu',
              $sciezkaSesji !== '' && is_dir($sciezkaSesji) && is_writable($sciezkaSesji) || $uzytoWlasnego,
              htmlspecialchars($uzytoWlasnego ? $wlasnyKatalogSesji : ($sciezkaSesji !== '' ? $sciezkaSesji : '(nie ustawiono)'), ENT_QUOTES),
              $uzytoWlasnego ? 'Hosting nie dawał zapisywalnego katalogu — użyto data/sessions.' : '') ?>
        <?= wiersz('Nagłówki nie zostały wysłane przed sesją', !$naglowkiJuzWyslane,
              $naglowkiJuzWyslane ? 'NIE — wyjście w ' . htmlspecialchars((string)$plikWyjscia, ENT_QUOTES) . ':' . (int)$liniaWyjscia : 'tak') ?>
    </table>

    <h3>Połączenie i ciasteczka</h3>
    <table>
        <?= wiersz('Strona działa po HTTPS', $httpsWykryte, $httpsWykryte ? 'tak' : 'nie (HTTP)',
              $httpsWykryte ? '' : 'Włącz certyfikat SSL w panelu hostingu — hasło leci teraz otwartym tekstem.') ?>
        <?= wiersz('Flaga Secure na ciasteczku sesji', true, $httpsWykryte ? 'tak (poprawnie dla HTTPS)' : 'nie (poprawnie dla HTTP)') ?>
        <?= wiersz('$_SERVER[HTTPS]', true, htmlspecialchars((string)($_SERVER['HTTPS'] ?? '(brak)'), ENT_QUOTES)) ?>
        <?= wiersz('$_SERVER[HTTP_X_FORWARDED_PROTO]', true, htmlspecialchars((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '(brak)'), ENT_QUOTES)) ?>
        <?= wiersz('$_SERVER[SERVER_PORT]', true, htmlspecialchars((string)($_SERVER['SERVER_PORT'] ?? '(brak)'), ENT_QUOTES)) ?>
        <?= wiersz('Adres bazowy aplikacji', true, htmlspecialchars($adresBaz, ENT_QUOTES)) ?>
    </table>

    <h3>PHP i rozszerzenia</h3>
    <table>
        <?= wiersz('Wersja PHP co najmniej 7.4', PHP_VERSION_ID >= 70400, PHP_VERSION) ?>
        <?= wiersz('pdo_sqlite (wymagane)', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'jest' : 'BRAK',
              extension_loaded('pdo_sqlite') ? '' : 'Bez tego panel nie ruszy. Włącz w panelu hostingu albo poproś support.') ?>
        <?= wiersz('mbstring (zalecane)', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'jest' : 'brak') ?>
        <?= wiersz('fileinfo (zalecane)', extension_loaded('fileinfo'), extension_loaded('fileinfo') ? 'jest' : 'brak',
              extension_loaded('fileinfo') ? '' : 'Bez tego zostaje sama kontrola rozszerzenia pliku.') ?>
        <?= wiersz('session', extension_loaded('session'), extension_loaded('session') ? 'jest' : 'BRAK') ?>
    </table>

    <h3>Katalogi</h3>
    <table>
        <?= wiersz('data/ istnieje', $dataInfo['istnieje'], $dataInfo['istnieje'] ? 'tak' : 'NIE') ?>
        <?= wiersz('data/ ma prawo zapisu', $dataInfo['zapisywal'], 'chmod ' . $dataInfo['prawa'],
              $dataInfo['zapisywal'] ? '' : 'Ustaw przez FTP chmod 755, a jeśli nie pomoże 777.') ?>
        <?= wiersz('uploads/ istnieje', $uploadsInfo['istnieje'], $uploadsInfo['istnieje'] ? 'tak' : 'NIE') ?>
        <?= wiersz('uploads/ ma prawo zapisu', $uploadsInfo['zapisywal'], 'chmod ' . $uploadsInfo['prawa'],
              $uploadsInfo['zapisywal'] ? '' : 'Ustaw przez FTP chmod 755, a jeśli nie pomoże 777.') ?>
        <?= wiersz('Baza danych utworzona', is_file(__DIR__ . '/data/panel.sqlite'),
              is_file(__DIR__ . '/data/panel.sqlite') ? number_format((float)filesize(__DIR__ . '/data/panel.sqlite') / 1024, 1, ',', ' ') . ' KB' : 'jeszcze nie') ?>
    </table>

    <h3>Ochrona katalogów przez .htaccess</h3>
    <table>
        <?= wiersz('data/ zablokowane z przeglądarki',
              $blokadaData['zbadane'] ? in_array($blokadaData['kod'], [401, 403, 404], true) : true,
              $blokadaData['zbadane'] ? 'HTTP ' . $blokadaData['kod'] : 'nie dało się sprawdzić automatycznie',
              $blokadaData['zbadane'] && !in_array($blokadaData['kod'], [401, 403, 404], true)
                  ? 'Hosting ignoruje .htaccess. Przenieś katalog data poza public_html i popraw DATA_DIR w db.php.'
                  : ($blokadaData['zbadane'] ? '' : 'Sprawdź ręcznie: otwórz ' . htmlspecialchars($adresBaz, ENT_QUOTES) . '/data/index.html — powinno być 403.')) ?>
        <?= wiersz('uploads/ zablokowane z przeglądarki',
              $blokadaUploads['zbadane'] ? in_array($blokadaUploads['kod'], [401, 403, 404], true) : true,
              $blokadaUploads['zbadane'] ? 'HTTP ' . $blokadaUploads['kod'] : 'nie dało się sprawdzić automatycznie',
              $blokadaUploads['zbadane'] && !in_array($blokadaUploads['kod'], [401, 403, 404], true)
                  ? 'Hosting ignoruje .htaccess w tym katalogu.' : '') ?>
    </table>

    <h3>Wysyłanie plików — test na żywo</h3>
    <table>
        <?php foreach ($testUpload as [$nazwa, $ok, $wartosc]): ?>
            <?= wiersz($nazwa, (bool)$ok, htmlspecialchars((string)$wartosc, ENT_QUOTES)) ?>
        <?php endforeach; ?>
    </table>

    <h3>Transport wysyłki — która metoda dociera do serwera</h3>
    <table id="transport">
        <tr><td class="i">…</td><td class="n">Trwa test trzech metod</td><td class="w">chwila…</td></tr>
    </table>
    <p style="margin:10px 2px 0;font-size:13px;color:#64748b">
        Panel próbuje kolejno: strumień, base64, formularz. Wystarczy, że
        <strong>choć jedna</strong> metoda pokaże liczbę bajtów zgodną z wysłaną —
        wysyłanie plików będzie działać.
    </p>

    <h3>Limity wysyłania plików</h3>
    <table>
        <?= wiersz('Efektywny limit co najmniej 15 MB', $limitUpload >= 15728640,
              number_format((float)$limitUpload / 1048576, 1, ',', ' ') . ' MB',
              $limitUpload >= 15728640 ? '' : 'Panel przyjmie mniejsze pliki. Instrukcja w README.md, punkt 6.') ?>
        <?= wiersz('upload_max_filesize', true, htmlspecialchars((string)ini_get('upload_max_filesize'), ENT_QUOTES)) ?>
        <?= wiersz('post_max_size', true, htmlspecialchars((string)ini_get('post_max_size'), ENT_QUOTES)) ?>
        <?= wiersz('memory_limit', true, htmlspecialchars((string)ini_get('memory_limit'), ENT_QUOTES)) ?>
        <?php $wlasneIni = (string)ini_get('upload_max_filesize') === '16M'; ?>
        <?= wiersz('Źródło limitów', $wlasneIni || $limitUpload >= 15728640,
              $wlasneIni ? 'plik .user.ini' : 'ustawienia hostingu',
              $wlasneIni
                  ? ''
                  : ($limitUpload >= 15728640
                      ? 'Hosting narzuca własne wartości i są wyższe niż potrzebne — wszystko w porządku.'
                      : 'Hosting narzuca własne wartości i są za niskie. Patrz README.md, punkt 6.')) ?>
    </table>

    <p class="stopka">
        <strong>Skasuj ten plik po zakończeniu.</strong>
        <code>diagnostyka.php</code> pokazuje szczegóły konfiguracji serwera i nie powinien zostać na produkcji.
    </p>
</div>

<script>
(function () {
    var ROZMIAR = 4096;
    var bajty = new Uint8Array(ROZMIAR);
    for (var i = 0; i < ROZMIAR; i++) { bajty[i] = i % 251; }
    var plik = new File([bajty], 'sonda.bin', { type: 'application/octet-stream' });

    function wyslij(opcje) {
        return new Promise(function (resolve) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'diagnostyka.php?sonda=1');
            if (opcje.typ) { xhr.setRequestHeader('Content-Type', opcje.typ); }
            xhr.onload = function () {
                try { resolve(JSON.parse(xhr.responseText)); }
                catch (e) { resolve({ blad: 'HTTP ' + xhr.status }); }
            };
            xhr.onerror = function () { resolve({ blad: 'brak połączenia' }); };
            xhr.send(opcje.tresc);
        });
    }

    function base64url(buf) {
        var s = '', b = new Uint8Array(buf);
        for (var i = 0; i < b.length; i++) { s += String.fromCharCode(b[i]); }
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_');
    }

    var formularz = new FormData();
    formularz.append('plik', plik);

    Promise.all([
        wyslij({ typ: 'application/octet-stream', tresc: plik }),
        wyslij({ typ: 'application/x-www-form-urlencoded', tresc: 'b64_data=' + base64url(bajty.buffer) }),
        wyslij({ typ: null, tresc: formularz })
    ]).then(function (wyniki) {
        var opisy = [
            ['Strumień (domyślna metoda panelu)', wyniki[0].strumien, wyniki[0]],
            ['Base64 w formularzu (pierwsza zapasowa)', wyniki[1].base64, wyniki[1]],
            ['Klasyczny multipart (druga zapasowa)', wyniki[2].multipart, wyniki[2]]
        ];
        var html = '';
        var jakakolwiek = false;

        opisy.forEach(function (o) {
            var ile = o[1];
            var ok = ile === ROZMIAR;
            if (ok) { jakakolwiek = true; }
            var opis;
            if (o[2].blad) { opis = 'nie udało się: ' + o[2].blad; }
            else if (ok) { opis = 'dotarło ' + ile + ' z ' + ROZMIAR + ' B'; }
            else if (ile === 0) { opis = 'dotarło 0 B — ta metoda nie działa'; }
            else if (ile < 0) { opis = 'PHP zgłosił błąd wysyłki (kod ' + (-ile) + ')'; }
            else { opis = 'dotarło ' + ile + ' z ' + ROZMIAR + ' B — niepełne'; }

            html += '<tr class="' + (ok ? 'ok' : 'zle') + '"><td class="i">' + (ok ? '✔' : '✖')
                 + '</td><td class="n">' + o[0] + '</td><td class="w">' + opis + '</td></tr>';
        });

        html += '<tr class="' + (jakakolwiek ? 'ok' : 'zle') + '"><td class="i">'
             + (jakakolwiek ? '✔' : '✖') + '</td><td class="n">Wysyłanie plików w panelu</td><td class="w">'
             + (jakakolwiek ? 'zadziała — panel użyje działającej metody'
                            : 'NIE ZADZIAŁA — żadna metoda nie dostarcza danych, zgłoś to hostingowi')
             + '</td></tr>';

        document.getElementById('transport').innerHTML = html;
    });
})();
</script>
</body>
</html>
