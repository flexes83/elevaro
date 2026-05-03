(function () {
  const subjectEmojis = {
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

  const subjectColors = {
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

  const visibleImageStatuses = new Set(['', 'draft', 'approved', 'generated', 'selected', 'active']);

  function create(item, options = {}) {
    const card = document.createElement('article');
    card.className = 'elevaro-quiz-card';

    const subjectCode = String(item.subject_code || item.subject || '').toLowerCase();
    const colors = subjectColors[subjectCode] || ['#5a4ff3', '#00cec9'];

    const color1 = item.theme_color_1 || colors[0];
    const color2 = item.theme_color_2 || colors[1];
    const emoji = item.theme_emoji || item.subject_icon || subjectEmojis[subjectCode] || '🎯';
    const title = item.quiz_title || item.title || item.topic_title || 'Quiz';
    const description = item.quiz_description || item.description || item.topic_description || 'Starte ein kurzes Quiz mit direktem Feedback.';
    const quizKey = item.quiz_key || item.key || '';
    const quizId = item.quiz_id || item.id || '';
    const imagePath = item.image_path || item.card_image_path || item.coverImage || '';
    const imageStatus = String(item.image_status || '').toLowerCase();
    const hasImage = imagePath && visibleImageStatuses.has(imageStatus);

    const tags = normalizeTags(item);
    const progress = normalizeProgress(item);

    card.style.setProperty('--quiz-card-c1', color1);
    card.style.setProperty('--quiz-card-c2', color2);
    card.style.setProperty('--quiz-donut-green', `${progress.greenDeg}deg`);
    card.style.setProperty('--quiz-donut-red', `${progress.redDeg}deg`);

    const media = hasImage
      ? `<img class="elevaro-quiz-card-img" src="${escapeAttr(imagePath)}" alt="">`
      : `<span class="elevaro-quiz-card-fallback">${escapeHtml(emoji)}</span>`;

    const donutLabel = progress.played ? `${Math.round(progress.greenPercent)}%` : '';

    const adminEdit = (item.can_edit || options.canEdit) && quizId
      ? `<a class="elevaro-quiz-card-admin" href="/admin/quiz_questions.php?quiz_id=${encodeURIComponent(quizId)}">bearbeiten</a>`
      : '';

    const startHref = quizKey ? `/quiz.php?key=${encodeURIComponent(quizKey)}` : '#';
    const badgeText = `${item.subject_name || 'Quiz'} · ${item.tag_names || item.topic_title || 'Lernbereich'}`;

    card.innerHTML = `
      <div class="elevaro-quiz-card-media">
        ${media}
        <span class="elevaro-quiz-card-badge">${escapeHtml(badgeText)}</span>
        <span class="elevaro-quiz-card-donut ${progress.played ? '' : 'is-empty'}" title="${escapeAttr(progress.title)}">
          <span class="elevaro-quiz-card-donut-label">${escapeHtml(donutLabel)}</span>
        </span>
      </div>

      <div class="elevaro-quiz-card-body">
        <h3 class="elevaro-quiz-card-title">${escapeHtml(title)}</h3>
        <p class="elevaro-quiz-card-description">${escapeHtml(description)}</p>

        ${tags.length ? `
          <div class="elevaro-quiz-card-tags">
            ${tags.slice(0, 4).map(tag => `<span class="elevaro-quiz-card-tag">${escapeHtml(tag)}</span>`).join('')}
          </div>
        ` : ''}

        <div class="elevaro-quiz-card-footer">
          <a class="btn btn-primary" href="${escapeAttr(startHref)}">Quiz starten</a>
          <span class="elevaro-quiz-card-meta">${escapeHtml(progress.meta)}</span>
        </div>
      </div>

      ${adminEdit}
    `;

    return card;
  }

  function normalizeProgress(item) {
    const total = number(item.progress_total ?? item.total_questions ?? item.question_count ?? 0);
    const passed = number(item.progress_passed ?? item.correct_questions ?? 0);
    const failed = number(item.progress_failed ?? item.failed_questions ?? 0);
    let unanswered = number(item.progress_unanswered ?? 0);
    const attempted = number(item.progress_attempted ?? item.answered_questions ?? 0);
    const played = attempted > 0 || passed > 0 || failed > 0;

    if (!unanswered && total > 0) {
      unanswered = Math.max(total - passed - failed, 0);
    }

    const denom = Math.max(total, passed + failed + unanswered, 0);

    if (!played || denom <= 0) {
      return {
        played: false,
        greenDeg: 0,
        redDeg: 0,
        greenPercent: 0,
        meta: total ? `${total} Fragen` : 'noch nicht gespielt',
        title: 'Noch nicht gespielt'
      };
    }

    const greenDeg = (passed / denom) * 360;
    const redDeg = (failed / denom) * 360;
    const greenPercent = (passed / denom) * 100;

    return {
      played: true,
      greenDeg,
      redDeg,
      greenPercent,
      meta: `${passed}/${denom} bestanden`,
      title: `${passed} bestanden, ${failed} noch nachzuarbeiten, ${unanswered} unbearbeitet`
    };
  }

  function normalizeTags(item) {
    if (Array.isArray(item.tags)) {
      return item.tags.map(tag => typeof tag === 'string' ? tag : (tag.name || tag.slug || '')).filter(Boolean);
    }

    if (item.tag_names) {
      return String(item.tag_names).split(',').map(tag => tag.trim()).filter(Boolean);
    }

    return [];
  }

  function number(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
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

  window.ElevaroQuizCard = { create };
})();
