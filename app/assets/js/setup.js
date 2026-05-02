async function checkSetup() {
  try {
    const res = await fetch('/api/auth/setup.php');
    const data = await res.json();

    if (data.installed) {
      window.location.href = '/logowanie.html';
    } else {
      window.location.href = '/rejestracja.html';
    }
  } catch (e) {
    console.error('Błąd setup:', e);
  }
}

checkSetup();
