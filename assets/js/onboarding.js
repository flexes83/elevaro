(function () {
  const state = {
    step: 0,
    values: {
      name: null,
      state: null,
      school_type: null,
      grade: null,
      subject: null,
      topic: null
    },
    labels: {}
  };

  const steps = [
    {
      key: 'name',
      type: 'name',
      title: 'Wie dürfen wir dich nennen?',
      text: 'Dein Vorname reicht völlig. So fühlt sich Elevaro persönlicher an – du kannst den Schritt aber auch überspringen.',
      hint: 'Dein Lernkompass startet.',
      illustration: '✨'
    },
    {
      key: 'state',
      title: 'Wo gehst du zur Schule?',
      text: 'Wähle dein Bundesland. So können wir später Inhalte passend zum Lehrplan vorschlagen.',
      hint: 'Wir richten die Inhalte auf deinen Lernort aus.',
      illustration: '🗺️',
      action: 'states'
    },
    {
      key: 'school_type',
      title: 'Welche Schulart passt zu dir?',
      text: 'Je nach Bundesland gibt es unterschiedliche Schularten. Wir zeigen dir nur passende Optionen.',
      hint: 'Damit die Vorschläge wirklich passen.',
      illustration: '🏫',
      action: 'school_types'
    },
    {
      key: 'grade',
      title: 'In welcher Klasse bist du?',
      text: 'Davon hängt ab, welche Fächer und Themen für dich wirklich relevant sind.',
      hint: 'Ein Schritt näher an passenden Quizzen.',
      illustration: '🎒',
      action: 'grades'
    },
    {
      key: 'subject',
      title: 'Was möchtest du üben?',
      text: 'Wir zeigen dir nur Fächer, die für deine Klasse sinnvoll sind.',
      hint: 'Jetzt wird aus Schule ein Lernziel.',
      illustration: '📚',
      action: 'subjects'
    },
    {
      key: 'topic',
      title: 'Womit willst du starten?',
      text: 'Wähle ein Thema. Danach zeigen wir dir passende Quiz-Empfehlungen.',
      hint: 'Such dir den besten Einstieg aus.',
      illustration: '🎯',
      action: 'topics'
    }
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

  const requiredElements = Object.entries(els).filter(([key, el]) => !el);
  if (requiredElements.length) {
    console.error('Onboarding DOM elements missing:', requiredElements.map(([key]) => key));
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

    if (!json.success) {
      throw new Error(json.message || 'API error');
    }

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
      </div>
    `;

    const savedName = localStorage.getItem('elevaro_profile_name');
    const input = document.getElementById('profileNameInput');
    const btn = document.getElementById('nameContinueBtn');

    if (savedName) {
      input.value = savedName;
    }

    setTimeout(() => input.focus(), 80);

    btn.addEventListener('click', () => {
      const name = input.value.trim();

      if (name) {
        state.values.name = name;
        state.labels.name = name;
        localStorage.setItem('elevaro_profile_name', name);
      } else {
        state.values.name = null;
        delete state.labels.name;
        localStorage.removeItem('elevaro_profile_name');
      }

      state.step++;
      render();
    });

    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        btn.click();
      }
    });
  }

  function itemCode(item, key) {
    if (key === 'grade') return item.code;
    if (key === 'topic') return item.code;
    return item.code;
  }

  function itemLabel(item, key) {
    if (key === 'topic') return item.title || item.name || item.code;
    return item.name || item.title || item.code;
  }

  function itemMeta(item, key) {
    if (key === 'school_type') {
      return `Klasse ${item.min_grade} bis ${item.max_grade}`;
    }

    if (key === 'subject') {
      return item.icon ? item.icon + ' Fach auswählen' : 'Fach auswählen';
    }

    if (key === 'topic') {
      return item.description || item.quiz_title || 'Thema starten';
    }

    return '';
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

    els.skip.textContent = 'Später auswählen';

    try {
      const items = await api(step.action);

      if (!items.length) {
        els.empty.classList.remove('d-none');
      }

      items.forEach(item => {
        const code = itemCode(item, step.key);
        const label = itemLabel(item, step.key);

        const btn = document.createElement('button');
        btn.className = 'choice-btn';
        btn.type = 'button';
        btn.innerHTML = `
          <span class="choice-title">${escapeHtml(label)}</span>
          <span class="choice-meta">${escapeHtml(itemMeta(item, step.key))}</span>
        `;
        btn.addEventListener('click', () => choose(code, label, item));
        els.choices.appendChild(btn);
      });
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

    finish(item);
  }

  function finish(topicItem) {
    const name = state.values.name || localStorage.getItem('elevaro_profile_name');

    localStorage.setItem('elevaro_profile', JSON.stringify({
      values: state.values,
      labels: state.labels,
      created_at: new Date().toISOString()
    }));

    if (name) {
      localStorage.setItem('elevaro_profile_name', name);
    }

    els.progress.style.width = '100%';
    document.body.dataset.step = String(steps.length);
    els.illustration.textContent = '🏆';
    els.illustration.classList.add('success-pop');
    els.hint.textContent = name ? `Perfekt, ${name}. Ich habe etwas Passendes gefunden.` : 'Perfekt. Ich habe etwas Passendes gefunden.';
    els.badge.textContent = 'Bereit';
    els.title.textContent = name ? `Dein Lernweg ist vorbereitet, ${name}.` : 'Dein Lernweg ist vorbereitet.';
    els.text.textContent = 'Wir zeigen dir jetzt passende Quiz-Empfehlungen auf Basis deiner Auswahl.';
    els.choices.innerHTML = '';

    const start = document.createElement('a');
    start.href = 'recommendations.php';
    start.className = 'btn btn-primary btn-lg';
    start.textContent = 'Empfehlungen ansehen';

    els.choices.appendChild(start);
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
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    }[char]));
  }

  els.back.addEventListener('click', goBack);
  els.skip.addEventListener('click', skip);

  render();
})();
