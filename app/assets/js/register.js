document.addEventListener('DOMContentLoaded', async () => {
  const form = document.getElementById('registerForm');
  const passwordInput = document.getElementById('password');

  if (!form) return;

  const registrationAllowed = await checkRegistrationAvailability();

  if (!registrationAllowed) {
    return;
  }

  initRegisterPasswordStrength(passwordInput);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    showRegisterError('');

    const clientName = getRegisterValue('registerClientName');
    const email = getRegisterValue('email');
    const password = document.getElementById('password')?.value || '';

    const companyFullName = getRegisterValue('companyFullName');
    const companyOwnerName = getRegisterValue('companyOwnerName');
    const companyTaxId = getRegisterValue('companyTaxId');
    const companyAddress = getRegisterValue('companyAddress');
    const companyEmailInput = getRegisterValue('companyEmail');
    const companyPhone = getRegisterValue('companyPhone');

    const companyEmail = companyEmailInput || email;
    const passwordResult = evaluateRegisterPasswordStrength(password);

    if (!clientName) {
      showRegisterError('Podaj nazwę publiczną / markę.');
      focusRegisterField('registerClientName');
      return;
    }

    if (!email) {
      showRegisterError('Podaj adres e-mail administratora.');
      focusRegisterField('email');
      return;
    }

    if (!isValidEmail(email)) {
      showRegisterError('Podaj poprawny adres e-mail administratora.');
      focusRegisterField('email');
      return;
    }

    if (!passwordResult.meetsMinimum) {
      showRegisterError('Hasło musi mieć minimum 8 znaków, małą literę, dużą literę, cyfrę oraz znak specjalny.');
      focusRegisterField('password');
      return;
    }

        if (!isValidCompanyName(companyFullName)) {
      showRegisterError('Podaj poprawną pełną nazwę firmy.');
      focusRegisterField('companyFullName');
      return;
    }

    if (!isValidPersonName(companyOwnerName)) {
      showRegisterError('Podaj poprawne imię i nazwisko osoby kontaktowej.');
      focusRegisterField('companyOwnerName');
      return;
    }

    if (!isValidPolishNip(companyTaxId)) {
      showRegisterError('Podaj poprawny NIP.');
      focusRegisterField('companyTaxId');
      return;
    }

    if (!isValidCompanyAddress(companyAddress)) {
      showRegisterError('Podaj adres w formacie: ulica i numer, miasto, kod pocztowy XX-XXX.');
      focusRegisterField('companyAddress');
      return;
    }

    if (companyEmailInput && !isValidEmail(companyEmailInput)) {
      showRegisterError('Podaj poprawny e-mail firmy albo zostaw pole puste.');
      focusRegisterField('companyEmail');
      return;
    }

    if (!isValidPolishPhone(companyPhone)) {
      showRegisterError('Podaj poprawny numer telefonu, np. 123456789 lub +48 123-456-789.');
      focusRegisterField('companyPhone');
      return;
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn ? submitBtn.textContent : '';

    try {
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Tworzenie konta...';
      }

      const res = await fetch('/api/auth/register.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          client_name: clientName,
          email,
          password,

          company_full_name: companyFullName,
          company_owner_name: companyOwnerName,
          company_tax_id: normalizePolishNip(companyTaxId),
          company_address: companyAddress,
          company_email: companyEmail,
          company_phone: normalizePolishPhone(companyPhone)
        })
      });

      let data = null;

      try {
        data = await res.json();
      } catch (jsonError) {
        data = null;
      }

      if (!res.ok || !data?.success) {
        showRegisterError(data?.error || 'Nie udało się utworzyć konta.');
        return;
      }

      window.location.href = '/logowanie.html';
    } catch (error) {
      console.error('register error:', error);
      showRegisterError('Błąd połączenia z serwerem.');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalBtnText || 'Utwórz konto';
      }
    }
  });
});

function getRegisterValue(id) {
  return document.getElementById(id)?.value?.trim() || '';
}

function showRegisterError(message) {
  const err = document.getElementById('err');
  if (!err) return;

  err.textContent = message || '';

  if (message) {
    err.classList.add('visible');
  } else {
    err.classList.remove('visible');
  }
}

function focusRegisterField(id) {
  const el = document.getElementById(id);
  if (!el) return;

  el.focus();

  if (typeof el.scrollIntoView === 'function') {
    el.scrollIntoView({
      behavior: 'smooth',
      block: 'center'
    });
  }
}

async function checkRegistrationAvailability() {
  try {
    const res = await fetch('/api/auth/register.php', {
      method: 'GET',
      headers: {
        'Accept': 'application/json'
      },
      cache: 'no-store'
    });

    let data = null;

    try {
      data = await res.json();
    } catch (jsonError) {
      data = null;
    }

    if (!res.ok || !data?.success) {
      showRegisterError(data?.error || 'Nie udało się sprawdzić dostępności rejestracji.');
      return false;
    }

    if (data.registration_allowed === false) {
      window.location.href = data.redirect_to || '/logowanie.html';
      return false;
    }

    return true;
  } catch (error) {
    console.error('registration availability error:', error);
    showRegisterError('Błąd połączenia z serwerem podczas sprawdzania rejestracji.');
    return false;
  }
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
}

