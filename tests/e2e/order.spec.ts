import { test, expect, Page, Locator } from '@playwright/test';
import { scenariuszKoszyka, sprawdzKoszyk, wierszKoszyka, KOSZYK } from './helpers/koszyk';
import type { Produkt } from './helpers/koszyk';
import { normalizujId, roznicePozycji } from './helpers/pozycje';
import type { Pozycja } from './helpers/pozycje';
import { pozycjeZamowieniaWPanelu } from './helpers/panel';

/**
 * W4.3: zakup zawartości koszyka przez nowego klienta.
 *
 *  1. Operacje na koszyku z W4.2 (scenariuszKoszyka), w koszyku zostaje 8 pozycji.
 *  2. Rejestracja nowego konta z unikalnym adresem e-mail.
 *  3. Uzupełnienie adresu.
 *  4. Wybór jednego z dwóch nowych przewoźników (InPost, DPD).
 *  5. Płatność przy odbiorze, akceptacja regulaminu, złożenie zamówienia.
 *  6. Potwierdzenie: przewoźnik, płatność, liczba pozycji i sztuk zgodna z koszykiem.
 *  7. Pozycje zamówienia w panelu: ID produktu, ID wariantu i ilość zgodne z koszykiem.
 *  8. Zamówienie widoczne w historii klienta ze statusem płatności przy odbiorze.
 *
 * Produkty w zamówieniu są dopasowywane po ID produktu i ID wariantu zapisanych z koszyka,
 * a nie po nazwie: nazwa nie odróżnia wariantów (np. białego i grafitowego zatrzasku).
 * Sklep pokazuje te ID klientowi tylko w koszyku, a w zamówieniu dopiero w panelu,
 * dlatego krok 7 wymaga ADMIN_EMAIL i ADMIN_PASSWORD (zmienne albo .env, patrz helpers/panel.ts).
 */

// Nowi przewoźnicy skonfigurowani w deploy/init.
const NOWI_PRZEWOZNICY = ['InPost', 'DPD'];

// Przewoźnik użyty w zamówieniu, można nadpisać zmienną środowiskową PRZEWOZNIK.
const PRZEWOZNIK = process.env.PRZEWOZNIK ?? 'InPost';

// Moduł i nazwa płatności przy odbiorze (ps_cashondelivery).
const MODUL_PLATNOSCI = 'ps_cashondelivery';
const PLATNOSC_PRZY_ODBIORZE = /przy odbiorze|pobrani|cash on delivery/i;

// Dane nowego klienta. Imię, nazwisko i miasto nie mogą zawierać cyfr (walidacja PrestaShop).
const KLIENT = {
  imie: 'Jan',
  nazwisko: 'Testowy',
  ulica: 'ul. Testowa 1',
  kodPocztowy: '80-233',
  miasto: 'Gdańsk',
  telefon: '500600700',
};


// Polski klient: PrestaShop dobiera kraj adresu na podstawie języka przeglądarki,
// a domyślny język Playwright (en-US) daje w formularzu Stany Zjednoczone.
test.use({ locale: 'pl-PL' });


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


/**
 * Zapisuje z koszyka ID produktu i ID wariantu każdej pozycji (atrybuty przycisku usuwania).
 * Produkt bez wariantów ma ID wariantu 0. Tymi ID test dopasowuje później pozycje zamówienia.
 */
