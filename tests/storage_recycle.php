<?php
declare(strict_types=1);
require dirname(__DIR__).'/php/storage.php';
$root=sys_get_temp_dir().'/lr-bin-test-'.bin2hex(random_bytes(5));mkdir($root,0700,true);
putenv('LR_DATA_DIR='.$root);
function verify(bool $condition,string $why):void {if(!$condition)throw new RuntimeException($why);}
try{
  $a=str_repeat('a',32);$b=str_repeat('b',32);
  $make=static fn($id,$batch,$file)=>['id'=>$id,'key'=>$id,'status'=>'issued','items'=>[['product'=>'UREA']], 'issues'=>[], 'common'=>['consignee'=>'Shop','destination'=>'Pune','date'=>'2026-11-21'],'source_file'=>$file,'source_sheet'=>'CSV','source_batch'=>$batch,'issued_at'=>'2026-11-21T00:00:00+00:00'];
  writeJson($root.'/register.json',['LR-A'=>$make('LR-A',$a,'same.csv'),'LR-B'=>$make('LR-B',$b,'same.csv')]);
  writeJson($root.'/upload-'.$a.'.json',['data'=>'A']);writeJson($root.'/upload-'.$b.'.json',['data'=>'B']);
  $reg=lrRegistryResponse(); verify(count($reg['workspaces'])===2,'distinct workspaces');
  $deleted=lrWorkspaceMove($a);verify($deleted['count']===1,'move count');
  verify(count(registry())===1,'active exclude recycled');
  verify(count(lrRegistryResponse()['recycle'])===1,'recycle entry');
  verify(lrWorkspaceTrashed($a),'trash flag');
  verify(!lrWorkspaceTrashed($b),'other remains active');
  $restored=lrWorkspaceRestore($a);verify($restored['count']===1 && count(registry())===2,'restore');
  lrWorkspaceMove($a);$purged=lrWorkspacePurge($a);verify($purged['count']===1,'purge');
  verify(!is_file($root.'/upload-'.$a.'.json'),'purged source');
  verify(is_file($root.'/upload-'.$b.'.json'),'other source preserved');
  verify(count(registry())===1,'remaining active');
  lrWorkspaceMove($b);$p=lrRecycleEmpty();verify($p['count']===1 && count(registryAll())===0,'empty trash');
  writeJson($root.'/register.json',['LR-A'=>$make('LR-A',$a,'same.csv')]);lrWorkspaceMove($a);
  $all=registryAll();$all['LR-A']['recycle']['expires_at']='2001-01-01T00:00:00+00:00';writeJson($root.'/register.json',$all);
  lrRecycleSweep();verify(registryAll()===[],'expired cleanup');
  echo "Recycle storage test passed: isolation, trash, restore, purge, expiry.\n";
}finally{
  foreach(glob($root.'/*')?:[] as $file)unlink($file);
  rmdir($root);
}
