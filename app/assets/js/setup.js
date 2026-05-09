async function checkSetup() {
  try {
    const res = await fetch('/api/auth/register.php');
    const data = await res.json().catch(() => null);

    if (data?.registration_allowed === true) {
      window.location.href = '/rejestracja.html';
      return;
    }

    window.location.href = data?.redirect_to || '/logowanie.html';
  } catch (e) {
    console.error('Błąd setup:', e);
  }
}

checkSetup();
