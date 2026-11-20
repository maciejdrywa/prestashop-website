"""D2.4: import galerii przez API. Nie aktywuje produktów ani nie uruchamia bootstrapu."""

import argparse
import json
import sys
from pathlib import Path

from category_import.mapping import sanitize_instance_host
from image_import import import_images
from import_state import atomic_json
from prestashop_api import PrestaShopAPIError, PrestaShopClient

ROOT = Path(__file__).resolve().parents[1]


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--products-file", type=Path, default=ROOT / "scraper-results/products.json"
    )
    parser.add_argument(
        "--cache-dir", type=Path, default=ROOT / "scraper-results/images"
    )
    parser.add_argument("--state-file", type=Path)
    parser.add_argument("--report-file", type=Path)
    parser.add_argument(
        "--legacy-map-file",
        type=Path,
        help="Świeży eksport export-image-mapping.php dla galerii PHP",
    )
    parser.add_argument("--shop-id", type=int, default=1)
    parser.add_argument("--ids", nargs="+")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument(
        "--no-release",
        action="store_true",
        help="Cache lokalny i źródło, bez GitHub Releases",
    )
    args = parser.parse_args(argv)
    try:
        if args.shop_id <= 0:
            raise ValueError("Niepoprawne ID sklepu.")
        client = PrestaShopClient()
        state = (
            args.state_file
            or ROOT
            / f"logs/image-import/{sanitize_instance_host(client.api_url)}/state.json"
        )
        report = import_images(
            client,
            args.products_file,
            args.cache_dir,
            state,
            shop_id=args.shop_id,
            legacy_map_file=args.legacy_map_file,
            source_ids=set(args.ids or []),
            dry_run=args.dry_run,
            use_release=not args.no_release,
        )
        if not args.dry_run:
            atomic_json(args.report_file or state.with_name("report.json"), report)
        print(
            json.dumps(
                {k: v for k, v in report.items() if k != "products"},
                ensure_ascii=False,
                indent=2,
            )
        )
        return int(bool(report["errors"]))
    except (ValueError, OSError, PrestaShopAPIError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
