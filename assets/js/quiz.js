(function () {
  const originalQuestions = window.ELEVARO_QUIZ.questions || [];
  const userIsPremium = !!window.ELEVARO_QUIZ.userIsPremium;
  let questions = [...originalQuestions];
  let index = 0;
  let score = 0;
  let selected = false;
  let weakQuestions = [];
  let questionStartedAt = null;
  let quizSessionId = null;
  let classroomSessionId = window.ELEVARO_QUIZ.classroomSessionId || null;
  let currentStreak = 0;
  let bestStreak = 0;
  let totalPoints = 0;
  let currentQuestion = null;
  let lastSelectedAnswer = null;

  const name = localStorage.getItem('elevaro_profile_name');
  const sessionId = getOrCreateSessionId();

  const introCard = document.getElementById('introCard');
  const introExtras = document.getElementById('introExtras');
  const quizPlayMedia = document.getElementById('quizPlayMedia');
  const quizCard = document.getElementById('quizCard');
  const resultCard = document.getElementById('resultCard');

  const startBtn = document.getElementById('startBtn');
  const introAudio = document.getElementById('introAudio');
  const listeningComprehensionAudio = document.getElementById('listeningComprehensionAudio');
  const listeningComprehensionBox = document.getElementById('listeningComprehensionBox');
  const listeningIntroBox = document.getElementById('listeningIntroBox');
  const nextBtn = document.getElementById('nextBtn');
  const restartBtn = document.getElementById('restartBtn');
  const weakBtn = document.getElementById('weakBtn');
  const premiumWeakBtn = document.getElementById('premiumWeakBtn');

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


  function redirectToPaywall() {
    window.location.href = '/paywall.php?return=' + encodeURIComponent(window.location.pathname + window.location.search);
  }

  function requirePremiumForRepeat() {
    if (window.ELEVARO_QUIZ.classroomMode || window.ELEVARO_QUIZ.userIsPremium || window.ELEVARO_QUIZ.userCanContinue) {
      return true;
    }
    redirectToPaywall();
    return false;
  }

  function setupIntroAudioGate() {
    if (!window.ELEVARO_QUIZ.requiresIntroAudio || !introAudio || !startBtn) {
      return;
    }

    startBtn.disabled = true;
    startBtn.classList.add('is-audio-locked');

    const unlock = () => {
      startBtn.disabled = false;
      startBtn.textContent = 'Quiz starten';
      startBtn.classList.remove('is-audio-locked');
      if (listeningIntroBox) {
        listeningIntroBox.classList.add('is-complete');
      }
    };

    introAudio.addEventListener('ended', unlock);
    introAudio.addEventListener('play', () => {
      if (listeningIntroBox) {
        listeningIntroBox.classList.add('is-playing');
      }
    });
    introAudio.addEventListener('pause', () => {
      if (listeningIntroBox) {
        listeningIntroBox.classList.remove('is-playing');
      }
    });
  }


  function setupListeningComprehensionGate() {
    if (!window.ELEVARO_QUIZ.listeningMode || !listeningComprehensionAudio || !startBtn) {
      return;
    }

    startBtn.disabled = true;
    startBtn.textContent = 'Hörtext zuerst anhören';
    startBtn.classList.add('is-audio-locked');

    const unlock = () => {
      startBtn.disabled = false;
      startBtn.textContent = 'Quiz starten';
      startBtn.classList.remove('is-audio-locked');
      if (listeningComprehensionBox) {
        listeningComprehensionBox.classList.add('is-complete');
      }
    };

    listeningComprehensionAudio.addEventListener('ended', unlock);
    listeningComprehensionAudio.addEventListener('play', () => {
      if (listeningComprehensionBox) listeningComprehensionBox.classList.add('is-playing');
    });
    listeningComprehensionAudio.addEventListener('pause', () => {
      if (listeningComprehensionBox) listeningComprehensionBox.classList.remove('is-playing');
    });
  }

  function startQuiz(useWeak = false) {
    questions = (useWeak && userIsPremium) ? [...weakQuestions] : [...originalQuestions];
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

    document.body.classList.add('quiz-is-playing');
    introCard.classList.add('d-none');
    if (introExtras) introExtras.classList.add('d-none');
    resultCard.classList.add('d-none');
    quizCard.classList.remove('d-none');
    quizCard.classList.add('quiz-pop-in');

    if (!questions.length) {
      alert('Für diese Runde sind keine Fragen verfügbar.');
      return;
    }

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
      audio: normalizeAudio(q.audio),
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

  function normalizeAudio(audio) {
    if (!audio || typeof audio !== 'object') {
      return { path: null, text: null, status: 'none' };
    }

    return {
      path: audio.path || audio.audio_path || null,
      text: audio.text || audio.audio_text || null,
      status: audio.status || audio.audio_status || 'none'
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
    renderQuestionAudio(q);

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

  function renderQuestionAudio(question) {
    const existing = document.getElementById('questionListeningBox');
    if (existing) existing.remove();

    if (question.type !== 'listening_mc' || !question.audio || !question.audio.path) {
      return;
    }

    const box = document.createElement('div');
    box.id = 'questionListeningBox';
    box.className = 'question-listening-box';
    box.innerHTML = `
      <div class="question-listening-icon">🔊</div>
      <div>
        <strong>Hör genau hin</strong>
        <p>Spiele den Hörtext ab und wähle dann die passende Antwort.</p>
        <audio controls preload="metadata" src="${escapeAttribute(question.audio.path)}"></audio>
      </div>
    `;

    questionEl.insertAdjacentElement('afterend', box);
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

    lastSelectedAnswer = answer;
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
    document.body.classList.remove('quiz-is-playing');
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
      resultText.innerHTML = `Du hast ${score} von ${total} Fragen richtig beantwortet.<br><b>${totalPoints} Punkte</b> · beste Serie: <b>${bestStreak}</b> 🔥<br><span class="result-microcopy">Speichere deinen Fortschritt und bleib dran.</span>`;
      celebrateResult('confetti');
    } else if (percent >= 60) {
      resultPanda.textContent = '🌟';
      resultHeadline.textContent = name ? `Gut gemacht, ${name}.` : 'Gut gemacht.';
      resultText.innerHTML = `Du hast ${score} von ${total} Fragen richtig beantwortet.<br><b>${totalPoints} Punkte</b> · beste Serie: <b>${bestStreak}</b> ✨<br><span class="result-microcopy">Mit gezieltem Fehlertraining holst du schnell noch mehr raus.</span>`;
      celebrateResult('spark');
    } else {
      resultPanda.textContent = '💪';
      resultHeadline.textContent = name ? `Dranbleiben, ${name}.` : 'Dranbleiben.';
      resultText.innerHTML = `Du hast ${score} von ${total} Fragen richtig beantwortet.<br><b>${totalPoints} Punkte</b>.<br><span class="result-microcopy">Die Wackelkandidaten sind dein schnellster Weg zu besseren Noten.</span>`;
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

    if (!userIsPremium) {
      weakBox.classList.add('d-none');
      weakBtn.classList.add('d-none');
      return;
    }

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
        session_id: quizSessionId || null,
        quiz_session_id: quizSessionId || null,
        session_token: sessionId,
        response_time_ms: responseTimeMs,
        points,
        class_id: window.ELEVARO_QUIZ.classroomMode ? window.ELEVARO_QUIZ.classroomId : 0,
        classroom_session_id: classroomSessionId || null,
        duel_id: window.ELEVARO_QUIZ.classroomDuelId || 0,
        question_count: questions.length
      })
    })
      .then(response => response.json().catch(() => null))
      .then(data => {
        if (data && data.classroom_session_id) {
          classroomSessionId = data.classroom_session_id;
          window.ELEVARO_QUIZ.classroomSessionId = data.classroom_session_id;
        }
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
        session_token: sessionId,
        question_count: questions.length,
        class_id: window.ELEVARO_QUIZ.classroomMode ? window.ELEVARO_QUIZ.classroomId : 0,
        duel_id: window.ELEVARO_QUIZ.classroomDuelId || 0
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
        if (data && data.success && data.classroom_session_id) {
          classroomSessionId = data.classroom_session_id;
          window.ELEVARO_QUIZ.classroomSessionId = data.classroom_session_id;
        }
      })
      .catch(() => {});
  }

  function completeQuizSession() {
    if (!quizSessionId && !classroomSessionId && !window.ELEVARO_QUIZ.classroomMode) return;

    fetch('api/quiz_complete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        quiz_id: window.ELEVARO_QUIZ.dbId,
        quiz_session_id: quizSessionId || null,
        classroom_session_id: classroomSessionId || null,
        class_id: window.ELEVARO_QUIZ.classroomMode ? window.ELEVARO_QUIZ.classroomId : 0,
        duel_id: window.ELEVARO_QUIZ.classroomDuelId || 0,
        session_token: sessionId,
        question_count: questions.length,
        score,
        total: questions.length,
        points: totalPoints,
        best_streak: bestStreak
      })
    })
      .then(response => response.json().catch(() => null))
      .then(data => {
        if (data && data.classroom_session_id) {
          classroomSessionId = data.classroom_session_id;
          window.ELEVARO_QUIZ.classroomSessionId = data.classroom_session_id;
        }
        if (data && data.success && data.duel_result) {
          renderDuelResult(data.duel_result);
        }
      })
      .catch(() => {});
  }

  function renderDuelResult(result) {
    const box = document.getElementById('duelResultBox');
    if (!box) return;

    let headline = 'Duell beendet';
    let icon = '⚔️';
    let text = 'Dein Ergebnis wurde gespeichert.';

    if (result.outcome === 'waiting') {
      headline = 'Du hast vorgelegt';
      icon = '⏳';
      text = `${escapeHtml(result.other_name || 'Dein Gegner')} ist noch dran.`;
    } else if (result.outcome === 'won') {
      headline = 'Du hast gewonnen!';
      icon = '🏆';
      text = `Stark! Du liegst vor ${escapeHtml(result.other_name || 'deinem Gegner')}.`;
      celebrateResult('confetti');
    } else if (result.outcome === 'lost') {
      headline = 'Du hast verloren';
      icon = '💪';
      text = `${escapeHtml(result.other_name || 'Dein Gegner')} war diesmal vorne. Nächste Runde!`;
    } else if (result.outcome === 'draw') {
      headline = 'Unentschieden';
      icon = '🤝';
      text = 'Ihr wart gleich stark.';
    }

    box.innerHTML = `
      <div class="duel-result-icon">${icon}</div>
      <div>
        <strong>${headline}</strong>
        <p>${text}</p>
        <div class="duel-scoreline">
          <span>Du: <b>${Number(result.own_points || 0)}</b> Punkte · ${Number(result.own_correct || 0)} richtig</span>
          <span>${escapeHtml(result.other_name || 'Gegner')}: <b>${result.other_points === null || result.other_points === undefined ? '…' : Number(result.other_points)}</b> Punkte${result.other_correct === null || result.other_correct === undefined ? '' : ' · ' + Number(result.other_correct) + ' richtig'}</span>
        </div>
      </div>
    `;
    box.classList.remove('d-none');
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


  function renderReportLink(question) {
    const old = document.getElementById('questionReportWrap');
    if (old) old.remove();

    if (!question || !question.id) return;

    const wrap = document.createElement('div');
    wrap.id = 'questionReportWrap';
    wrap.className = 'question-report-wrap';
    wrap.innerHTML = `<button type="button" class="question-report-link" id="questionReportBtn">Fehler melden</button>`;
    answersEl.insertAdjacentElement('afterend', wrap);

    const btn = document.getElementById('questionReportBtn');
    btn.addEventListener('click', () => openReportModal(question));
  }

  function openReportModal(question) {
    const existing = document.getElementById('reportModalBackdrop');
    if (existing) existing.remove();

    const backdrop = document.createElement('div');
    backdrop.id = 'reportModalBackdrop';
    backdrop.className = 'report-modal-backdrop';
    backdrop.innerHTML = `
      <div class="report-modal" role="dialog" aria-modal="true">
        <button type="button" class="report-modal-close" id="reportCloseBtn" aria-label="Schließen">×</button>
        <h3>Fehler melden</h3>
        <p>Unsere Quizze werden mithilfe von KI erstellt und danach nochmals geprüft. Sollte hier trotzdem etwas nicht stimmen, kannst du die Frage melden. Wir prüfen sie und blenden sie bei Bedarf bis zur Klärung aus.</p>
        <label>Was ist dir aufgefallen?</label>
        <select id="reportReason">
          <option value="wrong_answer">Antwort ist falsch</option>
          <option value="bad_explanation">Erklärung stimmt nicht</option>
          <option value="typo">Tippfehler / Formulierung</option>
          <option value="unclear">Frage ist unklar</option>
          <option value="technical">Technisches Problem</option>
          <option value="other">Sonstiges</option>
        </select>
        <label>Hinweis optional</label>
        <textarea id="reportMessage" rows="4" placeholder="Was genau stimmt deiner Meinung nach nicht?"></textarea>
        <div class="report-modal-actions">
          <button type="button" class="btn btn-light" id="reportCancelBtn">Abbrechen</button>
          <button type="button" class="btn btn-primary" id="reportSubmitBtn">Melden</button>
        </div>
        <div id="reportStatus" class="report-status d-none"></div>
      </div>
    `;
    document.body.appendChild(backdrop);

    document.getElementById('reportCloseBtn').addEventListener('click', () => backdrop.remove());
    document.getElementById('reportCancelBtn').addEventListener('click', () => backdrop.remove());
    backdrop.addEventListener('click', (event) => {
      if (event.target === backdrop) backdrop.remove();
    });

    document.getElementById('reportSubmitBtn').addEventListener('click', () => submitQuestionReport(question));
  }

  function submitQuestionReport(question) {
    const submitBtn = document.getElementById('reportSubmitBtn');
    const statusEl = document.getElementById('reportStatus');
    const reasonEl = document.getElementById('reportReason');
    const messageEl = document.getElementById('reportMessage');

    submitBtn.disabled = true;
    statusEl.className = 'report-status';
    statusEl.textContent = 'Meldung wird gesendet …';

    fetch('api/report_question.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        quiz_id: window.ELEVARO_QUIZ.dbId,
        question_id: question.id,
        quiz_session_id: quizSessionId || null,
        selected_answer: lastSelectedAnswer || null,
        reason: reasonEl.value,
        message: messageEl.value,
        page_url: window.location.href
      })
    })
      .then(response => response.json())
      .then(data => {
        if (!data.success) {
          throw new Error(data.message || 'Meldung konnte nicht gespeichert werden.');
        }

        statusEl.className = 'report-status is-success';
        statusEl.textContent = data.message || 'Danke, die Frage wurde gemeldet.';
        const reportBtn = document.getElementById('questionReportBtn');
        if (reportBtn) {
          reportBtn.textContent = 'Fehler gemeldet';
          reportBtn.disabled = true;
        }
        window.setTimeout(() => {
          const backdrop = document.getElementById('reportModalBackdrop');
          if (backdrop) backdrop.remove();
        }, 1200);
      })
      .catch(error => {
        submitBtn.disabled = false;
        statusEl.className = 'report-status is-error';
        statusEl.textContent = error.message;
      });
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

  setupIntroAudioGate();
  setupListeningComprehensionGate();

  startBtn.addEventListener('click', () => startQuiz(false));

  nextBtn.addEventListener('click', () => {
    index++;

    if (index < questions.length) {
      renderQuestion();
    } else {
      finishQuiz();
    }
  });

  restartBtn.addEventListener('click', () => {
    if (requirePremiumForRepeat()) startQuiz(false);
  });
  weakBtn.addEventListener('click', () => {
    if (window.ELEVARO_QUIZ.userIsPremium) startQuiz(true);
    else redirectToPaywall();
  });
  if (premiumWeakBtn) {
    premiumWeakBtn.addEventListener('click', () => {
      if (window.ELEVARO_QUIZ.userIsPremium) startQuiz(true);
      else redirectToPaywall();
    });
  }
})();
