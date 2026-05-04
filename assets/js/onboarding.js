(function () {
  function getSavedProfile() {
    try {
      return JSON.parse(localStorage.getItem('elevaro_profile') || 'null');
    } catch (error) {
      return null;
    }
  }

  function hasCompleteProfile(profile) {
    const values = profile && profile.values ? profile.values : null;

    if (!values) {
      return false;
    }

    return Boolean(
      values.state &&
      values.school_type &&
      values.grade &&
      values.subject
    );
  }

  function shouldSkipOnboarding() {
    const params = new URLSearchParams(window.location.search);

    if (params.has('edit') || params.has('reset') || params.has('force')) {
      return false;
    }

    return hasCompleteProfile(getSavedProfile());
  }

  if (shouldSkipOnboarding()) {
    window.location.replace('recommendations.php');
    return;
  }

  const state = {
    step: 0,
    values: {
      name: null,
      state: null,
      school_type: null,
      grade: null,
      subject: null,
      topic: null,
      focus_tags: []
    },
    labels: {}
  };

  const steps = [
    { key: 'name', type: 'name', title: 'Wie dürfen wir dich nennen?', text: 'Dein Vorname reicht völlig. So fühlt sich Elevaro persönlicher an – du kannst den Schritt aber auch überspringen.', hint: 'Dein Lernkompass startet.', illustration: '✨' },
    { key: 'state', title: 'Wo gehst du zur Schule?', text: 'Wähle dein Bundesland. So können wir später Inhalte passend zum Lehrplan vorschlagen.', hint: 'Wir richten die Inhalte auf deinen Lernort aus.', illustration: '🗺️', action: 'states' },
    { key: 'school_type', title: 'Welche Schulart passt zu dir?', text: 'Je nach Bundesland gibt es unterschiedliche Schularten. Wir zeigen dir nur passende Optionen.', hint: 'Damit die Vorschläge wirklich passen.', illustration: '🏫', action: 'school_types' },
    { key: 'grade', title: 'In welcher Klasse bist du?', text: 'Davon hängt ab, welche Fächer und Themen für dich wirklich relevant sind.', hint: 'Ein Schritt näher an passenden Quizzen.', illustration: '🎒', action: 'levels' },
    { key: 'subject', title: 'Was möchtest du üben?', text: 'Wir zeigen dir nur Fächer, die für deine Klasse sinnvoll sind.', hint: 'Jetzt wird aus Schule ein Lernziel.', illustration: '📚', action: 'subjects' },
  ];

  const els = {
    progress: document.getElementById('stepProgress'),
    illustration: document.getElementById('stepIllustration'),
    hint: document.getElementById('pandaHint'),
    badge: document.getElementById('stepBadge'),
    title: document.getElementById('stepTitle'),
    text: document.getElementById('stepText'),
    choices: document.getElementById('choices'),
    empty: document.getElementById('emptyState'),
    back: document.getElementById('backBtn'),
    skip: document.getElementById('skipBtn')
  };

  if (Object.values(els).some(el => !el)) {
    console.error('Onboarding DOM elements missing', els);
    return;
  }

  async function api(action) {
    const params = new URLSearchParams({ action });
    if (state.values.state) params.set('state', state.values.state);
    if (state.values.school_type) params.set('school_type', state.values.school_type);
    if (state.values.grade) params.set('grade', state.values.grade);
    if (state.values.subject) params.set('subject', state.values.subject);

    const res = await fetch('api/curriculum.php?' + params.toString());
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'API error');
    return json.items || [];
  }

  function renderNameStep() {
    els.choices.innerHTML = `
      <div class="name-step-card">
        <label for="profileNameInput" class="form-label fw-bold">Dein Vorname</label>
        <div class="name-input-row">
          <input id="profileNameInput" class="form-control form-control-lg" type="text" maxlength="40" autocomplete="given-name" placeholder="z. B. Felix">
          <button id="nameContinueBtn" class="btn btn-primary btn-lg" type="button">Weiter</button>
        </div>
        <p class="small text-muted mt-3 mb-0">Du musst nichts angeben. Elevaro funktioniert auch ohne Namen.</p>
      </div>`;

    const input = document.getElementById('profileNameInput');
    const btn = document.getElementById('nameContinueBtn');
    const savedName = localStorage.getItem('elevaro_profile_name');
    if (savedName) input.value = savedName;
    setTimeout(() => input.focus(), 80);

    btn.addEventListener('click', () => {
      const name = input.value.trim();
      state.values.name = name || null;
      if (name) {
        state.labels.name = name;
        localStorage.setItem('elevaro_profile_name', name);
      } else {
        delete state.labels.name;
        localStorage.removeItem('elevaro_profile_name');
      }
      state.step++;
      render();
    });

    input.addEventListener('keydown', event => {
      if (event.key === 'Enter') btn.click();
    });
  }

  function itemCode(item, key) {
    return String(item.code ?? item.slug ?? item.id ?? '');
  }

  function itemLabel(item, key) {
    return item.title || item.name || item.code || item.slug || '';
  }

  function itemMeta(item, key) {
    if (key === 'school_type') return `Klasse ${item.min_grade} bis ${item.max_grade}`;
    if (key === 'subject') return item.icon ? item.icon + ' Fach auswählen' : 'Fach auswählen';
    if (key === 'topic') {
      const count = item.quiz_count ? ` · ${item.quiz_count} Quiz${Number(item.quiz_count) === 1 ? '' : 'ze'}` : '';
      return (item.description || 'Lernbereich starten') + count;
    }
    return '';
  }

  function focusTagsFromArea(item) {
    if (Array.isArray(item.tags)) return item.tags.map(String).filter(Boolean);
    if (typeof item.tags === 'string') return item.tags.split(',').map(tag => tag.trim()).filter(Boolean);
    return [item.code || item.slug].filter(Boolean).map(String);
  }

  function renderChoiceButton(item, step) {
    const code = itemCode(item, step.key);
    const label = itemLabel(item, step.key);
    const btn = document.createElement('button');
    btn.className = 'choice-btn';
    btn.type = 'button';
    btn.innerHTML = `<span class="choice-title">${escapeHtml(label)}</span><span class="choice-meta">${escapeHtml(itemMeta(item, step.key))}</span>`;
    btn.addEventListener('click', () => choose(code, label, item));
    return btn;
  }

  function renderSchoolTypeChoices(items, step) {
    const generalItems = items.filter(item => (item.school_category || 'general') !== 'vocational');
    const vocationalItems = items.filter(item => (item.school_category || 'general') === 'vocational');

    generalItems.forEach(item => {
      els.choices.appendChild(renderChoiceButton(item, step));
    });

    if (!vocationalItems.length) {
      return;
    }

    const details = document.createElement('details');
    details.className = 'vocational-school-group';
    details.innerHTML = `
      <summary>
        <span>Berufliche Schulen</span>
        <small>Berufskolleg, Berufsschule, Berufliches Gymnasium …</small>
      </summary>
      <div class="vocational-school-grid"></div>
    `;

    const grid = details.querySelector('.vocational-school-grid');

    vocationalItems.forEach(item => {
      grid.appendChild(renderChoiceButton(item, step));
    });

    els.choices.appendChild(details);
  }

  async function render() {
    const step = steps[state.step];
    document.body.dataset.step = String(state.step);

    els.progress.style.width = `${((state.step + 1) / steps.length) * 100}%`;
    els.illustration.textContent = step.illustration;
    els.illustration.classList.remove('success-pop');
    void els.illustration.offsetWidth;
    els.illustration.classList.add('success-pop');
    els.hint.textContent = step.hint;
    els.badge.textContent = `Schritt ${state.step + 1} von ${steps.length}`;
    els.title.textContent = step.title;
    els.text.textContent = step.text;
    els.back.disabled = state.step === 0;
    els.choices.innerHTML = '';
    els.empty.classList.add('d-none');

    if (step.type === 'name') {
      els.skip.textContent = 'Überspringen';
      renderNameStep();
      return;
    }

    els.skip.textContent = 'Überspringen';

    try {
      const items = await api(step.action);
      if (!items.length) els.empty.classList.remove('d-none');

      if (step.key === 'school_type') {
        renderSchoolTypeChoices(items, step);
      } else {
        items.forEach(item => {
          els.choices.appendChild(renderChoiceButton(item, step));
        });
      }
    } catch (err) {
      els.empty.textContent = 'Die Auswahl konnte gerade nicht geladen werden.';
      els.empty.classList.remove('d-none');
      console.error(err);
    }
  }

  function choose(code, label, item) {
    const step = steps[state.step];
    state.values[step.key] = code;
    state.labels[step.key] = label;

    for (let i = state.step + 1; i < steps.length; i++) {
      state.values[steps[i].key] = null;
      delete state.labels[steps[i].key];
    }

    if (state.step < steps.length - 1) {
      state.step++;
      render();
      return;
    }

    finish();
  }

  function finish() {
    const name = state.values.name || localStorage.getItem('elevaro_profile_name');
    const profile = {
      values: { ...state.values },
      labels: { ...state.labels },
      created_at: new Date().toISOString()
    };

    localStorage.setItem('elevaro_profile', JSON.stringify(profile));
    if (name) localStorage.setItem('elevaro_profile_name', name);

    document.body.dataset.step = String(steps.length);
    els.progress.style.width = '100%';
    els.illustration.textContent = '🚀';
    els.illustration.classList.add('success-pop');
    els.hint.textContent = name ? `Alles bereit, ${name}.` : 'Alles bereit.';
    els.badge.textContent = 'Bereit';
    els.title.textContent = 'Dein Lernbereich ist bereit';
    els.text.textContent = 'Wir haben deine Auswahl gespeichert und können dir jetzt passende Quizze für deinen Lernstand anzeigen.';
    els.choices.innerHTML = '';

    const selectionRows = [
      ['Bundesland', state.labels.state || state.values.state || 'Nicht gewählt'],
      ['Schulart', state.labels.school_type || state.values.school_type || 'Nicht gewählt'],
      ['Klasse', state.labels.grade || (state.values.grade ? `${state.values.grade}. Klasse` : 'Nicht gewählt')],
      ['Fach', state.labels.subject || state.values.subject || 'Nicht gewählt']
    ];

    const selectionHtml = selectionRows.map(([label, value]) => `
      <div class="onboarding-selection-row">
        <span>${escapeHtml(label)}</span>
        <strong>${escapeHtml(value)}</strong>
      </div>
    `).join('');

    els.choices.innerHTML = `
      <div class="onboarding-summary-step">
        <div class="onboarding-selection-card">
          <h2>Deine Auswahl</h2>
          <div class="onboarding-selection-grid">
            ${selectionHtml}
          </div>
        </div>

        <div class="onboarding-pricing-grid">
          <article class="onboarding-plan-card is-premium">
            <div class="plan-badge">Empfohlen</div>
            <h2>Premium</h2>
            <p class="plan-subline">Gezielt besser werden</p>
            <ul>
              <li>Unbegrenzt Quizze spielen</li>
              <li>Aufgaben üben, die noch nicht sitzen</li>
              <li>Fortschritt, Statistiken und Serien speichern</li>
              <li>Passende Empfehlungen für Klasse, Fach und Schulart</li>
              <li>Premium-Inhalte wie Listenings und Comprehension-Übungen</li>
            </ul>
            <a class="btn btn-primary btn-lg w-100" href="/register.php?mode=premium&return=${encodeURIComponent('/api/create_checkout_session.php')}">Premium freischalten</a>
            <small>4,99 € / Monat · monatlich kündbar</small>
          </article>

          <article class="onboarding-plan-card">
            <h2>Free</h2>
            <p class="plan-subline">Erstmal ausprobieren</p>
            <ul>
              <li>Einzelne Quizze kostenlos spielen</li>
              <li>Passende Empfehlungen ansehen</li>
              <li class="is-negative">Kein gespeicherter Fortschritt im Account</li>
              <li class="is-negative">Kein gezieltes Training deiner falschen Aufgaben</li>
              <li class="is-negative">Premium-Inhalte nur eingeschränkt nutzbar</li>
            </ul>
            <a class="btn btn-light btn-lg w-100" href="recommendations.php">Ohne Anmeldung starten</a>
            <small>Dein Fortschritt wird nicht dauerhaft gespeichert.</small>
          </article>
        </div>
      </div>
    `;

    els.back.disabled = true;
    els.skip.classList.add('d-none');
  }

  function goBack() {
    if (state.step === 0) return;
    state.step--;
    render();
  }

  function skip() {
    const step = steps[state.step];
    if (step.type === 'name') {
      state.values.name = null;
      delete state.labels.name;
      localStorage.removeItem('elevaro_profile_name');
      state.step++;
      render();
      return;
    }
    localStorage.setItem('elevaro_profile_skipped', new Date().toISOString());
    window.location.href = '/';
  }

  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, char => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
  }

  els.back.addEventListener('click', goBack);
  els.skip.addEventListener('click', skip);
  render();
})();
