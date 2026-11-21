"""Trwałość dziennika przy blokadach plików i awariach zapisu."""

import json
from pathlib import Path
from unittest.mock import Mock

import pytest
from import_state import atomic_json


def windows_error(code):
    error = PermissionError("Windows temporarily locked the state file")
    error.winerror = code
    return error


@pytest.mark.parametrize("code", [5, 32, 33])
def test_atomic_write_retries_windows_lock_without_removing_old_state(tmp_path, monkeypatch, code):
    path = tmp_path / "products.state.json"
    path.write_text('{"previous":true}', encoding="utf-8")
    original = Path.replace
    attempts = []

    def replace(source, destination):
        attempts.append(source)
        if len(attempts) < 3:
            assert json.loads(path.read_text(encoding="utf-8")) == {"previous": True}
            raise windows_error(code)
        return original(source, destination)

    monkeypatch.setattr(Path, "replace", replace)
    sleep = Mock()
    monkeypatch.setattr("import_state.time.sleep", sleep)
    atomic_json(path, {"name": "Żółta klamka"}, compact=True)
    assert len(attempts) == 3 and len(set(attempts)) == 1
    assert sleep.call_count == 2
    assert json.loads(path.read_text(encoding="utf-8")) == {"name": "Żółta klamka"}
    assert list(tmp_path.iterdir()) == [path]


def test_permanent_lock_preserves_old_state_and_cleans_temporary_file(tmp_path, monkeypatch):
    path = tmp_path / "products.state.json"
    previous = b'{"previous":true}'
    path.write_bytes(previous)
    error = windows_error(5)
    replace = Mock(side_effect=error)
    monkeypatch.setattr(Path, "replace", replace)
    sleep = Mock()
    monkeypatch.setattr("import_state.time.sleep", sleep)
    with pytest.raises(PermissionError) as caught:
        atomic_json(path, {"new": True})
    assert caught.value is error
    assert replace.call_count == 10 and sleep.call_count == 9
    assert sum(call.args[0] for call in sleep.call_args_list) < 2
    assert path.read_bytes() == previous
    assert list(tmp_path.iterdir()) == [path]


def test_other_io_error_is_not_retried(tmp_path, monkeypatch):
    path = tmp_path / "products.state.json"
    path.write_text('{}', encoding="utf-8")
    error = OSError("Disk failure")
    replace = Mock(side_effect=error)
    monkeypatch.setattr(Path, "replace", replace)
    sleep = Mock()
    monkeypatch.setattr("import_state.time.sleep", sleep)
    with pytest.raises(OSError) as caught:
        atomic_json(path, {"new": True})
    assert caught.value is error
    replace.assert_called_once()
    sleep.assert_not_called()
    assert path.read_bytes() == b'{}'
    assert list(tmp_path.iterdir()) == [path]


def test_serialization_failure_preserves_state(tmp_path):
    path = tmp_path / "products.state.json"
    path.write_text('{}', encoding="utf-8")
    with pytest.raises(TypeError):
        atomic_json(path, {"unsupported": object()})
    assert path.read_bytes() == b'{}'
    assert list(tmp_path.iterdir()) == [path]
