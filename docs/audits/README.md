# Audyt D1.4

## Lighthouse

W repozytorium zapisano [raport Lighthouse strony głównej](lighthouse.html), wykonany dla lokalnego sklepu pod adresem `https://localhost` w profilu desktopowym. Raport zawiera datę pomiaru, wersję narzędzia, wyniki dostępności i wydajności oraz szczegóły wykrytych problemów.

Wymagany wynik Accessibility dla D1.4 wynosi co najmniej 85.

## Uruchomienie audytu

Uruchom sklep, a następnie wykonaj poniższe polecenie z głównego katalogu projektu. Wymagane są Node.js oraz Chrome lub Chromium. W razie potrzeby ustaw zmienną `CHROME_PATH` na ścieżkę do pliku wykonywalnego przeglądarki.

```powershell
npx --yes --package lighthouse@13.6.0 lighthouse https://localhost --preset=desktop --chrome-flags="--headless --ignore-certificate-errors" --only-categories=accessibility,performance --output=html --output-path=docs/audits/lighthouse.html
```

Polecenie aktualizuje `docs/audits/lighthouse.html`. Otwórz ten plik w przeglądarce, aby sprawdzić wyniki.

## Zakres raportu

Raport obejmuje stronę główną w profilu desktopowym. Wyniki dotyczą lokalnego środowiska i stanu sklepu w chwili pomiaru. Lighthouse wykonuje automatyczne kontrole dostępności i wydajności. Pełna ocena dostępności wymaga także osobnego sprawdzenia obsługi klawiaturą oraz czytnikiem ekranu.
