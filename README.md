# Panel zespołu — instrukcja wdrożenia

Panel zarządzania zadaniami dla czteroosobowego zespołu: foldery projektowe,
tablica kanban, terminy z kalendarzem, wideorozmowy z notatkami, wspólna
notatka Markdown, załączniki i dziennik zmian.

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
| HTTPS | **Wymagany do wideorozmów.** Reszta panelu działa też po HTTP |

Praktycznie każdy polski hosting współdzielony (home.pl, OVH, cyber_Folks,
nazwa.pl, Zenbox, LH.pl, seohost) spełnia te warunki bez żadnych zmian.

> **Wideorozmowy wymagają HTTPS.** To ograniczenie przeglądarek, nie panelu:
> bez bezpiecznego połączenia Chrome i Firefox nie udostępnią kamery ani
> mikrofonu żadnej stronie. Certyfikat Let's Encrypt jest na wszystkich
> wymienionych hostingach darmowy i włącza się jednym kliknięciem w panelu
> administracyjnym. Dopóki go nie ma, panel pokazuje o tym ostrzeżenie
> w zakładce *Spotkania* i nie pozwala wejść do pokoju.
>
> Moduł wideo **nie wymaga** natomiast Node.js, WebSocketów ani konta
> w żadnej usłudze zewnętrznej.

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

**Termin wykonania** — ustawiasz go w oknie zadania albo od razu przy dodawaniu.
Obok pola daty są skróty *Dziś*, *Jutro* i *Za tydzień*. Karta pokazuje termin
plakietką, która sama dobiera kolor:

