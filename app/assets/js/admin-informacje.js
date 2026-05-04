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
setText('info-company-address', company.company_address);
setText('info-company-email', company.company_email);
setText('info-company-phone', company.company_phone);
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
  });
})();