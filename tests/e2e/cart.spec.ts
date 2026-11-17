import { test } from '@playwright/test';
import { scenariuszKoszyka } from './helpers/koszyk';

/**
 * W4.2: operacje na koszyku wymagane na odbiorze.
 * Kroki i asercje znajdują się w scenariuszKoszyka() (helpers/koszyk.ts),
 * bo ten sam scenariusz otwiera test zamówienia (order.spec.ts).
 */


test('koszyk: 10 produktów z 2 kategorii, wyszukiwanie + losowanie, usunięcie 3', async ({ page }) => {
  // Kilkadziesiąt otwarć stron; README wymaga zakończenia w 5 minut.
  test.setTimeout(300_000);

  await scenariuszKoszyka(page);
});
