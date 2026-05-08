let currentBookingsView = 'upcoming';

const BOOKING_VIEWS = {
  upcoming: 'Nadchodzące',
  today: 'Dzisiejsze',
  past: 'Historia',
  all: 'Wszystkie'
};

function isValidBookingsView(view) {
  return Object.prototype.hasOwnProperty.call(BOOKING_VIEWS, view);
}

document.addEventListener('DOMContentLoaded', async () => {
  if (typeof checkAuth === 'function') {
    checkAuth();
    checkSystemStatus();
  }

   initMenu();
  initSidebar();
  initTopbarActions();
  initBookingFilters();
  initCalendarEnabledToggle();

  try {
    const accountReady = window.adminAccountDataReady || Promise.resolve();

    await Promise.all([
      accountReady,
      loadBookings(currentBookingsView)
    ]);
  } finally {
    if (window.AppLoader) {
      window.AppLoader.hide();
    }
  }
});

function initMenu() {
  const menuItems = document.querySelectorAll('.menu-item');
  const sections = document.querySelectorAll('[data-section]');

  const sectionMap = [
    'rezerwacje',
    'blokady',
    'usluga-platnosci',
    'email',
    'integracje',
    'dokumenty_prawne',
    'informacje',
    'ustawienia',
    'moje_konto'
  ];

  menuItems.forEach((btn, index) => {
    btn.addEventListener('click', () => {
      menuItems.forEach(item => item.classList.remove('active'));
      btn.classList.add('active');

      sections.forEach(section => section.classList.add('hidden'));

      const targetSection = sectionMap[index];
      const targetEl = document.querySelector(`[data-section="${targetSection}"]`);

      if (targetEl) {
        targetEl.classList.remove('hidden');
      }
    });
  });
}

function initSidebar() {
  const toggleBtn = document.getElementById('sidebarToggle');
  const sidebar = document.querySelector('.sidebar');
  const layout = document.querySelector('.admin-layout');

  if (!toggleBtn || !sidebar || !layout) return;

  toggleBtn.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    layout.classList.toggle('sidebar-collapsed');
  });
}

function initTopbarActions() {
  const logoutBtn = document.getElementById('logoutBtn');
  const refreshBtn = document.getElementById('refreshBtn');

  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      try {
        const res = await fetch('/api/auth/logout.php', {
          method: 'POST',
          credentials: 'include'
        });

        const data = await res.json();

        if (data.success) {
          window.location.href = '/logowanie.html';
          return;
        }

        alert(data.error || 'Błąd wylogowania');
      } catch (error) {
        console.error('logout error:', error);
        alert('Błąd serwera przy wylogowaniu');
      }
    });
  }

   if (refreshBtn) {
    refreshBtn.addEventListener('click', async () => {
      await loadBookings(currentBookingsView);
    });
  }
}

function initBookingFilters() {
  const bookingList = document.getElementById('bookingList');
  if (!bookingList) return;

  const table = bookingList.closest('table');
  if (!table) return;

  if (document.getElementById('bookingViewFilters')) return;

  const toolbar = document.createElement('div');
  toolbar.id = 'bookingViewFilters';
  toolbar.className = 'booking-view-toolbar';

 toolbar.innerHTML = `
  <div class="booking-view-top">
    <div class="booking-view-tabs" role="group" aria-label="Filtr rezerwacji">
      ${Object.entries(BOOKING_VIEWS).map(([view, label]) => `
        <button
          type="button"
          class="booking-view-btn${view === currentBookingsView ? ' active' : ''}"
          data-view="${escapeHtml(view)}"
        >
          ${escapeHtml(label)}
        </button>
      `).join('')}
    </div>

    <a
      class="booking-history-download-btn"
      href="/api/booking/export-history.php"
      target="_blank"
      rel="noopener"
    >
      Pobierz historię CSV
    </a>
  </div>

  <p class="booking-retention-info">
    Historia rezerwacji jest przechowywana przez 1 miesiąc.
    Starsze rezerwacje zostaną automatycznie usunięte.
    Przed usunięciem pobierz historię do pliku CSV.
  </p>
`;

  table.parentNode.insertBefore(toolbar, table);

  toolbar.querySelectorAll('.booking-view-btn').forEach(button => {
    button.addEventListener('click', async () => {
      const view = button.dataset.view || 'upcoming';

      if (!isValidBookingsView(view)) return;

      currentBookingsView = view;

      toolbar.querySelectorAll('.booking-view-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.view === currentBookingsView);
      });

      await loadBookings(currentBookingsView);
    });
  });
}

