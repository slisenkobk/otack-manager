# Jak używać Otack Manager

Witaj w **Otack Manager** — lekkim narzędziu do zarządzania projektami i zadaniami dla małych zespołów, które wolą wykonywać prawdziwą pracę zamiast walczyć z narzędziami. Ta strona jest twoim podręcznikiem operatora: co aplikacja potrafi, jak jej elementy ze sobą współgrają i które metodologie naturalnie się na nią nakładają.

## Spis treści

- [Co jest w środku](#co-jest-w-środku)
- [Codzienny przepływ pracy](#codzienny-przepływ-pracy)
- [Metodologie, które dobrze pasują](#metodologie-które-dobrze-pasują)
  - [Kanban](#1-kanban)
  - [Scrum-lite](#2-scrum-lite-sprint-bez-ciężaru-ceremonii)
  - [GTD](#3-gtd-getting-things-done)
  - [Osobista lista dzienna](#4-osobista-lista-dzienna)
- [Wiki — najlepsze praktyki](#wiki--najlepsze-praktyki)
- [Role i uprawnienia](#role-i-uprawnienia)
- [Tagi — tani i potężny przekrój](#tagi--tani-i-potężny-przekrój)
- [Ankiety — decyzje asynchroniczne](#ankiety--decyzje-asynchroniczne)
- [Formularze — lekki przyjm zgłoszeń](#formularze--lekki-przyjm-zgłoszeń)
- [Wyszukiwanie](#wyszukiwanie)
- [Konfiguracja na start](#konfiguracja-na-start)
- [Skrócona ściągawka](#skrócona-ściągawka)

> Jeśli konfigurujesz narzędzie pierwszy raz — przejdź od razu do sekcji **Konfiguracja na start** na końcu. Jeśli jesteś codziennym użytkownikiem — najwięcej wartości znajdziesz w bloku **Codzienny przepływ pracy**.

---

## Co jest w środku

- **Projekty** — długoterminowe kontenery na powiązane zadania, każdy z własną tablicą kanban, członkami, tagami i statusem przypięcia.
- **Zadania** — jednostka pracy, żyje w kolumnie tablicy projektu, może mieć priorytet, podstatus, jedną osobę przypisaną, komentarze, załączniki i powiązania z innymi zadaniami.
- **Tagi** — etykiety w zakresie typu encji (projekt, zadanie, strona Wiki). Tworzone globalnie, przypisywane w każdym zakresie osobno.
- **Ankiety** — szybkie asynchroniczne decyzje bez spotkań.
- **Formularze** — publiczne formularze do zbierania zgłoszeń/feedbacku. Zgłoszenia trafiają do Forms Data do triażu.
- **Wiki** (jesteś tu teraz) — strony Markdown z kategoriami, tagami, komentarzami i jawnymi migawkami wersji.
- **Użytkownicy i role** — admin / manager / employee, z bramką zatwierdzenia przy rejestracji.
- **Tokeny API** — osobiste długoterminowe tokeny `otk_…` do dostępu programowego. Zarządzanie w profilu.
- **Compass** *(admin)* — panel administracyjny pod `/admin/compass`: uruchamianie migracji, czyszczenie cache/sesji, reset wersji statyków, przycinanie logów aktywności, statystyki bazy, czytanie logów błędów, wbudowany self-update.

---

## Codzienny przepływ pracy

Zdrowy dzień w Otack Manager wygląda tak:

| Krok | Gdzie | Co robisz |
|------|-------|-----------|
| 1 | Pasek **Projekty** | Otwórz swój przypięty projekt — to twoja tablica „domowa". |
| 2 | **Projekty → tablica** | Przeciągnij priorytety z góry „Backlog" / „To Do" do „In Progress". |
| 3 | **Strona zadania** | Zaktualizuj podstatus, dodawaj komentarze dla kontekstu, dołączaj zrzuty/pliki. |
| 4 | **Wiki** | Zapisuj wszystko wielokrotnego użytku — runbooki, decyzje, podpowiedzi onboardingowe. |
| 5 | Pod koniec dnia | Przerzuć ukończone zadania do „Done", zostaw krótki komentarz w stylu standupu. |

Pętla jest celowo krótka. Narzędzie zarabia na siebie tylko wtedy, gdy nie staje na drodze kroku 2.

---

## Metodologie, które dobrze pasują

Otack Manager **świadomie nie narzuca metodologii**. Oto jak najpopularniejsze podejścia mapują się na jego prymitywy.

### 1. Kanban

Najlepszy, gdy praca przychodzi nieprzewidywalnie (support, ops, marketing).

- **Kolumny** = etapy. Domyślne `Backlog → To Do → In Progress → Done` sprawdzą się w większości zespołów. Dostosuj per projekt — dodawaj/zmieniaj nazwy/sortuj kolumny z menu kolumny na tablicy.
- **Limity WIP** nie są wymuszane przez aplikację — ustalcie je społecznie i zapiszcie w opisie projektu.
- **Priorytety** zadań (`low / medium / high / urgent`, lub none) to twój sygnał, co ciągnąć dalej. Tablica umie sortować po priorytecie z toolbara. Połącz z **przypiętymi projektami**, żeby najważniejsze tablice były na górze paska bocznego.

```text
Backlog  →  To Do  →  In Progress  →  Done
   ↑                                    │
   └──── Ciągły strumień nowej pracy ───┘
```

### 2. Scrum-lite (sprint bez ciężaru ceremonii)

Najlepszy, gdy planujesz w cyklach 1-2 tygodni.

- Utwórz **tag** typu `sprint-N` (np. `sprint-12`) w zakresie zadań. Oznacz nim każde zadanie w sprincie.
- W **Ankiecie** zagłosujcie nad zakresem sprintu na starcie — wynik zapiszcie komentarzem w zadaniu planistycznym.
- Pod koniec sprintu — prowadźcie **retrospektywę** w Wiki w kategorii `Retros`. Zapiszcie **migawkę** po uzgodnieniu finalnej wersji.

### 3. GTD (Getting Things Done)

Najlepszy dla osobistego zarządzania obciążeniem.

- Jeden projekt = twój inbox. Kolumna `Backlog` to „złapane, ale jeszcze nieprzetworzone".
- Przenoś do `To Do` tylko po podjęciu decyzji o **następnej konkretnej akcji**.
- Używaj **tagów** jako kontekstów (np. `home`, `deep-work`, `quick-win`).
- Co tydzień opróżniaj inbox do tablic projektów — to twój weekly review.

### 4. Osobista lista dzienna

Jeśli metodologie wydają się ciężkie, można prościej:

1. Przypnij jeden projekt `Today`.
2. Rano przenieś 3-5 zadań do `In Progress`.
3. Wieczorem zamknij je lub odłóż.

Narzędzie nie będzie cię męczyć przypomnieniami. To celowe.

---

## Wiki — najlepsze praktyki

Moduł Wiki (gdzie jesteś teraz) służy do **rzeczy, które trzeba przeczytać więcej niż raz**.

- **Kategorie** są płaską listą. Nie przeginaj: `Inżynieria / Ops / HR / Sprzedaż / Decyzje` to zwykle aż nadto.
- **Tagi** działają w obrębie całej Wiki — używaj ich do tematów krzyżowych (`security`, `oncall`, `customer-X`).
- **Migawki** są jawne. Na stronie Versions kliknij *Zapisz migawkę* przed dużym przepisywaniem. Migawki to historia tylko do odczytu — **brak automatycznego przywracania**, świadomie.
- **Komentarze** zostawiaj na stronie, której dotyczą, a nie na czacie. Przyszły ty podziękuje.
- **Wyszukiwanie** jest niewrażliwe na wielkość liter, obsługuje Unicode, szuka po tytule i treści — nawet po fragmencie słowa. Łącz z kategorią, by zawęzić wyniki.

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

| Rola | Czytanie | Edycja zadań | Zarządzanie użytkownikami | Edycja Wiki |
|------|----------|---------------|----------------------------|-------------|
| **Admin** | tak | tak | tak | tak (i kasowanie stron + zarządzanie kategoriami) |
| **Manager** | tak | tak | częściowo | tak (edycja + tworzenie + migawki) |
| **Employee** | tak (przypisane + publiczne) | własne zadania | nie | tylko komentarze |
| **Pending** | nic | nic | nic | nic |

Nowe rejestracje lądują w **Pending** do zatwierdzenia przez admina. To celowe — utrzymuje workspace mały i zaufany.

---

## Tagi — tani i potężny przekrój

Tagi działają w **projektach**, **zadaniach** i **Wiki**, każdy w swoim zakresie. Kilka wzorców, które się opłacają:

- `fire` — widoczny od razu w całym workspace, nie tylko w jednym projekcie.
- `cust-acme` — wszystko, co dotyczy klienta Acme, jednym kliknięciem.
- `area-billing` — tagi po obszarze kodu pomagają inżynierom szybciej triagować niż czytanie tytułów.

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

Pole wyszukiwania w pasku bocznym Wiki szuka po **tytule i treści**, bez uwzględniania wielkości liter, z obsługą Unicode i dopasowaniem do fragmentu słowa. Łącz z kategorią, żeby zawęzić wyniki. Gdy zespół urośnie — opieraj się bardziej na tagach niż na pełnotekstowym wyszukiwaniu: są precyzyjniejsze.

---

## Konfiguracja na start

Jeśli jesteś adminem rozkręcającym narzędzie:

1. **Utwórz pierwszy projekt** w `/projects`. Przypnij go — to stanie się tablicą „domową".
2. **Zdefiniuj kolumny**. Domyślne 5 jest zwykle wystarczające — zmieniaj tylko jeśli masz powód.
3. **Utwórz podstawowe tagi** (priorytety, obszary, klienci). Na start ogranicz się do ~15 tagów.
4. **Zaproś zespół**. Zarejestrują się, ty zatwierdzasz ich w `/users`.
5. **Zasiej Wiki** przynajmniej trzema stronami:
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
| Komentarz do zadania | Strona zadania → na dole |
| Filtruj zadania po tagu | Chip tagu na górze tablicy projektu |
| Zapisz migawkę Wiki | Strona Wiki → Versions → *Zapisz migawkę* |
| Zobacz tokeny API | `/profile` → API tokens |
| Uruchom akcję systemową | `/admin/compass` (tylko admin) |

---

> **Pamiętaj:** narzędzia wzmacniają nawyki — dobre lub złe. Otack Manager nie zamieni chaotycznego procesu w czysty. Najpierw zdecyduj *jak* chcesz pracować, potem skorzystaj z najbliższego mapowania powyżej, żeby dopasować aplikację do tego.
