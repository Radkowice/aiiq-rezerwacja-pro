let currentBookingsView = 'upcoming';
const ADMIN_NOTIFICATIONS_ENDPOINT = '/api/system/admin-notifications.php';
const PLAN_LOCKED_SECTIONS = {
  personel: {
    featureKey: 'staff_module',
    title: 'Dostępne w wersji Pro'
  },
  integracje: {
    featureKey: 'online_payments',
    title: 'Dostępne w wersji Pro'
  },
  dokumenty_prawne: {
    featureKey: 'legal_documents',
    title: 'Dostępne w wersji Pro'
  }
};
const PLAN_LOCKED_ELEMENTS = [
  {
    selector: '.service-manager-layout',
    featureKey: 'multiple_services',
    title: 'Dostępne w wersji Pro'
  },
  {
    selector: '.plan-payments-pro-group',
    featureKey: 'online_payments',
    title: 'Dostępne w wersji Pro'
  },
  {
    selector: '[data-email-card="staff-template"]',
    featureKey: 'staff_module',
    title: 'Dostępne w wersji Pro'
  },
  {
    selector: 'input[name="block-scope"][value="staff"]',
    closest: '.block-scope-option',
    featureKey: 'staff_blocks',
    title: 'Dostępne w wersji Pro'
  },
  {
    selector: '#account-logo',
    closest: '.admin-card',
    featureKey: 'branding_logo',
    title: 'Dostępne w wersji Pro'
  },
  {
    selector: '#reservations-bg-color',
    closest: '.admin-card',
    featureKey: 'branding_colors',
    title: 'Dostępne w wersji Pro'
  },
  {
    selector: '#front-bg-color',
    closest: '.admin-card',
    featureKey: 'calendar_appearance',
    title: 'Dostępne w wersji Pro'
  },
  {
    selector: '#label-name',
    closest: '.admin-card',
    featureKey: 'calendar_appearance',
    title: 'Dostępne w wersji Pro'
  }
];

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
  initAdminNotifications();
  initBookingFilters();
  initCalendarEnabledToggle();

  try {
    const accountReady = window.adminAccountDataReady || Promise.resolve();

    await Promise.all([
      accountReady,
      loadBookings(currentBookingsView)
    ]);

    applyPlanLocks();

    if (aiIqHasFeature('admin_staff_notifications')) {
      await loadAdminNotifications();
    }
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
    'personel',
    'usluga-platnosci',
    'email',
    'integracje',
    'dokumenty_prawne',
    'informacje',
    'ustawienia',
    'moje_konto'
  ];

  function showSection(targetSection) {
    const menuIndex = sectionMap.indexOf(targetSection);
    const targetEl = document.querySelector(`[data-section="${targetSection}"]`);

    if (menuIndex < 0 || !targetEl) {
      return false;
    }

    menuItems.forEach(item => item.classList.remove('active'));
    menuItems[menuIndex]?.classList.add('active');
    sections.forEach(section => section.classList.add('hidden'));
    targetEl.classList.remove('hidden');

    window.dispatchEvent(new CustomEvent('aiiq:section-shown', {
      detail: {
        section: targetSection,
      },
    }));

    return true;
  }

  function showSectionFromHash() {
    const targetSection = window.location.hash.replace(/^#/, '');

    if (targetSection !== '') {
      showSection(targetSection);
    }
  }

  menuItems.forEach((btn, index) => {
    btn.addEventListener('click', () => {
      const targetSection = sectionMap[index];
      showSection(targetSection);
    });
  });

  window.addEventListener('hashchange', showSectionFromHash);

  showSectionFromHash();
  window.requestAnimationFrame(showSectionFromHash);
}

function aiIqHasFeature(featureKey) {
  const context = window.AIIQ_PLAN_CONTEXT || {};
  const features = context.features || {};

  return Boolean(features[featureKey]);
}

function aiIqIsFreePlan() {
  const context = window.AIIQ_PLAN_CONTEXT || {};
  return (context.plan_code || 'free') === 'free';
}