function updateCalendarEnabledUi(isEnabled) {
  const toggle = document.getElementById('calendar-enabled-toggle');
  const label = document.getElementById('calendar-enabled-label');

  if (toggle) {
    toggle.checked = isEnabled === true;
  }

  if (label) {
    label.textContent = isEnabled === true
      ? 'Kalendarz włączony'
      : 'Kalendarz wyłączony';
  }
}

function showCalendarEnabledMessage(message, type = '') {
  const messageEl = document.getElementById('calendar-enabled-message');
  if (!messageEl) return;

  messageEl.textContent = message || '';
  messageEl.classList.remove('success', 'error');

  if (type) {
    messageEl.classList.add(type);
  }

  if (message) {
    setTimeout(() => {
      messageEl.textContent = '';
      messageEl.classList.remove('success', 'error');
    }, 3500);
  }
}

async function initCalendarEnabledToggle() {
  const toggle = document.getElementById('calendar-enabled-toggle');
  const saveBtn = document.getElementById('save-calendar-enabled-btn');

  if (!toggle || !saveBtn) return;

  try {
    const data = await apiFetch('/api/system/settings.php', {
      cache: 'no-store'
    });

    const isEnabled = data?.settings?.calendar_enabled === true;
    updateCalendarEnabledUi(isEnabled);
  } catch (error) {
    console.error('calendar enabled load error:', error);
    showCalendarEnabledMessage('Nie udało się pobrać statusu kalendarza', 'error');
  }

  toggle.addEventListener('change', () => {
    updateCalendarEnabledUi(toggle.checked);
  });

  saveBtn.addEventListener('click', async () => {
    const startTime = Date.now();
    const defaultText = 'Zapisz status kalendarza';

    try {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Zapisywanie statusu...';

      const data = await apiFetch('/api/system/settings.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          calendar_enabled: toggle.checked
        })
      });

      if (!data?.success) {
        showCalendarEnabledMessage(data?.error || 'Nie udało się zapisać statusu kalendarza', 'error');
        return;
      }

      updateCalendarEnabledUi(toggle.checked);
      showCalendarEnabledMessage(
        toggle.checked ? 'Kalendarz został włączony' : 'Kalendarz został wyłączony',
        'success'
      );
    } catch (error) {
      console.error('calendar enabled save error:', error);
      showCalendarEnabledMessage('Błąd zapisu statusu kalendarza', 'error');
    } finally {
      if (typeof finishButtonState === 'function') {
        finishButtonState(saveBtn, defaultText, startTime, 800);
      } else {
        saveBtn.disabled = false;
        saveBtn.textContent = defaultText;
      }
    }
  });
}

function getBookingTimestamp(item) {
  const date = item.booking_date || item.date || '';
  const time = item.booking_time || item.time || '00:00';

  const timestamp = new Date(`${date}T${time}`).getTime();

  return Number.isNaN(timestamp) ? 0 : timestamp;
}

function sortBookingsForView(bookings, view) {
  if (!Array.isArray(bookings)) return [];

  return bookings.sort((a, b) => {
    const aTime = getBookingTimestamp(a);
    const bTime = getBookingTimestamp(b);

    if (view === 'past') {
      return bTime - aTime;
    }

    return aTime - bTime;
  });
}

async function fetchBookingsByView(view) {
  const safeView = isValidBookingsView(view) ? view : 'upcoming';

  return await apiFetch(`/api/booking/bookings.php?view=${encodeURIComponent(safeView)}`, {
    cache: 'no-store'
  });
}

function getEmptyBookingsText(view) {
  if (view === 'today') return 'Brak rezerwacji na dziś';
  if (view === 'past') return 'Brak rezerwacji w historii';
  if (view === 'all') return 'Brak rezerwacji';
  return 'Brak nadchodzących rezerwacji';
}

