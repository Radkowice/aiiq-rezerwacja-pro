<section class="panel-card hidden" data-section="widocznosc-google" id="seo-google-section">
  <div class="panel-header">
    <div>
      <h2>Widoczność w Google</h2>
      <p class="settings-desc">
        Zdecyduj, jak strona rezerwacji będzie prezentowana w wynikach wyszukiwania Google
      </p>
    </div>
  </div>

  <p
    id="seo-google-message"
    class="message hidden"
    role="status"
    aria-live="polite"
  ></p>

  <div id="seo-google-loading" class="settings-card" role="status" aria-live="polite">
    <h3>Ładowanie ustawień</h3>
    <p class="settings-desc">Pobieranie aktualnych danych widoczności strony…</p>
  </div>

  <div id="seo-google-content" class="hidden" aria-busy="true">
    <div class="settings-row seo-google-layout">
      <section class="settings-card" aria-labelledby="seo-google-config-heading">
        <h3 id="seo-google-config-heading">Ustawienia widoczności</h3>
        <p id="seo-google-visibility-status" class="settings-desc" role="status" aria-live="polite">
          Sprawdzanie widoczności…
        </p>

        <form id="seo-google-form" class="service-payments-form-grid" novalidate>
          <label class="service-payments-switch full" for="seo-google-indexing-enabled">
            <input type="checkbox" id="seo-google-indexing-enabled">
            <span>
              <strong>Pozwól wyszukiwarkom wyświetlać stronę</strong>
              <small>Po wyłączeniu strona rezerwacji nie będzie wyświetlana w wynikach wyszukiwania Google.</small>
            </span>
          </label>

          <label class="full" for="seo-google-title">
            <span>Tytuł strony w Google</span>
            <input
              type="text"
              id="seo-google-title"
              maxlength="60"
              autocomplete="off"
              aria-describedby="seo-google-title-help seo-google-title-count"
            >
            <small id="seo-google-title-help" class="settings-help">
              Maksymalnie 60 znaków.
            </small>
            <small id="seo-google-title-count" class="settings-help" aria-live="polite">0 / 60 znaków</small>
          </label>

          <label class="full" for="seo-google-description">
            <span>Opis strony w Google</span>
            <textarea
              id="seo-google-description"
              rows="6"
              maxlength="160"
              aria-describedby="seo-google-description-help seo-google-description-count"
            ></textarea>
            <small id="seo-google-description-help" class="settings-help">
              Maksymalnie 160 znaków.
            </small>
            <small id="seo-google-description-count" class="settings-help" aria-live="polite">0 / 160 znaków</small>
          </label>

          <div class="settings-actions full">
            <button type="submit" class="btn" id="seo-google-save-btn">Zapisz ustawienia</button>
          </div>
        </form>
      </section>

      <section class="settings-card seo-google-preview-card" aria-labelledby="seo-google-preview-heading">
        <h3 id="seo-google-preview-heading">Podgląd wyniku Google</h3>

        <div class="seo-google-serp" aria-label="Przybliżony wygląd strony w wynikach wyszukiwania Google">
          <div class="seo-google-serp-source">
            <span class="seo-google-serp-favicon" aria-hidden="true">
              <img id="seo-google-preview-favicon" src="/favicon.png" alt="">
            </span>
            <div class="seo-google-serp-source-text">
              <span id="seo-google-preview-site-name" class="seo-google-serp-site-name">Twoja strona</span>
              <span class="seo-google-serp-url-row">
                <span id="seo-google-preview-domain">Domena nie jest jeszcze dostępna</span>
                <span class="seo-google-serp-menu" aria-hidden="true">&#8942;</span>
              </span>
            </div>
          </div>

          <div id="seo-google-preview-title" class="seo-google-serp-title">
            Tytuł zostanie ustalony po wczytaniu danych
          </div>
          <p id="seo-google-preview-description" class="seo-google-serp-description">
            Opis zostanie ustalony po wczytaniu danych.
          </p>
        </div>

        <p class="seo-google-preview-note">
          To przybliżony podgląd. Google może samodzielnie zmienić tytuł lub opis wyniku.
        </p>

        <div class="settings-actions">
          <a
            id="seo-google-public-page-link"
            class="btn hidden"
            href="/"
            target="_blank"
            rel="noopener"
          >Otwórz publiczną stronę</a>
        </div>
      </section>
    </div>
  </div>
</section>
