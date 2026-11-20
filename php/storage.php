<?php
declare(strict_types=1);

function dbRoot(): string {
  $dir=defined('FREIGHTSLIP_DATA_DIR') ? FREIGHTSLIP_DATA_DIR : getenv('LR_DATA_DIR');
  if (!is_string($dir) || $dir==='' || !str_starts_with($dir,'/')) throw new RuntimeException('Set LR_DATA_DIR to an absolute path outside the web root.');
  if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Cannot create storage.');
  $root=realpath($_SERVER['DOCUMENT_ROOT']??'');$real=realpath($dir);
  if (!$real || ($root && ($root===$real || str_starts_with($real,$root.'/')))) throw new RuntimeException('Storage must be outside the web root.');
  return $real;
}
function readJson(string $path):array {return is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];}
function writeJson(string $path,array $data):void {
  $tmp=tempnam(dirname($path),'.write-');if($tmp===false)throw new RuntimeException('Cannot open temporary storage file.');
  try {$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if(file_put_contents($tmp,$json)!==strlen($json) || !rename($tmp,$path))throw new RuntimeException('Could not save records.');
  } finally {if(is_file($tmp))unlink($tmp);}
}
function saveDraft(string $type,array $data):string {$id=bin2hex(random_bytes(16));writeJson(dbRoot()."/$type-$id.json",$data);return $id;}
function draft(string $type,mixed $id):array {
  if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid draft reference.');
  $data=readJson(dbRoot()."/$type-$id.json");
  if(!$data)throw new InvalidArgumentException('Draft not found. Upload the workbook again.');
  $batch=$type==='upload'?$id:($data['upload']??null);
  if(is_string($batch) && lrWorkspaceTrashed($batch))throw new DomainException('This workspace is in the Recycle Bin. Restore it before accessing its draft.');
  return $data;
}
function registryAll():array {return readJson(dbRoot().'/register.json');}
function registry():array {return array_filter(registryAll(),static fn($record)=>is_array($record)&&!isset($record['recycle']));}
function lrWorkspaceId(array $receipt):string {
  $id=$receipt['source_batch']??null;
  if(is_string($id)&&preg_match('/^[a-f0-9]{32}$/D',$id))return $id;
  return 'legacy-'.substr(hash('sha256',(string)($receipt['source_file']??'')),0,20);
}
function lrWorkspaceIdValid(mixed $id):bool {
  return is_string($id) && (bool)preg_match('/^(?:[a-f0-9]{32}|legacy-[a-f0-9]{20})$/D',$id);
}
function lrWorkspaceTrashed(string $batch):bool {
  foreach(registryAll() as $record)if(is_array($record)&&isset($record['recycle'])&&lrWorkspaceId($record)===$batch)return true;
  return false;
}
function lrWorkspaceIndex(array $records,bool $recycled=false):array {
  $out=[];
  foreach($records as $receipt) {
    if(!is_array($receipt) || isset($receipt['recycle'])!==$recycled)continue;
    $id=lrWorkspaceId($receipt);$legacy=str_starts_with($id,'legacy-');
    if(!isset($out[$id]))$out[$id]=[
      'id'=>$id,'name'=>(string)($receipt['source_file']??'')?:'Older receipts (source unknown)',
      'sheet'=>(string)($receipt['source_sheet']??''), 'count'=>0,
      'last_issued'=>'','legacy'=>$legacy
    ];
    $out[$id]['count']++;
    $issued=(string)($receipt['issued_at']??'');
    if($issued>$out[$id]['last_issued'])$out[$id]['last_issued']=$issued;
    if($recycled){
      $out[$id]['deleted_at']=$receipt['recycle']['deleted_at'];
      $out[$id]['expires_at']=$receipt['recycle']['expires_at'];
    }
  }
  $values=array_values($out);
  usort($values,static fn($a,$b)=>strcmp($recycled?$b['deleted_at']:$b['last_issued'],$recycled?$a['deleted_at']:$a['last_issued']));
  return $values;
}
// A single, atomic register.json transaction is authoritative for active and recycled
// receipts, preventing crashes between separate active/trash file updates.
function lrMutateRegistry(callable $operation):mixed {
  $fp=fopen(dbRoot().'/register.lock','c');if(!$fp)throw new RuntimeException('Could not open register lock.');
  try {
    if(!flock($fp,LOCK_EX))throw new RuntimeException('Could not lock register.');
    $all=registryAll();$result=$operation($all);
    if(($result['write']??false)===true)writeJson(dbRoot().'/register.json',$all);
    return $result['data']??null;
  } finally {flock($fp,LOCK_UN);fclose($fp);}
}
function lrRegistryResponse():array {
  $all=registryAll();$active=array_filter($all,static fn($r)=>is_array($r)&&!isset($r['recycle']));
  $groups=array_reverse(array_values($active));
  foreach($groups as &$receipt){$receipt['workspace_id']=lrWorkspaceId($receipt);$receipt['workbook_id']=$receipt['workspace_id'];}
  unset($receipt);
  $spaces=lrWorkspaceIndex($all);
  return ['groups'=>$groups,'workspaces'=>$spaces,'workbooks'=>$spaces,'recycle'=>lrWorkspaceIndex($all,true)];
}
function lrWorkspaceMove(mixed $id):array {
  if(!lrWorkspaceIdValid($id))throw new InvalidArgumentException('Choose a valid workspace.');
  return lrMutateRegistry(static function(array &$all)use($id):array {
    $count=0;$now=time();$at=gmdate(DATE_ATOM,$now);$until=gmdate(DATE_ATOM,$now+30*86400);
    foreach($all as &$record){if(is_array($record)&&!isset($record['recycle'])&&lrWorkspaceId($record)===$id){
      $record['recycle']=['deleted_at'=>$at,'expires_at'=>$until];$count++;
    }}unset($record);
    if(!$count)throw new DomainException('Workspace not found or already in Recycle Bin.');
    return ['write'=>true,'data'=>['id'=>$id,'count'=>$count,'deleted_at'=>$at,'expires_at'=>$until]];
  });
}
function lrWorkspaceRestore(mixed $id):array {
  if(!lrWorkspaceIdValid($id))throw new InvalidArgumentException('Choose a valid workspace.');
  return lrMutateRegistry(static function(array &$all)use($id):array {
    $count=0;
    foreach($all as &$record){if(is_array($record)&&isset($record['recycle'])&&lrWorkspaceId($record)===$id){
      unset($record['recycle']);$count++;
    }}unset($record);
    if(!$count)throw new DomainException('Workspace is not in Recycle Bin (or its retention period has expired).');
    return ['write'=>true,'data'=>['id'=>$id,'count'=>$count]];
  });
}
function lrPurgeArtifacts(array $batches):void {
  // Only delete material from explicitly identified issued imports. Other uploads,
  // even if they have the same filename, must not be touched.
  $batches=array_values(array_unique(array_filter($batches,static fn($id)=>is_string($id)&&preg_match('/^[a-f0-9]{32}$/D',$id))));
  if(!$batches)return;
  $root=dbRoot();
  foreach($batches as $id){$path="$root/upload-$id.json";if(is_file($path)&&!unlink($path))error_log('FreightSlip could not purge a retired upload draft.');}
  foreach(glob($root.'/review-*.json')?:[] as $path){
    try{$data=readJson($path);}catch(Throwable $e){continue;}
    if(in_array($data['upload']??null,$batches,true)&&is_file($path)&&!unlink($path))error_log('FreightSlip could not purge a retired review draft.');
  }
}
function lrWorkspacePurge(mixed $id):array {
  if(!lrWorkspaceIdValid($id))throw new InvalidArgumentException('Choose a valid workspace.');
  $result=lrMutateRegistry(static function(array &$all)use($id):array {
    $count=0;$batches=[];
    foreach($all as $key=>$record){if(is_array($record)&&isset($record['recycle'])&&lrWorkspaceId($record)===$id){
      if(isset($record['source_batch']))$batches[]=$record['source_batch'];
      unset($all[$key]);$count++;
    }}
    if(!$count)throw new DomainException('Workspace is not in Recycle Bin.');
    return ['write'=>true,'data'=>['id'=>$id,'count'=>$count,'batches'=>$batches]];
  });
  lrPurgeArtifacts($result['batches']);unset($result['batches']);return $result;
}
function lrRecycleEmpty(bool $expiredOnly=false):array {
  $result=lrMutateRegistry(static function(array &$all)use($expiredOnly):array {
    $count=0;$spaces=[];$batches=[];$now=time();
    foreach($all as $key=>$record){if(!is_array($record)||!isset($record['recycle']))continue;
      $expiry=strtotime((string)($record['recycle']['expires_at']??''));
      if($expiredOnly&&(!$expiry||$expiry>$now))continue;
      $spaces[lrWorkspaceId($record)]=true;
      if(isset($record['source_batch']))$batches[]=$record['source_batch'];
      unset($all[$key]);$count++;
    }
    return ['write'=>$count>0,'data'=>['count'=>$count,'workspaces'=>count($spaces),'batches'=>$batches]];
  });
  lrPurgeArtifacts($result['batches']);unset($result['batches']);return $result;
}
function lrRecycleSweep():void {
  // No cron requirement: purge expired data on the first authenticated request
  // after 30 days. This does not guarantee deletion during periods with no visits.
  foreach(registryAll() as $record){
    if(!is_array($record)||!isset($record['recycle']))continue;
    $expiry=strtotime((string)($record['recycle']['expires_at']??''));
    if($expiry!==false&&$expiry<=time()){lrRecycleEmpty(true);break;}
  }
}
function issue(array $review,array $keys):array {
  if(!$keys||count($keys)>100||count(array_unique($keys,SORT_STRING))!==count($keys))throw new InvalidArgumentException('Select 1–100 distinct receipts to issue.');
  return lrMutateRegistry(static function(array &$all)use($review,$keys):array {
    $index=array_column($review['groups'],null,'key');$out=[];
    foreach($keys as $key){
      if(!is_string($key)||!isset($index[$key])||$index[$key]['status']!=='ready')throw new InvalidArgumentException('Only validated receipts can be issued.');
      if(isset($all[$key]))throw new DomainException("LR {$index[$key]['id']} already exists (including Recycle Bin). Nothing in this selection was saved.");
      $g=$index[$key];$g['status']='issued';$g['issued_at']=gmdate(DATE_ATOM);
      $g['source_file']=$review['file'];$g['source_sheet']=(string)($review['sheet']??'');
      $g['source_batch']=(string)($review['upload']??'');$all[$key]=$g;$out[]=$g;
    }
    return ['write'=>true,'data'=>$out];
  });
}
