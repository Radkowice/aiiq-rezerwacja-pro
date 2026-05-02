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

function checkPasswordStrength(password) {
  let score = 0;

  if (password.length >= 8) score++;
  if (/[a-z]/.test(password)) score++;
  if (/[A-Z]/.test(password)) score++;
  if (/[0-9]/.test(password)) score++;
  if (/[^A-Za-z0-9]/.test(password)) score++;

  return score;
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

function updateStrengthUI(score, password = '') {
  const bar = document.getElementById('strength-bar');
  const text = document.getElementById('strength-text');

  if (!bar || !text) return;

  bar.classList.remove('weak', 'medium', 'strong');

  if (!password) {
    bar.style.width = '0%';
    text.textContent = 'Siła hasła';
    return;
  }

  let width = '20%';
  let label = 'Bardzo słabe';
  let className = 'weak';

  if (score <= 2) {
    width = '40%';
    label = 'Słabe';
    className = 'weak';
  } else if (score === 3 || score === 4) {
    width = '70%';
    label = 'Średnie';
    className = 'medium';
  } else if (score >= 5) {
    width = '100%';
    label = 'Mocne';
    className = 'strong';
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

  if (passwordInput) {
    passwordInput.addEventListener('input', function () {
      const password = passwordInput.value;
      const score = checkPasswordStrength(password);
      updateStrengthUI(score, password);
    });
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      resetPassword();
    });
  }
});