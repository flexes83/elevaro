<?php
require __DIR__.'/../lib/functions.php';
$slug = preg_replace('/[^a-z0-9_-]/i','', $_GET['quiz'] ?? 'pferde');
$q = quiz_config($slug); if(!$q) exit('Quiz nicht gefunden');

$questionFile = __DIR__.'/../data/quizzes/'.$slug.'.questions.json';
$questions = is_file($questionFile) ? json_decode(file_get_contents($questionFile), true) : [];

$promptFile = __DIR__.'/../data/quizzes/'.$slug.'.image_prompts.php';
$promptMap = is_file($promptFile) ? require $promptFile : [];

function filename_prompt_fallback($file){
  $name = pathinfo($file, PATHINFO_FILENAME);
  $label = str_replace(['-','_'], ' ', $name);
  return [
    'label' => ucwords($label),
    'query' => $label.' educational realistic photo no text no logo no watermark'
  ];
}

$targets = [];
foreach($promptMap as $file=>$p){ $targets[$file] = $p; }
foreach($questions as $qq){
  if(!empty($qq['image']) && empty($targets[$qq['image']])) $targets[$qq['image']] = filename_prompt_fallback($qq['image']);
  if(!empty($qq['options']) && is_array($qq['options'])){
    foreach($qq['options'] as $o){
      if(is_array($o) && !empty($o['image']) && empty($targets[$o['image']])) $targets[$o['image']] = filename_prompt_fallback($o['image']);
    }
  }
}
$imgDir = __DIR__.'/../assets/quizzes/'.$slug.'/img';
if(is_dir($imgDir)){
  foreach(scandir($imgDir) as $f){
    if(preg_match('/\.(jpe?g|png|webp|svg)$/i',$f) && empty($targets[$f])) $targets[$f] = filename_prompt_fallback($f);
  }
}
ksort($targets, SORT_NATURAL | SORT_FLAG_CASE);
$firstFile = array_key_first($targets) ?: 'cover.jpg';
$selected = $_GET['target'] ?? $firstFile;
if(empty($targets[$selected])) $selected = $firstFile;
$promptJson = json_encode($targets, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bilder</title><link rel="stylesheet" href="<?=asset_url('assets/css/portal.css')?>?v=<?=filemtime(__DIR__.'/../assets/css/portal.css')?>"><style>
.current-preview{display:flex;gap:18px;align-items:center;flex-wrap:wrap}.current-preview img{width:160px;height:120px;object-fit:cover;border-radius:18px;background:#f2f2f2}.muted{opacity:.65}.prompt-tools{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}.prompt-tools button{width:auto}.asset small{opacity:.7;display:block;margin-top:4px}.query-source{font-size:.9rem;opacity:.72;margin-top:6px}
</style></head><body><main class="site"><div class="topbar"><h1><?=h($q['icon'].' Bilder: '.$q['title'])?></h1><a href="index.php">Backend</a></div>
<div class="notice"><b>Moderierter Bildimport:</b> Zuerst lokalen Ziel-Dateinamen wählen. Die Suchanfrage wird jetzt pro Quiz und pro Datei vorausgefüllt. Danach Vorschau prüfen und lokal importieren. Wichtig für Quizbilder: keine Schrift, kein Logo, kein Wasserzeichen, eindeutiges Motiv.</div>
<section class="card">
  <div class="form-row">
    <label>Quiz<input value="<?=h($q['title'])?>" disabled></label>
    <label>Lokaler Dateiname<select id="target"><?php foreach($targets as $file=>$p): ?><option value="<?=h($file)?>" <?=$file===$selected?'selected':''?>><?=h($file)?> — <?=h($p['label'] ?? '')?></option><?php endforeach; ?></select></label>
  </div>
  <div class="current-preview" style="margin-top:14px">
    <img id="localPreview" src="<?=asset_url('assets/quizzes/'.$slug.'/img/'.$selected)?>?v=<?=time()?>" onerror="this.src='../assets/img/placeholder.svg'" alt="lokale Vorschau">
    <div><b id="labelText"><?=h($targets[$selected]['label'] ?? $selected)?></b><p class="muted">Zieldatei: <code id="targetText"><?=h($selected)?></code></p><p class="query-source">Prompt-Konfiguration: <code>data/quizzes/<?=h($slug)?>.image_prompts.php</code></p></div>
  </div>
  <label style="margin-top:18px">Suchanfrage<input id="query" value="<?=h($targets[$selected]['query'] ?? '')?>"></label>
  <div class="prompt-tools">
    <button class="btn" id="search" type="button">Freepik suchen</button>
    <button class="btn secondary" id="resetPrompt" type="button">Prompt aus Config wiederherstellen</button>
  </div>
</section>
<section><h2>Vorschläge</h2><div id="results" class="results"></div></section></main>
<script>
const slug = <?=json_encode($slug)?>;
const promptMap = <?=$promptJson?>;
let current = [];
const targetEl = document.getElementById('target');
const queryEl = document.getElementById('query');
const labelText = document.getElementById('labelText');
const targetText = document.getElementById('targetText');
const localPreview = document.getElementById('localPreview');
function applyTarget(file){
  const p = promptMap[file] || {label:file, query:file.replace(/\.[^.]+$/,'') + ' educational realistic photo no text no logo no watermark'};
  queryEl.value = p.query || '';
  labelText.textContent = p.label || file;
  targetText.textContent = file;
  localPreview.src = '../assets/quizzes/'+slug+'/img/'+file+'?v=' + Date.now();
  history.replaceState(null, '', 'images.php?quiz='+encodeURIComponent(slug)+'&target='+encodeURIComponent(file));
}
targetEl.addEventListener('change', e => applyTarget(e.target.value));
document.getElementById('resetPrompt').onclick = () => applyTarget(targetEl.value);
document.getElementById('search').onclick = async () => {
  let q = queryEl.value.trim();
  document.getElementById('results').innerHTML = '<p>Suche läuft…</p>';
  let r = await fetch('api/freepik_search.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({query:q})});
  let j = await r.json();
  current = j.items || [];
  if(!current.length){
    document.getElementById('results').innerHTML = '<p>Keine Ergebnisse oder API-Fehler. Prüfe API-Key und Antwort.</p>' + (j.error ? '<pre>'+String(j.error)+'</pre>' : '');
    return;
  }
  document.getElementById('results').innerHTML = current.map((it,i)=>`<div class="asset"><img src="${it.preview||'../assets/img/placeholder.svg'}"><b>${it.title||it.id}</b><p>ID: ${it.id}</p>${it.author?`<small>${it.author}</small>`:''}<button class="btn" onclick="imp(${i})">als ${targetEl.value} importieren</button></div>`).join('');
};
async function imp(i){
  let target = targetEl.value;
  let prompt = queryEl.value;
  let r = await fetch('api/freepik_import.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({slug,id:current[i].id,target,prompt,title:current[i].title||''})});
  let j = await r.json();
  if(j.ok){
    alert('Importiert: '+j.path);
    localPreview.src = '../'+j.path+'?v='+Date.now();
  } else {
    alert('Fehler: '+(j.error||'unbekannt'));
  }
}
</script></body></html>
