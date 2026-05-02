(function () {
  const profile = readProfile();

  const els = {
    heroText: document.getElementById('heroText'),
    heroIcon: document.getElementById('heroIcon'),
    state: document.getElementById('stateLabel'),
    schoolType: document.getElementById('schoolTypeLabel'),
    grade: document.getElementById('gradeLabel'),
    subject: document.getElementById('subjectLabel'),
    topicKicker: document.getElementById('topicKicker'),
    sectionIntro: document.getElementById('sectionIntro'),
    loading: document.getElementById('loadingState'),
    grid: document.getElementById('recommendationsGrid'),
    empty: document.getElementById('emptyState')
  };

  if (!profile) {
    window.location.href = 'onboarding.php';
    return;
  }

  renderProfile(profile);
  loadRecommendations(profile);

  function readProfile() {
    try {
      return JSON.parse(localStorage.getItem('elevaro_profile') || 'null');
    } catch (error) {
      return null;
    }
  }

  function renderProfile(profile) {
    const labels = profile.labels || {};
    const values = profile.values || {};

    els.state.textContent = labels.state || values.state || '–';
    els.schoolType.textContent = labels.school_type || values.school_type || '–';
    els.grade.textContent = labels.grade || (values.grade ? `${values.grade}. Klasse` : '–');
    els.subject.textContent = labels.subject || values.subject || '–';

    const name = localStorage.getItem('elevaro_profile_name');
    const topic = labels.topic || values.topic;

    if (name) {
      els.heroText.textContent = `${name}, wir haben deine Auswahl gespeichert und schlagen dir passende Quizze vor.`;
    }

    if (topic) {
      els.topicKicker.classList.remove('d-none');
      els.topicKicker.textContent = `Thema: ${topic}`;
      els.sectionIntro.textContent = 'Zuerst zeigen wir Quizze zu deinem gewählten Thema. Danach kannst du ähnliche Inhalte entdecken.';
    }

    const subjectIconMap = {
      mathe: '➗',
      mathematik: '➗',
      deutsch: '📖',
      englisch: '🇬🇧',
      geographie: '🗺️',
      erdkunde: '🗺️',
      biologie: '🌱',
      bio: '🌱',
      physik: '🧲',
      chemie: '⚗️'
    };

    const subjectKey = String(values.subject || labels.subject || '').toLowerCase();
    els.heroIcon.textContent = subjectIconMap[subjectKey] || '🎯';
  }

  function loadRecommendations(profile) {
    const values = profile.values || {};
    const params = new URLSearchParams();

    ['state', 'school_type', 'grade', 'subject', 'topic'].forEach(key => {
      if (values[key]) {
        params.set(key, values[key]);
      }
    });

    fetch(`api/recommendations.php?${params.toString()}`)
      .then(response => response.json())
      .then(data => {
        const items = Array.isArray(data.items) ? data.items : [];
        renderRecommendations(items);
      })
      .catch(() => {
        showEmpty();
      });
  }

  function renderRecommendations(items) {
    const usableItems = items.filter(item => item.quiz_key);

    els.loading.classList.add('d-none');

    if (!usableItems.length) {
      showEmpty();
      return;
    }

    els.grid.innerHTML = '';
    els.grid.classList.remove('d-none');
    els.empty.classList.add('d-none');

    usableItems.forEach(item => {
      els.grid.appendChild(createQuizCard(item));
    });
  }

  function createQuizCard(item) {
    const card = document.createElement('article');
    card.className = 'quiz-card';

    const color1 = item.theme_color_1 || subjectColor(item.subject_code, 0);
    const color2 = item.theme_color_2 || subjectColor(item.subject_code, 1);
    const emoji = item.theme_emoji || item.subject_icon || subjectEmoji(item.subject_code);
    const hasImage = item.image_path && (!item.image_status || item.image_status === 'approved');

    card.style.setProperty('--card-c1', color1);
    card.style.setProperty('--card-c2', color2);

    const visual = hasImage
      ? `<img class="quiz-card-img" src="${escapeAttr(item.image_path)}" alt="">`
      : `<span class="quiz-card-emoji">${escapeHtml(emoji)}</span>`;

    card.innerHTML = `
      <div class="quiz-card-visual">
        ${visual}
        <span class="quiz-card-badge">${escapeHtml(item.subject_name || 'Quiz')} · ${escapeHtml(item.topic_title || 'Thema')}</span>
      </div>
      <div class="quiz-card-body">
        <h3>${escapeHtml(item.quiz_title || item.topic_title || 'Quiz')}</h3>
        <p>${escapeHtml(item.quiz_description || item.topic_description || 'Starte ein kurzes Quiz mit direktem Feedback.')}</p>
        <div class="quiz-card-footer">
          <a class="btn btn-primary" href="quiz.php?key=${encodeURIComponent(item.quiz_key)}">Quiz starten</a>
          <span class="quiz-meta">kurz & motivierend</span>
        </div>
      </div>
    `;

    return card;
  }

  function showEmpty() {
    els.loading.classList.add('d-none');
    els.grid.classList.add('d-none');
    els.empty.classList.remove('d-none');
  }

  function subjectEmoji(subject) {
    const map = {
      mathe: '➗',
      mathematik: '➗',
      deutsch: '📖',
      englisch: '🇬🇧',
      geographie: '🗺️',
      erdkunde: '🗺️',
      biologie: '🌱',
      bio: '🌱',
      physik: '🧲',
      chemie: '⚗️'
    };

    return map[String(subject || '').toLowerCase()] || '🎯';
  }

  function subjectColor(subject, index) {
    const map = {
      mathe: ['#6c5ce7', '#a29bfe'],
      mathematik: ['#6c5ce7', '#a29bfe'],
      deutsch: ['#fdcb6e', '#ffeaa7'],
      englisch: ['#0984e3', '#74b9ff'],
      geographie: ['#00b894', '#55efc4'],
      erdkunde: ['#00b894', '#55efc4'],
      biologie: ['#00cec9', '#81ecec'],
      bio: ['#00cec9', '#81ecec'],
      physik: ['#2d3436', '#74b9ff'],
      chemie: ['#e17055', '#fab1a0']
    };

    const colors = map[String(subject || '').toLowerCase()] || ['#5a4ff3', '#00cec9'];
    return colors[index] || colors[0];
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    }[char]));
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#096;');
  }
})();