- **czerwona** — po terminie (np. „3 dni po terminie"),
- **bursztynowa** — dziś albo jutro,
- **szara** — termin dalej w przyszłości.

Zadania po terminie wskakują na samą górę kolumny, przed priorytetami — to
najpilniejsza informacja na tablicy. Zadania zrobione nigdy nie są oznaczane
jako spóźnione; pokazują po prostu datę, na kiedy były planowane.

**Filtrowanie tablicy** — pasek obok zakładek zawęża widok bez przeładowywania
strony: po treści zadania (szuka też w opisie), po osobie, po priorytecie oraz
przełącznik *Termin* pokazujący tylko zadania z ustawioną datą. Filtry można
łączyć, a krzyżyk obok czyści wszystkie naraz. Filtry działają wyłącznie
w Twojej przeglądarce — reszta zespołu widzi tablicę bez zmian.

**Moje zadania** — pierwsza pozycja w bocznym panelu. Zbiera zadania przypisane
do Ciebie ze **wszystkich folderów** i grupuje je według pilności: *Po terminie*,
*Na dziś*, *Najbliższe dni*, *Później*, *Bez terminu*. Zrobione znikają z listy.
Licznik przy nazwie robi się czerwony, gdy cokolwiek jest po terminie. Kliknięcie
zadania przenosi do jego folderu i otwiera okno szczegółów.

**Kalendarz** — druga pozycja w bocznym panelu. Pokazuje miesiąc w układzie
siedmiu kolumn (poniedziałek – niedziela) i rozkłada na nim **terminy zadań ze
wszystkich folderów naraz**. Zadania bez terminu tu nie trafiają.

W kratce mieszczą się trzy paski zadań; przy większej liczbie w rogu pojawia się
`+2`, a **kliknięcie w dzień rozwija pod kalendarzem pełną listę** tego dnia.
Kliknięcie samego paska otwiera zadanie w jego folderze — z opisem, załącznikami
i komentarzami. Kwadracik przy zadaniu na liście dnia od razu oznacza je jako
zrobione.

Kolor paska mówi to samo, co plakietka na tablicy: **czerwony** po terminie,
**bursztynowy** na dziś, **niebieski** dalej w przyszłości, **szary przekreślony**
dla zrobionych. Kropka z lewej strony paska ma kolor pierwszej osoby
odpowiedzialnej. Dzisiejszy dzień jest zaznaczony granatowym kółkiem.

Nad kalendarzem: strzałki przewijają miesiące, *Dziś* wraca do bieżącego,
a dwa przełączniki zawężają widok — *Tylko moje* zostawia zadania przypisane do
Ciebie, *Ukryj zrobione* chowa zamknięte. Podobnie jak filtry tablicy, działają
wyłącznie w Twojej przeglądarce.

Kalendarz pokazuje też kilka dni z sąsiednich miesięcy, żeby domknąć pierwszy
i ostatni tydzień — dzięki temu widać, czy zaraz po granicy miesiąca nie czeka
coś pilnego. Na telefonie siedem kolumn jest nieczytelne, więc panel zastępuje
siatkę **listą dni**, w których coś wypada.

**Komentarze** — okno zadania ma osobną sekcję dyskusji, niezależną od opisu.
Każdy wpis ma autora i czas, `Ctrl+Enter` wysyła. Swój komentarz można usunąć,
cudzego nie — chodzi o to, żeby nikt nie zmieniał cudzej wypowiedzi. Karta na
tablicy pokazuje liczbę komentarzy ikoną dymka.

**Spotkania (wideorozmowy)** — trzecia pozycja w bocznym panelu.
Rozmowa startuje **głosowo**, z kamerą jako opcją do włączenia w pokoju. Przycisk
*Umów spotkanie* otwiera formularz: temat, data, godzina, czas trwania,
agenda, uczestnicy z zespołu, adresy gości i opcjonalne powiązanie z folderem.
Skróty *Za chwilę*, *Za godzinę* i *Jutro 9:00* wpisują termin jednym
kliknięciem. Każde spotkanie dostaje własny, losowy adres pokoju
(np. `krz-fmbq-wtd`) — kopiujesz go przyciskiem *Link*.

Lista dzieli się na trzy sekcje: **W trakcie**, **Nadchodzące**
i **Zakończone** (te ostatnie pod przyciskiem *Archiwum*). Status liczy się
na bieżąco z zegara i z tego, czy ktoś siedzi w pokoju — spotkanie sprzed
miesiąca, na które nikt nie przyszedł, samo trafia do archiwum.

Pokój otwiera się **15 minut przed startem** i zamyka dwie godziny po
planowanym końcu. Wcześniej przycisk *Dołącz teraz* jest nieaktywny i pisze,
dlaczego. Spotkaniem zarządza osoba, która je umówiła — tylko ona zmienia
szczegóły, odwołuje i usuwa. Dołączyć i pisać notatkę może każdy z zespołu.

**Kto może wejść** — wyłącznie osoby zalogowane w panelu. Adresy e-mail
z formularza to lista zaproszonych, a nie przepustka: panel nie wysyła
poczty, więc link przekazujesz sam. Sam link też nie omija logowania.

**W pokoju rozmawiacie domyślnie samym głosem.** Kafelki pokazują inicjały,
a pierścień wokół nich zapala się na zielono, gdy ktoś mówi. To celowe:
kamera bywa zajęta przez inny program (Teams, Zoom, Skype, OBS, aparat
systemowy), a w rozmowie roboczej i tak włącza ją mało kto.

**Kamera jest opcją, nie warunkiem.** Panel prosi o nią dopiero po kliknięciu
przycisku kamery — do tej chwili urządzenie zostaje wolne dla innych
programów. Wyłączenie kamery nie gasi tylko obrazu: **zwalnia sprzęt**,
więc nie blokujesz go koledze ani sobie w innym oknie. Włączona kamera jest
wyróżniona kolorem, wyłączona wygląda zwyczajnie — bo to normalny stan,
a nie awaria.

Jeśli kamera nie chce się włączyć, panel mówi dlaczego. Przy komunikacie
o zajętym urządzeniu wystarczy zamknąć program, który je trzyma, i kliknąć
przycisk jeszcze raz — rozmowa nie jest przerywana, dźwięk leci dalej.
Tak samo działa mikrofon: gdy nie udało się go dostać przy wejściu, kliknięcie
ikony mikrofonu próbuje ponownie.

Pod kafelkami pasek sterowania: mikrofon, kamera, udostępnianie ekranu,
notatka i czerwony przycisk wyjścia. Ekran można udostępnić **bez włączania
kamery**. Ikona ludzików pokazuje listę obecnych ze stanem połączenia każdej
osoby. Klawisz `Esc` zamyka panele boczne, ale **nigdy nie kończy rozmowy** —
wyjście wymaga świadomego kliknięcia.

Ostatnia osoba wychodząca z pokoju zamyka spotkanie i przenosi je do archiwum.

**Notatki ze spotkania** — panel po prawej stronie pokoju (na telefonie
wysuwany od dołu). Markdown jak w notatce folderu, z **autozapisem**: panel
zapisuje po 1,2 sekundy ciszy i pokazuje stan — *niezapisane zmiany*,
*zapisywanie*, *zapisano*. Notatka jest wspólna: kto tylko patrzy, temu
tekst dopisuje się na żywo.

Gdy dwie osoby piszą naraz, panel **nie nadpisuje po cichu**. Każdy zapis
niesie numer wersji; jeśli w międzyczasie zapisał ktoś inny, pojawia się
pasek z wyborem: *Wczytaj wersję z serwera* albo *Zachowaj moją*.
Notatkę otwierasz i edytujesz również po spotkaniu — przycisk *Notatka*
na karcie w archiwum.

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
| `task.create` / `task.update` / `task.delete` | POST | Operacje na zadaniach (`due_date` w formacie `RRRR-MM-DD` albo `null`) |
| `task.mine` | GET | Otwarte zadania przypisane do zalogowanej osoby, ze wszystkich folderów |
| `task.calendar` | GET | Zadania z terminem w zakresie `&from=`…`&to=` (`RRRR-MM-DD`), ze wszystkich folderów |
| `comment.list` | GET | Komentarze pod zadaniem |
| `comment.add` / `comment.delete` | POST | Dyskusja pod zadaniem (skasować może tylko autor) |
| `note.save` | POST | Zapis notatki folderu |
| `meeting.list` | GET | Wszystkie spotkania zespołu ze stanem i uczestnikami |
| `meeting.open` | GET | Jedno spotkanie z notatką (`&id=` albo `&room=`) |
| `meeting.create` / `meeting.update` / `meeting.delete` | POST | Zarządzanie spotkaniem (tylko osoba, która je umówiła) |
| `meeting.join` / `meeting.leave` | POST | Wejście i wyjście z pokoju; zwraca konfigurację ICE i listę obecnych |
| `meeting.note` | POST | Zapis notatki z numerem wersji (`force: true` nadpisuje mimo kolizji) |
| `rtc.poll` | POST | Jedno odpytanie pokoju: obecność, stan mikrofonu i kamery, odbiór wiadomości sygnalizacyjnych |
| `rtc.signal` | POST | Oferta, odpowiedź albo kandydat ICE dla wskazanego uczestnika |
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

### Jak działa wideorozmowa

Obraz i dźwięk idą **bezpośrednio między przeglądarkami** (WebRTC, połączenie
każdy z każdym). Serwer pośredniczy wyłącznie w nawiązaniu połączenia i po
jego zestawieniu nie przenosi już ani jednego bajtu transmisji — przy czterech
osobach to trzy strumienie wychodzące na osobę i zero obciążenia hostingu.

Hosting współdzielony nie daje WebSocketów ani procesów działających w tle,
więc sygnalizacja idzie tą samą drogą co reszta panelu: wiadomości lądują
w tabeli `meeting_signals`, a przeglądarki odbierają je, odpytując `api.php`.
Jedno żądanie `rtc.poll` robi trzy rzeczy naraz — podtrzymuje obecność,
przynosi listę osób w pokoju i opróżnia skrzynkę wiadomości. Odpytywanie
przyspiesza do sekundy, gdy coś się jeszcze zestawia, i zwalnia do trzech,
gdy wszyscy są połączeni.

Ofertę połączenia składa zawsze ta strona, której identyfikator jest
mniejszy alfabetycznie. Bez takiej umowy obie strony wysłałyby ofertę naraz
i połączenie rozsypałoby się na kolizji.

Ta sama strona ustala **układ torów**: najpierw dźwięk, potem obraz. Oba
powstają od razu przy zestawianiu połączenia, nawet gdy kamera jest wyłączona,
a odpowiadający zgłasza na nich gotowość do nadawania, zanim złoży odpowiedź.
Dzięki temu włączenie kamery w trakcie rozmowy jest samą podmianą ścieżki
(`replaceTrack`) i nie wymaga negocjacji od nowa — przez sygnalizację opartą
o odpytywanie byłaby ona wolna i zawodna. Gdyby tory zakładały obie strony
niezależnie, każda nadawałaby na torze, którego druga w ogóle nie czyta.

Obiekty WebRTC (`MediaStream`, `RTCPeerConnection`, `AudioContext`) żyją
w `index.php` poza stanem Alpine, w stałej `MEDIA`. Alpine opakowuje dane
w `Proxy`, a przypisanie opakowanego strumienia do elementu `<video>` po
prostu nie działa. Interfejs dowiaduje się o zmianach przez licznik
`room.strumienTik`.

### Gdy rozmowa nie chce się zestawić

Panel korzysta z publicznych serwerów **STUN** (stała `STUN_SERVERS`
w `db.php`) — mówią przeglądarce, jaki ma publiczny adres. Nie przechodzi
przez nie żaden obraz ani dźwięk.

W części sieci — głównie firmowych i komórkowych — NAT nie przepuszcza
połączenia bezpośredniego i wtedy potrzebny jest **przekaźnik TURN**. Panel
nie ma go domyślnie, bo TURN wymaga własnego serwera albo płatnej usługi.
Gdy po dwudziestu sekundach któreś połączenie nie wstanie, pokój pisze
o tym wprost.

Przekaźnik dopisujesz w `db.php`:

```php
const TURN_SERVERS = [
    ['urls' => 'turn:adres.serwera:3478', 'username' => 'login', 'credential' => 'haslo'],
];
```

Czy problem leży po stronie sieci, sprawdzisz w `diagnostyka.php` — sekcja
*Wideorozmowy* testuje realny dostęp do serwera STUN i pokazuje, jakie
adresy udało się przeglądarce ustalić.

### Tabele bazy

`users`, `folders`, `tasks`, `task_assignees`, `task_comments`, `notes`,
`files`, `activity`, `login_attempts`, `meetings`, `meeting_participants`,
`meeting_notes`, `meeting_presence`, `meeting_signals`. Przypisania osób do zadań leżą
w `task_assignees` (jedno zadanie ↔ wiele osób), `task_comments` trzyma
dyskusję pod zadaniem, a `files.task_id` wskazuje zadanie, pod które podpięto
załącznik — wartość `NULL` oznacza plik należący do całego folderu.
Termin wykonania to `tasks.due_date` w formacie `RRRR-MM-DD` (`NULL` = brak).

Spotkania trzyma `meetings` (`room_id` to publiczny adres pokoju), listę
zaproszonych `meeting_participants` — z `user_id` **albo** `email`, przy czym
unikalności pilnują dwa indeksy częściowe, bo SQLite dopuszcza powtórzone
`NULL`-e w kluczu złożonym. `meeting_notes` przechowuje notatkę wraz
z numerem `revision`, na którym opiera się wykrywanie równoległej edycji.
`meeting_presence` i `meeting_signals` to dane ulotne: obecność wygasa po
25 sekundach bez sygnału życia, a wiadomości sygnalizacyjne po minucie —
jedne i drugie sprzątają się same przy każdym odpytaniu pokoju.
Kalendarz nie ma własnej tabeli — czyta `tasks.due_date` zakresem dat, a że
format jest sortowalny leksykograficznie, porównanie tekstowe działa tu jak
porównanie dat. Zadania bez terminu odpadają same: `NULL` nie przechodzi
porównania, a pusty tekst jest mniejszy niż jakakolwiek data.
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
