import { expect, test } from '@playwright/test';

test('kafle i sekcja o firmie prowadzą do lokalnych stron sklepu', async ({ page }) => {
  await page.goto('/');

  const tiles = page.getByRole('region', { name: 'Kategorie', exact: true });
  await expect(tiles.getByRole('link')).toHaveCount(4);
  const firstTile = tiles.getByRole('link', { name: /klamki/i });
  await expect(firstTile).toBeVisible();
  await firstTile.click();
  await expect(page.locator('h1').first()).toContainText(/klamki/i);

  await page.goto('/');
  await page.getByRole('link', { name: 'Czytaj więcej o firmie' }).click();
  await expect(page.locator('h1').first()).toContainText('O sklepie');
});

test('nagłówek desktop pokazuje kontakt, konto, koszyk i osobną nawigację', async ({ page }) => {
  await page.setViewportSize({ width: 1680, height: 945 });
  await page.goto('/');

  const header = page.locator('#header');
  await expect(header.locator('#contact-link')).toContainText('Zadzwoń do nas');
  await expect(header.getByRole('link', { name: 'Konto', exact: true })).toBeVisible();
  await expect(header.locator('#_desktop_cart .cart-products-count')).toHaveText('0');

  const categories = header.getByRole('navigation', { name: 'Kategorie produktów' });
  const entry = categories.getByRole('link', { name: 'Klamki do drzwi', exact: true });
  await entry.hover();
  const subcategory = categories.getByRole('link', { name: 'klamki zewnętrzne', exact: true });
  await expect(subcategory).toBeVisible();
  await expect(subcategory).toHaveCSS('text-align', 'left');

  const panel = categories.locator('#top-menu > li > .sub-menu:visible');
  const bounds = await panel.boundingBox();
  const viewport = await page.evaluate(() => document.documentElement.clientWidth);
  expect(bounds?.x).toBeCloseTo(0);
  expect(bounds?.width).toBeCloseTo(viewport);
});


test('mobilne menu rozdziela rozwijanie podkategorii od wejścia do kategorii', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/');

  const menu = page.getByRole('button', { name: 'Menu kategorii' });
  const search = page.locator('#search_widget');
  await expect(search).toBeHidden();
  await menu.click();
  await expect(menu).toHaveAttribute('aria-expanded', 'true');
  await expect(search).toBeVisible();

  const navigation = page.getByRole('navigation', { name: 'Kategorie produktów' });
  const toggle = navigation.getByRole('button', { name: 'Podkategorie: Klamki do drzwi', exact: true });
  await toggle.click();
  await expect(navigation.getByRole('link', { name: 'klamki zewnętrzne', exact: true })).toBeVisible();

  await toggle.press('Escape');
  await expect(menu).toHaveAttribute('aria-expanded', 'false');
  await expect(menu).toBeFocused();
  await expect(search).toBeHidden();
});

test('wyszukiwarka i filtr dostępności korzystają z zaimportowanego katalogu', async ({ page }) => {
  await page.goto('/');

  const search = page.locator('#search_widget');
  await search.getByRole('textbox').fill('klamka');
  await search.getByRole('button', { name: 'Szukaj' }).click();
  await expect(page.locator('#js-product-list .product-miniature').first()).toBeVisible();

  await page.goto('/');
  await page.getByRole('region', { name: 'Kategorie', exact: true })
    .getByRole('link', { name: /klamki/i }).click();
  await page.locator('#search_filters').getByRole('link', { name: /w magazynie/i }).click();
  await expect(page).toHaveURL(/[?&]q=/);
  await expect(page.locator('#js-product-list .product-miniature').first()).toBeVisible();
});

test('panel kategorii pokazuje bieżącą kategorię i rozwija jej gałęzie nad filtrami', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 900 });
  await page.goto('/');
  await page.getByRole('region', { name: 'Kategorie', exact: true })
    .getByRole('link', { name: /bramy i ogrodzenia/i }).click();

  const column = page.locator('#left-column');
  const tree = column.locator('.block-categories');
  const filters = column.locator('#search_filters_wrapper');
  await expect(tree.locator('.category-top-menu > li > a')).toHaveText('Bramy i ogrodzenia');
  expect((await tree.boundingBox())?.y ?? Infinity).toBeLessThan((await filters.boundingBox())?.y ?? 0);

  const toggle = tree.getByRole('button', { name: 'Podkategorie: zamki', exact: true });
  const child = tree.getByRole('link', { name: 'zamki hakowe', exact: true });
  await expect(child).toBeHidden();
  await toggle.press('Enter');
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(child).toBeVisible();

  await tree.getByRole('link', { name: 'zamki', exact: true }).click();
  await expect(tree.locator('.category-top-menu > li > a')).toHaveText('zamki');
  await expect(tree.getByRole('link', { name: 'zamki hakowe', exact: true })).toBeVisible();
});


test('sortowanie kategorii zachowuje aktywny filtr i aktualizuje kolejność produktów', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 900 });
  await page.goto('/');
  await page.getByRole('region', { name: 'Kategorie', exact: true })
    .getByRole('link', { name: /klamki/i }).click();

  await expect(page.locator('#js-product-list-header, #subcategories')).toHaveCount(0);
  const sort = page.locator('.products-sort-order');
  const button = sort.getByRole('button', { name: 'Sortuj wg wyboru' });
  await expect(button).toHaveText(/Trafność/);

  await page.locator('#search_filters').getByRole('link', { name: /w magazynie/i }).click();
  await expect(page).toHaveURL(/[?&]q=/);
  const activeFilter = new URL(page.url()).searchParams.get('q');

  await button.press('Enter');
  await expect(button).toHaveAttribute('aria-expanded', 'true');
  await expect(sort.getByRole('link')).toHaveText([
    'Sprzedaż, od najwyższej do najniższej',
    'Trafność',
    'Nazwa, A do Z',
    'Nazwa, Z do A',
    'Cena, rosnąco',
    'Cena, malejąco',
  ]);
  await sort.getByRole('link', { name: 'Cena, rosnąco', exact: true }).click();
  await expect(page).toHaveURL(/[?&]order=product\.price\.asc/);
  await expect(button).toHaveText(/Cena, rosnąco/);
  expect(new URL(page.url()).searchParams.get('q')).toBe(activeFilter);
  await expect(page.locator('#js-product-list-header, #subcategories')).toHaveCount(0);

  const prices = (await page.locator('#js-product-list .product-miniature .price').allTextContents())
    .map((text) => Number(text.replace(/[^\d,]/g, '').replace(',', '.')));
  expect(prices.length).toBeGreaterThan(1);
  expect(prices).toEqual([...prices].sort((a, b) => a - b));
});


test('strona kategorii pokazuje 36 produktów bez bloków marek i dostawców', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('region', { name: 'Kategorie', exact: true })
    .getByRole('link', { name: /klamki/i }).click();

  await expect(page.locator('#search_filters_brands, #search_filters_suppliers')).toHaveCount(0);
  const products = page.locator('#js-product-list .product-miniature');
  await expect(products).toHaveCount(36);
  const firstProduct = await products.first().getAttribute('data-id-product');
  const categoryUrl = new URL(page.url());

  await page.locator('.pagination .page-list a[href*="page=2"]').first().click();
  await expect(page).toHaveURL(/[?&]page=2/);
  await expect(products).toHaveCount(36);
  await expect(products.first()).not.toHaveAttribute('data-id-product', firstProduct ?? '');

  categoryUrl.searchParams.set('resultsPerPage', '100');
  await page.goto(categoryUrl.toString());
  await expect(products).toHaveCount(36);
});
