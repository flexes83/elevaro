(() => {
  const cfg = window.ELEVARO_DUEL || {};
  const questions = cfg.questions || [];
  const duration = 10;
  let index = 0;
  let answered = false;
  let currentStartedAt = 0;
  let timer = null;
  let poller = null;
  let myCorrect = 0;
  let otherCorrect = 0;
  let lastState = null;

  const intro = document.getElementById('duelIntro');
  const game = document.getElementById('duelGame');
  const result = document.getElementById('duelResult');
  const startBtn = document.getElementById('duelStartBtn');
  const questionEl = document.getElementById('duelQuestion');
  const answersEl = document.getElementById('duelAnswers');
  const feedbackEl = document.getElementById('duelFeedback');
  const timerEl = document.getElementById('duelTimer');
  const counterEl = document.getElementById('duelCounter');
  const progressBar = document.getElementById('duelProgressBar');
  const myScoreEl = document.getElementById('myScore');
  const otherScoreEl = document.getElementById('otherScore');
  const rematchBtn = document.getElementById('rematchBtn');

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  const escapeAttr = (value) => escapeHtml(value).replace(/`/g, '&#096;');

  function api(action = 'state', payload = {}) {
    return fetch('/api/classroom_duel.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify({class_id: cfg.classId, duel_id: cfg.duelId, action, ...payload})
    }).then((response) => response.json()).then((data) => {
      if (!data.success) throw new Error(data.error || 'Duell konnte nicht aktualisiert werden.');
      return data;
    });
  }

  function normalizeOption(option) {
    if (typeof option === 'string') return {text: option, media: {type: 'none'}};
    return {text: String(option?.text || option?.label || ''), media: option?.media || {type: 'none'}};
  }

  function start() {
    intro.classList.add('d-none');
    result.classList.add('d-none');
    game.classList.remove('d-none');
    index = 0;
    myCorrect = 0;
    otherCorrect = 0;
    renderQuestion();
    poller = setInterval(pollState, 800);
  }

  function renderQuestion() {
    if (index >= questions.length) {
      finishLocal();
      return;
    }
    answered = false;
    currentStartedAt = Date.now();
    const q = questions[index];
    feedbackEl.className = 'duel-feedback d-none';
    feedbackEl.innerHTML = '';
    counterEl.textContent = `Frage ${index + 1} von ${questions.length}`;
    progressBar.style.width = `${(index / questions.length) * 100}%`;
    questionEl.textContent = q.question || '';
    answersEl.innerHTML = '';
    (q.options || []).forEach((option) => {
      const o = normalizeOption(option);
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'duel-answer-btn';
      btn.dataset.answer = o.text;
      if (o.media?.type === 'image' && o.media?.path) {
        btn.innerHTML = `<span class="duel-answer-image"><img src="${escapeAttr(o.media.path)}" alt="${escapeAttr(o.media.alt || o.text)}"></span><span>${escapeHtml(o.text)}</span>`;
      } else {
        btn.textContent = o.text;
      }
      btn.addEventListener('click', () => submitAnswer(o.text));
      answersEl.appendChild(btn);
    });
    tick();
    clearInterval(timer);
    timer = setInterval(tick, 100);
  }

  function tick() {
    const elapsed = (Date.now() - currentStartedAt) / 1000;
    const left = Math.max(0, duration - elapsed);
    timerEl.textContent = String(Math.ceil(left));
    timerEl.style.setProperty('--time', String(left / duration));
    if (left <= 0 && !answered) {
      submitAnswer('');
    }
  }

  function submitAnswer(answer) {
    if (answered) return;
    answered = true;
    clearInterval(timer);
    const q = questions[index];
    const responseTimeMs = Math.max(0, Date.now() - currentStartedAt);

    [...answersEl.children].forEach((btn) => {
      btn.disabled = true;
      if (btn.dataset.answer === q.answer) btn.classList.add('is-correct');
      if (answer && btn.dataset.answer === answer && answer !== q.answer) btn.classList.add('is-wrong');
    });

    if (!answer) {
      feedbackEl.innerHTML = `<strong>Zeit abgelaufen.</strong><span>Richtig wäre: ${escapeHtml(q.answer)}</span>`;
      feedbackEl.className = 'duel-feedback is-wrong';
    } else if (answer === q.answer) {
      feedbackEl.innerHTML = `<strong>Richtig!</strong><span>${escapeHtml(q.fact || 'Punkt für dich.')}</span>`;
      feedbackEl.className = 'duel-feedback is-good';
      myCorrect++;
      myScoreEl.textContent = String(myCorrect);
    } else {
      feedbackEl.innerHTML = `<strong>Leider falsch.</strong><span>Richtig wäre: ${escapeHtml(q.answer)}</span>`;
      feedbackEl.className = 'duel-feedback is-wrong';
    }

    api('answer', {question_id: q.id, selected_answer: answer, response_time_ms: responseTimeMs})
      .then((data) => {
        lastState = data;
        renderOpponentAnswer(data);
        maybeAdvanceEarly(data);
      })
      .catch(() => {});

    setTimeout(() => {
      index++;
      renderQuestion();
    }, 1800);
  }

  function pollState() {
    api('state').then((data) => {
      lastState = data;
      renderScores(data);
      renderOpponentAnswer(data);
      maybeAdvanceEarly(data);
      if (data.duel?.finished && index >= questions.length) {
        showResult(data);
      }
    }).catch(() => {});
  }

  function renderScores(data) {
    if (!data.duel) return;
    myCorrect = Number(data.duel.my_correct || 0);
    otherCorrect = Number(data.duel.other_correct || 0);
    myScoreEl.textContent = String(myCorrect);
    otherScoreEl.textContent = String(otherCorrect);
  }

  function answersForCurrent(data) {
    const q = questions[index];
    if (!q || !data.answers) return {};
    return data.answers[q.id] || {};
  }

  function renderOpponentAnswer(data) {
    const q = questions[index];
    if (!q || !data.answers) return;
    const answers = answersForCurrent(data);
    const other = answers[cfg.opponentId];
    if (!other) return;

    [...answersEl.children].forEach((btn) => {
      btn.querySelector('.opponent-pick')?.remove();
      if (btn.dataset.answer === other.selected_answer) {
        const badge = document.createElement('span');
        badge.className = `opponent-pick ${other.is_correct ? 'is-good' : 'is-bad'}`;
        badge.innerHTML = avatarMarkup(cfg.opponentAvatar);
        btn.appendChild(badge);
      }
    });
  }

  function maybeAdvanceEarly(data) {
    if (!answered) return;
    const answers = answersForCurrent(data);
    if (answers[cfg.meId] && answers[cfg.opponentId]) {
      // Beide haben geantwortet. Das feste setTimeout aus submitAnswer übernimmt das Weiterblättern.
      renderOpponentAnswer(data);
    }
  }

  function avatarMarkup(avatar) {
    const a = avatar || {type:'emoji', value:'🙂', gradient:'grad-1'};
    return `<span class="avatar-bubble ${escapeHtml(a.type || 'emoji')} ${escapeHtml(a.gradient || 'grad-1')}">${escapeHtml(a.value || '🙂')}</span>`;
  }

  function finishLocal() {
    clearInterval(timer);
    progressBar.style.width = '100%';
    game.classList.add('d-none');
    result.classList.remove('d-none');
    if (lastState) showResult(lastState);
    else pollState();
  }

  function showResult(data) {
    renderScores(data);
    const outcome = data.duel?.outcome || 'waiting';
    const icon = document.getElementById('duelResultIcon');
    const headline = document.getElementById('duelResultHeadline');
    const text = document.getElementById('duelResultText');
    document.getElementById('finalMyScore').textContent = String(Number(data.duel?.my_correct || 0));
    document.getElementById('finalOtherScore').textContent = String(Number(data.duel?.other_correct || 0));

    if (outcome === 'won') {
      icon.textContent = '🏆';
      headline.textContent = 'Du hast gewonnen!';
      text.textContent = 'Stark gespielt. Revanche?';
      confetti();
    } else if (outcome === 'lost') {
      icon.textContent = '💥';
      headline.textContent = 'Du hast verloren';
      text.textContent = `${cfg.opponentName} war diesmal schneller oder sicherer. Hol dir die Revanche!`;
    } else if (outcome === 'draw') {
      icon.textContent = '🤝';
      headline.textContent = 'Unentschieden';
      text.textContent = 'Ihr wart gleich stark. Eine Revanche entscheidet es.';
    } else {
      icon.textContent = '⏳';
      headline.textContent = 'Warte auf das Ergebnis';
      text.textContent = `${cfg.opponentName} ist noch im Duell.`;
      setTimeout(pollState, 1200);
    }
  }

  function confetti() {
    ['🎉','✨','🏆','⚡','🚀'].forEach((emoji, i) => {
      for (let j = 0; j < 4; j++) {
        const el = document.createElement('div');
        el.className = 'duel-confetti';
        el.textContent = emoji;
        el.style.left = `${15 + Math.random() * 70}vw`;
        el.style.top = `${20 + Math.random() * 35}vh`;
        el.style.animationDelay = `${(i + j) * 0.035}s`;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 1400);
      }
    });
  }

  startBtn?.addEventListener('click', start);
  rematchBtn?.addEventListener('click', () => {
    rematchBtn.disabled = true;
    rematchBtn.textContent = 'Revanche wird angefragt …';
    api('rematch').then((data) => { window.location.href = data.redirect || `/classroom.php?class_id=${cfg.classId}`; })
      .catch(() => { rematchBtn.disabled = false; rematchBtn.textContent = 'Revanche fordern'; });
  });
})();
