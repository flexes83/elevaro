<?php
header('Content-Type: application/json');
$cfg = require __DIR__.'/../../config/freepik.php';
$in = json_decode(file_get_contents('php://input'), true);
$query = trim($in['query'] ?? '');
if(!$cfg['api_key']) { echo json_encode(['items'=>[], 'error'=>'API-Key fehlt in config/freepik.php']); exit; }
$url = $cfg['base_url'].'/resources?'.http_build_query(['term'=>$query,'limit'=>$cfg['default_limit'],'order'=>'relevance']);
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['x-freepik-api-key: '.$cfg['api_key']]]);
$res = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if($err || $code >= 400) { echo json_encode(['items'=>[], 'error'=>$err ?: ('HTTP '.$code), 'raw'=>$res]); exit; }
$json = json_decode($res, true);
$rows = $json['data'] ?? $json['items'] ?? $json['resources'] ?? [];
$items = [];
foreach($rows as $r){
  $id = $r['id'] ?? $r['resource_id'] ?? null; if(!$id) continue;
  $preview = $r['image']['source']['url'] ?? $r['image']['url'] ?? $r['preview']['url'] ?? $r['thumbnail'] ?? $r['url'] ?? '';
  $author = '';
  if(isset($r['author'])) $author = is_array($r['author']) ? ($r['author']['name'] ?? '') : $r['author'];
  $items[] = ['id'=>$id, 'title'=>$r['title'] ?? $r['name'] ?? ('Resource '.$id), 'preview'=>$preview, 'author'=>$author];
}
echo json_encode(['items'=>$items, 'raw_count'=>count($rows)]);
?>
