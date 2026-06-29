(function () {
  const STORAGE_KEY = 'aiiq_privacy_consent_v1';
  const POLICY_URL = '/legal/polityka-prywatnosci.html';

  function hasConsent() {
    try {
      return window.localStorage.getItem(STORAGE_KEY) === 'accepted';
    } catch (error) {
      return false;
    }
  }

  function storeConsent() {
    try {
      window.localStorage.setItem(STORAGE_KEY, 'accepted');
    } catch (error) {
      return;
    }
  }

  function createConsentBanner() {
    const banner = document.createElement('section');
    banner.className = 'privacy-consent';
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-label', 'Informacja o plikach cookies');

    const inner = document.createElement('div');
    inner.className = 'privacy-consent__inner';

    const text = document.createElement('p');
    text.className = 'privacy-consent__text';
    text.append(
      'Ta strona używa niezbędnych plików cookies i podobnych technologii, aby zapewnić prawidłowe działanie systemu rezerwacji. Korzystając ze strony, możesz zapoznać się z '
    );

    const link = document.createElement('a');
    link.className = 'privacy-consent__link';
    link.href = POLICY_URL;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Polityką prywatności';
    text.append(link);
    text.append(' AI-IQ oraz z dokumentami usługodawcy, jeśli zostały przez niego udostępnione.');

    const button = document.createElement('button');
    button.className = 'privacy-consent__button';
    button.type = 'button';
    button.textContent = 'Akceptuję';
    button.addEventListener('click', function () {
      storeConsent();
      banner.hidden = true;
      banner.remove();
    });

    inner.append(text, button);
    banner.append(inner);

    return banner;
  }

  function initConsentBanner() {
    if (hasConsent()) {
      return;
    }

    document.body.append(createConsentBanner());
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initConsentBanner);
  } else {
    initConsentBanner();
  }
})();
