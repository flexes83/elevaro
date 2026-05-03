(function () {
  const originalQuestions = window.ELEVARO_QUIZ.questions || [];
  let questions = [...originalQuestions];
  let index = 0;
  let score = 0;
  let selected = false;
  let weakQuestions = [];
  let questionStartedAt = null;
  let quizSessionId = null;
  let currentStreak = 0;
  let bestStreak = 0;
  let totalPoints = 0;

  const name = localStorage.getItem('elevaro_profile_name');
  const sessionId = getOrCreateSessionId();

  const introCard = document.getElementById('introCard');
  const quizCard = document.getElementById('quizCard');
  const resultCard = document.getElementById('resultCard');

  const startBtn = document.getElementById('startBtn');
  const nextBtn = document.getElementById('nextBtn');
  const restartBtn = document.getElementById('restartBtn');
  const weakBtn = document.getElementById('weakBtn');

  const questionEl = document.getElementById('question');
  const answersEl = document.getElementById('answers');
  const feedbackEl = document.getElementById('feedback');
  const counterEl = document.getElementById('counter');
  const progressBar = document.getElementById('progressBar');

  const resultHeadline = document.getElementById('resultHeadline');
  const resultText = document.getElementById('resultText');
  const statCorrect = document.getElementById('statCorrect');
  const statTotal = document.getElementById('statTotal');
  const statPercent = document.getElementById('statPercent');
  const weakBox = document.getElementById('weakBox');
  const weakList = document.getElementById('weakList');
  const resultPanda = document.getElementById('resultPanda');

  function startQuiz(useWeak = false) {
    questions = useWeak ? [...weakQuestions] : [...originalQuestions];
    index = 0;
    score = 0;
    selected = false;
    currentStreak = 0;
    bestStreak = 0;
    totalPoints = 0;

    if (!useWeak) {
      weakQuestions = [];
    }

    startQuizSession();

    introCard.classList.add('d-none');
    resultCard.classList.add('d-none');
    quizCard.classList.remove('d-none');
    quizCard.classList.add('quiz-pop-in');

    renderQuestion();
  }


  function normalizeQuestion(question) {
    const q = question || {};
    return {
      ...q,
      question: getValueText(q.question || q.question_text || q.title || ''),
      answer: getValueText(q.answer || q.correct_answer || ''),
      fact: getValueText(q.fact || q.explanation || ''),
      media: normalizeMedia(q.media),
      options: Array.isArray(q.options) ? q.options : []
    };
  }

  function normalizeOption(option) {
    if (typeof option === 'string' || typeof option === 'number') {
      return {
        text: String(option),
        media: { type: 'none' }
      };
    }

    const obj = option && typeof option === 'object' ? option : {};

    return {
      text: getValueText(
        obj.text ??
        obj.label ??
        obj.option_text ??
        obj.answer ??
        obj.title ??
        obj.name ??
        ''
      ),
      media: normalizeMedia(obj.media || {
        type: obj.media_type || 'none',
        path: obj.media_path || obj.image || obj.path || null,
        alt: obj.media_alt || obj.alt || obj.label || obj.text || null,
        credit: obj.media_credit || obj.credit || null,
        source: obj.media_source || obj.source || null
      })
    };
  }

  function normalizeMedia(media) {
    if (!media || typeof media !== 'object') {
      return { type: 'none' };
    }

    return {
      type: media.type || media.media_type || 'none',
      path: media.path || media.media_path || media.image || null,
      alt: media.alt || media.media_alt || '',
      credit: media.credit || media.media_credit || '',
      source: media.source || media.media_source || ''
    };
  }

  function getValueText(value) {
    if (typeof value === 'string' || typeof value === 'number') {
      return String(value);
    }

    if (value && typeof value === 'object') {
      return String(
        value.text ??
        value.label ??
        value.option_text ??
        value.answer ??
        value.title ??
        value.name ??
        ''
      );
    }

    return '';
  }

  function renderQuestion() {
    selected = false;
    questionStartedAt = Date.now();
    quizCard.classList.remove('quiz-question-enter');
    void quizCard.offsetWidth;
    quizCard.classList.add('quiz-question-enter');

    const q = normalizeQuestion(questions[index]);

    questionEl.innerHTML = '';
    const title = document.createElement('span');
    title.textContent = q.question;
    questionEl.appendChild(title);

    renderQuestionMedia(q);

    answersEl.innerHTML = '';
    feedbackEl.classList.add('d-none');
    feedbackEl.innerHTML = '';
    nextBtn.classList.add('d-none');

    counterEl.textContent = `Frage ${index + 1} von ${questions.length}`;
    progressBar.style.width = `${(index / questions.length) * 100}%`;

    q.options.forEach(option => {
      const normalized = normalizeOption(option);

      const btn = document.createElement('button');
      btn.className = hasImageMedia(normalized.media) ? 'btn answer-btn image-answer-btn' : 'btn btn-outline-primary answer-btn';
      btn.type = 'button';
      btn.dataset.answer = normalized.text;

      if (hasImageMedia(normalized.media)) {
        btn.innerHTML = `
          <span class="option-image-wrap">
            <img src="${escapeAttribute(normalized.media.path)}" alt="${escapeAttribute(normalized.media.alt || normalized.text)}">
          </span>
          <span class="option-label">${escapeHtml(normalized.text)}</span>
        `;
      } else {
        btn.textContent = normalized.text;
      }

      btn.addEventListener('click', () => selectAnswer(btn, normalized.text, q));
      answersEl.appendChild(btn);
    });
  }

  function renderQuestionMedia(question) {
    const existing = document.getElementById('questionMedia');
    if (existing) existing.remove();

    if (!hasImageMedia(question.media)) return;

    const media = document.createElement('div');
    media.id = 'questionMedia';
    media.className = 'question-media';

    media.innerHTML = `
      <img src="${escapeAttribute(question.media.path)}" alt="${escapeAttribute(question.media.alt || question.question)}">
      ${question.media.credit ? `<small>${escapeHtml(question.media.credit)}</small>` : ''}
    `;

    questionEl.insertAdjacentElement('afterend', media);
  }

  function selectAnswer(button, answer, question) {
    if (selected) return;
    selected = true;

    const isCorrect = answer === question.answer;
    const responseTimeMs = questionStartedAt ? Date.now() - questionStartedAt : null;
    const speedBonus = isCorrect && responseTimeMs && responseTimeMs < 5000 ? 5 : 0;
    const streakBonus = isCorrect && currentStreak >= 2 ? 5 : 0;
    const points = isCorrect ? 10 + speedBonus + streakBonus : 0;

    [...answersEl.children].forEach(btn => {
      btn.disabled = true;

      if (btn.dataset.answer === question.answer) {
        btn.classList.add('correct');
      }
    });

    if (isCorrect) {
      score++;
      currentStreak++;
      bestStreak = Math.max(bestStreak, currentStreak);
      totalPoints += points;

      button.classList.add('answer-bounce');
      launchPoints(button, points);
      showComboEmoji(button, currentStreak);

      feedbackEl.innerHTML = `
        <strong>${currentStreak >= 3 ? 'Serie läuft!' : 'Richtig!'}</strong>
        <span>${escapeHtml(question.fact || 'Das sitzt.')}</span>
        <small class="quiz-reward-line">+${points} Punkte${speedBonus ? ' · Blitzbonus ⚡' : ''}${streakBonus ? ' · Serienbonus 🔥' : ''}</small>
      `;
      feedbackEl.className = 'feedback-box feedback-good mt-4';
    } else {
      currentStreak = 0;
      button.classList.add('wrong');
      weakQuestions.push(question);
      shakeElement(button);
      feedbackEl.innerHTML = `
        <strong>Fast!</strong>
        <span>Richtig wäre: <b>${escapeHtml(question.answer)}</b></span>
        ${question.fact ? `<small>${escapeHtml(question.fact)}</small>` : ''}
        <small class="quiz-reward-line">Diese Frage zählt erst wieder als bestanden, wenn du sie 2× richtig beantwortest.</small>
      `;
      feedbackEl.className = 'feedback-box feedback-wrong mt-4';
    }

    updateMiniHud();
    recordAnswer(question, answer, isCorrect, responseTimeMs, points);

    feedbackEl.classList.remove('d-none');
    nextBtn.classList.remove('d-none');
  }

  function finishQuiz() {
    quizCard.classList.add('d-none');
    resultCard.classList.remove('d-none');
    resultCard.classList.add('quiz-pop-in');
    progressBar.style.width = '100%';

    const total = questions.length;
    const percent = Math.round((score / total) * 100);

    statCorrect.textContent = score;
    statTotal.textContent = total;
    statPercent.textContent = percent + '%';

    if (percent >= 90) {
      resultPanda.textContent = '🏆';
      resultHeadline.textContent = name ? `Stark, ${name}!` : 'Stark!';
      resultText.innerHTML = `Du hast ${score} von ${total} Fragen richtig beantwortet.<br><b>${totalPoints} Punkte</b> · beste Serie: <b>${bestStreak}</b> 🔥`;
      celebrateResult('confetti');
    } else if (percent >= 60) {
      resultPanda.textContent = '🌟';
      resultHeadline.textContent = name ? `Gut gemacht, ${name}.` : 'Gut gemacht.';
      resultText.innerHTML = `Du hast ${score} von ${total} Fragen richtig beantwortet.<br><b>${totalPoints} Punkte</b> · beste Serie: <b>${bestStreak}</b> ✨`;
      celebrateResult('spark');
    } else {
      resultPanda.textContent = '💪';
      resultHeadline.textContent = name ? `Dranbleiben, ${name}.` : 'Dranbleiben.';
      resultText.innerHTML = `Du hast ${score} von ${total} Fragen richtig beantwortet.<br><b>${totalPoints} Punkte</b>. Übe die Wackelkandidaten und versuch es nochmal.`;
    }

    localStorage.setItem('elevaro_progress_' + window.ELEVARO_QUIZ.id, JSON.stringify({
      score,
      total,
      percent,
      points: totalPoints,
      best_streak: bestStreak,
      weak_count: weakQuestions.length,
      date: new Date().toISOString()
    }));

    completeQuizSession();
    renderWeakQuestions();
  }

  function renderWeakQuestions() {
    weakList.innerHTML = '';

    if (!weakQuestions.length) {
      weakBox.classList.add('d-none');
      weakBtn.classList.add('d-none');
      return;
    }

    weakQuestions.forEach(q => {
      const li = document.createElement('li');
      li.textContent = q.question;
      weakList.appendChild(li);
    });

    weakBox.classList.remove('d-none');
    weakBtn.classList.remove('d-none');
  }

  function recordAnswer(question, selectedAnswer, isCorrect, responseTimeMs, points = 0) {
    if (!question.id || !window.ELEVARO_QUIZ.dbId) return;

    fetch('api/answer.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        quiz_id: window.ELEVARO_QUIZ.dbId,
        question_id: question.id,
        selected_answer: selectedAnswer,
        correct_answer: question.answer,
        is_correct: isCorrect,
        session_id: quizSessionId || sessionId,
        response_time_ms: responseTimeMs,
        points
      })
    })
      .then(response => response.json().catch(() => null))
      .then(data => {
        if (data && data.success && data.progress) {
          window.ELEVARO_LAST_PROGRESS = data.progress;
        }
      })
      .catch(err => {
        console.warn('Answer tracking failed', err);
      });
  }

  function startQuizSession() {
    if (!window.ELEVARO_QUIZ.dbId) return;

    fetch('api/quiz_session.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        quiz_id: window.ELEVARO_QUIZ.dbId,
        session_token: sessionId
      })
    })
      .then(response => {
        if (response.status === 401) return null;
        return response.json();
      })
      .then(data => {
        if (data && data.success && data.quiz_session_id) {
          quizSessionId = data.quiz_session_id;
        }
      })
      .catch(() => {});
  }

  function completeQuizSession() {
    if (!quizSessionId) return;

    fetch('api/quiz_complete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        quiz_session_id: quizSessionId
      })
    }).catch(() => {});
  }

  function updateMiniHud() {
    let hud = document.getElementById('quizMiniHud');

    if (!hud) {
      hud = document.createElement('div');
      hud.id = 'quizMiniHud';
      hud.className = 'quiz-mini-hud';
      quizCard.prepend(hud);
    }

    hud.innerHTML = `
      <span>⭐ ${totalPoints} Punkte</span>
      <span>🔥 ${currentStreak}er Serie</span>
      <span>🏅 Beste: ${bestStreak}</span>
    `;
  }

  function launchPoints(anchor, points) {
    const rect = anchor.getBoundingClientRect();
    const bubble = document.createElement('div');
    bubble.className = 'quiz-points-bubble';
    bubble.textContent = `+${points}`;
    bubble.style.left = `${rect.left + rect.width * 0.72}px`;
    bubble.style.top = `${rect.top + rect.height * 0.5}px`;
    document.body.appendChild(bubble);

    window.setTimeout(() => bubble.remove(), 1000);
  }

  function showComboEmoji(anchor, streak) {
    if (streak < 2) return;

    const emojis = streak >= 5 ? ['🔥', '🚀', '🏆'] : ['✨', '🔥'];
    const rect = anchor.getBoundingClientRect();

    emojis.forEach((emoji, i) => {
      const el = document.createElement('div');
      el.className = 'quiz-combo-emoji';
      el.textContent = emoji;
      el.style.left = `${rect.right - 30 + i * 22}px`;
      el.style.top = `${rect.top - 10 - i * 10}px`;
      document.body.appendChild(el);
      window.setTimeout(() => el.remove(), 1100);
    });
  }

  function shakeElement(el) {
    el.classList.remove('quiz-shake');
    void el.offsetWidth;
    el.classList.add('quiz-shake');
  }

  function celebrateResult(type) {
    const emojis = type === 'confetti' ? ['🎉', '✨', '🏆', '🚀', '🔥'] : ['✨', '🌟', '👏'];

    for (let i = 0; i < 18; i++) {
      const el = document.createElement('div');
      el.className = 'quiz-result-confetti';
      el.textContent = emojis[i % emojis.length];
      el.style.left = `${15 + Math.random() * 70}vw`;
      el.style.top = `${25 + Math.random() * 30}vh`;
      el.style.animationDelay = `${Math.random() * .18}s`;
      document.body.appendChild(el);
      window.setTimeout(() => el.remove(), 1300);
    }
  }

  function hasImageMedia(media) {
    return media && media.type === 'image' && media.path;
  }

  function getOrCreateSessionId() {
    let id = localStorage.getItem('elevaro_session_id');

    if (!id) {
      id = 'anon_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
      localStorage.setItem('elevaro_session_id', id);
    }

    return id;
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

  function escapeAttribute(str) {
    return escapeHtml(str).replace(/`/g, '&#096;');
  }

  startBtn.addEventListener('click', () => startQuiz(false));

  nextBtn.addEventListener('click', () => {
    index++;

    if (index < questions.length) {
      renderQuestion();
    } else {
      finishQuiz();
    }
  });

  restartBtn.addEventListener('click', () => startQuiz(false));
  weakBtn.addEventListener('click', () => startQuiz(true));
})();