function disablePlanLockedControls(container) {
  container.querySelectorAll('input, select, textarea, button').forEach(control => {
    control.disabled = true;
  });
}

function addPlanLockOverlay(container, config, options = {}) {
  if (!container) {
    return;
  }

  container.classList.add('plan-locked');

  if (options.featureLocked !== false) {
    container.classList.add('feature-locked');
  }

  if (options.disableControls) {
    disablePlanLockedControls(container);
  }

  if (container.querySelector('[data-plan-lock-overlay="true"]')) {
    return;
  }

  const overlay = document.createElement('div');
  overlay.className = 'plan-lock-overlay';
  overlay.dataset.planLockOverlay = 'true';
  overlay.setAttribute('role', 'note');
  overlay.setAttribute('aria-label', config.title);

  const box = document.createElement('div');
  box.className = 'plan-lock-box';

  const title = document.createElement('strong');
  title.className = 'plan-lock-title';
  title.textContent = config.title;

  box.appendChild(title);
  overlay.appendChild(box);
  container.appendChild(overlay);
}

function resolvePlanLockedElement(config) {
  const element = document.querySelector(config.selector);

  if (!element) {
    return null;
  }

  return config.closest ? element.closest(config.closest) : element;
}

function renderPlanUpgradeNotice() {
  const notice = document.getElementById('planUpgradeNotice');

  if (!notice) {
    return;
  }

  notice.hidden = false;
}