async function pozycjeKoszyka(page: Page, produkty: Produkt[]): Promise<Pozycja[]> {
  // Zwykle koszyk jest już otwarty przez sprawdzKoszyk, wtedy nie przeładowujemy strony.
  if ((await page.locator('body#cart').count()) === 0) await page.goto(KOSZYK);
  await expect(page.locator('.cart-item'), 'Inna liczba pozycji w koszyku').toHaveCount(produkty.length);

  const pozycje: Pozycja[] = [];

  for (const p of produkty) {
    const wiersz = wierszKoszyka(page, p);
    await expect(wiersz, `Brak w koszyku: ${p.nazwa}`).toHaveCount(1);

    const usun = wiersz.locator('a.remove-from-cart');
    const href = (await usun.getAttribute('href')) ?? '';
    const zHref = (nazwa: string) => new URL(href, page.url()).searchParams.get(nazwa);

    // Pusty atrybut ('') też kieruje do adresu usuwania, który zawsze ma id_product i id_product_attribute.
    const idProduktu = normalizujId((await usun.getAttribute('data-id-product')) || zHref('id_product'));
    const idWariantu = normalizujId((await usun.getAttribute('data-id-product-attribute')) || zHref('id_product_attribute'));

    expect(idProduktu, `Wiersz koszyka nie należy do produktu ${p.nazwa}`).toBe(normalizujId(p.id));
    expect(idWariantu, `Brak ID wariantu w koszyku: ${p.nazwa}`).not.toBe('');

    // Wariant w koszyku musi być tym, który test dodał ze strony produktu.
    expect(p.idWariantu, `Nie odczytano ID wariantu ze strony produktu: ${p.nazwa}`).toBeTruthy();
    expect(idWariantu, `W koszyku jest inny wariant niż dodany ze strony produktu: ${p.nazwa}`).toBe(p.idWariantu);

    // Ilość z bieżącego stanu wiersza koszyka, nie z danych oczekiwanych.
    const ilosc = Number(await wiersz.locator('input.js-cart-line-product-quantity').inputValue());
    expect(ilosc, `Zła ilość w koszyku: ${p.nazwa}`).toBe(p.ilosc);

    pozycje.push({ idProduktu, idWariantu, ilosc, nazwa: p.nazwa });
  }

  return pozycje;
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
  await expect(kraj, 'Brak wyboru kraju w formularzu adresu').toHaveCount(1);

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

  // Pola wymagane muszą być w formularzu; telefon zależy od konfiguracji kraju.
  const wymagane = ['firstname', 'lastname', 'address1', 'postcode', 'city'];

  for (const [nazwa, wartosc] of pola) {
    const pole = krok.locator(`input[name="${nazwa}"]`);
    if (wymagane.includes(nazwa)) await expect(pole, `Brak wymaganego pola adresu: ${nazwa}`).toHaveCount(1);

    await wypelnijJesliJest(pole, wartosc);
  }

  // Ponowna kontrola tuż przed zapisem: formularz nie został przeładowany i nie zgubił danych.
  await expect(krok.locator('select[name="id_country"] option:checked')).toHaveText(/Polska/i);
  for (const [nazwa, wartosc] of pola) {
    const pole = krok.locator(`input[name="${nazwa}"]`);
    if ((await pole.count()) > 0) await expect(pole.first(), `Puste pole adresu: ${nazwa}`).toHaveValue(wartosc);
  }

  await krok.locator('button[name="confirm-addresses"]').click();

  // Czekamy na wynik zapisu: zaliczony krok albo błędy formularza, żeby test pokazał ich treść.
  // Tylko widoczne błędy: szablon classic zawsze ma w tym kroku ukryty .alert-danger.js-address-error.
  const bledy = krok.locator('.help-block li, .alert-danger').filter({ visible: true });
  await expect(page.locator('#checkout-addresses-step.-complete').or(bledy).first(), 'Brak odpowiedzi po zapisie adresu')
    .toBeAttached({ timeout: 15_000 });

  const tresc = (await bledy.allTextContents()).map(t => t.replace(/\s+/g, ' ').trim()).filter(Boolean);
  expect(tresc, 'Formularz adresu zgłosił błąd').toEqual([]);

  await expect(krok, 'Adres nie został zapisany').toHaveClass(/-complete/, { timeout: 15_000 });
  await oczekujKroku(page, 'checkout-delivery-step', 'dostawa');
}


