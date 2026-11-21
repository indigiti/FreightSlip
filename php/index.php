<?php
declare(strict_types=1);
require __DIR__.'/http.php';require __DIR__.'/domain.php';require __DIR__.'/storage.php';require __DIR__.'/settings.php';require __DIR__.'/print-design.php';require __DIR__.'/visual-design.php';require __DIR__.'/users.php';require __DIR__.'/auth.php';require __DIR__.'/import.php';require __DIR__.'/receipt.php';require __DIR__.'/qr.php';
function suggestHeaders(array $sheet):array {
  $url=getenv('LR_AI_URL');if(!$url)throw new DomainException('Mapping assistant is disabled. Select columns manually.');
  $body=json_encode(['headers'=>$sheet['headers'],'fields'=>array_map(fn($s)=>array_slice($s,0),FIELDS),'mapped'=>(object)known($sheet['headers'])],JSON_THROW_ON_ERROR);
  $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>$body,'timeout'=>3,'follow_location'=>0]]);
  $out=@file_get_contents(rtrim($url,'/').'/suggest',false,$context,0,32768);if($out===false)throw new DomainException('Mapping assistant is unavailable. Select columns manually.');
  return json_decode($out,true,32,JSON_THROW_ON_ERROR);
}
try {
  $action=$_GET['action']??'register';$method=$_SERVER['REQUEST_METHOD'];
  lrAuthenticate((string)$action,(string)$method);
  lrRecycleSweep();
  if($method==='POST'){
    // A cross-origin form cannot add this header. No CORS permissions are granted.
    if(($_SERVER['HTTP_X_LR_STUDIO']??'')!=='1')respond(['error'=>'Submit requests through LR Studio.'],403);
    if($action==='upload'){
      $file=$_FILES['workbook']??null;
      if(!$file || $file['error']!==UPLOAD_ERR_OK || $file['size']>5000000 || !is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Upload a workbook up to 5 MB.');
      $header=(string)($_POST['header']??'');if($header!==''&&!preg_match('/^[1-9]\d{0,2}$/D',$header))throw new InvalidArgumentException('Invalid header row.');
      $sheet=loadSheet($file['tmp_name'],basename($file['name']),(int)$header,(string)($_POST['sheet']??''));$id=saveDraft('upload',$sheet);[$mapping,$inferred]=detectColumns($sheet);$configured=lrSettingsRead()['settings'];
      respond(['upload'=>$id,'file'=>$sheet['file'],'sheet'=>$sheet['sheet'],'sheets'=>$sheet['sheets'],'inferred'=>$inferred,'header'=>$sheet['header_row'],'headers'=>$sheet['headers'],'rows'=>count($sheet['rows']),'map'=>(object)$mapping,'fields'=>array_map(fn($v)=>$v[0],FIELDS),'required'=>array_values(array_filter(REQUIRED,fn($f)=>$f!=='carrier' || $configured['carrier']==='')),'assistant'=>(bool)getenv('LR_AI_URL')]);
    }
    $p=payload();
    if($action==='suggest')respond(suggestHeaders(draft('upload',$p['upload']??null)));
    if($action==='settings')respond(lrSettingsSave($p));
    if($action==='print_design'){lrRequireAdmin();respond(lrPrintDesignSave($p));}
    if(in_array($action,['visual_create','visual_save','visual_publish','visual_revert','visual_delete'],true)){
       lrRequireAdmin();respond(lrVisualMutateAction($action,$p));
    }
     if($action==='user_create'){lrRequireAdmin();respond(['user'=>lrUserCreate($p)],201);}
     if($action==='user_update'){lrRequireAdmin();respond(['user'=>lrUserUpdate($p)]);}
     if($action==='user_delete'){lrRequireAdmin();respond(['deleted'=>lrUserDelete($p)]);}
    if($action==='workspace_trash')respond(['deleted'=>lrWorkspaceMove($p['id']??null)]);
    if($action==='workspace_restore')respond(['restored'=>lrWorkspaceRestore($p['id']??null)]);
    if($action==='workspace_purge')respond(['purged'=>lrWorkspacePurge($p['id']??null)]);
    if($action==='recycle_empty')respond(['purged'=>lrRecycleEmpty()]);
    if($action==='review'){
      $settings=lrSettingsRead();$s=draft('upload',$p['upload']??null);$groups=analyze($s,requiredArray($p['map']??null),requiredArray($p['rules']??null),registryAll(),$settings['settings']);
      $id=saveDraft('review',['file'=>$s['file'],'sheet'=>$s['sheet'],'upload'=>$p['upload'],'groups'=>$groups]);respond(['review'=>$id,'groups'=>$groups]);
    }
    if($action==='issue'){respond(['groups'=>issue(draft('review',$p['review']??null),requiredArray($p['keys']??null))],201);}
  }
  if($method==='GET'){
    if($action==='details'){
      $record=registry()[(string)($_GET['key']??'')]??null;
      if(!$record||!lrWorkspaceAllowed(lrWorkspaceId($record)))
         respond(['error'=>'Receipt not found.'],404);
      header('Content-Type: text/plain; charset=utf-8');echo lrQrText($record,false);exit;
    }
    if($action==='settings')respond(lrSettingsRead());
    if($action==='print_design'){lrRequireAdmin();respond(lrPrintDesignRead());}
    if($action==='visual_templates'){lrRequireAdmin();respond(lrVisualAdmin());}
    if($action==='visual_public')respond(['templates'=>lrVisualPublic()]);
    if($action==='users'){lrRequireAdmin();respond(lrUserList());}
     if($action==='register'){
       $response=lrRegistryResponse();
       if(lrCurrentRole()==='download'){
         $allowed=array_fill_keys(lrCurrentWorkspaces(),true);
         $response['groups']=array_values(array_filter($response['groups'],
             static fn($g)=>isset($allowed[$g['workspace_id']??''])));
         $response['workspaces']=array_values(array_filter($response['workspaces'],
             static fn($w)=>isset($allowed[$w['id']])));
         $response['workbooks']=$response['workspaces'];
         $response['recycle']=[];
       }
       respond($response);
     }
    if(in_array($action,['preview','pdf'],true)){
      $keys=explode(',',(string)($_GET['keys']??''));if(count($keys)>100 || count(array_unique($keys))!==count($keys))throw new InvalidArgumentException('Select up to 100 distinct receipts.');
      $isDraft=isset($_GET['review']);if($isDraft && lrCurrentRole()==='download')respond(['error'=>'Drafts are not available to download-only accounts.'],403);
       if($isDraft && ($action==='pdf'||count($keys)!==1))throw new InvalidArgumentException('Drafts can only be previewed one at a time.');
      $records=$isDraft?array_column(draft('review',$_GET['review'])['groups'],null,'key'):registry();$groups=[];
      foreach($keys as $key){if(!isset($records[$key])||($isDraft&&$records[$key]['status']!=='ready')||(!$isDraft&&!lrWorkspaceAllowed(lrWorkspaceId($records[$key]))))respond(['error'=>'Receipt not found or unavailable.'],404);$groups[]=$records[$key];}
      $savedDesign=lrPrintDesignRead()['design'];
      $layout=$_GET['layout']??$savedDesign['default_layout'];
      if(!is_string($layout))throw new InvalidArgumentException('Select a valid receipt layout.');
      $qr=$_GET['qr']??($savedDesign['default_qr']?'1':'0');
      if(!in_array($qr,['0','1'],true))throw new InvalidArgumentException('Select a valid QR printing option.');
      if(str_starts_with($layout,'visual:')){
         $templateId=substr($layout,7);
         $draftTemplate=($_GET['template_draft']??'0')==='1';
         if($draftTemplate){
            lrRequireAdmin();
            $template=lrVisualDraft($templateId);
         }else{
            $version=null;
            if(isset($_GET['template_version'])){
               lrRequireAdmin();
               if(!preg_match('/^[1-9][0-9]{0,5}$/D',(string)$_GET['template_version']))throw new InvalidArgumentException('Invalid visual template version.');
               $version=(int)$_GET['template_version'];
            }
            $template=lrVisualPublished($templateId,$version);
         }
         $validation=lrVisualValidate($template,!$draftTemplate);
         if($validation['errors'])throw new InvalidArgumentException(implode(' ',$validation['errors']));
         $html=lrVisualHtml($groups,$isDraft,$template,$qr==='1');
      }else{
         if(!in_array($layout,['classic','clean'],true))throw new InvalidArgumentException('Select Classic, Print Clean or a published visual template.');
         $html=receiptHtml($groups,$isDraft,$layout,$qr==='1',$savedDesign[$layout]);
      }
      if($action==='preview'){header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'self'");header('Content-Type: text/html; charset=utf-8');echo $html;exit;}
      vendor();$opts=new \Dompdf\Options();$opts->set('defaultMediaType','print');$opts->set('isRemoteEnabled',false);$opts->set('isPhpEnabled',false);$opts->set('isJavascriptEnabled',false);$pdf=new \Dompdf\Dompdf($opts);$pdf->setPaper('A4','portrait');$pdf->loadHtml($html,'UTF-8');$pdf->render();$pdf->stream(count($groups)===1?'LR-'.$groups[0]['id'].'.pdf':'LR-Batch.pdf',['Attachment'=>true]);exit;
    }
  }
  respond(['error'=>'Route not found.'],404);
}catch(JsonException|InvalidArgumentException $e){respond(['error'=>$e instanceof JsonException?'Invalid request data.':$e->getMessage()],422);}
catch(DomainException $e){respond(['error'=>$e->getMessage()],409);}
catch(\PhpOffice\PhpSpreadsheet\Reader\Exception $e){respond(['error'=>'The workbook could not be read. Save it as a new XLSX file in Excel and try again.'],422);}
catch(Throwable $e){error_log((string)$e);respond(['error'=>'Server could not complete the request. Check its configuration and logs.'],500);}
