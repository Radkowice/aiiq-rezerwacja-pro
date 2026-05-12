<section class="panel-card hidden" data-section="email">
  <h2>Powiadomienia e-mail</h2>

  <div class="settings-grid">

    <div class="settings-card">
      <h3>Serwer SMTP</h3>
     <p class="settings-desc">
  Ustaw dane konta pocztowego, z którego system będzie wysyłał powiadomienia.
  Przed konfiguracją zapoznaj się z
  <a
    class="settings-help-link"
    href="https://ai-iq.pl/wsparcie/rezerwacja-ai-iq-pro/instrukcja.html"
    target="_blank"
    rel="noopener noreferrer"
  >instrukcją</a>.
</p>

      <div class="settings-row">
        <div class="form-group">
          <label for="smtp-from-name">Nazwa nadawcy</label>
          <input type="text" id="smtp-from-name" placeholder="Twoja nazwa firmy lub imię i nazwisko">
        </div>

        <div class="form-group">
          <label for="smtp-from-email">Adres e-mail nadawcy</label>
          <input type="email" id="smtp-from-email" placeholder="Twój email">
        </div>
      </div>

      <div class="settings-row">
        <div class="form-group">
          <label for="smtp-host">Host SMTP</label>
          <input type="text" id="smtp-host" placeholder="host serwera">
        </div>

        <div class="form-group">
          <label for="smtp-port">Port SMTP</label>
          <input type="number" id="smtp-port" placeholder="">
        </div>
      </div>

      <div class="settings-row">
        <div class="form-group">
          <label for="smtp-username">Login SMTP</label>
          <input type="text" id="smtp-username" placeholder="email nadawcy">
        </div>

        <div class="form-group">
          <label for="smtp-password">Hasło SMTP</label>
          <div class="password-field">
            <input type="password" id="smtp-password" placeholder="Wpisz hasło SMTP">
            <button
              type="button"
              class="password-toggle"
              id="toggle-smtp-password"
              aria-label="Pokaż hasło"
              aria-pressed="false"
            >
              👁
            </button>
            </div>
            <div style="margin-top:10px;">
    <button type="button" class="btn" id="test-email-connection">
        Sprawdź połączenie SMTP
    </button>
