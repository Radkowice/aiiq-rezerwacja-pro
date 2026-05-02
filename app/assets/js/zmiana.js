const ALL_TIMES = ['09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00'];

function getEl(id) {
  return document.getElementById(id);
}

function showError(message) {
  const errorBox = getEl('errorBox');
  const successBox = getEl('successBox');
  successBox.style.display = 'none';
  errorBox.textContent = message;
  errorBox.style.display = 'block';
}

function showSuccess(message) {
  const errorBox = getEl('errorBox');
  const successBox = getEl('successBox');
  errorBox.style.display = 'none';
  successBox.textContent = message;
  successBox.style.display = 'block';
}

function clearMessages() {
  getEl('errorBox').style.display = 'none';
  getEl('successBox').style.display = 'none';
}

async function getSettings() {
  const res = await fetch('/api/booking/blocked.php', { cache: 'no-store' });
  if (!res.ok) {
    throw new Error('Nie uda³o siê pobraæ dostêpnych terminów.');
  }
  return await res.json();
}

function setMinDate() {
  const dateInput = getEl('date');
  if (!dateInput) return;

  const today = new Date();
  dateInput.min = today.toISOString().split('T')[0];
}

function getParams() {
  const params = new URLSearchParams(window.location.search);
  return {
    email: params.get('email') || '',
    oldDate: params.get('date') || '',
    oldTime: params.get('time') || ''
  };
}

function renderInfo() {
  const { email, oldDate, oldTime } = getParams();
  const infoBox = getEl('infoBox');

  if (!email || !oldDate || !oldTime) {
    showError('Brak danych rezerwacji w linku.');
    return false;
  }

  infoBox.innerHTML = `Aktualna rezerwacja: <strong>${oldDate}</strong> o <strong>${oldTime}</strong><br>E-mail: <strong>${email}</strong>`;
  return true;
}

async function renderTimeOptions() {
  const dateInput = getEl('date');
  const select = getEl('time');

  if (!dateInput || !select) return;

  const date = dateInput.value;
  select.innerHTML = '<option value="">Wybierz godzinê *</option>';

  if (!date) return;

  try {
    const settings = await getSettings();
    const blocked = settings.blockedTimes?.[date] || [];

    if (blocked.includes('all')) {
      select.innerHTML = '<option value="">Brak dostêpnoœci</option>';
      return;
    }

    let availableCount = 0;
    const now = new Date();
    const todayStr = new Date().toISOString().split('T')[0];

    ALL_TIMES.forEach(time => {
      if (blocked.includes(time)) return;

      if (date === todayStr) {
        const [hours, minutes] = time.split(':').map(Number);
        const slotMinutes = hours * 60 + minutes;
        const nowMinutes = now.getHours() * 60 + now.getMinutes();

        if (slotMinutes <= nowMinutes) return;
      }

      const opt = document.createElement('option');
      opt.value = time;
      opt.textContent = time;
      select.appendChild(opt);
      availableCount++;
    });

    if (availableCount === 0) {
      select.innerHTML = '<option value="">Brak wolnych godzin</option>';
    }
  } catch (error) {
    showError('Nie uda³o siê pobraæ godzin. Odœwie¿ stronê i spróbuj ponownie.');
  }
}

async function changeBooking() {
  clearMessages();

  const { email, oldDate, oldTime } = getParams();
  const newDate = getEl('date').value;
  const newTime = getEl('time').value;
  const btn = getEl('changeBtn');

  if (!email || !oldDate || !oldTime) {
    showError('Link do zmiany terminu jest nieprawid³owy.');
    return;
  }

  if (!newDate) {
    showError('Wybierz now¹ datê.');
    return;
  }

  if (!newTime) {
    showError('Wybierz now¹ godzinê.');
    return;
  }
  
  if (newDate === oldDate && newTime === oldTime) {
  showError('Nowy termin jest taki sam jak obecny.');
  return;
}

  btn.disabled = true;
  btn.textContent = '? Zmieniam...';

  try {
    const res = await fetch('/api/booking/zmien-rezerwacje.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        email,
        oldDate,
        oldTime,
        newDate,
        newTime
      })
    });

    const result = await res.json();

    if (!res.ok || result.status !== 'success') {
      showError(result.message || 'Nie uda³o siê zmieniæ terminu.');
      return;
    }

    showSuccess('Termin konsultacji zosta³ zmieniony.');
  } catch (error) {
    showError('B³¹d po³¹czenia z serwerem. Spróbuj ponownie za chwilê.');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Zmieñ termin';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  setMinDate();
  renderInfo();

  const dateEl = getEl('date');
  const btn = getEl('changeBtn');

  if (dateEl) {
    dateEl.addEventListener('change', renderTimeOptions);
  }

  if (btn) {
    btn.addEventListener('click', changeBooking);
  }
});