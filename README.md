# Panel zespołu — instrukcja wdrożenia

Panel zarządzania zadaniami dla czteroosobowego zespołu: foldery projektowe,
tablica kanban, wspólna notatka Markdown, załączniki i dziennik zmian.

Aplikacja jest napisana w czystym PHP z bazą SQLite. **Nie wymaga Dockera,
Node.js, Composera ani linii poleceń** — wystarczy skopiować pliki przez FTP.

---

## 1. Wymagania hostingu

| Element | Wymaganie |
|---|---|
| PHP | 7.4 lub nowsze (sprawdzone na 7.4.33 i 8.3.33) |
| Rozszerzenia PHP | `pdo_sqlite` (wymagane), `fileinfo` (zalecane), `mbstring` (zalecane) |
| Serwer WWW | Apache lub LiteSpeed z obsługą `.htaccess` |
| Miejsce na dysku | ~1 MB na aplikację + tyle, ile zajmą załączniki |
| Przeglądarka | Dowolna aktualna (panel pobiera Tailwind CSS i Alpine.js z CDN, więc potrzebny jest internet po stronie użytkownika) |

Praktycznie każdy polski hosting współdzielony (home.pl, OVH, cyber_Folks,
nazwa.pl, Zenbox, LH.pl, seohost) spełnia te warunki bez żadnych zmian.

---

## 2. Struktura plików

```
public_html/            ← katalog główny strony na serwerze
├── index.php           ← ekran logowania + panel
├── api.php             ← endpointy AJAX (zadania, pliki, notatki)
├── db.php              ← konfiguracja, baza SQLite, sesje  (nie otwiera się z przeglądarki)
├── .htaccess           ← reguły Apache, limity uploadu, nagłówki bezpieczeństwa
├── .user.ini           ← limity PHP dla hostingów z PHP-FPM / LiteSpeed
├── diagnostyka.php     ← sprawdzarka hostingu; SKASUJ PO UŻYCIU
├── README.md           ← ten plik
│
├── data/               ← tworzone automatycznie, zablokowane dla przeglądarki
│   ├── .htaccess
│   ├── index.html
│   ├── panel.sqlite    ← baza danych (powstaje przy pierwszym wejściu)
│   ├── sessions/       ← pliki sesji (tylko gdy hosting nie daje własnego katalogu)
│   └── error.log       ← log błędów (powstaje w razie potrzeby)
│
└── uploads/            ← załączniki, zablokowane dla przeglądarki
    ├── .htaccess
    ├── index.html
    └── (pliki zapisywane pod losowymi nazwami)
```

Pliki są pobierane wyłącznie przez `api.php?action=download&id=…`, po
sprawdzeniu, czy użytkownik jest zalogowany. Nikt bez konta nie wejdzie
bezpośrednio na adres pliku, bo ten adres nie istnieje publicznie.

---

## 3. Wgranie przez WinSCP — krok po kroku

1. Połącz się z serwerem (protokół **SFTP** lub **FTP**, dane z panelu hostingu).
2. Po prawej stronie przejdź do katalogu strony — zwykle `public_html`,
   `www`, `htdocs` albo `domeny/twojadomena.pl/public_html`.
3. **Włącz pokazywanie plików ukrytych**, inaczej `.htaccess` i `.user.ini`
   się nie skopiują:
   `Opcje → Preferencje → Panele → ✔ Pokazuj ukryte pliki` (skrót `Ctrl+Alt+H`).
4. Zaznacz po lewej **całą zawartość** tego katalogu (łącznie z folderami
   `data` i `uploads`) i przeciągnij na prawą stronę.
5. Ustaw uprawnienia — patrz tabela niżej.
6. Wejdź w przeglądarce na adres swojej domeny. Baza danych utworzy się sama.

> **FileZilla:** to samo, a pliki ukryte włącza się przez
> `Serwer → Wymuś pokazywanie ukrytych plików`.

