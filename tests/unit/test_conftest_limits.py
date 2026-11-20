"""Kontrola limitów czasu i reguł blokady analityki z tests/conftest.py bez łączenia ze sklepem."""

import importlib.util
import os
import subprocess
import sys
import textwrap
import xml.etree.ElementTree as ET
from pathlib import Path

import pytest

CONFTEST_PATH = Path(__file__).resolve().parents[1] / "conftest.py"

# Osobna nazwa modułu, żeby nie mieszać z conftest załadowanym przez pytest.
_spec = importlib.util.spec_from_file_location("conftest_under_test", CONFTEST_PATH)
limits = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(limits)


@pytest.mark.parametrize(
    ("raw", "expected"),
    [(None, 300.0), ("", 300.0), ("  ", 300.0), ("120", 120.0), (" 0.5 ", 0.5), ("1e3", 1000.0)],
)
def test_parse_positive_number(raw, expected):
    """Sprawdza wartość domyślną i odczyt liczb całkowitych oraz ułamkowych."""
    assert limits.parse_positive_number("E2E_MAX_SECONDS", raw, 300.0) == expected


@pytest.mark.parametrize("raw", ["0", "-5", "abc", "5s", "nan", "inf", "1,5"])
def test_reject_invalid_positive_number(raw):
    """Sprawdza czytelny błąd dla zera, liczb ujemnych i tekstu."""
    with pytest.raises(pytest.UsageError, match="E2E_EXPECT_TIMEOUT_MS must be a positive number"):
        limits.parse_positive_number("E2E_EXPECT_TIMEOUT_MS", raw, 5000.0)


@pytest.mark.parametrize(
    ("raw", "expected"),
    [(None, False), ("", False), ("0", False), ("false", False), ("1", True), ("TRUE", True), ("yes", True)],
)
def test_parse_flag(raw, expected):
    """Sprawdza przełącznik E2E_ALLOW_ANALYTICS."""
    assert limits.parse_flag("E2E_ALLOW_ANALYTICS", raw) is expected


def test_reject_invalid_flag():
    """Literówka w przełączniku przerywa sesję zamiast po cichu włączać analitykę."""
    with pytest.raises(pytest.UsageError, match="E2E_ALLOW_ANALYTICS must be 0 or 1"):
        limits.parse_flag("E2E_ALLOW_ANALYTICS", "tak")


def test_analytics_resolver_rules_cover_hosts_and_subdomains():
    """Sprawdza, że reguły Chromium obejmują każdy host analityki i jego subdomeny."""
    rules = [rule.strip() for rule in limits.analytics_resolver_rules().split(",")]
    for host in limits.ANALYTICS_HOSTS:
        assert f"MAP {host} ~NOTFOUND" in rules
        assert f"MAP *.{host} ~NOTFOUND" in rules
    assert all(rule.startswith("MAP ") and rule.endswith(" ~NOTFOUND") for rule in rules)


SCENARIOS = textwrap.dedent(
    '''
    import time

    import pytest
    from pytest_playwright.pytest_playwright import _test_failed_before_teardown


    @pytest.fixture
    def artifacts_decision(request):
        """Zapisuje tę samą decyzję, którą pytest-playwright podejmuje o zrzucie i śladzie."""
        yield
        marker = request.config.rootpath / f"{request.node.name}.artifacts"
        marker.write_text(str(_test_failed_before_teardown(request.node)))


    def test_slow(artifacts_decision):
        time.sleep(0.6)


    def test_fast(artifacts_decision):
        pass


    def test_slow_and_failing():
        time.sleep(0.6)
        assert False, "original failure"
    '''
)


def _run_inner_pytest(tmp_path: Path, env_overrides: dict) -> subprocess.CompletedProcess:
    """Uruchamia osobną sesję pytest z kopią conftest.py i scenariuszami w katalogu e2e."""
    (tmp_path / "conftest.py").write_text(CONFTEST_PATH.read_text(encoding="utf-8"), encoding="utf-8")
    (tmp_path / "e2e").mkdir()
    (tmp_path / "e2e" / "test_scenarios.py").write_text(SCENARIOS, encoding="utf-8")
    (tmp_path / "other").mkdir()
    (tmp_path / "other" / "test_outside_e2e.py").write_text(
        "import time\n\ndef test_slow_outside():\n    time.sleep(0.6)\n", encoding="utf-8"
    )
    (tmp_path / "pytest.ini").write_text("[pytest]\njunit_logging = all\n", encoding="utf-8")
    env = {**os.environ, **env_overrides}
    return subprocess.run(
        [
            sys.executable, "-m", "pytest", "-p", "no:cacheprovider",
            f"--junitxml={tmp_path / 'junit.xml'}", f"--html={tmp_path / 'report.html'}",
            "--self-contained-html", f"--output={tmp_path / 'out'}",
        ],
        cwd=tmp_path, env=env, capture_output=True, text=True, timeout=120,
    )


def test_time_limit_fails_call_phase_and_keeps_reports(tmp_path):
    """Przekroczenie E2E_MAX_SECONDS daje zwykły błąd w fazie call, zapisany w JUnit i HTML."""
    result = _run_inner_pytest(tmp_path, {"E2E_MAX_SECONDS": "0.3"})
    assert result.returncode == 1, result.stdout + result.stderr

    cases = {case.get("name"): case for case in ET.parse(tmp_path / "junit.xml").iter("testcase")}
    slow = cases["test_slow"]
    failure = slow.find("failure")
    assert failure is not None, ET.tostring(slow, encoding="unicode")
    assert "exceeds the limit of 0.3 s (E2E_MAX_SECONDS)" in failure.get("message")
    assert slow.find("error") is None
    assert "DURATION:" in slow.find("system-out").text
    assert "(limit 0.3 s)" in slow.find("system-out").text

    assert cases["test_fast"].find("failure") is None
    assert "DURATION:" in cases["test_fast"].find("system-out").text
    # Błąd testu pozostaje głównym komunikatem, limit go nie zastępuje.
    assert "original failure" in cases["test_slow_and_failing"].find("failure").get("message")
    # Testy spoza e2e nie są objęte limitem.
    assert cases["test_slow_outside"].find("failure") is None

    # pytest-playwright zachowuje zrzut i ślad, gdy rep_call jest nieudany.
    assert (tmp_path / "test_slow.artifacts").read_text() == "True"
    assert (tmp_path / "test_fast.artifacts").read_text() == "False"

    html = (tmp_path / "report.html").read_text(encoding="utf-8")
    assert "exceeds the limit of 0.3 s" in html


@pytest.mark.parametrize(
    ("name", "value", "message"),
    [
        ("E2E_MAX_SECONDS", "zero", "E2E_MAX_SECONDS must be a positive number"),
        ("E2E_EXPECT_TIMEOUT_MS", "-1", "E2E_EXPECT_TIMEOUT_MS must be a positive number"),
        ("E2E_ALLOW_ANALYTICS", "maybe", "E2E_ALLOW_ANALYTICS must be 0 or 1"),
    ],
)
def test_invalid_env_stops_session(tmp_path, name, value, message):
    """Błędna wartość zmiennej przerywa sesję z czytelnym komunikatem przed uruchomieniem testów."""
    result = _run_inner_pytest(tmp_path, {name: value})
    assert result.returncode == pytest.ExitCode.USAGE_ERROR, result.stdout + result.stderr
    assert message in result.stderr
