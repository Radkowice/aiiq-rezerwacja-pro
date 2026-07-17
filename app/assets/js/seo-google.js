(function () {
  'use strict';

  const API_URL = '/api/system/seo-google.php';
  const TITLE_MAX_LENGTH = 60;
  const DESCRIPTION_MAX_LENGTH = 160;
  const TENANT_FAVICON_URL = '/api/system/favicon-front.php';
  const DEFAULT_FAVICON_URL = '/favicon.png';

  let initialized = false;
  let elements = null;
  let savedState = {
    indexingEnabled: true,
    seoTitle: '',
    seoDescription: '',
    effectiveTitle: '',
    effectiveDescription: '',
    domain: ''
  };

  function findModuleElements() {
    const section = document.querySelector('section[data-section="widocznosc-google"]');

    if (!section) {
      return null;
    }

    const found = {
      section,
      message: section.querySelector('#seo-google-message'),
      loading: section.querySelector('#seo-google-loading'),
      content: section.querySelector('#seo-google-content'),
      form: section.querySelector('#seo-google-form'),
      indexingEnabled: section.querySelector('#seo-google-indexing-enabled'),
      title: section.querySelector('#seo-google-title'),
      description: section.querySelector('#seo-google-description'),
      titleCount: section.querySelector('#seo-google-title-count'),
      descriptionCount: section.querySelector('#seo-google-description-count'),
      saveButton: section.querySelector('#seo-google-save-btn'),
      visibilityStatus: section.querySelector('#seo-google-visibility-status'),
      previewFavicon: section.querySelector('#seo-google-preview-favicon'),
      previewSiteName: section.querySelector('#seo-google-preview-site-name'),
      previewDomain: section.querySelector('#seo-google-preview-domain'),
      previewTitle: section.querySelector('#seo-google-preview-title'),
      previewDescription: section.querySelector('#seo-google-preview-description'),
      publicPageLink: section.querySelector('#seo-google-public-page-link')
    };

    return Object.values(found).every(Boolean) ? found : null;
  }

  function setMessage(text = '', type = '') {
    if (!elements?.message) return;

    elements.message.textContent = String(text || '');
    elements.message.classList.remove('success', 'error');

    if (type === 'success' || type === 'error') {
      elements.message.classList.add(type);
    }

    elements.message.classList.toggle('hidden', elements.message.textContent === '');
  }

  function setFormDisabled(disabled, buttonText = '') {
    const isDisabled = disabled === true;

    [
      elements?.indexingEnabled,
      elements?.title,
      elements?.description,
      elements?.saveButton
    ].forEach((control) => {
      if (control) control.disabled = isDisabled;
    });

    if (elements?.saveButton) {
      elements.saveButton.textContent = buttonText || 'Zapisz ustawienia';
    }

    if (elements?.content) {
      elements.content.setAttribute('aria-busy', isDisabled ? 'true' : 'false');
    }
  }

  function characterCount(value) {
    return Array.from(String(value || '')).length;
  }

  function enforceTechnicalLimit(input, maxLength) {
    if (!input) return;

    const characters = Array.from(String(input.value || ''));

    if (characters.length > maxLength) {
      input.value = characters.slice(0, maxLength).join('');
    }
  }

  function updateCounter(input, counter, maxLength) {
    if (!input || !counter) return;

    const length = characterCount(input.value);
    counter.textContent = `${length} / ${maxLength} znaków`;
  }

  function previewValue(currentValue, savedValue, effectiveValue, pendingText) {
    const current = String(currentValue || '').trim();

    if (current !== '') {
      return current;
    }

    if (String(savedValue || '').trim() === '') {
      const effective = String(effectiveValue || '').trim();
      return effective || pendingText;
    }

    return pendingText;
  }

  function normalizePreviewDomain(domain) {
    return String(domain || '')
      .trim()
      .replace(/^https?:\/\//i, '')
      .replace(/\/+$/, '');
  }

  function previewSiteName(domain) {
    const hostname = normalizePreviewDomain(domain).split('/')[0];
    const firstLabel = hostname.split('.')[0].replace(/[-_]+/g, ' ').trim();

    if (firstLabel === '') {
      return 'Twoja strona';
    }

    return firstLabel.charAt(0).toLocaleUpperCase('pl-PL') + firstLabel.slice(1);
  }

  function loadPreviewFavicon() {
    if (!elements?.previewFavicon) return;

    const favicon = elements.previewFavicon;

    favicon.addEventListener('error', () => {
      favicon.src = DEFAULT_FAVICON_URL;
    }, { once: true });

    favicon.src = TENANT_FAVICON_URL;
  }

  function updatePreview() {
    if (!elements) return;

    const previewDomain = normalizePreviewDomain(savedState.domain);

    elements.previewSiteName.textContent = previewSiteName(previewDomain);
    elements.previewDomain.textContent = previewDomain !== ''
      ? `https://${previewDomain}`
      : 'Domena nie jest jeszcze dostępna';
    elements.publicPageLink.href = new URL('/', window.location.origin).href;
    elements.publicPageLink.classList.toggle('hidden', savedState.domain === '');
    elements.previewTitle.textContent = previewValue(
      elements.title.value,
      savedState.seoTitle,
      savedState.effectiveTitle,
      'Tytuł zostanie ustalony po zapisaniu ustawień'
    );
    elements.previewDescription.textContent = previewValue(
      elements.description.value,
      savedState.seoDescription,
      savedState.effectiveDescription,
      'Opis zostanie ustalony po zapisaniu ustawień.'
    );
  }

  function updateVisibilityStatus() {
    if (!elements?.visibilityStatus) return;

    const isEnabled = elements.indexingEnabled.checked;
    const isUnsaved = isEnabled !== savedState.indexingEnabled;
    const status = isEnabled ? 'Widoczność włączona' : 'Strona ukryta';

    elements.visibilityStatus.textContent = isUnsaved ? `${status} — zmiana niezapisana` : status;
  }

  function updateLiveFields() {
    enforceTechnicalLimit(elements.title, TITLE_MAX_LENGTH);
    enforceTechnicalLimit(elements.description, DESCRIPTION_MAX_LENGTH);
    updateCounter(elements.title, elements.titleCount, TITLE_MAX_LENGTH);
    updateCounter(elements.description, elements.descriptionCount, DESCRIPTION_MAX_LENGTH);
    updateVisibilityStatus();
    updatePreview();
  }

  function applyResponseData(data) {
    const settings = data && typeof data.settings === 'object' && data.settings !== null
      ? data.settings
      : {};

    savedState = {
      indexingEnabled: settings.indexing_enabled !== false,
      seoTitle: String(settings.seo_title || ''),
      seoDescription: String(settings.seo_description || ''),
      effectiveTitle: String(settings.effective_title || ''),
      effectiveDescription: String(settings.effective_description || ''),
      domain: String(settings.domain || '')
    };

    elements.indexingEnabled.checked = savedState.indexingEnabled;
    elements.title.value = savedState.seoTitle;
    elements.description.value = savedState.seoDescription;
    updateLiveFields();
  }

  async function requestJson(options = {}) {
    if (typeof window.apiFetch === 'function') {
      return await window.apiFetch(API_URL, options);
    }

    if (typeof window.adminRequest !== 'function') {
      throw new Error('Brak helpera requestów administracyjnych.');
    }

    const response = await window.adminRequest(API_URL, options);
    return await response.json();
  }

  async function loadSettings() {
    setMessage();
    elements.loading.classList.remove('hidden');
    elements.content.classList.add('hidden');
    setFormDisabled(true, 'Ładowanie…');

    try {
      const data = await requestJson({
        method: 'GET',
        cache: 'no-store'
      });

      if (!data || data.success !== true) {
        throw new Error(data?.error || 'Nie udało się pobrać ustawień widoczności.');
      }

      applyResponseData(data);
      setFormDisabled(false);
    } catch (error) {
      console.error('seo-google load error:', error);
      setMessage(error?.message || 'Nie udało się pobrać ustawień widoczności.', 'error');
      setFormDisabled(true, 'Zapis niedostępny');
    } finally {
      elements.loading.classList.add('hidden');
      elements.content.classList.remove('hidden');
    }
  }

  async function saveSettings() {
    const titleLength = characterCount(elements.title.value);
    const descriptionLength = characterCount(elements.description.value);

    if (titleLength > TITLE_MAX_LENGTH || descriptionLength > DESCRIPTION_MAX_LENGTH) {
      setMessage('Tytuł lub opis przekracza techniczny limit znaków.', 'error');
      return;
    }

    setMessage();
    setFormDisabled(true, 'Zapisywanie…');

    try {
      const data = await requestJson({
        method: 'POST',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          indexing_enabled: elements.indexingEnabled.checked,
          seo_title: elements.title.value.trim(),
          seo_description: elements.description.value.trim()
        })
      });

      if (!data || data.success !== true) {
        throw new Error(data?.error || 'Nie udało się zapisać ustawień widoczności.');
      }

      applyResponseData(data);
      setMessage(data.message || 'Ustawienia widoczności zostały zapisane.', 'success');
    } catch (error) {
      console.error('seo-google save error:', error);
      setMessage(error?.message || 'Nie udało się zapisać ustawień widoczności.', 'error');
    } finally {
      setFormDisabled(false);
    }
  }

  function bindEvents() {
    elements.title.addEventListener('input', updateLiveFields);
    elements.description.addEventListener('input', updateLiveFields);
    elements.indexingEnabled.addEventListener('change', updateLiveFields);
    elements.form.addEventListener('submit', (event) => {
      event.preventDefault();
      void saveSettings();
    });
  }

  window.initSeoGoogleModule = function initSeoGoogleModule() {
    if (initialized) {
      return true;
    }

    elements = findModuleElements();

    if (!elements) {
      return false;
    }

    initialized = true;
    bindEvents();
    loadPreviewFavicon();
    void loadSettings();

    return true;
  };
})();
