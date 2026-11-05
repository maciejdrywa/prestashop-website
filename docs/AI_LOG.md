# Dziennik wykorzystania AI

Dokument opisuje wykorzystanie AI w projekcie oraz sposób weryfikacji otrzymanych wyników.

## Etap 1 — wpisy

| Data i autor | Narzędzie i model | Zadanie | Co otrzymano | Co było błędne | Weryfikacja |
|---|---|---|---|---|---|
| 2026-10-27, Maciej Drywa | ChatGPT; model: DO UZUPEŁNIENIA | W1.4 — Potwierdzenie dziannika wykorzystania AI | Wygeneruj tabelę do trzyamanai log-ów dotyczących korzystania z AI | DO UZUPEŁNIENIA: Brak błędów, tabela została wygenerowana poprawnie | Ręczna weryfikacja otrzymanej tabeli w skompilowanym pliku .md |
| 2026-10-28, Paweł Krajewski | Gemini | W2.1/W2.2 — Oszacowanie liczby produktów w sklepie źródłowym | Sposoby na sprawdzenie liczby asortymentu oraz szacunkową liczbę produktów. | Brak błędów. AI poprawnie wskazało ograniczenia i zaproponowało skuteczne obejście problemu. | Ręczna weryfikacja ze stanem faktycznym na stronie sklepu i dodanie informacji do .md |
| 2026-10-30, Paweł Krajewski | Gemini | W2.3 — Projektowanie schematów walidacji Pydantic dla parsera | Kod źródłowy klas `Product` i `ProductVariant` oraz logika uzupełniania brakujących pół. | AI słusznie odrzuciło pole netto na rzecz dynamicznie liczonego VAT-u oraz uzmysłowiło potrzebę losowania parametru `stock` bezpośrednio w kodzie z powodu braku jawnych danych. | Ręczna weryfikacja klasyfikacji oraz niezgodzenie sie z usuwaniem pola netto |
| 2026-11-01, Paweł Krajewski | Gemini | W2.3 — Diagnoza błędów testów jednostkowych i wyciągania ID | Pomoc w odczytaniu logów błędu `pytest` |Sporo rzeczy, literowki w kodzie oraz zle przypisywania|Ręczna weryfikacja i zgodzenie się z AI|


## Etap 1 — refleksja zespołu

Do uzupełnienia przed odbiorem etapu, należy opisać:
- W których zadaniach AI przyspieszyło pracę.
- Jakie błędy lub nietrafne propozycje wymagały poprawienia.
- Jak zespół sprawdzał otrzymane wyniki.
- Czego członkowie zespołu musieli nauczyć się samodzielnie.
