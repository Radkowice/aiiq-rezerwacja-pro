document.addEventListener('DOMContentLoaded', () => {
  initServicePaymentsSettings();
});

let servicePaymentsLoaded = false;

function initServicePaymentsSettings() {
  const saveBtn = document.getElementById('save-service-payments-btn');

  if (!saveBtn) return;

  loadServiceCompanyNamePreview();
  bindServicePaymentsMenuLoad();

  saveBtn.addEventListener('click', async () => {
    await saveServicePaymentsSettings(saveBtn);
  });
}

function bindServicePaymentsMenuLoad() {
  const menuItems = document.querySelectorAll('.menu-item');

  menuItems.forEach((btn) => {
    const label = btn.textContent || '';

    if (!label.includes('Usługa') && !label.includes('Płatności')) {
      return;
    }

    btn.addEventListener('click', async () => {
      if (servicePaymentsLoaded) return;

      await loadServicePaymentsSettings();
      servicePaymentsLoaded = true;
    });
  });
}

async function loadServiceCompanyNamePreview() {
  const previewEl = document.getElementById('service-company-name-preview');

  if (!previewEl) return;

  try {
    const response = await fetch('/api/auth/me.php', {
      method: 'GET',
      cache: 'no-store',
      credentials: 'include',
      headers: {
        'Accept': 'application/json'
      }
    });

    if (response.status === 401) {
      previewEl.textContent = 'Nazwa firmy ustawisz w zakładce Konto';
      return;
    }

    const data = await response.json();

    if (!response.ok || !data?.success) {
      previewEl.textContent = 'Nazwa firmy ustawisz w zakładce Konto';
      return;
    }

    const companyName =
      data?.branding?.client_name ||
      data?.user?.client_name ||
      data?.user?.company_name ||
      data?.client_name ||
      '';

    previewEl.textContent = companyName
      ? companyName
      : 'Nazwa firmy ustawisz w zakładce Konto';

  } catch (error) {
    console.error('loadServiceCompanyNamePreview error:', error);
    previewEl.textContent = 'Nazwa firmy ustawisz w zakładce Konto';
  }
}

async function loadServicePaymentsSettings() {
  const formExists = document.getElementById('save-service-payments-btn');

  if (!formExists) {
    return;
  }

  try {
    const response = await fetch('/api/system/service-settings.php', {
      method: 'GET',
      cache: 'no-store',
      credentials: 'include',
      headers: {
        'Accept': 'application/json'
      }
    });

    if (response.status === 401) {
      console.warn('Ustawienia usługi nie zostały pobrane: brak aktywnej sesji.');
      return;
    }

    let data = null;

    try {
      data = await response.json();
    } catch (jsonError) {
      console.warn('Nieprawidłowa odpowiedź JSON z service-settings.php');
      return;
    }

    if (!response.ok) {
      console.warn('Nie udało się pobrać ustawień usługi:', data?.error || response.status);
      return;
    }

    if (data?.success) {
      fillServicePaymentsForm(data.settings || {});
      return;
    }

    console.warn('Nie udało się pobrać ustawień usługi:', data?.error || data);
  } catch (error) {
    console.error('loadServicePaymentsSettings error:', error);
  }
}

function fillServicePaymentsForm(settings) {
  setFieldValue('service-company-tax-id', settings.company_tax_id || '');
  setFieldValue('service-company-address', settings.company_address || '');
  setFieldValue('service-company-email', settings.company_email || '');
  setFieldValue('service-company-phone', settings.company_phone || '');

  setFieldValue('service-name', settings.service_name || '');
  setFieldValue('service-description', settings.service_description || '');
  setFieldValue('service-price-amount', settings.price_amount ?? '');
  setFieldValue('service-price-currency', settings.price_currency || 'PLN');

  setCheckboxValue('service-payment-required', Boolean(settings.payment_required));
  setFieldValue('service-payment-time-limit-hours', settings.payment_time_limit_value || 48);
  setFieldValue('service-payment-time-unit', settings.payment_time_limit_unit || 'hours');
  setFieldValue('service-payment-message', settings.payment_message || '');
}

async function saveServicePaymentsSettings(button) {
  const payload = {
  service_name: getFieldValue('service-name'),
  service_description: getFieldValue('service-description'),
  price_amount: getFieldValue('service-price-amount') || 0,
  price_currency: getFieldValue('service-price-currency') || 'PLN',

  payment_required: getCheckboxValue('service-payment-required'),
  payment_time_limit_value: parseInt(getFieldValue('service-payment-time-limit-hours') || '48', 10),
  payment_time_limit_unit: getFieldValue('service-payment-time-unit') || 'hours',
  payment_message: getFieldValue('service-payment-message')
};

  if (Number(payload.price_amount) < 0) {
    await showServicePaymentsMessage('Cena nie może być ujemna.', 'error');
    return;
  }

  if (!payload.payment_time_limit_value || payload.payment_time_limit_value <= 0) {
    payload.payment_time_limit_value = 48;
  }

  const originalText = button.textContent;
  const startTime = Date.now();

  try {
    button.disabled = true;
    button.textContent = 'Zapisywanie...';

    const data = await apiFetch('/api/system/service-settings.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    if (!data?.success) {
      await showServicePaymentsMessage(data?.error || 'Nie udało się zapisać ustawień usługi.', 'error');
      return;
    }

    fillServicePaymentsForm(data.settings || payload);
    servicePaymentsLoaded = true;

    await showServicePaymentsMessage(data.message || 'Ustawienia usługi zostały zapisane.', 'success');
  } catch (error) {
    console.error('saveServicePaymentsSettings error:', error);
    await showServicePaymentsMessage('Błąd połączenia z serwerem.', 'error');
  } finally {
    button.disabled = false;

    if (typeof finishButtonState === 'function') {
      finishButtonState(button, originalText, startTime, 900);
    } else {
      button.textContent = originalText;
    }
  }
}

function getFieldValue(id) {
  const el = document.getElementById(id);
  return el ? String(el.value || '').trim() : '';
}

function setFieldValue(id, value) {
  const el = document.getElementById(id);
  if (!el) return;
  el.value = value;
}

function getCheckboxValue(id) {
  const el = document.getElementById(id);
  return el ? Boolean(el.checked) : false;
}

function setCheckboxValue(id, value) {
  const el = document.getElementById(id);
  if (!el) return;
  el.checked = Boolean(value);
}

async function showServicePaymentsMessage(message, type = 'success') {
  if (typeof openAdminConfirm === 'function') {
    await openAdminConfirm({
      title: type === 'success' ? 'Dobra wiadomość' : 'Błąd',
      message,
      confirmText: 'OK',
      cancelText: 'Zamknij',
      variant: type === 'success' ? 'primary' : 'danger',
      icon: type === 'success' ? '✅' : '⚠️'
    });

    return;
  }

  console[type === 'success' ? 'log' : 'error'](message);
}