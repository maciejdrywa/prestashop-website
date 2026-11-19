"""Testy automatyczne skryptu tworzenia kopii zapasowej deploy/backup.sh."""

import json
import stat
import subprocess
import tarfile
from pathlib import Path

REPO_DIR = Path(__file__).resolve().parents[2]
BACKUP_SCRIPT = REPO_DIR / "deploy" / "backup.sh"


def test_backup_script_exists_and_executable():
    """Weryfikuje istnienie skryptu deploy/backup.sh oraz uprawnienia wykonywalności."""
    assert BACKUP_SCRIPT.is_file(), f"Brak pliku {BACKUP_SCRIPT}"
    mode = BACKUP_SCRIPT.stat().st_mode
    assert bool(mode & stat.S_IXUSR), (
        "Brak uprawnień wykonywalności dla użytkownika (chmod +x)"
    )


def test_backup_script_syntax():
    """Weryfikuje poprawność składniową skryptu za pomocą bash -n."""
    res = subprocess.run(
        ["bash", "-n", str(BACKUP_SCRIPT)], capture_output=True, text=True
    )
    assert res.returncode == 0, f"Błąd składni bash: {res.stderr}"


def test_backup_script_help_flag():
    """Sprawdza obsługę flag -h i --help."""
    for flag in ["-h", "--help"]:
        res = subprocess.run([str(BACKUP_SCRIPT), flag], capture_output=True, text=True)
        assert res.returncode == 0, f"Flaga {flag} zwróciła kod {res.returncode}"
        assert "Użycie: deploy/backup.sh" in res.stdout
        assert "--output-dir" in res.stdout
        assert "--maintenance" in res.stdout


def test_backup_script_rejects_unknown_flag():
    """Sprawdza odrzucenie nieznanej opcji z kodem wyjścia 1."""
    res = subprocess.run(
        [str(BACKUP_SCRIPT), "--nieistniejaca-opcja"], capture_output=True, text=True
    )
    assert res.returncode == 1
    assert (
        "Nieznana opcja: --nieistniejaca-opcja" in res.stderr
        or "Nieznana opcja" in res.stdout
    )


def test_backup_script_rejects_missing_output_dir_arg():
    """Sprawdza zgłoszenie błędu przy braku wartości dla --output-dir."""
    res = subprocess.run(
        [str(BACKUP_SCRIPT), "--output-dir"], capture_output=True, text=True
    )
    assert res.returncode == 1
    assert (
        "wymaga podania ścieżki" in res.stderr or "wymaga podania ścieżki" in res.stdout
    )


