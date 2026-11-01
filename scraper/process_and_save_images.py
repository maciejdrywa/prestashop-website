#W tej funkcji jest duzo dmuchania na zimne, z tego co analizowalem na naszej stronie zdjecia powinny byc zapisywane w rgb w rozdzielczosci 840x840 i powinny wazyc okolo 20kb

from pathlib import Path
from PIL import Image
import io
import logging

def process_and_save_images(image_bytes: bytes, target_dir: Path ) -> bool:
    try:
        with Image.open(io.BytesIO(image_bytes)) as img:

            # Czy RGB
            if img.mode != 'RGB':
                img = img.convert('RGB')

            width, height = img.size
            # Czy nie za duzy
            if max(width, height) > 1600:
                scale = 1600 / max(width, height)
                scaled_size = (int(width * scale), int(height * scale))
                img = img.resize(scaled_size)


            target_dir.parent.mkdir(parents=True, exist_ok=True)
            quality = 85

            # Czy nie wazy za duzo
            while quality >= 40:
                buffer = io.BytesIO()
                img.save(buffer, format='JPEG', quality=quality)
                size_kb = buffer.tell() / 1024

                if size_kb <= 300 or quality <= 45:
                    with open(target_dir, 'wb') as f:
                        f.write(buffer.getvalue())
                    return True
                quality -= 5


            return True
    except Exception as e:
        logging.error(f"Nie przetworzono obrazu. {e}")
        return False