import { test, expect, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

/**
 * Operacje w panelu administracyjnym PrestaShop 8 potrzebne testom E2E.
 *
 * W sklepie płatność przy odbiorze zaczyna się bez faktury. Fakturę generuje
 * dopiero zmiana statusu na „Płatność zaakceptowana” przez administratora
 * (docs/CONFIGURATION.md, deploy/init/lib/orders.php). Test robi to samo co
 * administrator: loguje się do panelu i zmienia status zamówienia.
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

  const formularz = page.locator('#login_form');
  await expect(formularz, `Brak formularza logowania panelu /${katalog}/`).toBeVisible();

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
 * Zmienia status zamówienia w panelu, tak jak administrator potwierdzający otrzymanie pieniędzy.
 * Sprawdza komunikat sukcesu i to, że po przeładowaniu bieżącym statusem jest nowy status.
 */
export async function zmienStatusWPanelu(page: Page, idZamowienia: string, numer: string, status: string) {
  await test.step(`Panel: zmiana statusu zamówienia ${numer} na „${status}”`, async () => {
    const { katalog, token } = await zalogujAdmina(page);

    await page.goto(`/${katalog}/index.php/sell/orders/${idZamowienia}/view?_token=${token}`);

    // Strona panelu dotyczy właściwego zamówienia: zawiera jego numer (referencję).
    await expect(page.locator('body'), `Panel nie pokazuje zamówienia ${numer}`).toContainText(numer);

    // Formularz zmiany statusu (nagłówek zamówienia lub zakładka „Status”).
    const forma = page.locator('form')
      .filter({ has: page.locator('select[name$="[new_order_status_id]"]:visible') })
      .first();
    await expect(forma, 'Brak formularza zmiany statusu w panelu').toBeVisible();

    const lista = forma.locator('select[name$="[new_order_status_id]"]');
    await lista.selectOption({ label: status });
    await expect(lista.locator('option:checked'), 'Nie wybrano nowego statusu').toHaveText(status);

    // Przycisk „Aktualizacja statusu” nie ma atrybutu type, więc szukamy go po roli i nazwie.
    const zapisz = forma.getByRole('button', { name: /Aktualiz/i });
    await expect(zapisz, 'Brak przycisku „Aktualizacja statusu”').toHaveCount(1);
    await expect(zapisz, 'Przycisk zmiany statusu jest nieaktywny').toBeEnabled();
    await zapisz.click();

    // Komunikat sukcesu po przeładowaniu. Samo .alert-success nie wystarcza: panel ma zawsze
    // pusty, ukryty #ajax_confirmation z tą klasą, a .first() trafiało właśnie w niego.
    await expect(
      page.locator('.alert-success:visible').filter({ hasText: /Pomyślne uaktualnienie|zakończon\S* sukcesem/i }),
      'Panel nie potwierdził zmiany statusu',
    ).toHaveCount(1, { timeout: 30_000 });

    // Po przeładowaniu strona zamówienia pokazuje nowy status jako bieżący.
    await expect(
      page.locator('select[name$="[new_order_status_id]"]').first().locator('option:checked'),
      'Status w panelu nie został zmieniony',
    ).toHaveText(status);
  });
}
