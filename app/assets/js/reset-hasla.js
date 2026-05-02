function getResetMessageEl() {
  return document.getElementById('msg');
}

function setResetMessage(message, type = '') {
  const msg = getResetMessageEl();
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

function isValidEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

async function sendReset() {
  const emailInput = document.getElementById('email');
  const email = emailInput ? emailInput.value.trim() : '';

  setResetMessage('');

  if (!email) {
    setResetMessage('Podaj adres e-mail.', 'error');
    emailInput?.focus();
    return;
  }

  if (!isValidEmail(email)) {
    setResetMessage('Podaj poprawny adres e-mail.', 'error');
    emailInput?.focus();
    return;
  }

  try {
    const res = await fetch('/api/user/forgot-password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email })
    });

    const data = await res.json().catch(() => null);

    if (res.ok && data?.success) {
      setResetMessage(data.message || 'Jeśli konto istnieje, wysłaliśmy wiadomość z instrukcją resetu hasła.', 'success');
      return;
    }

   if (res.ok && data?.success) {
  setResetMessage(
    data.message || 'Jeśli konto istnieje, wysłaliśmy wiadomość z instrukcją resetu hasła.',
    'success'
  );
  return;
}

setResetMessage(
  data?.error || 'Nie udało się wysłać wiadomości resetującej hasło. Spróbuj ponownie za chwilę.',
  'error'
);
  } catch (e) {
    console.error('reset password error:', e);
    setResetMessage('Nie udało się połączyć z serwerem. Spróbuj ponownie za chwilę.', 'error');
  }
}

document.addEventListener('DOMContentLoaded', () => {
  setResetMessage('');
});