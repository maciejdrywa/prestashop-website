import { test, expect, Page, Locator } from '@playwright/test';

/**
 * Scenariusz koszyka wymagany na odbiorze:
 *  1. 10 różnych produktów z 2 kategorii, w różnych ilościach,
 *  2. wyszukanie produktu po nazwie,
 *  3. wylosowanie produktu z wyników i dodanie go do koszyka,
 *  4. usunięcie 3 produktów,
 *  5. asercje produktów i ilości po każdej operacji.
 *
 * Test nie zna z góry adresów ani ID produktów. Produkty wybiera w trakcie
 * działania z list kategorii, sprawdzając stan magazynowy na stronie produktu.
 * Dzięki temu działa zarówno na lokalnym PrestaShopie (inne ID, stany 0–10),
 * jak i na sklepie źródłowym. Adres sklepu ustawia BASE_URL.
 */

type Produkt = { id: string; nazwa: string; url: string; ilosc: number };

// Kategorie główne z menu sklepu i ilości do dodania (różne, w zakresie stanów 0–10).
const KATEGORIE = [
  { nazwa: 'Akcesoria meblowe', ilosci: [2, 1, 3, 1, 2] },
  { nazwa: 'Klamki do drzwi', ilosci: [1, 3, 2, 2, 1] },
];
// Fraza wyszukiwania, można nadpisać zmienną środowiskową FRAZA.
const FRAZA = process.env.FRAZA ?? 'klamka';
// Adres koszyka działający z przyjaznymi adresami i bez nich.
const KOSZYK = '/index.php?controller=cart&action=show';

// ---------- pomocnicze ----------

/** Adres strony głównej kategorii, odczytany z linku w menu. */
async function adresKategorii(page: Page, nazwa: string): Promise<string> {
  await page.goto('/');
  const link = page.getByRole('link', { name: nazwa }).first();
  await expect(link, `Brak kategorii "${nazwa}" w menu`).toBeAttached();
  const href = await link.getAttribute('href');
  expect(href, `Link kategorii "${nazwa}" nie ma adresu`).toBeTruthy();
  return new URL(href!, page.url()).toString();
}

/** Linki do produktów z bieżącej listy (kategoria lub wyniki wyszukiwania). */
async function linkiProduktow(page: Page): Promise<string[]> {
  const karty = page.locator('.product-miniature');
  const linki: string[] = [];
  for (const karta of await karty.all()) {
    const href = await karta.locator('.product-title a, a.thumbnail').first().getAttribute('href');
    if (href) linki.push(new URL(href, page.url()).toString());
  }
  return linki;
}

/**
 * Otwiera stronę produktu i zwraca jego ID oraz dostępny stan.
 * Stan pochodzi z atrybutu data-stock (motyw classic). Gdy sklep go nie pokazuje,
 * zwracamy null, a dostępność potwierdza aktywny przycisk i okno koszyka.
 */
async function otworzProdukt(page: Page, url: string) {
  await page.goto(url);
  const id = await page.locator('#add-to-cart-or-refresh input[name="id_product"]').inputValue();
  const nazwa = (await page.locator('h1').first().innerText()).trim();
  const przycisk = page.locator('button.add-to-cart');
  const aktywny = (await przycisk.count()) > 0 && (await przycisk.isEnabled());
  const stanAttr = await page.locator('.product-quantities [data-stock]').first()
    .getAttribute('data-stock', { timeout: 1_000 }).catch(() => null);
  const stan = stanAttr === null ? null : Number(stanAttr);
  return { id, nazwa, aktywny, stan };
}

function wystarczy(info: { aktywny: boolean; stan: number | null }, ilosc: number): boolean {
  return info.aktywny && (info.stan === null || info.stan >= ilosc);
}

/** Dodaje produkt z otwartej strony produktu w podanej ilości. */
async function dodajZeStrony(page: Page, p: Produkt) {
  const ilosc = page.locator('#quantity_wanted');
  await ilosc.fill(String(p.ilosc));
  await expect(ilosc).toHaveValue(String(p.ilosc));
  await page.locator('button.add-to-cart').click();

  // Okno potwierdzenia = sklep przyjął produkt do koszyka.
  await expect(page.locator('#blockcart-modal'), `Nie dodano do koszyka: ${p.nazwa}`)
    .toBeVisible({ timeout: 15_000 });
}

/**
 * Z kategorii wybiera produkty dostępne w wymaganych ilościach i dodaje je do koszyka.
 * Pomija produkty już wybrane, niedostępne lub ze zbyt małym stanem.
 */
async function dodajZKategorii(page: Page, kategoria: string, ilosci: number[], zajete: Set<string>): Promise<Produkt[]> {
  let strona: string | null = await adresKategorii(page, kategoria);
  const dodane: Produkt[] = [];

  while (strona && dodane.length < ilosci.length) {
    await page.goto(strona);
    const kandydaci = await linkiProduktow(page);
    expect(kandydaci.length, `Kategoria "${kategoria}" nie ma produktów`).toBeGreaterThan(0);
    const nastepna = await page.locator('.pagination a.next').first()
      .getAttribute('href', { timeout: 1_000 }).catch(() => null);

    for (const url of kandydaci) {
      if (dodane.length === ilosci.length) break;
      const ilosc = ilosci[dodane.length];
      const info = await otworzProdukt(page, url);
      if (zajete.has(info.id) || !wystarczy(info, ilosc)) continue;

      const p: Produkt = { id: info.id, nazwa: info.nazwa, url, ilosc };
      await dodajZeStrony(page, p);
      zajete.add(p.id);
      dodane.push(p);
      console.log(`[${kategoria}] ${p.nazwa} (id ${p.id}) x ${p.ilosc}, stan: ${info.stan ?? 'nie pokazano'}`);
    }
    strona = nastepna ? new URL(nastepna, page.url()).toString() : null;
  }

  expect(dodane.length, `Za mało dostępnych produktów w "${kategoria}"`).toBe(ilosci.length);
  return dodane;
}

