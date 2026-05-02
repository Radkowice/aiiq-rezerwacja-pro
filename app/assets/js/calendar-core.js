export const FIXED_HOLIDAYS = [
  '01-01',
  '01-06',
  '05-01',
  '05-02',
  '05-03',
  '08-15',
  '11-01',
  '11-11',
  '12-24',
  '12-25',
  '12-26'
];

export function formatLocalDate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

export function getMaxAllowedDate(today = new Date()) {
  const day = today.getDate();

  if (day < 20) {
    return new Date(today.getFullYear(), today.getMonth() + 1, 0);
  }

  return new Date(today.getFullYear(), today.getMonth() + 2, 0);
}

export function getMonthRangeLimits(today = new Date()) {
  const minMonthDate = new Date(today.getFullYear(), today.getMonth(), 1);
  const maxAllowedDate = getMaxAllowedDate(today);
  const maxMonthDate = new Date(maxAllowedDate.getFullYear(), maxAllowedDate.getMonth(), 1);

  return { minMonthDate, maxMonthDate };
}

export function isSameMonth(dateA, dateB) {
  return (
    dateA.getFullYear() === dateB.getFullYear() &&
    dateA.getMonth() === dateB.getMonth()
  );
}

export function isFixedHoliday(dateStr, holidays = FIXED_HOLIDAYS) {
  if (!dateStr || typeof dateStr !== 'string') return false;
  return holidays.includes(dateStr.slice(5));
}

export function normalizeBlockedData(data = {}) {
  const blockedDates = Array.isArray(data.blockedDates) ? data.blockedDates : [];
  const blockedTimes =
    data.blockedTimes && typeof data.blockedTimes === 'object'
      ? data.blockedTimes
      : {};
  const workingHours =
    Array.isArray(data.workingHours) && data.workingHours.length
      ? data.workingHours
      : ['10:00', '11:00', '12:00'];

  return {
    blockedDates,
    blockedTimes,
    workingHours
  };
}

export function generateTimeSlots(workStart = '10:00', workEnd = '16:00', slotDuration = 60) {
  const slots = [];

  const [startH, startM] = workStart.split(':').map(Number);
  const [endH, endM] = workEnd.split(':').map(Number);

  let current = startH * 60 + startM;
  const end = endH * 60 + endM;

  while (current + slotDuration <= end) {
    const hours = Math.floor(current / 60);
    const minutes = current % 60;

    slots.push(
      `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`
    );

    current += slotDuration;
  }

  return slots;
}

export function isDateAllowed(dateStr, options = {}) {
  if (!dateStr) return false;

  const {
    holidays = FIXED_HOLIDAYS,
    blockWeekends = true,
    today = new Date(),
    useMonthLimit = true
  } = options;

  const selected = new Date(`${dateStr}T00:00:00`);
  if (Number.isNaN(selected.getTime())) return false;

  const day = selected.getDay();

  if (blockWeekends && (day === 0 || day === 6)) {
    return false;
  }

  if (isFixedHoliday(dateStr, holidays)) {
    return false;
  }

  const todayOnly = new Date(today.getFullYear(), today.getMonth(), today.getDate());
  const selectedOnly = new Date(selected.getFullYear(), selected.getMonth(), selected.getDate());

  if (selectedOnly < todayOnly) {
    return false;
  }

  if (!useMonthLimit) {
    return true;
  }

  const maxDate = getMaxAllowedDate(today);
  const maxOnly = new Date(maxDate.getFullYear(), maxDate.getMonth(), maxDate.getDate());

  return selectedOnly <= maxOnly;
}

export function getBlockedTimesForDate(dateStr, blockedTimes = {}) {
  if (!dateStr || !blockedTimes || typeof blockedTimes !== 'object') {
    return [];
  }

  return Array.isArray(blockedTimes[dateStr]) ? blockedTimes[dateStr] : [];
}

export function isFullDayBlocked(dateStr, blockedDates = [], blockedTimes = {}) {
  if (blockedDates.includes(dateStr)) return true;

  const blockedForDay = getBlockedTimesForDate(dateStr, blockedTimes);
  return blockedForDay.includes('all');
}

export function getAvailableTimesForDate(dateStr, data = {}, options = {}) {
  const {
    blockedDates,
    blockedTimes,
    workingHours
  } = normalizeBlockedData(data);

  if (!dateStr) {
    return [...workingHours];
  }

  if (!isDateAllowed(dateStr, options)) {
    return [];
  }

  if (isFullDayBlocked(dateStr, blockedDates, blockedTimes)) {
    return [];
  }

  const blockedForDay = getBlockedTimesForDate(dateStr, blockedTimes);

  let available = workingHours.filter(time => !blockedForDay.includes(time));

  const now = options.now instanceof Date ? options.now : new Date();
  const todayStr = formatLocalDate(now);

  if (dateStr === todayStr) {
    const nowMinutes = now.getHours() * 60 + now.getMinutes();

    available = available.filter(time => {
      const [hours, minutes] = time.split(':').map(Number);
      const slotMinutes = hours * 60 + minutes;
      return slotMinutes > nowMinutes;
    });
  }

  return available;
}

export function buildAvailabilityMap(data = {}, options = {}) {
  const {
    blockedDates,
    blockedTimes,
    workingHours
  } = normalizeBlockedData(data);

  const map = {};

  blockedDates.forEach(dateStr => {
    map[dateStr] = [];
  });

  Object.keys(blockedTimes).forEach(dateStr => {
    map[dateStr] = getAvailableTimesForDate(
      dateStr,
      { blockedDates, blockedTimes, workingHours },
      options
    );
  });

  return map;
}

export function getDayState(dateStr, data = {}, options = {}) {
  const { blockedDates, blockedTimes } = normalizeBlockedData(data);

  const allowed = isDateAllowed(dateStr, options);
  const holiday = isFixedHoliday(dateStr, options.holidays || FIXED_HOLIDAYS);
  const fullDayBlocked = isFullDayBlocked(dateStr, blockedDates, blockedTimes);
  const availableTimes = getAvailableTimesForDate(dateStr, data, options);

  return {
    allowed,
    holiday,
    fullDayBlocked,
    availableTimes,
    availableCount: availableTimes.length,
    isDisabled: !allowed || availableTimes.length === 0
  };
}