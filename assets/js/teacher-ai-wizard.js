(() => {
  const root = document.querySelector('.ai-wizard');
  if (!root) return;

  let state = { draftId: null, payload: null, imagePath: null, analysis: null, analysisRoute: null };
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

  function showError(message) { alert(message); }

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

  async function apiJson(url, payload) {
    let res;
    try {
      res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {})
      });
    } catch (networkError) {
      throw new Error('Verbindung unterbrochen oder Server nicht erreichbar. Bitte erneut versuchen. Details: ' + (networkError.message || networkError));
    }

    const text = await res.text();
    let json = {};
    try {
      json = text ? JSON.parse(text) : {};
    } catch (parseError) {
      throw new Error(text ? 'Serverantwort war kein JSON: ' + text.replace(/\s+/g, ' ').slice(0, 500) : 'Serverantwort war leer.');
    }

    if (!res.ok || !json.ok) {
      throw new Error(json.error || 'Anfrage fehlgeschlagen.');
    }

    return json;
  }


  const promptPlaceholderSets = {
    default: [
      'z. B. eher leichte Fragen, Fokus auf Vokabelverständnis, keine Jahreszahlen abfragen...',
      'z. B. kurze Fragen für Klasse 5, keine Fangfragen, einfache Sprache...',
      'z. B. verwende Alltagssituationen aus Schule, Familie und Freizeit...'
    ],
    curriculum: [
      'Verwende die Vokabeln „mother“, „father“, „sister“ und „brother“...',
      'Erstelle einfache Aufgaben auf Niveau A1 mit kurzen Sätzen...',
      'Fokus auf Monate, Jahreszeiten und Datumsangaben...',
      'Nutze Beispiele aus dem Schulalltag und der Familie...',
      'Baue 3 Wiederholungsfragen zu schwierigen Begriffen ein...'
    ],
    listening: [
      'Erstelle einen kurzen Dialog zwischen zwei Schülern...',
      'Nutze einfache Alltagssprache und langsames Niveau...',
      'Der Hörtext soll eine kleine Geschichte mit 5 Abschnitten sein...',
      'Verwende die Wörter „mother“, „father“, „sister“ und „school“...'
    ]
  };

  let promptPlaceholderIndex = 0;
  let promptPlaceholderTimer = null;

  function updateExtraPromptPlaceholder() {
    const field = $('#aiExtraPrompt');
    if (!field || field.value.trim() !== '') return;

    const sourceKind = $('#aiWizardSourceKind')?.value || 'material';
    const mode = document.querySelector('input[name="mode"]:checked')?.value || 'quiz';
    const key = mode === 'listening' ? 'listening' : (sourceKind === 'curriculum' ? 'curriculum' : 'default');
    const items = promptPlaceholderSets[key] || promptPlaceholderSets.default;

    field.classList.add('is-placeholder-changing');
    setTimeout(() => {
      field.placeholder = items[promptPlaceholderIndex % items.length];
      field.dataset.placeholderMode = key;
      promptPlaceholderIndex++;
      field.classList.remove('is-placeholder-changing');
    }, 120);
  }

  function startPromptPlaceholderRotation() {
    if (promptPlaceholderTimer) clearInterval(promptPlaceholderTimer);
    updateExtraPromptPlaceholder();
    promptPlaceholderTimer = setInterval(updateExtraPromptPlaceholder, 2600);
  }


  function wireChoiceCards(selector) {
    $$(selector).forEach(card => {
      card.addEventListener('click', () => {
        const input = $('input', card);
        if (!input) return;
        const name = input.name;
        $$(`${selector} input[name="${name}"]`).forEach(i => i.closest(selector)?.classList.remove('is-selected'));
        card.classList.add('is-selected');
        const changed = !input.checked;
        input.checked = true;
        if (changed) {
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    });
  }
  wireChoiceCards('.ai-mode-card');
  wireChoiceCards('.ai-intent-card');

  wireChoiceCards('.ai-source-kind-card');

  const curriculumState = { domains: [], topicMap: new Map() };

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch]));
  }

  function updateSourceKind(kind) {
    const hidden = $('#aiWizardSourceKind');
    if (hidden) hidden.value = kind;

    const isCurriculum = kind === 'curriculum';
    $('#aiMaterialSourceBox')?.classList.toggle('is-active', !isCurriculum);
    $('#aiCurriculumSourceBox')?.classList.toggle('is-active', isCurriculum);
    $('#aiMaterialGoalBox')?.classList.toggle('d-none', isCurriculum);
    $('#aiCurriculumGoalBox')?.classList.toggle('d-none', !isCurriculum);

    if (isCurriculum && !curriculumState.domains.length) {
      loadCurriculumTopics();
    }
    updateExtraPromptPlaceholder();
  }

  async function loadCurriculumTopics() {
    const select = $('#aiCurriculumTopicSelect');
    if (!select) return;
    select.innerHTML = '<option value="">Themen werden geladen…</option>';
    try {
      const res = await fetch('/teacher/api/curriculum_topics.php?class_id=' + encodeURIComponent(root.dataset.classId || '0'));
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'Themen konnten nicht geladen werden.');
      curriculumState.domains = json.domains || [];
      curriculumState.topicMap = new Map();
      let html = '<option value="">Bitte Thema auswählen…</option>';
      curriculumState.domains.forEach(domain => {
        html += `<optgroup label="${escapeHtml(domain.title || 'Allgemein')}">`;
        (domain.topics || []).forEach(topic => {
          curriculumState.topicMap.set(String(topic.id), topic);
          html += `<option value="${topic.id}">${escapeHtml(topic.title)}</option>`;
        });
        html += '</optgroup>';
      });
      if (!json.count) html = '<option value="">Noch keine Lehrplanthemen für diese Klasse importiert</option>';
      select.innerHTML = html;
    } catch (err) {
      select.innerHTML = '<option value="">Fehler beim Laden der Themen</option>';
      showError(err.message || 'Themen konnten nicht geladen werden.');
    }
  }

  function updateSubtopics() {
    const topicId = $('#aiCurriculumTopicSelect')?.value || '';
    const sub = $('#aiCurriculumSubtopicSelect');
    const preview = $('#aiCurriculumPreview');
    const topic = curriculumState.topicMap.get(String(topicId));
    if (!sub) return;
    sub.innerHTML = '<option value="">Ganzes Thema verwenden</option>';
    if (topic) {
      (topic.subtopics || []).forEach(s => { sub.innerHTML += `<option value="${s.id}">${escapeHtml(s.title)}</option>`; });
      if (preview) preview.innerHTML = `<strong>${escapeHtml(topic.title)}</strong><br>${escapeHtml(topic.title_long || topic.description || 'Dieses Thema wird als Grundlage für das Quiz verwendet.')}`;
    } else if (preview) {
      preview.textContent = 'Wähle ein Thema aus. Elevaro nutzt Kurz- und Langtitel, Lernziel, Keywords und Klassenkontext als Grundlage.';
    }
  }

  $$('.ai-source-kind-card input').forEach(input => input.addEventListener('change', () => updateSourceKind(input.value)));
  $('#aiCurriculumTopicSelect')?.addEventListener('change', updateSubtopics);


  const loadingTexts = [
    'Material wird gelesen und strukturiert...',
    'Die KI erkennt, ob es um Inhalte oder Übungen geht...',
    'Lernziele werden herausgearbeitet...',
    'Relevante Themen werden pädagogisch sortiert...',
    'Fragen werden passend zum Aufgabentyp formuliert...',
    'Antwortmöglichkeiten werden didaktisch geprüft...',
    'Der Quizentwurf wird vorbereitet...'
  ];
  let loadingTimer = null;
  function startLoadingCopy() {
    let i = 0;
    $('#aiWizardProgressText').textContent = loadingTexts[0];
    setProgress(10, loadingTexts[0]);
    loadingTimer = setInterval(() => {
      i = (i + 1) % loadingTexts.length;
      $('#aiWizardProgressText').textContent = loadingTexts[i];
    }, 2300);
  }
  function stopLoadingCopy() { if (loadingTimer) clearInterval(loadingTimer); }


  function showAnalysisRoute(route) {
    if (!route) return;
    const card = $('#aiRouteCard');
    const headline = $('#aiRouteHeadline');
    const steps = $('#aiRouteSteps');
    if (!card || !headline || !steps) return;

    headline.textContent = route.headline || 'Material erkannt';
    steps.innerHTML = '';

    (route.steps || []).forEach((stepText, index) => {
      const li = document.createElement('li');
      li.textContent = stepText;
      li.style.animationDelay = `${index * 120}ms`;
      steps.appendChild(li);
    });

    card.classList.remove('d-none');
    card.dataset.route = route.route || 'general';
  }

  function resetAnalysisRoute() {
    const card = $('#aiRouteCard');
    if (!card) return;
    card.classList.add('d-none');
    card.removeAttribute('data-route');
  }


  function setProgress(percent, label) {
    const bar = document.querySelector('#aiWizardProgressBar');
    const labelEl = $('#aiWizardProgressText');
    const generatingCard = document.querySelector('.ai-generating-card');
    const isPlausibility = String(label || '').toLowerCase().includes('plausibilität') || String(label || '').toLowerCase().includes('fachliche richtigkeit');
    if (generatingCard) generatingCard.classList.toggle('is-plausibility-check', isPlausibility);
    if (isPlausibility && loadingTimer) {
      clearInterval(loadingTimer);
      loadingTimer = null;
    }
    const safePercent = Math.max(5, Math.min(100, Number(percent || 0)));
    if (bar) {
      bar.style.animation = 'none';
      bar.style.transform = 'none';
      bar.style.width = safePercent + '%';
    }
    if (labelEl && label) labelEl.textContent = label;
  }

  function progressFromStatus(status) {
    if (!status) return 12;
    if (status === 'analysis_done') return 25;
    const match = String(status).match(/^questions_(\d+)/);
    if (match) return 25 + Math.round((Number(match[1]) / 3) * 62);
    if (status === 'plausibility') return 93;
    if (status === 'done') return 100;
    return 14;
  }

  async function pollGeneration(draftId) {
    const started = Date.now();
    let lastStatus = '';

    while (Date.now() - started < 8 * 60 * 1000) {
      await new Promise(resolve => setTimeout(resolve, 3500));
      const res = await apiJson('/teacher/api/ai_wizard_status.php', { draft_id: draftId });
      if (res.needs_analysis_review) {
        state.analysis = res.analysis || {};
        state.analysisRoute = res.analysis_route || null;
        if (res.analysis_route) showAnalysisRoute(res.analysis_route);
        renderAnalysisReview(state.analysis, state.analysisRoute);
        return 'analysis_review';
      }

      if (res.done) {
        setProgress(100, 'Fertig. Dein Quizentwurf wird geladen…');
        state.payload = res.payload;
        if (res.payload && res.payload.analysis_route) {
          showAnalysisRoute(res.payload.analysis_route);
        }
        return 'done';
      }
      if (res.status && res.status !== lastStatus) {
        lastStatus = res.status;
        setProgress(res.progress || progressFromStatus(res.status), res.status_label || 'Elevaro erstellt deinen Quizentwurf…');
      }
    }

    throw new Error('Die KI-Erstellung dauert ungewöhnlich lange. Bitte versuche es später erneut oder nutze weniger Seiten.');
  }

  $('#aiWizardSourceForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    step(2);
    setProgress(8, 'Inhalte werden hochgeladen…');
    startLoadingCopy();
    try {
      const form = e.currentTarget;
      const sourceKind = $('#aiWizardSourceKind')?.value || 'material';
      if (sourceKind === 'curriculum' && !($('#aiCurriculumTopicSelect')?.value || '')) {
        throw new Error('Bitte ein Lerninhalt auswählen.');
      }
      const res = await fetch('/teacher/api/ai_wizard_generate.php', { method: 'POST', body: new FormData(form) });
      const text = await res.text();
      let json = {};
      try { json = text ? JSON.parse(text) : {}; }
      catch (parseError) { throw new Error(text ? 'Serverantwort war kein JSON: ' + text.replace(/\s+/g, ' ').slice(0, 500) : 'Serverantwort war leer.'); }
      if (!res.ok || !json.ok) throw new Error(json.error || 'KI-Erstellung fehlgeschlagen.');
      state.draftId = json.draft_id;

      if (json.pending) {
        const pollResult = await pollGeneration(state.draftId);
        if (pollResult === 'analysis_review') {
          stopLoadingCopy();
          step('analysis');
          return;
        }
      } else {
        state.payload = json.payload;
      }

      fillEditor();
      step(3);
      generateImage(false);
    } catch (err) {
      toast(err.message || String(err));
      const box = document.getElementById('aiWizardErrorBox');
      if (box) {
        box.classList.remove('d-none');
        box.textContent = err.message || String(err);
      }
      // Nicht zurück auf den Startscreen springen: Lehrer soll Fehlermeldung sehen und Eingaben behalten.
      step(2);
    } finally {
      stopLoadingCopy();
    }
  });



  function splitListInput(value) {
    return String(value || '')
      .split(/\n|,/)
      .map(v => v.trim())
      .filter(Boolean);
  }

  function joinListInput(items) {
    return (items || []).filter(Boolean).join('\n');
  }

  function populateAnalysisCurriculumSelect() {
    const topicSelect = $('#aiAnalysisCurriculumTopic');
    const subtopicSelect = $('#aiAnalysisCurriculumSubtopic');
    if (!topicSelect || !subtopicSelect) return;

    if (!curriculumState.domains.length) {
      loadCurriculumTopics().then(populateAnalysisCurriculumSelect).catch(() => {});
      return;
    }

    const currentTopic = String((state.analysis && state.analysis.curriculum_topic_content_id) || $('#aiCurriculumTopicSelect')?.value || '');
    const currentSubtopic = String((state.analysis && state.analysis.curriculum_topic_subtopic_id) || $('#aiCurriculumSubtopicSelect')?.value || '');

    topicSelect.innerHTML = '<option value="">KI-Zuordnung verwenden</option>';
    curriculumState.domains.forEach(domain => {
      const group = document.createElement('optgroup');
      group.label = domain.domain_title || domain.title || domain.name || 'Lerninhalte';
      (domain.topics || []).forEach(topic => {
        const option = document.createElement('option');
        option.value = topic.id;
        option.textContent = topic.title || topic.title_short || topic.topic_title || topic.name || ('Thema #' + topic.id);
        group.appendChild(option);
      });
      topicSelect.appendChild(group);
    });

    topicSelect.value = currentTopic;
    populateAnalysisSubtopicSelect(currentTopic, currentSubtopic);
  }

  function populateAnalysisSubtopicSelect(topicId, selectedSubtopic) {
    const subtopicSelect = $('#aiAnalysisCurriculumSubtopic');
    if (!subtopicSelect) return;
    subtopicSelect.innerHTML = '<option value="">Ganzes Thema</option>';
    const topic = findCurriculumTopic(topicId);
    if (!topic) return;
    (topic.subtopics || []).forEach(sub => {
      const option = document.createElement('option');
      option.value = sub.id;
      option.textContent = sub.title_short || sub.subtopic_title || ('Skill #' + sub.id);
      subtopicSelect.appendChild(option);
    });
    if (selectedSubtopic) subtopicSelect.value = String(selectedSubtopic);
  }

  function renderAnalysisReview(analysis, route) {
    state.analysis = analysis || {};
    state.analysisRoute = route || state.analysis.analysis_route || null;

    $('#aiAnalysisMaterialType').value = state.analysis.material_type || 'mixed';
    $('#aiAnalysisContentMode').value = state.analysis.content_mode || (
      state.analysis.task_intent === 'quiz_about_content' ? 'content_source' : 'self_contained_exercises'
    );
    $('#aiAnalysisStrategy').value = state.analysis.generation_strategy || (
      $('#aiAnalysisContentMode').value === 'content_source' ? 'content_questions' : 'reuse_or_adapt_examples'
    );
    $('#aiAnalysisRequiresContext').value = state.analysis.requires_visible_context ? '1' : '0';
    $('#aiAnalysisSkills').value = joinListInput(state.analysis.detected_skills || state.analysis.topics || []);
    $('#aiAnalysisDependencies').value = joinListInput(state.analysis.detected_dependencies || []);

    const headline = $('#aiAnalysisHeadline');
    const steps = $('#aiAnalysisSteps');
    if (headline && steps) {
      const routePayload = route || {};
      headline.textContent = routePayload.headline || 'Analyse prüfen';
      steps.innerHTML = '';
      (routePayload.steps || [
        'Prüfe, ob die didaktische Einordnung stimmt.',
        'Danach erstellt Elevaro die Fragen mit dieser Strategie.'
      ]).forEach(text => {
        const li = document.createElement('li');
        li.textContent = text;
        steps.appendChild(li);
      });
    }

    populateAnalysisCurriculumSelect();
  }

  function readAnalysisReview() {
    const contentMode = $('#aiAnalysisContentMode')?.value || 'content_source';
    const strategy = $('#aiAnalysisStrategy')?.value || (
      contentMode === 'content_source' ? 'content_questions' : 'reuse_or_adapt_examples'
    );

    return {
      ...(state.analysis || {}),
      material_type: $('#aiAnalysisMaterialType')?.value || 'mixed',
      content_mode: contentMode,
      generation_strategy: strategy,
      requires_visible_context: ($('#aiAnalysisRequiresContext')?.value || '0') === '1',
      exercise_transform: contentMode !== 'content_source',
      detected_skills: splitListInput($('#aiAnalysisSkills')?.value || ''),
      detected_dependencies: splitListInput($('#aiAnalysisDependencies')?.value || ''),
      curriculum_topic_content_id: Number($('#aiAnalysisCurriculumTopic')?.value || 0),
      curriculum_topic_subtopic_id: Number($('#aiAnalysisCurriculumSubtopic')?.value || 0)
    };
  }


  function populateReviewCurriculumSelect() {
    const topicSelect = $('#aiReviewCurriculumTopic');
    const subtopicSelect = $('#aiReviewCurriculumSubtopic');
    if (!topicSelect || !subtopicSelect) return;

    if (!curriculumState.domains.length) {
      loadCurriculumTopics().then(populateReviewCurriculumSelect).catch(() => {});
      return;
    }

    const currentTopic = String((state.payload && state.payload.curriculum_topic_content_id) || $('#aiCurriculumTopicSelect')?.value || '');
    const currentSubtopic = String((state.payload && state.payload.curriculum_topic_subtopic_id) || $('#aiCurriculumSubtopicSelect')?.value || '');

    topicSelect.innerHTML = '<option value="">Automatisch zuordnen</option>';
    curriculumState.domains.forEach(domain => {
      const group = document.createElement('optgroup');
      group.label = domain.domain_title || domain.title || domain.name || 'Lerninhalte';
      (domain.topics || []).forEach(topic => {
        const option = document.createElement('option');
        option.value = topic.id;
        option.textContent = topic.title || topic.title_short || topic.topic_title || topic.name || ('Thema #' + topic.id);
        group.appendChild(option);
      });
      topicSelect.appendChild(group);
    });

    topicSelect.value = currentTopic;
    populateReviewSubtopicSelect(currentTopic, currentSubtopic);
  }

  function populateReviewSubtopicSelect(topicId, selectedSubtopic) {
    const subtopicSelect = $('#aiReviewCurriculumSubtopic');
    if (!subtopicSelect) return;
    subtopicSelect.innerHTML = '<option value="">Ganzes Thema</option>';
    const topic = findCurriculumTopic(topicId);
    if (!topic) return;
    (topic.subtopics || []).forEach(sub => {
      const option = document.createElement('option');
      option.value = sub.id;
      option.textContent = sub.title_short || sub.subtopic_title || ('Skill #' + sub.id);
      subtopicSelect.appendChild(option);
    });
    if (selectedSubtopic) subtopicSelect.value = String(selectedSubtopic);
  }


  function fillEditor() {
    const p = state.payload || {};
    $('#aiDraftId').value = state.draftId || '';
    $('#aiQuizTitle').value = p.title || '';
    $('#aiQuizDescription').value = p.description || '';
    $('#aiImagePrompt').value = p.image_prompt || '';
    const debugImagePrompt = document.querySelector('[data-debug-image-prompt]');
    if (debugImagePrompt) debugImagePrompt.textContent = p.image_prompt || '';
    $('#aiListeningText').value = p.listening_text || '';
    $('#aiListeningBox').classList.toggle('d-none', p.mode !== 'listening');
    renderPlausibilityReview();
    renderQuestions();
    populateReviewCurriculumSelect();
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
      curriculum_topic_content_id: $('#aiReviewCurriculumTopic') ? Number($('#aiReviewCurriculumTopic').value || 0) : Number((state.payload || {}).curriculum_topic_content_id || 0),
      curriculum_topic_subtopic_id: $('#aiReviewCurriculumSubtopic') ? Number($('#aiReviewCurriculumSubtopic').value || 0) : Number((state.payload || {}).curriculum_topic_subtopic_id || 0),
      title: $('#aiQuizTitle').value.trim(),
      description: $('#aiQuizDescription').value.trim(),
      listening_text: $('#aiListeningText').value.trim(),
      image_prompt: $('#aiImagePrompt').value.trim(),
      questions
    };
    return state.payload;
  }


  function renderPlausibilityReview() {
    const p = state.payload || {};
    let box = $('#aiPlausibilityReview');
    const questionEditor = $('#aiQuestionEditor');
    if (!questionEditor) return;
    if (!box) {
      box = document.createElement('div');
      box.id = 'aiPlausibilityReview';
      questionEditor.parentNode.insertBefore(box, questionEditor);
    }

    const review = p.plausibility_review || null;
    if (!review) {
      box.innerHTML = '';
      box.classList.add('d-none');
      return;
    }

    box.classList.remove('d-none');
    const issues = Array.isArray(review.issues) ? review.issues : [];
    const notes = Array.isArray(review.teacher_notes) ? review.teacher_notes : [];
    const status = review.overall_status === 'ok' ? 'Sieht gut aus' : 'Bitte kurz prüfen';
    const issueHtml = issues.length
      ? `<ul>${issues.slice(0, 8).map(i => `<li><strong>Frage ${Number(i.question_number || 0)}:</strong> ${esc(i.message || '')}${i.suggestion ? `<br><small>${esc(i.suggestion)}</small>` : ''}</li>`).join('')}</ul>`
      : '<p>Keine kritischen Auffälligkeiten gefunden.</p>';
    const noteHtml = notes.length ? `<div class="ai-plausibility-notes">${notes.slice(0, 4).map(n => `<span>${esc(n)}</span>`).join('')}</div>` : '';

    box.innerHTML = `
      <div class="ai-plausibility-card ${review.overall_status === 'ok' ? 'is-ok' : 'needs-review'}">
        <div class="ai-plausibility-head">
          <span>✅ Plausibilitätsprüfung</span>
          <strong>${esc(status)}</strong>
        </div>
        <p>${esc(review.coverage_summary || 'Die Fragen wurden fachlich, didaktisch und auf Materialbezug geprüft.')}</p>
        ${issueHtml}
        ${noteHtml}
      </div>`;
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
      <input class="ai-question-difficulty" value="${Number(q.difficulty || .35)}" type="hidden">
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
      const res = await apiJson('/teacher/api/ai_wizard_suggest_options.php', { draft_id: Number(state.draftId || 0), question });
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
    await apiJson('/teacher/api/ai_wizard_save.php', { draft_id: Number(state.draftId || 0), payload });
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
      const res = await apiJson('/teacher/api/ai_wizard_image.php', { draft_id: Number(state.draftId || 0), image_prompt: $('#aiImagePrompt').value.trim() });
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
      if (!state.imagePath) {
        btn.textContent = 'Erstelle Quizbild...';
        try { await generateImage(false); } catch (e) {}
        btn.textContent = 'Veröffentliche...';
      }
      const res = await apiJson('/teacher/api/ai_wizard_publish.php', { draft_id: Number(state.draftId || 0), payload });
      toast('Quiz wurde veröffentlicht.');
      window.location.href = res.class_quizzes_url || '/teacher/quizzes.php';
    } catch (err) {
      toast(err.message || String(err));
      $('#aiPublishQuiz').disabled = false;
      $('#aiPublishQuiz').textContent = '🚀 Für Klasse veröffentlichen';
    }
  });

  $('#aiReviewCurriculumTopic')?.addEventListener('change', e => {
    populateReviewSubtopicSelect(e.target.value, '');
  });


  $$('input[name="mode"]').forEach(input => {
    input.addEventListener('change', () => updateExtraPromptPlaceholder());
  });

  $$('[data-prompt-example]').forEach(btn => {
    btn.addEventListener('click', () => {
      const field = $('#aiExtraPrompt');
      if (!field) return;
      const text = btn.dataset.promptExample || '';
      field.value = field.value.trim() ? (field.value.trim() + "\n" + text) : text;
      field.focus();
    });
  });


  $('#aiAnalysisCurriculumTopic')?.addEventListener('change', e => {
    populateAnalysisSubtopicSelect(e.target.value, '');
  });

  $('#aiAnalysisContentMode')?.addEventListener('change', e => {
    const mode = e.target.value;
    const strategy = $('#aiAnalysisStrategy');
    const requires = $('#aiAnalysisRequiresContext');
    if (strategy) {
      if (mode === 'content_source') strategy.value = 'content_questions';
      if (mode === 'self_contained_exercises') strategy.value = 'reuse_or_adapt_examples';
      if (mode === 'context_dependent_exercises') strategy.value = 'generate_similar_exercises';
    }
    if (requires) requires.value = mode === 'context_dependent_exercises' ? '1' : '0';
  });

  $('#aiConfirmAnalysis')?.addEventListener('click', async () => {
    if (!state.draftId) return;
    step(2);
    resetAnalysisRoute();
    setProgress(28, 'Analyse bestätigt. Fragen werden mit dieser Strategie erstellt…');
    startLoadingCopy();

    try {
      const confirmedAnalysis = readAnalysisReview();
      const res = await apiJson('/teacher/api/ai_wizard_confirm_analysis.php', {
        draft_id: Number(state.draftId || 0),
        analysis: confirmedAnalysis
      });
      if (res.analysis_route) showAnalysisRoute(res.analysis_route);

      const pollResult = await pollGeneration(state.draftId);
      if (pollResult === 'analysis_review') {
        stopLoadingCopy();
        step('analysis');
        return;
      }

      fillEditor();
      step(3);
      generateImage(false);
    } catch (err) {
      toast(err.message || String(err));
      const box = document.getElementById('aiWizardErrorBox');
      if (box) {
        box.classList.remove('d-none');
        box.textContent = err.message || String(err);
      }
      step('analysis');
    } finally {
      stopLoadingCopy();
    }
  });


})();
