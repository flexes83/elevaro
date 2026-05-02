<?php
function h($s){return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');}
function base_path(){
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $dir = rtrim(str_replace('\\','/', dirname($script)), '/');
  if ($dir === '' || $dir === '.') return '';
  if (str_ends_with($dir, '/admin') || str_ends_with($dir, '/admin/api')) $dir = dirname($dir);
  return $dir === '/' ? '' : $dir;
}
function asset_url($path){return base_path().'/'.ltrim($path,'/');}
function quiz_configs(){
  $items=[];
  foreach(glob(__DIR__.'/../data/quizzes/*.json') as $file){
    if(str_contains($file,'.questions.')) continue;
    $data=json_decode(file_get_contents($file),true);
    if($data) $items[]=$data;
  }
  usort($items, fn($a,$b)=>strcmp($a['order'] ?? 999, $b['order'] ?? 999) ?: strcmp($a['title'],$b['title']));
  return $items;
}
function quiz_config($slug){
  $slug=basename((string)$slug);
  $file=__DIR__.'/../data/quizzes/'.$slug.'.json';
  return is_file($file)?json_decode(file_get_contents($file),true):null;
}
function quiz_url($q){return asset_url('quiz/'.rawurlencode($q['slug']).'/');}
function play_url($q){return asset_url('spielen/'.rawurlencode($q['slug']).'/');}
function img_url($path){return asset_url($path ?: 'assets/img/placeholder.svg');}
?>
