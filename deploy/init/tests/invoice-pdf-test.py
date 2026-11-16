"""Optional PDF QA after orders-invoices-test.php (requires PyMuPDF in dev venv)."""
import argparse
from pathlib import Path

import pymupdf

parser = argparse.ArgumentParser(description="Check downloaded demo invoices and optionally render PNGs.")
parser.add_argument("pdfs", nargs="+", type=Path)
parser.add_argument("--render-dir", type=Path)
args = parser.parse_args()
if args.render_dir:
    args.render_dir.mkdir(parents=True, exist_ok=True)
for path in args.pdfs:
    with pymupdf.open(path) as document:
        assert len(document) == 1 and not document.is_encrypted, f"Unexpected PDF structure: {path}"
        text = " ".join(document[0].get_text().split())
        # The integration fixture is 100 PLN gross + 15 PLN shipping, both at 23%.
        # Inspect actual PDF text rather than trusting the HTTP MIME type alone.
        for expected in ["FAKTURA", "#FV/DEMO/", "Gdańsk", "Test faktury VAT", "Adres demonstracyjny 1",
                         "Miasto testowe", "Dokument demonstracyjny", "fikcyjne", "23 %", "81,30 zł",
                         "18,70 zł", "12,20 zł", "2,80 zł", "93,50 zł", "21,50 zł", "115,00 zł"]:
            assert expected in text, f"Missing {expected!r} in {path}"
        assert "\ufffd" not in text, f"Broken Unicode in {path}"
        if args.render_dir:
            document[0].get_pixmap(matrix=pymupdf.Matrix(1.5, 1.5)).save(str(args.render_dir / f"{path.stem}.png"))
    print(f"PDF OK: {path.name}; Polish text, issuer, VAT, totals and demonstrational notice.")
