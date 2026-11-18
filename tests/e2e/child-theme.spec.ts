import { expect, test } from '@playwright/test';

test('PAN DOMU extends Classic and loads its own assets', async ({ page }) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  await page.goto('/');

  await expect(page.locator('link[href*="/themes/classic/assets/css/theme.css"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/themes/classic/assets/js/theme.js"]')).toHaveCount(1);
  await expect(page.locator('link[href*="/themes/pandomu/assets/css/custom.css"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/themes/pandomu/assets/js/custom.js"]')).toHaveCount(1);

  // Atrybut i obliczona właściwość CSS potwierdzają wykonanie skryptu i zastosowanie arkusza.
  const marker = page.locator('[data-pandomu-theme]');
  await expect(marker).toHaveAttribute('data-pandomu-script', 'ready');
  await expect.poll(() => page.locator('html').evaluate(
    (root) => getComputedStyle(root).getPropertyValue('--pandomu-theme-ready').trim(),
  )).toBe('1');
  expect(pageErrors).toEqual([]);
});
