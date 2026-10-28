# Dziennik wykorzystania AI

Dokument opisuje wykorzystanie AI w projekcie oraz sposób weryfikacji otrzymanych wyników.

## Etap 1 — wpisy

| Data i autor | Narzędzie i model | Zadanie | Co otrzymano | Co było błędne | Weryfikacja |
|---|---|---|---|---|---|
| 2026-10-27, Maciej Drywa | codex sol 6.1 | W1.4 — Potwierdzenie dziannika wykorzystania AI | Wygeneruj tabelę do trzyamanai log-ów dotyczących korzystania z AI | Brak błędów, tabela została wygenerowana poprawnie | Ręczna weryfikacja otrzymanej tabeli w skompilowanym pliku .md |
| 2026-10-28, Maciej Drywa | codex sol 6.1 | W3.1 — Uruchomienie PrestaShop i bazy danych w Docker Compose | Podłącz dwa osobne serwisy, prestashop i bazę danych. Przypisz do nich odpowienie zmienne środowiskowe oraz w przypadku bazy danych zaimplementuj health check. | Brak błędów. AI poprawnie stworzyło serwisy i przypisało zmienne env. Health check bazy danych równiez został zaimplementowany. | Sklep udało się odalić oraz przypisane zmienne działają poniewa udało się zalogować na konto administratora. Health check bazy równiez działa poprawnie, poniewaz bez nie go strona by się nie odpaliła. |

| 2026-10-27, Maciej Drywa | ChatGPT; model: DO UZUPEŁNIENIA | W1.4 — Potwierdzenie dziannika wykorzystania AI | Wygeneruj tabelę do trzyamanai log-ów dotyczących korzystania z AI | DO UZUPEŁNIENIA: Brak błędów, tabela została wygenerowana poprawnie | Ręczna weryfikacja otrzymanej tabeli w skompilowanym pliku .md |
| 2026-10-28, Paweł Krajewski | Gemini | W2.1/W2.2 — Oszacowanie liczby produktów w sklepie źródłowym | Sposoby na sprawdzenie liczby asortymentu oraz szacunkową liczbę produktów. | Brak błędów. AI poprawnie wskazało ograniczenia i zaproponowało skuteczne obejście problemu. | Ręczna weryfikacja ze stanem faktycznym na stronie sklepu i dodanie informacji do .md |
## Etap 1 — refleksja zespołu

Do uzupełnienia przed odbiorem etapu, należy opisać:
- W których zadaniach AI przyspieszyło pracę.
- Jakie błędy lub nietrafne propozycje wymagały poprawienia.
- Jak zespół sprawdzał otrzymane wyniki.
- Czego członkowie zespołu musieli nauczyć się samodzielnie.
