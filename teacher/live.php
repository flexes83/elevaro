<?php
require_once __DIR__ . '/_layout.php';
$class = teacher_selected_class();
teacher_header('Live Quizz', 'Vorbereitet für spätere Live-Runden im Unterricht.');
?>
<div class="card card-soft"><div class="card-body p-4">
  <h2 class="h5 fw-bold">Live Quizz kommt als nächstes</h2>
  <p class="text-muted mb-0">Hier können Lehrer später ein Quiz starten, den Beitrittscode anzeigen und Antworten live auswerten.</p>
</div></div>
<?php teacher_footer(); ?>
