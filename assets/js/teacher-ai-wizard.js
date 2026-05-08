(() => {
  const root = document.querySelector('.ai-wizard');
  if (!root) return;

  let state = { draftId: null, payload: null, imagePath: null, analysis: null, analysisRoute: null, sourceKind: null, previewQuestions: [] };
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

    const sourceKind = currentSourceKind();
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

  function findCurriculumTopic(topicId) {
    const id = String(topicId || '');
    if (!id) return null;

    if (curriculumState.topicMap && curriculumState.topicMap.has(id)) {
      return curriculumState.topicMap.get(id);
    }

    for (const domain of (curriculumState.domains || [])) {
      for (const topic of (domain.topics || [])) {
        if (String(topic.id) === id) {
          return topic;
        }
      }
    }

    return null;
  }

  function curriculumTopicLabel(topic) {
    if (!topic) return '';
    return topic.title
      || topic.title_short
      || topic.topic_title
      || topic.title_long
      || topic.description
      || topic.name
      || ('Thema #' + topic.id);
  }

  function curriculumSubtopicLabel(subtopic) {
    if (!subtopic) return '';
    return subtopic.title
      || subtopic.title_short
      || subtopic.subtopic_title
      || subtopic.title_long
      || subtopic.description
      || subtopic.name
      || ('Skill #' + subtopic.id);
  }


  function findCurriculumSubtopic(topic, subtopicId) {
    if (!topic || !subtopicId) return null;
    const id = String(subtopicId || '');
    return (topic.subtopics || []).find(sub => String(sub.id) === id) || null;
  }

  function selectedCurriculumContext() {
    const topicId = String((state.analysis && state.analysis.curriculum_topic_content_id) || $('#aiCurriculumTopicSelect')?.value || $('#aiAnalysisCurriculumTopic')?.value || '');
    const subtopicId = String((state.analysis && state.analysis.curriculum_topic_subtopic_id) || $('#aiCurriculumSubtopicSelect')?.value || $('#aiAnalysisCurriculumSubtopic')?.value || '');
    const topic = findCurriculumTopic(topicId);
    const subtopic = findCurriculumSubtopic(topic, subtopicId);
    const focusTitle = subtopic ? curriculumSubtopicLabel(subtopic) : curriculumTopicLabel(topic);
    const parentTitle = topic ? curriculumTopicLabel(topic) : '';
    const learningGoal = (subtopic && (subtopic.learning_goal || subtopic.title_long || subtopic.description))
      || (topic && (topic.learning_goal || topic.title_long || topic.description))
      || (state.analysis && ((state.analysis.content_map || [])[0]?.learning_goal || (state.analysis.topics || [])[0]))
      || '';
    const keywords = [];
    [subtopic, topic].forEach(item => {
      if (!item) return;
      ['keywords', 'aliases', 'alias_terms'].forEach(key => {
        const value = item[key];
        if (Array.isArray(value)) keywords.push(...value);
        else if (typeof value === 'string') keywords.push(...value.split(/[,;\n]/));
      });
    });
    return {
      topicId,
      subtopicId,
      topicTitle: parentTitle,
      focusTitle: focusTitle || parentTitle || 'gewähltes Lernziel',
      learningGoal: learningGoal || 'Fragen werden direkt am aktiven Lernziel ausgerichtet.',
      keywords: [...new Set(keywords.map(v => String(v).trim()).filter(Boolean))].slice(0, 8)
    };
  }



  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch]));
  }

  function currentSourceKind() {
    if (state.sourceKind === 'curriculum' || state.sourceKind === 'material') return state.sourceKind;
    const checked = document.querySelector('input[name="source_kind_choice"]:checked')?.value;
    return checked || $('#aiWizardSourceKind')?.value || 'material';
  }

  function updateStageLabels() {
    const isCurriculum = currentSourceKind() === 'curriculum';
    const labels = isCurriculum
      ? { source: '🎯 Lernziel', material: '🧭 Fokus', strategy: '🧩 Fragenblöcke', check: '✅ Qualitätscheck' }
      : { source: '📄 Quelle verstehen', material: '🧠 Materialtyp erkennen', strategy: '🧩 Aufgabenstrategie wählen', check: '✅ Antworten prüfen' };

    Object.entries(labels).forEach(([stage, label]) => {
      const el = $(`#aiStageTrack [data-stage="${stage}"]`);
      if (el) el.textContent = label;
    });
  }


  function setSelectOptionTexts(selectId, labels) {
    const select = $(selectId);
    if (!select) return;
    Object.entries(labels || {}).forEach(([value, label]) => {
      const option = select.querySelector(`option[value="${value}"]`);
      if (option) option.textContent = label;
    });
  }

  function updateWizardModeCopy() {
    const isCurriculum = currentSourceKind() === 'curriculum';
    const heroTitle = $('#aiWizardHeroTitle');
    if (heroTitle) heroTitle.textContent = isCurriculum
      ? 'Aus Lernziel wird ein spielbares Quiz'
      : 'Aus Material wird ein spielbares Quiz';

    const progressText = $('#aiWizardProgressText');
    if (progressText && !progressText.dataset.locked) {
      progressText.textContent = isCurriculum
        ? 'Lernziel wird gelesen und didaktisch geplant...'
        : 'Material wird gelesen und didaktisch sortiert...';
    }

    updateStageLabels();
  }

  function updateSourceKind(kind) {
    state.sourceKind = kind === 'curriculum' ? 'curriculum' : 'material';
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
    updateStageLabels();
    updateWizardModeCopy();
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
      (topic.subtopics || []).forEach(s => { sub.innerHTML += `<option value="${s.id}">${escapeHtml(curriculumSubtopicLabel(s))}</option>`; });
      if (preview) preview.innerHTML = `<strong>${escapeHtml(topic.title)}</strong><br>${escapeHtml(topic.title_long || topic.description || 'Dieses Thema wird als Grundlage für das Quiz verwendet.')}`;
    } else if (preview) {
      preview.textContent = 'Wähle ein Thema aus. Elevaro nutzt Kurz- und Langtitel, Lernziel, Keywords und Klassenkontext als Grundlage.';
    }
  }

  $$('.ai-source-kind-card input').forEach(input => input.addEventListener('change', () => updateSourceKind(input.value)));
  $('#aiCurriculumTopicSelect')?.addEventListener('change', updateSubtopics);


  function getLoadingTexts() {
    const subject = String(root.dataset.subjectLabel || '').toLowerCase();
    const sourceKind = currentSourceKind();
    const mode = document.querySelector('input[name="mode"]:checked')?.value || 'quiz';
    const goal = document.querySelector('input[name="material_goal"]:checked')?.value || '';

    if (mode === 'listening') {
      return [
        'Ich baue einen hörbaren Text mit altersgerechter Sprache…',
        'Wortschatz, Tempo und Verständnisfragen werden abgeglichen…',
        'Die Fragen werden so formuliert, dass sie ohne Bildkontext lösbar sind…',
        'Antwortoptionen werden auf eindeutige Hörverständnis-Logik geprüft…'
      ];
    }

    if (sourceKind === 'curriculum') {
      const ctx = selectedCurriculumContext();
      const items = [
        `Aktiver Fokus: ${ctx.focusTitle}`,
        ctx.learningGoal ? `Lernziel: ${ctx.learningGoal}` : '',
        ctx.keywords.length ? `Begriffe im Fokus: ${ctx.keywords.slice(0, 5).join(', ')}` : '',
        'Block 1 wird leicht und motivierend aufgebaut…',
        'Block 2 vertieft das Lernziel mit etwas anspruchsvolleren Fragen…',
        'Block 3 prüft Anwendung und sichere Unterscheidung im aktiven Fokus…'
      ].filter(Boolean);
      return items.length ? items : [
        'Ich lese Lerninhalt, Klasse, Fach und deinen Zusatzwunsch…',
        'Der Schwerpunkt wird aus Lehrplan und Prompt abgeleitet…',
        'Die Fragen werden nach Kompetenz und Schwierigkeit sortiert…',
        'Antworten und Erklärungen werden auf Eindeutigkeit geprüft…'
      ];
    }

    if (subject.includes('engl') || subject.includes('franz') || subject.includes('sprach') || goal === 'grammar' || goal === 'vocabulary') {
      return [
        'Ich erkenne Wortschatz, Grammatikmuster und Aufgabenmechanik…',
        'Übersetzungen und Zusatzhinweise werden nur übernommen, wenn sie im Original vorkommen…',
        'Bei Wortpaaren wird der Gesamtpool als Kontext berücksichtigt…',
        'Distraktoren werden geprüft, ohne die Aufgabe unnötig einfacher zu machen…'
      ];
    }

    if (subject.includes('math') || subject.includes('mathe')) {
      return [
        'Ich erkenne Rechenart, Einheiten und benötigte Zwischenschritte…',
        'Ergebnisse und Antwortoptionen werden rechnerisch gegengeprüft…',
        'Falsche Antworten orientieren sich an typischen Schülerfehlern…',
        'Die Lösung wird eindeutig und ohne Ratespiel aufgebaut…'
      ];
    }


    return [
      'Ich lese die Quelle und erkenne, was später sichtbar im Quiz stehen muss…',
      'Materialtyp und Aufgabenlogik werden eingeordnet…',
      'Fragen werden aus dem vorhandenen Kontext gebaut, nicht frei dazu erfunden…',
      'Antwortoptionen und Erklärungen werden auf Eindeutigkeit geprüft…'
    ];
  }

  let loadingTimer = null;
  let tickerTimer = null;

  function setStage(activeStage) {
    const order = ['source', 'material', 'strategy', 'check'];
    const activeIndex = Math.max(0, order.indexOf(activeStage));
    $$('#aiStageTrack [data-stage]').forEach(el => {
      const index = order.indexOf(el.dataset.stage);
      el.classList.toggle('is-done', index >= 0 && index < activeIndex);
      el.classList.toggle('is-active', el.dataset.stage === activeStage);
    });
  }

  function setTicker(headline, items) {
    const headlineEl = $('#aiTickerHeadline');
    const textEl = $('#aiTickerText');
    const cleanItems = (items || []).filter(Boolean).map(v => String(v).trim()).filter(Boolean);
    if (headlineEl && headline) headlineEl.textContent = headline;
    if (!textEl || !cleanItems.length) return;

    if (tickerTimer) clearInterval(tickerTimer);
    let i = 0;
    textEl.textContent = cleanItems[0];
    textEl.classList.remove('is-changing');

    if (cleanItems.length > 1) {
      tickerTimer = setInterval(() => {
        i = (i + 1) % cleanItems.length;
        textEl.classList.add('is-changing');
        setTimeout(() => {
          textEl.textContent = cleanItems[i];
          textEl.classList.remove('is-changing');
        }, 150);
      }, 2900);
    }
  }

  function startLoadingCopy() {
    const texts = getLoadingTexts();
    let i = 0;
    setProgress(10, texts[0]);
    setTicker(currentSourceKind() === 'curriculum' ? 'Lernziel-Ticker' : 'Gerade läuft', texts);
    if (loadingTimer) clearInterval(loadingTimer);
    loadingTimer = setInterval(() => {
      i = (i + 1) % texts.length;
      setProgress(Math.min(82, 12 + i * 9), texts[i]);
    }, 2600);
  }

  function stopLoadingCopy() {
    if (loadingTimer) clearInterval(loadingTimer);
    loadingTimer = null;
  }


  function showAnalysisRoute(route) {
    if (!route) return;
    const isCurriculum = currentSourceKind() === 'curriculum';
    if (isCurriculum) {
      const ctx = selectedCurriculumContext();
      const steps = [
        `Aktiver Fokus: ${ctx.focusTitle}`,
        ctx.learningGoal ? `Lernziel: ${ctx.learningGoal}` : '',
        ...(route.steps || []).slice(0, 2)
      ].filter(Boolean);
      setTicker('Lernziel eingeordnet', steps);
      return;
    }
    const headline = route.headline || 'Material erkannt';
    const steps = route.steps || [];
    setTicker(headline, steps.length ? steps : ['Die KI hat Materialtyp und Aufgabenstrategie erkannt.']);
  }

  function resetAnalysisRoute() {
    setTicker('Gerade läuft', getLoadingTexts());
  }


  function setProgress(percent, label) {
    const bar = document.querySelector('#aiWizardProgressBar');
    const labelEl = $('#aiWizardProgressText');
    const generatingCard = document.querySelector('.ai-generating-card');
    const labelText = String(label || '');
    const lowerLabel = labelText.toLowerCase();
    const isPlausibility = lowerLabel.includes('plausibilität') || lowerLabel.includes('fachliche richtigkeit') || lowerLabel.includes('antwort');
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

    if (safePercent < 25) setStage('source');
    else if (safePercent < 50) setStage('material');
    else if (safePercent < 88) setStage('strategy');
    else setStage('check');

    if (labelEl && label) labelEl.textContent = label;
  }


  function updateQuestionTickerFromPreview(res) {
    if (!Array.isArray(state.previewQuestions) || !state.previewQuestions.length) return;
    const blockInfo = res && res.question_block && res.question_block_total
      ? `Fragenblock ${res.question_block}/${res.question_block_total}`
      : 'Erste Fragen entstehen';
    const items = state.previewQuestions
      .slice(-5)
      .map(q => `„${String(q).replace(/\s+/g, ' ').slice(0, 140)}“`);
    setTicker(blockInfo, items);
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
      if (res.source_kind) state.sourceKind = res.source_kind;
      if (Array.isArray(res.preview_questions)) {
        state.previewQuestions = res.preview_questions.filter(Boolean);
        updateQuestionTickerFromPreview(res);
      }
      if (res.needs_analysis_review) {
        state.analysis = res.analysis || {};
        state.analysisRoute = res.analysis_route || null;
        if (res.analysis_route) showAnalysisRoute(res.analysis_route);
        renderAnalysisReview(state.analysis, state.analysisRoute);
        return 'analysis_review';
      }

      if (res.done) {
        if (res.source_kind) state.sourceKind = res.source_kind;
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
      const selectedSourceKind = document.querySelector('input[name="source_kind_choice"]:checked')?.value || 'material';
      updateSourceKind(selectedSourceKind);
      const sourceKind = currentSourceKind();
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
      if (json.source_kind) state.sourceKind = json.source_kind;

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
        option.textContent = curriculumTopicLabel(topic);
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
      option.textContent = curriculumSubtopicLabel(sub);
      subtopicSelect.appendChild(option);
    });
    if (selectedSubtopic) subtopicSelect.value = String(selectedSubtopic);
  }

  function applyAnalysisReviewMode() {
    const isCurriculum = currentSourceKind() === 'curriculum';
    const box = $('#aiAnalysisReviewBox');
    if (box) {
      box.classList.toggle('is-curriculum-review', isCurriculum);
      box.classList.toggle('is-material-review', !isCurriculum);
    }

    const contextCard = $('[data-analysis-card="visible-context"]');
    if (contextCard) contextCard.classList.toggle('d-none', isCurriculum);

    const copy = isCurriculum ? {
      kicker: '🎯 Lerninhalt prüfen',
      title: 'Elevaro plant dein Quiz aus dem Lerninhalt',
      intro: 'Prüfe kurz, ob Schwerpunkt, Kompetenz und Quizstrategie passen. Es wurde kein Material hochgeladen.',
      materialType: 'Quizgrundlage',
      contentMode: 'Aufgabenausrichtung',
      strategy: 'Quizstrategie',
      skills: 'Geplante Kompetenzen',
      deps: 'Zusatzwünsche / Schwerpunkt',
      depsHelp: 'Optional: Was soll die KI zusätzlich beachten? Zum Beispiel Wortschatz, Niveau oder Themenfokus.'
    } : {
      kicker: '🧭 Analyse prüfen',
      title: 'Elevaro hat dein Material didaktisch eingeordnet',
      intro: 'Prüfe kurz, ob Materialtyp, Aufgaben-Kontext und Strategie stimmen. Erst danach werden Fragen generiert.',
      materialType: 'Materialtyp',
      contentMode: 'Aufgaben-Kontext',
      strategy: 'Generierungsstrategie',
      skills: 'Erkannte Kompetenzen',
      deps: 'Abhängigkeiten / Kontext',
      depsHelp: 'Was müsste sichtbar sein, damit die Originalaufgabe lösbar wäre?'
    };

    const materialTypeCard = $('[data-analysis-card="material-type"]');
    if (materialTypeCard) materialTypeCard.classList.toggle('d-none', isCurriculum);

    if (isCurriculum) {
      setSelectOptionTexts('#aiAnalysisContentMode', {
        content_source: 'Quiz aus Lernziel / Lehrplaninhalt',
        self_contained_exercises: 'Übungsfragen zum ausgewählten Skill',
        context_dependent_exercises: 'Transferfragen zum Lernziel'
      });
      setSelectOptionTexts('#aiAnalysisStrategy', {
        content_questions: 'Lernziel direkt abfragen',
        reuse_or_adapt_examples: 'Kompetenz abwechslungsreich üben',
        generate_similar_exercises: 'Neue Aufgaben zum Skill erzeugen',
        listening_text_questions: 'Hörtext passend zum Lernziel erzeugen'
      });
    } else {
      setSelectOptionTexts('#aiAnalysisContentMode', {
        content_source: 'Lernstoff: Fragen zum Inhalt',
        self_contained_exercises: 'Selbstlösbare Übung: Beispiele nutzen/variieren',
        context_dependent_exercises: 'Kontextabhängig: Kontext einbauen oder ähnliche Aufgaben'
      });
      setSelectOptionTexts('#aiAnalysisStrategy', {
        content_questions: 'Fragen zum tatsächlichen Stoff',
        reuse_or_adapt_examples: 'Beispiele übernehmen oder leicht variieren',
        generate_similar_exercises: 'Neue ähnliche Aufgaben erzeugen',
        listening_text_questions: 'Neuen Hörtext + Verständnisfragen erzeugen'
      });
    }

    const mapping = {
      aiAnalysisKicker: copy.kicker,
      aiAnalysisTitle: copy.title,
      aiAnalysisIntro: copy.intro,
      aiAnalysisMaterialTypeLabel: copy.materialType,
      aiAnalysisContentModeLabel: copy.contentMode,
      aiAnalysisStrategyLabel: copy.strategy,
      aiAnalysisSkillsLabel: copy.skills,
      aiAnalysisDependenciesLabel: copy.deps,
      aiAnalysisDependenciesHelp: copy.depsHelp
    };
    Object.entries(mapping).forEach(([id, text]) => {
      const el = document.getElementById(id);
      if (el) el.textContent = text;
    });
  }

  function renderAnalysisReview(analysis, route) {
    applyAnalysisReviewMode();
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
    const isCurriculum = currentSourceKind() === 'curriculum';
    const ctx = selectedCurriculumContext();
    if (isCurriculum) {
      const skills = [
        ctx.focusTitle,
        ctx.learningGoal,
        ...(state.analysis.detected_skills || []),
        ...(state.analysis.topics || [])
      ].filter(Boolean);
      $('#aiAnalysisSkills').value = joinListInput([...new Set(skills)].slice(0, 8));
      const deps = [
        ctx.topicTitle ? `Kontext: ${ctx.topicTitle}` : '',
        ctx.keywords.length ? `Fokusbegriffe: ${ctx.keywords.join(', ')}` : '',
        ...(state.analysis.detected_dependencies || [])
      ].filter(Boolean);
      $('#aiAnalysisDependencies').value = joinListInput(deps.slice(0, 5));
    } else {
      $('#aiAnalysisSkills').value = joinListInput(state.analysis.detected_skills || state.analysis.topics || []);
      $('#aiAnalysisDependencies').value = joinListInput(state.analysis.detected_dependencies || []);
    }

    const headline = $('#aiAnalysisHeadline');
    const steps = $('#aiAnalysisSteps');
    if (headline && steps) {
      const routePayload = route || {};
      if (isCurriculum) {
        headline.textContent = 'Lernziel-Fokus';
        const plans = (state.analysis.question_plan || []).slice(0, 3);
        steps.innerHTML = `
          <li class="ai-focus-item is-primary"><span>🎯</span><div><strong>${escapeHtml(ctx.focusTitle)}</strong><small>${escapeHtml(ctx.learningGoal)}</small></div></li>
          ${ctx.keywords.length ? `<li class="ai-focus-item"><span>🏷️</span><div><strong>Fokusbegriffe</strong><small>${escapeHtml(ctx.keywords.slice(0, 6).join(', '))}</small></div></li>` : ''}
          ${plans.map((plan, idx) => `<li class="ai-focus-item"><span>${idx + 1}</span><div><strong>Block ${idx + 1}: ${escapeHtml(plan.difficulty || ['leicht','mittel','anspruchsvoller'][idx] || '')}</strong><small>${escapeHtml(plan.focus || 'Fragen passend zum aktiven Lernziel')}</small></div></li>`).join('')}
        `;
      } else {
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
      curriculum_topic_content_id: $('#aiAnalysisCurriculumTopic')?.value ? Number($('#aiAnalysisCurriculumTopic').value) : 0,
      curriculum_topic_subtopic_id: $('#aiAnalysisCurriculumSubtopic')?.value ? Number($('#aiAnalysisCurriculumSubtopic').value) : 0
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
        option.textContent = curriculumTopicLabel(topic);
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
      option.textContent = curriculumSubtopicLabel(sub);
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


  updateStageLabels();
  updateWizardModeCopy();
  startPromptPlaceholderRotation();
})();
