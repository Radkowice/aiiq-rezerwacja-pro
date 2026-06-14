async function checkSetupBeforeLogin() {
  try {
    const res = await fetch('/api/auth/register.php', {
      cache: 'no-store'
    });

    const data = await res.json().catch(() => null);

    if (data?.registration_allowed === true) {
      window.location.href = '/rejestracja.html';
    }
  } catch (e) {
    console.error('check setup error:', e);
    clearLoginError();
  }
}

function getLoginErrorEl() {
  return document.getElementById('err');
}

function setLoginError(message) {
  const err = getLoginErrorEl();
  if (!err) return;

  err.innerText = message || '';
  err.classList.toggle('visible', Boolean(message));
}

function clearLoginError() {
  setLoginError('');
}

function showActivationMessage() {
  const params = new URLSearchParams(window.location.search);
  const activated = params.get('activated');
  const activationError = params.get('activation');

  if (activated === '1') {
    setLoginError('Konto zostało aktywowane. Możesz się teraz zalogować.');
  } else if (activationError === 'domain_unavailable') {
    setLoginError('Konto zostało aktywowane, ale adres panelu nie jest jeszcze dostępny.');
  } else if (activationError) {
    setLoginError('Link aktywacyjny jest nieprawidłowy, wygasł albo został już użyty.');
  } else {
    return;
  }

  window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
}

async function login() {
  clearLoginError();

  const emailInput = document.getElementById('email');
  const passwordInput = document.getElementById('password');

  const email = emailInput ? emailInput.value.trim() : '';
  const password = passwordInput ? passwordInput.value : '';

  if (!email) {
    setLoginError('Podaj adres e-mail');
    emailInput?.focus();
    return;
  }

  if (!password) {
    setLoginError('Podaj hasło');
    passwordInput?.focus();
    return;
  }

  try {
    const res = await fetch('/api/auth/login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ email, password })
    });

    const data = await res.json().catch(() => null);

    if (res.ok && data?.success) {
      window.location.href = '/panel-admina.php';
      return;
    }

    setLoginError(data?.error || 'Nieprawidłowy e-mail lub hasło');
  } catch (error) {
    console.error('login error:', error);
    setLoginError('Nie udało się zalogować. Spróbuj ponownie za chwilę');
  }
}

function togglePassword() {
  const input = document.getElementById('password');
  const button = document.querySelector('.login-toggle-password');

  if (!input || !button) return;

  const isVisible = input.type === 'text';

  input.type = isVisible ? 'password' : 'text';
  button.classList.toggle('is-visible', !isVisible);
}

document.addEventListener('DOMContentLoaded', async () => {
  clearLoginError();

  const form = document.querySelector('form');
  const togglePasswordButton = document.querySelector('.login-toggle-password');

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      login();
    });
  }

  if (togglePasswordButton) {
    togglePasswordButton.addEventListener('click', togglePassword);
  }

  await checkSetupBeforeLogin();
  showActivationMessage();
});
