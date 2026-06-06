<section class="panel-card hidden" data-section="moje_konto">
 
  <h2>Moje konto</h2>
  <p class="message" id="settings-message"></p>

  <div class="account-grid">

    <div class="admin-card">
      <h3>Dane konta</h3>
      
      <div class="form-group">
        <label>Numer klienta</label>
        <input type="text" id="account-client-number" readonly placeholder="Generowany automatycznie">
      </div>

      <div class="form-group">
        <label>Nazwa publiczna firmy - marka</label>
        <input type="text" id="account-company-name" placeholder="Nazwa firmy">
      </div>

      <div class="form-group">
        <label>Nr identyf. firmy</label>
        <input type="text" id="account-company-id" readonly placeholder="Generowany automatycznie">
      </div>

      <div class="form-group">
        <label>Aktualny e-mail</label>
        <input type="email" id="account-email" readonly>
      </div>

      <div class="form-group">
        <label>Rola</label>
        <input type="text" id="account-role" readonly>
      </div>

      <button class="btn" type="button" id="save-company-btn">
        Zapisz nazwę firmy
      </button>
    </div>

    <div class="admin-card">
      <h3>Zmiana email</h3>

      <div class="form-group">
        <label for="new-email">Nowy e-mail</label>
        <input type="email" id="new-email" placeholder="Podaj nowy adres e-mail" autocomplete="off">
      </div>

      <button class="btn" type="button" id="change-email-btn">
        Zapisz nowy e-mail
      </button>
    </div>

    <div class="admin-card">
      <h3>Zmiana hasła</h3>

      <div class="form-group">
        <label for="current-password">Obecne hasło</label>
        <div class="password-field">
          <input type="password" id="current-password" autocomplete="off" placeholder="Wpisz obecne hasło">
          <button type="button" class="toggle-password" data-target="current-password" aria-label="Pokaż lub ukryj hasło">👁</button>
        </div>
      </div>

      <div class="form-group">
        <label for="new-password">Nowe hasło</label>
        <div class="password-field">
          <input type="password" id="new-password" autocomplete="new-password" placeholder="Wpisz nowe hasło">
          <button type="button" class="toggle-password" data-target="new-password" aria-label="Pokaż lub ukryj hasło">👁</button>
        </div>

        <div id="password-strength" class="password-strength"></div>

        <div id="password-rules" class="password-rules">
          <div class="password-rule" data-rule="length">• minimum 8 znaków</div>
          <div class="password-rule" data-rule="lower">• mała litera</div>
          <div class="password-rule" data-rule="upper">• duża litera</div>
          <div class="password-rule" data-rule="number">• cyfra</div>
          <div class="password-rule" data-rule="special">• znak specjalny</div>
        </div>
      </div>

      <div class="form-group">
        <label for="confirm-password">Powtórz nowe hasło</label>
        <div class="password-field">
          <input type="password" id="confirm-password" autocomplete="new-password" placeholder="Powtórz nowe hasło">
          <button type="button" class="toggle-password" data-target="confirm-password" aria-label="Pokaż lub ukryj hasło">👁</button>
        </div>
      </div>

    <button class="btn" type="button" id="change-password-btn">
  Zmień hasło
</button>

    <div id="password-code-section" class="hidden">
  <div class="form-group">
    <label for="password-code">Kod z e-maila</label>
    <input type="text" id="password-code" maxlength="6" placeholder="Wpisz 6-cyfrowy kod">
  </div>

  <div id="password-code-timer" class="password-code-timer"></div>

  <div class="form-group">
    <button class="btn" type="button" id="confirm-password-code-btn">
      Potwierdź kod
    </button>
    <button class="btn" type="button" id="resend-password-code-btn">
      Wyślij ponownie
    </button>
  </div>
</div>
</div>

<div class="admin-card">
  <h3>Usuń konto</h3>

      <p style="font-size:14px; opacity:.8; margin-bottom:15px;">
        Usunięcie konta jest nieodwracalne. Wszystkie dane zostaną trwale usunięte jeśli jest tylko 1 użytkownik danej firmy.
      </p>

      <div class="form-group">
        <label for="delete-password">Podaj hasło</label>
        <input type="password" id="delete-password" placeholder="Potwierdź hasłem">
      </div>

      <button class="btn" type="button" id="delete-account-btn" style="background:#dc2626; color:#fff;">
        Usuń konto
      </button>
    </div>

    <div class="admin-card">
      <h3>Branding klienta</h3>

     <div class="form-group">
  <label for="account-logo">Logo</label>
  <input type="file" id="account-logo" accept="image/*">
  <small>Dozwolone formaty: PNG, JPG, WEBP. Maksymalnie 2 MB.</small>

  <div class="branding-logo-preview-wrap">
    <div class="branding-logo-preview-label">Aktualne logo</div>
    <img id="account-logo-preview" class="branding-logo-preview" src="" alt="Aktualne logo" style="display:none;">
    <div id="account-logo-empty" class="branding-logo-empty">Brak wgranego logo</div>

    <button class="btn branding-remove-btn" type="button" id="delete-logo-front-btn" style="display:none;">
      Usuń logo
    </button>
  </div>
