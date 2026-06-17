# Jak używać Otack Manager

Witaj w **Otack Manager** — lekkim narzędziu do zarządzania projektami i zadaniami dla małych zespołów, które wolą wykonywać prawdziwą pracę zamiast walczyć z narzędziami. Ta strona jest twoim podręcznikiem operatora: co aplikacja potrafi, jak jej elementy ze sobą współgrają i które metodologie naturalnie się na nią nakładają.

> Jeśli konfigurujesz narzędzie pierwszy raz — przejdź od razu do sekcji **Konfiguracja na start** na końcu. Jeśli jesteś codziennym użytkownikiem — najwięcej wartości znajdziesz w bloku **Codzienny przepływ pracy**.

---

## Co jest w środku

- **Projekty** — długoterminowe kontenery na powiązane zadania, każdy z własną tablicą, członkami, tagami i statusem przypięcia.
- **Zadania** — jednostka pracy, żyje w kolumnie tablicy projektu, może mieć priorytet, podstatus, osoby przypisane, komentarze, załączniki i powiązania.
- **Tagi** — globalne etykiety wieloprojektowe (każdy zakres osobno: projekt, zadanie, dokument Wiedzy).
- **Ankiety** — szybkie asynchroniczne decyzje bez spotkań.
- **Formularze** — przyjmowanie zgłoszeń lub feedbacku w formie zgłoszeń wpadających do aplikacji.
- **Wiedza** (jesteś tu teraz) — wiki w Markdown z kategoriami, tagami, komentarzami i jawnymi migawkami wersji.
- **Użytkownicy i role** — admin / manager / employee, z bramką zatwierdzenia przy rejestracji.
- **Compass** *(admin)* — panel administracyjny: uruchamianie migracji, czyszczenie cache/sesji, reset wersji statyków, przycinanie logów aktywności, statystyki bazy i logi błędów. Strona operacyjna, nie do codziennej pracy.

---

## Codzienny przepływ pracy

Zdrowy dzień w Otack Manager wygląda tak:

| Krok | Gdzie | Co robisz |
|------|-------|-----------|
| 1 | Pasek **Projekty** | Otwórz swój przypięty projekt — to twoja tablica „domowa". |
| 2 | **Projekty → tablica** | Przeciągnij priorytety z góry „Backlog" / „To do" do „In progress". |
| 3 | **Strona zadania** | Zaktualizuj podstatus, dodawaj komentarze dla kontekstu, dołączaj zrzuty/pliki. |
| 4 | **Wiedza** | Zapisuj wszystko wielokrotnego użytku — runbooki, decyzje, podpowiedzi onboardingowe. |
| 5 | Pod koniec dnia | Przerzuć ukończone zadania do „Done", zostaw krótki komentarz w stylu standupu. |

Pętla jest celowo krótka. Narzędzie zarabia na siebie tylko wtedy, gdy nie staje na drodze kroku 2.

---

## Metodologie, które dobrze pasują

Otack Manager **świadomie nie narzuca metodologii**. Oto jak najpopularniejsze podejścia mapują się na jego prymitywy.

### 1. Kanban

Najlepszy, gdy praca przychodzi nieprzewidywalnie (support, ops, marketing).

- **Kolumny** = etapy. Domyślne `Backlog → To do → In progress → Review → Done` sprawdzą się w większości zespołów. Dostosuj per projekt.
- **Limity WIP** nie są wymuszane przez aplikację — ustalcie je społecznie i zapiszcie w opisie projektu.
- **Priorytety** zadań (`P1 / P2 / P3 / P4`) to twój sygnał, co ciągnąć dalej. Połącz z **przypiętymi projektami**, żeby najważniejsze tablice były na górze paska bocznego.

```text
Backlog  →  To do  →  In progress  →  Review  →  Done
   ↑                                              │
   └────── Ciągły strumień nowej pracy ───────────┘
```

### 2. Scrum-lite (sprint bez ciężaru ceremonii)

Najlepszy, gdy planujesz w cyklach 1-2 tygodni.

- Utwórz **tag** typu `sprint-N` (np. `sprint-12`). Oznacz nim każde zadanie w sprincie.
- W **Ankiecie** zagłosujcie nad zakresem sprintu na starcie — wynik zapiszcie komentarzem w zadaniu planistycznym.
- Pod koniec sprintu — prowadźcie **retrospektywę** w Wiedzy w kategorii `Retros`. Zapiszcie **migawkę** po uzgodnieniu finalnej wersji.

### 3. GTD (Getting Things Done)

Najlepszy dla osobistego zarządzania obciążeniem.

- Jeden projekt = twój inbox. Kolumna `Backlog` to „złapane, ale jeszcze nieprzetworzone".
- Przenoś do `To do` tylko po podjęciu decyzji o **następnej konkretnej akcji**.
- Używaj **tagów** jako kontekstów (`@home`, `@deep-work`, `@quick-win`).
- Co tydzień opróżniaj inbox do tablic projektów — to twój weekly review.

### 4. Osobista lista dzienna

Jeśli metodologie wydają się ciężkie, można prościej:

1. Przypnij jeden projekt `Today`.
2. Rano przenieś 3-5 zadań do `In progress`.
3. Wieczorem zamknij je lub odłóż.

Narzędzie nie będzie cię męczyć przypomnieniami. To celowe.

