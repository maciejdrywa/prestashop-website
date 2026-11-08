# Środowisko i skrypty

Katalog zawiera compose.yaml oraz skrypty instalacji, inicjalizacji danych, tworzenia kopii zapasowej i odtwarzania sklepu.

Środowisko wykorzystuje Docker Compose v2 i obejmuje PrestaShop, bazę danych oraz Mailpit lub MailHog. Sklep działa przez HTTPS, a HTTP przekierowuje na HTTPS.

Uruchomienie wymaga jednej komendy startowej i jednej komendy inicjalizacji. Sklep z danymi musi działać w ciągu 15 minut od uruchomienia z czystego klona.

Sekrety przekazujemy przez plik .env wykluczony z Git. Repozytorium zawiera wyłącznie .env.example.