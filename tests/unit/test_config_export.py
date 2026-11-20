"""Testy pliku eksportu konfiguracji deploy/config/shop-config.json.

Nie wymagają uruchomionego sklepu. Sprawdzają, że plik w repozytorium ma oczekiwaną strukturę, obejmuje
ustawienia wymagane przez sklep, jest zgodny z wartościami konfiguratorów (np. taryfami w lib/carriers.php)
i nie zawiera sekretów ani danych osobowych. Wzorce odpowiadają kontroli z deploy/init/lib/config-export.php,
a test sprawdza je dodatkowo na przykładach wycieków.
"""

import html
import json
import re
from decimal import ROUND_HALF_UP, Decimal
from pathlib import Path
from urllib.parse import unquote

import pytest

REPO_DIR = Path(__file__).resolve().parents[2]
EXPORT_FILE = REPO_DIR / "deploy" / "config" / "shop-config.json"
LIBRARY = REPO_DIR / "deploy" / "init" / "lib" / "config-export.php"
CARRIERS = REPO_DIR / "deploy" / "init" / "lib" / "carriers.php"

SECTIONS = [
    "languages", "currencies", "countries", "customer_groups", "taxes", "modules",
    "theme", "cms", "carriers", "payments", "configuration",
]
SENSITIVE_KEY = re.compile(
    r"passw|secret|token|salt|private|api_?key|cookie_?(key|iv)|dkim|credential|iban|swift", re.I
)
SENSITIVE_UPPER_KEY = re.compile(r"(^|_)KEY$|_RAND$|MAIL_USER$|(^|_)(E?MAILS?|MAIL_RECEIVERS|EMAIL_RECEIVERS)$")
ALLOWED_FLAG_KEYS = {"PS_CUSTOMER_CREATION_EMAIL", "PS_LOG_EMAILS"}
VALUE_PATTERNS = {
    "adres e-mail": re.compile(r"[A-Z0-9._%+\-]+@[A-Z0-9\-]+(?:\.[A-Z0-9\-]+)*\.[A-Z]{2,}", re.I),
    "IBAN": re.compile(r"\b[A-Z]{2}[0-9]{2}(?: ?[0-9A-Z]{4}){3,7}(?: ?[0-9A-Z]{1,4})?\b"),
    "długi ciąg cyfr": re.compile(r"(?:\d[ \-]?){20,}"),
    "numer telefonu": re.compile(
        r"(?<![\d.,/])(?:\+\d{2}[ \-]?|\(\d{2}\)[ \-]?)?"
        r"(?:\d{3}[ \-]?\d{3}[ \-]?\d{3}|\d{2}[ \-]\d{3}[ \-]\d{2}[ \-]\d{2}|\d{3}[ \-]\d{2}[ \-]\d{2})(?![\d.,])"
    ),
    "klucz API": re.compile(
        r"\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}|\bAIza[0-9A-Za-z_\-]{30,}"
        r"|\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}"
    ),
}
TOKEN = re.compile(r"(?<![A-Za-z0-9_])(?=[A-Za-z0-9_]*\d)(?=[A-Za-z0-9_]*[A-Za-z])[A-Za-z0-9_]{32,}(?![A-Za-z0-9_])")
NUMBER = re.compile(r"(?<![\d.,])\d(?:[ \-]?\d){9,18}(?![\d.,])")


def _personal_number(digits):
    """Rozpoznaje PESEL, NIP i numer karty po sumie kontrolnej."""
    if len(set(digits)) == 1:
        return None
    values = [int(d) for d in digits]
    if len(digits) == 11 and (10 - sum(w * v for w, v in zip([1, 3, 7, 9, 1, 3, 7, 9, 1, 3], values)) % 10) % 10 == values[10]:
        return "PESEL"
    if len(digits) == 10:
        rest = sum(w * v for w, v in zip([6, 5, 7, 2, 3, 4, 5, 6, 7], values)) % 11
        if rest != 10 and rest == values[9]:
            return "NIP"
    if 13 <= len(digits) <= 19:
        total = 0
        for i, v in enumerate(reversed(values)):
            v = v * 2 if i % 2 else v
            total += v - 9 if v > 9 else v
        if total % 10 == 0:
            return "karta"
    return None


