<?php
require_once __DIR__ . '/../api/helpers/session.php';
start_secure_session();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="/favicon.png">
  <link rel="stylesheet" href="/assets/css/theme.css?v=1"> 
                                                                   
  <link rel="stylesheet" href="/assets/css/panel-admina.css?v=7">
  <link rel="stylesheet" href="/assets/css/panel-admina-blokady.css?v=2">
  <link rel="stylesheet" href="/assets/css/panel-admina-rezerwacje.css">
  <link rel="stylesheet" href="/assets/css/panel-admina-layout.css">
  <link rel="stylesheet" href="/assets/css/admin-email.css?v=1">
  <link rel="stylesheet" href="/assets/css/admin-ustawienia.css?v=1">
  <link rel="stylesheet" href="/assets/css/admin-moje-konto.css?v=1">
  <link rel="stylesheet" href="/assets/css/panel-admina-przyciski.css?v=2">
  <link rel="stylesheet" href="/assets/css/admin-integracje.css?v=1">
  <link rel="stylesheet" href="/assets/css/admin-usluga-platnosci.css?v=1">
  <link rel="stylesheet" href="/assets/css/admin-dokumenty-prawne.css?v=1">
  <link rel="stylesheet" href="/assets/css/admin-informacje.css?v=1">
  <title>AI-IQ Admin</title>
 

  <script>
    window.CSRF_TOKEN = "<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>";
    window.INTERNAL_API_KEY = "<?= htmlspecialchars(getenv('INTERNAL_API_KEY') ?: '', ENT_QUOTES, 'UTF-8') ?>";
    window.PANEL_API_TOKEN = "<?= htmlspecialchars(getenv('PANEL_API_TOKEN') ?: '', ENT_QUOTES, 'UTF-8') ?>";
  </script>

  <script src="/assets/js/auth.js" defer></script>
  <script src="/assets/js/buttons.js?v=2"></script>
  <script type="module" src="/assets/js/admin-api.js"></script>
  <script src="/assets/js/admin-email.js?v=smtp-fix-1" defer></script>
  <script src="/assets/js/admin-modal.js" defer></script>
  <script src="/assets/js/admin-kalendarz.js" defer></script>
  <script src="/assets/js/panel-admina.js" defer></script>
  <script src="/assets/js/admin-ustawienia.js" defer></script>
  <script src="/assets/js/admin-integracje.js?v=1"></script>
  <script src="/assets/js/admin-usluga-platnosci.js?v=1"></script>
  <script src="/assets/js/admin-dokumenty-prawne.js?v=1" defer></script>
  <script src="/assets/js/admin-informacje.js?v=1" defer></script>
</head>
<body class="tenant-theme">
  <div class="admin-page">
    <div class="admin-layout">

      <aside class="sidebar">
        <div class="sidebar-header">
  <div class="sidebar-logo">AI-IQ</div>
  <button id="sidebarToggle" class="sidebar-toggle">☰</button>
</div>

        <nav class="sidebar-menu">
        <button class="menu-item active" type="button">
  <span class="label">Rezerwacje</span>
  <span class="icon">📅</span>
</button>

<button class="menu-item" type="button">
  <span class="label">Blokady</span>
  <span class="icon">⛔</span>
</button>

<button class="menu-item" type="button">
  <span class="label">Usługa i płatności</span>
  <span class="icon">💳</span>
</button>

<button class="menu-item" type="button">
  <span class="label">Email</span>
  <span class="icon">✉️</span>
</button>

<button class="menu-item" type="button">
  <span class="label">Integracje</span>
  <span class="icon">🔌</span>
</button>

<button class="menu-item" type="button">
  <span class="label">Dokumenty prawne</span>
  <span class="icon">📄</span>
</button>

<button class="menu-item" type="button">
  <span class="label">Informacje</span>
  <span class="icon">ℹ️</span>
</button>

<button class="menu-item" type="button">
  <span class="label">Ustawienia</span>
  <span class="icon">⚙️</span>
</button>

<button class="menu-item" type="button">
  <span class="label">Konto</span>
  <span class="icon">👤</span>
</button>

         
 
        </nav>
      </aside>

      <div class="main-content">
        <header class="topbar">
          <div class="topbar-left">
            <h1>AI-IQ Kalendarz Rezerwacji Pro</h1>
            <p>Panel administratora</p>
          </div>
          <button class="logout-btn" id="logoutBtn" type="button">Wyloguj</button>
        </header>

        <main class="container">
     
  <?php require __DIR__ . '/partials/rezerwacje.php'; ?>
  
  <?php require __DIR__ . '/partials/blokady.php'; ?>
         
 <?php require __DIR__ . '/partials/usluga-platnosci.php'; ?>
  
  <?php require __DIR__ . '/partials/email.php'; ?>
  
 <?php require __DIR__ . '/partials/integracje.php'; ?>

<?php require __DIR__ . '/partials/dokumenty-prawne.php'; ?>

<?php require __DIR__ . '/partials/informacje-admin.php'; ?>

<?php require __DIR__ . '/partials/ustawienia.php'; ?>

  <?php require __DIR__ . '/partials/moje-konto.php'; ?>
  
         </main>
      </div>
    </div>
<footer class="footer">
  © 2026 <a class="footer-link" href="https://www.ai-iq.pl" target="_blank" rel="noopener">AI-IQ</a> – Kalendarz Rezerwacji Pro. Wszelkie prawa zastrzeżone.
</footer>
   
  </div>
   
 </body>
</html>         