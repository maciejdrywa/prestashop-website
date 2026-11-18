import { test, expect, Page, Locator } from '@playwright/test';
import { scenariuszKoszyka, sprawdzKoszyk } from './koszyk';
import type { Produkt } from './koszyk';

/**
 * Wspólne kroki zakupu przez nowego klienta (W4.3), używane przez test zamówienia
 * (order.spec.ts) i test obsługi zamówienia po zakupie (invoice.spec.ts).
 *
 * scenariuszZamowienia():
 *  1. Operacje na koszyku z W4.2 (scenariuszKoszyka), w koszyku zostaje 8 pozycji.
 *  2. Rejestracja nowego konta z unikalnym adresem e-mail.
 *  3. Uzupełnienie adresu.
 *  4. Wybór jednego z dwóch nowych przewoźników (InPost, DPD).
 *  5. Płatność przy odbiorze, akceptacja regulaminu, złożenie zamówienia.
 *  6. Potwierdzenie: przewoźnik, płatność, produkty i ilości zgodne z koszykiem.
 *
 * Pliki testów muszą ustawić test.use({ locale: 'pl-PL' }), bo PrestaShop dobiera
 * kraj adresu na podstawie języka przeglądarki (domyślne en-US daje Stany Zjednoczone).
 */

// Nowi przewoźnicy skonfigurowani w deploy/init (InPost 15 zł, DPD 20 zł).
export const NOWI_PRZEWOZNICY = ['InPost', 'DPD'];

// Przewoźnik użyty w zamówieniu, można nadpisać zmienną środowiskową PRZEWOZNIK.
export const PRZEWOZNIK = process.env.PRZEWOZNIK ?? 'InPost';

// Moduł i nazwa płatności przy odbiorze (ps_cashondelivery).
const MODUL_PLATNOSCI = 'ps_cashondelivery';
export const PLATNOSC_PRZY_ODBIORZE = /przy odbiorze|pobrani|cash on delivery/i;

// Statusy z deploy/init/lib/orders.php.
export const STATUS_PRZY_ODBIORZE = 'Oczekiwanie na płatność przy odbiorze';
export const STATUS_OPLACONE = 'Płatność zaakceptowana';

// Dane nowego klienta. Nazwy pól tekstowych nie mogą zawierać cyfr.
const KLIENT = {
  imie: 'Jan',
  nazwisko: 'Testowy',
  ulica: 'ul. Testowa 1',
  kodPocztowy: '80-233',
  miasto: 'Gdańsk',
  telefon: '500600700',
};

export type Zamowienie = { numer: string; email: string; przewoznik: string; produkty: Produkt[] };


// ---------- pomocnicze ----------


/** Unikalny e-mail przy każdym uruchomieniu, więc ponowny bieg nie trafia na istniejące konto. */
function unikalnyEmail(): string {
  const losowe = Math.random().toString(36).slice(2, 8);

  return `w43.${Date.now()}.${losowe}@example.test`;
}


/** Hasło spełniające politykę haseł PrestaShop 8 (długość i siła). */
function noweHaslo(): string {
  const losowe = Math.random().toString(36).slice(2, 10);

  return `Zaq1@${losowe}#Wx9!`;
}


/** Dokładne dopasowanie tekstu (bez rozróżniania wielkości liter i z dowolnymi odstępami). */
export function dokladnie(tekst: string): RegExp {
  return new RegExp(`^\\s*${tekst.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s+')}\\s*$`, 'i');
}


/**
 * Dokładne dopasowanie nazwy produktu w zamówieniu. Samo „zawiera” myli produkty,
 * których nazwy są swoimi początkami (np. „Klamka X” i „Klamka X z szyldem”).
 * Dla kombinacji sklep dopisuje wybrany wariant w nawiasie, np.
 * „Zatrzask balkonowy pochwyt palacza - kolory (WYBIERZ KOLOR: biały)”.
 * Dopuszczamy też domyślny format PrestaShop: „Nazwa - Kolor : czarny”.
 */
function nazwaWZamowieniu(nazwa: string): RegExp {
  const wzorzec = nazwa
    .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    .replace(/\s+/g, '\\s+');

  return new RegExp(`^\\s*${wzorzec}(\\s*\\(.+\\)|\\s+-\\s.+)?\\s*$`, 's');
}