def _walk(node, path=""):
    """Zwraca pary (ścieżka, klucz albo wartość tekstowa) dla całej struktury."""
    if isinstance(node, dict):
        for key, value in node.items():
            child = f"{path}.{key}" if path else str(key)
            yield child, "key", str(key)
            yield from _walk(value, child)
    elif isinstance(node, list):
        for index, value in enumerate(node):
            yield from _walk(value, f"{path}[{index}]")
    elif isinstance(node, str):
        yield path, "value", node


def findings(data):
    """Zwraca opisy naruszeń bez wartości, zgodnie z kontrolą w bibliotece PHP."""
    result = []
    for path, kind, text in _walk(data):
        if kind == "key":
            if text not in ALLOWED_FLAG_KEYS and (SENSITIVE_KEY.search(text) or SENSITIVE_UPPER_KEY.search(text)):
                result.append(f"{path}: nazwa pola")
            continue
        decoded = html.unescape(unquote(text)) + "\n" + html.unescape(re.sub(r"<[^>]+>", "", text))
        for name, pattern in VALUE_PATTERNS.items():
            if pattern.search(decoded):
                result.append(f"{path}: {name}")
        if TOKEN.search(re.sub(r"/img/[^\"'\s)]+", "", decoded)):
            result.append(f"{path}: token")
        for candidate in NUMBER.findall(decoded):
            kind = _personal_number(re.sub(r"\D", "", candidate))
            if kind:
                result.append(f"{path}: {kind}")
    return result


@pytest.fixture(scope="module")
def export():
    """Wczytuje plik eksportu jako UTF-8."""
    assert EXPORT_FILE.is_file(), f"Brak pliku {EXPORT_FILE}"
    raw = EXPORT_FILE.read_bytes()
    assert not raw.startswith((b"\xff\xfe", b"\xfe\xff")), "Plik eksportu nie może być w UTF-16"
    return json.loads(raw.decode("utf-8"))


def test_export_has_format_and_all_sections(export):
    """Plik ma wersję formatu i wszystkie sekcje odtwarzane przez configure-from-export.php."""
    assert export["format"] == 1
    for section in SECTIONS:
        assert section in export, f"Brak sekcji {section}"


def test_export_contains_required_shop_settings(export):
    """Plik obejmuje język, walutę, podatki, płatności, przewoźników, CMS, motyw i moduły sklepu."""
    configuration = export["configuration"]
    assert configuration["PS_LANG_DEFAULT"] in export["languages"]
    assert configuration["PS_CURRENCY_DEFAULT"] in export["currencies"]
    assert configuration["PS_COUNTRY_DEFAULT"] in export["countries"]["active"]
    assert configuration["PS_TAX"] == "1"
    assert any(rule["country"] == "PL" and rule["rate"] == "23.000"
               for group in export["taxes"].values() for rule in group["rules"])
    assert configuration["PS_CARRIER_DEFAULT"] in export["carriers"]
    assert configuration["PS_CONDITIONS_CMS_ID"] in export["cms"]
    assert export["modules"]["ps_wirepayment"]["enabled"] and export["modules"]["ps_cashondelivery"]["enabled"]
    for module in ("ps_wirepayment", "ps_cashondelivery"):
        permissions = export["payments"][module]
        assert "PL" in permissions["countries"] and permissions["currencies"] and permissions["carriers"]
        assert set(permissions["carriers"]) <= set(export["carriers"])
    assert export["theme"]["name"] == "pandomu"


def test_export_references_are_natural_keys(export):
    """Odwołania nie zawierają ID instalacji, a treści CMS używają znaczników zamiast adresów z ID stron."""
    for carrier in export["carriers"].values():
        assert carrier["tax_rules_group"] is None or carrier["tax_rules_group"] in export["taxes"]
    for page in export["cms"].values():
        for fields in page["lang"].values():
            assert not re.search(r"/content/\d+-", fields["content"]), "Treść CMS zawiera adres z ID strony"
            for target in re.findall(r"\{\{cms:([A-Za-z0-9\-]+)\}\}", fields["content"]):
                assert target in export["cms"], f"Odnośnik do nieznanej strony {target}"


