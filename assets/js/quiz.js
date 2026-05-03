(function () {
  const originalQuestions = window.ELEVARO_QUIZ.questions || [];
  let questions = [...originalQuestions];
  let index = 0;
  let score = 0;
  let selected = false;
  let weakQuestions = [];
  let questionStartedAt = null;

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

    if (!useWeak) {
      weakQuestions = [];
    }

    introCard.classList.add('d-none');
    resultCard.classList.add('d-none');
    quizCard.classList.remove('d-none');

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

    [...answersEl.children].forEach(btn => {
      btn.disabled = true;

      if (btn.dataset.answer === question.answer) {
        btn.classList.add('correct');
      }
    });

    if (isCorrect) {
      score++;
      feedbackEl.innerHTML = `
        <strong>Richtig!</strong>
        <span>${escapeHtml(question.fact || 'Das sitzt.')}</span>
      `;
      feedbackEl.className = 'feedback-box feedback-good mt-4';
    } else {
      button.classList.add('wrong');
      weakQuestions.push(question);
      feedbackEl.innerHTML = `
        <strong>Fast!</strong>
        <span>Richtig wäre: <b>${escapeHtml(question.answer)}</b></span>
        ${question.fact ? `<small>${escapeHtml(question.fact)}</small>` : ''}
      `;
      feedbackEl.className = 'feedback-box feedback-wrong mt-4';
    }

    recordAnswer(question, answer, isCorrect, responseTimeMs);

    feedbackEl.classList.remove('d-none');
    nextBtn.classList.remove('d-none');
  }

  function finishQuiz() {
    quizCard.classList.add('d-none');
    resultCard.classList.remove('d-none');
    progressBar.style.width = '100%';

    const total = questions.length;
    const percent = Math.round((score / total) * 100);

    statCorrect.textContent = score;
    statTotal.textContent = total;
    statPercent.textContent = percent + '%';

    if (percent >= 90) {
      resultPanda.textContent = '🏆';
      resultHeadline.textContent = name ? `Stark, ${name}!` : 'Stark!';
      resultText.textContent = `Du hast ${score} von ${total} Fragen richtig beantwortet. Das sitzt schon richtig gut.`;
    } else if (percent >= 60) {
      resultPanda.textContent = '🐼';
      resultHeadline.textContent = name ? `Gut gemacht, ${name}.` : 'Gut gemacht.';
      resultText.textContent = `Du hast ${score} von ${total} Fragen richtig beantwortet. Ein paar Fragen kannst du noch festigen.`;
    } else {
      resultPanda.textContent = '💪';
      resultHeadline.textContent = name ? `Dranbleiben, ${name}.` : 'Dranbleiben.';
      resultText.textContent = `Du hast ${score} von ${total} Fragen richtig beantwortet. Übe die Wackelkandidaten und versuch es nochmal.`;
    }

    localStorage.setItem('elevaro_progress_' + window.ELEVARO_QUIZ.id, JSON.stringify({
      score,
      total,
      percent,
      weak_count: weakQuestions.length,
      date: new Date().toISOString()
    }));

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

  function recordAnswer(question, selectedAnswer, isCorrect, responseTimeMs) {
    if (!question.id || !window.ELEVARO_QUIZ.dbId) return;

    fetch('api/answer.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        quiz_id: window.ELEVARO_QUIZ.dbId,
        question_id: question.id,
        selected_answer: selectedAnswer,
        is_correct: isCorrect,
        session_id: sessionId,
        response_time_ms: responseTimeMs
      })
    }).catch(err => {
      console.warn('Answer tracking failed', err);
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
