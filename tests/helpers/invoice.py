"""Kontrola pobranej faktury VAT w formacie PDF."""

import re
from io import BytesIO

from pypdf import PdfReader


def assert_invoice_pdf(content: bytes, reference: str) -> int:
    """Sprawdza, że plik jest niepustym PDF ze stronami i referencją zamówienia; zwraca liczbę stron."""
    assert content, "Invoice file is empty"
    # Sklep zgłasza brak faktury zwykłym tekstem, więc najpierw sprawdzamy sygnaturę pliku.
    assert content.startswith(b"%PDF-"), f"Invoice is not a PDF file, it starts with: {content[:80]!r}"
    assert b"%%EOF" in content[-1024:], "Invoice PDF is truncated: missing end-of-file marker"
    try:
        reader = PdfReader(BytesIO(content))
        page_count = len(reader.pages)
        text = "\n".join(page.extract_text() or "" for page in reader.pages)
    except Exception as error:  # pypdf zgłasza różne typy wyjątków dla uszkodzonych plików.
        raise AssertionError(f"Invoice PDF cannot be read: {error}") from error
    assert page_count >= 1, "Invoice PDF has no pages"
    assert text.strip(), "Invoice PDF contains no text"
    # Ekstrakcja tekstu może rozdzielać znaki odstępami, dlatego porównujemy bez białych znaków.
    assert reference in re.sub(r"\s+", "", text), f"Invoice PDF does not mention order {reference}"
    return page_count
