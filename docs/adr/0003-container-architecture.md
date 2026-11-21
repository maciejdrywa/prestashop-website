# ADR 0003 — Rozdzielenie usług w Docker Compose

## Kontekst

Sklep wymaga serwera PHP, bazy danych, HTTPS i testowej poczty. Członkowie zespołu uruchamiają projekt na różnych systemach. Dane katalogu i instalacji muszą przetrwać odtworzenie kontenera, a inicjalizacja powinna mieć kontrolowaną kolejność i umożliwiać wznowienie.

## Rozważane opcje

1. Instalować wszystkie usługi bezpośrednio na komputerze każdego członka zespołu.
2. Umieścić sklep, bazę, proxy i pocztę w jednym kontenerze.
3. Rozdzielić usługi w jednej definicji Docker Compose i przechowywać dane poza warstwą zapisywalną kontenerów.

## Decyzja

Używamy `deploy/compose.yaml` z oddzielnymi usługami: `shop` dla PrestaShop i Apache, `db` dla MariaDB, `nginx` dla HTTPS oraz `mailpit` dla testowego SMTP. Zadania `certificates` i `bootstrap` przygotowują środowisko i inicjalizację. Dane bazy i pliki sklepu są przechowywane w nazwanych wolumenach `db_data` i `shop_data`.

Konfiguracja pochodzi z pliku środowiskowego. Zależności usług i kontrola gotowości bazy porządkują uruchamianie. Osobne projekty Compose i pliki środowiskowe pozwalają tworzyć odrębne instancje do testów i odtwarzania kopii.

## Konsekwencje

- Środowisko jest opisane w repozytorium, co ogranicza różnice wynikające z ręcznej instalacji usług.
- Restart lub wymiana kontenera nie usuwa danych zapisanych w wolumenach; ich jawne usunięcie nadal powoduje utratę danych.
- Rozdzielenie usług ułatwia analizę logów i aktualizację komponentów, ale wymaga poprawnej konfiguracji sieci, portów, certyfikatów i uprawnień plików.
- Samo uruchomienie serwerów nie oznacza zakończenia konfiguracji i importu. Należy korzystać z udokumentowanego procesu bootstrapu.
- Wolumeny wymagają kopii zapasowych; ich trwałość nie zastępuje procedury odtwarzania.

[Architektura](../ARCHITECTURE.md) · [Wdrożenie](../DEPLOYMENT.md) · [Kopie zapasowe](../BACKUP.md) · [Lista ADR](README.md)
