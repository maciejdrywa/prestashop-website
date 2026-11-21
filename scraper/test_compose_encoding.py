"""Wyjście procesów Compose musi być czytelne także przy locale CP1250."""

import json
import subprocess
import sys
from unittest.mock import Mock

import pytest
from catalog_import.config import Compose


@pytest.mark.parametrize("returncode", [0, 1])
def test_real_child_output_uses_utf8_despite_windows_locale(tmp_path, monkeypatch, returncode):
    compose = Compose(tmp_path / "env", {}, tmp_path / "source")
    run = subprocess.run
    message = "Żółta klamka — koniec \U0001f600"
    payload = json.dumps({"message": message}, ensure_ascii=False).encode("utf-8")
    script = (
        f"import sys; sys.stdout.buffer.write({payload!r}); "
        "sys.stderr.buffer.write(b'error: \\xff'); "
        f"sys.exit({returncode})"
    )
    # Wykonujemy prawdziwy proces, ale bez Dockera, sklepu i połączeń sieciowych.
    def child(command, **kwargs):
        return run([sys.executable, "-c", script], **kwargs)

    monkeypatch.setattr(subprocess, "_text_encoding", lambda: "cp1250")
    monkeypatch.setattr(subprocess, "run", child)
    if returncode == 0:
        assert json.loads(compose.run("exec", capture=True)) == {"message": message}
    else:
        with pytest.raises(ValueError) as caught:
            compose.run("exec", capture=True)
        assert message in str(caught.value)
        assert "error: \ufffd" in str(caught.value)
        assert "kodem 1" in str(caught.value)


@pytest.mark.parametrize("stdout,stderr", [(None, "PHP failed"), ("PHP failed", None)])
def test_missing_output_does_not_mask_process_error(tmp_path, monkeypatch, stdout, stderr):
    compose = Compose(tmp_path / "env", {}, tmp_path / "source")
    monkeypatch.setattr(
        subprocess, "run",
        Mock(return_value=subprocess.CompletedProcess([], 1, stdout, stderr)),
    )
    with pytest.raises(ValueError, match="PHP failed"):
        compose.run("exec")
