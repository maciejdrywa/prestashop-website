"""Normalize product photos before saving them locally or as repository samples."""

import io
import logging
import os
from pathlib import Path
import tempfile

from PIL import Image, ImageOps

MIN_LONG_EDGE = 800
MAX_LONG_EDGE = 1600
MAX_IMAGE_BYTES = 300_000


def image_meets_requirements(path: Path) -> bool:
    """Check actual encoded content, dimensions and byte size, not just extension."""
    try:
        if not 0 < path.stat().st_size <= MAX_IMAGE_BYTES:
            return False
        with Image.open(path) as image:
            if image.format not in {"JPEG", "WEBP"}:
                return False
            if not MIN_LONG_EDGE <= max(image.size) <= MAX_LONG_EDGE:
                return False
            image.load()
        return True
    except (OSError, ValueError, Image.DecompressionBombError):
        return False


def _resize(image: Image.Image, edge: int) -> Image.Image:
    factor = edge / max(image.size)
    size = tuple(max(1, round(side * factor)) for side in image.size)
    return image.resize(size, Image.Resampling.LANCZOS)


def process_and_save_images(image_bytes: bytes, target_dir: Path) -> bool:
    """Save JPEG only after all constraints are met; retain old file on failure."""
    temporary = None
    try:
        if target_dir.suffix.lower() not in {".jpg", ".jpeg"}:
            raise ValueError("JPEG output must have a .jpg or .jpeg extension")
        with Image.open(io.BytesIO(image_bytes)) as source:
            image = ImageOps.exif_transpose(source)
            if image.mode in {"RGBA", "LA"} or "transparency" in image.info:
                rgba = image.convert("RGBA")
                image = Image.new("RGB", rgba.size, "white")
                image.paste(rgba, mask=rgba.getchannel("A"))
            else:
                image = image.convert("RGB")
            edge = max(image.size)
            if edge < MIN_LONG_EDGE or edge > MAX_LONG_EDGE:
                image = _resize(image, min(MAX_LONG_EDGE, max(MIN_LONG_EDGE, edge)))

            # Lower quality first, then dimensions, never below 800 px.
            while True:
                encoded = None
                for quality in range(85, 39, -5):
                    buffer = io.BytesIO()
                    image.save(buffer, format="JPEG", quality=quality, optimize=True)
                    if buffer.tell() <= MAX_IMAGE_BYTES:
                        encoded = buffer.getvalue()
                        break
                if encoded is not None:
                    break
                edge = max(image.size)
                if edge == MIN_LONG_EDGE:
                    raise ValueError("Cannot fit JPEG into 300000 bytes at the minimum 800 px")
                image = _resize(image, max(MIN_LONG_EDGE, round(edge * 0.85)))

        target_dir.parent.mkdir(parents=True, exist_ok=True)
        with tempfile.NamedTemporaryFile(dir=target_dir.parent, suffix=".tmp", delete=False) as output:
            temporary = Path(output.name)
            output.write(encoded)
        if not image_meets_requirements(temporary):
            raise ValueError("Encoded image failed final validation")
        os.replace(temporary, target_dir)
        temporary = None
        return True
    except Exception as error:
        logging.error("Nie przetworzono obrazu %s: %s", target_dir.name, error)
        return False
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)
