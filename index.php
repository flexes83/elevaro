<?php
require __DIR__.'/lib/functions.php';
$quizzes=quiz_configs();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$subjects=[]; $grades=[]; $states=[]; $schoolTypes=[];
foreach($quizzes as $quiz){
  if(!empty($quiz['subject'])) $subjects[$quiz['subject']]=$quiz['subject'];
  if(!empty($quiz['grade'])) $grades[(string)$quiz['grade']]=$quiz['grade'];
  foreach(($quiz['states'] ?? []) as $s) $states[$s]=$s;
  foreach(($quiz['schoolTypes'] ?? []) as $s) $schoolTypes[$s]=$s;
}
sort($subjects); sort($grades); sort($states); sort($schoolTypes);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Elevaro – Spielerisch zu guten Noten</title>
<meta name="description" content="Elevaro hilft Schülern, Schulstoff spielerisch zu üben und besser zu verstehen – nach Klasse, Fach und Thema.">
<link rel="stylesheet" href="<?=asset_url('assets/css/portal.css')?>?v=<?=filemtime(__DIR__.'/assets/css/portal.css') ?>">
</head>
<body>
<header class="main-hero elevaro-hero">
  <nav class="site nav-main">
    <a class="brand" href="<?=asset_url('index.php')?>"><span class="brand-mark">E</span><span>Elevaro</span></a>
    <div class="nav-actions">
      <a href="#quiz-finder">Quiz-Finder</a>
      <a href="#quizzes" class="nav-pill">Quiz starten</a>
    </div>
  </nav>
  <div class="site hero-grid">
    <div>
      <p class="eyebrow">Schulstoff, der hängen bleibt</p>
      <h1>Spielerisch zu guten Noten.</h1>
      <p class="lead">Elevaro macht aus trockenem Schulstoff kurze, motivierende Quizrunden. Übe gezielt nach Fach, Klasse und Thema – mit direktem Feedback und Wackelkandidaten, die du später wiederholen kannst.</p>
      <div class="hero-actions">
        <a class="btn" href="#quiz-finder">Passendes Quiz finden</a>
        <?php if($quizzes): ?><a class="btn secondary" href="<?=quiz_url($quizzes[0])?>">Direkt loslegen</a><?php endif; ?>
      </div>
      <div class="trust-row">
        <span>🎯 Nah am Schulstoff</span>
        <span>⚡ Sofort Feedback</span>
        <span>🔁 Gezielt wiederholen</span>
      </div>
    </div>
    <div class="panda-stage" aria-hidden="true">
      <div class="panda-bubble">🐼</div>
      <div class="panda-note"><b>Bereit?</b><br>Such dir Fach, Klasse und Thema aus.</div>
    </div>
  </div>
