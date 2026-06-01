<?php
declare(strict_types=1);

function render_app_footer(array $options = []): void
{
    $class = trim('app-footer ' . (string) ($options['class'] ?? ''));
    $extraHtml = (string) ($options['extra_html'] ?? '');
    $year = date('Y');
    ?>
    <footer class="<?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
      <span class="app-footer-main">
        © <?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?> System Rezerwacji Pro · Obsługiwane przez
        <a href="https://www.ai-iq.pl" target="_blank" rel="noopener noreferrer">AI-IQ</a>
      </span>
      <?= $extraHtml ?>
    </footer>
    <?php
}
