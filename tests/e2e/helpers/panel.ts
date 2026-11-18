import { test, expect, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { normalizujId } from './pozycje';
import type { Pozycja } from './pozycje';

/**
 * Operacje w panelu administracyjnym PrestaShop 8 potrzebne testom E2E.
 *
 * Strony klienta (potwierdzenie, historia, szczegóły zamówienia) nie pokazują ID wariantu
 * zamówionego produktu, tylko jego nazwę. ID produktu i wariantu oraz ilość każdej pozycji
 * zamówienia są dostępne na stronie zamówienia w panelu (atrybuty przycisku edycji pozycji).
 *
 * Dane logowania: zmienne ADMIN_EMAIL i ADMIN_PASSWORD. Jeżeli nie są ustawione,
 * test czyta je z lokalnego pliku .env w głównym katalogu repozytorium (tego samego,
 * którego używa Docker Compose). Katalog panelu: ADMIN_PATH, domyślnie admin-dev.
 */


/** Odczytuje zmienną z lokalnego .env repozytorium (KLUCZ=wartość), bez zewnętrznych bibliotek. */
function zPlikuEnv(klucz: string): string | undefined {
  const plik = path.resolve(__dirname, '..', '..', '..', '.env');
  if (!fs.existsSync(plik)) return undefined;

  for (const linia of fs.readFileSync(plik, 'utf8').split(/\r?\n/)) {
    const m = linia.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (m && m[1] === klucz) return m[2].replace(/^(['"])(.*)\1$/, '$2');
  }

  return undefined;
}


function daneAdmina() {
  const email = process.env.ADMIN_EMAIL ?? zPlikuEnv('ADMIN_EMAIL');
  const haslo = process.env.ADMIN_PASSWORD ?? zPlikuEnv('ADMIN_PASSWORD');
  const katalog = (process.env.ADMIN_PATH ?? 'admin-dev').replace(/^\/+|\/+$/g, '');

  expect(email, 'Brak ADMIN_EMAIL (zmienna środowiskowa albo .env w głównym katalogu repozytorium)').toBeTruthy();
  expect(haslo, 'Brak ADMIN_PASSWORD (zmienna środowiskowa albo .env w głównym katalogu repozytorium)').toBeTruthy();

  return { email: email!, haslo: haslo!, katalog };
}


/**
 * Loguje administratora i zwraca token Symfony (_token) potrzebny do otwierania stron
 * nowego panelu (np. /sell/orders/...). Token jest odczytywany z linku „Zamówienia” w menu.
 */
async function zalogujAdmina(page: Page): Promise<{ katalog: string; token: string }> {
  const { email, haslo, katalog } = daneAdmina();

  await page.goto(`/${katalog}/index.php?controller=AdminLogin`);

  // Formularza nie ma np. gdy na serwerze został katalog /install; wtedy komunikat pokazuje treść strony.
  const formularz = page.locator('#login_form');
  const widoczny = await formularz.waitFor({ state: 'visible', timeout: 15_000 }).then(() => true, () => false);
  const strona = widoczny ? '' : (await page.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').slice(0, 300);
  expect(widoczny, `Brak formularza logowania panelu /${katalog}/. Strona: ${strona}`).toBe(true);

  await formularz.locator('#email').fill(email);
  await formularz.locator('#passwd').fill(haslo);
  await formularz.locator('#submit_login').click();

  // Po zalogowaniu panel ma w menu link do zamówień z tokenem; przy błędzie formularz pokazuje #error.
  // Filtr [href*="_token="] jest potrzebny, bo panel ma też ukryte linki do zamówień bez tokenu
  // (np. w powiadomieniach), a pierwszy taki link w DOM nie nadaje się do odczytu tokenu.
  const zamowienia = page.locator('a[href*="/sell/orders"][href*="_token="]').first();
  const blad = page.locator('#error:visible');

  await expect(zamowienia.or(blad).first(), 'Brak odpowiedzi po logowaniu do panelu').toBeAttached({ timeout: 30_000 });
  expect(await blad.count(), `Logowanie do panelu nie powiodło się: ${await blad.allTextContents()}`).toBe(0);

  const href = await zamowienia.getAttribute('href');
  const token = new URL(href!, page.url()).searchParams.get('_token');
  expect(token, 'Brak tokenu panelu w linku „Zamówienia”').toBeTruthy();

  return { katalog, token: token! };
}


/**
 * Odczytuje pozycje zamówienia z panelu: ID produktu, ID wariantu (combination) i ilość.
 * Wiersze produktów poza pierwszą stroną paginacji są w DOM jako ukryte, więc czytamy wszystkie.
 * Przycisk edycji pozycji jest w panelu tylko dla zamówień niedostarczonych, czyli także dla
 * świeżego zamówienia z płatnością przy odbiorze.
 */
export async function pozycjeZamowieniaWPanelu(page: Page, idZamowienia: string, numer: string): Promise<Pozycja[]> {
  return test.step(`Panel: pozycje zamówienia ${numer}`, async () => {
    const { katalog, token } = await zalogujAdmina(page);

    await page.goto(`/${katalog}/index.php/sell/orders/${idZamowienia}/view?_token=${token}`);

    // Strona panelu dotyczy właściwego zamówienia: zawiera jego numer (referencję).
    await expect(page.locator('body'), `Panel nie pokazuje zamówienia ${numer}`).toContainText(numer);

    const licznik = page.locator('#orderProductsPanelCount');
    await expect(licznik, 'Brak liczby produktów zamówienia w panelu').toHaveText(/^\s*\d+\s*$/);
    const liczba = Number((await licznik.textContent())!.trim());

    // Liczymy wszystkie wiersze produktów, bez filtra po przycisku, żeby brak przycisku nie ukrył wiersza.
    const wiersze = page.locator('#orderProductsTable tr.cellProduct');
    await expect(wiersze, 'Panel nie pokazuje wszystkich pozycji zamówienia').toHaveCount(liczba);

    const pozycje: Pozycja[] = [];

    for (const wiersz of await wiersze.all()) {
      const nazwaWiersza = wiersz.locator('.cellProductName .productName');
      const nazwa = (((await nazwaWiersza.count()) > 0
        ? await nazwaWiersza.first().textContent()
        : await wiersz.locator('.cellProductName').textContent()) ?? '').replace(/\s+/g, ' ').trim();

      // Przycisk edycji (z ID produktu, wariantu i ilością) panel pokazuje tylko dla zamówień niedostarczonych.
      const przycisk = wiersz.locator('.js-order-product-edit-btn');
      await expect(przycisk, `Brak przycisku edycji pozycji w panelu (zamówienie oznaczone jako dostarczone?): ${nazwa}`)
        .toHaveCount(1);

      const atrybut = async (nazwaAtrybutu: string) => {
        const wartosc = normalizujId(await przycisk.getAttribute(nazwaAtrybutu));
        expect(wartosc, `Brak ${nazwaAtrybutu} pozycji w panelu: ${nazwa}`).not.toBe('');
        return wartosc;
      };

      pozycje.push({
        idProduktu: await atrybut('data-product-id'),
        idWariantu: await atrybut('data-combination-id'),
        ilosc: Number(await atrybut('data-product-quantity')),
        nazwa,
      });
    }

    return pozycje;
  });
}
