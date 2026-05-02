(function () {
  initReturningBox();
  initCounters();
  initHeroQuiz();

  function initReturningBox() {
    const box = document.getElementById('returningBox');
    const title = document.getElementById('returningTitle');
    const text = document.getElementById('returningText');

    if (!box || !title || !text) return;

    let profile = null;

    try {
      profile = JSON.parse(localStorage.getItem('elevaro_profile') || 'null');
    } catch (e) {
      profile = null;
    }

    if (!profile || !profile.values) return;

    const name = localStorage.getItem('elevaro_profile_name');
    const labels = profile.labels || {};
    const values = profile.values || {};

    title.textContent = name ? `Weiterlernen, ${name}?` : 'Weiterlernen?';

    const parts = [
      labels.subject || values.subject,
      labels.grade || (values.grade ? `${values.grade}. Klasse` : null),
      labels.school_type || values.school_type
    ].filter(Boolean);

    text.textContent = parts.length
      ? `Deine letzte Auswahl: ${parts.join(' · ')}`
      : 'Wir haben deine letzte Auswahl gespeichert.';

    box.classList.remove('d-none');
  }

  function initCounters() {
    const counters = document.querySelectorAll('.count-up');
    if (!counters.length) return;

    const animate = (el) => {
      const target = parseInt(el.dataset.target || '0', 10);
      const duration = 1100;
      const start = performance.now();

      function tick(now) {
        const progress = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = Math.round(target * eased).toLocaleString('de-DE') + '+';

        if (progress < 1) requestAnimationFrame(tick);
      }

      requestAnimationFrame(tick);
    };

    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) return;
          animate(entry.target);
          observer.unobserve(entry.target);
        });
      }, { threshold: 0.25 });

      counters.forEach(counter => observer.observe(counter));
    } else {
      counters.forEach(animate);
    }
  }

  function initHeroQuiz() {
    const contextEl = document.getElementById('demoContext');
    const titleEl = document.getElementById('demoTitle');
    const progressEl = document.getElementById('demoProgress');
    const mediaEl = document.getElementById('demoMedia');
    const questionEl = document.getElementById('demoQuestion');
    const answersEl = document.getElementById('demoAnswers');
    const feedbackEl = document.getElementById('demoFeedback');
    const cursorEl = document.getElementById('fakeCursor');
    const scoreEl = document.querySelector('.demo-score');

    if (!questionEl || !answersEl) return;

    const questions = [
      {
        context: 'Englisch · Klasse 5',
        title: 'this, that, these & those',
        question: 'Which sentence is correct?',
        options: ['This are my shoes.', 'These are my shoes.', 'Those is my shoes.'],
        correct: 1,
        feedback: 'Richtig! „These“ nutzt du bei mehreren Dingen in der Nähe.',
        media: null
      },
      {
        context: 'Biologie · Arten erkennen',
        title: 'Vogelarten bestimmen',
        question: 'Welcher Vogel ist hier abgebildet?',
        options: ['Amsel', 'Elster', 'Star'],
        correct: 1,
        feedback: 'Genau. Die Elster erkennst du oft am schwarz-weißen Gefieder.',
        media: '🐦'
      },
      {
        context: 'Geographie · Orientierung',
        title: 'Karten lesen',
        question: 'Welche Richtung liegt auf Karten meistens oben?',
        options: ['Süden', 'Westen', 'Norden'],
        correct: 2,
        feedback: 'Ja! Auf den meisten Karten ist Norden oben.',
        media: '🗺️'
      }
    ];

    let index = 0;

    function renderQuestion() {
      const q = questions[index];

      contextEl.textContent = q.context;
      titleEl.textContent = q.title;
      questionEl.textContent = q.question;
      answersEl.innerHTML = '';
      feedbackEl.classList.add('d-none');
      feedbackEl.textContent = '';
      scoreEl.classList.remove('pop');
      cursorEl.classList.remove('clicking');

      if (q.media) {
        mediaEl.classList.remove('d-none');
        mediaEl.innerHTML = `<span class="media-emoji">${q.media}</span>`;
      } else {
        mediaEl.classList.add('d-none');
        mediaEl.innerHTML = '';
      }

      progressEl.style.width = '0%';
      requestAnimationFrame(() => {
        progressEl.style.width = '100%';
      });

      q.options.forEach((option, optionIndex) => {
        const answer = document.createElement('div');
        answer.className = 'demo-answer pending';
        answer.style.animationDelay = `${optionIndex * 120}ms`;
        answer.textContent = option;
        answersEl.appendChild(answer);
      });

      const clickDelay = 1200;
      const revealDelay = 1650;
      const nextDelay = 3600;

      setTimeout(() => fakeClick(q.correct), clickDelay);
      setTimeout(() => revealAnswer(q), revealDelay);
      setTimeout(() => {
        index = (index + 1) % questions.length;
        renderQuestion();
      }, nextDelay);
    }

    function fakeClick(correctIndex) {
      const target = answersEl.children[correctIndex];
      if (!target) return;

      const shell = document.querySelector('.demo-shell');
      const shellRect = shell.getBoundingClientRect();
      const targetRect = target.getBoundingClientRect();

      const x = targetRect.left - shellRect.left + targetRect.width * 0.72;
      const y = targetRect.top - shellRect.top + targetRect.height * 0.55;

      cursorEl.style.left = `${x}px`;
      cursorEl.style.top = `${y}px`;
      cursorEl.classList.add('clicking');
    }

    function revealAnswer(q) {
      Array.from(answersEl.children).forEach((answer, i) => {
        answer.classList.toggle('selected', i === q.correct);
      });

      feedbackEl.textContent = q.feedback;
      feedbackEl.classList.remove('d-none');

      scoreEl.classList.remove('pop');
      void scoreEl.offsetWidth;
      scoreEl.classList.add('pop');
    }

    renderQuestion();
  }
})();
