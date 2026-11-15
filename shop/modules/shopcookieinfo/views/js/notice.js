(() => {
  const initialize = () => {
    const notice = document.getElementById('shop-cookie-info');
    if (!notice) return;

    const cookieName = encodeURIComponent(notice.dataset.cookieName);
    // Also handle previously cached HTML after closing the notice on another page.
    try {
      if (document.cookie.split(';').some(part => part.trim() === `${cookieName}=1`)) {
        notice.remove();
        return;
      }
    } catch (_) {
      // Keep the notice usable even if reading browser storage is blocked.
    }

    const button = notice.querySelector('[data-cookie-dismiss]');
    if (!button) return;
    button.hidden = false;
    button.addEventListener('click', () => {
      const secure = window.location.protocol === 'https:' ? '; Secure' : '';
      try {
        document.cookie = `${cookieName}=1; Max-Age=${notice.dataset.cookieMaxAge}; Path=${notice.dataset.cookiePath}; SameSite=Lax${secure}`;
      } catch (_) {
        // Closing still works for this page when browser storage is blocked.
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
