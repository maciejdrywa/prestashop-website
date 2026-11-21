"""Ograniczone pobieranie, bezpieczny ZIP i normalizacja cache zdjęć."""

import hashlib
import io
import os
import stat
import tempfile
import zipfile
from collections import Counter
from functools import partial
from pathlib import Path, PurePosixPath
from urllib.parse import urlsplit

import requests
from PIL import Image
from robots_policy import source_get
from scrapping_images.process_and_save_images import (
    image_meets_requirements,
    process_and_save_images,
)

RELEASE_URL = "https://github.com/maciejdrywa/prestashop-website/releases/download/etap1/product_images.zip"
RELEASE_SHA256 = "2f48130e0b586ff40bcfd74803918dd607c34a91714cb0cfc216793bd5a870d7"
MAX_DOWNLOAD = 20_000_000
MAX_ARCHIVE = 128_000_000
MAX_EXPANDED = 512_000_000


def source_url(value):
    if not isinstance(value, str):
        raise ValueError("Niepoprawny URL zdjęcia.")
    parsed = urlsplit(value)
    if (
        parsed.scheme not in {"https", "http"}
        or not parsed.hostname
        or parsed.username
        or parsed.password
        or parsed.fragment
    ):
        raise ValueError("Niepoprawny URL zdjęcia.")
    return value


def filename(url):
    parts = urlsplit(source_url(url)).path.rstrip("/").split("/")
    name = (
        str(Path(f"{parts[-2]}_{parts[-1]}").with_suffix(".jpg"))
        if len(parts) >= 2
        else str(Path(parts[-1]).with_suffix(".jpg"))
    )
    if (
        not name
        or Path(name).name != name
        or "\\" in name
        or "%" in name
        or len(name.encode()) > 240
    ):
        raise ValueError("Niebezpieczna nazwa cache zdjęcia.")
    return name


def digest(path):
    checksum = hashlib.sha256()
    with Path(path).open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            checksum.update(chunk)
    return checksum.hexdigest()


def jpeg_valid(path):
    if not image_meets_requirements(path):
        return False
    with Image.open(path) as picture:
        return (
            picture.format == "JPEG"
            and picture.mode == "RGB"
            and picture.getexif().get(274, 1) == 1
        )


def normalize(content, target):
    try:
        with Image.open(io.BytesIO(content)) as picture:
            if picture.format not in {"JPEG", "WEBP"}:
                raise ValueError("Źródło musi być JPEG albo WebP.")
            if picture.width * picture.height > 40_000_000:
                raise ValueError("Przekroczony limit pikseli obrazu.")
            picture.verify()
    except (OSError, Image.DecompressionBombError) as exc:
        raise ValueError("Niepoprawny obraz źródłowy.") from exc
    if not process_and_save_images(content, target) or not jpeg_valid(target):
        raise ValueError("Nie udało się przygotować JPEG 800–1600 px do 300000 bajtów.")


def extract_archive(archive, directory):
    """Waliduje cały katalog ZIP przed zapisem; nie ufa ścieżkom ani linkom."""
    directory = directory.resolve()
    with zipfile.ZipFile(archive) as bundle:
        entries = bundle.infolist()
        if len(entries) > 5000 or sum(i.file_size for i in entries) > MAX_EXPANDED:
            raise ValueError("Przekroczone limity ZIP.")
        names = set()
        for entry in entries:
            path = PurePosixPath(entry.filename)
            if (
                path.is_absolute()
                or ".." in path.parts
                or "\\" in entry.filename
                or ":" in entry.filename
                or stat.S_ISLNK(entry.external_attr >> 16)
            ):
                raise ValueError("Niebezpieczna ścieżka ZIP.")
            if entry.is_dir():
                continue
            if entry.file_size > MAX_DOWNLOAD or entry.flag_bits & 1:
                raise ValueError("Niepoprawny wpis ZIP.")
            name = str(Path(path.name).with_suffix(".jpg"))
            if name in names or Path(name).suffix.lower() not in {
                ".jpg",
                ".jpeg",
                ".webp",
            }:
                raise ValueError("Powielona nazwa lub niepoprawny format w ZIP.")
            names.add(name)
        directory.mkdir(parents=True, exist_ok=True)
        count = 0
        for entry in entries:
            if entry.is_dir():
                continue
            target = directory / str(
                Path(PurePosixPath(entry.filename).name).with_suffix(".jpg")
            )
            if target.is_symlink():
                raise ValueError("Cache zawiera link symboliczny.")
            if target.exists() and jpeg_valid(target):
                continue
            content = bundle.read(entry)
            try:
                # Poprawny JPEG zachowujemy bez ponownej stratnej kompresji.
                with Image.open(io.BytesIO(content)) as picture:
                    valid = (
                        picture.format == "JPEG"
                        and picture.mode == "RGB"
                        and picture.getexif().get(274, 1) == 1
                        and 800 <= max(picture.size) <= 1600
                        and len(content) <= 300000
                    )
                    picture.load()
                if valid:
                    with tempfile.NamedTemporaryFile(
                        dir=directory, delete=False
                    ) as output:
                        temp = Path(output.name)
                        output.write(content)
                    os.replace(temp, target)
                else:
                    normalize(content, target)
                count += 1
            except (ValueError, OSError, Image.DecompressionBombError):
                # Uszkodzony plik nie ukrywa braków: pobierze go fallback źródłowy.
                continue
        return count


