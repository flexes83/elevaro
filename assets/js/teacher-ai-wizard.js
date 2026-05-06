(() => {
  const root = document.querySelector('.ai-wizard');
  if (!root) return;

  let state = { draftId: null, payload: null, imagePath: null };
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

  function toast(message) {
    const el = document.createElement('div');
    el.className = 'ai-toast';
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3600);
  }

  function step(n) {
    $$('.ai-wizard-panel').forEach(p => p.classList.toggle('is-active', p.dataset.step === String(n)));
    $$('[data-step-indicator]').forEach(b => b.classList.toggle('is-active', b.dataset.stepIndicator === String(n)));
  }

  function apiJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {})
    }).then(async res => {
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) throw new Error(json.error || 'Anfrage fehlgeschlagen.');
      return json;
    });
  }

  $$('.ai-mode-card').forEach(card => {
    card.addEventListener('click', () => {
      $$('.ai-mode-card').forEach(c => c.classList.remove('is-selected'));
      card.classList.add('is-selected');
      const input = $('input', card);
      if (input) input.checked = true;
    });
  });

  const loadingTexts = [
    'Material wird gelesen und quellengebunden analysiert...',
    'Die KI erstellt zuerst eine sichere Inhaltsbasis...',
    'Danach entstehen die Fragen in mehreren kleinen Blöcken...',
    'So gehen keine Informationen verloren und die Antwort wird nicht abgeschnitten...',
    'Handschriftliche Notizen werden visuell geprüft, soweit erkennbar...',
    'Fragenblock 1/3 wird vorbereitet...',
    'Antwortoptionen werden plausibel gemischt...'
  ];
  let loadingTimer = null;
  function startLoadingCopy() {
    let i = 0;
    $('#aiWizardProgressText').textContent = loadingTexts[0];
    loadingTimer = setInterval(() => {
      i = (i + 1) % loadingTexts.length;
      $('#aiWizardProgressText').textContent = loadingTexts[i];
    }, 2300);
  }
  function stopLoadingCopy() { if (loadingTimer) clearInterval(loadingTimer); }

  async function pollGeneration(draftId) {
    const started = Date.now();
    let lastStatus = '';

    while (Date.now() - started < 8 * 60 * 1000) {
      await new Promise(resolve => setTimeout(resolve, 3500));
      const res = await apiJson('/teacher/api/ai_wizard_status.php', { draft_id: draftId });
      if (res.done) {
        state.payload = res.payload;
        return;
      }
      if (res.status && res.status !== lastStatus) {
        lastStatus = res.status;
        const label = $('#aiWizardProgressText');
        if (label) label.textContent = res.status_label || ('OpenAI verarbeitet dein Material… Status: ' + res.status);
      }
    }

    throw new Error('Die KI-Erstellung dauert ungewöhnlich lange. Bitte versuche es später erneut oder nutze weniger Seiten.');
  }

  $('#aiWizardSourceForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    step(2);
    startLoadingCopy();
    try {
      const form = e.currentTarget;
      const res = await fetch('/teacher/api/ai_wizard_generate.php', { method: 'POST', body: new FormData(form) });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) throw new Error(json.error || 'KI-Erstellung fehlgeschlagen.');
      state.draftId = json.draft_id;

      if (json.pending) {
        await pollGeneration(state.draftId);
      } else {
        state.payload = json.payload;
      }

      fillEditor();
      step(3);
      generateImage(false);
    } catch (err) {
      toast(err.message || String(err));
      step(1);
    } finally {
      stopLoadingCopy();
    }
  });

  function fillEditor() {
    const p = state.payload || {};
    $('#aiDraftId').value = state.draftId || '';
    $('#aiQuizTitle').value = p.title || '';
    $('#aiQuizDescription').value = p.description || '';
    $('#aiImagePrompt').value = p.image_prompt || '';
    $('#aiListeningText').value = p.listening_text || '';
    $('#aiListeningBox').classList.toggle('d-none', p.mode !== 'listening');
    renderQuestions();
    updatePublishSummary();
  }

  function readPayload() {
    const questions = $$('.ai-question-card').map(card => {
      const options = $$('.ai-option-text', card).map(i => i.value.trim()).filter(Boolean);
      const checked = $('.ai-option-correct:checked', card);
      const correctIndex = checked ? Number(checked.value) : 0;
      return {
        question: $('.ai-question-text', card).value.trim(),
        options,
        answer: options[correctIndex] || options[0] || '',
        explanation: $('.ai-question-explanation', card).value.trim(),
        difficulty: Number($('.ai-question-difficulty', card).value || 0.35)
      };
    }).filter(q => q.question && q.options.length);

    state.payload = {
      ...(state.payload || {}),
      title: $('#aiQuizTitle').value.trim(),
      description: $('#aiQuizDescription').value.trim(),
      listening_text: $('#aiListeningText').value.trim(),
      image_prompt: $('#aiImagePrompt').value.trim(),
      questions
    };
    return state.payload;
  }

  function renderQuestions() {
    const box = $('#aiQuestionEditor');
    const questions = (state.payload && state.payload.questions) ? state.payload.questions : [];
    box.innerHTML = '';
    questions.forEach((q, idx) => box.appendChild(questionCard(q, idx)));
  }

  function questionCard(q, idx) {
    const card = document.createElement('div');
    card.className = 'ai-question-card';
    const opts = (q.options && q.options.length ? q.options : ['', '', '', '']).slice(0, 4);
    while (opts.length < 4) opts.push('');
    let correctIndex = Math.max(0, opts.findIndex(o => o === q.answer));
    if (correctIndex < 0) correctIndex = 0;
    card.innerHTML = `
      <div class="ai-question-card-head">
        <span class="ai-question-number">Frage ${idx + 1}</span>
        <button class="btn btn-sm btn-outline-danger ai-delete-question" type="button">Löschen</button>
      </div>
      <label class="form-label fw-bold">Frage</label>
      <textarea class="form-control ai-question-text" rows="2">${esc(q.question || '')}</textarea>
      <div class="ai-question-options">
        ${opts.map((o, i) => `
          <label class="ai-option-row">
            <input class="ai-option-correct" type="radio" name="correct_${Date.now()}_${idx}" value="${i}" ${i === correctIndex ? 'checked' : ''}>
            <input class="form-control ai-option-text" value="${esc(o)}" placeholder="Antwort ${i + 1}">
          </label>
        `).join('')}
      </div>
      <label class="form-label fw-bold mt-3">Erklärung</label>
      <textarea class="form-control ai-question-explanation" rows="2">${esc(q.explanation || '')}</textarea>
      <label class="form-label fw-bold mt-3">Schwierigkeit</label>
      <input class="form-range ai-question-difficulty" min="0.05" max="0.95" step="0.05" value="${Number(q.difficulty || .35)}" type="range">
      <div class="ai-question-actions">
        <button class="btn btn-sm btn-outline-primary ai-suggest-options" type="button">✨ 4 Antworten vorschlagen</button>
      </div>`;
    $('.ai-delete-question', card).addEventListener('click', () => { card.remove(); renumber(); });
    $('.ai-suggest-options', card).addEventListener('click', () => suggestOptions(card));
    return card;
  }

  function renumber() {
    $$('.ai-question-card').forEach((card, i) => $('.ai-question-number', card).textContent = `Frage ${i + 1}`);
  }

  function esc(s) {
    return String(s || '').replace(/[&<>"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));
  }

  $('#aiAddQuestion')?.addEventListener('click', () => {
    const box = $('#aiQuestionEditor');
    box.appendChild(questionCard({ question: '', options: ['', '', '', ''], answer: '', explanation: '', difficulty: 0.35 }, $$('.ai-question-card').length));
  });

  async function suggestOptions(card) {
    try {
      readPayload();
      const question = $('.ai-question-text', card).value.trim();
      const btn = $('.ai-suggest-options', card);
      btn.disabled = true; btn.textContent = 'KI denkt...';
      const res = await apiJson('/teacher/api/ai_wizard_suggest_options.php', { draft_id: state.draftId, question });
      const suggestion = res.suggestion || {};
      const opts = suggestion.options || [];
      $$('.ai-option-text', card).forEach((input, i) => { input.value = opts[i] || ''; });
      $('.ai-question-explanation', card).value = suggestion.explanation || '';
      const correctIndex = opts.findIndex(o => o === suggestion.answer);
      if (correctIndex >= 0) {
        const radio = $$('.ai-option-correct', card)[correctIndex];
        if (radio) radio.checked = true;
      }
    } catch (err) {
      toast(err.message || String(err));
    } finally {
      const btn = $('.ai-suggest-options', card);
      btn.disabled = false; btn.textContent = '✨ 4 Antworten vorschlagen';
    }
  }

  async function saveDraft() {
    const payload = readPayload();
    await apiJson('/teacher/api/ai_wizard_save.php', { draft_id: state.draftId, payload });
    updatePublishSummary();
  }

  $('#aiSaveReview')?.addEventListener('click', async () => {
    try { await saveDraft(); step(4); }
    catch (err) { toast(err.message || String(err)); }
  });

  $$('[data-back-to-step]').forEach(btn => btn.addEventListener('click', () => step(btn.dataset.backToStep)));

  function updatePublishSummary() {
    const p = readPayload();
    $('#aiPublishSummary').innerHTML = `
      <strong>${esc(p.title || 'Unbenanntes Quiz')}</strong><br>
      ${p.questions.length} Fragen · ${p.mode === 'listening' ? 'Listening + Comprehension' : 'Multiple Choice'}<br>
      <span class="text-muted">${esc((p.description || '').slice(0, 180))}</span>`;
  }

  async function generateImage(showToast = true) {
    if (!state.draftId) return;
    const preview = $('#aiImagePreview');
    preview.innerHTML = '<span>🎨</span><p>Bild wird erstellt...</p>';
    try {
      const res = await apiJson('/teacher/api/ai_wizard_image.php', { draft_id: state.draftId, image_prompt: $('#aiImagePrompt').value.trim() });
      state.imagePath = res.image_path;
      preview.innerHTML = `<img src="${esc(res.image_path)}" alt="Quizbild"><p>KI-Bild erstellt</p>`;
      if (showToast) toast('Bild wurde neu erstellt.');
    } catch (err) {
      preview.innerHTML = '<span>🎨</span><p>Bild konnte nicht automatisch erstellt werden.</p>';
      if (showToast) toast(err.message || String(err));
    }
  }
  $('#aiRegenerateImage')?.addEventListener('click', () => generateImage(true));

  $('#aiPublishQuiz')?.addEventListener('click', async () => {
    try {
      const btn = $('#aiPublishQuiz');
      btn.disabled = true; btn.textContent = 'Veröffentliche...';
      const payload = readPayload();
      const res = await apiJson('/teacher/api/ai_wizard_publish.php', { draft_id: state.draftId, payload });
      toast('Quiz wurde veröffentlicht.');
      window.location.href = res.class_quizzes_url || '/teacher/quizzes.php';
    } catch (err) {
      toast(err.message || String(err));
      $('#aiPublishQuiz').disabled = false;
      $('#aiPublishQuiz').textContent = '🚀 Für Klasse veröffentlichen';
    }
  });
})();
