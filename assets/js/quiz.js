(function () {
  const originalQuestions = window.ELEVARO_QUIZ.questions || [];
  let questions = [...originalQuestions];
  let index = 0;
  let score = 0;
  let selected = false;
  let weakQuestions = [];

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
  const resultText = document.getElementById('resultText');

  function startQuiz(useWeak = false) {
    questions = useWeak ? [...weakQuestions] : [...originalQuestions];
    index = 0;
    score = 0;
    selected = false;
    if (!useWeak) weakQuestions = [];

    introCard.classList.add('d-none');
    resultCard.classList.add('d-none');
    quizCard.classList.remove('d-none');

    renderQuestion();
  }

  function renderQuestion() {
    selected = false;
    const q = questions[index];

    questionEl.textContent = q.question;
    answersEl.innerHTML = '';
    feedbackEl.classList.add('d-none');
    feedbackEl.innerHTML = '';
    nextBtn.classList.add('d-none');

    counterEl.textContent = `Frage ${index + 1} von ${questions.length}`;
    progressBar.style.width = `${(index / questions.length) * 100}%`;

    q.options.forEach(option => {
      const btn = document.createElement('button');
      btn.className = 'btn btn-outline-primary answer-btn';
      btn.textContent = option;
      btn.addEventListener('click', () => selectAnswer(btn, option, q));
      answersEl.appendChild(btn);
    });
  }

  function selectAnswer(button, answer, question) {
    if (selected) return;
    selected = true;

    const isCorrect = answer === question.answer;

    [...answersEl.children].forEach(btn => {
      btn.disabled = true;
      if (btn.textContent === question.answer) btn.classList.add('correct');
    });

    if (isCorrect) {
      score++;
      feedbackEl.innerHTML = `<strong>Nice!</strong> Genau richtig. ${question.fact || ''}`;
    } else {
      button.classList.add('wrong');
      weakQuestions.push(question);
      feedbackEl.innerHTML = `<strong>Fast!</strong> Richtig wäre: <strong>${question.answer}</strong><br>${question.fact || ''}`;
    }

    feedbackEl.classList.remove('d-none');
    nextBtn.classList.remove('d-none');
  }

  function finishQuiz() {
    quizCard.classList.add('d-none');
    resultCard.classList.remove('d-none');
    progressBar.style.width = '100%';

    const percent = Math.round((score / questions.length) * 100);
    resultText.textContent = `Du hast ${score} von ${questions.length} Fragen richtig beantwortet. Das sind ${percent} %.`;

    localStorage.setItem('elevaro_progress_' + window.ELEVARO_QUIZ.id, JSON.stringify({
      score,
      total: questions.length,
      percent,
      date: new Date().toISOString()
    }));

    weakBtn.classList.toggle('d-none', weakQuestions.length === 0);
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
