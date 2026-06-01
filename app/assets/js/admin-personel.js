(function () {
  let initialized = false;

  const weekdays = [
    { value: 1, label: 'Poniedziałek' },
    { value: 2, label: 'Wtorek' },
    { value: 3, label: 'Środa' },
    { value: 4, label: 'Czwartek' },
    { value: 5, label: 'Piątek' },
    { value: 6, label: 'Sobota' },
    { value: 7, label: 'Niedziela' },
  ];

  const state = {
    staff: [],
    selectedId: null,
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
    els.sortOrder = section.querySelector('#personel-sort-order');
    els.isActive = section.querySelector('#personel-is-active');
    els.sendInvite = section.querySelector('#personel-send-invite');
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
    const response = await fetch(url, {
      credentials: 'same-origin',
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

    return {
      id: row && row.id ? String(row.id) : '',
      display_name: row && row.display_name ? String(row.display_name) : '',
      email: row && row.email ? String(row.email) : '',
      phone: row && row.phone ? String(row.phone) : '',
      description: row && row.description ? String(row.description) : '',
      color,
      sort_order: Number.isFinite(Number(row && row.sort_order)) ? Number(row.sort_order) : 0,
      is_active: row && row.is_active === true,
      created_at: row && row.created_at ? row.created_at : null,
      updated_at: row && row.updated_at ? row.updated_at : null,
    };
  }

  function findSelectedStaff() {
    return state.staff.find((person) => person.id === state.selectedId) || null;
  }

  function getStaffSearchText(person) {
    return [
      person.display_name,
      person.email,
      person.phone,
      person.is_active ? 'aktywny' : 'nieaktywny',
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
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'personel-list-item';
      item.dataset.staffId = person.id;
      item.setAttribute('aria-pressed', person.id === state.selectedId ? 'true' : 'false');

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
      meta.textContent = person.email || person.phone || 'Brak danych kontaktowych';

      const badges = document.createElement('span');
      badges.className = 'personel-badges';

      const activeBadge = document.createElement('span');
      activeBadge.className = person.is_active ? 'personel-badge is-active' : 'personel-badge is-inactive';
      activeBadge.textContent = person.is_active ? 'Aktywny' : 'Nieaktywny';
      badges.append(activeBadge);
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
      els.saveAvailabilityButton.disabled = !state.selectedId;
    }
  }

  function populateForm(person) {
    const selected = person || null;
    state.selectedId = selected ? selected.id : null;

    els.id.value = selected ? selected.id : '';
    els.displayName.value = selected ? selected.display_name : '';
    els.email.value = selected ? selected.email : '';
    els.phone.value = selected ? selected.phone : '';
    els.description.value = selected ? selected.description : '';
    els.color.value = selected && selected.color ? selected.color : '#2563eb';
    els.sortOrder.value = selected && Number.isFinite(selected.sort_order) ? String(selected.sort_order) : '';
    els.isActive.checked = selected ? selected.is_active : true;

    if (els.sendInvite) {
      els.sendInvite.checked = false;
    }

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

      if (state.selectedId && !findSelectedStaff()) {
        state.selectedId = null;
      }

      renderStaffList();
      populateForm(findSelectedStaff());

      if (!state.selectedId) {
        resetAvailability();
      }
    } catch (error) {
      setMessage(els.listMessage, error.message || 'Nie udało się załadować personelu.', 'error');
    }
  }

  async function loadAvailability(staffId) {
    if (!staffId) return;

    setMessage(els.availabilityMessage, 'Ładowanie grafiku...', 'muted');

    try {
      const data = await requestJson(`/api/staff/availability.php?staff_id=${encodeURIComponent(staffId)}`, {
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

  async function selectStaff(staffId) {
    const person = state.staff.find((item) => item.id === staffId);

    if (!person) return;

    populateForm(person);
    setMessage(els.formMessage, '', '');
    await loadAvailability(person.id);
  }

  function readProfilePayload() {
    const displayName = els.displayName.value.trim();

    if (displayName === '') {
      throw new Error('Podaj imię i nazwisko osoby.');
    }

    const payload = {
      display_name: displayName,
      email: els.email.value.trim(),
      phone: els.phone.value.trim(),
      description: els.description.value.trim(),
      color: els.color.value || '#2563eb',
      sort_order: els.sortOrder.value.trim() === '' ? 0 : Number.parseInt(els.sortOrder.value, 10),
      is_active: els.isActive.checked
    };

    if (Number.isNaN(payload.sort_order)) {
      throw new Error('Kolejność musi być liczbą.');
    }


    if (state.selectedId) {
      payload.id = state.selectedId;
    }

    return payload;
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
      state.selectedId = saved.id;

      if (shouldSendInvite) {
        if (!saved.id) {
          setMessage(els.formMessage, 'Osoba została zapisana, ale nie udało się wysłać zaproszenia: brak identyfikatora osoby.', 'error');
        } else {
          try {
            await requestJson('/api/staff/invite.php', {
              method: 'POST',
              body: JSON.stringify({ staff_id: saved.id }),
            });

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

      if (saved.id) {
        await loadAvailability(saved.id);
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
        body: JSON.stringify({ id: selected.id }),
      });

      state.selectedId = null;
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
    if (!state.selectedId) {
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
      staff_id: state.selectedId,
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

    els.searchInput.addEventListener('input', () => {
      state.searchQuery = els.searchInput.value;
      renderStaffList();
    });

    els.list.addEventListener('click', (event) => {
      const item = event.target.closest('.personel-list-item');

      if (!item) return;

      selectStaff(item.dataset.staffId);
    });
  }

  function initAdminPersonel() {
    if (initialized) return;

    const section = document.querySelector('section[data-section="personel"]');
    if (!section) return;

    initialized = true;
    cacheElements(section);
    renderAvailabilityRows(new Map());
    bindEvents();
    startNewPerson();
    loadStaffList();
  }

  document.addEventListener('DOMContentLoaded', initAdminPersonel);
})();