/** Wypełnia pole tylko wtedy, gdy formularz je zawiera (pola zależą od konfiguracji kraju). */
async function wypelnijJesliJest(pole: Locator, wartosc: string) {
  if ((await pole.count()) === 0) return;

  await pole.first().fill(wartosc);
  await expect(pole.first()).toHaveValue(wartosc);
}


/** Krok zamówienia (sekcja checkout) jest aktywny, czyli poprzedni został zaliczony. */
async function oczekujKroku(page: Page, idKroku: string, opis: string) {
  await expect(page.locator(`#${idKroku}`), `Nie przeszedł do kroku: ${opis}`)
    .toHaveClass(/-current/, { timeout: 15_000 });
}


// ---------- kroki zamówienia ----------


async function zarejestruj(page: Page, email: string, haslo: string) {
  await page.goto('/index.php?controller=registration');

  const formularz = page.locator('#customer-form');
  await expect(formularz, 'Brak formularza rejestracji').toBeVisible();

  // Płeć jest opcjonalna, wybieramy ją, jeśli formularz ją pokazuje.
  const plec = formularz.locator('input[name="id_gender"]').first();
  if ((await plec.count()) > 0) await plec.check();

  await formularz.locator('input[name="firstname"]').fill(KLIENT.imie);
  await formularz.locator('input[name="lastname"]').fill(KLIENT.nazwisko);
  await formularz.locator('input[name="email"]').fill(email);
  await formularz.locator('input[name="password"]').fill(haslo);

  // Wymagane zgody (regulamin, prywatność, RODO) zależą od modułów, zaznaczamy wszystkie wymagane.
  for (const zgoda of await formularz.locator('input[type="checkbox"][required]').all()) {
    await zgoda.check();
    await expect(zgoda).toBeChecked();
  }

  await formularz.locator('button[data-link-action="save-customer"]').click();

  // Czekamy na wynik wysłania: link wylogowania (sukces) albo komunikat błędu formularza.
  const wyloguj = page.locator('a.logout').first();
  const bledy = page.locator('#customer-form .help-block li, #customer-form .alert-danger, #notifications .alert-danger');

  await expect(wyloguj.or(bledy).first(), `Brak odpowiedzi formularza rejestracji dla ${email}`)
    .toBeAttached({ timeout: 15_000 });

  // Błędy sprawdzamy przed potwierdzeniem zalogowania, żeby test pokazał ich treść (np. zajęty e-mail).
  const tresc = (await bledy.allTextContents()).map(t => t.replace(/\s+/g, ' ').trim()).filter(Boolean);
  expect(tresc, `Formularz rejestracji zgłosił błąd dla ${email}`).toEqual([]);

  // Po rejestracji klient jest zalogowany: w nagłówku jest link wylogowania.
  await expect(wyloguj, `Rejestracja nie powiodła się dla ${email}`).toBeAttached();
}


/** Wybiera Polskę w formularzu adresu i czeka na jego przeładowanie, jeśli kraj był inny. */
async function ustawPolske(page: Page, krok: Locator) {
  const kraj = krok.locator('select[name="id_country"]');
  if ((await kraj.count()) === 0) return;

  const wybrany = ((await kraj.locator('option:checked').textContent()) ?? '').trim();

  if (!/Polska/i.test(wybrany)) {
    // Formularz przeładowuje się żądaniem z action=addressForm (z przyjaznymi adresami i bez nich).
    const przeladowanie = page.waitForResponse(
      odp => odp.request().method() === 'POST' && odp.url().includes('action=addressForm'),
      { timeout: 15_000 },
    );

    await kraj.selectOption({ label: 'Polska' });

    await przeladowanie;
    await expect(krok.locator('input[name="postcode"]'), 'Formularz adresu nie przeładował się').toBeVisible();
  }

  await expect(kraj.locator('option:checked'), 'Kraj adresu to nie Polska').toHaveText(/Polska/i);
}


