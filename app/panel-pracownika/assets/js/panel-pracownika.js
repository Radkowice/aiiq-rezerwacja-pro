(function () {
  'use strict';

  const ME_ENDPOINT = '/api/staff/me.php';
  const BOOKINGS_ENDPOINT = '/api/staff/bookings.php';
  const SERVICE_SETTINGS_ENDPOINT = '/api/staff/service-settings.php';
  const STAFF_BLOCKS_ENDPOINT = '/api/staff/blocks.php';
  const LOGOUT_ENDPOINT = '/api/staff/logout.php';
  const CHANGE_PASSWORD_ENDPOINT = '/api/staff/change-password.php';
  const LOGIN_URL = '/panel-pracownika/logowanie.html';
  const EMPLOYEE_MONTH_NAMES = [
    'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
    'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'
  ];

  let employeeBlocksViewDate = new Date();
  let employeeSelectedBlocksDate = '';
  let employeeCalendarDays = {};

  function getElement(id) {
    return document.getElementById(id);
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function hasValue(value) {
    return String(value ?? '').trim() !== '';
  }

  function setMessage(message, type) {
    const messageEl = getElement('employeePanelMessage');

    if (!messageEl) {
      return;
    }

    messageEl.textContent = message || '';
    messageEl.classList.remove('is-success', 'is-error');

    if (type === 'success') {
      messageEl.classList.add('is-success');
    }

    if (type === 'error') {
      messageEl.classList.add('is-error');
    }
  }

  function setPasswordMessage(message, type) {
    const messageEl = getElement('employeePasswordMessage');

    if (!messageEl) {
      return;
    }

    messageEl.textContent = message || '';
    messageEl.classList.remove('is-success', 'is-error');

    if (type === 'success') {
      messageEl.classList.add('is-success');
    }

    if (type === 'error') {
      messageEl.classList.add('is-error');
    }
  }

  function setBlocksMessage(message, type) {
    const messageEl = getElement('employeeBlocksMessage');

    if (!messageEl) {
      return;
    }

    messageEl.textContent = message || '';
    messageEl.classList.remove('is-success', 'is-error', 'is-muted');

    if (type === 'success') {
      messageEl.classList.add('is-success');
    } else if (type === 'error') {
      messageEl.classList.add('is-error');
    } else if (type === 'muted') {
      messageEl.classList.add('is-muted');
    }
  }

  function redirectToLogin() {
    window.location.href = LOGIN_URL;
  }

  function showPlanLock(message) {
    const layout = document.querySelector('.employee-panel-layout');
    const lock = getElement('employeePlanLock');

    if (layout) {
      layout.hidden = true;
    }

    if (lock) {
      const title = lock.querySelector('h1');
      if (title && message) {
        title.textContent = message;
      }
      lock.hidden = false;
    }
  }

  function valueOrFallback(value, fallback) {
    const normalized = String(value ?? '').trim();

    return normalized || fallback;
  }

  function getPasswordValidationError(password) {
    if (password.length < 8) {
      return 'Nowe hasło musi mieć minimum 8 znaków.';
    }

    if (!/[a-z]/.test(password)) {
      return 'Nowe hasło musi zawierać małą literę.';
    }

    if (!/[A-Z]/.test(password)) {
      return 'Nowe hasło musi zawierać dużą literę.';
    }

    if (!/[0-9]/.test(password)) {
      return 'Nowe hasło musi zawierać cyfrę.';
    }

    if (!/[^A-Za-z0-9]/.test(password)) {
      return 'Nowe hasło musi zawierać znak specjalny.';
    }

    return '';
  }

  function formatDate(dateValue) {
    if (!hasValue(dateValue)) {
      return 'Brak daty';
    }

    const date = new Date(String(dateValue) + 'T00:00:00');

    if (Number.isNaN(date.getTime())) {
      return String(dateValue);
    }

    return date.toLocaleDateString('pl-PL', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric'
    });
  }

  function formatTime(timeValue) {
    const time = String(timeValue ?? '').trim();

    if (!time) {
      return 'Brak godziny';
    }

    return time.slice(0, 5);
  }

  function formatLocalDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
  }

  function dateFromLocalString(dateValue) {
    const parts = String(dateValue || '').split('-').map(Number);

    if (parts.length !== 3 || parts.some((part) => !Number.isFinite(part))) {
      return new Date();
    }

    return new Date(parts[0], parts[1] - 1, parts[2]);
  }

  function isPastDate(dateValue) {
    return dateValue < formatLocalDate(new Date());
  }


  function getRescheduleCount(item) {
    const count = parseInt(item?.reschedule_count ?? 0, 10);
    return Number.isFinite(count) && count > 0 ? count : 0;
  }

  function isRescheduled(item) {
    return getRescheduleCount(item) > 0 || Boolean(item?.rescheduled_at);
  }

  function formatDateTime(value) {
    const date = new Date(value || '');

    if (Number.isNaN(date.getTime())) {
      return '';
    }

    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');

    return `${day}.${month}.${year} ${hours}:${minutes}`;
  }

  function rescheduleBadgeHtml(item) {
    if (!isRescheduled(item)) {
      return '';
    }

    const count = getRescheduleCount(item);
    const text = count > 0 ? `↻ Przeniesiona ${count} z 3` : '↻ Przeniesiona';

    return `<span class="employee-reschedule-badge">${escapeHtml(text)}</span>`;
  }

  function getCalendarDayInfo(dateValue) {
    const info = employeeCalendarDays && typeof employeeCalendarDays === 'object'
      ? employeeCalendarDays[dateValue]
      : null;

    return info && typeof info === 'object'
      ? info
      : {
          has_reserved: false,
          has_staff_block: false,
          has_staff_date_block: false,
          has_staff_time_block: false,
          has_global_block: false
        };
  }

  function getCalendarDayLabel(info) {
    if (info.has_reserved) {
      return info.has_rescheduled ? 'R↻' : 'R';
    }

    if (info.has_staff_block) {
      return 'Moja';
    }

    if (info.has_global_block) {
      return 'Firma';
    }

    return '';
  }

  function renderEmployeeBlocksCalendar() {
    const container = getElement('employeeBlocksCalendar');

    if (!container) {
      return;
    }

    const year = employeeBlocksViewDate.getFullYear();
    const month = employeeBlocksViewDate.getMonth();
    const firstDate = new Date(year, month, 1);
    const firstWeekday = firstDate.getDay() === 0 ? 6 : firstDate.getDay() - 1;
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    let html = `
      <div class="employee-calendar-header">
        <button type="button" id="employeeBlocksPrevMonth" aria-label="Poprzedni miesiąc">&lsaquo;</button>
        <div>${EMPLOYEE_MONTH_NAMES[month]} ${year}</div>
        <button type="button" id="employeeBlocksNextMonth" aria-label="Następny miesiąc">&rsaquo;</button>
      </div>
      <div class="employee-admin-weekdays">
        <div>Pn</div>
        <div>Wt</div>
        <div>Śr</div>
        <div>Cz</div>
        <div>Pt</div>
        <div>So</div>
        <div>Nd</div>
      </div>
      <div class="employee-calendar-grid">
    `;

    for (let i = 0; i < firstWeekday; i += 1) {
      html += '<div class="employee-admin-day empty"></div>';
    }

    for (let day = 1; day <= daysInMonth; day += 1) {
      const dateValue = formatLocalDate(new Date(year, month, day));
      const info = getCalendarDayInfo(dateValue);
      const label = getCalendarDayLabel(info);
      const classes = [
        'employee-admin-day',
        isPastDate(dateValue) ? 'disabled' : '',
        info.has_global_block ? 'blocked' : '',
        info.has_staff_block ? 'staff-blocked' : '',
        info.has_reserved ? 'has-reservation' : '',
        dateValue === formatLocalDate(new Date()) ? 'today' : '',
        dateValue === employeeSelectedBlocksDate ? 'selected' : ''
      ].filter(Boolean).join(' ');

      html += `
        <button type="button" class="${classes}" data-date="${escapeHtml(dateValue)}" ${isPastDate(dateValue) ? 'disabled' : ''}>
          <span>${day}</span>
          ${label ? `<small class="day-label">${escapeHtml(label)}</small>` : ''}
        </button>
      `;
    }

    html += '</div>';
    container.innerHTML = html;

    const prevBtn = getElement('employeeBlocksPrevMonth');
    const nextBtn = getElement('employeeBlocksNextMonth');

    if (prevBtn) {
      prevBtn.addEventListener('click', () => {
        employeeBlocksViewDate = new Date(year, month - 1, 1);
        employeeSelectedBlocksDate = formatLocalDate(employeeBlocksViewDate);
        renderEmployeeBlocksCalendar();
        loadServiceSlots();
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        employeeBlocksViewDate = new Date(year, month + 1, 1);
        employeeSelectedBlocksDate = formatLocalDate(employeeBlocksViewDate);
        renderEmployeeBlocksCalendar();
        loadServiceSlots();
      });
    }

    container.querySelectorAll('.employee-admin-day[data-date]:not(:disabled)').forEach((button) => {
      button.addEventListener('click', () => {
        employeeSelectedBlocksDate = button.getAttribute('data-date') || formatLocalDate(new Date());
        employeeBlocksViewDate = dateFromLocalString(employeeSelectedBlocksDate);
        renderEmployeeBlocksCalendar();
        loadServiceSlots();
      });
    });
  }

  function renderEmployeeTimeTitle() {
    const title = getElement('employeeBlocksTimeTitle');

    if (title) {
      title.textContent = 'Godziny dla: ' + (employeeSelectedBlocksDate || '-');
    }
  }

  function getSlotStatusLabel(status) {
    const labels = {
      available: 'Wolny',
      reserved: 'Rezerwacja',
      staff_busy: 'Zajęty',
      blocked_staff: 'Moja blokada',
      blocked_global: 'Blokada firmy'
    };

    return labels[status] || 'Nieznany status';
  }

  function reservationDetailsHtml(reservation, serviceName, fallbackTime) {
    const safeReservation = reservation || {};
    const rows = [
      ['👤', 'Klient', safeReservation.name || 'Brak nazwy'],
      ['✉️', 'E-mail', safeReservation.email || 'Brak e-maila'],
      ['☎️', 'Telefon', safeReservation.phone || 'Brak telefonu'],
      ['🕒', 'Godzina', safeReservation.time || fallbackTime || 'Brak godziny'],
      ['🧾', 'Usługa', safeReservation.service || serviceName || 'Usługa']
    ];

    if (isRescheduled(safeReservation)) {
      const count = getRescheduleCount(safeReservation);
      const changedAt = formatDateTime(safeReservation.rescheduled_at);
      rows.push(['↻', 'Przeniesiona', count > 0 ? `${count} z 3` : 'Tak']);
      if (changedAt) {
        rows.push(['📅', 'Ostatnia zmiana', changedAt]);
      }
    }

    return `
      <div class="employee-slot-reservation">
        ${rows.map(([icon, label, value]) => `
          <span>
            <i aria-hidden="true">${icon}</i>
            <strong>${escapeHtml(label)}:</strong>
            ${escapeHtml(value)}
          </span>
        `).join('')}
      </div>
    `;
  }

  function ensureEmployeeConfirm() {
    let overlay = getElement('employeeConfirmOverlay');

    if (overlay) {
      return overlay;
    }

    overlay = document.createElement('div');
    overlay.id = 'employeeConfirmOverlay';
    overlay.className = 'employee-confirm-overlay';
    overlay.innerHTML = `
      <div class="employee-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="employeeConfirmTitle">
        <div class="employee-confirm-icon" id="employeeConfirmIcon">⚠️</div>
        <div class="employee-confirm-title" id="employeeConfirmTitle">Potwierdzenie</div>
        <div class="employee-confirm-message" id="employeeConfirmMessage"></div>
        <div class="employee-confirm-actions">
          <button type="button" class="employee-confirm-btn cancel" id="employeeConfirmCancel">Zamknij</button>
          <button type="button" class="employee-confirm-btn ok primary" id="employeeConfirmOk">OK</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);

    return overlay;
  }

  function openEmployeeConfirm({ title, html, message, variant, icon } = {}) {
    return new Promise((resolve) => {
      const overlay = ensureEmployeeConfirm();
      const iconEl = getElement('employeeConfirmIcon');
      const titleEl = getElement('employeeConfirmTitle');
      const messageEl = getElement('employeeConfirmMessage');
      const cancelBtn = getElement('employeeConfirmCancel');
      const okBtn = getElement('employeeConfirmOk');

      if (!overlay || !titleEl || !messageEl || !cancelBtn || !okBtn) {
        resolve(true);
        return;
      }

      if (iconEl) {
        iconEl.textContent = icon || '⚠️';
      }

      titleEl.textContent = title || 'Potwierdzenie';
      messageEl.innerHTML = html || escapeHtml(message || '');
      okBtn.className = `employee-confirm-btn ok ${variant || 'primary'}`;
      overlay.classList.add('show');
      document.body.classList.add('modal-open');

      const close = (result) => {
        overlay.classList.remove('show');
        document.body.classList.remove('modal-open');
        cancelBtn.onclick = null;
        okBtn.onclick = null;
        document.removeEventListener('keydown', onKeyDown);
        resolve(result);
      };

      const onKeyDown = (event) => {
        if (event.key === 'Escape') {
          close(false);
        }
      };

      cancelBtn.onclick = () => close(false);
      okBtn.onclick = () => close(true);
      overlay.onclick = (event) => {
        if (event.target === overlay) {
          close(false);
        }
      };
      document.addEventListener('keydown', onKeyDown);
    });
  }

  function mapBookingStatus(status) {
    const normalized = String(status ?? '').trim().toLowerCase();

    const labels = {
      new: 'Nowa',
      pending: 'Oczekuje',
      pending_payment: 'Niepotwierdzona',
      payment_pending: 'Niepotwierdzona',
      payment_overdue: 'Płatność po terminie',
      confirmed: 'Potwierdzona',
      completed: 'Zakończona',
      cancelled: 'Anulowana',
      canceled: 'Anulowana'
    };

    return labels[normalized] || status || 'Brak statusu';
  }

  function mapPaymentStatus(status, paymentRequired) {
    const normalized = String(status ?? '').trim().toLowerCase();

    if (paymentRequired === false && !normalized) {
      return 'Nie wymaga płatności';
    }

    const labels = {
      paid: 'Opłacona',
      pending: 'Oczekuje na płatność',
      pending_payment: 'Oczekuje na płatność',
      payment_pending: 'Oczekuje na płatność',
      cancelled: 'Anulowana',
      canceled: 'Anulowana',
      failed: 'Nieudana',
      expired: 'Wygasła',
      not_required: 'Nie wymaga płatności'
    };

    return labels[normalized] || status || 'Brak płatności';
  }

  function statusClass(status) {
    const normalized = String(status ?? '').trim().toLowerCase();

    if (normalized === 'paid' || normalized === 'confirmed' || normalized === 'completed') {
      return 'is-success';
    }

    if (normalized === 'pending' || normalized === 'pending_payment' || normalized === 'payment_pending' || normalized === 'new') {
      return 'is-warning';
    }

    if (normalized === 'cancelled' || normalized === 'canceled' || normalized === 'failed' || normalized === 'expired' || normalized === 'payment_overdue') {
      return 'is-danger';
    }

    return 'is-neutral';
  }

  function renderStatusCell(booking) {
    const bookingStatus = String(booking.status ?? '').trim();
    const paymentStatus = String(booking.payment_status ?? '').trim();

    return `
      <div class="employee-status-stack">
        <span class="employee-status-badge ${statusClass(bookingStatus)}">
          ${escapeHtml(mapBookingStatus(bookingStatus))}
        </span>
        <span class="employee-status-badge ${statusClass(paymentStatus)}">
          ${escapeHtml(mapPaymentStatus(paymentStatus, booking.payment_required))}
        </span>
      </div>
    `;
  }

  function renderBookings(bookings) {
    const list = getElement('employeeBookingsList');

    if (!list) {
      return;
    }

    if (!Array.isArray(bookings) || bookings.length === 0) {
      list.innerHTML = `
        <tr>
          <td colspan="6" class="employee-bookings-empty">Nie masz jeszcze przypisanych rezerwacji.</td>
        </tr>
      `;
      return;
    }

    list.innerHTML = bookings.map((booking) => {
      const serviceName = hasValue(booking.service_name_snapshot)
        ? booking.service_name_snapshot
        : 'Bez nazwy usługi';
      const contactParts = [
        booking.email,
        booking.phone
      ].filter(hasValue);

      return `
        <tr>
          <td class="employee-col-term">
            <strong>${escapeHtml(formatDate(booking.booking_date))}</strong>
            <span>${escapeHtml(formatTime(booking.booking_time))}</span>
            ${rescheduleBadgeHtml(booking)}
          </td>
          <td class="employee-col-client">
            ${escapeHtml(booking.name || 'Bez nazwy klienta')}
          </td>
          <td class="employee-col-contact">
            ${contactParts.length > 0 ? contactParts.map(escapeHtml).join('<br>') : '<span class="employee-muted">Brak kontaktu</span>'}
          </td>
          <td class="employee-col-service">
            ${escapeHtml(serviceName)}
          </td>
          <td class="employee-col-status">
            ${renderStatusCell(booking)}
          </td>
          <td class="employee-col-notes">
            ${hasValue(booking.notes) ? escapeHtml(booking.notes) : '<span class="employee-muted">Brak notatki</span>'}
          </td>
        </tr>
      `;
    }).join('');
  }

  function renderStaffInformation(company, staff) {
    const companyName = getElement('employeeCompanyName');
    const companyAddress = getElement('employeeCompanyAddress');
    const companyNip = getElement('employeeCompanyNip');
    const companyPhone = getElement('employeeCompanyPhone');
    const companyEmail = getElement('employeeCompanyEmail');
    const staffDisplayName = getElement('employeeStaffDisplayName');
    const staffEmail = getElement('employeeStaffEmail');

    if (companyName) {
      companyName.textContent = valueOrFallback(company && company.name, 'Usługodawca');
    }

    if (companyAddress) {
      companyAddress.textContent = valueOrFallback(company && company.address, 'Nie podano');
    }

    if (companyNip) {
      companyNip.textContent = valueOrFallback(company && company.nip, 'Nie podano');
    }

    if (companyPhone) {
      companyPhone.textContent = valueOrFallback(company && company.phone, 'Nie podano');
    }

    if (companyEmail) {
      companyEmail.textContent = valueOrFallback(company && company.email, 'Nie podano');
    }

    if (staffDisplayName) {
      staffDisplayName.textContent = valueOrFallback(staff && staff.display_name, 'Personel');
    }

    if (staffEmail) {
      staffEmail.textContent = valueOrFallback(staff && staff.email, 'Brak e-maila');
    }
  }

  function renderServiceSlots(services) {
    const container = getElement('employeeServiceSlots');

    if (!container) {
      return;
    }

    if (!Array.isArray(services) || services.length === 0) {
      container.innerHTML = '<div class="employee-blocks-empty">Brak usług przypisanych do tego konta personelu.</div>';
      return;
    }

    container.innerHTML = services.map((service, serviceIndex) => {
      const slots = Array.isArray(service.slots) ? service.slots : [];
      const serviceName = service.name || 'Usługa';

      return `
        <article class="employee-service-card">
          <div class="employee-service-card-header">
            <div>
              <h3>${escapeHtml(serviceName)}</h3>
              <p>
                ${escapeHtml(String(service.duration || 0))} min
                · przerwa ${escapeHtml(String(service.break || 0))} min
                · bufor ${escapeHtml(String(service.buffer || 0))} min
              </p>
            </div>
          </div>

          ${slots.length > 0 ? `
            <div class="employee-slots-grid" role="list" aria-label="Godziny dla usługi ${escapeHtml(serviceName)}">
              ${slots.map((slot, slotIndex) => {
                const status = String(slot.status || 'available');
                const detailId = `employee-slot-detail-${serviceIndex}-${slotIndex}`;
                const isReserved = status === 'reserved';

                return `
                  <div class="employee-slot-wrap" role="listitem">
                    <button
                      type="button"
                      class="employee-slot-tile is-${escapeHtml(status)}"
                      data-slot-status="${escapeHtml(status)}"
                      data-slot-time="${escapeHtml(slot.time || '')}"
                      data-block-source="${escapeHtml(slot.block_source || '')}"
                      ${isReserved ? `data-reservation-detail="${escapeHtml(detailId)}" aria-expanded="false" aria-controls="${escapeHtml(detailId)}"` : ''}
                    >
                      <strong>${escapeHtml(formatTime(slot.time))}</strong>
                      ${isReserved ? `<span class="employee-slot-r">${isRescheduled(slot.reservation) ? 'R↻' : 'R'}</span>` : ''}
                      <small>${escapeHtml(getSlotStatusLabel(status))}</small>
                    </button>
                    ${isReserved ? `
                      <div class="employee-slot-detail" id="${escapeHtml(detailId)}" hidden>
                        ${reservationDetailsHtml(slot.reservation, serviceName, slot.time)}
                      </div>
                    ` : ''}
                  </div>
                `;
              }).join('')}
            </div>
          ` : '<div class="employee-blocks-empty">Brak godzin dla wybranego dnia.</div>'}
        </article>
      `;
    }).join('');

    bindSlotTiles();
  }

  async function loadServiceSlots() {
    const refreshBtn = getElement('employeeRefreshBlocksBtn');
    const container = getElement('employeeServiceSlots');
    const date = employeeSelectedBlocksDate || formatLocalDate(new Date());

    if (!date) {
      setBlocksMessage('Wybierz dzień.', 'muted');
      return;
    }

    if (refreshBtn) {
      refreshBtn.disabled = true;
      refreshBtn.textContent = 'Odświeżanie...';
    }

    if (container) {
      container.innerHTML = '<div class="employee-blocks-empty">Ładowanie usług i godzin...</div>';
    }

    setBlocksMessage('', '');

    try {
      const params = new URLSearchParams({ date });
      const response = await fetch(`${SERVICE_SETTINGS_ENDPOINT}?${params.toString()}`, {
        method: 'GET',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      });

      const data = await response.json().catch(() => null);

      if (response.status === 401) {
        redirectToLogin();
        return;
      }

      if (!response.ok || !data || data.success !== true) {
        throw new Error(data && data.error ? data.error : 'Nie udało się pobrać godzin.');
      }

      employeeCalendarDays = data.calendar_days || {};
      renderEmployeeBlocksCalendar();
      renderEmployeeTimeTitle();
      renderServiceSlots(data.services || []);
    } catch (error) {
      renderServiceSlots([]);
      setBlocksMessage(error.message || 'Nie udało się pobrać godzin.', 'error');
    } finally {
      if (refreshBtn) {
        refreshBtn.disabled = false;
        refreshBtn.textContent = 'Odśwież';
      }
    }
  }

  function bindSlotTiles() {
    document.querySelectorAll('.employee-slot-tile').forEach((button) => {
      button.addEventListener('click', async () => {
        const status = button.getAttribute('data-slot-status') || '';
        const detailId = button.getAttribute('data-reservation-detail') || '';
        const time = button.getAttribute('data-slot-time') || '';
        const blockSource = button.getAttribute('data-block-source') || '';

        if (detailId) {
          const detail = getElement(detailId);

          if (detail) {
            const shouldShow = detail.hidden;
            detail.hidden = !shouldShow;
            button.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
          }

          return;
        }

        if (status === 'available') {
          await handleEmployeeTimeAction('block', time);
          return;
        }

        if (status === 'blocked_staff') {
          if (blockSource === 'staff_time') {
            await handleEmployeeTimeAction('unblock', time);
            return;
          }

          openEmployeeInfo(
            'Blokada dnia',
            'Ten termin jest zablokowany blokadą całego dnia. Użyj przycisku „Odblokuj dzień”.',
            'primary'
          );
          return;
        }

        if (status === 'blocked_global') {
          openEmployeeInfo(
            'Blokada firmy',
            'Nie można odblokować blokady globalnej ustawionej przez administratora.',
            'danger'
          );
          return;
        }

        if (status === 'staff_busy') {
          openEmployeeInfo(
            'Zajęty termin',
            'Nie można zmienić tego terminu, ponieważ jest zajęty rezerwacją w innej usłudze.',
            'danger'
          );
        }
      });
    });
  }

  async function handleEmployeeDayAction(action) {
    const date = employeeSelectedBlocksDate || formatLocalDate(new Date());
    const isUnblock = action === 'unblock';
    const info = getCalendarDayInfo(date);

    if (isUnblock && !info.has_staff_date_block) {
      openEmployeeInfo(
        'Brak własnej blokady',
        info.has_global_block
          ? 'Tego dnia jest blokada firmy. Może ją zmienić tylko administrator.'
          : 'Ten dzień nie ma Twojej ręcznej blokady.',
        info.has_global_block ? 'danger' : 'primary'
      );
      return;
    }

    if (!isUnblock && info.has_global_block) {
      openEmployeeInfo(
        'Blokada firmy',
        'Ten dzień jest już zablokowany przez firmę. Personel nie może zmieniać blokad globalnych.',
        'danger'
      );
      return;
    }

    const confirmed = await openEmployeeConfirm({
      title: isUnblock ? 'Odblokowanie dnia' : 'Blokada dnia',
      html: isUnblock
        ? `Czy na pewno odblokować dzień <strong>${escapeHtml(date)}</strong>?`
        : `Czy na pewno zablokować cały dzień <strong>${escapeHtml(date)}</strong>?`,
      variant: isUnblock ? 'primary' : 'danger',
      icon: '⚠️'
    });

    if (!confirmed) {
      return;
    }

    await saveEmployeeBlockAction(
      isUnblock ? 'DELETE' : 'POST',
      { date, allDay: true },
      isUnblock ? 'Odblokowano dzień.' : 'Zablokowano dzień.'
    );
  }

  async function handleEmployeeTimeAction(action, time) {
    const date = employeeSelectedBlocksDate || formatLocalDate(new Date());
    const isUnblock = action === 'unblock';

    if (!/^\d{2}:\d{2}$/.test(String(time || ''))) {
      setBlocksMessage('Nieprawidłowa godzina terminu.', 'error');
      return;
    }

    const confirmed = await openEmployeeConfirm({
      title: isUnblock ? 'Odblokowanie terminu' : 'Blokada terminu',
      html: isUnblock
        ? `Czy na pewno odblokować termin <strong>${escapeHtml(time)}</strong> w dniu <strong>${escapeHtml(date)}</strong>?`
        : `Czy na pewno zablokować termin <strong>${escapeHtml(time)}</strong> w dniu <strong>${escapeHtml(date)}</strong>?`,
      variant: isUnblock ? 'primary' : 'danger',
      icon: '⚠️'
    });

    if (!confirmed) {
      return;
    }

    await saveEmployeeBlockAction(
      isUnblock ? 'DELETE' : 'POST',
      { date, time, allDay: false },
      isUnblock ? 'Odblokowano termin.' : 'Zablokowano termin.'
    );
  }

  async function saveEmployeeBlockAction(method, payload, successMessage) {
    setBlocksMessage('', '');

    try {
      const response = await fetch(STAFF_BLOCKS_ENDPOINT, {
        method,
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      const data = await response.json().catch(() => null);

      if (response.status === 401) {
        redirectToLogin();
        return;
      }

      if (!response.ok || !data || data.success !== true) {
        throw new Error(data && data.error ? data.error : 'Nie udało się zapisać blokady.');
      }

      setBlocksMessage(successMessage, 'success');
      await loadServiceSlots();
      await loadBookings();
    } catch (error) {
      setBlocksMessage(error.message || 'Nie udało się zapisać blokady.', 'error');
    }
  }

  function openEmployeeInfo(title, html, variant) {
    openEmployeeConfirm({
      title,
      html,
      variant,
      icon: '⚠️'
    });
  }

  async function loadStaffSession() {
    try {
      const response = await fetch(ME_ENDPOINT, {
        method: 'GET',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      });

      const data = await response.json().catch(() => null);

      if (response.status === 403 && data?.upgrade_required === true) {
        showPlanLock(data.error || 'Panel personelu jest dostępny w planie Pro.');
        return null;
      }

      if (!response.ok || !data || data.success !== true || !data.staff) {
        redirectToLogin();
        return null;
      }

      const staff = data.staff;
      const company = data.company || {};
      const companyName = valueOrFallback(company.name, 'Usługodawca');
      const title = getElement('employeePanelTitle');
      const subtitle = getElement('employeePanelSubtitle');

      if (title) {
        title.textContent = companyName;
      }

      if (subtitle) {
        subtitle.textContent = 'Zalogowano jako: ' + (staff.display_name || staff.email || 'personel');
      }

      const layout = document.querySelector('.employee-panel-layout');
      if (layout) {
        layout.hidden = false;
      }

      renderStaffInformation(company, staff);

      return staff;
    } catch (error) {
      redirectToLogin();
      return null;
    }
  }

  async function loadBookings() {
    const refreshBtn = getElement('employeeRefreshBookingsBtn');

    if (refreshBtn) {
      refreshBtn.disabled = true;
      refreshBtn.textContent = 'Odświeżanie...';
    }

    setMessage('', '');

    try {
      const response = await fetch(BOOKINGS_ENDPOINT, {
        method: 'GET',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      });

      const data = await response.json().catch(() => null);

      if (response.status === 401) {
        redirectToLogin();
        return;
      }

      if (!response.ok || !data || data.success !== true) {
        throw new Error(data && data.error ? data.error : 'Nie udało się pobrać rezerwacji.');
      }

      renderBookings(data.bookings || []);
    } catch (error) {
      renderBookings([]);
      setMessage(error.message || 'Nie udało się pobrać rezerwacji.', 'error');
    } finally {
      if (refreshBtn) {
        refreshBtn.disabled = false;
        refreshBtn.textContent = 'Odśwież';
      }
    }
  }

  async function handleLogout() {
    const logoutBtn = getElement('employeeLogoutBtn');

    if (logoutBtn) {
      logoutBtn.disabled = true;
      logoutBtn.textContent = 'Wylogowywanie...';
    }

    try {
      await fetch(LOGOUT_ENDPOINT, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      });

      redirectToLogin();
    } catch (error) {
      setMessage('Nie udało się wylogować. Spróbuj ponownie.', 'error');

      if (logoutBtn) {
        logoutBtn.disabled = false;
        logoutBtn.textContent = 'Wyloguj';
      }
    }
  }

  function setPasswordFormDisabled(disabled) {
    const form = getElement('employeePasswordForm');

    if (!form) {
      return;
    }

    form.querySelectorAll('input, button').forEach((element) => {
      element.disabled = disabled;
    });
  }

  function clearPasswordForm() {
    ['employeeCurrentPassword', 'employeeNewPassword', 'employeeConfirmPassword'].forEach((id) => {
      const input = getElement(id);

      if (input) {
        input.value = '';
      }
    });
  }

  function bindPasswordToggles() {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
      button.addEventListener('click', () => {
        const targetId = button.getAttribute('data-password-toggle') || '';
        const input = getElement(targetId);

        if (!input) {
          return;
        }

        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        button.textContent = isHidden ? '🙈' : '👁️';
        button.setAttribute('aria-label', isHidden ? 'Ukryj hasło' : 'Pokaż hasło');
      });
    });
  }

  async function handlePasswordChange(event) {
    event.preventDefault();

    const currentPasswordInput = getElement('employeeCurrentPassword');
    const newPasswordInput = getElement('employeeNewPassword');
    const confirmPasswordInput = getElement('employeeConfirmPassword');
    const submitBtn = getElement('employeePasswordSubmit');

    const currentPassword = currentPasswordInput ? currentPasswordInput.value : '';
    const newPassword = newPasswordInput ? newPasswordInput.value : '';
    const confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : '';

    setPasswordMessage('', '');

    if (!currentPassword || !newPassword || !confirmPassword) {
      setPasswordMessage('Wypełnij wszystkie pola.', 'error');
      return;
    }

    if (newPassword !== confirmPassword) {
      setPasswordMessage('Nowe hasła nie są takie same.', 'error');
      return;
    }

    const passwordError = getPasswordValidationError(newPassword);

    if (passwordError) {
      setPasswordMessage(passwordError, 'error');
      return;
    }

    setPasswordFormDisabled(true);

    if (submitBtn) {
      submitBtn.textContent = 'Zapisywanie...';
    }

    try {
      const response = await fetch(CHANGE_PASSWORD_ENDPOINT, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          current_password: currentPassword,
          new_password: newPassword,
          confirm_password: confirmPassword
        })
      });

      const data = await response.json().catch(() => null);

      if (response.status === 401) {
        redirectToLogin();
        return;
      }

      if (!response.ok || !data || data.success !== true) {
        throw new Error(data && data.error ? data.error : 'Nie udało się zmienić hasła.');
      }

      clearPasswordForm();
      setPasswordMessage(data.message || 'Hasło zostało zmienione.', 'success');
    } catch (error) {
      setPasswordMessage(error.message || 'Nie udało się zmienić hasła.', 'error');
    } finally {
      setPasswordFormDisabled(false);

      if (submitBtn) {
        submitBtn.textContent = 'Zmień hasło';
      }
    }
  }

  function bindTabs() {
    const tabs = Array.from(document.querySelectorAll('[data-panel-tab]'));
    const sections = Array.from(document.querySelectorAll('[data-panel-section]'));

    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        const target = tab.getAttribute('data-panel-tab');

        tabs.forEach((item) => {
          item.classList.toggle('is-active', item === tab);
        });

        sections.forEach((section) => {
          const isActive = section.getAttribute('data-panel-section') === target;
          section.classList.toggle('is-active', isActive);
          section.hidden = !isActive;
        });

        if (target === 'blocks') {
          loadServiceSlots();
        }
      });
    });
  }

  function initEmployeeSidebarToggle() {
    const layout = document.querySelector('.employee-panel-layout');
    const sidebar = document.querySelector('.employee-panel-sidebar');
    const toggleBtn = getElement('employeeSidebarToggle');
    const desktopMedia = window.matchMedia('(min-width: 1101px)');

    if (!layout || !sidebar || !toggleBtn) {
      return;
    }

    const setCollapsed = (collapsed) => {
      sidebar.classList.toggle('collapsed', collapsed);
      layout.classList.toggle('sidebar-collapsed', collapsed);
      toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      toggleBtn.setAttribute('aria-label', collapsed ? 'Rozwiń panel boczny' : 'Zwiń panel boczny');
    };

    toggleBtn.addEventListener('click', () => {
      if (!desktopMedia.matches) {
        setCollapsed(false);
        return;
      }

      setCollapsed(!sidebar.classList.contains('collapsed'));
    });

    const handleDesktopChange = (event) => {
      if (!event.matches) {
        setCollapsed(false);
      }
    };

    if (typeof desktopMedia.addEventListener === 'function') {
      desktopMedia.addEventListener('change', handleDesktopChange);
    } else if (typeof desktopMedia.addListener === 'function') {
      desktopMedia.addListener(handleDesktopChange);
    }
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const logoutBtn = getElement('employeeLogoutBtn');
    const refreshBtn = getElement('employeeRefreshBookingsBtn');
    const blocksRefreshBtn = getElement('employeeRefreshBlocksBtn');
    const blockFullDayBtn = getElement('employeeBlockFullDayBtn');
    const unblockFullDayBtn = getElement('employeeUnblockFullDayBtn');
    const passwordForm = getElement('employeePasswordForm');

    initEmployeeSidebarToggle();
    bindTabs();
    bindPasswordToggles();

    employeeSelectedBlocksDate = formatLocalDate(new Date());
    employeeBlocksViewDate = dateFromLocalString(employeeSelectedBlocksDate);
    renderEmployeeBlocksCalendar();
    renderEmployeeTimeTitle();

    if (logoutBtn) {
      logoutBtn.addEventListener('click', handleLogout);
    }

    if (refreshBtn) {
      refreshBtn.addEventListener('click', loadBookings);
    }

    if (blocksRefreshBtn) {
      blocksRefreshBtn.addEventListener('click', loadServiceSlots);
    }

    if (blockFullDayBtn) {
      blockFullDayBtn.addEventListener('click', () => handleEmployeeDayAction('block'));
    }

    if (unblockFullDayBtn) {
      unblockFullDayBtn.addEventListener('click', () => handleEmployeeDayAction('unblock'));
    }

    if (passwordForm) {
      passwordForm.addEventListener('submit', handlePasswordChange);
    }

    const staff = await loadStaffSession();

    if (staff) {
      loadBookings();
    }
  });
})();
