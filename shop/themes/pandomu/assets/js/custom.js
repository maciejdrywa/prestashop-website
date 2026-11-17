// Test Playwright sprawdza, czy skrypt ustawił atrybut na ukrytym znaczniku Smarty.
const pandomuThemeMarker = document.querySelector('[data-pandomu-theme]');
if (pandomuThemeMarker) {
    pandomuThemeMarker.dataset.pandomuScript = 'ready';
}
