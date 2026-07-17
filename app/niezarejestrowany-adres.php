<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers/footer.php';

http_response_code(404);
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Adres niezarejestrowany</title>
  <link rel="icon" type="image/png" href="/favicon.png">
  <link rel="stylesheet" href="/assets/css/app-footer.css">
  <link rel="stylesheet" href="/assets/css/niezarejestrowany-adres.css">
</head>
<body>
  <main class="unregistered-page">
    <section class="unregistered-card" aria-labelledby="unregisteredTitle">
      <h1 id="unregisteredTitle">Ten adres nie jest<br>zarejestrowany w AI-IQ<br>Rezerwacja Pro.</h1>
      <p>Sprawdź poprawność adresu albo utwórz nowe konto.</p>
      <div class="unregistered-actions">
        <a href="https://rezerwacja-ai-iq.pl/rejestracja.html">Utwórz konto Free</a>
        <span aria-hidden="true">·</span>
        <a href="https://rezerwacja-ai-iq.pl/rejestracja-pro.html">Utwórz konto Pro</a>
      </div>
    </section>
  </main>

  <?php render_app_footer([
      'class' => 'unregistered-footer',
      'platform_links' => true,
      'platform_base_url' => 'https://rezerwacja-ai-iq.pl',
  ]); ?>
</body>
</html>
