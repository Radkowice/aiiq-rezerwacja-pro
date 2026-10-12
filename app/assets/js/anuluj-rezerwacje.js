'use strict';
(() => {
  const info = document.getElementById('bookingInfo');
  const status = document.getElementById('requestStatus');
  const button = document.getElementById('submitCancellationRequest');
  const token = new URLSearchParams(window.location.search).get('token') || '';
  // Avoid leaking bearer tokens through navigation, copy/paste or Referer.
  history.replaceState(null, '', location.pathname);
  if (!/^[a-f0-9]{64}$/i.test(token)) {
    info.textContent = 'Nieprawidłowy link do rezerwacji.';
    return;
  }
  let snapshot = null;
  async function post(path, data) {
    const res = await fetch(path, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(data),
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || payload?.success !== true) throw new Error('Operacja jest niedostępna.');
    return payload;
  }
  (async () => {
    try {
      snapshot = await post('/api/booking/cancel-preview.php', {token});
      info.textContent = `Termin: ${snapshot.booking_date}, godz. ${snapshot.booking_time}. `
        + (snapshot.payment_required
          ? 'Rezerwacja jest związana z płatnością i wymaga weryfikacji firmy.'
          : 'Firma musi rozpatrzyć wniosek.');
      button.disabled = false;
    } catch {
      info.textContent = 'Nie można odczytać rezerwacji. Link mógł wygasnąć.';
    }
  })();
  button.addEventListener('click', async () => {
    if (!snapshot || button.disabled) return;
    if (!window.confirm('Wysłać do firmy prośbę o anulowanie rezerwacji?')) return;
    button.disabled = true;
    status.textContent = 'Wysyłanie wniosku…';
    try {
      const result = await post('/api/booking/cancel-request.php', {
        token,
        expected_booking_date: snapshot.booking_date,
        expected_booking_time: snapshot.booking_time,
      });
      if (result.booking_cancelled !== false) throw new Error('Nieznany wynik.');
      status.textContent = 'Zapisano wniosek o anulowanie. Rezerwacja nadal jest aktywna do czasu decyzji firmy.';
    } catch {
      status.textContent = 'Nie udało się potwierdzić wyniku. Skontaktuj się z firmą.';
      // Do not automatically retry an unknown operation.
    }
  });
})();
