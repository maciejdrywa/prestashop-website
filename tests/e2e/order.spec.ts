import { test, expect } from '@playwright/test';
import {
  scenariuszZamowienia, wierszHistorii, statusWHistorii,
  PLATNOSC_PRZY_ODBIORZE, STATUS_PRZY_ODBIORZE, dokladnie,
} from './helpers/zamowienie';

/**
 * W4.3: zakup zawartości koszyka przez nowego klienta.
 * Kroki 1–6 (koszyk, rejestracja, adres, przewoźnik, płatność przy odbiorze, potwierdzenie)
 * są w scenariuszZamowienia() (helpers/zamowienie.ts), bo ten sam zakup otwiera test faktury.
 *  7. Zamówienie widoczne w historii klienta ze statusem płatności przy odbiorze.
 */

// Polski klient: PrestaShop dobiera kraj adresu na podstawie języka przeglądarki.
test.use({ locale: 'pl-PL' });


test('zamówienie: nowy klient, przewoźnik, płatność przy odbiorze', async ({ page }) => {
  // Scenariusz koszyka + zamówienie; README wymaga zakończenia w 5 minut.
  test.setTimeout(300_000);

  const zamowienie = await scenariuszZamowienia(page);

  // 7. Zamówienie w historii klienta
  await test.step('Zamówienie w historii klienta', async () => {
    const wiersz = await wierszHistorii(page, zamowienie.numer);

    await expect(wiersz, 'Zła metoda płatności w historii').toContainText(PLATNOSC_PRZY_ODBIORZE);
    await expect(statusWHistorii(wiersz), 'Zły status zamówienia').toHaveText(dokladnie(STATUS_PRZY_ODBIORZE));
  });
});
