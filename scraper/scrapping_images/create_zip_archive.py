import logging
import zipfile
from pathlib import Path


def create_zip_archive(source_dir: Path, zip_path: Path):
    """Pakuje katalog zdjęć do archiwum ZIP w podanej lokalizacji."""
    logging.info(f"robienie zipa {zip_path}")
    with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as zipf:
        for file in source_dir.glob("*.jpg"):
            zipf.write(file, arcname=file.name)
    size_mb = zip_path.stat().st_size / (1024 * 1024)
    logging.info(f"ZIP zrobiony: {zip_path.name} Wielkosc: ({size_mb:.2f} MB)")