async function wybierzPrzewoznika(page: Page, nazwa: string) {
  const krok = page.locator('#checkout-delivery-step');
  const opcje = krok.locator('.delivery-option');

  // Obaj nowi przewoźnicy muszą być dostępni dla tego koszyka.
  for (const przewoznik of NOWI_PRZEWOZNICY) {
    const opcja = opcje.filter({ has: page.locator('.carrier-name', { hasText: new RegExp(`^\\s*${przewoznik}\\s*$`) }) });
    await expect(opcja, `Przewoźnik niedostępny: ${przewoznik}`).toHaveCount(1);
  }

  const wybrana = opcje.filter({ has: page.locator('.carrier-name', { hasText: new RegExp(`^\\s*${nazwa}\\s*$`) }) });
  await expect(wybrana, `Brak opcji dostawy dla przewoźnika "${nazwa}"`).toHaveCount(1);

  const radio = wybrana.locator('input[type="radio"]');

  // Zmiana przewoźnika zapisuje się AJAX-em (action=selectDeliveryOption), a motyw classic po zapisie
  // przeładowuje stronę zamówienia (js-cart-payment-step-refresh). Klik „Dalej” w trakcie tego
  // przeładowania mógłby zostać anulowany, więc czekamy na zapis i na nowe załadowanie strony.
  if (!(await radio.isChecked())) {
    const odswiezy = (await page.locator('.js-cart-payment-step-refresh').count()) > 0;
    const zapis = page.waitForResponse(
      odp => odp.request().method() === 'POST' && odp.url().includes('action=selectDeliveryOption'),
      { timeout: 15_000 },
    );
    const nawigacja = odswiezy
      ? page.waitForEvent('framenavigated', { predicate: ramka => ramka === page.mainFrame(), timeout: 15_000 })
      : null;

    await radio.check();

    expect((await zapis).ok(), `Sklep nie zapisał przewoźnika ${nazwa}`).toBe(true);
    if (nawigacja) {
      await nawigacja;
      await page.waitForLoadState('load');
      await oczekujKroku(page, 'checkout-delivery-step', 'dostawa (po zapisie przewoźnika)');
    }
  }

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
  expect(idOpcji, 'Opcja płatności nie ma id').toBeTruthy();
  await expect(krok.locator(`label[for="${idOpcji}"]`), 'Etykieta opcji nie wskazuje płatności przy odbiorze')
    .toContainText(PLATNOSC_PRZY_ODBIORZE);

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


/**
 * Sprawdza stronę potwierdzenia i zwraca ID oraz numer (referencję) zamówienia.
 * Potwierdzenie nie zawiera ID produktów ani wariantów, więc tutaj sprawdzamy tylko
 * liczbę pozycji i sztuk. Dopasowanie pozycji po ID wykonuje krok w panelu.
 */
async function sprawdzPotwierdzenie(page: Page, przewoznik: string, email: string, oczekiwane: Pozycja[]) {
  const szczegoly = page.locator('#order-details');

  const numerTekst = (await szczegoly.locator('#order-reference-value').textContent()) ?? '';
  const numer = numerTekst.match(/[A-Z]{9}/)?.[0];
  expect(numer, `Brak numeru zamówienia w: "${numerTekst}"`).toBeTruthy();

  // Adres potwierdzenia zawiera id_order (z przyjaznymi adresami i bez nich).
  const id = normalizujId(new URL(page.url()).searchParams.get('id_order'));
  expect(id, `Brak id_order w adresie potwierdzenia: ${page.url()}`).not.toBe('');

  // Zamówienie złożył nowy klient: potwierdzenie wysłano na jego adres e-mail.
  await expect(page.locator('#content-hook_order_confirmation'), `Potwierdzenie nie dotyczy konta ${email}`)
    .toContainText(email);

  // Szablon classic: li[0] numer, li[1] płatność, li[2] przewoźnik i jego opis (<em>).
  // Sprawdzamy każdy wiersz osobno, żeby opis przewoźnika nie zaliczył sprawdzenia płatności.
  const wiersze = szczegoly.locator('ul > li');
  await expect(wiersze.nth(1), 'Zła metoda płatności w zamówieniu').toContainText(PLATNOSC_PRZY_ODBIORZE);

  const dostawa = wiersze.nth(2);
  await expect(dostawa, 'Brak sposobu dostawy na potwierdzeniu').toBeAttached();
  const em = dostawa.locator('em');
  const opisDostawy = (await em.count()) > 0 ? ((await em.first().textContent()) ?? '') : '';
  const nazwaDostawy = ((await dostawa.textContent()) ?? '').replace(opisDostawy, '').replace(/\s+/g, ' ').trim();
  expect(nazwaDostawy, 'Zły przewoźnik w zamówieniu').toMatch(new RegExp(`:\\s*${przewoznik}$`));

  const linie = page.locator('#order-items .order-line');
  await expect(linie, 'Inna liczba pozycji niż w koszyku').toHaveCount(oczekiwane.length);

  // Kolumny w motywie classic: cena jednostkowa, ilość, suma. Druga kolumna to ilość.
  // Porównujemy zbiór ilości (posortowany), bo potwierdzenie nie ma ID, po których dałoby się dopasować linie.
  const komorki = linie.locator('.qty .row > div:nth-child(2)');
  await expect(komorki, 'Nie odczytano ilości każdej pozycji').toHaveCount(oczekiwane.length);

  const ilosci = (await komorki.allTextContents()).map(t => t.trim());
  expect(ilosci.filter(t => !/^\d+$/.test(t)), 'Nieliczbowa ilość na potwierdzeniu').toEqual([]);

  const rosnaco = (lista: number[]) => [...lista].sort((a, b) => a - b);
  expect(rosnaco(ilosci.map(Number)), 'Ilości pozycji na potwierdzeniu niezgodne z koszykiem')
    .toEqual(rosnaco(oczekiwane.map(p => p.ilosc)));

  return { id, numer: numer! };
}


/**
 * Pozycje zamówienia z panelu muszą odpowiadać koszykowi po ID produktu i ID wariantu,
 * a dopasowana pozycja musi mieć tę samą ilość. Inny wariant tego samego produktu
 * przy tej samej ilości jest błędem (brak oczekiwanej pozycji + pozycja nieoczekiwana).
 */
async function sprawdzPozycjeZamowienia(page: Page, idZamowienia: string, numer: string, oczekiwane: Pozycja[]) {
  const panel = await page.context().newPage();

  try {
    const zamowione = await pozycjeZamowieniaWPanelu(panel, idZamowienia, numer);

    expect(roznicePozycji(oczekiwane, zamowione), `Zamówienie ${numer} niezgodne z koszykiem`).toEqual([]);
  } catch (blad) {
    // Zrzut z panelu przed zamknięciem karty (screenshot: only-on-failure obejmuje tylko otwarte strony).
    const zrzut = await panel.screenshot({ fullPage: true }).catch(() => null);
    if (zrzut) await test.info().attach('panel-zamowienia', { body: zrzut, contentType: 'image/png' });
    throw blad;
  } finally {
    await panel.close();
  }
}


async function sprawdzHistorie(page: Page, numer: string) {
  await page.goto('/index.php?controller=history');

  const wiersz = page.locator('table tbody tr').filter({ hasText: numer });

  await expect(wiersz, `Brak zamówienia ${numer} w historii klienta`).toHaveCount(1);

  // Szablon classic: th = numer, td[0] data, td[1] suma, td[2] płatność, td[3] status (span.label-pill).
  // Status sprawdzamy w jego własnej komórce, bo kolumna płatności też zawiera „przy odbiorze”.
  await expect(wiersz.locator('td').nth(2), 'Zła płatność w historii').toContainText(PLATNOSC_PRZY_ODBIORZE);
  await expect(wiersz.locator('.label-pill'), 'Zły status zamówienia (oczekiwano płatności przy odbiorze)')
    .toHaveText(PLATNOSC_PRZY_ODBIORZE);
}


// ---------- scenariusz ----------


test('zamówienie: nowy klient, przewoźnik, płatność przy odbiorze', async ({ page }) => {
  // Scenariusz koszyka + zamówienie; README wymaga zakończenia w 5 minut.
  test.setTimeout(300_000);

  // PRZEWOZNIK musi być jednym z dwóch nowych przewoźników; literówka kończy test od razu z czytelnym komunikatem.
  expect(NOWI_PRZEWOZNICY, `PRZEWOZNIK="${PRZEWOZNIK}" nie jest nowym przewoźnikiem`).toContain(PRZEWOZNIK);

  const email = unikalnyEmail();
  const haslo = noweHaslo();

  // 1. Operacje na koszyku (W4.2), w koszyku zostaje 8 pozycji
  const koszyk = await scenariuszKoszyka(page);

  // 2. Rejestracja nowego konta, koszyk zostaje zachowany.
  //    Z koszyka zapisujemy ID produktu i ID wariantu każdej pozycji (stan tuż przed zamówieniem).
  let pozycje: Pozycja[] = [];
  await test.step(`Rejestracja konta ${email}`, async () => {
    await zarejestruj(page, email, haslo);
    console.log(`Zarejestrowano: ${email}`);

    await sprawdzKoszyk(page, koszyk);
    pozycje = await pozycjeKoszyka(page, koszyk);
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
  let idZamowienia = '';
  await test.step('Sprawdzenie potwierdzenia zamówienia', async () => {
    ({ id: idZamowienia, numer } = await sprawdzPotwierdzenie(page, PRZEWOZNIK, email, pozycje));
    console.log(`Zamówienie: ${numer} (id ${idZamowienia}), przewoźnik: ${PRZEWOZNIK}, płatność przy odbiorze`);
  });

  // 7. Produkty, warianty i ilości zamówienia zgodne z koszykiem (po ID)
  await test.step('Pozycje zamówienia zgodne z koszykiem (ID produktu i wariantu)', async () => {
    await sprawdzPozycjeZamowienia(page, idZamowienia, numer, pozycje);
  });

  // 8. Zamówienie w historii klienta
  await test.step('Zamówienie w historii klienta', async () => {
    await sprawdzHistorie(page, numer);
  });
});
