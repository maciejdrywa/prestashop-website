"""Jedna komenda katalogu przez API: python scraper/import_catalog.py --bootstrap --resume."""

import argparse
import json
import sys
from pathlib import Path

from catalog_import.config import ROOT, environment
from catalog_import.runner import run_catalog
from category_import.mapping import sanitize_instance_host
from prestashop_api import PrestaShopAPIError


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--bootstrap",
        action="store_true",
        help="Zbuduj i uruchom usługi jedynego deploy/compose.yaml",
    )
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument(
        "--resume",
        action="store_true",
        help="Kontynuuj identyczne dane (tryb domyślny)",
    )
    mode.add_argument(
        "--sync", action="store_true", help="Świadomie synchronizuj zmienione dane"
    )
    parser.add_argument("--reset-stock", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--env-file", type=Path, default=ROOT / ".env")
    parser.add_argument(
        "--products-file", type=Path, default=ROOT / "scraper-results/products.json"
    )
    parser.add_argument(
        "--categories-file", type=Path, default=ROOT / "scraper-results/categories.json"
    )
    parser.add_argument(
        "--cache-dir", type=Path, default=ROOT / "scraper-results/images"
    )
    parser.add_argument("--state-dir", type=Path)
    parser.add_argument("--report-file", type=Path)
    parser.add_argument("--mapping-file", type=Path)
    parser.add_argument("--product-state-file", type=Path)
    parser.add_argument("--image-state-file", type=Path)
    parser.add_argument("--no-release", action="store_true")
    args = parser.parse_args(argv)
    try:
        if args.state_dir is None:
            host = sanitize_instance_host(
                environment(args.env_file).get(
                    "PRESTASHOP_API_URL", "https://localhost/api"
                )
            )
            args.state_dir = ROOT / "logs/catalog-import" / host
        report = run_catalog(args)
        console_report = {k: v for k, v in report.items() if k != "stages"}
        print(json.dumps(console_report, ensure_ascii=False, indent=2))
        return 130 if report.get("interrupted") else int(bool(report["errors"]))
    except (ValueError, OSError, PrestaShopAPIError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
