import io
import json
from pathlib import Path
import random
import tempfile
import unittest
from unittest.mock import patch

from PIL import Image

import scrapping_images_module as downloader
from scrapping_images.process_and_save_images import (
    MAX_IMAGE_BYTES,
    image_meets_requirements,
    process_and_save_images,
)


def image_bytes(image, format="PNG", **options):
    buffer = io.BytesIO()
    image.save(buffer, format=format, **options)
    return buffer.getvalue()


class ImageProcessingTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.folder = Path(self.temporary.name)
        self.target = self.folder / "image.jpg"

    def process(self, source):
        self.assertTrue(process_and_save_images(source, self.target))
        self.assertTrue(image_meets_requirements(self.target))
        self.assertLessEqual(self.target.stat().st_size, MAX_IMAGE_BYTES)
        with Image.open(self.target) as image:
            self.assertEqual(image.format, "JPEG")
            self.assertEqual(image.mode, "RGB")
            return image.size

    def test_small_photo_is_upscaled_with_aspect_ratio(self):
        self.assertEqual(self.process(image_bytes(Image.new("RGB", (400, 300)))), (800, 600))

    def test_large_photo_is_downscaled(self):
        self.assertEqual(self.process(image_bytes(Image.new("RGB", (2400, 1800)))), (1600, 1200))

    def test_compliant_photo_keeps_its_dimensions(self):
        self.assertEqual(self.process(image_bytes(Image.new("RGB", (840, 840)))), (840, 840))

    def test_noise_cannot_bypass_byte_limit_at_low_quality(self):
        noise = Image.frombytes("RGB", (1600, 1600), random.Random(42).randbytes(1600 * 1600 * 3))
        width, height = self.process(image_bytes(noise))
        self.assertEqual(width, height)
        self.assertLess(width, 1600)
        self.assertGreaterEqual(width, 800)

    def test_transparency_uses_white_background(self):
        self.process(image_bytes(Image.new("RGBA", (800, 800), (0, 0, 0, 0))))
        with Image.open(self.target) as image:
            self.assertEqual(image.getpixel((0, 0)), (255, 255, 255))

    def test_exif_orientation_is_applied_before_resizing(self):
        image = Image.new("RGB", (2400, 1200))
        exif = Image.Exif()
        exif[274] = 6
        self.assertEqual(self.process(image_bytes(image, "JPEG", exif=exif)), (800, 1600))

    def test_bad_input_preserves_old_file_and_cleans_temporary_files(self):
        self.process(image_bytes(Image.new("RGB", (800, 800))))
        before = self.target.read_bytes()
        with self.assertLogs(level="ERROR"):
            self.assertFalse(process_and_save_images(b"not an image", self.target))
        self.assertEqual(before, self.target.read_bytes())
        self.assertEqual(list(self.folder.glob("*.tmp")), [])

    def test_validator_rejects_small_oversized_and_corrupt_files(self):
        self.target.write_bytes(image_bytes(Image.new("RGB", (400, 300)), "JPEG"))
        self.assertFalse(image_meets_requirements(self.target))
        self.target.write_bytes(b"x" * (MAX_IMAGE_BYTES + 1))
        self.assertFalse(image_meets_requirements(self.target))
        self.target.write_bytes(b"invalid")
        self.assertFalse(image_meets_requirements(self.target))

    def test_samples_are_capped_at_twenty_and_invalid_sample_is_replaced(self):
        self.process(image_bytes(Image.new("RGB", (800, 800))))
        samples = self.folder / "samples"
        samples.mkdir()
        for index in range(20):
            (samples / f"{index}.jpg").write_bytes(self.target.read_bytes())
        downloader.save_sample(self.target, samples)
        self.assertFalse((samples / self.target.name).exists())
        source = self.folder / "0.jpg"
        source.write_bytes(self.target.read_bytes())
        (samples / "0.jpg").write_bytes(b"invalid")
        downloader.save_sample(source, samples)
        self.assertEqual(len(list(samples.iterdir())), 20)
        self.assertEqual((samples / "0.jpg").read_bytes(), source.read_bytes())

    def test_jpeg_filename_ignores_source_extension_and_query(self):
        self.assertEqual(downloader.get_filename("https://example.test/123/photo.webp?size=2"), "123_photo.jpg")

    def test_resume_repairs_cached_photo_before_skipping_download(self):
        images = self.folder / "images"
        samples = self.folder / "samples"
        images.mkdir()
        source_url = "https://example.test/123/photo.jpg"
        cached = images / downloader.get_filename(source_url)
        cached.write_bytes(image_bytes(Image.new("RGB", (400, 300))))
        results = self.folder / "products.json"
        results.write_text(json.dumps([{"images": [source_url]}]), encoding="utf-8")
        with patch.multiple(downloader, RESULTS_FILE=results, IMAGES_DIR=images, SAMPLES_DIR=samples), \
                patch.object(downloader, "sync_playwright") as browser, \
                patch.object(downloader, "create_zip_archive") as archive:
            self.assertEqual(downloader.main(), 0)
            context = browser.return_value.__enter__.return_value.chromium.launch_persistent_context.return_value
            context.pages[0].goto.assert_not_called()
            archive.assert_called_once()
        self.assertTrue(image_meets_requirements(cached))
        self.assertTrue(image_meets_requirements(samples / cached.name))

    def test_failed_processing_prevents_successful_archive(self):
        results = self.folder / "products.json"
        results.write_text(json.dumps([{"images": ["https://example.test/1/bad.jpg"]}]), encoding="utf-8")
        with patch.multiple(downloader, RESULTS_FILE=results, IMAGES_DIR=self.folder / "images", SAMPLES_DIR=self.folder / "samples"), \
                patch.object(downloader, "sync_playwright") as browser, \
                patch.object(downloader, "create_zip_archive") as archive, \
                patch.object(downloader.time, "sleep"), self.assertLogs(level="ERROR"):
            context = browser.return_value.__enter__.return_value.chromium.launch_persistent_context.return_value
            response = context.pages[0].goto.return_value
            response.status = 200
            response.body.return_value = b"invalid image"
            self.assertEqual(downloader.main(), 1)
            archive.assert_not_called()


if __name__ == "__main__":
    unittest.main()
