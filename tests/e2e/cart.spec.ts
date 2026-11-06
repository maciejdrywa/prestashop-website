import { test, expect, Page, Locator } from '@playwright/test';

type Produkt = { nazwa: string; url: string; ilosc: number };

// Ścieżki zamiast pełnych adresów, żeby zmiana BASE_URL działała bez edycji kodu.
// Kategoria A: Akcesoria meblowe
const katA: Produkt[] = [
  { nazwa: 'Śruby do łączenia blatów kompaktowych HLP', url: '/akcesoria-meblowe/570-sruby-do-laczenia-blatow-kompaktowych-hlp-kpl-3szt.html', ilosc: 5 },
  { nazwa: 'Zatrzask meblowy plastikowy', url: '/akcesoria-meblowe/530-zatrzask-meblowy-plastikowy.html', ilosc: 5 },
  { nazwa: 'Spowalniacz AIRTICBOX hamulec do SZUFLADY KULKOWEJ', url: '/akcesoria-meblowe/562-airticbox-hamulec-do-szuflady-kulkowej.html', ilosc: 3 },
  { nazwa: 'Spowalniacz hamulec DO SZUFLADY ROLKOWEJ', url: '/akcesoria-meblowe/563-airticbox-hamulec-do-szuflady-rolkowej.html', ilosc: 2 },
  { nazwa: 'GAŁKA MEBLOWA KWADRATOWA', url: '/uchwyty-meblowe/638-galka-meblowa-kwadratowa.html', ilosc: 4 },
];
// Kategoria B: Klamki z szyldem kwadratowym
const katB: Produkt[] = [
  { nazwa: 'Klamka BRAVO z szyldem kwadratowym', url: '/klamki-z-szyldem-kwartatowym/125-680-klamka-z-szyldem-kwadratowym-bravo.html#/24-wybierz_kolor-nikiel_chrom', ilosc: 7 },
  { nazwa: 'Klamka FOCUS z szyldem kwadratowym', url: '/klamki-z-szyldem-kwartatowym/126-683-klamka-focus-z-szyldem-kwadratowym.html', ilosc: 2 },
  { nazwa: 'Klamka HECTOR z szyldem kwadratowym', url: '/klamki-z-szyldem-kwartatowym/127-684-klamka-hector-z-szyldem-kwadratowym.html', ilosc: 3 },
  { nazwa: 'Klamka LOTUS z szyldem kwadratowym', url: '/klamki-z-szyldem-kwartatowym/128-687-klamka-lotus-z-szyldem-kwadratowym.html', ilosc: 1 },
  { nazwa: 'Klamka TORINO z szyldem kwadratowym', url: '/klamki-z-szyldem-kwartatowym/129-688-klamka-torino-z-szyldem-kwadratowym.html', ilosc: 4 },
];

const FRAZA = 'klamka';

/** ID produktu z adresu, np. "/klamki-.../125-680-klamka....html" -> "125". */
function idProduktu(url: string): string {
  const id = url.match(/\/(\d+)-/)?.[1];
  if (!id) throw new Error(`Nie udało się odczytać ID produktu z adresu: ${url}`);
  return id;
}

/**
 * Wiersz koszyka danego produktu. Szukamy po ID w linku usuwania
 * (…?delete=1&id_product=570&…), a nie po nazwie, bo nazwy na stronie
 * produktu i w koszyku różnią się wielkością liter i długością.
 */
function wierszKoszyka(page: Page, p: Produkt): Locator {
  return page
    .locator('.cart-item')
    .filter({ has: page.locator(`a.remove-from-cart[href*="id_product=${idProduktu(p.url)}&"]`) });
}

async function dodajProdukt(page: Page, produkt: Produkt) {
  await page.goto(produkt.url);

  const przycisk = page.locator('button.add-to-cart');
  await expect(przycisk, `Produkt niedostępny: ${produkt.nazwa}`).toBeEnabled();

  await page.locator('#quantity_wanted').fill(String(produkt.ilosc));
  await expect(page.locator('#quantity_wanted')).toHaveValue(String(produkt.ilosc));
  await przycisk.click();

  // Okno potwierdzenia = sklep przyjął produkt do koszyka.
  await expect(page.locator('#blockcart-modal')).toBeVisible();
}

