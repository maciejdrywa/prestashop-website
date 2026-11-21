# Decyzje architektoniczne (ADR)

ADR opisują kontekst decyzji, rozważane opcje, wybrane rozwiązanie i jego konsekwencje. Poniższe zapisy porządkują decyzje już zastosowane w projekcie; data oznacza dzień ich udokumentowania, a nie termin wdrożenia.

| ADR | Decyzja | Status |
|---|---|---|
| [0001](0001-python-e2e.md) | Przepisanie testów E2E z TypeScript na Python | Przyjęta |
| [0002](0002-webservice-import.md) | Import katalogu przez Webservice API | Przyjęta |
| [0003](0003-container-architecture.md) | Oddzielne usługi Docker Compose i trwałe wolumeny | Przyjęta |
| [0004](0004-image-storage.md) | Lokalny cache zdjęć i archiwum w GitHub Releases | Przyjęta |

Przy zmianie decyzji należy dodać kolejny ADR i wskazać, który zapis zastępuje. Szczegóły aktualnej implementacji znajdują się w instrukcjach technicznych.

[Architektura](../ARCHITECTURE.md) · [Indeks dokumentacji](../README.md)