### Uprawnienia (chmod)

W WinSCP: prawy przycisk na pliku/katalogu → **Właściwości** → pole
**Uprawnienia (ósemkowo)**.

| Element | chmod | Uwagi |
|---|---|---|
| `data/` | **755** | Jeśli pojawi się komunikat o braku prawa zapisu — ustaw `777` |
| `uploads/` | **755** | Jak wyżej |
| `index.php`, `api.php`, `db.php` | **644** | Standard |
| `.htaccess`, `.user.ini` | **644** | Standard |
| `data/panel.sqlite` | **640** | Ustawia się samo przy tworzeniu bazy |

Zasada: **katalogi `data` i `uploads` muszą mieć prawo zapisu dla serwera.**
Na hostingach z PHP działającym jako właściciel plików (większość) wystarcza
`755`. Jeśli PHP działa jako `www-data`, potrzebne będzie `777`.

Gdy uprawnienia będą złe, panel nie „umrze” po cichu — pokaże czytelny
komunikat z instrukcją, co poprawić.

---

## 4. Pierwsze logowanie

Wejdź na adres domeny. Zobaczysz ekran wyboru profilu:

| Profil | Kolor akcentu |
|---|---|
| Alan | fioletowy |
| Hubert | morski (cyan) |
| Szymon | zielony |
| Adrian | pomarańczowy |

**Hasło startowe (wspólne dla wszystkich): `projekt123`**

### Zmiana hasła

Otwórz `db.php`, znajdź linijkę u samej góry:

```php
const DEFAULT_PASSWORD = 'projekt123';
```

Wpisz własne hasło, zapisz plik i wgraj go z powrotem przez FTP.
Nowe hasło zacznie obowiązywać przy najbliższym logowaniu — aplikacja sama
przeliczy hashe wszystkich profili, a stare hasło przestanie działać.
Nie trzeba nic kasować w bazie.

Po ośmiu nieudanych próbach logowania z jednego adresu IP panel blokuje
logowanie na 15 minut (wartości również w `db.php`).

---

## 5. Jak się korzysta z panelu

**Foldery (lewa kolumna)** — jeden folder = jeden projekt. Ma własną tablicę
zadań, notatkę i załączniki. Przycisk *Nowy folder* jest na samej górze listy.
Nazwę zmienia ikona ołówka, usuwa kosz. Usunięcie folderu kasuje też jego
zadania, notatkę i pliki.

Kolejność folderów ustawiasz **przeciągając je myszą w górę i w dół**.
Zapisuje się od razu i jest wspólna dla całego zespołu. Przy włączonym
wyszukiwaniu przeciąganie jest wyłączone — najpierw wyczyść pole szukania.

**Tablica (kanban)** — trzy kolumny: *Do zrobienia*, *W trakcie*, *Zrobione*.

- Pole dodawania zadania jest **na górze każdej kolumny** — zadanie trafia od
  razu do tej kolumny, w której je wpisujesz.
- Po kliknięciu w pole rozwijają się wybór osoby i priorytet.
- Kartę przeciągasz myszą między kolumnami.
- Kwadracik po lewej stronie karty przełącza zadanie na *Zrobione* i z powrotem.
- Kliknięcie karty otwiera okno ze statusem, priorytetem, opisem, osobami
  odpowiedzialnymi, załącznikami i przyciskiem usunięcia.
- Na każdej karcie widać, kto ją dodał oraz kto i kiedy zmieniał ją ostatnio.

**Osoby na zadaniu** — do jednego zadania można przypisać **kilka osób**.
W oknie zadania klikasz imiona, żeby je dodawać i usuwać; to samo działa przy
szybkim dodawaniu w kolumnie. Karta pokazuje pojedynczą osobę z imieniem,
a przy większej liczbie — nałożone na siebie awatary z licznikiem (najedź
myszą, żeby zobaczyć pełną listę).

