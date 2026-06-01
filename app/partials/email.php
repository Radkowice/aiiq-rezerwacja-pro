<section class="panel-card hidden" data-section="email">
  <div class="panel-header">
    <div>
      <h2>Powiadomienia e-mail</h2>
      <p>Skonfiguruj skrzynkę nadawczą oraz treść wiadomości wysyłanych do klientów.</p>
    </div>
  </div>

  <div class="settings-grid email-settings-grid">
    <div class="settings-card email-settings-card" data-email-card="smtp">
      <h3>Serwer SMTP</h3>
      <p class="settings-desc">
        Tutaj ustawiasz skrzynkę e-mail, z której system będzie wysyłał wiadomości do klientów. Możesz użyć poczty firmowej albo Gmaila/Google Workspace. Po zmianie danych SMTP zapisz ustawienia i sprawdź połączenie.
        <a class="settings-help-link" href="https://ai-iq.pl/wsparcie/rezerwacja-ai-iq-pro/instrukcja.html" target="_blank" rel="noopener noreferrer">Instrukcja</a>
      </p>

      <div class="settings-row">
        <div class="form-group">
          <label for="smtp-from-name">Nazwa nadawcy</label>
          <input type="text" id="smtp-from-name" placeholder="Twoja nazwa firmy lub imię i nazwisko">
        </div>

        <div class="form-group">
          <label for="smtp-from-email">Adres e-mail nadawcy</label>
          <input type="email" id="smtp-from-email" placeholder="kontakt@twojafirma.pl">
        </div>
      </div>

      <div class="settings-row">
        <div class="form-group">
          <label for="smtp-host">Host SMTP</label>
          <input type="text" id="smtp-host" placeholder="smtp.twojafirma.pl">
        </div>

        <div class="form-group">
          <label for="smtp-port">Port SMTP</label>
          <input type="number" id="smtp-port" placeholder="587">
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
            <input type="password" id="smtp-password" placeholder="Zostaw puste, aby nie zmieniać hasła">
            <button type="button" class="password-toggle" id="toggle-smtp-password" aria-label="Pokaż hasło" aria-pressed="false">👁</button>
          </div>
          <small class="field-hint">Jeśli pole hasła zostawisz puste, obecne hasło zostanie bez zmian.</small>
        </div>
      </div>

      <div class="email-actions smtp-actions">
        <button type="button" class="btn" id="save-smtp-settings-btn">Zapisz ustawienia SMTP</button>
        <button type="button" class="btn" id="test-email-connection">Sprawdź połączenie SMTP</button>
      </div>
      <div class="email-action-message" id="smtp-settings-message" hidden></div>
    </div>

    <div class="settings-card email-settings-card" data-email-card="global-template">
      <h3>Globalny szablon e-mail</h3>
      <p class="settings-desc">
        To domyślna wiadomość wysyłana do klienta po rezerwacji. System użyje tego szablonu wtedy, gdy pracownik nie ma ustawionej własnej treści wiadomości.
        <a class="settings-help-link" href="https://ai-iq.pl/wsparcie/rezerwacja-ai-iq-pro/instrukcja.html" target="_blank" rel="noopener noreferrer">Instrukcja</a>
      </p>

      <div class="settings-row email-top-row">
        <div class="form-group email-subject-group">
          <label for="booking-email-subject">Temat wiadomości</label>
          <div class="subject-field">
            <input type="text" id="booking-email-subject" placeholder="Np. Potwierdzenie rezerwacji {date} o {time}">
            <button type="button" class="subject-emoji-btn" data-action="emoji-toggle" data-target="global-subject">🙂</button>
          </div>

          <div class="subject-placeholders">
            <button type="button" class="toolbar-btn" data-insert-target="global-subject" data-insert-text="{name}">{name}</button>
            <button type="button" class="toolbar-btn" data-insert-target="global-subject" data-insert-text="{date}">{date}</button>
            <button type="button" class="toolbar-btn" data-insert-target="global-subject" data-insert-text="{time}">{time}</button>
          </div>
        </div>

        <div class="form-group email-service-group">
          <label for="booking-email-service-name">Nagłówek wiadomości</label>
          <input type="text" id="booking-email-service-name" placeholder="Np. Dziękujemy za rezerwację">
        </div>
      </div>

      <div class="form-group email-body-group">
        <label for="booking-email-content">Treść wiadomości</label>
        <div class="email-toolbar" data-toolbar-for="global" data-field-target="global-body">
          <button type="button" data-action="bold"><b>B</b></button>
          <button type="button" data-action="italic"><i>I</i></button>
          <button type="button" data-action="br">↵</button>
          <button type="button" data-action="link">Link</button>
          <button type="button" data-action="emoji-toggle" data-target="global-body">🙂</button>
          <button type="button" data-action="ph-name">{name}</button>
          <button type="button" data-action="ph-date">{date}</button>
          <button type="button" data-action="ph-time">{time}</button>
        </div>

        <div class="emoji-picker" data-emoji-picker="global" style="display:none;">
          <span>😊</span><span>😂</span><span>😍</span><span>🥰</span><span>😎</span><span>😉</span><span>😁</span><span>🤩</span><span>😇</span><span>🙂</span>
          <span>🙃</span><span>😘</span><span>😋</span><span>🤗</span><span>🤔</span><span>😴</span><span>🥳</span><span>😭</span><span>😷</span><span>🤯</span>
          <span>👍</span><span>👎</span><span>👌</span><span>🙌</span><span>💪</span><span>🙏</span><span>👋</span><span>👊</span><span>☝️</span><span>🤝</span>
          <span>👆</span><span>👇</span><span>👉</span><span>👈</span><span>🤍</span><span>❤️</span><span>🧡</span><span>💛</span><span>💚</span><span>💙</span>
          <span>💜</span><span>🖤</span><span>🤎</span><span>💕</span><span>💖</span><span>💘</span><span>💗</span><span>💞</span><span>💯</span><span>🔥</span>
          <span>✨</span><span>⭐</span><span>🌟</span><span>💡</span><span>⚡</span><span>☀️</span><span>🌙</span><span>☁️</span><span>🌈</span><span>❄️</span>
          <span>🌸</span><span>🌱</span><span>🌺</span><span>🌻</span><span>🍀</span><span>🎉</span><span>🎊</span><span>🎁</span><span>🎈</span><span>📅</span>
          <span>⏰</span><span>⌚</span><span>📌</span><span>📍</span><span>📎</span><span>📄</span><span>☎️</span><span>📧</span><span>✉️</span><span>📨</span>
          <span>📩</span><span>🔔</span><span>🔗</span><span>🖥️</span><span>💻</span><span>📱</span><span>🛒</span><span>💳</span><span>✅</span><span>☑️</span>
          <span>✔️</span><span>❌</span><span>❗</span><span>❓</span><span>⚠️</span><span>🚨</span><span>🚀</span><span>🏆</span><span>🥇</span><span>🎯</span>
          <span>📣</span><span>🔒</span><span>🔓</span><span>🧾</span><span>🏢</span><span>👤</span><span>👥</span><span>🤖</span>
        </div>

        <textarea id="booking-email-content" rows="10" placeholder="Np. Dzień dobry {name}, dziękujemy za rezerwację."></textarea>

        <div class="email-preview-box">
          <div class="email-preview-header">Podgląd wiadomości</div>
          <div id="email-preview-subject" class="email-preview-subject">Temat wiadomości pojawi się tutaj</div>
          <div id="email-preview-content" class="email-preview-content">Treść wiadomości pojawi się tutaj</div>
        </div>
      </div>

      <div class="email-actions global-email-actions">
        <button type="button" id="save-email-settings-btn" class="btn">Zapisz globalny szablon e-mail</button>
      </div>
      <div class="email-action-message" id="global-email-message" hidden></div>
    </div>

    <div class="settings-card email-settings-card" data-email-card="staff-template">
      <h3>Szablony e-mail pracowników</h3>
      <p class="settings-desc">
        Tutaj możesz ustawić osobny temat, nagłówek i treść wiadomości dla konkretnego pracownika. Jeśli zostawisz pola puste albo przywrócisz szablon globalny, system użyje wiadomości z sekcji „Globalny szablon e-mail”.
        <a class="settings-help-link" href="https://ai-iq.pl/wsparcie/rezerwacja-ai-iq-pro/instrukcja.html" target="_blank" rel="noopener noreferrer">Instrukcja</a>
      </p>

      <div class="form-group staff-email-select-group">
        <label for="staff-email-template-select">Pracownik</label>
        <select id="staff-email-template-select">
          <option value="">Wybierz pracownika</option>
        </select>
      </div>

      <div class="staff-email-template-status" id="staff-email-template-status">
        Wybierz pracownika, aby ustawić jego własny szablon e-mail.
      </div>

      <div class="settings-row email-top-row">
        <div class="form-group">
          <label for="staff-email-subject">Temat wiadomości</label>
          <div class="subject-field">
            <input type="text" id="staff-email-subject" placeholder="Temat wiadomości pracownika" disabled>
            <button type="button" class="subject-emoji-btn" data-action="emoji-toggle" data-target="staff-subject">🙂</button>
          </div>
          <div class="subject-placeholders">
            <button type="button" class="toolbar-btn" data-insert-target="staff-subject" data-insert-text="{name}">{name}</button>
            <button type="button" class="toolbar-btn" data-insert-target="staff-subject" data-insert-text="{date}">{date}</button>
            <button type="button" class="toolbar-btn" data-insert-target="staff-subject" data-insert-text="{time}">{time}</button>
          </div>
        </div>

        <div class="form-group">
          <label for="staff-email-heading">Nagłówek wiadomości</label>
          <input type="text" id="staff-email-heading" placeholder="Nagłówek wiadomości pracownika" disabled>
        </div>
      </div>

      <div class="form-group email-body-group">
        <label for="staff-email-body">Treść wiadomości</label>
        <div class="email-toolbar staff-email-toolbar" data-toolbar-for="staff" data-field-target="staff-body">
          <button type="button" data-action="bold"><b>B</b></button>
          <button type="button" data-action="italic"><i>I</i></button>
          <button type="button" data-action="br">↵</button>
          <button type="button" data-action="link">Link</button>
          <button type="button" data-action="emoji-toggle" data-target="staff-body">🙂</button>
          <button type="button" data-action="ph-name">{name}</button>
          <button type="button" data-action="ph-date">{date}</button>
          <button type="button" data-action="ph-time">{time}</button>
        </div>
        <div class="emoji-picker" data-emoji-picker="staff" style="display:none;"></div>
        <textarea id="staff-email-body" rows="8" placeholder="Treść wiadomości pracownika" disabled></textarea>
      </div>

      <div class="email-preview-box staff-email-preview">
        <div class="email-preview-header">Podgląd wiadomości pracownika</div>
        <div id="staff-email-preview-status" class="email-preview-status">
          Wybierz pracownika, aby zobaczyć podgląd jego wiadomości.
        </div>
        <div id="staff-email-preview-subject" class="email-preview-subject">
          Temat wiadomości pracownika pojawi się tutaj
        </div>
        <div id="staff-email-preview-heading" class="email-preview-heading">
          Nagłówek wiadomości pracownika pojawi się tutaj
        </div>
        <div id="staff-email-preview-content" class="email-preview-content">
          Treść wiadomości pracownika pojawi się tutaj
        </div>
      </div>

      <div class="email-actions staff-email-actions">
        <button type="button" class="btn" id="save-staff-email-template-btn" disabled>Zapisz szablon pracownika</button>
        <button type="button" class="btn" id="reset-staff-email-template-btn" disabled>Przywróć globalny szablon</button>
      </div>
      <div class="email-action-message" id="staff-email-template-message" hidden></div>
    </div>
  </div>
</section>
