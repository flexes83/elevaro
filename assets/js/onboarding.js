const state = {
  step: 0,
  values: {
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
    key: 'state',
    title: 'Wo gehst du zur Schule?',
    text: 'Wähle dein Bundesland. So können wir später Inhalte passend zum Lehrplan vorschlagen.',
    hint: 'Ich suche nach deinem Lehrplan.',
    illustration: '🗺️',
    action: 'states'
  },
  {
    key: 'school_type',
    title: 'Welche Schulart passt zu dir?',
    text: 'Je nach Bundesland gibt es unterschiedliche Schularten. Wir zeigen dir nur passende Optionen.',
    hint: 'Okay, jetzt wird’s genauer.',
    illustration: '🏫',
    action: 'school_types'
  },
  {
    key: 'grade',
    title: 'In welcher Klasse bist du?',
    text: 'Davon hängt ab, welche Fächer und Themen für dich wirklich relevant sind.',
    hint: 'Nice. Das grenzt die Themen ein.',
    illustration: '🎒',
    action: 'grades'
  },
  {
    key: 'subject',
    title: 'Was möchtest du üben?',
    text: 'Wir zeigen dir nur Fächer, die für deine Klasse sinnvoll sind.',
    hint: 'Fast geschafft.',
    illustration: '📚',
    action: 'subjects'
  },
  {
    key: 'topic',
    title: 'Womit willst du starten?',
    text: 'Wähle ein Thema. Danach kannst du direkt losquizzen.',
    hint: 'Bereit für dein erstes Quiz?',
    illustration: '🚀',
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

  els.progress.style.width = `${((state.step + 1) / steps.length) * 100}%`;
  els.illustration.textContent = step.illustration;
  els.hint.textContent = step.hint;
  els.badge.textContent = `Schritt ${state.step + 1} von ${steps.length}`;
  els.title.textContent = step.title;
  els.text.textContent = step.text;
  els.back.disabled = state.step === 0;
  els.choices.innerHTML = '';
  els.empty.classList.add('d-none');

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

  // Reset dependent values.
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
  localStorage.setItem('elevaro_profile', JSON.stringify({
    values: state.values,
    labels: state.labels,
    created_at: new Date().toISOString()
  }));

  els.progress.style.width = '100%';
  els.illustration.textContent = '🎉';
  els.illustration.classList.add('success-pop');
  els.hint.textContent = 'Perfekt. Ich habe etwas Passendes gefunden.';
  els.badge.textContent = 'Bereit';
  els.title.textContent = 'Dein Lernweg ist vorbereitet.';
  els.text.textContent = 'Du kannst jetzt direkt mit einem passenden Quiz starten. Deine Auswahl merken wir uns für später.';
  els.choices.innerHTML = '';

  const quizKey = topicItem && topicItem.quiz_key ? topicItem.quiz_key : null;
  const href = quizKey ? `quiz.php?key=${encodeURIComponent(quizKey)}` : '/';

  const start = document.createElement('a');
  start.href = href;
  start.className = 'btn btn-primary btn-lg';
  start.textContent = quizKey ? 'Quiz starten' : 'Zur Übersicht';

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
