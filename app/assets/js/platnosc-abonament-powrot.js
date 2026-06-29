function getSubscriptionReturnEl(id) {
  return document.getElementById(id);
}

function setSubscriptionText(id, value, fallback = '—') {
  const el = getSubscriptionReturnEl(id);
  if (!el) return;

  const text = String(value || '').trim();
  el.textContent = text || fallback;
}

function getSubscriptionCompanyName(company) {
  return String(
    company?.company_full_name
    || company?.company_name
    || company?.client_name
    || ''
  ).trim();
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
    logoEl.removeAttribute('src');
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

function getSafeSubscriptionUrl(value) {
  const url = String(value || '').trim();

  if (/^https:\/\/[a-z0-9.-]+\//i.test(url) || /^https:\/\/[a-z0-9.-]+$/i.test(url)) {
    return url;
  }

  if (/^\/[a-z0-9_\-/.?=&%#]+$/i.test(url)) {
    return url;
  }

  return '';
}

function setSubscriptionPrimaryButton(url, label) {
  const button = getSubscriptionReturnEl('subscriptionReturnPrimaryBtn');
  if (!button) return;

  const safeUrl = getSafeSubscriptionUrl(url) || '/logowanie.html';
  button.href = safeUrl;
  button.textContent = label || 'Przejdź do logowania';
}

function companyText(clientName) {
  return clientName ? ` dla firmy ${clientName}` : '';
}

function validityText(validUntilLabel) {
  return validUntilLabel && validUntilLabel !== '—'
    ? ` Abonament ważny do: ${validUntilLabel}.`
    : '';
}

function resolvePrimaryAction(payment, urls) {
  const paymentType = String(payment?.payment_type || '').trim();
  const loginUrl = getSafeSubscriptionUrl(urls?.login_url);
  const panelUrl = getSafeSubscriptionUrl(urls?.panel_url);
  const primaryUrl = getSafeSubscriptionUrl(urls?.primary_url);

  if ((paymentType === 'subscription_upgrade' || paymentType === 'subscription_renewal') && panelUrl) {
    return {
      url: panelUrl,
      label: 'Wróć do panelu'
    };
  }

  return {
    url: loginUrl || primaryUrl || '/logowanie.html',
    label: 'Przejdź do logowania'
  };
}

function applySubscriptionReturnText(payment, company, urls = {}) {
  const titleEl = getSubscriptionReturnEl('subscriptionReturnTitle');
  const leadEl = getSubscriptionReturnEl('subscriptionReturnLead');
  const messageEl = getSubscriptionReturnEl('subscriptionReturnMessage');
  const clientName = getSubscriptionCompanyName(company);
  const validUntilLabel = String(
    payment?.subscription_valid_until_label
    || payment?.valid_until_label
    || ''
  ).trim();
  const status = String(payment?.status || '').trim();
  const paymentType = String(payment?.payment_type || '').trim();
  const paymentTypeLabel = String(payment?.payment_type_label || '').trim();

  if (titleEl) {
    titleEl.textContent = 'Dziękujemy';
  }

  if (leadEl) {
    leadEl.textContent = `Płatność za dostęp do planu Pro${companyText(clientName)} została przekazana do PayU.${validityText(validUntilLabel)}`;
  }

  if (messageEl) {
    if (status === 'failed' || status === 'cancelled' || status === 'canceled') {
      messageEl.textContent = 'PayU nie potwierdziło tej płatności jako zakończonej. Plan Pro nie został aktywowany ani przedłużony na podstawie tego powrotu.';
    } else if (status === 'paid') {
      messageEl.textContent = 'PayU potwierdziło płatność. Jeśli konto wymaga aktywacji, sprawdź wiadomość e-mail z linkiem aktywacyjnym.';
    } else {
      messageEl.textContent = 'System oczekuje na potwierdzenie płatności przez PayU. Plan Pro zostanie aktywowany albo przedłużony dopiero po potwierdzeniu płatności.';
    }
  }

  setSubscriptionText('subscriptionReturnPeriod', validUntilLabel || '—');
  setSubscriptionText('subscriptionReturnCompany', clientName || '—');
  setSubscriptionText(
    'subscriptionReturnType',
    paymentTypeLabel || (paymentType === 'subscription_renewal' ? 'Przedłużenie Pro' : 'Przejście na Pro')
  );

  const primaryAction = resolvePrimaryAction(payment, urls);
  setSubscriptionPrimaryButton(primaryAction.url, primaryAction.label);
}

function applySubscriptionReturnUnavailableText() {
  const titleEl = getSubscriptionReturnEl('subscriptionReturnTitle');
  const leadEl = getSubscriptionReturnEl('subscriptionReturnLead');
  const messageEl = getSubscriptionReturnEl('subscriptionReturnMessage');

  if (titleEl) {
    titleEl.textContent = 'Sprawdzamy status płatności';
  }

  if (leadEl) {
    leadEl.textContent = 'Płatność została przekazana do PayU. Jeśli została zakończona poprawnie, status abonamentu zaktualizuje się automatycznie po potwierdzeniu przez PayU.';
  }

  if (messageEl) {
    messageEl.textContent = 'Ten ekran nie wymaga już identyfikatora płatności w adresie. Szczegóły płatności są sprawdzane bezpiecznie po stronie systemu. Status abonamentu możesz potwierdzić w panelu administracyjnym, w zakładce Informacje.';
  }

  setSubscriptionText('subscriptionReturnCompany', '—');
  setSubscriptionText('subscriptionReturnPeriod', '—');
  setSubscriptionText('subscriptionReturnType', 'Plan Pro');
  setSubscriptionPrimaryButton('/logowanie.html', 'Przejdź do logowania');
}

async function loadSubscriptionReturnData() {
  try {
    const res = await fetch('/api/subscriptions/payment-return-status.php', {
      cache: 'no-store',
      credentials: 'same-origin'
    });

    const data = await res.json().catch(() => null);

    if (!res.ok || data?.success !== true) {
      console.warn('Nie udało się pobrać danych płatności abonamentu.', {
        status: res.status,
        success: data?.success,
        error: data?.error || null
      });
      applySubscriptionReturnUnavailableText();
      return;
    }

    const payment = data.payment || {};
    const company = data.company || {};
    const urls = data.urls || {};
    const companyName = getSubscriptionCompanyName(company);

    document.title = 'Plan Pro — płatność';
    setSubscriptionLogo(company.logo_url_front, companyName);
    setSubscriptionFavicon(company.favicon_url_front);
    applySubscriptionReturnText(payment, company, urls);
  } catch (error) {
    console.warn('Błąd frontu podczas pobierania danych płatności abonamentu.', {
      message: error?.message || String(error)
    });
    applySubscriptionReturnUnavailableText();
  }
}

document.addEventListener('DOMContentLoaded', loadSubscriptionReturnData);