async function uzupelnijAdres(page: Page) {
  await page.goto('/index.php?controller=order');
  await expect(page.locator('body#checkout'), 'Nie otwarto strony zamówienia').toBeAttached();

  // Klient jest zalogowany, więc pierwszym aktywnym krokiem jest adres.
  await oczekujKroku(page, 'checkout-addresses-step', 'adres');

  const krok = page.locator('#checkout-addresses-step');

  // Kraj ustawiamy przed wypełnieniem pól, bo zmiana kraju przeładowuje formularz AJAX-em.
  await ustawPolske(page, krok);

  const pola: [string, string][] = [
    ['firstname', KLIENT.imie],
    ['lastname', KLIENT.nazwisko],
    ['address1', KLIENT.ulica],
    ['postcode', KLIENT.kodPocztowy],
    ['city', KLIENT.miasto],
    ['phone', KLIENT.telefon],
  ];

  for (const [nazwa, wartosc] of pola) {
    await wypelnijJesliJest(krok.locator(`input[name="${nazwa}"]`), wartosc);
  }

  // Ponowna kontrola tuż przed zapisem: formularz nie został przeładowany i nie zgubił danych.
  await expect(krok.locator('select[name="id_country"] option:checked')).toHaveText(/Polska/i);
  for (const [nazwa, wartosc] of pola) {
    const pole = krok.locator(`input[name="${nazwa}"]`);
    if ((await pole.count()) > 0) await expect(pole.first(), `Puste pole adresu: ${nazwa}`).toHaveValue(wartosc);
  }

  await krok.locator('button[name="confirm-addresses"]').click();

  await expect(krok, 'Adres nie został zapisany').toHaveClass(/-complete/, { timeout: 15_000 });
  await oczekujKroku(page, 'checkout-delivery-step', 'dostawa');
}


async function wybierzPrzewoznika(page: Page, nazwa: string) {
  const krok = page.locator('#checkout-delivery-step');
  const opcje = krok.locator('.delivery-option');

  // Obaj nowi przewoźnicy muszą być dostępni dla tego koszyka (limit 50 kg).
  for (const przewoznik of NOWI_PRZEWOZNICY) {
    const opcja = opcje.filter({ has: page.locator('.carrier-name', { hasText: dokladnie(przewoznik) }) });
    await expect(opcja, `Przewoźnik niedostępny: ${przewoznik}`).toHaveCount(1);
  }

  const wybrana = opcje.filter({ has: page.locator('.carrier-name', { hasText: dokladnie(nazwa) }) });
  await expect(wybrana, `Brak opcji dostawy dla przewoźnika "${nazwa}"`).toHaveCount(1);

  const radio = wybrana.locator('input[type="radio"]');

  await radio.check();
  await expect(radio, `Nie zaznaczono przewoźnika ${nazwa}`).toBeChecked();

  await krok.locator('button[name="confirmDeliveryOption"]').click();

  await expect(krok, 'Dostawa nie została zatwierdzona').toHaveClass(/-complete/, { timeout: 15_000 });
  await oczekujKroku(page, 'checkout-payment-step', 'płatność');
}


async function zaplacPrzyOdbiorzeIZamow(page: Page) {
  const krok = page.locator('#checkout-payment-step');

  const radio = krok.locator(`input[name="payment-option"][data-module-name="${MODUL_PLATNOSCI}"]`);
  await expect(radio, 'Brak płatności przy odbiorze').toHaveCount(1);

  await radio.check();
  await expect(radio, 'Nie zaznaczono płatności przy odbiorze').toBeChecked();

  // Etykieta zaznaczonej opcji potwierdza, że to płatność przy odbiorze.
  const idOpcji = await radio.getAttribute('id');
  await expect(krok.locator(`label[for="${idOpcji}"]`)).toContainText(PLATNOSC_PRZY_ODBIORZE);

  // Regulamin i inne warunki wymagane do złożenia zamówienia.
  for (const warunek of await krok.locator('input[name^="conditions_to_approve"]').all()) {
    await warunek.check();
    await expect(warunek).toBeChecked();
  }

  const zamow = page.locator('#payment-confirmation button[type="submit"]');
  await expect(zamow).toBeEnabled();

  await zamow.click();

  await expect(page.locator('body#order-confirmation'), 'Nie otwarto potwierdzenia zamówienia')
    .toBeAttached({ timeout: 30_000 });
}