function applyPlanLocks() {
  if (!aiIqIsFreePlan()) {
    return;
  }

  const sectionMap = [
    'rezerwacje',
    'blokady',
    'personel',
    'usluga-platnosci',
    'email',
    'integracje',
    'dokumenty_prawne',
    'informacje',
    'ustawienia',
    'moje_konto'
  ];

  const menuItems = document.querySelectorAll('.menu-item');

  Object.entries(PLAN_LOCKED_SECTIONS).forEach(([sectionName, config]) => {
    if (aiIqHasFeature(config.featureKey)) {
      return;
    }

    const menuIndex = sectionMap.indexOf(sectionName);
    const menuButton = menuIndex >= 0 ? menuItems[menuIndex] : null;

    if (menuButton) {
      menuButton.classList.add('feature-locked', 'plan-disabled-menu-item');
      menuButton.title = config.title;
    }

    const section = document.querySelector(`[data-section="${sectionName}"]`);

    addPlanLockOverlay(section, config);
  });

  PLAN_LOCKED_ELEMENTS.forEach(config => {
    if (aiIqHasFeature(config.featureKey)) {
      return;
    }

    addPlanLockOverlay(resolvePlanLockedElement(config), config, {
      disableControls: true
    });
  });

  applyAdminNotificationsPlanLock();
  renderPlanUpgradeNotice();
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

function renderAdminNotificationsProMessage() {
  const list = document.getElementById('adminNotificationsList');
  const markReadBtn = document.getElementById('adminNotificationsMarkRead');

  if (list) {
    list.replaceChildren();

    const message = document.createElement('p');
    message.className = 'admin-notifications-empty';
    message.textContent = 'Dostępne w wersji Pro';
    list.appendChild(message);
  }

  if (markReadBtn) {
    markReadBtn.disabled = true;
  }
}

function applyAdminNotificationsPlanLock() {
  if (aiIqHasFeature('admin_staff_notifications')) {
    return;
  }

  const root = document.getElementById('adminNotifications');
  const countEl = document.getElementById('adminNotificationsCount');

  if (root) {
    root.classList.add('feature-locked', 'plan-disabled-notification');
    root.removeAttribute('title');
  }

  if (countEl) {
    countEl.hidden = true;
    countEl.textContent = '0';
  }

  renderAdminNotificationsProMessage();
}

function initAdminNotifications() {
  const root = document.getElementById('adminNotifications');
  const toggle = document.getElementById('adminNotificationsToggle');
  const dropdown = document.getElementById('adminNotificationsDropdown');
  const markReadBtn = document.getElementById('adminNotificationsMarkRead');

  if (!root || !toggle || !dropdown) return;

  toggle.addEventListener('click', async () => {
    if (!aiIqHasFeature('admin_staff_notifications')) {
      applyAdminNotificationsPlanLock();
      dropdown.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      return;
    }

    const shouldOpen = dropdown.hidden;
    dropdown.hidden = !shouldOpen;
    toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');

    if (shouldOpen) {
      await loadAdminNotifications();
    }
  });

  document.addEventListener('click', event => {
    if (dropdown.hidden || root.contains(event.target)) return;

    dropdown.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
  });

  if (markReadBtn) {
    markReadBtn.addEventListener('click', async () => {
      if (!aiIqHasFeature('admin_staff_notifications')) {
        renderAdminNotificationsProMessage();
        return;
      }

      await markAdminNotificationsRead(markReadBtn);
    });
  }
}

function setAdminNotificationsCount(count) {
  const countEl = document.getElementById('adminNotificationsCount');
  if (!countEl) return;

  const safeCount = Math.max(0, Number(count) || 0);
  countEl.textContent = String(safeCount);
  countEl.hidden = safeCount === 0;
}

function renderAdminNotificationsList(notifications, options = {}) {
  const list = document.getElementById('adminNotificationsList');
  const markReadBtn = document.getElementById('adminNotificationsMarkRead');

  if (!list) return;

  list.replaceChildren();

  if (options.error) {
    const errorEl = document.createElement('p');
    errorEl.className = 'admin-notifications-empty';
    errorEl.textContent = options.error;
    list.appendChild(errorEl);

    if (markReadBtn) {
      markReadBtn.disabled = true;
    }

    return;
  }

  if (!Array.isArray(notifications) || notifications.length === 0) {
    const emptyEl = document.createElement('p');
    emptyEl.className = 'admin-notifications-empty';
    emptyEl.textContent = 'Brak nowych powiadomień.';
    list.appendChild(emptyEl);

    if (markReadBtn) {
      markReadBtn.disabled = true;
    }

    return;
  }

  const unreadCount = notifications.filter(item => item?.is_read !== true).length;

  notifications.forEach(item => {
    const row = document.createElement('div');
    row.className = 'admin-notifications-item';

    if (item?.is_read !== true) {
      row.classList.add('unread');
    }

    const message = document.createElement('p');
    message.textContent = String(item?.message || 'Powiadomienie o blokadzie personelu.');

    const meta = document.createElement('span');
    meta.textContent = formatAdminNotificationDate(item?.created_at);

    row.append(message, meta);
    list.appendChild(row);
  });

  if (markReadBtn) {
    markReadBtn.disabled = unreadCount === 0;
  }
}

function formatAdminNotificationDate(value) {
  if (!value) return '';

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  const day = String(date.getDate()).padStart(2, '0');
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const year = date.getFullYear();
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');

  return `${day}.${month}.${year}, ${hours}:${minutes}`;
}

async function loadAdminNotifications() {
  try {
    const response = await fetch(ADMIN_NOTIFICATIONS_ENDPOINT, {
      method: 'GET',
      credentials: 'include',
      cache: 'no-store'
    });

    const data = await response.json().catch(() => null);

    if (!response.ok || !data?.success) {
      setAdminNotificationsCount(0);
      renderAdminNotificationsList([], {
        error: data?.requires_migration
          ? 'Powiadomienia wymagają konfiguracji bazy danych.'
          : (data?.error || 'Nie udało się pobrać powiadomień.')
      });
      return;
    }

    setAdminNotificationsCount(data.unread_count || 0);
    renderAdminNotificationsList(data.notifications || []);
  } catch (error) {
    console.error('admin notifications load error:', error);
    setAdminNotificationsCount(0);
    renderAdminNotificationsList([], {
      error: 'Nie udało się pobrać powiadomień.'
    });
  }
}

async function markAdminNotificationsRead(button) {
  const defaultText = button?.textContent || 'Oznacz jako przeczytane';

  try {
    if (button) {
      button.disabled = true;
      button.textContent = 'Oznaczanie...';
    }

    const response = await fetch(ADMIN_NOTIFICATIONS_ENDPOINT, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        action: 'mark_read'
      })
    });

    const data = await response.json().catch(() => null);

    if (!response.ok || !data?.success) {
      renderAdminNotificationsList([], {
        error: data?.error || 'Nie udało się oznaczyć powiadomień.'
      });
      return;
    }

    setAdminNotificationsCount(0);
    await loadAdminNotifications();
  } catch (error) {
    console.error('admin notifications mark read error:', error);
    renderAdminNotificationsList([], {
      error: 'Nie udało się oznaczyć powiadomień.'
    });
  } finally {
    if (button) {
      button.textContent = defaultText;
    }
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
    Historia rezerwacji jest przechowywana przez 3 miesiące.
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
      <td colspan="6" class="empty">Ładowanie danych...</td>
    </tr>
  `;

  try {
    const data = await fetchBookingsByView(currentBookingsView);

    if (!data || !Array.isArray(data)) {
      bookingList.innerHTML = `
        <tr>
          <td colspan="6" class="empty">Nie udało się wczytać danych</td>
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
          <td colspan="6" class="empty">${escapeHtml(getEmptyBookingsText(currentBookingsView))}</td>
        </tr>
      `;
      return;
    }

    bookingList.innerHTML = data.map(item => renderBookingRow(item)).join('');
  } catch (error) {
    console.error('loadBookings error:', error);

    bookingList.innerHTML = `
      <tr>
        <td colspan="6" class="empty">Nie udało się wczytać danych</td>
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

function hasValue(value) {
  return value !== null && value !== undefined && String(value).trim() !== '';
}

function getBookingDescription(item) {
  return item.description || item.message || item.notes || item.opis || '';
}

function getBookingServiceName(item) {
  return item.service_name_snapshot || item.service_name || 'Usługa domyślna';
}

function getBookingStaffName(item) {
  if (hasValue(item.staff_display_name)) {
    return item.staff_display_name;
  }

  if (hasValue(item.staff_id)) {
    return 'przypisany, brak nazwy';
  }

  return 'Bez przypisanego pracownika';
}

function getBookingStaffDisplayText(item) {
  if (hasValue(item.staff_display_name) || hasValue(item.staff_id)) {
    return `Pracownik: ${getBookingStaffName(item)}`;
  }

  return getBookingStaffName(item);
}

function renderContactCell(item) {
  const parts = [];

  if (hasValue(item.email)) {
    parts.push(`<div>${escapeHtml(item.email)}</div>`);
  }

  if (hasValue(item.phone)) {
    parts.push(`<div>${escapeHtml(item.phone)}</div>`);
  }

  return parts.length ? parts.join('') : '<span class="booking-muted">Brak danych</span>';
}

function renderTermCell(item) {
  const date = item.booking_date || item.date || '';
  const time = item.booking_time || item.time || '';

  return `
    <div class="booking-term-date">${escapeHtml(date || 'Brak daty')}</div>
    ${hasValue(time) ? `<div class="booking-term-time">${escapeHtml(time)}</div>` : ''}
    ${renderRescheduleBadge(item)}
  `;
}

function renderServiceStaffCell(item) {
  return `
    <div class="booking-service-name">${escapeHtml(getBookingServiceName(item))}</div>
    <div class="booking-staff-name">${escapeHtml(getBookingStaffDisplayText(item))}</div>
  `;
}

function formatBoolean(value) {
  return value === true || value === 'true' || value === 1 || value === '1' ? 'Tak' : 'Nie';
}

function formatReadableText(value) {
  const text = String(value || '')
    .trim()
    .replace(/[_-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .toLowerCase();

  if (!text) return '';

  return text.charAt(0).toUpperCase() + text.slice(1);
}

function formatBookingDateTime(value) {
  if (!hasValue(value)) return '';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';

  const day = String(date.getDate()).padStart(2, '0');
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const year = date.getFullYear();
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');

  return `${day}.${month}.${year}, ${hours}:${minutes}`;
}

function getBookingRescheduleCount(item) {
  const count = parseInt(item?.reschedule_count ?? 0, 10);
  return Number.isFinite(count) && count > 0 ? count : 0;
}

function isBookingRescheduled(item) {
  return getBookingRescheduleCount(item) > 0 || hasValue(item?.rescheduled_at);
}

function renderRescheduleBadge(item) {
  if (!isBookingRescheduled(item)) return '';

  const count = getBookingRescheduleCount(item);
  const countText = count > 0 ? ` x${count}/3` : '';

  return `<span class="booking-reschedule-badge" title="Rezerwacja przeniesiona przez klienta">↻ Przeniesiona${countText}</span>`;
}

function getCurrentBookingTermLabel(item) {
  const date = item.booking_date || item.date || '';
  const time = item.booking_time || item.time || '';
  const dateLabel = hasValue(date) ? date : '';
  const timeLabel = hasValue(time) ? String(time).slice(0, 5) : '';

  return `${dateLabel}${dateLabel && timeLabel ? ' ' : ''}${timeLabel}`.trim();
}

function mapBookingStatus(value) {
  const status = String(value || '').trim().toLowerCase();

  const labels = {
    new: 'Nowa',
    pending: 'Oczekuje',
    pending_payment: 'Oczekuje na płatność',
    payment_pending: 'Oczekuje na płatność',
    payment_overdue: 'Płatność po terminie',
    confirmed: 'Potwierdzona',
    cancelled: 'Anulowana',
    canceled: 'Anulowana',
    completed: 'Zakończona',
    deleted: 'Usunięta'
  };

  return labels[status] || formatReadableText(value);
}

function mapBookingSource(value) {
  const source = String(value || '').trim().toLowerCase();

  const labels = {
    www: 'Strona WWW',
    front: 'Strona WWW',
    admin: 'Panel admina',
    n8n: 'Automatyzacja'
  };

  return labels[source] || formatReadableText(value);
}

function isPaymentRequired(value) {
  return value === true || value === 'true' || value === 1 || value === '1';
}

function mapPaymentStatus(item) {
  const status = String(item.payment_status || '').trim().toLowerCase();

  if (!status && item.payment_required === false) {
    return 'Nie wymaga płatności';
  }

  if (!status && item.payment_required === 'false') {
    return 'Nie wymaga płatności';
  }

  const labels = {
    paid: 'Opłacona',
    pending: 'Oczekuje na płatność',
    pending_payment: 'Oczekuje na płatność',
    payment_pending: 'Oczekuje na płatność',
    expired: 'Nie opłacono',
    failed: 'Płatność nieudana',
    cancelled: 'Anulowana',
    canceled: 'Anulowana',
    not_required: 'Nie wymaga płatności'
  };

  if (labels[status]) {
    return labels[status];
  }

  if (status) {
    return formatReadableText(status);
  }

  if (!isPaymentRequired(item.payment_required)) {
    return 'Nie wymaga płatności';
  }

  return '';
}

function formatBookingAmount(item) {
  if (!hasValue(item.payment_amount)) return '';

  const currency = item.payment_currency === 'PLN' ? 'zł' : (item.payment_currency || '');
  const amount = Number(item.payment_amount);
  const amountText = Number.isFinite(amount)
    ? amount.toFixed(2).replace('.', ',')
    : String(item.payment_amount);

  return `${amountText}${currency ? ` ${currency}` : ''}`;
}

function getSafePaymentUrl(value) {
  const url = String(value || '').trim();

  if (/^https?:\/\//i.test(url)) {
    return url;
  }

  return '';
}

function buildBookingDetails(item) {
  const details = [];
  const description = getBookingDescription(item);
  const amount = formatBookingAmount(item);
  const bookingStatus = mapBookingStatus(item.status);
  const bookingSource = mapBookingSource(item.source);
  const paymentStatus = mapPaymentStatus(item);
  const createdAt = formatBookingDateTime(item.created_at);
  const bookingDate = item.booking_date || item.date || '';
  const bookingTime = item.booking_time || item.time || '';

  if (hasValue(item.name)) details.push(['Klient', item.name]);
  if (hasValue(item.email)) details.push(['E-mail', item.email]);
  if (hasValue(item.phone)) details.push(['Telefon', item.phone]);
  if (hasValue(bookingDate)) details.push(['Data', bookingDate]);
  if (hasValue(bookingTime)) details.push(['Godzina', bookingTime]);
  if (isBookingRescheduled(item)) {
    const count = getBookingRescheduleCount(item);
    const lastChange = formatBookingDateTime(item.rescheduled_at);
    details.push(['Rezerwacja przeniesiona', count > 0 ? `Tak, ${count} z 3` : 'Tak']);
    details.push(['Aktualny termin po zmianie', getCurrentBookingTermLabel(item)]);
    if (hasValue(lastChange)) details.push(['Ostatnia zmiana terminu', lastChange]);
  }
  if (hasValue(description)) details.push(['Opis / notatka', description]);
  if (hasValue(bookingStatus)) details.push(['Status rezerwacji', bookingStatus]);
  if (hasValue(bookingSource)) details.push(['Źródło rezerwacji', bookingSource]);
  if (hasValue(getBookingServiceName(item))) details.push(['Usługa', getBookingServiceName(item)]);
  if (hasValue(getBookingStaffDisplayText(item))) details.push(['Pracownik', getBookingStaffDisplayText(item)]);
  if (hasValue(paymentStatus)) details.push(['Płatność', paymentStatus]);
  if (hasValue(amount)) details.push(['Kwota', amount]);
  if (hasValue(createdAt)) details.push(['Utworzono', createdAt]);

  return details;
}

function buildBookingTechnicalDetails(item) {
  const details = [];
  const paymentUrl = getSafePaymentUrl(item.payment_url);
  const paymentExpiresAt = formatBookingDateTime(item.payment_expires_at);
  const updatedAt = formatBookingDateTime(item.updated_at);

  if (hasValue(item.id)) details.push(['ID rezerwacji', item.id]);
  if (hasValue(item.staff_id)) details.push(['ID pracownika', item.staff_id]);
  if (hasValue(item.payment_provider)) details.push(['Operator płatności', item.payment_provider]);
  if (hasValue(item.payment_order_id)) details.push(['ID zamówienia płatności', item.payment_order_id]);
  if (hasValue(paymentUrl)) details.push(['Link płatności', paymentUrl, paymentUrl]);
  if (hasValue(paymentExpiresAt)) details.push(['Płatność ważna do', paymentExpiresAt]);
  if (hasValue(updatedAt)) details.push(['Zaktualizowano', updatedAt]);

  return details;
}

function renderBookingDetailGrid(details) {
  return `
    <div class="booking-details-grid">
      ${details.map(([label, value, href]) => `
        <div class="booking-detail-item">
          <span><i aria-hidden="true">${escapeHtml(getBookingDetailIcon(label))}</i>${escapeHtml(label)}</span>
          ${href
            ? `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer">${escapeHtml(value)}</a>`
            : `<strong>${escapeHtml(value)}</strong>`
          }
        </div>
      `).join('')}
    </div>
  `;
}

function getBookingDetailIcon(label) {
  const normalized = String(label || '').toLowerCase();

  if (normalized.includes('klient') || normalized.includes('pracownik')) return '👤';
  if (normalized.includes('email') || normalized.includes('e-mail')) return '✉️';
  if (normalized.includes('tel')) return '☎️';
  if (normalized.includes('data') || normalized.includes('utworzono') || normalized.includes('zaktualizowano')) return '📅';
  if (normalized.includes('godzina') || normalized.includes('termin')) return '🕒';
  if (normalized.includes('usługa')) return '🧾';
  if (normalized.includes('płatność') || normalized.includes('kwota')) return '💳';
  if (normalized.includes('status')) return 'ℹ️';

  return '•';
}

function renderBookingDetails(item, detailsId) {
  const details = buildBookingDetails(item);
  const technicalDetails = buildBookingTechnicalDetails(item);
  const technicalId = `${detailsId}-technical`;

  return `
    ${details.length ? renderBookingDetailGrid(details) : '<div class="booking-details-empty">Brak dodatkowych informacji.</div>'}
    ${technicalDetails.length ? `
      <div class="booking-technical">
        <button
          class="booking-technical-btn"
          type="button"
          aria-expanded="false"
          aria-controls="${escapeHtml(technicalId)}"
          onclick="toggleBookingTechnicalDetails('${escapeJs(technicalId)}', this)"
        >
          Pokaż dane techniczne
        </button>
        <div class="booking-technical-panel" id="${escapeHtml(technicalId)}" hidden>
          ${renderBookingDetailGrid(technicalDetails)}
        </div>
      </div>
    ` : ''}
  `;
}

function renderBookingRow(item) {
  const bookingId = String(item.id ?? '');
  const bookingDate = item.booking_date || item.date || '';
  const bookingTime = item.booking_time || item.time || '';
  const detailsId = `booking-details-${bookingId.replace(/[^a-zA-Z0-9_-]/g, '') || Math.random().toString(36).slice(2)}`;
  const payment = renderPaymentBadge(item);
  const paymentRowClass = getPaymentRowClass(item);

  return `
    <tr class="booking-row${paymentRowClass}" data-booking-row="${escapeHtml(bookingId)}">
      <td class="col-name">${escapeHtml(item.name || 'Brak danych')}</td>
      <td class="col-contact">${renderContactCell(item)}</td>
      <td class="col-term">${renderTermCell(item)}</td>
      <td class="col-service">${renderServiceStaffCell(item)}</td>
      <td class="col-payment">${payment}</td>
      <td class="col-actions">
        <div class="booking-actions">
          <button
            class="booking-details-btn"
            type="button"
            aria-expanded="false"
            aria-controls="${escapeHtml(detailsId)}"
            onclick="toggleBookingDetails('${escapeJs(detailsId)}', this)"
          >
            Więcej
          </button>
          <button
            class="delete-btn"
            type="button"
            onclick="deleteBooking('${escapeJs(bookingId)}','${escapeJs(bookingDate)}','${escapeJs(bookingTime)}','${escapeJs(item.status || '')}','${escapeJs(item.payment_status || '')}')"
          >
            Usuń
          </button>
        </div>
      </td>
    </tr>
    <tr class="booking-details-row" id="${escapeHtml(detailsId)}" hidden>
      <td colspan="6">
        <div class="booking-details-panel">
          ${renderBookingDetails(item, detailsId)}
        </div>
      </td>
    </tr>
  `;
}

function toggleBookingDetails(detailsId, button) {
  const row = document.getElementById(detailsId);
  if (!row) return;

  const shouldShow = row.hidden;
  row.hidden = !shouldShow;

  if (button) {
    button.textContent = shouldShow ? 'Mniej' : 'Więcej';
    button.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
  }
}

window.toggleBookingDetails = toggleBookingDetails;

function toggleBookingTechnicalDetails(technicalId, button) {
  const panel = document.getElementById(technicalId);
  if (!panel) return;

  const shouldShow = panel.hidden;
  panel.hidden = !shouldShow;

  if (button) {
    button.textContent = shouldShow ? 'Ukryj dane techniczne' : 'Pokaż dane techniczne';
    button.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
  }
}

window.toggleBookingTechnicalDetails = toggleBookingTechnicalDetails;

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
    } else if (status === 'pending' || status === 'pending_payment' || status === 'payment_pending') {
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
