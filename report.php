<?php
$config=require __DIR__.'/mail_config.php';
$raw=file_get_contents('php://input'); $data=json_decode($raw,true); header('Content-Type: application/json');
if(!$config['enabled']){ echo json_encode(['ok'=>true,'mail'=>'disabled']); exit; }
$lines=[]; foreach(['quiz','name','score','right','wrong','weak_categories'] as $k){ $lines[]=$k.': '.($data[$k]??''); }
if(!empty($data['wrong_questions'])){ $lines[]="\nFalsche Fragen:"; foreach($data['wrong_questions'] as $q){ $lines[]='- '.$q; }}
$body=implode("\n",$lines); $subject=$config['subject_prefix'].' '.($data['quiz']??'Quiz').' – '.($data['name']??'');
$headers='From: '.$config['from']."\r\nContent-Type: text/plain; charset=UTF-8";
$ok=@mail($config['to'],$subject,$body,$headers); echo json_encode(['ok'=>$ok]);
?>