def test_backup_execution_and_archive_structure(tmp_path: Path):
    """Tworzy kopię do katalogu tymczasowego i weryfikuje pełną strukturę archiwum oraz uprawnienia."""
    output_dir = tmp_path / "custom_backups"

    res = subprocess.run(
        [str(BACKUP_SCRIPT), "--output-dir", str(output_dir)],
        cwd=REPO_DIR,
        capture_output=True,
        text=True,
    )
    assert res.returncode == 0, (
        f"Skrypt backup.sh zakończył się błędem:\n{res.stderr}\n{res.stdout}"
    )
    assert "Kopia zapasowa została pomyślnie utworzona i zweryfikowana!" in res.stdout

    # Weryfikacja uprawnień katalogu (0700)
    dir_mode = output_dir.stat().st_mode & 0o777
    assert dir_mode == 0o700, (
        f"Niepoprawne uprawnienia katalogu docelowego: {oct(dir_mode)}"
    )

    # Weryfikacja obecności .gitignore w katalogu kopii
    gitignore_file = output_dir / ".gitignore"
    assert gitignore_file.is_file(), "Brak pliku .gitignore w katalogu kopii"
    assert "*\n!.gitignore" in gitignore_file.read_text(encoding="utf-8")

    # Weryfikacja pliku archiwum
    archives = list(output_dir.glob("backup_*.tar.gz"))
    assert len(archives) == 1, f"Oczekiwano jednego archiwum, znaleziono: {archives}"
    archive = archives[0]

    # Weryfikacja uprawnień pliku archiwum (0600)
    file_mode = archive.stat().st_mode & 0o777
    assert file_mode == 0o600, (
        f"Niepoprawne uprawnienia pliku archiwum: {oct(file_mode)}"
    )

    # Wypakowanie i badanie zawartości
    extract_dir = tmp_path / "extracted"
    extract_dir.mkdir()
    with tarfile.open(archive, "r:gz") as tar:
        tar.extractall(extract_dir)

    backup_folders = [p for p in extract_dir.iterdir() if p.is_dir()]
    assert len(backup_folders) == 1, (
        "Archiwum powinno zawierać jeden główny katalog kopii"
    )
    backup_root = backup_folders[0]

    # Weryfikacja wymaganych plików i katalogów
    db_file = backup_root / "database.sql"
    assert db_file.is_file() and db_file.stat().st_size > 0, (
        "Brak lub pusty plik database.sql"
    )

    # Weryfikacja poprawności zrzutu SQL
    db_content = db_file.read_text(encoding="utf-8", errors="replace")
    assert "CREATE TABLE" in db_content, "Zrzut SQL nie zawiera instrukcji CREATE TABLE"
    assert "Dump completed" in db_content, (
        "Zrzut SQL nie zawiera stopki 'Dump completed'"
    )

    # Weryfikacja katalogu zdjęć produktów
    images_dir = backup_root / "images" / "p"
    assert images_dir.is_dir(), "Brak katalogu images/p"
    all_image_files = [p for p in images_dir.rglob("*") if p.is_file()]
    assert len(all_image_files) > 0, "Katalog images/p nie zawiera zdjęć produktów"

    # Weryfikacja plików konfiguracyjnych
    config_dir = backup_root / "config"
    assert config_dir.is_dir(), "Brak katalogu config/"
    assert (config_dir / "parameters.php").is_file(), "Brak parameters.php w config/"
    assert (config_dir / ".env").is_file(), "Brak .env w config/"

    # Weryfikacja manifestu
    manifest_file = backup_root / "manifest.json"
    assert manifest_file.is_file(), "Brak manifest.json w archiwum"
    manifest = json.loads(manifest_file.read_text(encoding="utf-8"))

    assert "backup_name" in manifest
    assert "created_at" in manifest
    assert manifest.get("prestashop_version") == "8.2.8"
    assert "database" in manifest
    assert manifest["database"]["file"] == "database.sql"
    assert manifest["database"]["size_bytes"] == db_file.stat().st_size
    assert "summary" in manifest
    assert manifest["summary"]["images_count"] == len(all_image_files)
    assert "files" in manifest
    assert "database.sql" in manifest["files"]
    assert "config/parameters.php" in manifest["files"]


def test_backup_with_maintenance_mode(tmp_path: Path):
    """Sprawdza działanie flagi --maintenance i przywrócenie dostępności sklepu."""
    output_dir = tmp_path / "maintenance_backups"

    res = subprocess.run(
        [str(BACKUP_SCRIPT), "--output-dir", str(output_dir), "--maintenance"],
        cwd=REPO_DIR,
        capture_output=True,
        text=True,
    )
    assert res.returncode == 0, (
        f"Błąd wykonania z flagą --maintenance: {res.stderr}\n{res.stdout}"
    )
    assert "Włączanie trybu konserwacji w PrestaShop" in res.stdout
    assert "Przywracanie dostępności sklepu (PS_SHOP_ENABLE=1)" in res.stdout

    # Weryfikacja końcowego stanu sklepu w PrestaShop
    check_status = subprocess.run(
        [
            "docker",
            "compose",
            "--env-file",
            ".env",
            "-f",
            "deploy/compose.yaml",
            "exec",
            "-T",
            "shop",
            "php",
            "bin/console",
            "prestashop:config",
            "get",
            "PS_SHOP_ENABLE",
        ],
        cwd=REPO_DIR,
        capture_output=True,
        text=True,
    )
    assert check_status.returncode == 0
    assert "PS_SHOP_ENABLE=1" in check_status.stdout
