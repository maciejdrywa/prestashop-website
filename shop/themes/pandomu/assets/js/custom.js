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

    // Po zmianie szerokości Classic przenosi menu. Zamknięty widok mobilny nie zachowuje fokusu.
    window.matchMedia('(min-width: 768px)').addEventListener('change', (event) => {
        if (event.matches) {
            mobileMenu.style.display = 'none';
            document.getElementById('header').classList.remove('is-open');
            $('#notifications, #wrapper, #footer').show();
            syncMenuState();
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

// Classic odtwarza kontrolki po zmianie wariantu i zawartości koszyka.
function enhanceStorefrontControls() {
    document.querySelectorAll('.bootstrap-touchspin-up, .bootstrap-touchspin-down').forEach((button) => {
        const increase = button.classList.contains('bootstrap-touchspin-up');
        button.setAttribute('aria-label', increase ? 'Zwiększ ilość' : 'Zmniejsz ilość');
        button.querySelectorAll('.material-icons').forEach((icon) => icon.setAttribute('aria-hidden', 'true'));
    });

    document.querySelectorAll('[role="tablist"]').forEach((list) => {
        list.setAttribute('aria-label', list.closest('.tabs') ? 'Informacje o produkcie' : 'Dane klienta');
        list.querySelectorAll(':scope > li').forEach((item) => item.setAttribute('role', 'presentation'));
        list.querySelectorAll('[role="tab"]').forEach((tab) => {
            const active = tab.classList.contains('active');
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
        });
    });

    document.querySelectorAll('.js-thumb').forEach((image, index) => {
        image.setAttribute('role', 'button');
        image.tabIndex = 0;
        image.setAttribute('aria-label', 'Pokaż zdjęcie ' + (index + 1) + ': ' + image.alt);
    });

    document.querySelectorAll('.product-cover .layer').forEach((zoom) => {
        zoom.setAttribute('role', 'button');
        zoom.setAttribute('aria-label', 'Powiększ zdjęcie produktu');
        zoom.tabIndex = 0;
        zoom.querySelectorAll('.material-icons').forEach((icon) => icon.setAttribute('aria-hidden', 'true'));
    });

    document.querySelectorAll('[data-pd-field-error]').forEach((error) => {
        const group = error.closest('.form-group');
        group.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((field) => {
            field.setAttribute('aria-invalid', 'true');
            const descriptions = new Set((field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
            descriptions.add(error.id);
            field.setAttribute('aria-describedby', [...descriptions].join(' '));
        });
    });
}

document.addEventListener('keydown', (event) => {
    const imageButton = event.target.closest('.js-thumb, .product-cover .layer');
    if (imageButton && ['Enter', ' '].includes(event.key)) {
        event.preventDefault();
        imageButton.click();
    }

    const tab = event.target.closest('[role="tab"]');
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        return;
    }
    event.preventDefault();
    const tabs = [...tab.closest('[role="tablist"]').querySelectorAll('[role="tab"]')];
    let index = tabs.indexOf(tab);
    if (event.key === 'Home') {
        index = 0;
    } else if (event.key === 'End') {
        index = tabs.length - 1;
    } else {
        index = (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    }
    tabs[index].focus();
    tabs[index].click();
});

const contentWrapper = document.getElementById('content-wrapper');
if (contentWrapper) {
    contentWrapper.tabIndex = -1;
}

// Bootstrap zapisuje stan także na zwykłych div i ul, które nie obsługują aria-expanded.
new MutationObserver((changes) => {
    changes.forEach(({ target }) => {
        if (target.matches('.collapse, .collapsing') && target.hasAttribute('aria-expanded')) {
            target.removeAttribute('aria-expanded');
        }
    });
}).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['aria-expanded'] });

// Reagujemy po inicjalizacji wtyczek Classic, również na wymianę fragmentów przez AJAX.
$(function () {
    enhanceStorefrontControls();
    $(document).on('shown.bs.tab', '[role="tab"]', enhanceStorefrontControls);
    prestashop.on('updatedProduct', enhanceStorefrontControls);
    prestashop.on('updatedCart', enhanceStorefrontControls);
    prestashop.on('shownQuickView', enhanceStorefrontControls);
});