def test_export_has_no_secrets_or_personal_data(export):
    """Plik nie zawiera haseł, kluczy, adresów e-mail, numerów telefonów ani rachunków."""
    assert findings(export) == []


def test_export_configuration_keys_are_allowlisted(export):
    """Każdy klucz Configuration w pliku występuje w allowliście biblioteki PHP."""
    library = LIBRARY.read_text(encoding="utf-8")
    for key in export["configuration"]:
        assert f"'{key}' =>" in library, f"Klucz {key} spoza allowlisty"


@pytest.mark.parametrize("leak", [
    {"configuration": {"PS_SHOP_DETAILS": "kontakt: jan.kowalski@example.com"}},
    {"configuration": {"BANK_WIRE_DETAILS": "PL61 1090 1014 0000 0712 1981 2874"}},
    {"configuration": {"PS_MAIL_PASSWD": "x"}},
    {"configuration": {"PS_SHOP_DETAILS": "tel. 601 234 567"}},
    {"configuration": {"PS_SHOP_DETAILS": "A1B2C3D4E5F6G7H8J9K0L1M2N3P4Q5R6"}},
    {"configuration": {"PS_SHOP_DETAILS": "(58) 347-10-00"}},
    {"configuration": {"PS_SHOP_DETAILS": "+48601234567"}},
    {"configuration": {"PS_SHOP_DETAILS": "PESEL 44051401359"}},
    {"configuration": {"PS_SHOP_DETAILS": "NIP 123-456-32-18"}},
    {"configuration": {"PS_SHOP_DETAILS": "4111 1111 1111 1111"}},
    {"configuration": {"PS_SHOP_DETAILS": "sk_live_abcdefgh12345678"}},
    {"cms": {"o-sklepie": {"lang": {"pl": {"content": "<a href=\"mailto:jan&#64;example.com\">x</a>"}}}}},
    {"cms": {"o-sklepie": {"lang": {"pl": {"content": "<a href=\"mailto:a@b.pl\">x</a>"}}}}},
])
def test_scanner_detects_leaks(leak):
    """Kontrola wykrywa przykładowe sekrety i dane osobowe."""
    assert findings(leak) != []


@pytest.mark.parametrize("value", [
    "00-000", "2000.01", "50.000001", "12.195122", "10000.000000", "Dostawa w ciągu 2–3 dni roboczych",
    "<img src=\"/img/cms/5f4dcc3b5aa765d61d8327deb882cf99.jpg\">",
])
def test_scanner_accepts_regular_values(value):
    """Kontrola nie odrzuca kodów pocztowych, kwot, zakresów i nazw plików obrazów."""
    assert findings({"configuration": {"PS_SHOP_DETAILS": value}}) == []


def test_export_matches_carrier_configurator(export):
    """Taryfy w pliku odpowiadają lib/carriers.php; zmiana konfiguratora bez ponownego eksportu zostałaby cofnięta."""
    source = CARRIERS.read_text(encoding="utf-8")
    definitions = dict(re.findall(r"'(STANDARD|EXPRESS)' => \['name' => '[^']+', 'gross' => '([0-9.]+)'", source))
    assert set(definitions) == {"STANDARD", "EXPRESS"}
    upper = re.search(r"COURIER_WEIGHT_UPPER_BOUND = '([0-9.]+)'", source).group(1)
    max_weight = re.search(r"COURIER_MAX_WEIGHT_KG = ([0-9]+);", source).group(1)
    for key, gross in definitions.items():
        carrier = export["carriers"][key]
        net = (Decimal(gross) / Decimal("1.23")).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)
        assert carrier["weight_ranges"] == [{"from": "0.000000", "to": f"{Decimal(upper):.6f}", "prices": {"Europe": f"{net}"}}]
        assert carrier["max_weight"] == f"{Decimal(max_weight):.6f}" and carrier["active"]
    free = re.search(r"FREE_SHIPPING_MIN_GROSS = '([0-9.]+)'", source).group(1)
    assert export["configuration"]["PS_SHIPPING_FREE_PRICE"] == free