async function loadBookings(view = currentBookingsView) {
  const bookingList = document.getElementById('bookingList');
  if (!bookingList) return;

  currentBookingsView = isValidBookingsView(view) ? view : 'upcoming';

  bookingList.innerHTML = `
    <tr>
      <td colspan="9" class="empty">Ładowanie danych...</td>
    </tr>
  `;

  try {
    const data = await fetchBookingsByView(currentBookingsView);

    if (!data || !Array.isArray(data)) {
      bookingList.innerHTML = `
        <tr>
          <td colspan="9" class="empty">Nie udało się wczytać danych</td>
        </tr>
      `;
      updateTodayBookingsStat([]);
      renderTodayBookings([]);
      return;
    }

    window._bookingsData = data;

    sortBookingsForView(data, currentBookingsView);

    let todayData = [];

    try {
      todayData = currentBookingsView === 'today'
        ? data
        : await fetchBookingsByView('today');

      if (!Array.isArray(todayData)) {
        todayData = [];
      }

      sortBookingsForView(todayData, 'today');
    } catch (todayError) {
      console.error('today bookings stats error:', todayError);
      todayData = [];
    }

    updateTodayBookingsStat(todayData);
    renderTodayBookings(todayData);

    if (data.length === 0) {
      bookingList.innerHTML = `
        <tr>
          <td colspan="9" class="empty">${escapeHtml(getEmptyBookingsText(currentBookingsView))}</td>
        </tr>
      `;
      return;
    }

    bookingList.innerHTML = data.map(item => {
      const clientName = escapeHtml(item.name || '—');
      const contact = `
        <div>${escapeHtml(item.email || '—')}</div>
        <div>${escapeHtml(item.phone || '—')}</div>
      `;

      const bookingDate = escapeHtml(item.booking_date || item.date || '—');
      const bookingTime = escapeHtml(item.booking_time || item.time || '—');

      const description = escapeHtml(
        item.description ||
        item.message ||
        item.notes ||
        item.opis ||
        ''
      );

      const bookingId = String(item.id ?? '');
      const payment = renderPaymentBadge(item);
      const paymentRowClass = getPaymentRowClass(item);

      return `
        <tr class="${paymentRowClass}">
          <td class="col-name">${clientName}</td>
          <td class="col-contact">${contact}</td>
          <td class="col-date">${bookingDate}</td>
          <td class="col-time">${bookingTime}</td>
          <td class="col-desc">${description || '—'}</td>
          <td class="col-payment">${payment}</td>
          <td class="col-actions">
            <button
              class="delete-btn"
              type="button"
              onclick="deleteBooking('${escapeJs(bookingId)}','${escapeJs(bookingDate)}','${escapeJs(bookingTime)}','${escapeJs(item.status || '')}','${escapeJs(item.payment_status || '')}')"
            >
              Usuń
            </button>
          </td>
        </tr>
      `;
    }).join('');
  } catch (error) {
    console.error('loadBookings error:', error);

    bookingList.innerHTML = `
      <tr>
        <td colspan="9" class="empty">Nie udało się wczytać danych</td>
      </tr>
    `;

    updateTodayBookingsStat([]);
    renderTodayBookings([]);
  }
}

async function deleteBooking(id, date, time, bookingStatus = '', paymentStatus = '') {
  if (!id) {
    alert('Brak identyfikatora rezerwacji');
    return;
  }

  const isPaymentOverdue = bookingStatus === 'payment_overdue' || paymentStatus === 'expired';

  const confirmHtml = isPaymentOverdue
    ? `
      <p>Ta rezerwacja nie została opłacona w wyznaczonym czasie.</p>
      <p>
        Usunięcie rezerwacji zwolni ten termin w kalendarzu
        i umożliwi innemu klientowi dokonanie rezerwacji.
      </p>
      <p>
        Czy na pewno chcesz usunąć rezerwację z dnia
        <strong>${date}</strong> o godzinie <strong>${time}</strong>
        i zwolnić termin?
      </p>
    `
    : `Czy na pewno chcesz usunąć rezerwację z dnia <strong>${date}</strong> o godzinie <strong>${time}</strong>?`;

  const confirmed = await openAdminConfirm({
    title: isPaymentOverdue ? 'Usuń nieopłaconą rezerwację' : 'Usuń rezerwację',
    html: confirmHtml,
    confirmText: isPaymentOverdue ? 'Usuń i zwolnij termin' : 'Usuń',
    cancelText: 'Anuluj'
  });

  if (!confirmed) return;

  try {
    const data = await apiFetch('/api/booking/delete.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ id })
    });

    if (!data?.success) {
      alert(data?.error || 'Nie udało się usunąć rezerwacji');
      return;
    }

    await loadBookings(currentBookingsView);

    if (typeof window.refreshAdminCalendarData === 'function') {
      await window.refreshAdminCalendarData();
    }
  } catch (error) {
    console.error('deleteBooking error:', error);
    alert('Błąd serwera przy usuwaniu rezerwacji');
  }
}

