"""Trwały dziennik operacji API i blokada importu jednej instancji na hoście."""

from __future__ import annotations

import hashlib
import json
import os
import tempfile
import time
from contextlib import contextmanager
from contextvars import ContextVar
from pathlib import Path

from prestashop_api import PrestaShopAPIError

_protect_completed = ContextVar("prestashop_protect_completed", default=False)


@contextmanager
def completed_records_guard(enabled):
    token = _protect_completed.set(enabled)
    try:
        yield
    finally:
        _protect_completed.reset(token)


def completed_records_protected():
    return _protect_completed.get()


_held_locks = ContextVar("prestashop_import_locks", default=frozenset())


def atomic_json(path: Path, data: dict, *, compact: bool = False) -> None:
    """Zapisuje atomowo, ponawiając krótkotrwałe blokady plików Windows."""
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w", encoding="utf-8", dir=path.parent, delete=False
        ) as stream:
            temporary = Path(stream.name)
            if compact:
                stream.write(
                    json.dumps(data, ensure_ascii=False, separators=(",", ":"))
                )
            else:
                json.dump(data, stream, ensure_ascii=False, indent=2)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        # Windows nie pozwala podmienić pliku otwartego bez FILE_SHARE_DELETE,
        # np. podczas krótkiego odczytu przez skaner lub obserwatora katalogu.
        # Ponawiamy tę samą atomową operację, nigdy nie kasując starego stanu.
        for attempt in range(10):
            try:
                temporary.replace(path)
                break
            except OSError as exc:
                if getattr(exc, "winerror", None) not in {5, 32, 33} or attempt == 9:
                    raise
                time.sleep(min(0.025 * 2**attempt, 0.25))
        temporary = None
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


@contextmanager
def instance_lock(identity: str, *, reentrant: bool = False):
    """Blokada obejmuje również procesy używające innych plików mapowania."""
    lock_key = (os.getpid(), identity)
    if lock_key in _held_locks.get():
        yield
        return
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
        context_token = (
            _held_locks.set(_held_locks.get() | {lock_key}) if reentrant else None
        )
        try:
            yield
        finally:
            if context_token is not None:
                _held_locks.reset(context_token)
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
        previous = self.data["records"].get(source_url)
        if (
            completed_records_protected()
            and previous
            and previous["state"] == "done"
            and record["state"] == "pending"
            and not source_url.startswith("stock-seed:")
        ):
            raise PrestaShopAPIError(
                "Zmieniono ukończone pola katalogu poza importerem. Sprawdź zmiany i użyj --sync, aby je świadomie nadpisać."
            )
        self.data["records"][source_url] = record
        atomic_json(self.path, self.data, compact=True)

    def discard(self, source_url: str) -> None:
        self.data["records"].pop(source_url, None)
        atomic_json(self.path, self.data, compact=True)
