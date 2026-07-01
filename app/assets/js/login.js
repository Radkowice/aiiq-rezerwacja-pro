const loginUrlParams = new URLSearchParams(window.location.search);
const loginActivatedState = loginUrlParams.get('activated');
const skipSetupRedirect = loginActivatedState === 'already';
const activationReissueMessage = 'Jeśli konto wymaga aktywacji, wyślemy nowy link aktywacyjny.';
const activationReissueErrorMessage = 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.';
let activationReissueEmail = '';

async function checkSetupBeforeLogin() {
  if (skipSetupRedirect) {
    return;
  }

  try {
    const res = await fetch('/api/auth/register.php', {
      cache: 'no-store'
    });

    const data = await res.json().catch(() => null);

    if (!skipSetupRedirect && data?.registration_allowed === true) {
      window.location.href = '/rejestracja.html';
    }
  } catch (e) {
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

function getActivationReissueWrap() {
  return document.getElementById('activationReissueWrap');
}

function getActivationReissueButton() {
  return document.getElementById('activationReissueBtn');
}

function showActivationReissueAction() {
  const wrap = getActivationReissueWrap();
  if (!wrap) return;

  wrap.hidden = false;
}

function hideActivationReissueAction() {
  const wrap = getActivationReissueWrap();
  const button = getActivationReissueButton();
  activationReissueEmail = '';

  if (wrap) {
    wrap.hidden = true;
  }

  if (button) {
    button.disabled = false;
  }
}

function handleLoginEmailChange() {
  if (activationReissueEmail === '') {
    return;
  }

  const emailInput = document.getElementById('email');
  const currentEmail = emailInput ? emailInput.value.trim() : '';

  if (currentEmail !== activationReissueEmail) {
    hideActivationReissueAction();
  }
}

function showActivationMessage() {
  const params = new URLSearchParams(window.location.search);
  const activated = params.get('activated');
  const activationError = params.get('activation');

  if (activated === 'already') {
    setLoginError('Konto zostało aktywowane.');
  } else if (activated === '1') {
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
  hideActivationReissueAction();

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

    if (data?.activation_required === true) {
      activationReissueEmail = email;
      showActivationReissueAction();
    } else {
      hideActivationReissueAction();
    }

    setLoginError(data?.error || 'Nieprawidłowy e-mail lub hasło');
  } catch (error) {
    hideActivationReissueAction();
    setLoginError('Nie udało się zalogować. Spróbuj ponownie za chwilę');
  }
}

async function requestActivationReissue() {
  const button = getActivationReissueButton();
  const email = activationReissueEmail;

  if (!email) {
    hideActivationReissueAction();
    setLoginError(activationReissueErrorMessage);
    return;
  }

  if (button) {
    button.disabled = true;
  }

  try {
    const res = await fetch('/api/auth/activation-link-reissue.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ email })
    });

    const data = await res.json().catch(() => null);

    if (res.ok && data?.success === true) {
      hideActivationReissueAction();
      setLoginError(activationReissueMessage);
      return;
    }

    setLoginError(activationReissueErrorMessage);
  } catch (error) {
    setLoginError(activationReissueErrorMessage);
  } finally {
    if (button) {
      button.disabled = false;
    }
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
  const emailInput = document.getElementById('email');
  const togglePasswordButton = document.querySelector('.login-toggle-password');
  const activationReissueButton = getActivationReissueButton();

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      login();
    });
  }

  if (togglePasswordButton) {
    togglePasswordButton.addEventListener('click', togglePassword);
  }

  if (emailInput) {
    emailInput.addEventListener('input', handleLoginEmailChange);
  }

  if (activationReissueButton) {
    activationReissueButton.addEventListener('click', requestActivationReissue);
  }

  if (skipSetupRedirect) {
    showActivationMessage();
    return;
  }

  await checkSetupBeforeLogin();
  showActivationMessage();
});
