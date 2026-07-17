<section class="panel-card hidden" data-section="integracje">
  <div class="panel-header">
    <h2>Integracje</h2>
  </div>

  <div class="settings-grid integrations-grid">

    <div class="settings-card integration-card" data-provider="google_calendar">
      <div class="integration-card-header">
        <div>
          <h3>Google Calendar</h3>
          <p class="settings-desc">
            Połącz konto Google i zapisuj rezerwacje w swoim kalendarzu Google
          </p>
        </div>

        <label class="integration-switch">
          <input type="checkbox" id="google-calendar-enabled">
          <span>Włącz</span>
        </label>
      </div>

      <div class="integration-status" id="google-calendar-status">
        Status: nie połączono
      </div>

      <div class="settings-help">
        Zapiszesz rezerwację klienta w swoim kalendarzu Google.
      </div>

      <div class="integration-actions settings-actions">
        <button type="button" class="btn" id="connect-google-calendar-btn">
          Połącz z Google
        </button>

        <button type="button" class="btn" id="disconnect-google-calendar-btn">
          Rozłącz
        </button>
      </div>
    </div>

    <div class="settings-card integration-card" data-provider="payu">
      <div class="integration-card-header">
        <div>
          <h3>PayU</h3>
          <p class="settings-desc">
            Płatności online dla rezerwacji. Najpierw przetestuj tryb testowy.
          </p>
        </div>

        <label class="integration-switch">
          <input type="checkbox" id="payu-enabled">
          <span>Włącz</span>
        </label>
      </div>

      <div class="settings-row">
        <div class="form-group">
          <label for="payu-mode">Tryb</label>
          <select id="payu-mode">
            <option value="sandbox">Testowy / sandbox</option>
            <option value="production">Produkcyjny</option>
          </select>
          </div>

        <div class="form-group">
          <label for="payu-pos-id">POS ID</label>
          <input type="text" id="payu-pos-id" autocomplete="off">
        </div>
      </div>

      <div class="settings-row-3 integration-payu-keys-row">
        <div class="form-group">
          <label for="payu-client-id">Client ID</label>
          <input type="text" id="payu-client-id" autocomplete="off">
        </div>

        <div class="form-group">
          <label for="payu-client-secret">Client Secret</label>
          <input type="password" id="payu-client-secret" autocomplete="new-password">
        </div>

        <div class="form-group">
          <label for="payu-second-key">Second key / MD5</label>
          <input type="password" id="payu-second-key" autocomplete="new-password">
        </div>
      </div>

      <div class="settings-help" style="margin-top:14px;">
        Te dane znajdziesz w panelu PayU, w konfiguracji punktu płatności. Klucze PayU zostaną zaszyfrowane.
      </div>

      <div class="integration-actions settings-actions">
        <button type="button" class="btn" id="save-payu-settings-btn">
          Zapisz ustawienia PayU
        </button>

        <button type="button" class="btn" id="test-payu-connection-btn">
          Sprawdź połączenie
        </button>
      </div>
    </div>

  </div>
</section>