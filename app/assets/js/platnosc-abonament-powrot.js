function getSubscriptionReturnEl(id) {
  return document.getElementById(id);
}

function getSubscriptionPaymentId() {
  const params = new URLSearchParams(window.location.search);
  return String(params.get('payment_id') || '').trim();
}

function setSubscriptionText(id, value, fallback = '—') {
  const el = getSubscriptionReturnEl(id);
  if (!el) return;

  const text = String(value || '').trim();
  el.textContent = text || fallback;
}

function getSubscriptionCompanyName(company) {
  return String(company?.company_full_name || company?.company_name || company?.client_name || '').trim();
}

function setSubscriptionLogo(logoUrl, clientName) {
  const logoEl = getSubscriptionReturnEl('subscriptionReturnLogo');
  const fallbackEl = getSubscriptionReturnEl('subscriptionReturnLogoFallback');
  const cleanLogo = String(logoUrl || '').trim();
  const cleanClientName = String(clientName || '').trim();

  if (cleanLogo && logoEl) {
    logoEl.src = cleanLogo;
    logoEl.alt = cleanClientName || 'Logo';
    logoEl.classList.remove('hidden');

    if (fallbackEl) {
      fallbackEl.classList.add('hidden');
    }

    return;
  }

  if (fallbackEl) {
    fallbackEl.textContent = cleanClientName || 'AI-IQ';
    fallbackEl.classList.remove('hidden');
  }

  if (logoEl) {
    logoEl.classList.add('hidden');
  }
}

function setSubscriptionFavicon(faviconUrl) {
  const cleanUrl = String(faviconUrl || '').trim();

  if (!cleanUrl) return;

  let faviconEl = document.querySelector('link[rel="icon"]');

  if (!faviconEl) {
    faviconEl = document.createElement('link');
    faviconEl.rel = 'icon';
    document.head.appendChild(faviconEl);
  }

  faviconEl.href = cleanUrl;
}

function periodText(label) {
  return label && label !== '—' ? ` na ${label}` : '';
}

function companyText(clientName) {
  return clientName ? ` dla firmy ${clientName}` : '';
}

function applySubscriptionReturnText(payment, company) {
  const titleEl = getSubscriptionReturnEl('subscriptionReturnTitle');
  const leadEl = getSubscriptionReturnEl('subscriptionReturnLead');
  const messageEl = getSubscriptionReturnEl('subscriptionReturnMessage');
  const clientName = getSubscriptionCompanyName(company);
  const period = String(payment?.billing_period_label || '').trim();
  const status = String(payment?.status || '').trim();
  const paymentType = String(payment?.payment_type || '').trim();
  const paymentTypeLabel = String(payment?.payment_type_label || '').trim();

  if (titleEl) {
    titleEl.textContent = 'Dziękujemy';
  }

  if (leadEl) {
    leadEl.textContent = `Płatność za dostęp do planu Pro${periodText(period)}${companyText(clientName)} została przekazana do PayU.`;
  }

  if (messageEl) {
    if (status === 'failed' || status === 'cancelled') {
      messageEl.textContent = 'PayU nie potwierdziło tej płatności jako zakończonej. Plan Pro nie został aktywowany ani przedłużony na podstawie tego powrotu.';
      return;
    }

    messageEl.textContent = 'System oczekuje na potwierdzenie płatności przez PayU. Plan Pro zostanie aktywowany albo przedłużony dopiero po potwierdzeniu płatności.';
  }

  setSubscriptionText('subscriptionReturnPeriod', period || '—');
  setSubscriptionText('subscriptionReturnCompany', clientName || '—');
  setSubscriptionText(
    'subscriptionReturnType',
    paymentTypeLabel || (paymentType === 'subscription_renewal' ? 'Przedłużenie Pro' : 'Przejście na Pro')
  );
}

function applyMissingSubscriptionPaymentIdText() {
  const titleEl = getSubscriptionReturnEl('subscriptionReturnTitle');
  const leadEl = getSubscriptionReturnEl('subscriptionReturnLead');
  const messageEl = getSubscriptionReturnEl('subscriptionReturnMessage');

  if (titleEl) {
    titleEl.textContent = 'Nie znaleziono identyfikatora płatności';
  }

  if (leadEl) {
    leadEl.textContent = 'Nie możemy sprawdzić szczegółów tej płatności, ponieważ w adresie brakuje identyfikatora. Wróć do panelu i rozpocznij płatność ponownie albo sprawdź status abonamentu w zakładce Informacje.';
  }

  if (messageEl) {
    messageEl.textContent = 'Ten ekran nie aktywuje planu Pro i nie potwierdza płatności. Status abonamentu możesz sprawdzić w panelu administracyjnym.';
  }

  setSubscriptionText('subscriptionReturnCompany', '—');
  setSubscriptionText('subscriptionReturnPeriod', '—');
  setSubscriptionText('subscriptionReturnType', '—');
}

async function loadSubscriptionReturnData() {
  const paymentId = getSubscriptionPaymentId();

  if (!paymentId) {
    applyMissingSubscriptionPaymentIdText();
    return;
  }

  try {
    const res = await fetch(`/api/subscriptions/payment-return-status.php?payment_id=${encodeURIComponent(paymentId)}`, {
      cache: 'no-store'
    });

    const data = await res.json();

    if (!res.ok || data.success !== true) {
      console.warn('Nie udało się pobrać danych płatności abonamentu.', {
        status: res.status,
        success: data?.success,
        error: data?.error || null
      });
      applySubscriptionReturnText({}, {});
      return;
    }

    const payment = data.payment || {};
    const company = data.company || {};

    document.title = 'Plan Pro — płatność';
    setSubscriptionLogo(company.logo_url_front, getSubscriptionCompanyName(company));
    setSubscriptionFavicon(company.favicon_url_front);
    applySubscriptionReturnText(payment, company);
  } catch (error) {
    console.warn('Błąd frontu podczas pobierania danych płatności abonamentu.', {
      message: error?.message || String(error)
    });
    applySubscriptionReturnText({}, {});
  }
}

document.addEventListener('DOMContentLoaded', loadSubscriptionReturnData);