**Załączniki zadania** — okno zadania ma sekcję *Załączniki zadania*
z dwoma przyciskami:

- **Wgraj nowy** — wybierasz plik z dysku, ląduje od razu przy tym zadaniu.
- **Podepnij istniejący** — rozwija listę plików, które już są w folderze;
  klikasz i plik zostaje podpięty. Tak działa typowy scenariusz: ktoś wrzuca
  komplet materiałów w zakładce *Pliki*, a potem każdy podpina pod swoje
  zadanie to, czego potrzebuje.

Plik należy w danej chwili do jednego zadania. Jeśli w liście wybierzesz plik
zajęty przez inne zadanie, panel wypisze przy nim, skąd go zabiera —
przeniesienie jest świadome, nie po cichu.

Ikona rozłączonego ogniwa przy załączniku **odpina** go od zadania (plik
zostaje w folderze), a kosz **kasuje z serwera**. Karta z załącznikami dostaje
na tablicy ikonę spinacza z liczbą, a w zakładce *Pliki* takie załączniki mają
plakietkę z nazwą zadania, do którego należą.

Usunięcie zadania **nie kasuje jego załączników** — trafiają do zakładki
*Pliki* jako zwykłe pliki folderu. Panel uprzedza o tym w oknie potwierdzenia.

**Priorytety** — trzy poziomy: *Wysoki*, *Normalny*, *Niski*. Zadania
o wyższym priorytecie same wskakują na górę swojej kolumny. Karty z priorytetem
innym niż normalny dostają kolorową plakietkę (czerwoną albo niebieską);
normalne zostają bez plakietki, żeby tablica nie robiła się hałaśliwa.

**Notatka** — wspólne pole tekstowe z obsługą Markdown (`#` nagłówki,
`- ` listy, `**pogrubienie**`, `[link](https://…)`, ``` bloki kodu ```).
Otwiera się domyślnie w **podglądzie**, więc treść widać od razu po wejściu;
do pisania przełącz na *Edycja*. Zapis przyciskiem lub `Ctrl+S` — po zapisaniu
panel wraca do podglądu. Na dole widnieje podpis: „Ostatnia edycja: Imię,
data i godzina”. Pusta notatka otwiera się od razu w trybie edycji.

**Pliki** — przeciągnij na pole lub kliknij, żeby wybrać. Dozwolone:
`PDF, PNG, JPG, JPEG, ZIP, DOCX`, do 15 MB na plik. Przy każdym załączniku
widać rozmiar, autora i datę.

Podgląd (ikona oka) działa dla:

- **obrazów i PDF-ów** — otwierają się w nowej karcie,
- **dokumentów Worda (`.docx`)** — otwierają się w oknie panelu.

Podgląd `.docx` pokazuje tekst z zachowaniem nagłówków, list, tabel,
pogrubienia i kursywy. Nie odtwarza obrazów ani układu strony — od tego jest
Word, a plik zawsze można pobrać przyciskiem obok. Konwersja odbywa się
**w przeglądarce**: dokument nie jest nigdzie wysyłany ani przetwarzany przez
usługi zewnętrzne. Panel czyta archiwum `.docx` samodzielnie i rozpakowuje je
wbudowanym w przeglądarkę mechanizmem, więc nie potrzebuje żadnej dodatkowej
biblioteki z internetu.

**Tryb jasny i ciemny** — przełącznik (słońce / księżyc) w prawym górnym rogu,
obok dzwonka. Wybór zapisuje się w przeglądarce i obowiązuje przy kolejnych
wejściach — każda osoba w zespole ma własne ustawienie. Przy pierwszym wejściu
panel podpowiada się motywem systemowym Windowsa.

**Dziennik zmian (ikona dzwonka)** — ostatnie 20 akcji zespołu: kto, co i kiedy.
Kropka przy dzwonku pokazuje, ile zdarzeń pojawiło się od Twojego ostatniego
zajrzenia. Panel odświeża dane automatycznie co 30 sekund, więc zmiany kolegów
pojawią się bez przeładowywania strony.

