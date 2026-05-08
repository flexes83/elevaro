<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/includes/teacher_ai_wizard.php';

$class = teacher_selected_class();
if (!$class) {
    teacher_header('KI-Quiz-Wizard', 'Lege zuerst eine Klasse an.');
    echo '<div class="card card-soft"><div class="card-body p-4"><a class="btn btn-primary" href="classes.php">Klasse anlegen</a></div></div>';
    teacher_footer();
    exit;
}

$classId = (int)$class['id'];
$classLabel = teacher_class_label($class);
$subjectLabel = elevaro_teacher_ai_subject_label($class['subject_code'] ?? '');

teacher_header('KI-Quiz-Wizard', 'Aus Unterrichtsmaterial in wenigen Schritten ein Klassenquiz erstellen.');
?>
<link href="/assets/css/teacher-ai-wizard.css" rel="stylesheet">

<div class="ai-wizard" data-class-id="<?= (int)$classId ?>">
  <div class="ai-wizard-hero">
    <div>
      <span class="ai-wizard-kicker">✨ Neuer Lehrer-Assistent</span>
      <h2>Aus Material wird ein spielbares Quiz</h2>
      <p>
        Klasse: <strong><?= teacher_h($classLabel) ?></strong> · Fach: <strong><?= teacher_h($subjectLabel) ?></strong>
        <?php if (!empty($class['grade'])): ?> · Klasse <?= (int)$class['grade'] ?><?php endif; ?>
      </p>
    </div>
    <div class="ai-wizard-hero-orb">AI</div>
  </div>

  <div class="ai-wizard-steps" aria-label="Wizard Schritte">
    <button class="is-active" data-step-indicator="1">1 Quelle</button>
    <button data-step-indicator="2">2 KI erstellt</button>
    <button data-step-indicator="3">3 Review</button>
    <button data-step-indicator="4">4 Veröffentlichen</button>
  </div>

  <section class="ai-wizard-panel is-active" data-step="1">
    <form id="aiWizardSourceForm" enctype="multipart/form-data">
      <input type="hidden" name="class_id" value="<?= (int)$classId ?>">
      <input type="hidden" name="source_kind" id="aiWizardSourceKind" value="material">

      <div class="ai-step-one-layout">
        <div class="ai-step-card">
          <div class="ai-step-head">
            <span>1</span>
            <div>
              <h3>Quelle wählen</h3>
              <p>Woraus soll dein Quiz entstehen?</p>
            </div>
          </div>

          <div class="ai-choice-stack">
            <label class="ai-source-kind-card is-selected">
              <input type="radio" name="source_kind_choice" value="material" checked>
              <span class="ai-mode-icon">📄</span>
              <strong>Material hochladen</strong>
              <small>PDF, Foto, Arbeitsblatt, Buchseite oder eigener Text.</small>
            </label>

            <label class="ai-source-kind-card">
              <input type="radio" name="source_kind_choice" value="curriculum">
              <span class="ai-mode-icon">🎯</span>
              <strong>Lerninhalt wählen</strong>
              <small>Quiz ohne Upload aus den hinterlegten Themen dieser Klasse erstellen.</small>
            </label>
          </div>

          <div class="ai-source-detail ai-material-source is-active" id="aiMaterialSourceBox">
            <div class="ai-field-block">
              <label class="form-label fw-bold">Material hochladen</label>
              <input class="form-control form-control-lg" type="file" name="source_files[]" multiple accept="application/pdf,image/jpeg,image/png,image/webp">
              <div class="form-text">PDF, JPG, PNG oder WebP. Fotos vom Arbeitsblatt oder Buchseite sind okay.</div>
            </div>

            <div class="ai-field-block">
              <label class="form-label fw-bold">Text / Notizen / Aufgabenstellung</label>
              <textarea class="form-control" name="source_text" rows="6" placeholder="Optional: Text einkopieren oder kurz beschreiben, was aus dem Material entstehen soll."></textarea>
            </div>
          </div>

          <div class="ai-source-detail ai-curriculum-source" id="aiCurriculumSourceBox">
            <div class="ai-field-block">
              <label class="form-label fw-bold">Lerninhalt</label>
              <select class="form-select form-select-lg" id="aiCurriculumTopicSelect" name="curriculum_topic_content_id">
                <option value="">Themen werden geladen…</option>
              </select>
            </div>

            <div class="ai-field-block">
              <label class="form-label fw-bold">Unterthema / Skill <span class="text-muted fw-normal">optional</span></label>
              <select class="form-select" id="aiCurriculumSubtopicSelect" name="curriculum_topic_subtopic_id">
                <option value="">Ganzes Thema verwenden</option>
              </select>
            </div>

            <div class="ai-curriculum-preview" id="aiCurriculumPreview">
              Wähle einen Lerninhalt aus. Elevaro nutzt Thema, Skill, Lernziel, Keywords und Klassenkontext als Grundlage.
            </div>
          </div>
        </div>

        <div class="ai-step-card">
          <div class="ai-step-head">
            <span>2</span>
            <div>
              <h3>Ziel wählen</h3>
              <p>Was soll die KI daraus machen?</p>
            </div>
          </div>

          <div class="ai-mode-targets">
            <label class="ai-mode-card is-selected">
              <input type="radio" name="mode" value="quiz" checked>
              <span class="ai-mode-icon">📝</span>
              <strong>Normales Quiz</strong>
              <small>15 Multiple-Choice-Fragen für den Klassenraum.</small>
            </label>

            <label class="ai-mode-card">
              <input type="radio" name="mode" value="listening">
              <span class="ai-mode-icon">🎧</span>
              <strong>Listening + Comprehension</strong>
              <small>5 Hörabschnitte mit je einer Verständnisfrage.</small>
            </label>
          </div>

          <div class="ai-material-goal-box" id="aiMaterialGoalBox">
            <label class="form-label fw-bold mt-3">Was soll aus dem Material entstehen?</label>
            <div class="ai-intent-grid">
              <label class="ai-intent-card is-selected">
                <input type="radio" name="material_goal" value="auto" checked>
                <strong>KI entscheiden lassen</strong>
                <small>Erkennt Lesetext, Arbeitsblatt, Vokabelliste oder Grammatikübung.</small>
              </label>
              <label class="ai-intent-card">
                <input type="radio" name="material_goal" value="content">
                <strong>Inhalt abfragen</strong>
                <small>Für Sachtexte, Lesetexte oder Themenblätter.</small>
              </label>
              <label class="ai-intent-card">
                <input type="radio" name="material_goal" value="practice">
                <strong>Ähnliche Übungen</strong>
                <small>Für Arbeitsblätter, Lückentexte oder Aufgabenformate.</small>
              </label>
              <label class="ai-intent-card">
                <input type="radio" name="material_goal" value="vocabulary">
                <strong>Vokabeltraining</strong>
                <small>Für Fremdsprachen, Wortlisten und Satzergänzungen.</small>
              </label>
              <label class="ai-intent-card">
                <input type="radio" name="material_goal" value="grammar">
                <strong>Grammatiktraining</strong>
                <small>Für Pronomen, Zeiten, Satzbau und ähnliche Strukturen.</small>
              </label>
            </div>
          </div>

          <div class="ai-curriculum-goal-box d-none" id="aiCurriculumGoalBox">
            <div class="ai-goal-hint">
              <strong>Quiz aus Lerninhalt</strong>
              <span>Die KI erstellt ein Quiz passend zu Klasse, Fach, Lerninhalt und optionalem Skill. Im Zusatzfeld kannst du optional Wortschatz, Schwerpunkt oder Niveau steuern.</span>
            </div>
          </div>

          <div class="ai-field-block mt-4">
            <label class="form-label fw-bold">Zusatzwunsch an die KI <span class="text-muted fw-normal">optional</span></label>
            <textarea class="form-control ai-animated-placeholder" id="aiExtraPrompt" name="extra_prompt" rows="5" data-placeholder-mode="default" placeholder="z. B. eher leichte Fragen, Fokus auf Vokabelverständnis, keine Jahreszahlen abfragen, Niveau A1..."></textarea>
            <div class="ai-prompt-examples" id="aiPromptExamples">
              <span>Beispiele:</span>
              <button type="button" data-prompt-example="Verwende die Vokabeln „mother“, „father“, „sister“ und „brother“.">Familienvokabeln</button>
              <button type="button" data-prompt-example="Erstelle einfache Fragen auf Niveau A1 mit kurzen Sätzen.">Niveau A1</button>
              <button type="button" data-prompt-example="Nutze Alltagssituationen aus Schule, Familie und Freizeit.">Alltagssituationen</button>
            </div>
          </div>
        </div>
      </div>

      <div class="ai-step-info">
        <strong>Hinweis:</strong>
        Die KI erstellt einen Entwurf. Vor Veröffentlichung kannst du Titel, Beschreibung, Fragen, Antworten und Erklärungen prüfen und bearbeiten.
      </div>

      <div class="ai-step-actions">
        <a class="btn btn-light btn-lg" href="quizzes.php?class_id=<?= (int)$classId ?>">Abbrechen</a>
        <button class="btn btn-primary btn-lg" type="submit">✨ Quiz mit KI erstellen</button>
      </div>
    </form>
  </section>

  <section class="ai-wizard-panel" data-step="2">
    <div class="ai-generating-card">
      <div class="ai-orbit">
        <span></span><span></span><span></span>
      </div>
      <h3 id="aiGeneratingTitle">Elevaro baut dein Quiz</h3>
      <p id="aiWizardProgressText">Lernziel wird interpretiert und didaktisch sortiert...</p>
      <div class="ai-route-card d-none" id="aiRouteCard">
        <strong id="aiRouteHeadline">Material wird erkannt</strong>
        <ul id="aiRouteSteps">
          <li>Ich analysiere, ob es ein Lerntext oder ein Übungsformat ist.</li>
        </ul>
      </div>
      <div class="ai-progress"><i></i></div>
      <div class="ai-progress-list">
        <span data-loading-copy>🎯 Lernziel verstehen</span>
        <span data-loading-copy>🧠 Kompetenzen ableiten</span>
        <span data-loading-copy>🧩 Quizstrategie wählen</span>
        <span data-loading-copy>✅ Antworten prüfen</span>
      </div>
      <div id="aiWizardErrorBox" class="alert alert-danger d-none mt-4 text-start"></div>
    </div>
  </section>


  <section class="ai-wizard-panel" data-step="analysis">
    <div class="ai-analysis-review">
      <div class="ai-analysis-review-head">
        <span class="ai-wizard-kicker" id="aiAnalysisKicker">🧭 Einordnung prüfen</span>
        <h3 id="aiAnalysisTitle">Elevaro hat deinen Lerninhalt didaktisch eingeordnet</h3>
        <p id="aiAnalysisIntro">Prüfe kurz, ob Lernziel, Kompetenz-Fokus und Quizstrategie stimmen. Erst danach werden Fragen generiert.</p>
      </div>

      <div class="ai-analysis-grid">
        <div class="ai-analysis-card">
          <label id="aiAnalysisMaterialTypeLabel">Lernbasis</label>
          <select class="form-select" id="aiAnalysisMaterialType">
            <option value="reading_text">Lerntext / Sachtext</option>
            <option value="worksheet">Arbeitsblatt</option>
            <option value="vocabulary_list">Vokabelliste</option>
            <option value="grammar_exercise">Grammatikübung</option>
            <option value="mixed">Gemischtes Material</option>
            <option value="image_based_task">Bild-/Materialaufgabe</option>
          </select>
        </div>

        <div class="ai-analysis-card">
          <label>Aufgaben-Kontext</label>
          <select class="form-select" id="aiAnalysisContentMode">
            <option value="content_source">Lernstoff: Fragen zum Inhalt</option>
            <option value="self_contained_exercises">Selbstlösbare Übung: Beispiele nutzen/variieren</option>
            <option value="context_dependent_exercises">Kontextabhängig: Kontext einbauen oder ähnliche Aufgaben</option>
          </select>
        </div>

        <div class="ai-analysis-card">
          <label>Generierungsstrategie</label>
          <select class="form-select" id="aiAnalysisStrategy">
            <option value="content_questions">Fragen zum tatsächlichen Stoff</option>
            <option value="reuse_or_adapt_examples">Beispiele übernehmen oder leicht variieren</option>
            <option value="generate_similar_exercises">Neue ähnliche Aufgaben erzeugen</option>
            <option value="listening_text_questions">Neuen Hörtext + Verständnisfragen erzeugen</option>
          </select>
        </div>

        <div class="ai-analysis-card">
          <label>Benötigt sichtbaren Kontext?</label>
          <select class="form-select" id="aiAnalysisRequiresContext">
            <option value="0">Nein, ohne Originalmaterial lösbar</option>
            <option value="1">Ja, Kontext wäre sonst nicht sichtbar</option>
          </select>
        </div>
      </div>

      <div class="ai-analysis-summary-card">
        <strong id="aiAnalysisHeadline">Analyse wird geladen…</strong>
        <ul id="aiAnalysisSteps"></ul>
      </div>

      <div class="ai-analysis-edit-grid">
        <div>
          <label class="form-label fw-bold">Erkannte Kompetenzen</label>
          <textarea class="form-control" id="aiAnalysisSkills" rows="3" placeholder="z. B. months, ordinal numbers, dates"></textarea>
          <div class="form-text">Eine Kompetenz pro Zeile oder kommagetrennt.</div>
        </div>
        <div>
          <label class="form-label fw-bold">Abhängigkeiten / Kontext</label>
          <textarea class="form-control" id="aiAnalysisDependencies" rows="3" placeholder="z. B. Bild, Tabelle, rechte Lösungsspalte"></textarea>
          <div class="form-text">Was müsste sichtbar sein, damit die Originalaufgabe lösbar wäre?</div>
        </div>
      </div>

      <div class="ai-analysis-learning-box mt-4">
        <label class="form-label fw-bold">Lerninhalt-Zuordnung</label>
        <select class="form-select" id="aiAnalysisCurriculumTopic">
          <option value="">KI-Zuordnung verwenden</option>
        </select>
        <select class="form-select mt-2" id="aiAnalysisCurriculumSubtopic">
          <option value="">Ganzes Thema</option>
        </select>
      </div>

      <div class="d-flex justify-content-between gap-2 flex-wrap mt-4">
        <button class="btn btn-light btn-lg" type="button" data-back-to-step="1">Zurück</button>
        <button class="btn btn-primary btn-lg" id="aiConfirmAnalysis" type="button">Analyse bestätigen & Fragen erstellen</button>
      </div>
    </div>
  </section>

  <section class="ai-wizard-panel" data-step="3">
    <div class="row g-4">
      <div class="col-xl-4">
        <div class="card card-soft sticky-lg-top" style="top:24px"><div class="card-body p-4">
          <h3 class="h4 fw-bold">Quiz-Entwurf</h3>
          <input type="hidden" id="aiDraftId">

          <label class="form-label fw-bold">Titel</label>
          <input class="form-control" id="aiQuizTitle">

          <label class="form-label fw-bold mt-3">Beschreibung</label>
          <textarea class="form-control" id="aiQuizDescription" rows="5"></textarea>

          <div class="ai-review-mapping-box mt-3">
            <label class="form-label fw-bold">Lerninhalt-Zuordnung</label>
            <select class="form-select" id="aiReviewCurriculumTopic">
              <option value="">Automatisch zuordnen</option>
            </select>
            <select class="form-select mt-2" id="aiReviewCurriculumSubtopic">
              <option value="">Ganzes Thema</option>
            </select>
            <div class="form-text">Falls die automatische Zuordnung scheitert, wähle hier den passenden Lerninhalt manuell.</div>
          </div>

          <div id="aiListeningBox" class="mt-3 d-none">
            <label class="form-label fw-bold">Listening-Zusammenfassung</label>
            <textarea class="form-control" id="aiListeningText" rows="5"></textarea>
            <div class="form-text">Die eigentlichen Hörabschnitte stehen direkt bei den Fragen.</div>
          </div>

          <textarea class="d-none" id="aiImagePrompt" rows="1" aria-hidden="true"></textarea>
          <small class="d-none" data-debug-image-prompt></small>

          <div class="ai-image-preview mt-3" id="aiImagePreview">
            <span>🎨</span>
            <p>Bild wird im Hintergrund erstellt.</p>
          </div>

          <button class="btn btn-outline-primary w-100 mt-3" id="aiRegenerateImage" type="button">🎨 Bild neu generieren</button>
        </div></div>
      </div>
      <div class="col-xl-8">
        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
          <div>
            <h3 class="h4 fw-bold mb-1">Fragen prüfen</h3>
            <p class="text-muted mb-0">Du kannst Fragen löschen, ändern oder ergänzen.</p>
          </div>
          <button class="btn btn-light" id="aiAddQuestion" type="button">+ Frage hinzufügen</button>
        </div>
        <div id="aiQuestionEditor" class="ai-question-editor"></div>

        <div class="ai-custom-question-card mt-4" id="aiCustomQuestionCard">
          <div class="ai-custom-question-head">
            <span>➕ Eigene Frage ergänzen</span>
            <small>Formuliere grob vor – Elevaro macht daraus eine saubere Quizfrage mit 4 Antworten.</small>
          </div>
          <textarea class="form-control" id="aiCustomQuestionText" rows="3" placeholder="z. B. Warum ist der Schwarzwald so waldreich? Oder: Was bedeutet ‚deciduous forest‘ auf Deutsch?"></textarea>
          <div class="ai-custom-question-actions">
            <button class="btn btn-outline-primary" id="aiCustomQuestionGenerate" type="button">✨ Frage ausarbeiten & Antworten erstellen</button>
          </div>
        </div>

        <details class="ai-debug-prompt mt-4 d-none" id="aiPromptDebugBox">
          <summary>Prompt-Debug anzeigen</summary>
          <pre id="aiPromptDebugCode"></pre>
        </details>

        <div class="d-flex justify-content-end gap-2 flex-wrap mt-4">
          <button class="btn btn-light btn-lg" type="button" data-back-to-step="1">← Zurück</button>
          <button class="btn btn-primary btn-lg" id="aiSaveReview" type="button">Weiter zur Veröffentlichung</button>
        </div>
      </div>
    </div>
  </section>

  <section class="ai-wizard-panel" data-step="4">
    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card card-soft"><div class="card-body p-4">
          <h3 class="h4 fw-bold">Veröffentlichen</h3>
          <p class="text-muted">Das Quiz wird deiner Klasse zugeordnet. Bei Listening wird beim Veröffentlichen zusätzlich die Audiodatei mit ElevenLabs erstellt.</p>
          <div class="ai-publish-summary" id="aiPublishSummary"></div>
          <button class="btn btn-success btn-lg" id="aiPublishQuiz" type="button">🚀 Für Klasse veröffentlichen</button>
        </div></div>
      </div>
      <div class="col-lg-5">
        <div class="ai-share-card">
          <span>🌍</span>
          <h3>Später mehr Reichweite?</h3>
          <p>Nach der Veröffentlichung kannst du das Quiz zusätzlich für die Plattform vorschlagen. So profitieren andere Lehrkräfte – und du sammelst Community-Credits.</p>
          <strong>Idee: 10 veröffentlichte Community-Quizzes = 1 Gratismonat.</strong>
          <small>Das ist zunächst nur vorbereitet und wird nicht automatisch geteilt.</small>
        </div>
      </div>
    </div>
  </section>
</div>

<script src="/assets/js/teacher-ai-wizard.js"></script>
<?php teacher_footer(); ?>
