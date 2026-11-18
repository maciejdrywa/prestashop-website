import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import {
  scenariuszZamowienia, wierszHistorii, statusWHistorii, dokladnie,
  STATUS_PRZY_ODBIORZE, STATUS_OPLACONE,
} from './helpers/zamowienie';
import { zmienStatusWPanelu } from './helpers/panel';

/**
 * W4.4: obsługa zamówienia po zakupie.
 *
 *  1. Zakup przez nowego klienta (scenariuszZamowienia, W4.3): płatność przy odbiorze.
 *  2. Historia zamówień klienta: zamówienie odszukane po numerze, status
 *     „Oczekiwanie na płatność przy odbiorze”, faktury jeszcze nie ma.
 *  3. Panel: administrator potwierdza otrzymanie pieniędzy, status „Płatność zaakceptowana”.
 *     Tak działa konfiguracja sklepu (deploy/init/lib/orders.php): dopiero ten status
 *     generuje fakturę VAT, więc test nie zmienia ustawień sklepu.
 *  4. Historia klienta: status „Płatność zaakceptowana” i link do faktury.
 *  5. Pobranie faktury: plik musi być niepustym dokumentem PDF. Brak linku, brak pobrania
 *     albo plik, który nie jest PDF-em, kończą test błędem.
 */

// Polski klient: PrestaShop dobiera kraj adresu na podstawie języka przeglądarki.
test.use({ locale: 'pl-PL' });

// Minimalny rozmiar faktury. Pusty PDF z TCPDF ma ok. 1 KB, faktura z tabelą pozycji wyraźnie więcej.
const MIN_ROZMIAR_PDF = 2_000;


test('po zakupie: historia, status, faktura VAT w PDF', async ({ page }, testInfo) => {
  // Koszyk + zamówienie + panel + faktura; scenariusz zakupu sam trwa ok. 2 minut.
  test.setTimeout(360_000);

  // 1. Zakup
  const { numer } = await scenariuszZamowienia(page);

  // 2. Historia klienta przed potwierdzeniem płatności
  let idZamowienia = '';
  await test.step(`Historia: zamówienie ${numer} oczekuje na płatność`, async () => {
    const wiersz = await wierszHistorii(page, numer);

    await expect(statusWHistorii(wiersz), 'Zły status nowego zamówienia').toHaveText(dokladnie(STATUS_PRZY_ODBIORZE));
    await expect(wiersz.locator('a[href*="pdf-invoice"]'), 'Faktura dostępna przed potwierdzeniem płatności')
      .toHaveCount(0);

    // ID zamówienia (potrzebne w panelu) z linku do szczegółów zamówienia w tym wierszu.
    const szczegoly = wiersz.locator('a[data-link-action="view-order-details"], a[href*="id_order="]').first();
    const href = await szczegoly.getAttribute('href');
    idZamowienia = new URL(href!, page.url()).searchParams.get('id_order') ?? '';
    expect(idZamowienia, `Brak ID zamówienia w linku: ${href}`).toMatch(/^\d+$/);
  });

  // 3. Administrator potwierdza płatność, co generuje fakturę
  await zmienStatusWPanelu(page, idZamowienia, numer, STATUS_OPLACONE);

  // 4. Historia klienta po potwierdzeniu płatności
  const fakturaLink = await test.step(`Historia: zamówienie ${numer} opłacone, faktura dostępna`, async () => {
    const wiersz = await wierszHistorii(page, numer);

    await expect(statusWHistorii(wiersz), 'Zły status po potwierdzeniu płatności').toHaveText(dokladnie(STATUS_OPLACONE));

    const link = wiersz.locator('a[href*="pdf-invoice"]');
    await expect(link, `Brak faktury dla zamówienia ${numer}`).toHaveCount(1);

    // Link prowadzi do faktury tego zamówienia.
    const href = await link.getAttribute('href');
    expect(new URL(href!, page.url()).searchParams.get('id_order'), 'Link faktury dotyczy innego zamówienia')
      .toBe(idZamowienia);

    return link;
  });

  // 5. Pobranie i sprawdzenie pliku
  await test.step('Pobranie faktury VAT i sprawdzenie PDF', async () => {
    const pobieranie = page.waitForEvent('download', { timeout: 30_000 });
    await fakturaLink.click();
    const plik = await pobieranie;

    expect(await plik.failure(), 'Pobieranie faktury nie powiodło się').toBeNull();
    expect(plik.suggestedFilename(), 'Pobrany plik nie ma rozszerzenia .pdf').toMatch(/\.pdf$/i);

    const sciezka = testInfo.outputPath('faktura.pdf');
    await plik.saveAs(sciezka);
    const dane = fs.readFileSync(sciezka);

    expect(dane.length, 'Plik faktury jest pusty lub za mały').toBeGreaterThan(MIN_ROZMIAR_PDF);
    // Nagłówek i znacznik końca pliku PDF.
    expect(dane.subarray(0, 5).toString('latin1'), 'Plik nie zaczyna się nagłówkiem %PDF-').toBe('%PDF-');
    expect(dane.subarray(-1024).toString('latin1'), 'Plik nie ma znacznika końca %%EOF').toContain('%%EOF');
    // Co najmniej jedna strona dokumentu.
    expect(dane.toString('latin1'), 'PDF nie zawiera żadnej strony').toMatch(/\/Type\s*\/Page\b/);

    // Faktura trafia do raportu HTML jako załącznik.
    await testInfo.attach('faktura', { path: sciezka, contentType: 'application/pdf' });
    console.log(`Faktura: ${plik.suggestedFilename()}, ${dane.length} B, zamówienie ${numer} (id ${idZamowienia})`);
  });
});
