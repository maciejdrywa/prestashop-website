"""Konfiguracja bez wykonywania powłoki i bez wypisywania sekretów."""

import os
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def environment(path):
    values = {}
    for line in Path(path).read_text(encoding="utf-8").splitlines():
        if line.strip() and not line.lstrip().startswith("#") and "=" in line:
            key, value = line.split("=", 1)
            values[key.strip()] = value.strip().strip("\"'")
    for key in values.keys() | {
        "REQUESTS_CA_BUNDLE",
        "PRESTASHOP_API_URL",
        "PRESTASHOP_API_KEY",
    }:
        if not values.get(key) and key in os.environ:
            values[key] = os.environ[key]
    return values


class Compose:
    def __init__(self, env_file, values, source_dir):
        self.env_file = Path(env_file).resolve()
        self.env = {
            **os.environ,
            **values,
            "IMPORT_BACKEND": "api",
            "SCRAPER_RESULTS_DIR": str(Path(source_dir).resolve()),
        }

    def safe_output(self, value):
        for key, secret in self.env.items():
            if re.search(r"PASSWORD|PASSWD|API_KEY|TOKEN|SECRET", key) and secret:
                value = value.replace(secret, "[REDACTED]")
        return "\n".join(
            line
            for line in value.splitlines()
            if "authorization" not in line.lower() and "basic " not in line.lower()
        )

    def run(self, *args, capture=False):
        result = subprocess.run(
            [
                "docker",
                "compose",
                "--env-file",
                str(self.env_file),
                "-f",
                str(ROOT / "deploy/compose.yaml"),
                *args,
            ],
            cwd=ROOT,
            env=self.env,
            capture_output=True,
            text=True,
        )
        if result.returncode:
            diagnostic = self.safe_output(result.stdout + "\n" + result.stderr)[-2000:]
            raise ValueError(
                f"Compose {args[0]} zakończył się kodem {result.returncode}: {diagnostic}"
            )
        if not capture and result.stdout:
            print(self.safe_output(result.stdout), flush=True)
        return result.stdout

    def php(self, script, *args, capture=False):
        return self.run(
            "exec",
            "-T",
            "--user",
            "www-data",
            "shop",
            "php",
            "/opt/shop/init/" + script,
            *args,
            capture=capture,
        )
