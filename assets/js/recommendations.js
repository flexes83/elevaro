const els = {
  summaryState: document.getElementById('summaryState'),
  summarySchoolType: document.getElementById('summarySchoolType'),
  summaryGrade: document.getElementById('summaryGrade'),
  summarySubject: document.getElementById('summarySubject'),
  loading: document.getElementById('recommendationsLoading'),
  empty: document.getElementById('recommendationsEmpty'),
  list: document.getElementById('recommendationsList')
};

function getProfile() {
  try {
    return JSON.parse(localStorage.getItem('elevaro_profile') || 'null');
  } catch (e) {
    return null;
  }
}

function setSummary(profile) {
  const labels = profile?.labels || {};
  const values = profile?.values || {};

  els.summaryState.textContent = labels.state || values.state || '–';
  els.summarySchoolType.textContent = labels.school_type || values.school_type || '–';
  els.summaryGrade.textContent = labels.grade ? labels.grade : (values.grade ? `${values.grade}. Klasse` : '–');
  els.summarySubject.textContent = labels.subject || values.subject || '–';
}

async function loadRecommendations(profile) {
  const values = profile.values || {};
  const params = new URLSearchParams({
    state: values.state || '',
    school_type: values.school_type || '',
    grade: values.grade || ''
  });

  if (values.subject) {
    params.set('subject', values.subject);
  }

  const res = await fetch('api/recommendations.php?' + params.toString());
  const json = await res.json();

  if (!json.success) {
    throw new Error(json.message || 'API error');
  }

  return json.items || [];
}

function renderRecommendations(items) {
  els.loading.classList.add('d-none');
  els.list.innerHTML = '';

  const usableItems = items.filter(item => item.quiz_key);

  if (!usableItems.length) {
    els.empty.classList.remove('d-none');
    return;
  }

  els.empty.classList.add('d-none');

  usableItems.forEach(item => {
    const card = document.createElement('article');
    card.className = 'recommendation-card';

    const quizHref = quizLink(item.quiz_key);

    card.innerHTML = `
      <span class="recommendation-topic">${escapeHtml((item.subject_icon || '') + ' ' + item.subject_name)} · ${escapeHtml(item.topic_title)}</span>
      <h3>${escapeHtml(item.quiz_title || item.topic_title)}</h3>
      <p>${escapeHtml(item.quiz_description || item.topic_description || 'Ein passendes Quiz für deinen Lernweg.')}</p>
      <div class="recommendation-actions">
        <a href="${quizHref}" class="btn btn-primary">Quiz starten</a>
        <a href="onboarding.php" class="btn btn-light">Auswahl ändern</a>
      </div>
    `;

    els.list.appendChild(card);
  });
}
function quizLink(quizKey) {
  return 'quiz.php?key=' + encodeURIComponent(quizKey);
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

async function init() {
  const profile = getProfile();

  if (!profile || !profile.values) {
    window.location.href = 'onboarding.php';
    return;
  }

  setSummary(profile);

  try {
    const items = await loadRecommendations(profile);
    renderRecommendations(items);
  } catch (err) {
    console.error(err);
    els.loading.classList.add('d-none');
    els.empty.classList.remove('d-none');
  }
}

init();