</div>
        </div>
        </div>
        </div>
             
    <div class="settings-card">
      <h3>Temat wiadomości</h3>
      <p class="settings-desc">
        Ustaw temat wiadomości, którą otrzyma klient po rezerwacji.
      </p>

      <div class="settings-row email-top-row">
        <div class="form-group email-subject-group">
          <label for="booking-email-subject">
            Tytuł e-maila (możesz używać np. {date}, {time})
          </label>

          <div class="subject-field">
            <input
              type="text"
              id="booking-email-subject"
              placeholder="Wpisz temat emaila dla klienta"
            >
            <button
              type="button"
              class="subject-emoji-btn"
              data-action="emoji-toggle"
              data-target="subject"
            >
              😊
            </button>
          </div>

          <div class="subject-placeholders">
            <button type="button" class="toolbar-btn" data-subject-placeholder="{date}">{date}</button>
            <button type="button" class="toolbar-btn" data-subject-placeholder="{time}">{time}</button>
          </div>
        </div>

        <div class="form-group email-service-group">
          <label for="booking-email-service-name">
            Nagłówek potwierdzenia / nazwa usługi
          </label>
          <input
            type="text"
            id="booking-email-service-name"
            placeholder="Np. konsultacji, badania, wizyty, strzyżenia"
          />
          <small class="field-hint">
            To pole będzie użyte w nagłówku maila klienta, „Dziękujemy za umówienie (Twoja wiadomość)”
          </small>
        </div>
      </div>
    </div>

    <div class="settings-card full-width-card">
      <h3>Treść wiadomości dla klienta</h3>
      <p class="settings-desc">
        Ustaw treść wiadomości, którą otrzyma klient po rezerwacji.
      </p>


      <div class="form-group">
        <div class="email-toolbar">
          <button type="button" data-action="bold"><b>B</b></button>
          <button type="button" data-action="italic"><i>I</i></button>
          <button type="button" data-action="center">⏺</button>
          <button type="button" data-action="br">↵</button>
          <button type="button" data-action="link">🔗</button>
          <button type="button" data-action="emoji-toggle">😊</button>
          <button type="button" data-action="ph-name">{name}</button>
        </div>

        <div class="emoji-picker" style="display:none;">
          <span>😊</span><span>😂</span><span>😍</span><span>🥰</span><span>😎</span><span>😉</span><span>😁</span><span>🤩</span><span>😇</span><span>🙂</span>
          <span>🙃</span><span>😘</span><span>😋</span><span>🤗</span><span>🤔</span><span>😴</span><span>🥳</span><span>😭</span><span>😡</span><span>🤯</span>
          <span>👍</span><span>👎</span><span>👏</span><span>🙌</span><span>💪</span><span>🙏</span><span>👋</span><span>👌</span><span>✌️</span><span>🤝</span>
          <span>👆</span><span>👇</span><span>👉</span><span>👈</span><span>🤍</span><span>❤️</span><span>🧡</span><span>💛</span><span>💚</span>
          <span>💙</span><span>💜</span><span>🖤</span><span>🤎</span><span>💕</span><span>💖</span><span>💘</span><span>💝</span><span>💞</span><span>💯</span>
          <span>🔥</span><span>✨</span><span>⭐</span><span>🌟</span><span>💥</span><span>⚡</span><span>☀️</span><span>🌙</span><span>☁️</span><span>🌈</span>
          <span>❄️</span><span>🌸</span><span>🌹</span><span>🌺</span><span>🌻</span><span>🍀</span><span>🎉</span><span>🎊</span><span>🎁</span><span>🎈</span>
          <span>📅</span><span>⏰</span><span>⌚</span><span>📌</span><span>📍</span><span>📝</span><span>📄</span><span>📞</span><span>☎️</span><span>📧</span>
          <span>✉️</span><span>📨</span><span>📩</span><span>🔔</span><span>🔗</span><span>🖥️</span><span>💻</span><span>📱</span><span>🛒</span><span>💳</span>
          <span>✅</span><span>☑️</span><span>✔️</span><span>❌</span><span>❗</span><span>❓</span><span>⚠️</span><span>🚨</span><span>🚀</span><span>🏆</span>
          <span>🥇</span><span>🎯</span><span>📣</span><span>🔒</span><span>🔓</span><span>🧾</span><span>🏢</span><span>👤</span><span>👥</span><span>🤖</span>
        </div>

        <label for="booking-email-content">
          Treść (możesz używać np. {name}). Nie wpisuj daty, godziny. Pod treścią emaila klient dostanie systemowe potwierdzenie szczegółowe
        </label>

        <textarea
          id="booking-email-content"
          rows="10"
          placeholder="Np. Dzień dobry {name} 😊 Proszę na wizytę przygotować..."
        ></textarea>

        <div class="email-preview-box">
          <div class="email-preview-header">Podgląd tytułu i wiadomości</div>
          <div id="email-preview-subject" class="email-preview-subject">
            Temat wiadomości pojawi się tutaj
          </div>
          <div id="email-preview-content" class="email-preview-content">
            Treść wiadomości pojawi się tutaj
          </div>
        </div>
      </div>
    </div>

    <div class="settings-card settings-actions-card">
      <h3>Zapis ustawień</h3>
      <p class="settings-desc">
        Ustawienia zapisują email oraz powiadomienia dla klienta.
      </p>

      <div class="settings-actions">
        <button type="button" id="save-email-settings-btn" class="btn">
          Zapisz ustawienia email
        </button>
      </div>
    </div>

  </div>
</section>