/** Wiersz koszyka danego produktu, rozpoznawany po ID z przycisku usuwania. */
function wierszKoszyka(page: Page, p: Produkt): Locator {
  return page.locator('.cart-item').filter({
    has: page.locator(`a.remove-from-cart[data-id-product="${p.id}"], a.remove-from-cart[href*="id_product=${p.id}&"]`),
  });
}

async function sprawdzKoszyk(page: Page, oczekiwane: Produkt[]) {
  await page.goto(KOSZYK);
  await expect(page.locator('.cart-item')).toHaveCount(oczekiwane.length);
  for (const p of oczekiwane) {
    const wiersz = wierszKoszyka(page, p);
    await expect(wiersz, `Brak w koszyku: ${p.nazwa}`).toHaveCount(1);
    await expect(wiersz.locator('input.js-cart-line-product-quantity'), `Zła ilość: ${p.nazwa}`)
      .toHaveValue(String(p.ilosc));
  }
}

async function wyszukaj(page: Page, fraza: string): Promise<string[]> {
  await page.goto('/');
  const pole = page.locator('#search_widget input[name="s"], input.ui-autocomplete-input').first();
  await pole.fill(fraza);
  await pole.press('Enter');
  await expect(page.locator('.product-miniature').first(), `Brak wyników dla "${fraza}"`).toBeVisible();
  return linkiProduktow(page);
}

async function usunProdukty(page: Page, doUsuniecia: Produkt[]) {
  await page.goto(KOSZYK);
  const wiersze = page.locator('.cart-item');
  let liczba = await wiersze.count();

  for (const p of doUsuniecia) {
    const wiersz = wierszKoszyka(page, p);
    await expect(wiersz, `Brak w koszyku przed usunięciem: ${p.nazwa}`).toHaveCount(1);
    await wiersz.locator('a.remove-from-cart').click();

    // Koszyk odświeża się AJAX-em, czekamy na efekt, nie na stały czas.
    liczba -= 1;
    await expect(wiersz, `Nie usunięto: ${p.nazwa}`).toHaveCount(0, { timeout: 15_000 });
    await expect(wiersze).toHaveCount(liczba, { timeout: 15_000 });
  }
}

// ---------- scenariusz ----------

test('koszyk: 10 produktów z 2 kategorii, wyszukiwanie + losowanie, usunięcie 3', async ({ page }) => {
  // Kilkadziesiąt otwarć stron; README wymaga zakończenia w 5 minut.
  test.setTimeout(300_000);
  const zajete = new Set<string>();

  // 1. 10 różnych produktów z dwóch kategorii, w różnych ilościach
  const produkty: Produkt[] = [];
  for (const k of KATEGORIE) {
    produkty.push(...(await dodajZKategorii(page, k.nazwa, k.ilosci, zajete)));
  }
  expect(produkty).toHaveLength(10);
  expect(zajete.size, 'Produkty muszą być różne').toBe(10);
  await sprawdzKoszyk(page, produkty);

  // 2. Wyszukanie po nazwie
  const wyniki = await wyszukaj(page, FRAZA);
  expect(wyniki.length).toBeGreaterThan(0);

  // 3. Losowanie dostępnego produktu, którego nie ma w koszyku
  const kolejnosc = [...wyniki].sort(() => Math.random() - 0.5);
  let wylosowany: Produkt | undefined;
  for (const url of kolejnosc) {
    const info = await otworzProdukt(page, url);
    if (!zajete.has(info.id) && wystarczy(info, 1)) {
      wylosowany = { id: info.id, nazwa: info.nazwa, url, ilosc: 1 };
      break;
    }
  }
  expect(wylosowany, `Brak dostępnego produktu do wylosowania dla "${FRAZA}"`).toBeDefined();
  console.log(`Wylosowano: ${wylosowany!.nazwa} (id ${wylosowany!.id})`);

  await dodajZeStrony(page, wylosowany!); // strona produktu jest już otwarta
  await sprawdzKoszyk(page, [...produkty, wylosowany!]); // 11 pozycji

  // 4. Usunięcie 3 produktów (2 z pierwszej kategorii, 1 z drugiej)
  const doUsuniecia = [produkty[0], produkty[4], produkty[7]];
  await usunProdukty(page, doUsuniecia);

  // 5. Po usunięciu: 8 pozycji, ilości pozostałych bez zmian
  const pozostale = [...produkty.filter(p => !doUsuniecia.includes(p)), wylosowany!];
  await sprawdzKoszyk(page, pozostale);
  for (const p of doUsuniecia) {
    await expect(wierszKoszyka(page, p), `Nadal w koszyku: ${p.nazwa}`).toHaveCount(0);
  }
});
