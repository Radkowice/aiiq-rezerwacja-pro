<section class="panel-card hidden" data-section="dokumenty_prawne">
  <div class="panel-header">
    <h2>Dokumenty prawne</h2>
    <p>Ustaw regulamin i politykę prywatności swojej firmy widoczne dla klientów podczas rezerwacji.</p>
  </div>

  <div class="settings-grid">
    <div class="settings-card">
      <h3>Regulamin rezerwacji</h3>
      <p class="settings-desc">
        Regulamin będzie linkowany przy checkboxie zgody na froncie rezerwacji.
      </p>

      <div class="settings-row">
        <div class="form-group">
          <label for="legal-terms-title">Tytuł regulaminu</label>
          <input
            id="legal-terms-title"
            type="text"
            placeholder="Regulamin rezerwacji"
            maxlength="150"
          >
        </div>
      </div>

      <div class="form-group legal-editor-group">
        <label for="legal-terms-content">Treść regulaminu</label>
        <textarea
          id="legal-terms-content"
          rows="14"
          placeholder="Wpisz treść regulaminu swojej firmy..."
        ></textarea>
      </div>
    </div>

    <div class="settings-card">
      <h3>Polityka prywatności</h3>
      <p class="settings-desc">
        Polityka prywatności będzie będzie linkowana przy checkboxie zgody na froncie rezerwacji.
      </p>

      <div class="settings-row">
        <div class="form-group">
          <label for="legal-privacy-title">Tytuł polityki prywatności</label>
          <input
            id="legal-privacy-title"
            type="text"
            placeholder="Polityka prywatności"
            maxlength="150"
          >
        </div>
      </div>

      <div class="form-group legal-editor-group">
        <label for="legal-privacy-content">Treść polityki prywatności</label>
        <textarea
          id="legal-privacy-content"
          rows="14"
          placeholder="Wpisz treść polityki prywatności swojej firmy..."
        ></textarea>
      </div>
    </div>

    <div class="settings-card settings-actions-card">
      <h3>Publikacja dokumentów</h3>
      <p class="settings-desc">
        Po włączeniu linki do dokumentów klienta pojawią się przy zgodzie na froncie rezerwacji.
      </p>

      <label class="legal-check-row" for="legal-is-enabled">
        <input id="legal-is-enabled" type="checkbox">
        <span>Włącz dokumenty prawne w kalendarzu rezerwacji</span>
      </label>

      <div class="legal-links-row">
        <a href="/dokumenty/regulamin.html" target="_blank" rel="noopener">Podgląd regulaminu</a>
        <a href="/dokumenty/polityka-prywatnosci.html" target="_blank" rel="noopener">Podgląd polityki prywatności</a>
      </div>

    <div class="settings-actions">
  <button id="save-legal-documents-btn" type="button">
    Zapisz dokumenty prawne
  </button>
</div>

<p id="legal-documents-message" class="settings-help" aria-live="polite"></p>
 </div>
  </div>
</section>