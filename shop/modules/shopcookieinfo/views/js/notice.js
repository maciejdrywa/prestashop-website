/** Uruchamia obsługę informacji o cookies po przygotowaniu dokumentu. */
(() => {
  /** Odczytuje stan zamknięcia i wiąże przycisk z zapisem cookie dla sklepu. */
  const initialize = () => {
    const notice = document.getElementById('shop-cookie-info');
    if (!notice) return;

    const cookieName = encodeURIComponent(notice.dataset.cookieName);
    // Obsługujemy także HTML z cache po zamknięciu komunikatu na innej stronie.
    try {
      if (document.cookie.split(';').some(part => part.trim() === `${cookieName}=1`)) {
        notice.remove();
        return;
      }
    } catch (_) {
      // Komunikat pozostaje użyteczny przy zablokowanym odczycie cookies.
    }

    const button = notice.querySelector('[data-cookie-dismiss]');
    if (!button) return;
    button.hidden = false;
    /** Zapamiętuje zamknięcie na okres podany przez moduł i usuwa komunikat z DOM. */
    button.addEventListener('click', () => {
      const secure = window.location.protocol === 'https:' ? '; Secure' : '';
      try {
        document.cookie = `${cookieName}=1; Max-Age=${notice.dataset.cookieMaxAge}; Path=${notice.dataset.cookiePath}; SameSite=Lax${secure}`;
      } catch (_) {
        // Zamknięcie działa na bieżącej stronie również przy zablokowanym zapisie cookie.
      }
      notice.remove();
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();