</div>

<div class="form-group">
  <label for="account-favicon">Favicon frontu kalendarza</label>
  <input type="file" id="account-favicon" accept="image/png,image/jpeg,image/webp,image/x-icon,image/vnd.microsoft.icon">
  <small>Dozwolone formaty: PNG, JPG, WEBP, ICO. Maksymalnie 512 KB.</small>

  <div class="branding-favicon-preview-wrap">
    <div class="branding-logo-preview-label">Aktualna favicon</div>
    <img id="account-favicon-preview" class="branding-favicon-preview" src="" alt="Aktualna favicon" style="display:none;">
    <div id="account-favicon-empty" class="branding-logo-empty">Brak wgranej favicony</div>

    <button class="btn branding-remove-btn" type="button" id="delete-favicon-front-btn" style="display:none;">
      Usuń faviconę
    </button>
  </div>
</div>
      
     <div class="form-group">
        <label for="account-theme">Motyw panelu</label>
        <select id="account-theme">
          <option value="light">Jasny</option>
          <option value="gray">Szary</option>
          <option value="dark">Ciemny</option>
        </select>
      </div>

      <button class="btn" type="button" id="save-branding-btn">
        Zapisz branding
      </button>
    </div>

    <div class="admin-card">
      <h3>Wygląd zakładki Rezerwacje</h3>

      <div class="form-group">
        <label for="reservations-bg-color">Kolor tła sekcji</label>
        <input type="color" id="reservations-bg-color" value="#e5ebf2">
      </div>

      <div class="form-group">
        <label for="reservations-card-color">Kolor kafelków</label>
        <input type="color" id="reservations-card-color" value="#f8fafc">
      </div>

      <div class="form-group">
        <label for="reservations-table-color">Kolor tabeli</label>
        <input type="color" id="reservations-table-color" value="#eef2f7">
      </div>

      <div class="form-group">
        <label for="reservations-header-color">Kolor nagłówka tabeli</label>
        <input type="color" id="reservations-header-color" value="#cbd5e1">
      </div>

      <div class="form-group">
        <label for="reservations-border-color">Kolor ramek</label>
        <input type="color" id="reservations-border-color" value="#94a3b8">
      </div>

      <div class="form-group">
        <label for="reservations-radius">Zaokrąglenia</label>
        <input type="range" id="reservations-radius" min="0" max="30" value="16">
      </div>

      <button class="btn" type="button" id="save-reservations-style-btn">
        Zapisz wygląd rezerwacji
      </button>
    </div>

    <div class="admin-card">
      <h3>Wygląd kalendarza frontowego</h3>

      <div class="form-group">
        <label for="front-bg-color">Kolor tła</label>
        <input type="color" id="front-bg-color" value="#ffffff">
      </div>

      <div class="form-group">
        <label for="front-cell-color">Kolor komórek</label>
        <input type="color" id="front-cell-color" value="#f3f4f6">
      </div>

      <div class="form-group">
        <label for="front-active-color">Kolor aktywnego dnia</label>
        <input type="color" id="front-active-color" value="#2563eb">
      </div>

      <div class="form-group">
        <label for="front-blocked-color">Kolor blokad</label>
        <input type="color" id="front-blocked-color" value="#dc2626">
      </div>

      <div class="form-group">
        <label for="front-radius">Zaokrąglenia</label>
        <input type="range" id="front-radius" min="0" max="30" value="14">
      </div>

      <div class="form-group">
        <label for="front-width">Szerokość kontenera</label>
        <input type="range" id="front-width" min="320" max="1400" value="900">
      </div>

      <button class="btn" type="button" id="save-front-style-btn">
        Zapisz wygląd kalendarza
      </button>
    </div>

    <div class="admin-card">
      <h3>Pola formularza</h3>

      <div class="form-group">
        <label for="label-name">Nazwa pola: Imię i nazwisko</label>
        <input type="text" id="label-name" value="Imię i nazwisko">
      </div>

      <div class="form-group">
        <label for="label-email">Nazwa pola: E-mail</label>
        <input type="text" id="label-email" value="E-mail">
      </div>

      <div class="form-group">
        <label for="label-phone">Nazwa pola: Telefon</label>
        <input type="text" id="label-phone" value="Telefon">
      </div>

      <div class="form-group">
        <label for="label-notes">Nazwa pola: Wiadomość</label>
        <input type="text" id="label-notes" value="Wiadomość">
      </div>

          <div class="form-group">
        <label><input type="checkbox" id="toggle-phone-field" checked> Pokaż pole telefon</label>
      </div>

      <div class="form-group">
        <label><input type="checkbox" id="toggle-notes-field" checked> Pokaż pole wiadomość</label>
      </div>

      <button class="btn" type="button" id="save-form-fields-btn">
        Zapisz pola formularza
      </button>
    </div>

  </div>

</section>