---

## Wiedza — najlepsze praktyki

Moduł Wiedzy (gdzie jesteś teraz) służy do **rzeczy, które trzeba przeczytać więcej niż raz**.

- **Kategorie** są płaską listą. Nie przeginaj: `Inżynieria / Ops / HR / Sprzedaż / Decyzje` to zwykle aż nadto.
- **Tagi** działają w obrębie całej Wiedzy — używaj ich do tematów krzyżowych (`security`, `oncall`, `customer-X`).
- **Migawki** są jawne. Kliknij *Zapisz migawkę* przed dużym przepisywaniem. Migawki to historia tylko do odczytu — **brak automatycznego przywracania**, świadomie.
- **Komentarze** zostawiaj na stronie, której dotyczą, a nie na czacie. Przyszły ty podziękuje.

### Sugerowane pierwsze strony

```text
Inżynieria
  ├─ Instrukcja deployu
  ├─ Konfiguracja lokalnego środowiska
  └─ Playbook obsługi incydentów

Ops
  ├─ Lista dostawców i kontaktów
  └─ Rytm spotkań cyklicznych

HR
  └─ Checklist onboardingowy
```

---

## Role i uprawnienia

| Rola | Czytanie | Edycja zadań | Zarządzanie użytkownikami | Edycja Wiedzy |
|------|----------|---------------|----------------------------|---------------|
| **Admin** | tak | tak | tak | tak (i kasowanie) |
| **Manager** | tak | tak | częściowo | tak |
| **Employee** | tak (przypisane + publiczne) | własne zadania | nie | tylko komentarze |
| **Pending** | nic | nic | nic | nic |

Nowe rejestracje lądują w **Pending** do zatwierdzenia przez admina. To celowe — utrzymuje workspace mały i zaufany.

---

## Tagi — tani i potężny przekrój

Tagi działają w **projektach**, **zadaniach** i **Wiedzy**, każdy w swoim zakresie. Kilka wzorców, które się opłacają:

- `priority:fire` — widoczny od razu w całym workspace, nie tylko w jednym projekcie.
- `cust:acme` — wszystko, co dotyczy klienta Acme, jednym kliknięciem.
- `area:billing` — tagi po obszarze kodu pomagają inżynierom szybciej triagować niż czytanie tytułów.

Trzymaj słownik tagów mały. Ściana tagów jest gorsza niż brak tagów.

---

## Ankiety — decyzje asynchroniczne

Używaj Ankiet, gdy:

- Decyzja dotyczy więcej niż 2 osób, ale nie wymaga spotkania.
- Chcesz mieć ślad, kto jak głosował.
- Opcje są policzalne (tak/nie, A/B/C, wielokrotny wybór).

Nie używaj Ankiet do pytań otwartych — te należą do komentarza zadania albo szkicu strony Wiedzy.

---

## Formularze — lekki przyjm zgłoszeń

Formularze są dobre dla:

- Zgłoszeń błędów od nietechnicznych członków zespołu.
- Kanałów feedbacku klientów.
- Wniosków HR / urlopowych.

Każde zgłoszenie pojawia się w **Forms Data**, gdzie manager może je przekuć w zadanie.

---

## Wyszukiwanie

Pole wyszukiwania w pasku bocznym Wiedzy szuka po **tytule i treści**, bez uwzględniania wielkości liter. Łącz z kategorią, żeby zawęzić wyniki. Gdy zespół urośnie — opieraj się bardziej na tagach niż na pełnotekstowym wyszukiwaniu: są precyzyjniejsze.

---

## Konfiguracja na start

Jeśli jesteś adminem rozkręcającym narzędzie:

1. **Utwórz pierwszy projekt** w `/projects`. Przypnij go — to stanie się tablicą „domową".
2. **Zdefiniuj kolumny**. Domyślne 5 jest zwykle wystarczające — zmieniaj tylko jeśli masz powód.
3. **Utwórz podstawowe tagi** (priorytety, obszary, klienci). Na start ogranicz się do ~15 tagów.
4. **Zaproś zespół**. Zarejestrują się, ty zatwierdzasz ich w `/users`.
5. **Zasiej Wiedzę** przynajmniej trzema stronami:
   - Ta strona onboardingowa (już jest).
   - Strona o deploy / runbooku.
   - Strona o normach zespołu / „jak pracujemy".
6. **Ustaw domyślny język** w Settings — pod roboczy język zespołu.

---

## Skrócona ściągawka

| Akcja | Gdzie |
|-------|--------|
| Otwórz panel administracyjny | `/admin/compass` |
| Dodaj zadanie | Tablica projektu → `+ Add task` w dowolnej kolumnie |
| Przenieś zadanie | Drag & drop na tablicy |
| Wzmiankuj kogoś w komentarzu | Wpisz `@imię` |
| Filtruj po tagu | Chip tagu na górze listy |
| Zapisz migawkę Wiedzy | Strona Wiedzy → *Zapisz migawkę* |
| Zobacz tokeny API | `/profile` → API tokens |

---

> **Pamiętaj:** narzędzia wzmacniają nawyki — dobre lub złe. Otack Manager nie zamieni chaotycznego procesu w czysty. Najpierw zdecyduj *jak* chcesz pracować, potem skorzystaj z najbliższego mapowania powyżej, żeby dopasować aplikację do tego.
