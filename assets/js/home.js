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
    const contentEl = document.querySelector('.demo-content');
    const questionEl = document.getElementById('demoQuestion');
    const answersEl = document.getElementById('demoAnswers');
    const feedbackEl = document.getElementById('demoFeedback');
    const cursorEl = document.getElementById('fakeCursor');
    const pointsEl = document.getElementById('demoPoints');
    const streakEl = document.getElementById('demoStreak');

    if (!questionEl || !answersEl || !contentEl) return;

    const questions = [
      {
        context: 'Englisch · Klasse 5',
        title: 'this, that, these & those',
        question: 'Which sentence is correct?',
        options: ['This are my shoes.', 'These are my shoes.', 'Those is my shoes.'],
        correct: 1,
        feedback: 'Richtig! „These“ nutzt du bei mehreren Dingen in der Nähe.',
        media: null,
        points: 120,
        streak: 3
      },
      {
        context: 'Biologie · Arten erkennen',
        title: 'Vogelarten bestimmen',
        question: 'Welcher Vogel ist hier abgebildet?',
        options: ['Amsel', 'Elster', 'Star'],
        correct: 1,
        feedback: 'Genau. Die Elster erkennst du oft am schwarz-weißen Gefieder.',
        media: 'bird',
        points: 130,
        streak: 4
      },
      {
        context: 'Geographie · Orientierung',
        title: 'Karten lesen',
        question: 'Welche Richtung liegt auf Karten meistens oben?',
        options: ['Süden', 'Westen', 'Norden'],
        correct: 2,
        feedback: 'Ja! Auf den meisten Karten ist Norden oben.',
        media: 'map',
        points: 140,
        streak: 5
      }
    ];

    let index = 0;

    function renderQuestion() {
      const q = questions[index];

      contentEl.classList.add('is-leaving');

      setTimeout(() => {
        contextEl.textContent = q.context;
        titleEl.textContent = q.title;
        questionEl.textContent = q.question;
        answersEl.innerHTML = '';
        feedbackEl.classList.add('d-none');
        feedbackEl.textContent = '';
        pointsEl.textContent = q.points;
        streakEl.textContent = q.streak;

        if (q.media === 'bird') {
          mediaEl.classList.remove('d-none');
          mediaEl.innerHTML = '<div class="demo-media-inner"><span class="demo-bird"></span></div>';
        } else if (q.media === 'map') {
          mediaEl.classList.remove('d-none');
          mediaEl.innerHTML = '<div class="demo-media-inner map-demo"><span class="media-emoji">🗺️</span></div>';
        } else {
          mediaEl.classList.add('d-none');
          mediaEl.innerHTML = '';
        }

        progressEl.classList.remove('running');
        void progressEl.offsetWidth;
        progressEl.classList.add('running');

        q.options.forEach((option, optionIndex) => {
          const answer = document.createElement('div');
          answer.className = 'demo-answer pending';
          answer.style.animationDelay = `${optionIndex * 100}ms`;
          answer.textContent = option;
          answersEl.appendChild(answer);
        });

        contentEl.classList.remove('is-leaving');
        contentEl.classList.add('is-entering');
        requestAnimationFrame(() => {
          contentEl.classList.remove('is-entering');
        });

        setTimeout(() => fakeClick(q.correct), 1200);
        setTimeout(() => revealAnswer(q), 1580);
        setTimeout(() => {
          index = (index + 1) % questions.length;
          renderQuestion();
        }, 4000);
      }, 260);
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
      cursorEl.classList.remove('clicking');
      void cursorEl.offsetWidth;
      cursorEl.classList.add('clicking');

      createPointsPop(shell, x, y);
    }

    function revealAnswer(q) {
      Array.from(answersEl.children).forEach((answer, i) => {
        answer.classList.toggle('selected', i === q.correct);
      });

      feedbackEl.textContent = q.feedback;
      feedbackEl.classList.remove('d-none');
    }

    function createPointsPop(shell, x, y) {
      const pop = document.createElement('div');
      pop.className = 'points-pop';
      pop.textContent = '+10';
      pop.style.left = `${x}px`;
      pop.style.top = `${y}px`;
      shell.appendChild(pop);

      setTimeout(() => pop.remove(), 950);
    }

    renderQuestion();
  }
})();