/** Sprawdza stronę potwierdzenia i zwraca numer (referencję) zamówienia. */
async function sprawdzPotwierdzenie(page: Page, przewoznik: string, oczekiwane: Produkt[]): Promise<string> {
  const szczegoly = page.locator('#order-details');

  const numerTekst = (await szczegoly.locator('#order-reference-value').textContent()) ?? '';
  const numer = numerTekst.match(/[A-Z]{9}/)?.[0];
  expect(numer, `Brak numeru zamówienia w: "${numerTekst}"`).toBeTruthy();

  await expect(szczegoly, 'Zły przewoźnik w zamówieniu').toContainText(przewoznik);
  await expect(szczegoly, 'Zła metoda płatności w zamówieniu').toContainText(PLATNOSC_PRZY_ODBIORZE);

  // Produkty i ilości muszą być takie same jak w koszyku.
  const linie = page.locator('#order-items .order-line');
  await expect(linie, 'Inna liczba pozycji niż w koszyku').toHaveCount(oczekiwane.length);

  for (const p of oczekiwane) {
    const linia = linie.filter({
      has: page.locator('.details > span, .details > a > span', { hasText: nazwaWZamowieniu(p.nazwa) }),
    });

    await expect(linia, `Brak w zamówieniu: ${p.nazwa}`).toHaveCount(1);
    // Kolumny w motywie classic: cena jednostkowa, ilość, suma. Druga kolumna to ilość.
    await expect(linia.locator('.qty .row > div').nth(1), `Zła ilość w zamówieniu: ${p.nazwa}`)
      .toHaveText(String(p.ilosc));
  }

  return numer!;
}


// ---------- historia zamówień klienta ----------


/**
 * Otwiera historię zamówień zalogowanego klienta i zwraca wiersz zamówienia o podanym numerze.
 * Wiersz jest rozpoznawany po numerze (referencji), więc inne zamówienia klienta nie przeszkadzają.
 */
export async function wierszHistorii(page: Page, numer: string): Promise<Locator> {
  await page.goto('/index.php?controller=history');
  await expect(page.locator('body#history'), 'Nie otwarto historii zamówień').toBeAttached();

  const wiersz = page.locator('table tbody tr').filter({ hasText: numer });
  await expect(wiersz, `Brak zamówienia ${numer} w historii klienta`).toHaveCount(1);

  return wiersz;
}


/** Status zamówienia w wierszu historii (etykieta, nie kolumna płatności). */
export function statusWHistorii(wiersz: Locator): Locator {
  return wiersz.locator('.label-pill');
}


// ---------- scenariusz zamówienia ----------


/** Pełny zakup przez nowego klienta: koszyk, rejestracja, adres, przewoźnik, płatność przy odbiorze. */
export async function scenariuszZamowienia(page: Page): Promise<Zamowienie> {
  // PRZEWOZNIK musi być jednym z dwóch nowych przewoźników; literówka kończy test od razu z czytelnym komunikatem.
  expect(NOWI_PRZEWOZNICY, `PRZEWOZNIK="${PRZEWOZNIK}" nie jest nowym przewoźnikiem`).toContain(PRZEWOZNIK);

  const email = unikalnyEmail();
  const haslo = noweHaslo();

  // 1. Operacje na koszyku (W4.2), w koszyku zostaje 8 pozycji
  const koszyk = await scenariuszKoszyka(page);

  // 2. Rejestracja nowego konta, koszyk zostaje zachowany
  await test.step(`Rejestracja konta ${email}`, async () => {
    await zarejestruj(page, email, haslo);
    console.log(`Zarejestrowano: ${email}`);

    await sprawdzKoszyk(page, koszyk);
  });

  // 3. Adres
  await test.step('Uzupełnienie adresu', async () => {
    await uzupelnijAdres(page);
  });

  // 4. Przewoźnik
  await test.step(`Wybór przewoźnika ${PRZEWOZNIK}`, async () => {
    await wybierzPrzewoznika(page, PRZEWOZNIK);
  });

  // 5. Płatność przy odbiorze i złożenie zamówienia
  await test.step('Płatność przy odbiorze i złożenie zamówienia', async () => {
    await zaplacPrzyOdbiorzeIZamow(page);
  });

  // 6. Potwierdzenie zgodne z koszykiem
  let numer = '';
  await test.step('Sprawdzenie potwierdzenia zamówienia', async () => {
    numer = await sprawdzPotwierdzenie(page, PRZEWOZNIK, koszyk);
    console.log(`Zamówienie: ${numer}, przewoźnik: ${PRZEWOZNIK}, płatność przy odbiorze`);
  });

  return { numer, email, przewoznik: PRZEWOZNIK, produkty: koszyk };
}
