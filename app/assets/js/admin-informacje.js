(function () {
  function formatDate(value) {
    if (!value) return '—';

    const date = new Date(`${value}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
      return value;
    }

    return date.toLocaleDateString('pl-PL');
  }

  function formatMoney(amount, currency) {
    if (amount === null || amount === undefined || amount === '') {
      return '—';
    }

    const numericAmount = Number(amount);

    if (Number.isNaN(numericAmount)) {
      return `${amount} ${currency || ''}`.trim();
    }

    const displayCurrency = currency === 'PLN' ? 'zł' : (currency || '');

    return `${numericAmount.toFixed(2).replace('.', ',')} ${displayCurrency}`.trim();
  }

  function formatBillingPeriod(value) {
    const map = {
      monthly: 'Miesięczny',
      yearly: 'Roczny'
    };

    return map[value] || value || '—';
  }

  function formatSubscriptionStatus(value) {
    const map = {
      trial: 'Okres próbny',
      active: 'Aktywny',
      payment_due: 'Do zapłaty',
      overdue: 'Po terminie',
      suspended: 'Zawieszony',
      cancelled: 'Anulowany'
    };

    return map[value] || value || '—';
  }

  function setText(id, value) {
    const el = document.getElementById(id);
    if (!el) return;

    el.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
  }

  function setInputValue(id, value) {
    const el = document.getElementById(id);
    if (!el) return;

    el.value = value === null || value === undefined ? '' : String(value);
  }

  function getInputValue(id) {
    const el = document.getElementById(id);
    return el ? String(el.value || '').trim() : '';
  }

  function isValidEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
  }

  function isValidSimplePhone(value) {
    const phone = String(value || '').trim();
    return phone !== '' && /^[0-9\s+\-()]+$/.test(phone);
  }

  async function showCompanyContactMessage(message, type = 'success') {
    if (typeof openAdminConfirm === 'function') {
      await openAdminConfirm({
        title: type === 'success' ? 'Dane zapisane' : 'Błąd',
        message,
        confirmText: 'OK',
        cancelText: 'Zamknij',
        variant: type === 'success' ? 'primary' : 'danger',
        icon: type === 'success' ? '✓' : '!'
      });
      return;
    }

    console[type === 'success' ? 'log' : 'error'](message);
  }

  function getCompanyContactPayload() {
    return {
      section: 'company_contact',
      company_address: getInputValue('info-company-address'),
      company_email: getInputValue('info-company-email'),
      company_phone: getInputValue('info-company-phone')
    };
  }

  function validateCompanyContactPayload(payload) {
    if (!payload.company_address) {
      return 'Adres firmy nie może być pusty.';
    }

    if (!payload.company_email) {
      return 'Email firmowy nie może być pusty.';
    }

    if (!isValidEmail(payload.company_email)) {
      return 'Podaj poprawny email firmowy.';
    }

    if (!payload.company_phone) {
      return 'Telefon firmowy nie może być pusty.';
    }

    if (!isValidSimplePhone(payload.company_phone)) {
      return 'Telefon firmowy może zawierać tylko cyfry, spacje, plus, minus i nawiasy.';
    }

    return '';
  }

  async function saveCompanyContact(button) {
    const payload = getCompanyContactPayload();
    const validationError = validateCompanyContactPayload(payload);

    if (validationError) {
      await showCompanyContactMessage(validationError, 'error');
      return;
    }

    const originalText = button ? button.textContent : '';
    const startTime = Date.now();

    try {
      if (button) {
        button.disabled = true;
        button.textContent = 'Zapisywanie...';
      }

      const res = await fetch('/api/system/service-settings.php', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      const text = await res.text();
      let data = null;

      try {
        data = JSON.parse(text);
      } catch {
        throw new Error('Serwer zwrócił nieprawidłową odpowiedź.');
      }

      if (!res.ok || data?.success !== true) {
        throw new Error(data?.error || 'Nie udało się zapisać danych firmy.');
      }

      const settings = data.settings || payload;
      setInputValue('info-company-address', settings.company_address || payload.company_address);
      setInputValue('info-company-email', settings.company_email || payload.company_email);
      setInputValue('info-company-phone', settings.company_phone || payload.company_phone);

      await showCompanyContactMessage('Dane firmy zostały zapisane.', 'success');
    } catch (error) {
      console.error('company contact save error:', error);
      await showCompanyContactMessage(error.message || 'Nie udało się zapisać danych firmy.', 'error');
    } finally {
      if (button) {
        button.disabled = false;

        if (typeof finishButtonState === 'function') {
          finishButtonState(button, originalText, startTime, 900);
        } else {
          button.textContent = originalText;
        }
      }
    }
  }

  async function loadAccountInfo() {
    try {
      const res = await fetch('/api/system/account-info.php', {
        method: 'GET',
        credentials: 'include',
        cache: 'no-store'
      });

      const text = await res.text();

      let data;
      try {
        data = JSON.parse(text);
      } catch {
        throw new Error('Serwer zwrócił nieprawidłową odpowiedź.');
      }

      if (!res.ok || data.success !== true) {
        throw new Error(data.error || 'Nie udało się pobrać informacji.');
      }

      const user = data.user || {};
      const branding = data.branding || {};
      const company = data.company || {};
      const subscription = data.subscription || null;

      setText('info-company-name', branding.client_name);
      setText('info-company-full-name', company.company_full_name);
      setText('info-company-owner-name', company.company_owner_name);
      setText('info-company-tax-id', company.company_tax_id);
      setInputValue('info-company-address', company.company_address);
      setInputValue('info-company-email', company.company_email);
      setInputValue('info-company-phone', company.company_phone);
      setText('info-client-number', branding.client_number);
      setText('info-company-id', branding.company_id);
      setText('info-tenant-id', branding.tenant_id || user.tenant_id);
      setText('info-user-email', user.email);
      setText('info-user-role', user.role);

      if (subscription) {
        setText('info-plan-name', subscription.plan_name);
        setText('info-billing-period', formatBillingPeriod(subscription.billing_period));
        setText('info-next-payment', formatDate(subscription.next_payment_due_at));
        setText('info-amount', formatMoney(subscription.amount, subscription.currency));
        setText('info-status', formatSubscriptionStatus(subscription.status));
        setText('info-current-period', `${formatDate(subscription.current_period_start)} – ${formatDate(subscription.current_period_end)}`);
        setText('info-grace-period', `${subscription.grace_period_days ?? 0} dni`);
      } else {
        setText('info-plan-name', 'Brak danych abonamentu');
        setText('info-billing-period', '—');
        setText('info-next-payment', '—');
        setText('info-amount', '—');
        setText('info-status', 'Nie ustawiono');
        setText('info-current-period', '—');
        setText('info-grace-period', '—');
      }
    } catch (error) {
      console.error('account info load error:', error);

      setText('info-plan-name', 'Błąd pobierania danych');
      setText('info-status', error.message || 'Błąd');
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    loadAccountInfo();

    const saveButton = document.getElementById('save-company-contact-btn');
    if (saveButton) {
      saveButton.addEventListener('click', () => saveCompanyContact(saveButton));
    }
  });
})();