function normalizeDigits(value) {
  return String(value || '').replace(/\D+/g, '');
}

function normalizePolishPhone(value) {
  let digits = normalizeDigits(value);

  if (digits.length === 11 && digits.startsWith('48')) {
    digits = digits.slice(2);
  }

  return digits;
}

function isValidPolishPhone(value) {
  const phone = normalizePolishPhone(value);
  return /^[1-9][0-9]{8}$/.test(phone);
}

function normalizePolishNip(value) {
  return normalizeDigits(value);
}

function isValidPolishNip(value) {
  const nip = normalizePolishNip(value);

  if (!/^[0-9]{10}$/.test(nip)) {
    return false;
  }

  const weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
  let sum = 0;

  for (let i = 0; i < 9; i += 1) {
    sum += Number(nip[i]) * weights[i];
  }

  const checksum = sum % 11;

  return checksum !== 10 && checksum === Number(nip[9]);
}

function isValidPersonName(value) {
  const name = String(value || '').trim().replace(/\s+/g, ' ');

  if (name.length < 5 || name.length > 120) {
    return false;
  }

  if (/[0-9]/.test(name)) {
    return false;
  }

  return /^[\p{L}]+(?:[ -][\p{L}]+)+$/u.test(name);
}

function isValidCompanyName(value) {
  const name = String(value || '').trim().replace(/\s+/g, ' ');
  return name.length >= 2 && name.length <= 255;
}

function isValidCompanyAddress(value) {
  const address = String(value || '').trim().replace(/\s+/g, ' ');
  const parts = address
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean);

  if (address.length < 12 || address.length > 500) {
    return false;
  }

  if (parts.length < 3) {
    return false;
  }

  const hasPostalCode = /[0-9]{2}-[0-9]{3}/.test(address);
  const hasStreetNumber = /\p{L}{2,}.*[0-9]+|[0-9]+.*\p{L}{2,}/u.test(parts[0]);
  const hasCity = parts.some((part) => {
    if (/[0-9]{2}-[0-9]{3}/.test(part)) {
      return false;
    }

    return /^[\p{L}]+(?:[ -][\p{L}]+)*$/u.test(part) && part.length >= 2;
  });

  return hasPostalCode && hasStreetNumber && hasCity;
}

function evaluateRegisterPasswordStrength(value) {
  const password = String(value || '');
  const lower = password.toLowerCase();

  const hasLower = /[a-z]/.test(password);
  const hasUpper = /[A-Z]/.test(password);
  const hasNumber = /[0-9]/.test(password);
  const hasSpecial = /[^A-Za-z0-9]/.test(password);

  const meetsMinimum =
    password.length >= 8 &&
    hasLower &&
    hasUpper &&
    hasNumber &&
    hasSpecial;

  const normalized = lower
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');

  const hasWeakPattern =
    /haslo|hasła|password|admin|qwerty|abc123|1234|12345|123456|qwer|asdf|zxcv|firma|test/i.test(normalized);

  const hasRepeat = /(.)\1{3,}/.test(password);

  if (!meetsMinimum) {
    return {
      label: 'Słabe hasło',
      className: 'weak',
      meetsMinimum: false
    };
  }

  if (hasWeakPattern || hasRepeat) {
    return {
      label: 'Średnie hasło',
      className: 'medium',
      meetsMinimum: true
    };
  }

  if (password.length >= 15) {
    return {
      label: 'Mocne hasło',
      className: 'strong',
      meetsMinimum: true
    };
  }

  return {
    label: 'Średnie hasło',
    className: 'medium',
    meetsMinimum: true
  };
}

function initRegisterPasswordStrength(passwordInput) {
  if (!passwordInput) return;

  let strengthBox = document.getElementById('register-password-strength');

  if (!strengthBox) {
    strengthBox = document.createElement('div');
    strengthBox.id = 'register-password-strength';
    strengthBox.className = 'register-password-strength';
    passwordInput.insertAdjacentElement('afterend', strengthBox);
  }

  passwordInput.addEventListener('input', () => {
    const value = passwordInput.value || '';

    strengthBox.classList.remove('weak', 'medium', 'strong');

    if (!value) {
      strengthBox.textContent = '';
      return;
    }

    const result = evaluateRegisterPasswordStrength(value);
    strengthBox.textContent = result.label;
    strengthBox.classList.add(result.className);
  });
}

function togglePassword() {
  const passwordInput = document.getElementById('password');
  if (!passwordInput) return;

  passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
}