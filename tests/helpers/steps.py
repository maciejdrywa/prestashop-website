from contextlib import contextmanager
from time import monotonic


@contextmanager
def step(name: str):
    """Nazwany etap widoczny w logu i w opisie ewentualnego błędu."""
    start = monotonic()
    print(f"\nSTEP: {name}", flush=True)
    try:
        yield
    except Exception as error:
        error.add_note(f"Scenario step: {name}")
        raise
    print(f"OK: {name} ({monotonic() - start:.1f} s)", flush=True)
