import { test, expect } from '@playwright/test';

test('sklep jest dostępny', async ({ page }) => {
  const res = await page.goto('/');
  expect(res?.ok()).toBeTruthy();
  await expect(page).toHaveTitle(/.+/);
});