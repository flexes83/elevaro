(function () {
  const $ = (...ids) => ids.map(id => document.getElementById(id)).find(Boolean) || null;

  const els = {
    heroText: $('heroText'),
    heroIcon: $('heroIcon'),
    state: $('stateLabel', 'summaryState'),
    schoolType: $('schoolTypeLabel', 'summarySchoolType'),
    grade: $('gradeLabel', 'summaryGrade'),
    subject: $('subjectLabel', 'summarySubject'),
    topicKicker: $('topicKicker'),
    sectionIntro: $('sectionIntro'),
    loading: $('loadingState', 'recommendationsLoading'),
    grid: $('recommendationsGrid', 'recommendationsList'),
    empty: $('emptyState', 'recommendationsEmpty')
  };

  const profile = readProfile();

  if (!hasCompleteProfile(profile)) {
    localStorage.removeItem('elevaro_profile');
    window.location.href = 'onboarding.php?reset=1';
    return;
  }
  if (!profile || !profile.values || !profile.values.grade) {
    window.location.href = 'onboarding.php';
    return;
  }

  renderProfile(profile);
  loadRecommendations(profile);

  function readProfile() {
    try { return JSON.parse(localStorage.getItem('elevaro_profile') || 'null'); }
    catch (e) { return null; }
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


  function renderProfile(profile) {
    const labels = profile.labels || {};
    const values = profile.values || {};
    if (els.state) els.state.textContent = labels.state || values.state || '–';
    if (els.schoolType) els.schoolType.textContent = labels.school_type || values.school_type || '–';
    if (els.grade) {
      const isVocational = String(values.school_type || '').startsWith('beruf') || String(labels.school_type || '').toLowerCase().startsWith('beruf');
      els.grade.textContent = labels.grade || (values.grade ? (isVocational ? values.grade : `${values.grade}. Klasse`) : '–');
      const gradeCaption = document.querySelector('.profile-strip div:nth-child(3) span');
      if (gradeCaption) gradeCaption.textContent = isVocational ? 'Stufe' : 'Klasse';
    }
    if (els.subject) els.subject.textContent = labels.subject || values.subject || '–';

    const name = localStorage.getItem('elevaro_profile_name') || labels.name || values.name;
    if (name && els.heroText) {
      els.heroText.textContent = `${name}, wir haben deine Auswahl gespeichert und schlagen dir passende Quizze vor.`;
    }

    const focus = labels.focus || labels.topic || values.topic;
    if (focus && els.topicKicker) {
      els.topicKicker.classList.remove('d-none');
      els.topicKicker.textContent = `Lernbereich: ${focus}`;
    }
    if (focus && els.sectionIntro) {
      els.sectionIntro.textContent = 'Wir zeigen dir Quizze, die zu deinem Lernbereich passen. Du kannst später weiter einschränken.';
    }

    if (els.heroIcon) els.heroIcon.textContent = subjectEmoji(values.subject || labels.subject);
  }

  function loadRecommendations(profile) {
    const values = profile.values || {};
    const params = new URLSearchParams();
    ['state', 'school_type', 'grade', 'subject'].forEach(key => {
      if (values[key]) params.set(key, values[key]);
    });

    if (Array.isArray(values.focus_tags) && values.focus_tags.length) {
      params.set('tags', values.focus_tags.join(','));
    } else if (values.topic) {
      params.set('topic', values.topic);
    }

    fetch(`api/recommendations.php?${params.toString()}`)
      .then(response => response.json())
      .then(data => {
        if (!data.success) throw new Error(data.message || 'API error');
        renderRecommendations(Array.isArray(data.items) ? data.items : []);
      })
      .catch(error => {
        console.error(error);
        showEmpty();
      });
  }

  function renderRecommendations(items) {
    const usableItems = items.filter(item => item.quiz_key);
    if (els.loading) els.loading.classList.add('d-none');
    if (!els.grid) return;
    els.grid.innerHTML = '';

    if (!usableItems.length) {
      showEmpty();
      return;
    }

    els.grid.classList.remove('d-none');
    if (els.empty) els.empty.classList.add('d-none');

    usableItems.forEach(item => els.grid.appendChild(createQuizCard(item)));
  }

  function createQuizCard(item) {
    if (window.ElevaroQuizCard && typeof window.ElevaroQuizCard.create === 'function') {
      return window.ElevaroQuizCard.create(item);
    }

    const card = document.createElement('article');
    card.className = 'elevaro-quiz-card';
    card.textContent = item.quiz_title || item.title || 'Quiz';
    return card;
  }

  function showEmpty() {
    if (els.loading) els.loading.classList.add('d-none');
    if (els.grid) els.grid.classList.add('d-none');
    if (els.empty) els.empty.classList.remove('d-none');
  }

  function quizLink(quizKey) { return 'quiz.php?key=' + encodeURIComponent(quizKey); }

  function subjectEmoji(subject) {
    const map = { mathe:'➗', mathematik:'➗', deutsch:'📖', englisch:'🇬🇧', geographie:'🗺️', erdkunde:'🗺️', biologie:'🌱', bio:'🌱', physik:'🧲', chemie:'⚗️' };
    return map[String(subject || '').toLowerCase()] || '🎯';
  }

  function subjectColor(subject, index) {
    const map = { mathe:['#6c5ce7','#a29bfe'], mathematik:['#6c5ce7','#a29bfe'], deutsch:['#fdcb6e','#ffeaa7'], englisch:['#0984e3','#74b9ff'], geographie:['#00b894','#55efc4'], erdkunde:['#00b894','#55efc4'], biologie:['#00cec9','#81ecec'], bio:['#00cec9','#81ecec'] };
    const colors = map[String(subject || '').toLowerCase()] || ['#5a4ff3', '#00cec9'];
    return colors[index] || colors[0];
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[char]));
  }
  function escapeAttr(value) { return escapeHtml(value).replace(/`/g, '&#096;'); }
})();