class ImageCache:
    def __init__(self, directory, session=None, use_release=True):
        self.directory = Path(directory)
        self.session = session or requests.Session()  # Nigdy sesja z Basic Auth API.
        self.use_release, self.release_attempted = use_release, False
        self.counts = Counter()
        self.release_error = None

    def download(self, url, target, limit):
        target.parent.mkdir(parents=True, exist_ok=True)
        temporary = None
        try:
            get = self.session.get if url == RELEASE_URL else partial(source_get, self.session)
            with get(
                url, stream=True, timeout=(10, 60), verify=requests.certs.where()
            ) as response:
                response.raise_for_status()
                if int(response.headers.get("Content-Length", 0)) > limit:
                    raise ValueError("Przekroczony limit pobierania.")
                with tempfile.NamedTemporaryFile(
                    dir=target.parent, delete=False
                ) as output:
                    temporary = Path(output.name)
                    size = 0
                    for chunk in response.iter_content(65536):
                        size += len(chunk)
                        if size > limit:
                            raise ValueError("Przekroczony limit pobierania.")
                        output.write(chunk)
                    output.flush()
                    os.fsync(output.fileno())
                os.replace(temporary, target)
                temporary = None
        except requests.RequestException:
            raise ValueError("Nie udało się pobrać zdjęcia lub archiwum.") from None
        finally:
            if temporary:
                temporary.unlink(missing_ok=True)

    def release(self):
        self.release_attempted = True
        archive = self.directory / ".product-images-v1.zip"
        if archive.is_symlink():
            raise ValueError("Archiwum cache jest linkiem symbolicznym.")
        try:
            if not archive.exists() or digest(archive) != RELEASE_SHA256:
                self.counts["release_downloads"] += 1
                self.download(RELEASE_URL, archive, MAX_ARCHIVE)
            if digest(archive) != RELEASE_SHA256:
                archive.unlink(missing_ok=True)
                raise ValueError("Niezgodna suma SHA-256 wydania zdjęć.")
            self.counts["release_files"] += extract_archive(archive, self.directory)
        except (ValueError, OSError, zipfile.BadZipFile) as exc:
            self.release_error = str(exc)

    def get(self, url):
        target = self.directory / filename(url)
        if target.is_symlink():
            raise ValueError("Cache zawiera link symboliczny.")
        if target.exists():
            if jpeg_valid(target):
                self.counts["hits"] += 1
                return target
            if target.stat().st_size <= MAX_DOWNLOAD:
                try:
                    normalize(target.read_bytes(), target)
                    self.counts["normalized"] += 1
                    return target
                except ValueError:
                    pass
        if self.use_release and not self.release_attempted:
            self.release()
        if target.exists() and jpeg_valid(target):
            self.counts["release_hits"] += 1
            return target
        self.counts["source_downloads"] += 1
        with tempfile.TemporaryDirectory() as temporary:
            downloaded = Path(temporary) / "source"
            self.download(source_url(url), downloaded, MAX_DOWNLOAD)
            normalize(downloaded.read_bytes(), target)
        return target
