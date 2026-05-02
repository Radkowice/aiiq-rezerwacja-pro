function getPaymentReturnEl(id) {
  return document.getElementById(id);
}

function getPaymentReturnBookingId() {
  const params = new URLSearchParams(window.location.search);
  return String(params.get('booking_id') || '').trim();
}

function formatPaymentReturnDate(dateStr) {
  if (!dateStr) return '—';

  const date = new Date(`${dateStr}T00:00:00`);

  if (Number.isNaN(date.getTime())) {
    return dateStr;
  }

  return new Intl.DateTimeFormat('pl-PL', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  }).format(date);
}

function setPaymentReturnText(id, value, fallback = '—') {
  const el = getPaymentReturnEl(id);
  if (!el) return;

  const text = String(value || '').trim();
  el.textContent = text || fallback;
}

function setPaymentReturnFavicon(faviconUrl) {
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

function setPaymentReturnLogo(logoUrl, clientName) {
  const logoEl = getPaymentReturnEl('paymentReturnLogo');
  const fallbackEl = getPaymentReturnEl('paymentReturnLogoFallback');

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

function applyPaymentReturnStatus(paymentStatus) {
  const titleEl = getPaymentReturnEl('paymentReturnTitle');
  const leadEl = document.querySelector('.payment-return-lead');

  const status = String(paymentStatus || '').trim();

  if (status === 'paid') {
    if (titleEl) titleEl.textContent = 'Płatność została potwierdzona';
    if (leadEl) {
      leadEl.textContent = 'Twoja rezerwacja została opłacona i potwierdzona. Dziękujemy.';
    }
    return;
  }

  if (status === 'failed' || status === 'cancelled') {
    if (titleEl) titleEl.textContent = 'Płatność nie została zakończona';
    if (leadEl) {
      leadEl.textContent = 'Twoja rezerwacja została przyjęta, ale płatność nie została poprawnie zakończona.';
    }
    return;
  }

  if (status === 'expired') {
    if (titleEl) titleEl.textContent = 'Termin płatności minął';
    if (leadEl) {
      leadEl.textContent = 'Twoja rezerwacja nie została opłacona w wymaganym czasie.';
    }
    return;
  }

  if (titleEl) {
    titleEl.textContent = 'Twoja płatność jest przetwarzana';
  }

  if (leadEl) {
    leadEl.textContent = 'Twoja rezerwacja została przyjęta, a status płatności zostanie zaktualizowany po potwierdzeniu przez PayU.';
  }
}

async function loadPaymentReturnData() {
  const bookingId = getPaymentReturnBookingId();

  if (!bookingId) {
    applyPaymentReturnStatus('pending');
    setPaymentReturnText('paymentReturnServiceName', 'Nie znaleziono numeru rezerwacji');
    return;
  }

  try {
    const res = await fetch(`/api/payments/payment-return-status.php?booking_id=${encodeURIComponent(bookingId)}`, {
      cache: 'no-store'
    });

    const data = await res.json();

    if (!res.ok || data.success !== true) {
      console.warn('Nie udało się pobrać danych płatności:', data);
      setPaymentReturnText('paymentReturnServiceName', 'Rezerwacja');
      return;
    }

    const booking = data.booking || {};
    const bookingName = String(booking.name || '').trim();
    const branding = data.branding || {};
    const service = data.service || {};
    
    
    const serviceName = String(service.service_name || '').trim()
      || String(branding.client_name || '').trim()
      || 'Rezerwacja';
      
      if (bookingName) {
  setPaymentReturnText('paymentReturnThanks', `Dziękujemy, ${bookingName}`);
}

    document.title = `${serviceName} — płatność`;

    setPaymentReturnLogo(branding.logo_url_front, branding.client_name);
    setPaymentReturnFavicon(branding.favicon_url_front);

    setPaymentReturnText('paymentReturnServiceName', serviceName);
    setPaymentReturnText('paymentReturnDate', formatPaymentReturnDate(booking.booking_date));
    setPaymentReturnText('paymentReturnTime', booking.booking_time);

    applyPaymentReturnStatus(booking.payment_status);

  } catch (e) {
    console.error('loadPaymentReturnData error:', e);
    setPaymentReturnText('paymentReturnServiceName', 'Rezerwacja');
  }
}

document.addEventListener('DOMContentLoaded', loadPaymentReturnData);