</header>
<main class="site">
  <section id="quiz-finder" class="finder-card">
    <div class="finder-copy">
      <p class="eyebrow">Quiz-Finder</p>
      <h2>Was möchtest du üben?</h2>
      <p>Wähle ein paar Eckdaten aus. Elevaro zeigt dir passende Quizze zu deinem Schulstoff.</p>
    </div>
    <div class="finder-form" id="finderForm">
      <label>Bundesland
        <select id="filterState">
          <option value="">Alle Bundesländer</option>
          <?php foreach($states as $s): ?><option value="<?=h($s)?>"><?=h($s)?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Schulart
        <select id="filterSchoolType">
          <option value="">Alle Schularten</option>
          <?php foreach($schoolTypes as $s): ?><option value="<?=h($s)?>"><?=h($s)?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Klasse
        <select id="filterGrade">
          <option value="">Alle Klassen</option>
          <?php foreach($grades as $g): ?><option value="<?=h($g)?>">Klasse <?=h($g)?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Fach
        <select id="filterSubject">
          <option value="">Alle Fächer</option>
          <?php foreach($subjects as $s): ?><option value="<?=h($s)?>"><?=h($s)?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
  </section>

  <section id="quizzes" class="section-head">
    <p class="eyebrow">Aktuelle Quizze</p>
    <h2>Starte mit einem Thema</h2>
    <p>Der Fokus liegt auf konkretem Schulstoff. Weitere Klassen, Fächer und Bundesländer lassen sich später sauber ergänzen.</p>
  </section>

  <div class="quiz-grid rich" id="quizGrid">
    <?php foreach($quizzes as $q):
      $cover=img_url($q['coverImage'] ?? 'assets/img/placeholder.svg');
      $stateData=implode(',', $q['states'] ?? []);
      $schoolData=implode(',', $q['schoolTypes'] ?? []);
    ?>
    <a class="quiz-card rich-card" style="--quiz-color:<?=h($q['color'])?>;--quiz-soft:<?=h($q['softColor'] ?? '#f5eefc')?>" href="<?=quiz_url($q)?>" data-subject="<?=h($q['subject'] ?? '')?>" data-grade="<?=h($q['grade'] ?? '')?>" data-states="<?=h($stateData)?>" data-school-types="<?=h($schoolData)?>">
      <div class="card-image"><img src="<?=h($cover)?>" alt="<?=h($q['title'])?>"></div>
      <div class="card-body">
        <div class="card-meta"><span class="icon"><?=h($q['icon'])?></span><span class="cat"><?=h($q['category'])?></span></div>
        <h3><?=h($q['title'])?></h3>
        <p><?=h($q['description'])?></p>
        <div class="mini-tags">
          <?php if(!empty($q['grade'])): ?><span>Klasse <?=h($q['grade'])?></span><?php endif; ?>
          <?php if(!empty($q['subject'])): ?><span><?=h($q['subject'])?></span><?php endif; ?>
          <?php foreach(array_slice(($q['tags'] ?? []),0,3) as $tag): ?><span><?=h($tag)?></span><?php endforeach; ?>
        </div>
        <span class="btn card-btn">Quiz ansehen</span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <div class="empty-state" id="emptyState" hidden>
    <b>Hier ist noch nichts Passendes dabei.</b><br>
    Wähle weniger Filter oder starte mit einem vorhandenen Quiz.
  </div>

  <section class="teacher-teaser">
    <div>
      <p class="eyebrow">Für später vorbereitet</p>
      <h2>Für Schüler gemacht. Für Schule gedacht.</h2>
      <p>Später können Profile Schulart, Bundesland und Klasse speichern. So schlägt Elevaro automatisch Quizze vor, die zum Lehrplan passen. Lehrer können Klassen anlegen, Quizze freigeben und eigene Varianten erstellen.</p>
    </div>
    <div class="teacher-points">
      <span>🏫 Klassen</span>
      <span>🧩 eigene Quiz-Varianten</span>
      <span>📈 Fortschritt</span>
    </div>
  </section>
</main>
<footer class="site footer">© <?=date('Y')?> Elevaro · Spielerisch zu guten Noten.</footer>
<script>
(function(){
  const filters=['filterState','filterSchoolType','filterGrade','filterSubject'].map(id=>document.getElementById(id));
  const cards=[...document.querySelectorAll('.quiz-card')];
  const empty=document.getElementById('emptyState');
  function includesCSV(csv, value){ if(!value) return true; return String(csv||'').split(',').map(s=>s.trim()).includes(value); }
  function apply(){
    const state=document.getElementById('filterState').value;
    const school=document.getElementById('filterSchoolType').value;
    const grade=document.getElementById('filterGrade').value;
    const subject=document.getElementById('filterSubject').value;
    let visible=0;
    cards.forEach(card=>{
      const ok = includesCSV(card.dataset.states,state) && includesCSV(card.dataset.schoolTypes,school) && (!grade || card.dataset.grade===grade) && (!subject || card.dataset.subject===subject);
      card.hidden=!ok; if(ok) visible++;
    });
    empty.hidden = visible>0;
  }
  filters.forEach(f=>f.addEventListener('change', apply));
})();
</script>
</body></html>
