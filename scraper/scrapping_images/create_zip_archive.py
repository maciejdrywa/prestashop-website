from pathlib import Path
import logging
import zipfile


def create_zip_archive(source_dir: Path, zip_path: Path):
    logging.info(f"robienie zipa {zip_path}")
    with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as zipf:
        for file in source_dir.glob("*.jpg"):
            zipf.write(file, arcname=file.name)
    size_mb = zip_path.stat().st_size / (1024 * 1024)
    logging.info(f"ZIP zrobiony: {zip_path.name} Wielkosc: ({size_mb:.2f} MB)")

