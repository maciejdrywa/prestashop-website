"""Trwały dziennik operacji API i blokada importu jednej instancji na hoście."""

from __future__ import annotations

import hashlib
import json
import os
import tempfile
from contextlib import contextmanager
from pathlib import Path

from prestashop_api import PrestaShopAPIError


def atomic_json(path: Path, data: dict) -> None:
    """Zapisuje i synchronizuje plik przed atomową podmianą."""
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w", encoding="utf-8", dir=path.parent, delete=False
        ) as stream:
            temporary = Path(stream.name)
            json.dump(data, stream, ensure_ascii=False, indent=2)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        temporary.replace(path)
        temporary = None
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


@contextmanager
def instance_lock(identity: str):
    """Blokada obejmuje również procesy używające innych plików mapowania."""
    token = hashlib.sha256(identity.encode()).hexdigest()
    path = Path(tempfile.gettempdir()) / f"prestashop-import-{token}.lock"
    with path.open("a+b") as stream:
        if os.name == "nt":
            import msvcrt

            stream.seek(0, os.SEEK_END)
            if stream.tell() == 0:
                stream.write(b"0")
                stream.flush()
            stream.seek(0)

            def acquire():
                msvcrt.locking(stream.fileno(), msvcrt.LK_NBLCK, 1)

            def release():
                stream.seek(0)
                msvcrt.locking(stream.fileno(), msvcrt.LK_UNLCK, 1)
        else:
            import fcntl

            def acquire():
                fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)

            def release():
                fcntl.flock(stream, fcntl.LOCK_UN)

        try:
            acquire()
        except OSError as exc:
            raise PrestaShopAPIError(
                "Inny importer tej instancji jest już uruchomiony."
            ) from exc
        try:
            yield
        finally:
            release()


class ImportJournal:
    """ID instalacji i URL muszą odpowiadać serwerowi; uszkodzony stan blokuje zapis."""

    def __init__(self, path: Path, api_url: str, identity: str):
        self.path = path
        self.data = {
            "version": 1,
            "api_url": api_url.rstrip("/"),
            "identity": identity,
            "records": {},
        }
        if path.exists():
            try:
                data = json.loads(path.read_text(encoding="utf-8"))
            except (ValueError, OSError) as exc:
                raise PrestaShopAPIError(
                    "Nie można odczytać dziennika importu; zapis zablokowany."
                ) from exc
            if (
                not isinstance(data, dict)
                or any(
                    data.get(key) != self.data[key]
                    for key in ("version", "api_url", "identity")
                )
                or not isinstance(data.get("records"), dict)
            ):
                raise PrestaShopAPIError(
                    "Dziennik dotyczy innej instalacji lub ma błędny format; zachowaj kopię i sprawdź mapowania."
                )
            for record in data["records"].values():
                if (
                    not isinstance(record, dict)
                    or record.get("state") not in {"pending", "done"}
                    or not isinstance(record.get("desired"), dict)
                    or not record.get("desired")
                    or not isinstance(record.get("path"), list)
                    or not record.get("path")
                ):
                    raise PrestaShopAPIError(
                        "Niepoprawny rekord dziennika; zapis zablokowany."
                    )
            self.data = data

    def put(self, source_url: str, record: dict) -> None:
        self.data["records"][source_url] = record
        atomic_json(self.path, self.data)

    def discard(self, source_url: str) -> None:
        self.data["records"].pop(source_url, None)
        atomic_json(self.path, self.data)