function updateTodayBookingsStat(bookings = []) {
  const statToday = document.getElementById('stat-today');
  if (!statToday) return;

  const now = new Date();
const today = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;

  const todayCount = Array.isArray(bookings)
    ? bookings.filter(item => {
        const itemDate = item.booking_date || item.date || '';
        return itemDate === today;
      }).length
    : 0;

  statToday.textContent = String(todayCount);
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function escapeJs(value) {
  return String(value)
    .replaceAll('\\', '\\\\')
    .replaceAll("'", "\\'")
    .replaceAll('"', '\\"')
    .replaceAll('\n', '\\n')
    .replaceAll('\r', '\\r')
    .replaceAll('</', '<\\/');
}

function renderPaymentBadge(item) {
  const status = String(item.payment_status || 'not_required');
  const required = item.payment_required === true || item.payment_required === 'true';
  const amount = item.payment_amount;
    const currency = item.payment_currency === 'PLN' ? 'zł' : (item.payment_currency || '');

  let label = 'NIE WYMAGA';
  let className = 'not-required';

  if (required) {
    if (status === 'paid') {
      label = 'ZAPŁACONO';
      className = 'paid';
    } else if (status === 'pending') {
      label = 'OCZEKUJE';
      className = 'pending';
    } else if (status === 'expired') {
      label = 'NIE OPŁACONO';
      className = 'expired';
    } else if (status === 'failed') {
      label = 'NIEUDANA';
      className = 'failed';
    } else if (status === 'cancelled') {
      label = 'ANULOWANA';
      className = 'cancelled';
    }
  }

  const amountText = amount !== null && amount !== undefined && Number(amount) > 0
    ? ` <small>${escapeHtml(Number(amount).toFixed(2).replace('.', ','))} ${escapeHtml(currency)}</small>`
    : '';

  return `<span class="payment-badge ${className}">${label}${amountText}</span>`;
}

function getPaymentRowClass(item) {
  const status = String(item.payment_status || 'not_required');

  if (status === 'expired') {
    return ' payment-row-expired';
  }

  return '';
}

function renderTodayBookings(bookings = []) {
  const container = document.getElementById('todayBookings');
  if (!container) return;

  const now = new Date();
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

  const todayList = bookings.filter(item => {
    const d = item.booking_date || item.date || '';
    return d === today;
  });

  if (todayList.length === 0) {
    container.innerHTML = `<div class="empty">Brak rezerwacji na dziś</div>`;
    return;
  }

  container.innerHTML = todayList.map(item => {
    const time = escapeHtml(item.booking_time || item.time || '--:--');
    const name = escapeHtml(item.name || 'Klient');
    const desc = escapeHtml(
      item.description ||
      item.message ||
      item.notes ||
      item.opis ||
      ''
    );

    return `
      <div class="today-item">
        <strong>${time}</strong> – ${name}
        ${desc ? `<div class="today-desc">${desc}</div>` : ''}
      </div>
    `;
  }).join('');
}

async function checkSystemStatus() {
  const el = document.getElementById('stat-system');
  if (!el) return;

  let okCount = 0;
  let total = 2;

  try {
    const bookings = await fetch('/api/booking/bookings.php');
    if (bookings.ok) okCount++;
  } catch {}

  try {
    const blocked = await fetch('/api/booking/blocked.php');
    if (blocked.ok) okCount++;
  } catch {}

   // 🔥 LOGIKA STATUSU
  if (okCount === total) {
    el.innerText = 'OK';
    el.style.color = '#16a34a'; // zielony
  } else if (okCount > 0) {
    el.innerText = 'Coś się dzieje!!!';
    el.style.color = '#f59e0b'; // pomarańcz
  } else {
    el.innerText = 'AWARIA!!!';
    el.style.color = '#dc2626'; // czerwony
  }
}