---

## 6. Limity wysyłania plików

Aplikacja przyjmuje pliki do 15 MB, ale **serwer może mieć niższy limit**
(często domyślnie 2 MB). Panel wykryje to sam i pokaże ostrzeżenie
na zakładce *Pliki*.

Limity podnoszą dwa dołączone pliki — w zależności od tego, jak hosting
uruchamia PHP, zadziała jeden z nich:

- `.htaccess` — sekcja `php_value upload_max_filesize 16M` (hostingi z mod_php),
- `.user.ini` — te same wartości (hostingi z PHP-FPM, FastCGI, LiteSpeed).

Jeśli mimo to limit się nie zmienia:

1. Zajrzyj do panelu hostingu — wiele z nich ma przełącznik
   *PHP → Ustawienia → upload_max_filesize*.
2. Po zmianie `.user.ini` odczekaj do 5 minut (serwer cache'uje ten plik).
3. Ostateczność: napisz do supportu, prosząc o `upload_max_filesize = 16M`
   i `post_max_size = 20M`.

> Gdyby `.htaccess` powodował błąd 500 (rzadkie, ale zdarza się przy PHP-FPM,
> który nie akceptuje `php_value`), usuń z niego sekcje `<IfModule mod_php…>`.
> Limity ustawi wtedy samo `.user.ini`.

---

## 7. Kopia zapasowa i przenoszenie

Cała zawartość panelu to dwa katalogi:

- `data/panel.sqlite` — użytkownicy, foldery, zadania, notatki, dziennik,
- `uploads/` — same załączniki.

Skopiuj oba przez FTP i masz pełny backup. Przywracanie to wgranie ich
z powrotem. Przeniesienie na inny hosting: skopiuj **wszystkie** pliki
razem z `data` i `uploads`.

Warto robić kopię raz w tygodniu — najprościej przeciągnięciem obu katalogów
na dysk lokalny.

---

## 8. Bezpieczeństwo — co jest zabezpieczone

- Hasła trzymane jako hash `bcrypt` (`password_hash`), nigdy jawnie w bazie.
- Wszystkie zapytania SQL przez zapytania przygotowane (PDO) — brak SQL injection.
- Token CSRF wymagany przy każdej operacji zmieniającej dane.
- Ciasteczko sesji: `HttpOnly`, `SameSite=Lax`, `Secure` na HTTPS.
- Blokada logowania po ośmiu nieudanych próbach z jednego IP.
- Załączniki zapisywane pod losowymi nazwami (32 znaki hex) — nie da się
  zgadnąć ani nadpisać cudzego pliku.
- Weryfikacja nie tylko rozszerzenia, ale i faktycznej zawartości pliku —
  panel czyta sygnaturę bajtową z początku pliku (`%PDF`, `\x89PNG`, `PK\x03\x04`
  i tak dalej), więc skrypt PHP przebrany za `.png` nie przejdzie. Świadomie
  nie opieramy się na rozszerzeniu `fileinfo`: jego baza wzorców różni się
  między hostingami i potrafi odrzucać poprawne pliki.
- Katalogi `data/` i `uploads/` zablokowane przez `.htaccess` na trzy sposoby
  (`Require all denied`, wyłączony silnik PHP, `RemoveHandler`).
- Pliki wydawane z nagłówkami `nosniff` oraz `Content-Security-Policy: sandbox`.
- Notatki Markdown renderowane po wcześniejszym escapowaniu HTML — brak XSS.

### Zalecenia

1. **Nie wgrywaj katalogu `.git` na serwer.** Jeśli trzymasz projekt
   w repozytorium, przez FTP kopiuj wyłącznie pliki aplikacji. Adres
   `/.git/config` udostępniłby cały kod źródłowy razem z historią zmian.
   `.htaccess` blokuje ten katalog, ale to zabezpieczenie działa tylko wtedy,
   gdy hosting respektuje `.htaccess` — najpewniej po prostu go tam nie mieć.
2. **Włącz HTTPS** (certyfikat Let's Encrypt jest darmowy w każdym panelu
   hostingu). Bez tego hasło leci przez sieć otwartym tekstem.
3. Zmień domyślne hasło `projekt123` na własne (patrz punkt 4 instrukcji).
4. Panel jest przeznaczony dla zaufanego zespołu — nie ma ról ani uprawnień,
   każda z czterech osób widzi i może zmieniać wszystko.

---

## 9. Rozwiązywanie problemów

### Najpierw uruchom diagnostykę

Wgraj `diagnostyka.php` obok `index.php` i otwórz w przeglądarce:
`https://twojadomena.pl/diagnostyka.php`

Plik sprawdzi sesje, rozszerzenia PHP, uprawnienia katalogów, działanie
`.htaccess` i limity uploadu, a na górze wypisze wprost, co jest nie tak.
**Po sprawdzeniu skasuj go z serwera** — pokazuje szczegóły konfiguracji.

| Objaw | Przyczyna i rozwiązanie |
|---|---|
| „Formularz stracił ważność. Spróbuj jeszcze raz.” przy logowaniu | Sesja nie przeżywa między wyświetleniem formularza a jego wysłaniem, więc token CSRF zmienia się przy każdym żądaniu. Uruchom `diagnostyka.php` — wskaże, czy chodzi o niezapisywalny katalog sesji, odrzucone ciasteczko, czy o znaki przed `<?php`. Panel sam przechodzi na własny katalog `data/sessions`, gdy hosting nie daje zapisywalnego |
| „Aplikacja nie może wystartować” | Katalog `data` lub `uploads` nie ma prawa zapisu — ustaw chmod `755`, a jeśli nie pomoże `777` |
| Biała strona | Hosting ma wyłączone `pdo_sqlite` albo PHP starsze niż 7.4. Sprawdź wersję PHP w panelu hostingu i przełącz na 8.x |
| Błąd 500 zaraz po wgraniu | `.htaccess` z `php_value` na serwerze PHP-FPM. Usuń z pliku sekcje `<IfModule mod_php7.c>` i `<IfModule mod_php.c>` |
| Panel się nie wczytuje, kręci się kółko | Brak dostępu do CDN (Tailwind / Alpine). Sprawdź internet po stronie przeglądarki |
| „Token bezpieczeństwa wygasł” | Sesja przeterminowana — odśwież stronę klawiszem `F5` i zaloguj się ponownie |
| Nie da się wysłać większego pliku | Limit PHP na serwerze — patrz punkt 6 |
| Wysyłka pliku kończy się błędem | Uruchom `diagnostyka.php` — sekcja „Wysyłanie plików — test na żywo" zapisuje i odczytuje prawdziwy plik w `uploads/`, sprawdza `file_uploads`, katalog tymczasowy i `open_basedir`. Wskaże, który krok się wykłada |
| „Serwer nie mógł zapisać pliku tymczasowego" albo „Serwer nie otrzymał zawartości pliku" | Hosting ma popsuty `upload_tmp_dir` lub `php://input`. Panel obchodzi jedno i drugie, próbując trzech metod wysyłki po kolei. Odśwież stronę przez `Ctrl+F5`, żeby przeglądarka pobrała aktualny `index.php` — bez tego zostaje stara wersja z pamięci podręcznej. Jeśli błąd zostaje, otwórz `diagnostyka.php` i zobacz sekcję „Transport wysyłki" |
| „Zawartość pliku nie odpowiada rozszerzeniu" | Plik ma zmienione rozszerzenie albo jest uszkodzony. Panel czyta sygnaturę bajtową, więc np. `.jpg` przemianowany na `.png` nie przejdzie — zapisz go ponownie w prawidłowym formacie |
| Formularz logowania wraca bez błędu | Ciasteczka zablokowane w przeglądarce albo hosting nie zapisuje sesji |
| Chcę zobaczyć szczegóły błędu | Otwórz `data/error.log` przez FTP. Możesz też ustawić `const DEBUG = true;` w `db.php` — **pamiętaj, żeby wrócić do `false`** |

### Reset panelu do stanu początkowego

Skasuj plik `data/panel.sqlite` oraz zawartość katalogu `uploads/`.
Przy następnym wejściu baza utworzy się od nowa z czterema profilami.
**To kasuje wszystkie dane bezpowrotnie.**

---

## 10. Dla programisty — mapa kodu

**`db.php`** — konfiguracja na górze pliku (hasło, limity, skład zespołu,
kolory, dozwolone rozszerzenia), połączenie PDO, schemat bazy, sesja, CSRF,
logowanie i dziennik aktywności.

**`api.php`** — jeden endpoint, akcje wybierane parametrem `?action=`:

| Akcja | Metoda | Opis |
|---|---|---|
| `bootstrap` | GET | Dane startowe: profile, foldery, dziennik, limity |
| `ping` | GET | Numer ostatniego zdarzenia (odpytywanie co 30 s) |
| `folder.open` | GET | Zadania, notatka i pliki jednego folderu |
| `folder.create` / `folder.rename` / `folder.delete` | POST | Operacje na folderach |
| `folder.reorder` | POST | Nowa kolejność folderów po przeciągnięciu |
| `task.create` / `task.update` / `task.delete` | POST | Operacje na zadaniach |
| `note.save` | POST | Zapis notatki folderu |
| `file.upload` / `file.delete` | POST | Załączniki (`task_id` podpina plik pod zadanie już przy wysyłce) |
| `file.chunk` | POST | Fragment pliku przy wysyłce porcjami; `final: true` kończy i składa całość |
| `file.assign` | POST | Podpina istniejący plik pod zadanie albo odpina (`task_id: null`) |
| `download` | GET | Pobranie lub podgląd pliku (`&inline=1`) |

Operacje `POST` wymagają nagłówka `X-CSRF-Token`.
Odpowiedzi: `{ "ok": true, … }` albo `{ "ok": false, "error": "…" }`.

**`index.php`** — logowanie (zwykły formularz POST) oraz panel jako
komponent Alpine.js o nazwie `panel()`. Cały stan aplikacji jest w jednym
obiekcie, a wszystkie żądania przechodzą przez metodę `api()`.

### Jak panel wysyła pliki

Hostingi współdzielone bywają popsute na dwa niezależne sposoby: brak
sprawnego `upload_tmp_dir` (wtedy nie działa klasyczny formularz) oraz pusty
`php://input` (wtedy nie działa wysyłka strumieniowa). Żadnej z tych rzeczy
nie da się naprawić z poziomu aplikacji — `upload_tmp_dir` jest oznaczony
jako `PHP_INI_SYSTEM`, więc ignoruje i `.htaccess`, i `.user.ini`.

Dlatego panel ma **trzy metody wysyłki** i próbuje ich po kolei, aż któraś
zadziała. Użytkownik nic nie zauważa — widzi jeden pasek postępu.

| Kolejność | Metoda | Nie wymaga |
|---|---|---|
| 1 | Strumień — plik jako surowe ciało POST, metadane w nagłówkach `X-File-Name`, `X-Folder-Id`, `X-Task-Id` | katalogu tymczasowego |
| 2 | Base64 w polu formularza (`b64_name`, `b64_data`, wariant base64url) | katalogu tymczasowego ani `php://input` |
| 3 | Fragmenty — plik dzielony na porcje, każda osobnym żądaniem JSON (`file.chunk`) | dużych żądań; porcja schodzi automatycznie z 256 kB do 32 kB |
| 4 | Klasyczny `multipart` | — |

Metoda trzecia jest najpewniejsza: idzie tym samym kanałem, co reszta panelu,
więc przechodzi także przez zapory obcinające duże żądania POST (typowe
ustawienie ModSecurity na hostingach współdzielonych to 128 kB dla żądań
niebędących wysyłką pliku). Fragmenty dopisywane są do pliku roboczego
`.part_<id>` w `uploads/`; sygnatura sprawdzana jest dopiero po złożeniu
całości, a porzucone fragmenty starsze niż 6 godzin kasują się same.

Metodę, która zadziałała, panel zapamiętuje w przeglądarce i przy kolejnych
wysyłkach zaczyna od niej — bez powtarzania nieudanych prób.

Serwer rozpoznaje metodę po nagłówku lub polu i obsługuje wszystkie trzy
identycznie: ten sam limit rozmiaru, ta sama biała lista rozszerzeń, ta sama
kontrola sygnatury bajtowej. Przy metodzie strumieniowej dane lecą porcjami
po 256 kB, więc pamięć nie rośnie wraz z rozmiarem pliku.

Gdy serwer wykryje, że strumień dotarł pusty, dokleja do komunikatu znacznik
`[retry:b64]`. To sygnał dla przeglądarki, żeby od razu spróbowała następnej
metody — sam znacznik jest usuwany z tekstu pokazywanego użytkownikowi.

Która metoda działa na danym hostingu, sprawdza `diagnostyka.php` w sekcji
„Transport wysyłki" — wysyła 4 kB każdą z trzech dróg i pokazuje, ile bajtów
faktycznie dotarło.

### Tabele bazy

`users`, `folders`, `tasks`, `task_assignees`, `notes`, `files`, `activity`,
`login_attempts`. Przypisania osób do zadań leżą w `task_assignees` (jedno
zadanie ↔ wiele osób), a `files.task_id` wskazuje zadanie, pod które podpięto
załącznik — wartość `NULL` oznacza plik należący do całego folderu.
Każdy wpis w `folders`, `tasks`, `notes` i `files` niesie informację o autorze
(`created_by` / `uploaded_by`) i ostatniej zmianie (`updated_by`, `updated_at`),
dzięki czemu widać, kto co zrobił.

### Aktualizacja panelu do nowszej wersji

Wgraj nadpisując pliki `.php` — **nie kasuj katalogów `data` ani `uploads`**.
Panel trzyma numer schematu bazy w `PRAGMA user_version` i przy pierwszym
wejściu sam dostawia brakujące kolumny (funkcja `migrate_schema` w `db.php`).
Dane zostają nietknięte. Przed większą aktualizacją i tak warto skopiować
`data/panel.sqlite` na dysk — zajmuje sekundę.

### Motyw kolorystyczny

Cała paleta siedzi w zmiennych CSS na początku `index.php` (blok `:root`
oraz `html.dark`). Zmiana barw panelu to podmiana tych kilkunastu wartości —
nie trzeba ruszać klas przy poszczególnych elementach. Wartości podawane są
jako składowe RGB oddzielone spacjami, bo Tailwind dokłada do nich
przezroczystość (`bg-surface/60`).

### Dodanie piątej osoby do zespołu

W `db.php` dopisz wpis do tablicy `TEAM` i — jeśli chcesz nowy kolor akcentu —
do tablicy `COLORS`. Profil pojawi się sam przy następnym wejściu na stronę:

```php
const TEAM = [
    ['name' => 'Alan',   'color' => 'violet'],
    ['name' => 'Hubert', 'color' => 'cyan'],
    ['name' => 'Szymon', 'color' => 'emerald'],
    ['name' => 'Adrian', 'color' => 'orange'],
    ['name' => 'Kasia',  'color' => 'rose'],     // nowy wpis
];
```
