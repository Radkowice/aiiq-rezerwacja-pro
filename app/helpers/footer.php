<?php
declare(strict_types=1);

function render_app_footer(array $options = []): void
{
    $class = trim('app-footer ' . (string) ($options['class'] ?? ''));
    $extraHtml = (string) ($options['extra_html'] ?? '');
    $platformBaseUrl = rtrim((string) ($options['platform_base_url'] ?? ''), '/');
    $includePlatformLinks = (bool) ($options['platform_links'] ?? false);
    $year = date('Y');

    $buildPlatformUrl = static function (string $path) use ($platformBaseUrl): string {
        return $platformBaseUrl === '' ? $path : $platformBaseUrl . $path;
    };

    if ($includePlatformLinks) {
        ob_start();
        ?>
        <nav class="front-legal-links" data-app-footer-extra aria-label="Dokumenty prawne i informacje o aplikacji">
          <a href="<?= htmlspecialchars($buildPlatformUrl('/legal/regulamin.html'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Regulamin platformy</a>
          <span aria-hidden="true">•</span>
          <a href="<?= htmlspecialchars($buildPlatformUrl('/legal/polityka-prywatnosci.html'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Polityka prywatności platformy</a>
          <span aria-hidden="true">•</span>
          <a href="<?= htmlspecialchars($buildPlatformUrl('/o-aplikacji.html'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Informacje o aplikacji</a>
        </nav>
        <?php
        $extraHtml .= (string) ob_get_clean();
    }
    ?>
    <footer class="<?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
      <span class="app-footer-main">
        © <?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?> System Rezerwacji Pro · Obsługiwane przez
        <a href="https://www.ai-iq.pl" target="_blank" rel="noopener noreferrer">AI-IQ</a>
         <span aria-hidden="true">•</span>
      </span>
      <?= $extraHtml ?>
    </footer>
    <?php
}