async function sprawdzKoszyk(page: Page, oczekiwane: Produkt[]) {
  await page.goto('/koszyk?action=show');

  await expect(page.locator('.cart-item')).toHaveCount(oczekiwane.length);

  for (const p of oczekiwane) {
    const wiersz = wierszKoszyka(page, p);
    await expect(wiersz, `Brak w koszyku: ${p.nazwa}`).toHaveCount(1);
    await expect(wiersz.locator('input.js-cart-line-product-quantity'), `Zła ilość: ${p.nazwa}`)
      .toHaveValue(String(p.ilosc));
  }
}

async function wyszukaj(page: Page, fraza: string): Promise<Locator> {
  await page.goto('/');

  const searchBar = page.locator('input.ui-autocomplete-input');
  await searchBar.fill(fraza);
  await searchBar.press('Enter');

  const wyniki = page.locator('.product-miniature');
  await expect(wyniki.first()).toBeVisible();
  return wyniki;
}

async function usunProdukty(page: Page, doUsuniecia: Produkt[]) {
  await page.goto('/koszyk?action=show');
  const wiersze = page.locator('.cart-item');
  let liczba = await wiersze.count();

  for (const p of doUsuniecia) {
    const wiersz = wierszKoszyka(page, p);
    await expect(wiersz, `Brak w koszyku przed usunięciem: ${p.nazwa}`).toHaveCount(1);
    await wiersz.locator('a.remove-from-cart').click();

    // Koszyk odświeża się AJAX-em — czekamy na efekt, nie na stały czas.
    liczba -= 1;
    await expect(wiersz, `Nie usunięto: ${p.nazwa}`).toHaveCount(0, { timeout: 15_000 });
    await expect(wiersze).toHaveCount(liczba, { timeout: 15_000 });
  }
}

test('koszyk: 10 produktów z 2 kategorii, wyszukiwanie + losowanie, usunięcie 3', async ({ page }) => {
  // Kilkanaście przeładowań stron sklepu nie mieści się w domyślnych 30 s.
  test.setTimeout(180_000);

  const produkty = [...katA, ...katB];
  expect(produkty).toHaveLength(10);
  expect(new Set(produkty.map(p => idProduktu(p.url))).size, 'Produkty muszą być różne').toBe(10);

  // 1. Dodanie 10 produktów w różnych ilościach i sprawdzenie koszyka
  for (const p of produkty) {
    await dodajProdukt(page, p);
  }
  await sprawdzKoszyk(page, produkty);

  // 2. Wyszukanie po nazwie
  const wyniki = await wyszukaj(page, FRAZA);
  const kandydaci: string[] = [];
  for (const karta of await wyniki.all()) {
    const href = await karta.locator('.product-title a').getAttribute('href');
    if (href) kandydaci.push(new URL(href, page.url()).pathname);
  }
  expect(kandydaci.length, `Wyszukiwanie "${FRAZA}" nie zwróciło produktów`).toBeGreaterThan(0);

  // 3. Losowanie: pierwszy dostępny produkt w losowej kolejności, którego nie ma w koszyku
  const idWKoszyku = new Set(produkty.map(p => idProduktu(p.url)));
  const doLosowania = kandydaci
    .filter(url => /\/\d+-/.test(url) && !idWKoszyku.has(idProduktu(url)))
    .sort(() => Math.random() - 0.5);

  let wylosowany: Produkt | undefined;
  for (const url of doLosowania) {
    await page.goto(url);
    const przycisk = page.locator('button.add-to-cart');
    if ((await przycisk.count()) > 0 && (await przycisk.isEnabled())) {
      const nazwa = (await page.locator('h1').first().innerText()).trim();
      wylosowany = { nazwa, url, ilosc: 1 };
      break;
    }
  }
  expect(wylosowany, 'Brak dostępnego produktu do wylosowania').toBeDefined();
  console.log('Wylosowano:', wylosowany!.nazwa, wylosowany!.url);

  await dodajProdukt(page, wylosowany!);
  await sprawdzKoszyk(page, [...produkty, wylosowany!]);   // 11 pozycji

  // 4. Usunięcie 3 produktów
  const doUsuniecia = [produkty[0], produkty[4], produkty[7]];
  await usunProdukty(page, doUsuniecia);

  // 5. Koszyk po usunięciu: 8 pozycji, pozostałe ilości bez zmian
  const pozostale = [...produkty.filter(p => !doUsuniecia.includes(p)), wylosowany!];
  await sprawdzKoszyk(page, pozostale);
  for (const p of doUsuniecia) {
    await expect(wierszKoszyka(page, p), `Nadal w koszyku: ${p.nazwa}`).toHaveCount(0);
  }
});
