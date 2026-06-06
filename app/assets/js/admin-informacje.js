(function () {
  const PRO_PLAN_PRICES_ENDPOINT = '/api/system/subscription-plan-prices.php';
  const PRO_UPGRADE_PAYMENT_MESSAGE = 'Płatność za plan Pro będzie dostępna w kolejnym kroku wdrożenia.';

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
      yearly: 'Roczny',
      annual: 'Roczny',
      manual: 'Ustalany indywidualnie'
    };

    return map[value] || value || '—';
  }

  function isFreePlan(planCode) {
    return String(planCode || '').trim().toLowerCase() === 'free';
  }

  function formatCurrentPeriod(subscription) {
    const start = subscription?.current_period_start;
    const end = subscription?.current_period_end;

    if (isFreePlan(subscription?.plan_code)) {
      return start ? `Od ${formatDate(start)}` : 'Bezterminowo';
    }

    return `${formatDate(start)} – ${formatDate(end)}`;
  }

  function renderFreeSubscription(subscription = {}) {
    setText('info-plan-name', subscription.plan_name || 'Free');
    setText('info-billing-period', 'Nie dotyczy');
    setText('info-next-payment', '—');
    setText('info-amount', formatMoney(subscription.amount ?? 0, subscription.currency || 'PLN'));
    setText('info-status', formatSubscriptionStatus(subscription.status || 'active'));
    setText('info-current-period', formatCurrentPeriod({
      ...subscription,
      plan_code: 'free'
    }));
    setText('info-grace-period', 'Nie dotyczy');
  }

  function normalizePlanCode(planCode) {
    return String(planCode || '').trim().toLowerCase();
  }

  function resolveVisiblePlanCode(subscription, planContext) {
    if (planContext?.plan_code) {
      return normalizePlanCode(planContext.plan_code);
    }

    if (subscription?.plan_code) {
      return normalizePlanCode(subscription.plan_code);
    }

    return normalizePlanCode(planContext?.subscription_plan_code);
  }

  function setProUpgradeMessage(message, type = 'info') {
    const messageEl = document.getElementById('pro-upgrade-message');
    if (!messageEl) return;

    messageEl.textContent = message || '';
    messageEl.hidden = !message;
    messageEl.classList.remove('info', 'error');

    if (message) {
      messageEl.classList.add(type);
    }
  }

  function setProUpgradeButtonState(isEnabled) {
    const button = document.getElementById('pro-upgrade-btn');
    if (!button) return;

    button.disabled = !isEnabled;
  }

  function setProUpgradeOptionsState(isEnabled) {
    document.querySelectorAll('input[name="pro-upgrade-period"]').forEach(input => {
      input.disabled = !isEnabled;
    });
  }

  function hideProUpgradeSection() {
    const card = document.getElementById('pro-upgrade-card');
    if (card) {
      card.hidden = true;
    }
  }

  function showProUpgradeSection() {
    const card = document.getElementById('pro-upgrade-card');
    if (card) {
      card.hidden = false;
    }
  }

  function showProUpgradeUnavailable(message) {
    showProUpgradeSection();
    setText('pro-price-monthly', '—');
    setText('pro-price-yearly', '—');
    setProUpgradeOptionsState(false);
    setProUpgradeButtonState(false);
    setProUpgradeMessage(message, 'error');
  }

  function normalizePlanPrice(row) {
    const billingPeriod = String(row?.billing_period || '').trim().toLowerCase();
    const planCode = String(row?.plan_code || '').trim().toLowerCase();
    const amount = row?.amount;

    if (
      planCode !== 'pro'
      || !['monthly', 'yearly'].includes(billingPeriod)
      || row?.is_active !== true
      || amount === null
      || amount === undefined
      || amount === ''
    ) {
      return null;
    }

    return {
      plan_code: planCode,
      plan_name: row?.plan_name || 'Pro',
      billing_period: billingPeriod,
      amount,
      currency: row?.currency || 'PLN',
      is_active: true
    };
  }

  function getProPricesByPeriod(prices) {
    return (Array.isArray(prices) ? prices : [])
      .map(normalizePlanPrice)
      .filter(Boolean)
      .reduce((acc, item) => {
        acc[item.billing_period] = item;
        return acc;
      }, {});
  }

  function renderProUpgradePrices(prices) {
    const pricesByPeriod = getProPricesByPeriod(prices);
    const monthly = pricesByPeriod.monthly;
    const yearly = pricesByPeriod.yearly;

    if (!monthly || !yearly) {
      showProUpgradeUnavailable('Nie udało się pobrać aktualnej ceny planu Pro. Spróbuj ponownie później.');
      return;
    }

    showProUpgradeSection();
    setText('pro-price-monthly', formatMoney(monthly.amount, monthly.currency));
    setText('pro-price-yearly', formatMoney(yearly.amount, yearly.currency));
    setProUpgradeOptionsState(true);
    setProUpgradeButtonState(true);
    setProUpgradeMessage('', 'info');

    const selected = document.querySelector('input[name="pro-upgrade-period"]:checked');
    if (!selected) {
      const monthlyInput = document.querySelector('input[name="pro-upgrade-period"][value="monthly"]');
      if (monthlyInput) {
        monthlyInput.checked = true;
      }
    }
  }

  async function loadProUpgradePrices() {
    showProUpgradeSection();
    setProUpgradeOptionsState(false);
    setProUpgradeButtonState(false);
    setProUpgradeMessage('Pobieranie aktualnej ceny planu Pro...', 'info');

    try {
      const res = await fetch(PRO_PLAN_PRICES_ENDPOINT, {
        method: 'GET',
        credentials: 'include',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json'
        }
      });

      const text = await res.text();
      let data = null;

      try {
        data = JSON.parse(text);
      } catch {
        throw new Error('Serwer zwrócił nieprawidłową odpowiedź.');
      }

      if (!res.ok || data?.success !== true) {
        throw new Error(data?.error || 'Nie udało się pobrać aktualnej ceny planu Pro.');
      }

      renderProUpgradePrices(data.prices || []);
    } catch (error) {
      console.error('pro plan prices load error:', error);
      showProUpgradeUnavailable('Nie udało się pobrać aktualnej ceny planu Pro. Spróbuj ponownie później.');
    }
  }

  function renderProUpgradeSection(subscription, planContext) {
    const planCode = resolveVisiblePlanCode(subscription, planContext);

    if (planCode !== 'free') {
      hideProUpgradeSection();
      return;
    }

    loadProUpgradePrices();
  }

  async function handleProUpgradeClick() {
    setProUpgradeMessage(PRO_UPGRADE_PAYMENT_MESSAGE, 'info');

    if (typeof openAdminConfirm === 'function') {
      await openAdminConfirm({
        title: 'Plan Pro',
        message: PRO_UPGRADE_PAYMENT_MESSAGE,
        confirmText: 'OK',
        cancelText: 'Zamknij',
        variant: 'primary',
        icon: 'i'
      });
    }
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

    if (!phone) {
      return false;
    }

    if (!/^\+?[0-9\s-]+$/.test(phone)) {
      return false;
    }

    if ((phone.match(/\+/g) || []).length > 1) {
      return false;
    }

    if (phone.includes('+') && !phone.startsWith('+')) {
      return false;
    }

    const digits = phone.replace(/\D+/g, '');

    if (phone.startsWith('+48')) {
      return digits.length === 11 && digits.startsWith('48');
    }

    if (phone.startsWith('+')) {
      return false;
    }

    return digits.length === 9;
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
      return 'Podaj poprawny telefon firmowy.';
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
      const planContext = data.plan_context || {};

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
        if (isFreePlan(subscription.plan_code)) {
          renderFreeSubscription(subscription);
          renderProUpgradeSection(subscription, planContext);
          return;
        }

        setText('info-plan-name', subscription.plan_name);
        setText('info-billing-period', formatBillingPeriod(subscription.billing_period));
        setText('info-next-payment', formatDate(subscription.next_payment_due_at));
        setText('info-amount', formatMoney(subscription.amount, subscription.currency));
        setText('info-status', formatSubscriptionStatus(subscription.status));
        setText('info-current-period', formatCurrentPeriod(subscription));
        setText('info-grace-period', `${subscription.grace_period_days ?? 0} dni`);
        renderProUpgradeSection(subscription, planContext);
      } else {
        const fallbackPlanName = planContext.plan_name || (planContext.plan_code === 'free' ? 'Free' : 'Brak danych abonamentu');
        const fallbackStatus = planContext.plan_code === 'free'
          ? 'Aktywny'
          : formatSubscriptionStatus(planContext.status);

        if (isFreePlan(planContext.plan_code)) {
          renderFreeSubscription({
            plan_code: 'free',
            plan_name: 'Free',
            status: 'active',
            amount: 0,
            currency: 'PLN'
          });
          renderProUpgradeSection(subscription, planContext);
          return;
        }

        setText('info-plan-name', fallbackPlanName);
        setText('info-billing-period', '—');
        setText('info-next-payment', '—');
        setText('info-amount', '—');
        setText('info-status', fallbackStatus || 'Nie ustawiono');
        setText('info-current-period', '—');
        setText('info-grace-period', '—');
        renderProUpgradeSection(subscription, planContext);
      }
    } catch (error) {
      console.error('account info load error:', error);

      setText('info-plan-name', 'Błąd pobierania danych');
      setText('info-status', error.message || 'Błąd');
      hideProUpgradeSection();
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    loadAccountInfo();

    const saveButton = document.getElementById('save-company-contact-btn');
    if (saveButton) {
      saveButton.addEventListener('click', () => saveCompanyContact(saveButton));
    }

    const proUpgradeButton = document.getElementById('pro-upgrade-btn');
    if (proUpgradeButton) {
      proUpgradeButton.addEventListener('click', handleProUpgradeClick);
    }
  });
})();
