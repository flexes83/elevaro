<?php
header('Content-Type: application/json');
$cfg = require __DIR__.'/../../config/freepik.php';
$in = json_decode(file_get_contents('php://input'), true);
$slug = preg_replace('/[^a-z0-9_-]/i','', $in['slug'] ?? '');
$target = basename($in['target'] ?? 'image.jpg');
$id = preg_replace('/[^a-z0-9_-]/i','', (string)($in['id'] ?? ''));
$prompt = trim($in['prompt'] ?? '');
$title = trim($in['title'] ?? '');
if(!$cfg['api_key'] || !$slug || !$target || !$id){ echo json_encode(['ok'=>false,'error'=>'Fehlende Parameter oder API-Key']); exit; }
function api_get($url,$key){
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['x-freepik-api-key: '.$key]]);
  $res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
  return [$res,$code,$err];
}
[$res,$code,$err]=api_get($cfg['base_url'].'/resources/'.$id.'/download',$cfg['api_key']);
if($err || $code>=400){ echo json_encode(['ok'=>false,'error'=>$err?:('HTTP '.$code),'raw'=>$res]); exit; }
$json=json_decode($res,true);
$url=$json['data']['url']??$json['url']??$json['download_url']??'';
if(!$url && preg_match('~https?://[^"\s]+~',$res,$m)) $url=$m[0];
if(!$url){ echo json_encode(['ok'=>false,'error'=>'Keine Download-URL in Freepik-Antwort gefunden','raw'=>$json]); exit; }
$bin=@file_get_contents($url);
if(!$bin){ echo json_encode(['ok'=>false,'error'=>'Download fehlgeschlagen']); exit; }
$dir=__DIR__.'/../../assets/quizzes/'.$slug.'/img'; if(!is_dir($dir)) mkdir($dir,0775,true);
$path=$dir.'/'.$target; file_put_contents($path,$bin);
$metaDir=__DIR__.'/../../data/image_imports'; if(!is_dir($metaDir)) mkdir($metaDir,0775,true);
$metaFile=$metaDir.'/'.$slug.'.json'; $meta=is_file($metaFile)?json_decode(file_get_contents($metaFile),true):[]; if(!is_array($meta))$meta=[];
$meta[$target]=['source'=>'freepik','resource_id'=>$id,'title'=>$title,'prompt'=>$prompt,'imported_at'=>date('c'),'path'=>'assets/quizzes/'.$slug.'/img/'.$target];
file_put_contents($metaFile,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
echo json_encode(['ok'=>true,'path'=>'assets/quizzes/'.$slug.'/img/'.$target]);
?>
