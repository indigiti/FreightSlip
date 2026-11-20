<?php
declare(strict_types=1);
function dbRoot():string {
  $dir=defined('FREIGHTSLIP_DATA_DIR') ? FREIGHTSLIP_DATA_DIR : getenv('LR_DATA_DIR');if(!is_string($dir) || $dir==='' || !str_starts_with($dir,'/')) throw new RuntimeException('Set LR_DATA_DIR to an absolute path outside the web root.');
  if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir))throw new RuntimeException('Cannot create storage.');
  $root=realpath($_SERVER['DOCUMENT_ROOT']??'');$real=realpath($dir);
  if(!$real || ($root && ($root===$real || str_starts_with($real,$root.'/'))))throw new RuntimeException('Storage must be outside the web root.');
  return $real;
}
function readJson(string $path):array {return is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];}
function writeJson(string $path,array $data):void {
  $tmp=tempnam(dirname($path),'.write-');if($tmp===false)throw new RuntimeException('Cannot open temporary storage file.');
  try{$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(file_put_contents($tmp,$json)!==strlen($json) || !rename($tmp,$path))throw new RuntimeException('Could not save records.');}finally{if(is_file($tmp))unlink($tmp);}
}
function saveDraft(string $type,array $data):string {$id=bin2hex(random_bytes(16));writeJson(dbRoot()."/$type-$id.json",$data);return $id;}
function draft(string $type,mixed $id):array {
  if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid draft reference.');
  $data=readJson(dbRoot()."/$type-$id.json");if(!$data)throw new InvalidArgumentException('Draft not found. Upload the workbook again.');return $data;
}
function registry():array {return readJson(dbRoot().'/register.json');}
// Each issued LR belongs to the exact import/worksheet it was reviewed from.
// Older records have no immutable import token; group those by source filename and
// mark them as legacy rather than pretending their historical batch is known.
function lrWorkbookId(array $receipt):string {
  $batch=$receipt['source_batch']??null;
  if(is_string($batch) && preg_match('/^[a-f0-9]{32}$/D',$batch)) return $batch;
  return 'legacy-'.substr(hash('sha256',(string)($receipt['source_file']??'')),0,20);
}
function lrWorkbookIndex(array $records):array {
  $index=[];
  foreach($records as $receipt) {
    $id=lrWorkbookId($receipt);
    $name=(string)($receipt['source_file']??'');
    if($name==='')$name='Earlier receipts (source unknown)';
    $legacy=str_starts_with($id,'legacy-');
    if(!isset($index[$id]))$index[$id]=[
      'id'=>$id,'name'=>$name,'sheet'=>(string)($receipt['source_sheet']??''),
      'count'=>0,'last_issued'=>'','legacy'=>$legacy
    ];
    $index[$id]['count']++;
    $issued=(string)($receipt['issued_at']??'');
    if($issued>$index[$id]['last_issued'])$index[$id]['last_issued']=$issued;
  }
  $result=array_values($index);
  usort($result,fn($a,$b)=>strcmp($b['last_issued'],$a['last_issued']));
  return $result;
}
function issue(array $review,array $keys):array {
  if(!$keys || count($keys)>100 || count(array_unique($keys,SORT_STRING))!==count($keys))throw new InvalidArgumentException('Select 1–100 distinct receipts to issue.');
  $index=array_column($review['groups'],null,'key');$lock=fopen(dbRoot().'/register.lock','c');
  if(!$lock)throw new RuntimeException('Could not open register lock.');
  try{if(!flock($lock,LOCK_EX))throw new RuntimeException('Could not lock register.');$current=registry();$out=[];
    foreach($keys as $key){if(!is_string($key)||!isset($index[$key])||$index[$key]['status']!=='ready')throw new InvalidArgumentException('Only validated receipts can be issued.');
      if(isset($current[$key]))throw new DomainException("LR {$index[$key]['id']} is already issued. Nothing in this selection was saved.");
      $g=$index[$key];$g['status']='issued';$g['issued_at']=gmdate(DATE_ATOM);$g['source_file']=$review['file'];
      $g['source_sheet']=(string)($review['sheet']??'');
      $g['source_batch']=(string)($review['upload']??'');
      $current[$key]=$g;$out[]=$g;
    }
    writeJson(dbRoot().'/register.json',$current);return $out;
  }finally{flock($lock,LOCK_UN);fclose($lock);}
}
