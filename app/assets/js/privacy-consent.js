(function () {
  const STORAGE_KEY = 'aiiq_privacy_notice_v1';
  const STORAGE_VALUE = 'acknowledged';
  const LEGACY_STORAGE_KEY = 'aiiq_privacy_consent_v1';
  const LEGACY_STORAGE_VALUE = 'accepted';
  const POLICY_URL = '/legal/polityka-prywatnosci.html';

  function hasAcknowledgedNotice() {
    try {
      if (window.localStorage.getItem(STORAGE_KEY) === STORAGE_VALUE) {
        return true;
      }

      if (
        window.localStorage.getItem(LEGACY_STORAGE_KEY) ===
        LEGACY_STORAGE_VALUE
      ) {
        window.localStorage.setItem(STORAGE_KEY, STORAGE_VALUE);
        window.localStorage.removeItem(LEGACY_STORAGE_KEY);
        return true;
      }

      return false;
    } catch (error) {
      return false;
    }
  }

  function storeAcknowledgement() {
    try {
      window.localStorage.setItem(STORAGE_KEY, STORAGE_VALUE);
      window.localStorage.removeItem(LEGACY_STORAGE_KEY);
    } catch (error) {
      return;
    }
  }

  function createPrivacyNotice() {
    const banner = document.createElement('section');
    banner.className = 'privacy-consent';
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-label', 'Informacja o plikach cookies');

    const inner = document.createElement('div');
    inner.className = 'privacy-consent__inner';

    const text = document.createElement('p');
    text.className = 'privacy-consent__text';
    text.append(
      'Ta strona korzysta wyłącznie z niezbędnych plików cookies i podobnych technologii potrzebnych do prawidłowego działania systemu rezerwacji. Szczegółowe informacje znajdziesz w '
    );

    const link = document.createElement('a');
    link.className = 'privacy-consent__link';
    link.href = POLICY_URL;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Polityce prywatności AI-IQ';

    text.append(link);
    text.append(
      ' oraz w dokumentach usługodawcy, jeśli zostały przez niego udostępnione.'
    );

    const button = document.createElement('button');
    button.className = 'privacy-consent__button';
    button.type = 'button';
    button.textContent = 'Rozumiem';
    button.addEventListener('click', function () {
      storeAcknowledgement();
      banner.hidden = true;
      banner.remove();
    });

    inner.append(text, button);
    banner.append(inner);

    return banner;
  }

  function initPrivacyNotice() {
    if (hasAcknowledgedNotice()) {
      return;
    }

    document.body.append(createPrivacyNotice());
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPrivacyNotice);
  } else {
    initPrivacyNotice();
  }
})();