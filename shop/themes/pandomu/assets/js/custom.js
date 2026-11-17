// Test Playwright odczytuje ten atrybut po wykonaniu skryptu motywu.
const pandomuThemeMarker = document.querySelector('[data-pandomu-theme]');
if (pandomuThemeMarker) {
    pandomuThemeMarker.dataset.pandomuScript = 'ready';
}

const mobileMenu = document.getElementById('mobile_top_menu_wrapper');
const menuToggle = document.getElementById('menu-icon');
if (mobileMenu && menuToggle) {
    // Classic steruje widocznością menu za pomocą stylu inline.
    const syncMenuState = () => {
        menuToggle.setAttribute('aria-expanded', String(getComputedStyle(mobileMenu).display !== 'none'));
    };
    new MutationObserver(syncMenuState).observe(mobileMenu, { attributes: true, attributeFilter: ['style'] });
    syncMenuState();

    mobileMenu.closest('.pd-header-grid').addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') {
            menuToggle.click();
            menuToggle.focus();
        }
    });
}

document.querySelectorAll('.pd-navigation #top-menu > li').forEach((entry) => {
    const toggle = entry.querySelector(':scope > .pd-menu-item > .pd-submenu-toggle');
    if (!toggle) {
        return;
    }

    entry.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && entry.closest('.pd-navigation')) {
            entry.classList.add('pd-menu-closed');
            toggle.focus();
            event.stopPropagation();
        }
    });
    entry.addEventListener('pointerleave', () => entry.classList.remove('pd-menu-closed'));
    entry.addEventListener('focusout', (event) => {
        if (!entry.contains(event.relatedTarget)) {
            entry.classList.remove('pd-menu-closed');
        }
    });
    toggle.addEventListener('click', () => entry.classList.remove('pd-menu-closed'));
});
