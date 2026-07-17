<?php
require_once __DIR__ . '/../api/helpers/session.php';
require_once __DIR__ . '/helpers/footer.php';
start_secure_session();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="icon" type="image/png" href="/favicon.png">
  <link rel="stylesheet" href="/assets/css/theme.css?v=1"> 
                                                                   
  <link rel="stylesheet" href="/assets/css/panel-admina.css?v=7">
  <link rel="stylesheet" href="/assets/css/panel-admina-blokady.css?v=2">
  <link rel="stylesheet" href="/assets/css/panel-admina-rezerwacje.css?v=2">
  <link rel="stylesheet" href="/assets/css/panel-admina-layout.css">
  <link rel="stylesheet" href="/assets/css/admin-personel.css?v=1">
  <link rel="stylesheet" href="/assets/css/admin-email.css?v=1">
  <link rel="stylesheet" href="/assets/css/admin-ustawienia.css?v=2">
  <link rel="stylesheet" href="/assets/css/admin-moje-konto.css?v=2">
  <link rel="stylesheet" href="/assets/css/panel-admina-przyciski.css?v=2">
  <link rel="stylesheet" href="/assets/css/admin-integracje.css?v=1">
  <link rel="stylesheet" href="/assets/css/admin-usluga-platnosci.css?v=2">
  <link rel="stylesheet" href="/assets/css/admin-dokumenty-prawne.css?v=1">
  <link rel="stylesheet" href="/assets/css/admin-informacje.css?v=1">
  <link rel="stylesheet" href="/assets/css/plany.css?v=1">
  <link rel="stylesheet" href="/assets/css/app-loader.css">
  <link rel="stylesheet" href="/assets/css/app-footer.css">
  <title>AI-IQ Admin</title>
 

  <script src="/assets/js/app-loader.js"></script>
  <script src="/assets/js/app-loader-init.js"></script>
  <script src="/assets/js/admin-csrf.js"></script>
  <script src="/assets/js/auth.js?v=2" defer></script>
  <script src="/assets/js/buttons.js?v=8"></script>
  <script type="module" src="/assets/js/admin-api.js?v=2"></script>
  <script src="/assets/js/admin-email.js?v=smtp-fix-1" defer></script>
  <script src="/assets/js/admin-modal.js" defer></script>
  <script src="/assets/js/admin-kalendarz.js?v=20260615-2" defer></script>
  <script src="/assets/js/panel-admina.js?v=3" defer></script>
  <script src="/assets/js/admin-personel.js?v=2" defer></script>
  <script src="/assets/js/admin-ustawienia.js" defer></script>
  <script src="/assets/js/admin-integracje.js?v=2"></script>
  <script src="/assets/js/admin-usluga-platnosci.js?v=20260619-1"></script>
  <script src="/assets/js/admin-dokumenty-prawne.js?v=1" defer></script>
  <script src="/assets/js/admin-informacje.js?v=2" defer></script>
</head>
<body class="tenant-theme app-loading">
  <div id="appLoader" class="app-loader" role="status" aria-live="polite" aria-hidden="false">
    <div class="app-loader__box">
      <div class="app-loader__spinner" aria-hidden="true"></div>
      <p class="app-loader__text">Ładowanie panelu administracyjnego…</p>
      <p class="app-loader__error" data-loader-error></p>
      <button type="button" class="app-loader__refresh" data-loader-refresh>Odśwież stronę</button>
    </div>
  </div>
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
  <span class="label">Personel</span>
  <span class="icon">👥</span>
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

        <div class="sidebar-account-summary" aria-label="Informacje o zalogowanym koncie">
          <div class="sidebar-account-row">
            <div class="sidebar-account-label">Firma:</div>
            <div id="sidebarCompanyName" class="sidebar-account-value">Ładowanie...</div>
          </div>

          <div class="sidebar-account-row">
            <div class="sidebar-account-label">Zalogowany administrator:</div>
            <div id="sidebarAdminName" class="sidebar-account-value">Ładowanie...</div>
          </div>
        </div>
      </aside>

      <div class="main-content">
        <header class="topbar">
          <div class="topbar-left">
            <h1>AI-IQ Kalendarz Rezerwacji Pro</h1>
            <p>Panel administratora</p>
          </div>
          <div class="plan-upgrade-bar" id="planUpgradeNotice" hidden>
            Funkcje dostępne w wersji Pro. Aktywuj Plan Pro
          </div>
          <div class="topbar-actions">
            <div class="admin-notifications" id="adminNotifications">
              <button
                class="admin-notifications-toggle"
                id="adminNotificationsToggle"
                type="button"
                aria-label="Powiadomienia administratora"
                aria-haspopup="true"
                aria-expanded="false"
              >
                <span aria-hidden="true">🔔</span>
                <span class="admin-notifications-count" id="adminNotificationsCount" hidden>0</span>
              </button>
              <div class="admin-notifications-dropdown" id="adminNotificationsDropdown" hidden>
                <div class="admin-notifications-header">
                  <strong>Powiadomienia</strong>
                  <button class="admin-notifications-mark" id="adminNotificationsMarkRead" type="button">
                    Oznacz jako przeczytane
                  </button>
                </div>
                <div class="admin-notifications-list" id="adminNotificationsList">
                  <p class="admin-notifications-empty">Ładowanie powiadomień...</p>
                </div>
                <p class="admin-notifications-note">
                  Jeżeli nie wiesz, czego dotyczy blokada, skontaktuj się z pracownikiem.
                </p>
              </div>
            </div>
            <button class="logout-btn" id="logoutBtn" type="button">Wyloguj</button>
          </div>
        </header>

        <main class="container">
     
  <?php require __DIR__ . '/partials/rezerwacje.php'; ?>
  
  <?php require __DIR__ . '/partials/blokady.php'; ?>

  <?php require __DIR__ . '/partials/personel.php'; ?>
         
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
<?php render_app_footer(['class' => 'footer']); ?>
   
  </div>
   
 </body>
</html>         
