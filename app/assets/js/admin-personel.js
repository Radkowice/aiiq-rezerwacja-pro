(function () {
  let adminStaffInitialized = false;

  const weekdays = [
    { value: 1, label: 'Poniedziałek' },
    { value: 2, label: 'Wtorek' },
    { value: 3, label: 'Środa' },
    { value: 4, label: 'Czwartek' },
    { value: 5, label: 'Piątek' },
    { value: 6, label: 'Sobota' },
    { value: 7, label: 'Niedziela' },
  ];

  const STAFF_COLOR_PALETTE = [
    { value: '#2563eb', label: 'Niebieski' },
    { value: '#1e3a8a', label: 'Granatowy' },
    { value: '#0891b2', label: 'Turkusowy' },
    { value: '#16a34a', label: 'Zielony' },
    { value: '#65a30d', label: 'Oliwkowy' },
    { value: '#ca8a04', label: 'Żółty' },
    { value: '#ea580c', label: 'Pomarańczowy' },
    { value: '#dc2626', label: 'Czerwony' },
    { value: '#c026d3', label: 'Fioletowy' },
    { value: '#db2777', label: 'Różowy' },
    { value: '#7c3aed', label: 'Indygo' },
    { value: '#475569', label: 'Grafitowy' },
  ];

  const state = {
    staff: [],
    selectedRef: null,
    searchQuery: '',
  };

  const els = {};

  function cacheElements(section) {
    els.section = section;
    els.list = section.querySelector('#personel-list');
    els.listMessage = section.querySelector('#personel-list-message');
    els.searchInput = section.querySelector('#personel-search-input');
    els.formMessage = section.querySelector('#personel-form-message');
    els.availabilityMessage = section.querySelector('#personel-availability-message');
    els.addButton = section.querySelector('#personel-add-btn');
    els.deleteButton = section.querySelector('#personel-delete-btn');
    els.profileForm = section.querySelector('#personel-profile-form');
    els.availabilityForm = section.querySelector('#personel-availability-form');
    els.days = section.querySelector('#personel-days');
    els.saveAvailabilityButton = section.querySelector('#personel-save-availability-btn');
    els.id = section.querySelector('#personel-id');
    els.displayName = section.querySelector('#personel-display-name');
    els.email = section.querySelector('#personel-email');
    els.phone = section.querySelector('#personel-phone');
    els.description = section.querySelector('#personel-description');
    els.color = section.querySelector('#personel-color');
    els.colorPicker = section.querySelector('#personel-color-picker');
    els.colorTrigger = section.querySelector('#personel-color-trigger');
    els.colorTriggerSwatch = section.querySelector('#personel-color-trigger-swatch');
    els.colorPalette = section.querySelector('#personel-color-palette');
    els.sortOrder = section.querySelector('#personel-sort-order');
    els.isActive = section.querySelector('#personel-is-active');
    els.sendInvite = section.querySelector('#personel-send-invite');
    els.inviteStatus = section.querySelector('#personel-invite-status');
    els.resendInviteButton = section.querySelector('#personel-resend-invite-btn');

    ensureStaffColorPaletteControls();
    ensureInviteStatusControls();
  }

  function setMessage(element, text, type) {
    if (!element) return;

    element.textContent = text || '';
    element.dataset.type = type || '';
    element.hidden = !text;
  }

  function getErrorMessage(data, fallback) {
    if (data && typeof data.message === 'string' && data.message.trim() !== '') {
      return data.message;
    }

    if (data && typeof data.error === 'string' && data.error.trim() !== '') {
      return data.error;
    }

    return fallback;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function normalizeStaffColorValue(value) {
    const color = String(value || '').trim().toLowerCase();

    if (/^#[0-9a-f]{6}$/i.test(color)) {
      return color;
    }

    return STAFF_COLOR_PALETTE[0].value;
  }

  function closeStaffColorPalette() {
    if (!els.colorPalette || !els.colorTrigger) return;

    els.colorPalette.hidden = true;
    els.colorTrigger.setAttribute('aria-expanded', 'false');
  }

  function openStaffColorPalette() {
    if (!els.colorPalette || !els.colorTrigger) return;

    els.colorPalette.hidden = false;
    els.colorTrigger.setAttribute('aria-expanded', 'true');
  }

  function toggleStaffColorPalette() {
    if (!els.colorPalette || !els.colorTrigger) return;

    if (els.colorPalette.hidden) {
      openStaffColorPalette();
      return;
    }

    closeStaffColorPalette();
  }

  function renderStaffColorPalette(selectedColor) {
    const selected = normalizeStaffColorValue(selectedColor);

    if (els.colorTriggerSwatch) {
      els.colorTriggerSwatch.style.backgroundColor = selected;
    }

    if (!els.colorPalette) return;

    els.colorPalette.querySelectorAll('[data-staff-color]').forEach((button) => {
      const buttonColor = normalizeStaffColorValue(button.dataset.staffColor);
      const isActive = buttonColor === selected;

      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
  }

  function setStaffColor(colorValue, options = {}) {
    const color = normalizeStaffColorValue(colorValue);

    if (els.color) {
      els.color.value = color;
    }

    renderStaffColorPalette(color);

    if (options.close !== false) {
      closeStaffColorPalette();
    }
  }

  function ensureStaffColorPaletteControls() {
    if (!els.color) return;

    const colorInput = els.color;
    const host = colorInput.closest('label')?.parentElement || colorInput.parentElement;

    colorInput.type = 'hidden';
    colorInput.value = normalizeStaffColorValue(colorInput.value);

    if (!host) return;

    if (!els.colorPicker) {
      const picker = document.createElement('div');
      picker.id = 'personel-color-picker';
      picker.className = 'personel-color-picker';

      const trigger = document.createElement('button');
      trigger.id = 'personel-color-trigger';
      trigger.type = 'button';
      trigger.className = 'personel-color-trigger';
      trigger.setAttribute('aria-haspopup', 'listbox');
      trigger.setAttribute('aria-expanded', 'false');
      trigger.setAttribute('aria-controls', 'personel-color-palette');

      const triggerSwatch = document.createElement('span');
      triggerSwatch.id = 'personel-color-trigger-swatch';
      triggerSwatch.className = 'personel-color-trigger-swatch';
      triggerSwatch.setAttribute('aria-hidden', 'true');

      const triggerText = document.createElement('span');
      triggerText.className = 'sr-only';
      triggerText.textContent = 'Wybierz kolor osoby z personelu';

      const palette = document.createElement('div');
      palette.id = 'personel-color-palette';
      palette.className = 'personel-color-menu';
      palette.setAttribute('role', 'listbox');
      palette.setAttribute('aria-label', 'Kolor osoby z personelu');
      palette.hidden = true;

      STAFF_COLOR_PALETTE.forEach((item) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'personel-color-preset';
        button.dataset.staffColor = item.value;
        button.setAttribute('role', 'option');
        button.setAttribute('aria-label', item.label);
        button.setAttribute('aria-selected', 'false');

        const swatch = document.createElement('span');
        swatch.className = 'personel-color-preset-swatch';
        swatch.style.backgroundColor = item.value;
        swatch.setAttribute('aria-hidden', 'true');

        button.appendChild(swatch);
        palette.appendChild(button);
      });

      trigger.append(triggerSwatch, triggerText);
      picker.append(trigger, palette);
      colorInput.insertAdjacentElement('afterend', picker);

      els.colorPicker = picker;
      els.colorTrigger = trigger;
      els.colorTriggerSwatch = triggerSwatch;
      els.colorPalette = palette;
    } else {
      els.colorTrigger = els.colorPicker.querySelector('#personel-color-trigger');
      els.colorTriggerSwatch = els.colorPicker.querySelector('#personel-color-trigger-swatch');
      els.colorPalette = els.colorPicker.querySelector('#personel-color-palette');
    }

    if (els.colorPicker && !els.colorPicker.dataset.bound) {
      els.colorTrigger?.addEventListener('click', (event) => {
        event.preventDefault();
        toggleStaffColorPalette();
      });

      els.colorPalette?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-staff-color]');

        if (!button) return;

        event.preventDefault();
        setStaffColor(button.dataset.staffColor);
      });

      document.addEventListener('click', (event) => {
        if (!els.colorPicker || els.colorPicker.contains(event.target)) return;
        closeStaffColorPalette();
      });

      document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        closeStaffColorPalette();
      });

      els.colorPicker.dataset.bound = 'true';
    }

    renderStaffColorPalette(colorInput.value);
  }

  function ensureInviteStatusControls() {
    if (!els.sendInvite) return;

    const host = els.sendInvite.closest('.personel-invite-box')
      || els.sendInvite.closest('label')?.parentElement
      || els.sendInvite.parentElement;

    if (!host) return;

    if (!els.inviteStatus) {
      const status = document.createElement('div');
      status.id = 'personel-invite-status';
      status.className = 'personel-invite-status';
      status.hidden = true;
      host.appendChild(status);
      els.inviteStatus = status;
    }

    if (!els.resendInviteButton) {
      const button = document.createElement('button');
      button.id = 'personel-resend-invite-btn';
      button.type = 'button';
      button.className = 'personel-resend-invite-btn';
      button.textContent = 'Zresetuj token i wyślij ponownie zaproszenie';
      button.hidden = true;
      host.appendChild(button);
      els.resendInviteButton = button;
    }
  }

  function formatStaffDateTime(value) {
    const date = value ? new Date(value) : null;

    if (!date || Number.isNaN(date.getTime())) {
      return '';
    }

    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');

    return `${day}.${month}.${year} ${hours}:${minutes}`;
  }

  function resolveInviteStatus(person) {
    const status = String(person?.invite_status || 'none').trim();
    const expiresLabel = String(person?.invite_expires_at_label || '').trim()
      || formatStaffDateTime(person?.invite_expires_at);
    const backendLabel = String(person?.invite_status_label || '').trim();

    if (!person) {
      return {
        status: 'none',
        label: '',
        type: '',
        canSend: false,
        buttonText: 'Wyślij zaproszenie'
      };
    }

    if (status === 'activated') {
      return {
        status,
        label: 'Aktywowano konto.',
        type: 'success',
        canSend: false,
        buttonText: ''
      };
    }

    if (status === 'sent') {
      return {
        status,
        label: backendLabel || (expiresLabel ? `Wysłano zaproszenie. Link ważny do ${expiresLabel}.` : 'Wysłano zaproszenie.'),
        type: 'success',
        canSend: true,
        buttonText: 'Zresetuj token i wyślij ponownie zaproszenie'
      };
    }

    if (status === 'expired') {
      return {
        status,
        label: 'Link aktywacyjny wygasł.',
        type: 'error',
        canSend: true,
        buttonText: 'Zresetuj token i wyślij ponownie zaproszenie'
      };
    }

    if (status === 'unknown') {
      return {
        status,
        label: 'Nie udało się odczytać statusu zaproszenia.',
        type: 'muted',
        canSend: true,
        buttonText: 'Wyślij ponownie zaproszenie'
      };
    }

    return {
      status: 'none',
      label: 'Zaproszenie nie zostało jeszcze wysłane.',
      type: 'muted',
      canSend: true,
      buttonText: 'Wyślij zaproszenie'
    };
  }

  function renderInviteStatus(person) {
    ensureInviteStatusControls();

    if (!els.inviteStatus) return;

    if (!person) {
      els.inviteStatus.textContent = '';
      els.inviteStatus.dataset.type = '';
      els.inviteStatus.hidden = true;

      if (els.resendInviteButton) {
        els.resendInviteButton.hidden = true;
        els.resendInviteButton.disabled = true;
      }

      if (els.sendInvite) {
        els.sendInvite.disabled = false;
      }

      return;
    }

    const invite = resolveInviteStatus(person);

    els.inviteStatus.textContent = invite.label;
    els.inviteStatus.dataset.type = invite.type;
    els.inviteStatus.hidden = invite.label === '';

    if (els.sendInvite) {
      els.sendInvite.disabled = invite.status === 'activated';
    }

    if (els.resendInviteButton) {
      const hasEmail = String(person.email || '').trim() !== '';
      els.resendInviteButton.textContent = invite.buttonText || 'Wyślij zaproszenie';
      els.resendInviteButton.hidden = !invite.canSend || !hasEmail;
      els.resendInviteButton.disabled = !invite.canSend || !hasEmail;
    }
  }

  function getInviteBadgeText(person) {
    const status = String(person?.invite_status || 'none').trim();

    if (status === 'activated') return 'Konto aktywowane';
    if (status === 'sent') return 'Zaproszenie wysłane';
    if (status === 'expired') return 'Link wygasł';
    return '';
  }

  function createRequestError(data, fallback) {
    const error = new Error(getErrorMessage(data, fallback));
    error.data = data;
    return error;
  }

  async function showStaffDeleteInfo(description, icon = '⚠️') {
    if (typeof window.openAdminConfirm === 'function') {
      await window.openAdminConfirm({
        title: 'Nie można usunąć pracownika',
        html: `
          <div class="staff-delete-modal">
            <p>${escapeHtml(description)}</p>
          </div>
        `,
        confirmText: 'Zamknij',
        cancelText: 'Zamknij',
        variant: 'danger',
        icon,
        showCancel: false,
      });
      return;
    }

    setMessage(els.formMessage, description, 'error');
  }

  async function showStaffDeleteConfirm(selected) {
    if (typeof window.openAdminConfirm !== 'function') {
      setMessage(els.formMessage, 'Nie można pokazać okna potwierdzenia.', 'error');
      return false;
    }

    return window.openAdminConfirm({
      title: 'Usunąć pracownika?',
      html: `
        <div class="staff-delete-modal">
          <p>
            Ta operacja usunie pracownika z systemu. Historyczne rezerwacje nie blokują usunięcia,
            ale tej operacji nie można cofnąć.
          </p>
          <ul>
            <li>Pracownik: <strong>${escapeHtml(selected.display_name || 'bez nazwy')}</strong></li>
          </ul>
        </div>
      `,
      confirmText: 'Usuń pracownika',
      cancelText: 'Anuluj',
      variant: 'danger',
      icon: '🗑️',
    });
  }

  function getStaffDeleteBlockInfo(error) {
    const data = error && error.data ? error.data : null;
    const reason = String(data?.reason || data?.code || '').toLowerCase();
    const rawMessage = String(error?.message || '').toLowerCase();

    if (reason === 'staff_active' || rawMessage.includes('wyłącz pracownika') || rawMessage.includes('aktywn')) {
      return {
        message: 'Ten pracownik jest aktywny. Najpierw odznacz opcję „Aktywny”, zapisz zmiany i spróbuj ponownie.',
        icon: '⚠️',
      };
    }

    if (reason === 'staff_has_services' || (data && data.has_services)) {
      return {
        message: 'Ten pracownik ma przypisane usługi. Najpierw odłącz go od usług, a potem spróbuj ponownie.',
        icon: '🔗',
      };
    }

    if (reason === 'staff_has_future_bookings' || (data && data.has_future_bookings)) {
      return {
        message: 'Ten pracownik ma zaplanowane rezerwacje. Najpierw zmień obsługę tych terminów albo anuluj rezerwacje.',
        icon: '📅',
      };
    }

    if (reason === 'delete_failed_related_data') {
      return {
        message: 'Nie udało się usunąć pracownika, ponieważ system wykrył dodatkowe powiązane dane. Sprawdź przypisane usługi, grafik, blokady lub rezerwacje i spróbuj ponownie.',
        icon: '🔎',
      };
    }

    return {
      message: 'Nie udało się usunąć pracownika. Odśwież listę personelu i spróbuj ponownie.',
      icon: '❌',
    };
  }

  async function requestJson(url, options) {
    if (typeof window.apiFetch === 'function') {
      const data = await window.apiFetch(url, {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...(options && options.headers ? options.headers : {}),
        },
        ...options,
      });

      if (!data || data.success !== true) {
        throw createRequestError(data, 'Nie udało się wykonać operacji');
      }

      return data;
    }

    if (typeof window.adminRequest !== 'function') {
      throw new Error('Brak helpera requestów administracyjnych.');
    }

    const response = await window.adminRequest(url, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(options && options.headers ? options.headers : {}),
      },
      ...options,
    });

    const text = await response.text();
    let data = null;

    if (text) {
      try {
        data = JSON.parse(text);
      } catch (error) {
        throw new Error('Nieprawidłowa odpowiedź serwera');
      }
    }

    if (!response.ok || !data || data.success !== true) {
      throw createRequestError(data, 'Nie udało się wykonać operacji');
    }

    return data;
  }

  function normalizeStaff(row) {
    const color = row && typeof row.color === 'string' && /^#[0-9a-f]{6}$/i.test(row.color)
      ? row.color
      : '#2563eb';
    const staffRef = row && (row.staff_ref || row.staffRef || row.id) ? String(row.staff_ref || row.staffRef || row.id) : '';

    return {
      staff_ref: staffRef,
      display_name: row && row.display_name ? String(row.display_name) : '',
      email: row && row.email ? String(row.email) : '',
      phone: row && row.phone ? String(row.phone) : '',
      description: row && row.description ? String(row.description) : '',
      color,
      sort_order: Number.isFinite(Number(row && row.sort_order)) ? Number(row.sort_order) : 0,
      is_active: row && row.is_active === true,
      invite_status: row && row.invite_status ? String(row.invite_status) : 'none',
      invite_status_label: row && row.invite_status_label ? String(row.invite_status_label) : '',
      invite_expires_at: row && row.invite_expires_at ? row.invite_expires_at : null,
      invite_expires_at_label: row && row.invite_expires_at_label ? String(row.invite_expires_at_label) : '',
      staff_account_active: row && row.staff_account_active === true,
      staff_account_has_password: row && row.staff_account_has_password === true,
      created_at: row && row.created_at ? row.created_at : null,
      updated_at: row && row.updated_at ? row.updated_at : null,
    };
  }

  function getStaffRef(person) {
    return person ? String(person.staff_ref || person.staffRef || '').trim() : '';
  }

  function findSelectedStaff() {
    return state.staff.find((person) => getStaffRef(person) === state.selectedRef) || null;
  }

  function getStaffListDescription(person) {
    const description = String(person?.description || '').trim();

    if (!description) {
      return 'Brak opisu osoby';
    }

    return description.length > 90
      ? `${description.slice(0, 87).trim()}...`
      : description;
  }

  function getStaffSearchText(person) {
    return [
      person.display_name,
      person.description,
      person.email,
      person.phone,
      person.is_active ? 'aktywny' : 'nieaktywny',
      person.invite_status_label,
      getInviteBadgeText(person),
    ].join(' ').toLowerCase();
  }

  function getFilteredStaff() {
    const query = state.searchQuery.trim().toLowerCase();

    if (!query) {
      return state.staff;
    }

    return state.staff.filter((person) => getStaffSearchText(person).includes(query));
  }

  function renderStaffList() {
    if (!els.list) return;

    els.list.innerHTML = '';

    if (state.staff.length === 0) {
      setMessage(els.listMessage, 'Brak osób w personelu.', 'muted');
      return;
    }

    const visibleStaff = getFilteredStaff();

    if (visibleStaff.length === 0) {
      setMessage(els.listMessage, 'Brak wyników dla podanej frazy.', 'muted');
      return;
    }

    setMessage(els.listMessage, 'Personel załadowany.', 'success');

    const fragment = document.createDocumentFragment();

    visibleStaff.forEach((person) => {
      const staffRef = getStaffRef(person);
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'personel-list-item';
      item.dataset.staffRef = staffRef;
      item.setAttribute('aria-pressed', staffRef === state.selectedRef ? 'true' : 'false');

      if (!person.is_active) {
        item.classList.add('is-inactive');
      }

      const color = document.createElement('span');
      color.className = 'personel-color-dot';
      color.style.backgroundColor = person.color || '#2563eb';

      const body = document.createElement('span');
      body.className = 'personel-list-body';

      const name = document.createElement('strong');
      name.textContent = person.display_name || 'Bez nazwy';

      const meta = document.createElement('span');
      meta.className = 'personel-list-meta';
      meta.textContent = getStaffListDescription(person);
      meta.title = String(person.description || '').trim() || 'Brak opisu osoby';

      const badges = document.createElement('span');
      badges.className = 'personel-badges';

      const activeBadge = document.createElement('span');
      activeBadge.className = person.is_active ? 'personel-badge is-active' : 'personel-badge is-inactive';
      activeBadge.textContent = person.is_active ? 'Aktywny' : 'Nieaktywny';
      badges.append(activeBadge);

      const inviteBadgeText = getInviteBadgeText(person);

      if (inviteBadgeText) {
        const inviteBadge = document.createElement('span');
        inviteBadge.className = `personel-badge invite-status-${String(person.invite_status || 'none').replace(/[^a-z0-9_-]/gi, '')}`;
        inviteBadge.textContent = inviteBadgeText;
        badges.append(inviteBadge);
      }

      body.append(name, meta, badges);
      item.append(color, body);
      fragment.append(item);
    });

    els.list.append(fragment);
  }

  function renderAvailabilityRows(valuesByWeekday) {
    if (!els.days) return;

    els.days.innerHTML = '';

    const fragment = document.createDocumentFragment();

    weekdays.forEach((day) => {
      const value = valuesByWeekday && valuesByWeekday.get(day.value);
      const row = document.createElement('div');
      row.className = 'personel-day-row';
      row.dataset.weekday = String(day.value);

      const activeLabel = document.createElement('label');
      activeLabel.className = 'personel-day-active';

      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.className = 'personel-day-checkbox';
      checkbox.checked = Boolean(value && value.is_active);

      const labelText = document.createElement('span');
      labelText.textContent = day.label;

      activeLabel.append(checkbox, labelText);

      const timeGroup = document.createElement('div');
      timeGroup.className = 'personel-day-times';

      const startInput = document.createElement('input');
      startInput.type = 'time';
      startInput.className = 'personel-day-start';
      startInput.value = value && value.start_time ? value.start_time.slice(0, 5) : '08:00';

      const separator = document.createElement('span');
      separator.textContent = 'do';

      const endInput = document.createElement('input');
      endInput.type = 'time';
      endInput.className = 'personel-day-end';
      endInput.value = value && value.end_time ? value.end_time.slice(0, 5) : '16:00';

      timeGroup.append(startInput, separator, endInput);
      row.append(activeLabel, timeGroup);
      fragment.append(row);
    });

    els.days.append(fragment);
  }

  function resetAvailability() {
    renderAvailabilityRows(new Map());
    if (els.saveAvailabilityButton) {
      els.saveAvailabilityButton.disabled = !state.selectedRef;
    }
  }

  function populateForm(person) {
    const selected = person || null;
    const selectedRef = selected ? getStaffRef(selected) : '';
    state.selectedRef = selectedRef || null;

    els.id.value = selectedRef;
    els.displayName.value = selected ? selected.display_name : '';
    els.email.value = selected ? selected.email : '';
    els.phone.value = selected ? selected.phone : '';
    els.description.value = selected ? selected.description : '';
    setStaffColor(selected && selected.color ? selected.color : STAFF_COLOR_PALETTE[0].value);
    els.sortOrder.value = selected && Number.isFinite(selected.sort_order) ? String(selected.sort_order) : '';
    els.isActive.checked = selected ? selected.is_active : true;

    if (els.sendInvite) {
      els.sendInvite.checked = false;
      els.sendInvite.disabled = selected ? resolveInviteStatus(selected).status === 'activated' : false;
    }

    renderInviteStatus(selected);

    if (els.deleteButton) {
      els.deleteButton.hidden = !selected || selected.is_active;
      els.deleteButton.disabled = !selected || selected.is_active;
    }

    if (els.saveAvailabilityButton) {
      els.saveAvailabilityButton.disabled = !selected;
    }

    renderStaffList();
  }

  function startNewPerson() {
    populateForm(null);
    resetAvailability();
    setMessage(els.formMessage, '', '');
    setMessage(els.availabilityMessage, 'Wybierz osobę, aby edytować grafik.', 'muted');
    els.displayName.focus();
  }

  async function loadStaffList() {
    setMessage(els.listMessage, 'Ładowanie personelu...', 'muted');

    try {
      const data = await requestJson('/api/staff/list.php', { method: 'GET' });
      state.staff = Array.isArray(data.staff) ? data.staff.map(normalizeStaff) : [];

      if (state.selectedRef && !findSelectedStaff()) {
        state.selectedRef = null;
      }

      renderStaffList();
      populateForm(findSelectedStaff());

      if (!state.selectedRef) {
        resetAvailability();
      }
    } catch (error) {
      setMessage(els.listMessage, error.message || 'Nie udało się załadować personelu.', 'error');
    }
  }

  async function loadAvailability(staffRef) {
    if (!staffRef) return;

    setMessage(els.availabilityMessage, 'Ładowanie grafiku...', 'muted');

    try {
      const data = await requestJson(`/api/staff/availability.php?staff_ref=${encodeURIComponent(staffRef)}`, {
        method: 'GET',
      });

      const byWeekday = new Map();

      (Array.isArray(data.availability) ? data.availability : []).forEach((entry) => {
        const weekday = Number(entry.weekday);

        if (!byWeekday.has(weekday)) {
          byWeekday.set(weekday, {
            is_active: entry.is_active === true,
            start_time: entry.start_time || '08:00',
            end_time: entry.end_time || '16:00',
          });
        }
      });

      renderAvailabilityRows(byWeekday);
      setMessage(els.availabilityMessage, byWeekday.size ? 'Grafik załadowany.' : 'Brak grafiku. Ustaw dni pracy i zapisz.', byWeekday.size ? 'success' : 'muted');
    } catch (error) {
      resetAvailability();
      setMessage(els.availabilityMessage, error.message || 'Nie udało się załadować grafiku.', 'error');
    }
  }

  async function selectStaff(staffRef) {
    const person = state.staff.find((item) => getStaffRef(item) === staffRef);

    if (!person) return;

    populateForm(person);
    setMessage(els.formMessage, '', '');
    await loadAvailability(getStaffRef(person));
  }

  function readProfilePayload() {
    const displayName = els.displayName.value.trim();

    if (displayName === '') {
      throw new Error('Podaj imię i nazwisko osoby.');
    }

    const sortOrderValue = els.sortOrder.value.trim();
    const sortOrder = sortOrderValue === '' ? 0 : Number.parseInt(sortOrderValue, 10);

    if (sortOrderValue !== '' && !/^-?\d+$/.test(sortOrderValue)) {
      throw new Error('Kolejność musi być liczbą całkowitą.');
    }

    if (sortOrder < 0) {
      throw new Error('Kolejność nie może być mniejsza niż 0.');
    }

    const payload = {
      display_name: displayName,
      email: els.email.value.trim(),
      phone: els.phone.value.trim(),
      description: els.description.value.trim(),
      color: normalizeStaffColorValue(els.color.value),
      sort_order: sortOrder,
      is_active: els.isActive.checked
    };

    if (state.selectedRef) {
      payload.staff_ref = state.selectedRef;
    }

    return payload;
  }

  async function sendStaffInvite(staffRef) {
    const cleanStaffRef = String(staffRef || '').trim();

    if (!cleanStaffRef) {
      throw new Error('Brak identyfikatora osoby z personelu.');
    }

    return requestJson('/api/staff/invite.php', {
      method: 'POST',
      body: JSON.stringify({ staff_ref: cleanStaffRef }),
    });
  }

  async function resendSelectedInvite() {
    const selected = findSelectedStaff();

    if (!selected) {
      setMessage(els.formMessage, 'Wybierz osobę, aby wysłać zaproszenie.', 'error');
      return;
    }

    if (!selected.email) {
      setMessage(els.formMessage, 'Aby wysłać zaproszenie, wpisz adres e-mail osoby z personelu.', 'error');
      return;
    }

    const invite = resolveInviteStatus(selected);

    if (invite.status === 'activated') {
      setMessage(els.formMessage, 'Konto pracownika jest już aktywne.', 'success');
      return;
    }

    const defaultText = els.resendInviteButton?.textContent || 'Wyślij zaproszenie';

    try {
      if (els.resendInviteButton) {
        els.resendInviteButton.disabled = true;
        els.resendInviteButton.textContent = 'Wysyłanie zaproszenia...';
      }

      await sendStaffInvite(getStaffRef(selected));

      setMessage(
        els.formMessage,
        invite.status === 'none'
          ? 'Wysłano zaproszenie.'
          : 'Zresetowano token i wysłano ponownie zaproszenie. Poprzednie linki są już nieważne.',
        'success'
      );

      await loadStaffList();
      populateForm(findSelectedStaff());
    } catch (error) {
      setMessage(els.formMessage, error.message || 'Nie udało się wysłać zaproszenia.', 'error');
    } finally {
      if (els.resendInviteButton) {
        els.resendInviteButton.textContent = defaultText;
      }

      renderInviteStatus(findSelectedStaff());
    }
  }

  async function saveProfile(event) {
    event.preventDefault();

    let payload;
    const shouldSendInvite = Boolean(els.sendInvite && els.sendInvite.checked);

    try {
      payload = readProfilePayload();
    } catch (error) {
      setMessage(els.formMessage, error.message, 'error');
      return;
    }

    if (shouldSendInvite && !payload.email) {
      setMessage(els.formMessage, 'Aby wysłać zaproszenie, wpisz adres e-mail osoby z personelu.', 'error');
      return;
    }

    setMessage(els.formMessage, 'Zapisywanie osoby...', 'muted');

    try {
      const data = await requestJson('/api/staff/save.php', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      const saved = normalizeStaff(data.staff || {});
      const savedRef = getStaffRef(saved);
      state.selectedRef = savedRef || null;

      if (shouldSendInvite) {
        if (!savedRef) {
          setMessage(els.formMessage, 'Osoba została zapisana, ale nie udało się wysłać zaproszenia: brak identyfikatora osoby.', 'error');
        } else {
          try {
            await sendStaffInvite(savedRef);

            setMessage(els.formMessage, 'Zapisano osobę i wysłano zaproszenie do panelu personelu.', 'success');

            if (els.sendInvite) {
              els.sendInvite.checked = false;
            }
          } catch (inviteError) {
            setMessage(
              els.formMessage,
              `Osoba została zapisana, ale nie udało się wysłać zaproszenia: ${inviteError.message || 'nieznany błąd'}`,
              'error'
            );
          }
        }
      } else {
        setMessage(els.formMessage, 'Zapisano osobę.', 'success');
      }

      await loadStaffList();
      populateForm(findSelectedStaff() || saved);

      if (savedRef) {
        await loadAvailability(savedRef);
      }
    } catch (error) {
      setMessage(els.formMessage, error.message || 'Nie udało się zapisać osoby.', 'error');
    }
  }

  async function deleteSelected() {
    const selected = findSelectedStaff();

    if (!selected) {
      setMessage(els.formMessage, 'Wybierz pracownika do usunięcia.', 'error');
      return;
    }

    if (selected.is_active) {
      const message = 'Ten pracownik jest aktywny. Najpierw odznacz opcję „Aktywny”, zapisz zmiany i spróbuj ponownie.';
      setMessage(els.formMessage, message, 'error');
      await showStaffDeleteInfo(message, '⚠️');
      return;
    }

    const confirmed = await showStaffDeleteConfirm(selected);

    if (!confirmed) return;

    setMessage(els.formMessage, 'Usuwanie pracownika...', 'muted');

    try {
      const data = await requestJson('/api/staff/delete.php', {
        method: 'POST',
        body: JSON.stringify({ staff_ref: getStaffRef(selected) }),
      });

      state.selectedRef = null;
      await loadStaffList();
      startNewPerson();
      setMessage(els.formMessage, data.message || 'Pracownik został usunięty.', 'success');
    } catch (error) {
      const info = getStaffDeleteBlockInfo(error);
      setMessage(els.formMessage, info.message, 'error');
      await showStaffDeleteInfo(info.message, info.icon);
    }
  }

  function readAvailabilityPayload() {
    if (!state.selectedRef) {
      throw new Error('Wybierz osobę, aby edytować grafik.');
    }

    const availability = [];

    els.days.querySelectorAll('.personel-day-row').forEach((row) => {
      const isActive = row.querySelector('.personel-day-checkbox').checked;

      if (!isActive) return;

      const weekday = Number.parseInt(row.dataset.weekday, 10);
      const startTime = row.querySelector('.personel-day-start').value;
      const endTime = row.querySelector('.personel-day-end').value;

      if (!/^\d{2}:\d{2}$/.test(startTime) || !/^\d{2}:\d{2}$/.test(endTime)) {
        throw new Error('Uzupełnij godziny w formacie HH:MM.');
      }

      if (startTime >= endTime) {
        throw new Error('Godzina rozpoczęcia musi być wcześniejsza niż zakończenia.');
      }

      availability.push({
        weekday,
        start_time: startTime,
        end_time: endTime,
        is_active: true,
      });
    });

    return {
      staff_ref: state.selectedRef,
      availability,
    };
  }

  async function saveAvailability(event) {
    event.preventDefault();

    let payload;

    try {
      payload = readAvailabilityPayload();
    } catch (error) {
      setMessage(els.availabilityMessage, error.message, 'error');
      return;
    }

    setMessage(els.availabilityMessage, 'Zapisywanie grafiku...', 'muted');

    try {
      const data = await requestJson('/api/staff/availability.php', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      const byWeekday = new Map();

      (Array.isArray(data.availability) ? data.availability : []).forEach((entry) => {
        byWeekday.set(Number(entry.weekday), {
          is_active: entry.is_active === true,
          start_time: entry.start_time || '08:00',
          end_time: entry.end_time || '16:00',
        });
      });

      renderAvailabilityRows(byWeekday);
      setMessage(els.availabilityMessage, 'Zapisano grafik.', 'success');
    } catch (error) {
      setMessage(els.availabilityMessage, error.message || 'Nie udało się zapisać grafiku.', 'error');
    }
  }

  function bindEvents() {
    els.addButton.addEventListener('click', startNewPerson);
    els.profileForm.addEventListener('submit', saveProfile);
    els.availabilityForm.addEventListener('submit', saveAvailability);
    els.deleteButton?.addEventListener('click', deleteSelected);
    els.resendInviteButton?.addEventListener('click', resendSelectedInvite);

    els.searchInput.addEventListener('input', () => {
      state.searchQuery = els.searchInput.value;
      renderStaffList();
    });

    els.list.addEventListener('click', (event) => {
      const item = event.target.closest('.personel-list-item');

      if (!item) return;

      selectStaff(item.dataset.staffRef);
    });
  }

  window.initAdminStaffModule = async function initAdminStaffModule() {
    if (adminStaffInitialized) return;

    const section = document.querySelector('section[data-section="personel"]');
    if (!section) return;

    adminStaffInitialized = true;
    cacheElements(section);
    renderAvailabilityRows(new Map());
    bindEvents();
    startNewPerson();
    await loadStaffList();
  };
})();
