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

      <div class="row g-4">
        <div class="col-lg-7">
          <div class="card card-soft h-100"><div class="card-body p-4">
            <h3 class="h4 fw-bold">1. Quelle auswählen</h3>
            <p class="text-muted">Wähle aus, ob dein Quiz aus eigenem Material oder direkt aus einem Lehrplanthema entstehen soll.</p>

            <label class="form-label fw-bold mt-2">Quiz-Quelle</label>
            <div class="ai-source-kind-grid">
              <label class="ai-source-kind-card is-selected">
                <input type="radio" name="source_kind_choice" value="material" checked>
                <span class="ai-mode-icon">📄</span>
                <strong>Material hochladen</strong>
                <small>PDF, Arbeitsblatt, Foto oder eigener Text</small>
              </label>
              <label class="ai-source-kind-card">
                <input type="radio" name="source_kind_choice" value="curriculum">
                <span class="ai-mode-icon">🎯</span>
                <strong>Lehrplanthema wählen</strong>
                <small>Quiz ohne Upload aus deiner Klassenstufe erstellen</small>
              </label>
            </div>

            <div class="ai-curriculum-source d-none" id="aiCurriculumSourceBox">
              <label class="form-label fw-bold mt-4">Lehrplanthema</label>
              <select class="form-select form-select-lg" id="aiCurriculumTopicSelect" name="curriculum_topic_content_id">
                <option value="">Themen werden geladen…</option>
              </select>
              <label class="form-label fw-bold mt-3">Unterthema / Skill <span class="text-muted fw-normal">optional</span></label>
              <select class="form-select" id="aiCurriculumSubtopicSelect" name="curriculum_topic_subtopic_id">
                <option value="">Ganzes Thema verwenden</option>
              </select>
              <div class="ai-curriculum-preview" id="aiCurriculumPreview">
                Wähle ein Thema aus. Elevaro nutzt Kurz- und Langtitel, Lernziel, Keywords und Klassenkontext als Grundlage.
              </div>
            </div>

            <div class="ai-material-source" id="aiMaterialSourceBox">

            <div class="ai-source-modes">
              <label class="ai-mode-card is-selected">
                <input type="radio" name="mode" value="quiz" checked>
                <span class="ai-mode-icon">📝</span>
                <strong>Normales Quiz</strong>
                <small>Multiple Choice aus deinem Material</small>
              </label>
              <label class="ai-mode-card">
                <input type="radio" name="mode" value="listening">
                <span class="ai-mode-icon">🎧</span>
                <strong>Listening + Comprehension</strong>
                <small>Sprechertext + Fragen in der Zielsprache</small>
              </label>
            </div>

            <label class="form-label fw-bold mt-4">Was soll aus dem Material entstehen?</label>
            <div class="ai-intent-grid">
              <label class="ai-intent-card is-selected">
                <input type="radio" name="material_goal" value="auto" checked>
                <strong>KI entscheiden lassen</strong>
                <small>Erkennt automatisch, ob es ein Lesetext, Arbeitsblatt, Vokabelliste oder Grammatikübung ist.</small>
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

            <label class="form-label fw-bold mt-4">Material hochladen</label>
            <input class="form-control form-control-lg" type="file" name="source_files[]" multiple accept="application/pdf,image/jpeg,image/png,image/webp">
            <div class="form-text">PDF, JPG, PNG oder WebP. Fotos vom Arbeitsblatt oder Buchseite sind okay.</div>

            <label class="form-label fw-bold mt-4">Text / Notizen / Aufgabenstellung</label>
            <textarea class="form-control" name="source_text" rows="8" placeholder="Hier kannst du den relevanten Text einkopieren oder kurz beschreiben, was aus dem Material abgefragt werden soll."></textarea>

            <label class="form-label fw-bold mt-4">Zusatzwunsch an die KI</label>
            <textarea class="form-control" name="extra_prompt" rows="4" placeholder="z. B. bitte eher leichte Fragen, Fokus auf Vokabelverständnis, keine Jahreszahlen abfragen, Niveau A1..."></textarea>
            </div>

            <div class="d-flex gap-2 flex-wrap mt-4">
              <button class="btn btn-primary btn-lg" type="submit">✨ Quiz mit KI erstellen</button>
              <a class="btn btn-light btn-lg" href="quizzes.php?class_id=<?= (int)$classId ?>">Abbrechen</a>
            </div>
          </div></div>
        </div>
        <div class="col-lg-5">
          <div class="ai-info-card">
            <h3>Was die KI bekommt</h3>
            <ul>
              <li>Klasse, Schulart, Fach und Klassenstufe</li>
              <li>dein hochgeladenes Material oder das ausgewählte Lehrplanthema</li>
              <li>deine Zusatzanweisungen</li>
              <li>ob Inhalte abgefragt, ähnliche Übungen oder ein lehrplanbasiertes Quiz erstellt werden soll</li>
              <li>bei Listening: Ziel Sprache + Sprechertext</li>
            </ul>
            <div class="ai-warning">Wichtig: Die KI erstellt einen Entwurf. Vor Veröffentlichung bitte Fragen und Antworten prüfen.</div>
          </div>
        </div>
      </div>
    </form>
  </section>

  <section class="ai-wizard-panel" data-step="2">
    <div class="ai-generating-card">
      <div class="ai-orbit">
        <span></span><span></span><span></span>
      </div>
      <h3>Elevaro baut dein Quiz</h3>
      <p id="aiWizardProgressText">Material wird gelesen und didaktisch sortiert...</p>
      <div class="ai-progress"><i></i></div>
      <div class="ai-progress-list">
        <span data-loading-copy>📄 Quelle verstehen</span>
        <span data-loading-copy>🧠 Fragen entwickeln</span>
        <span data-loading-copy>✅ Antworten prüfen</span>
        <span data-loading-copy>🎨 Quizbild vorbereiten</span>
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

          <div id="aiListeningBox" class="mt-3 d-none">
            <label class="form-label fw-bold">Listening-Sprechertext</label>
            <textarea class="form-control" id="aiListeningText" rows="8"></textarea>
            <div class="form-text">Die Vertonung passiert erst beim Veröffentlichen.</div>
          </div>

          <label class="form-label fw-bold mt-3">Bildprompt</label>
          <textarea class="form-control" id="aiImagePrompt" rows="4"></textarea>

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
