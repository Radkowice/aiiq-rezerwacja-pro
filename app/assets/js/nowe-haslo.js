function getMessageEl() {
  return document.getElementById('msg');
}

function setMessage(message, type = '') {
  const msg = getMessageEl();
  if (!msg) return;

  msg.textContent = message || '';
  msg.classList.remove('visible', 'success', 'error-state');

  if (message) {
    msg.classList.add('visible');

    if (type === 'success') {
      msg.classList.add('success');
    }

    if (type === 'error') {
      msg.classList.add('error-state');
    }
  }
}

function clearMessage() {
  setMessage('');
}

function togglePassword() {
  const p1 = document.getElementById('password');
  const p2 = document.getElementById('password2');
  const button = document.querySelector('.login-toggle-password');

  if (!p1 || !p2) return;

  const isVisible = p1.type === 'text';
  const nextType = isVisible ? 'password' : 'text';

  p1.type = nextType;
  p2.type = nextType;

  if (button) {
    button.classList.toggle('is-visible', !isVisible);
  }
}

function getToken() {
  const params = new URLSearchParams(window.location.search);
  return params.get('token') || '';
}

function getEmail() {
  const params = new URLSearchParams(window.location.search);
  return params.get('email') || localStorage.getItem('reset_email') || '';
}

function evaluatePasswordStrength(value) {
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
    /haslo|password|admin|qwerty|abc123|1234|12345|123456|qwer|asdf|zxcv/i.test(normalized);

  const hasRepeat = /(.)\1{3,}/.test(password);

  if (!meetsMinimum) {
    return { label: 'Słabe hasło', className: 'weak' };
  }

  if (password.length >= 15 && !hasWeakPattern && !hasRepeat) {
    return { label: 'Mocne hasło', className: 'strong' };
  }

  return { label: 'Średnie hasło', className: 'medium' };
}

function isStrongPassword(password) {
  return (
    password.length >= 8 &&
    /[a-z]/.test(password) &&
    /[A-Z]/.test(password) &&
    /[0-9]/.test(password) &&
    /[^A-Za-z0-9]/.test(password)
  );
}

function updateStrengthUI(result, password = '') {
  const bar = document.getElementById('strength-bar');
  const text = document.getElementById('strength-text');

  if (!bar || !text) return;

  bar.classList.remove('weak', 'medium', 'strong');

  if (!password) {
    bar.style.width = '0%';
    text.textContent = 'Siła hasła';
    return;
  }

  const className = result?.className || 'weak';
  const label = result?.label || 'Słabe hasło';
  let width = '40%';

  if (className === 'medium') {
    width = '70%';
  } else if (className === 'strong') {
    width = '100%';
  }

  bar.style.width = width;
  bar.classList.add(className);
  text.textContent = label;
}

async function resetPassword() {
  clearMessage();

  const passwordInput = document.getElementById('password');
  const password2Input = document.getElementById('password2');

  if (!passwordInput || !password2Input) {
    setMessage('Brakuje pól formularza.', 'error');
    return;
  }

  const email = getEmail();
  const token = getToken();
  const password = passwordInput.value;
  const password2 = password2Input.value;

  if (!email) {
    setMessage('Brak adresu e-mail do resetu hasła.', 'error');
    return;
  }

  if (!token) {
    setMessage('Brak tokena resetu hasła.', 'error');
    return;
  }

  if (!password || !password2) {
    setMessage('Wpisz nowe hasło i powtórz je.', 'error');
    passwordInput.focus();
    return;
  }

  if (password !== password2) {
    setMessage('Hasła nie są takie same.', 'error');
    password2Input.focus();
    return;
  }

  if (!isStrongPassword(password)) {
    setMessage('Hasło musi mieć minimum 8 znaków, małą i dużą literę, cyfrę oraz znak specjalny.', 'error');
    passwordInput.focus();
    return;
  }

  try {
    const res = await fetch('/api/user/reset-password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, token, password })
    });

    const data = await res.json().catch(() => null);

    if (res.ok && data?.success === true) {
      localStorage.removeItem('reset_email');
      setMessage(data.message || 'Hasło zostało zmienione. Możesz się zalogować.', 'success');
      return;
    }

    setMessage(data?.message || data?.error || 'Nie udało się zresetować hasła.', 'error');
  } catch (e) {
    console.error('reset password error:', e);
    setMessage('Nie udało się połączyć z serwerem. Spróbuj ponownie za chwilę.', 'error');
  }
}

document.addEventListener('DOMContentLoaded', () => {
  clearMessage();

  const passwordInput = document.getElementById('password');
  const form = document.getElementById('newPasswordForm') || document.querySelector('form');
  const togglePasswordButton = document.querySelector('.login-toggle-password');

  if (passwordInput) {
    passwordInput.addEventListener('input', function () {
      const password = passwordInput.value;
      const result = evaluatePasswordStrength(password);
      updateStrengthUI(result, password);
    });
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      resetPassword();
    });
  }

  if (togglePasswordButton) {
    togglePasswordButton.addEventListener('click', togglePassword);
  }
});
