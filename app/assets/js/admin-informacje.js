(function () {
  'use strict';

  const API_ME = '/api/auth/me.php';
  const API_SERVICE_SETTINGS = '/api/system/service-settings.php';

  let currentServiceSettings = null;

  const REQUIRED_COMPANY_FIELDS = [
    {
      id: 'info-company-full-name',
      label: 'Pełna nazwa firmy'
    },
    {
      id: 'info-company-owner-name',
      label: 'Imię i nazwisko'
    },
    {
      id: 'info-company-tax-id',
      label: 'NIP'
    },
    {
      id: 'info-company-address',
      label: 'Adres firmy'
    },
    {
      id: 'info-company-email',
      label: 'E-mail firmy'
    },
    {
      id: 'info-company-phone',
      label: 'Telefon firmy'
    }
  ];

  function getEl(id) {
    return document.getElementById(id);
  }

  function setText(id, value, fallback = '—') {
    const el = getEl(id);
    if (!el) return;
    el.textContent = value && String(value).trim() !== '' ? value : fallback;
  }

  function setValue(id, value) {
    const el = getEl(id);
    if (!el) return;
    el.value = value ?? '';
  }

  function getValue(id) {
    const el = getEl(id);
    return el ? el.value.trim() : '';
  }

  function setMessage(message, type = '') {
    const el = getEl('company-info-message');
    if (!el) return;

    el.textContent = message || '';
    el.classList.remove('success', 'error');

    if (type) {
      el.classList.add(type);
    }
  }

  function clearFieldErrors() {
    REQUIRED_COMPANY_FIELDS.forEach((field) => {
      const el = getEl(field.id);
      if (!el) return;

      el.classList.remove('field-error');
      el.removeAttribute('aria-invalid');
    });
  }

  function markFieldError(id) {
    const el = getEl(id);
    if (!el) return;

    el.classList.add('field-error');
    el.setAttribute('aria-invalid', 'true');
  }

  function isValidEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
  }

  function validateCompanyInfo() {
    clearFieldErrors();

    let firstInvalidFieldId = null;

    REQUIRED_COMPANY_FIELDS.forEach((field) => {
      const value = getValue(field.id);

      if (!value) {
        if (!firstInvalidFieldId) {
          firstInvalidFieldId = field.id;
        }

        markFieldError(field.id);
      }
    });

    if (firstInvalidFieldId) {
      setMessage('Uzupełnij wszystkie wymagane dane firmy', 'error');
      getEl(firstInvalidFieldId)?.focus();
      return false;
    }

    const email = getValue('info-company-email');

    if (!isValidEmail(email)) {
      markFieldError('info-company-email');
      setMessage('Podaj poprawny adres e-mail firmy.', 'error');
      getEl('info-company-email')?.focus();
      return false;
    }

    return true;
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        ...(options.headers || {})
      },
      ...options
    });

    const data = await response.json().catch(() => null);

    if (!response.ok || !data) {
      throw new Error(data?.error || 'Błąd połączenia z serwerem.');
    }

    return data;
  }

  function normalizeServiceSettingsResponse(data) {
    return data?.settings || data?.data || data || {};
  }

  async function loadAccountInfo() {
    const data = await fetchJson(API_ME);

    const user = data?.user || {};
    const branding = data?.branding || {};

    setText('info-company-name', branding.client_name || user.company_name || '');
    setText('info-client-number', branding.client_number || user.client_number || '');
    setText('info-company-id', branding.company_id || user.tenant_id || '');
  }

  async function loadCompanyInfo() {
    const data = await fetchJson(API_SERVICE_SETTINGS);
    const settings = normalizeServiceSettingsResponse(data);

    currentServiceSettings = settings;

    setValue('info-company-full-name', settings.company_full_name || '');
    setValue('info-company-owner-name', settings.company_owner_name || '');
    setValue('info-company-tax-id', settings.company_tax_id || '');
    setValue('info-company-address', settings.company_address || '');
    setValue('info-company-email', settings.company_email || '');
    setValue('info-company-phone', settings.company_phone || '');
  }

  function buildPayloadForCompanyInfo() {
    const base = currentServiceSettings && typeof currentServiceSettings === 'object'
      ? { ...currentServiceSettings }
      : {};

    return {
      ...base,

      company_full_name: getValue('info-company-full-name'),
      company_owner_name: getValue('info-company-owner-name'),
      company_tax_id: getValue('info-company-tax-id'),
      company_address: getValue('info-company-address'),
      company_email: getValue('info-company-email'),
      company_phone: getValue('info-company-phone')
    };
  }

  async function saveCompanyInfo() {
    const button = getEl('save-company-info-btn');
    const defaultText = button ? button.textContent : '';

    try {
      setMessage('');

      if (!validateCompanyInfo()) {
        return;
      }

      if (button) {
        button.disabled = true;
        button.textContent = 'Zapisywanie...';
      }

      const payload = buildPayloadForCompanyInfo();

      const data = await fetchJson(API_SERVICE_SETTINGS, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      if (!data.success) {
        throw new Error(data.error || 'Nie udało się zapisać danych firmy.');
      }

      currentServiceSettings = normalizeServiceSettingsResponse(data) || payload;

      clearFieldErrors();
      setMessage('Dane firmy zostały zapisane', 'success');
    } catch (error) {
      console.error('save company info error:', error);
      setMessage(error.message || 'Nie udało się zapisać danych firmy.', 'error');
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = defaultText || 'Zapisz dane firmy';
      }
    }
  }

  function bindLiveValidation() {
    REQUIRED_COMPANY_FIELDS.forEach((field) => {
      const el = getEl(field.id);
      if (!el || el.dataset.validationBound) return;

      el.dataset.validationBound = '1';

      el.addEventListener('input', () => {
        if (getValue(field.id)) {
          el.classList.remove('field-error');
          el.removeAttribute('aria-invalid');
        }
      });
    });
  }

  async function initAdminInfo() {
    if (!document.querySelector('[data-section="informacje"]')) return;

    const button = getEl('save-company-info-btn');

    if (button && !button.dataset.bound) {
      button.dataset.bound = '1';
      button.addEventListener('click', saveCompanyInfo);
    }

    bindLiveValidation();

    try {
      await Promise.all([
        loadAccountInfo(),
        loadCompanyInfo()
      ]);
    } catch (error) {
      console.error('load admin info error:', error);
      setMessage(error.message || 'Nie udało się pobrać danych firmy.', 'error');
    }
  }

  document.addEventListener('DOMContentLoaded', initAdminInfo);
})();