"""Publiczny CLI D2.3. Nowe produkty pozostają nieaktywne do etapu zdjęć."""

import argparse
import json
import os
import sys
from pathlib import Path

from category_import.mapping import get_default_mapping_path, sanitize_instance_host
from prestashop_api import PrestaShopAPIError, PrestaShopClient
from product_import import import_products
from product_import.sync import write_report

ROOT = Path(__file__).resolve().parents[1]


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--env-file",
        type=Path,
        help="Opcjonalny plik konfiguracji API; domyślnie .env projektu",
    )
    parser.add_argument(
        "--products-file", type=Path, default=ROOT / "scraper-results/products.json"
    )
    parser.add_argument("--mapping-file", type=Path)
    parser.add_argument(
        "--context-file",
        type=Path,
        required=True,
        help="Świeży JSON z export-import-context.php",
    )
    parser.add_argument("--state-file", type=Path)
    parser.add_argument("--report-file", type=Path)
    parser.add_argument(
        "--ids",
        nargs="+",
        help="Źródłowe ID; plan magazynu nadal obejmuje cały katalog",
    )
    parser.add_argument(
        "--reset-stock",
        action="store_true",
        help="Jawnie odtwórz początkowe ilości także istniejących produktów",
    )
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)
    try:
        if args.env_file:
            allowed = {
                "PRESTASHOP_API_URL",
                "PRESTASHOP_API_KEY",
                "PRESTASHOP_API_VERIFY_SSL",
                "REQUESTS_CA_BUNDLE",
            }
            for line in args.env_file.read_text(encoding="utf-8").splitlines():
                if line.strip() and not line.lstrip().startswith("#") and "=" in line:
                    key, value = line.split("=", 1)
                    if key.strip() in allowed:
                        os.environ[key.strip()] = value.strip().strip("'\"")
        client = PrestaShopClient()
        instance = sanitize_instance_host(client.api_url)
        state = args.state_file or ROOT / f"logs/product-import/{instance}/state.json"
        mapping = args.mapping_file or get_default_mapping_path(
            ROOT / "scraper-results", client.api_url
        )
        report = import_products(
            client,
            args.products_file,
            mapping,
            args.context_file,
            state,
            source_ids=set(args.ids or []),
            reset_stock=args.reset_stock,
            dry_run=args.dry_run,
        )
        if not args.dry_run:
            write_report(args.report_file or state.with_name("report.json"), report)
        summary = {key: value for key, value in report.items() if key != "products"}
        print(json.dumps(summary, ensure_ascii=False, indent=2))
        return 1 if report["errors"] else 0
    except (ValueError, OSError, PrestaShopAPIError) as error:
        print(f"ERROR: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
