(() => {
  document.addEventListener('DOMContentLoaded', () => {
    loadSettingsForm();
  });
  
  async function loadSettingsForm() {
  try {
    const res = await fetch('/api/system/settings.php', {
      cache: 'no-store',
      credentials: 'include'
    });

    const data = await res.json();

    if (!res.ok || !data?.success || !data?.settings) {
      throw new Error(data?.error || 'Nie udało się pobrać ustawień');
    }
    document.getElementById('booking-buffer').value = data.settings.booking_buffer || 0;
    document.getElementById('work-start').value = data.settings.work_start || '00:00';
    document.getElementById('work-end').value = data.settings.work_end || '23:59';
    document.getElementById('consultation-duration').value = data.settings.consultation_duration || 60;
    document.getElementById('consultation-break').value = data.settings.consultation_break || 0;
    document.getElementById('booking-start-month-offset').value = data.settings.booking_start_month_offset || 0;
    document.getElementById('booking-month-range').value = data.settings.booking_month_range || 1;
    
  } catch (error) {
    console.error('loadSettingsForm error:', error);
    showSettingsMessage('Nie udało się wczytać ustawień', 'error');
  }
}

  function isValidTimeRange(start, end) {
    return timeToMinutes(end) > timeToMinutes(start);
  }

  function timeToMinutes(time) {
    const [hours, minutes] = String(time).split(':').map(Number);
    return (hours * 60) + minutes;
  }

  function showSettingsMessage(text, type = 'success') {
    const messageEl = document.getElementById('settings-message');
    if (!messageEl) return;

    messageEl.textContent = text;
    messageEl.classList.remove('success', 'error');
    messageEl.classList.add(type);
    messageEl.style.display = 'block';
  }
})();

