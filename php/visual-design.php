<?php
declare(strict_types=1);

/**
 * Secure visual LR templates. All authored content is a bounded structured schema;
 * there is no arbitrary CSS, HTML, JavaScript or remote asset fetching.
 * Coordinates are points relative to a single 595.27 x 410 pt LR half.
 */
const LR_VISUAL_WIDTH=595.27;
const LR_VISUAL_HEIGHT=410.0;
const LR_VISUAL_BINDINGS=[
  'carrier','transport_address','lr_no','date','consignor','consignor_address',
  'consignee','destination','product','qty','ref_no','weight','declared_value',
  'base_freight','total_freight','charge_bc','charge_hamali','charge_other',
  'charge_delivery','charge_risk','continuation','signature_name','draft_stamp'
];
const LR_VISUAL_REQUIRED=['carrier','lr_no','date','consignor','consignee','destination','product','qty','total_freight'];
const LR_VISUAL_FONT=['sans','serif','mono'];
const LR_VISUAL_ALIGN=['left','center','right'];
const LR_VISUAL_KINDS=['field','text','rect','line','qr','image'];

function lrVisualColor(mixed $color):bool {
  return is_string($color) && (bool)preg_match('/^#[a-fA-F0-9]{6}$/D',$color);
}
function lrVisualFinite(mixed $value,float $min,float $max):bool {
  return (is_int($value)||is_float($value)) && is_finite((float)$value) && $value >= $min && $value <= $max;
}
function lrVisualTemplateId(mixed $id):bool {
  return is_string($id) && (bool)preg_match('/^[a-f0-9]{16}$/D',$id);
}
function lrVisualStarter(string $base='clean'):array {
  if(!in_array($base,['classic','clean'],true))throw new InvalidArgumentException('Select Classic or Print Clean as a starting template.');
  $elements=[];$count=0;
  $put=static function(string $kind,float $x,float $y,float $w,float $h,array $extra=[])use(&$elements,&$count):void {
    $elements[]=array_merge(['id'=>'layer-'.(++$count),'kind'=>$kind,'x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h,
      'size'=>9,'font'=>'sans','bold'=>false,'italic'=>false,'underline'=>false,
      'align'=>'left','color'=>'#111827','fill'=>'#ffffff','stroke'=>'#64748b','strokeWidth'=>0.6,
      'text'=>'','binding'=>'','data'=>''], $extra);
  };
  $clean=$base==='clean';$ink=$clean?'#334155':'#333333';
  $put('text',25,12,545,18,['text'=>$clean?'LORRY RECEIPT':'TRANSPORT RECEIPT','size'=>8,'bold'=>true,'align'=>'center','color'=>'#64748b']);
  $put('field',99,28,395,29,['binding'=>'carrier','size'=>20,'bold'=>true,'align'=>'center','color'=>'#111827']);
  $put('field',80,58,438,24,['binding'=>'transport_address','size'=>8,'align'=>'center']);
  $put('line',27,92,541,1,['stroke'=>$ink,'strokeWidth'=>1.0]);
  $put('text',29,102,100,13,['text'=>'CONSIGNOR','size'=>9,'bold'=>true]);
  $put('text',307,102,110,13,['text'=>'CONSIGNEE','size'=>9,'bold'=>true]);
  $put('field',29,117,263,18,['binding'=>'consignor','size'=>10,'bold'=>true]);
  $put('field',307,117,258,18,['binding'=>'consignee','size'=>10,'bold'=>true]);
  $put('field',29,138,263,32,['binding'=>'consignor_address','size'=>8]);
  $put('field',307,138,252,25,['binding'=>'destination','size'=>9]);
  $put('text',408,83,47,10,['text'=>'LR No.','size'=>9,'bold'=>true]);
  $put('field',461,83,99,11,['binding'=>'lr_no','size'=>11,'bold'=>true,'align'=>'right']);
  $put('text',408,97,40,12,['text'=>'Date','size'=>8]);
  $put('field',456,97,104,12,['binding'=>'date','size'=>9,'align'=>'right']);
  $put('line',27,174,541,1,['stroke'=>$ink,'strokeWidth'=>.85]);
  $put('rect',27,180,541,24,['fill'=>$clean?'#f0f4ff':'#ffffff','stroke'=>$ink,'strokeWidth'=>.6]);
  $put('text',29,184,60,14,['text'=>'Articles','bold'=>true,'size'=>8,'align'=>'center']);
  $put('text',103,184,222,14,['text'=>'Particulars / Reference','bold'=>true,'size'=>8]);
  $put('text',337,184,55,14,['text'=>'Weight','bold'=>true,'size'=>8,'align'=>'center']);
  $put('text',488,184,78,14,['text'=>'Freight','bold'=>true,'size'=>8,'align'=>'right']);
  foreach([92,330,392,483] as $x)$put('line',$x,180,1,170,['stroke'=>$ink]);
  $put('line',27,351,541,1,['stroke'=>$ink]);
  $put('field',30,218,58,50,['binding'=>'qty','size'=>12,'bold'=>true,'align'=>'center']);
  $put('field',103,215,217,69,['binding'=>'product','size'=>10,'bold'=>$clean]);
  $put('field',103,299,217,22,['binding'=>'ref_no','size'=>8]);
  $put('field',336,219,52,29,['binding'=>'weight','size'=>10,'align'=>'center']);
  $put('field',490,218,72,24,['binding'=>'base_freight','size'=>10,'align'=>'right']);
  $put('text',405,250,78,14,['text'=>'B.C.','size'=>8]);
  $put('field',490,250,72,14,['binding'=>'charge_bc','size'=>8,'align'=>'right']);
  $put('text',405,267,78,14,['text'=>'Hamali','size'=>8]);
  $put('field',490,267,72,14,['binding'=>'charge_hamali','size'=>8,'align'=>'right']);
  $put('text',405,285,78,14,['text'=>'Others','size'=>8]);
  $put('field',490,285,72,14,['binding'=>'charge_other','size'=>8,'align'=>'right']);
  $put('text',405,303,78,14,['text'=>'Delivery / Risk','size'=>8]);
  $put('field',490,303,72,14,['binding'=>'charge_delivery','size'=>8,'align'=>'right']);
  $put('rect',396,328,170,29,['fill'=>$clean?'#ecfdf5':'#f8fafc','stroke'=>$ink,'strokeWidth'=>.8]);
  $put('text',400,338,82,14,['text'=>'Total ₹','bold'=>true,'size'=>11]);
  $put('field',485,335,77,19,['binding'=>'total_freight','size'=>11,'bold'=>true,'align'=>'right']);
  $put('field',106,359,162,15,['binding'=>'continuation','size'=>8,'italic'=>true]);
  $put('field',27,382,210,18,['binding'=>'draft_stamp','size'=>8,'bold'=>true]);
  $put('text',420,389,139,11,['text'=>'Authorised Signatory','size'=>8,'align'=>'right']);
  $put('qr',268,298,72,72);
  return ['name'=>$clean?'Custom Print Clean':'Custom Classic','base'=>$base,'elements'=>$elements];
}
function lrVisualValidate(array $template,bool $publish=false):array {
  $errors=[];$warnings=[];
  $name=$template['name']??null;
  if(!is_string($name)||strlen(trim($name))<3||strlen($name)>140||preg_match('/[<>]/',$name))$errors[]='Name must be 3–70 plain characters.';
  $elements=$template['elements']??null;
  if(!is_array($elements)||!array_is_list($elements)||count($elements)>110||count($elements)<1){
    $errors[]='Template requires 1–110 layers.';return ['errors'=>$errors,'warnings'=>$warnings];
  }
  $ids=[];$bindings=[];$textLayers=[];$imageBytes=0;
  foreach($elements as $index=>$e){
    $label='Layer '.($index+1);
    if(!is_array($e)){$errors[]="$label has invalid properties.";continue;}
    $id=$e['id']??null;$kind=$e['kind']??null;
    if(!is_string($id)||!preg_match('/^[a-zA-Z0-9-]{1,48}$/D',$id)||isset($ids[$id]))$errors[]="$label has a missing or duplicate ID.";
    else $ids[$id]=true;
    if(!in_array($kind,LR_VISUAL_KINDS,true)){$errors[]="$label has an unsupported type.";continue;}
    foreach(['x','y','w','h'] as $dim)if(!lrVisualFinite($e[$dim]??null,($dim==='w'||$dim==='h')?0.5:0,($dim==='x'||$dim==='w')?LR_VISUAL_WIDTH:LR_VISUAL_HEIGHT)){
      $errors[]="$label has invalid $dim.";continue 2;
    }
    $x=(float)$e['x'];$y=(float)$e['y'];$w=(float)$e['w'];$h=(float)$e['h'];
    if($x+$w>LR_VISUAL_WIDTH+0.01||$y+$h>LR_VISUAL_HEIGHT+0.01)$errors[]="$label extends outside an LR half.";
    if(!lrVisualColor($e['color']??null)||!lrVisualColor($e['fill']??null)||!lrVisualColor($e['stroke']??null))$errors[]="$label must use approved hex colors.";
    if(!lrVisualFinite($e['strokeWidth']??null,0,4))$errors[]="$label has invalid border thickness.";
    if(!lrVisualFinite($e['size']??null,5,32))$errors[]="$label needs a 5–32 pt font size.";
    if(!in_array($e['font']??null,LR_VISUAL_FONT,true)||!in_array($e['align']??null,LR_VISUAL_ALIGN,true))$errors[]="$label has unsupported text formatting.";
    foreach(['bold','italic','underline'] as $prop)if(!is_bool($e[$prop]??null))$errors[]="$label has invalid $prop setting.";
    if($kind==='field'){
      if(!in_array($e['binding']??null,LR_VISUAL_BINDINGS,true))$errors[]="$label has unknown LR binding.";
      else $bindings[$e['binding']]=true;
    }
    if($kind==='text'){
      if(!is_string($e['text']??null)||strlen($e['text'])>960)$errors[]="$label text exceeds 240 characters.";
      if(str_contains((string)($e['text']??''),'<'))$errors[]="$label cannot contain HTML.";
    }
    if($kind==='qr' && ($w<62||$h<62))$errors[]="$label QR must be at least 62 × 62 pt to remain scannable.";
    if($kind==='image'){
      $data=$e['data']??null;
      if(!is_string($data)||!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/]+={0,2})$#D',$data,$m)){$errors[]="$label requires a PNG or JPEG logo.";}
      else{
        $bytes=base64_decode($m[2],true);$imageBytes+=strlen($bytes?:'');
        $info=$bytes?@getimagesizefromstring($bytes):false;
        if(!$info||$info[0]>1200||$info[1]>1200||!in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true))$errors[]="$label contains invalid or oversized image data.";
      }
    }
    if(in_array($kind,['field','text'],true)){
      if($h < (float)$e['size']*.7)$warnings[]="$label may be too short for its font size.";
      $textLayers[]=['index'=>$index,'kind'=>$kind,'x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h];
    }
  }
  if($imageBytes>150000)$errors[]='Embedded images total more than 150 KB.';
  if($publish)foreach(LR_VISUAL_REQUIRED as $required)if(!isset($bindings[$required]))$errors[]='Required LR field missing: '.$required.'.';
  foreach($textLayers as $i=>$a){
    foreach(array_slice($textLayers,$i+1) as $b){
      $dx=min($a['x']+$a['w'],$b['x']+$b['w'])-max($a['x'],$b['x']);
      $dy=min($a['y']+$a['h'],$b['y']+$b['h'])-max($a['y'],$b['y']);
      if($dx>5&&$dy>5&&$dx*$dy>min($a['w']*$a['h'],$b['w']*$b['h'])*.4){
        $warnings[]='Some text layers overlap. Review the preview before publishing.';break 2;
      }
    }
  }
  return ['errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings))];
}
function lrVisualLibrary():array {
  $data=readJson(dbRoot().'/print-templates.json');
  if(!$data)return ['revision'=>0,'templates'=>[]];
  if(!is_int($data['revision']??null)||!is_array($data['templates']??null)||!array_is_list($data['templates']))throw new RuntimeException('Print templates storage is invalid.');
  return $data;
}
function lrVisualPublic():array {
  $out=[];
  foreach(lrVisualLibrary()['templates'] as $t){
    if(!isset($t['published']))continue;
    $out[]=['id'=>$t['id'],'name'=>$t['name'],'version'=>$t['published']['version']];
  }
  return $out;
}
function lrVisualAdmin():array {return lrVisualLibrary();}
function lrVisualMutate(array $payload,callable $fn):array {
  $revision=$payload['revision']??null;
  if(!is_int($revision)||$revision<0)throw new InvalidArgumentException('The current template revision is required.');
  $lock=fopen(dbRoot().'/print-templates.lock','c');
  if(!$lock)throw new RuntimeException('Could not open print template lock.');
  try{
    if(!flock($lock,LOCK_EX))throw new RuntimeException('Could not lock print templates.');
    $library=lrVisualLibrary();
    if($library['revision']!==$revision)throw new DomainException('Print templates changed in another session. Reload before saving.');
    $fn($library);
    $library['revision']++;
    writeJson(dbRoot().'/print-templates.json',$library);
    return $library;
  }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function lrVisualMutateAction(string $action,array $payload):array {
  return lrVisualMutate($payload,static function(array &$library)use($action,$payload):void{
    if($action==='visual_create'){
      if(count($library['templates'])>=20)throw new DomainException('You can keep up to 20 visual templates.');
      $base=$payload['base']??'clean';if(!is_string($base))throw new InvalidArgumentException('Invalid starter.');
      $starter=lrVisualStarter($base);
      $starter['name']=isset($payload['name'])?$payload['name']:$starter['name'];
      $check=lrVisualValidate($starter);
      if($check['errors'])throw new InvalidArgumentException(implode(' ',$check['errors']));
      $library['templates'][]=['id'=>bin2hex(random_bytes(8)),'name'=>$starter['name'],'base'=>$base,
        'draft'=>$starter['elements'],'published'=>null,'history'=>[]];
      return;
    }
    $id=$payload['id']??null;
    if(!lrVisualTemplateId($id))throw new InvalidArgumentException('Invalid template ID.');
    $slot=null;
    foreach($library['templates'] as $i=>$t)if($t['id']===$id){$slot=$i;break;}
    if($slot===null)throw new InvalidArgumentException('Print template not found.');
    $template=&$library['templates'][$slot];
    if($action==='visual_save'){
      $next=['name'=>$payload['name']??null,'elements'=>$payload['elements']??null];
      $check=lrVisualValidate($next);
      if($check['errors'])throw new InvalidArgumentException(implode(' ',$check['errors']));
      $template['name']=$next['name'];$template['draft']=$next['elements'];
    }elseif($action==='visual_publish'){
      $next=['name'=>$template['name'],'elements'=>$template['draft']];
      $check=lrVisualValidate($next,true);
      if($check['errors'])throw new InvalidArgumentException(implode(' ',$check['errors']));
      if($check['warnings']&&($payload['confirm_warnings']??false)!==true)
        throw new DomainException(implode(' ',$check['warnings']).' Confirm warnings before publishing.');
      lrVisualProof($next);
      $version=(int)($template['published']['version']??0)+1;
      $published=['version'=>$version,'name'=>$template['name'],'elements'=>$template['draft'],'published_at'=>gmdate(DATE_ATOM)];
      $template['published']=$published;$template['history'][]=$published;
      if(count($template['history'])>20)$template['history']=array_slice($template['history'],-20);
    }elseif($action==='visual_revert'){
      $version=$payload['version']??null;
      if(!is_int($version)||$version<1)throw new InvalidArgumentException('Select a valid version.');
      $past=null;
      foreach($template['history'] as $versioned)if($versioned['version']===$version)$past=$versioned;
      if(!$past)throw new InvalidArgumentException('Published version not found.');
      $template['name']=$past['name'];$template['draft']=$past['elements'];
    }elseif($action==='visual_delete'){
      if(isset($template['published']))throw new DomainException('Published templates cannot be deleted. Restore or republish them instead.');
      unset($template);array_splice($library['templates'],$slot,1);
    }else throw new InvalidArgumentException('Unsupported template change.');
  });
}
function lrVisualPublished(string $id,?int $version=null):array {
  if(!lrVisualTemplateId($id))throw new InvalidArgumentException('Unknown design.');
  foreach(lrVisualLibrary()['templates'] as $t){
    if($t['id']!==$id||!isset($t['published']))continue;
    if($version===null)return $t['published'];
    foreach($t['history'] as $item)if($item['version']===$version)return $item;
    throw new InvalidArgumentException('This design version is no longer available.');
  }
  throw new InvalidArgumentException('This print design is not published.');
}
function lrVisualDraft(string $id):array {
  if(!lrVisualTemplateId($id))throw new InvalidArgumentException('Unknown design.');
  foreach(lrVisualLibrary()['templates'] as $t)if($t['id']===$id)return ['name'=>$t['name'],'elements'=>$t['draft']];
  throw new InvalidArgumentException('Print template not found.');
}
function lrVisualFieldValue(string $binding,array $g,array $items,int $index,int $count,bool $draft):string {
  $c=$g['common'];[$base,$total]=lrAmounts($c);
  $itemText=static function(string $key)use($items):string{
    return implode("\n",array_values(array_filter(array_map(static fn($item)=>(string)($item[$key]??''),$items),static fn($v)=>$v!=='')));
  };
  return match($binding){
    'carrier'=>(string)$c['carrier'],'transport_address'=>(string)$c['address'],
    'lr_no'=>(string)$g['id'],'date'=>($c['date']?date('d-m-Y',strtotime($c['date'])):''),
    'consignor'=>(string)$c['company'],'consignor_address'=>(string)$c['consignor_address'],
    'consignee'=>(string)$c['consignee'],'destination'=>(string)$c['destination'],
    'product'=>$itemText('product'),'qty'=>$itemText('qty'),'ref_no'=>$itemText('obd'),
    'weight'=>$itemText('weight'),'declared_value'=>$c['value']!==''?money(paise($c['value'])):'',
    'base_freight'=>$index===$count-1?money($base):'',
    'total_freight'=>$index===$count-1?money($total):'',
    'charge_bc'=>$index===$count-1?money($c['charges']['bc']):'',
    'charge_hamali'=>$index===$count-1?money($c['charges']['hamali']):'',
    'charge_other'=>$index===$count-1?money($c['charges']['other']):'',
    'charge_delivery'=>$index===$count-1?money($c['charges']['delivery']):'',
    'charge_risk'=>$index===$count-1?money($c['charges']['risk']):'',
    'continuation'=>$count>1?'Continuation '.($index+1).' / '.$count:'',
    'signature_name'=>(string)$c['carrier'],
    'draft_stamp'=>$draft?'DRAFT · NOT ISSUED':'',default=>''
  };
}
/**
 * Return a real issued LR's first-half values for the admin canvas.
 * The browser never fabricates or truncates this data, so it can compare
 * its layout with the same saved draft opened via action=preview.
 */
function lrVisualSample(string $key):array {
  $record=registry()[$key]??null;
  if(!is_array($record))respond(['error'=>'Issued LR not found.'],404);
  $chunks=array_chunk($record['items'],4);
  if(!$chunks)throw new InvalidArgumentException('The selected LR contains no items.');
  $values=[];
  foreach(LR_VISUAL_BINDINGS as $binding){
    $values[$binding]=lrVisualFieldValue($binding,$record,$chunks[0],0,count($chunks),false);
  }
  $qr=lrQrImages([$record],false)[$key]??['png'=>null,'mode'=>'unavailable'];
  return ['key'=>$key,'id'=>$record['id'],'values'=>$values,
          'qr_png'=>$qr['png']??null,'qr_mode'=>$qr['mode']];
}
function lrVisualHalf(array $entry,bool $draft,bool $lower,array $qr,array $template):string {
  [$g,$items,$index,$count]=$entry;
  $out='<div class="half visual'.($lower?' lower':'').'">';
  foreach($template['elements'] as $e){
    $kind=$e['kind'];
    $x=(float)$e['x'];$y=(float)$e['y'];$w=(float)$e['w'];$h=(float)$e['h'];
    $style='left:'.$x.'pt;top:'.$y.'pt;width:'.$w.'pt;height:'.$h.'pt;';
    if($kind==='line'){
      $direction=$w>=$h?'border-top':'border-left';
      $out.='<div aria-hidden="true" class="vshape" style="'.$style.$direction.':'.$e['strokeWidth'].'pt solid '.$e['stroke'].'"></div>';
      continue;
    }
    if($kind==='rect'){
      $out.='<div aria-hidden="true" class="vshape" style="'.$style.'background:'.$e['fill'].';border:'.$e['strokeWidth'].'pt solid '.$e['stroke'].'"></div>';
      continue;
    }
    if($kind==='qr'){
      if(($qr['png']??null)!==null){
        $out.='<img class="vqr" alt="QR for LR" style="'.$style.'" src="data:image/png;base64,'.$qr['png'].'">';
      }
      continue;
    }
    if($kind==='image'){
      $out.='<img class="vimage" alt="Brand logo" style="'.$style.'object-fit:contain" src="'.htmlspecialchars($e['data'],ENT_QUOTES,'UTF-8').'">';
      continue;
    }
    $value=$kind==='field'?lrVisualFieldValue($e['binding'],$g,$items,$index,$count,$draft):$e['text'];
    if($value==='')continue;
    $size=(float)$e['size'];$lines=explode("\n",$value);
    $longest=max(array_map(static fn($s)=>mb_strlen($s,'UTF-8'),$lines));
    $minByWidth=$w/max(1,$longest)*1.28;
    $minByHeight=$h/max(1,count($lines))/1.22;
    $actual=min($size,$minByWidth,$minByHeight);
    if($actual<5){
      // Never silently crop the dispatch details just to satisfy the template.
      throw new DomainException('Print template has insufficient space for '.($kind==='field'?$e['binding']:'a label').'. Resize it or decrease its font size.');
    }
    $font=match($e['font']){'serif'=>'"DejaVu Serif",serif','mono'=>'"DejaVu Sans Mono",monospace',default=>'"DejaVu Sans",sans-serif'};
    $css='font-family:'.$font.';font-size:'.round($actual,2).'pt;'
      .'font-weight:'.($e['bold']?'bold':'normal').';font-style:'.($e['italic']?'italic':'normal').';'
      .'text-decoration:'.($e['underline']?'underline':'none').';text-align:'.$e['align'].';'
      .'color:'.$e['color'].';line-height:1.16;white-space:normal;overflow:visible;';
    $out.='<div class="vtext" data-layer="'.htmlspecialchars($e['id'],ENT_QUOTES,'UTF-8').'" style="'.$style.$css.'">'.nl2br(htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),false).'</div>';
  }
  return $out.'</div>';
}
function lrVisualHtml(array $groups,bool $draft,array $template,bool $includeQr):string {
  $pages=[];$qr=$includeQr?lrQrImages($groups,$draft):[];
  $blank=['png'=>null,'mode'=>'off'];
  foreach($groups as $g){
    $chunks=array_chunk($g['items'],4);$count=count($chunks);
    foreach($chunks as $i=>$items){
      $entry=[$g,$items,$i,$count];$last=array_key_last($pages);
      if($last!==null&&$pages[$last][1]===null&&$pages[$last][0][0]['id']!==$g['id'])$pages[$last][1]=$entry;
      else $pages[]=[$entry,null];
    }
  }
  $css='@page{size:A4 portrait;margin:0}*{box-sizing:border-box}html,body{margin:0;padding:0;color:#000;background:#fff;font-family:"DejaVu Sans",sans-serif}'
    .'.sheet{position:relative;width:595.27pt;height:828pt;overflow:hidden;background:#fff}.sheet.next{page-break-before:always}'
    .'.half.visual{position:absolute;left:0;top:0;width:595.27pt;height:410pt;background:#fff}.half.visual.lower{top:415.9pt}'
    .'.vshape,.vtext,.vqr,.vimage{position:absolute}.vtext{word-wrap:break-word}'
    .'@media screen{body{background:#e5e7eb}.sheet{margin:12px auto;box-shadow:0 1px 6px #888}}';
  $html='<!doctype html><html lang="en"><meta charset="utf-8"><title>Visual LR · A4</title><style>'.$css.'</style><body data-layout="visual">';
  foreach($pages as $i=>[$first,$second]){
    $html.='<div class="sheet'.($i>0?' next':'').'">'.lrVisualHalf($first,$draft,false,$includeQr?$qr[$first[0]['key']]:$blank,$template);
    if($second)$html.=lrVisualHalf($second,$draft,true,$includeQr?$qr[$second[0]['key']]:$blank,$template);
    $html.='</div>';
  }
  return $html.'</body></html>';
}

/** Validate the real Dompdf output before any draft can become published. */
function lrVisualProof(array $template):void {
  vendor();
  $common=[
    'carrier'=>'SHRIRAM TRANSPORT',
    'address'=>'MILKAT 2345, Pune Saswad Road, Uruli Devachi - 412308, Maharashtra',
    'company'=>'C/O Shriram Enterprises',
    'consignor_address'=>"Urulidevachi, Pune 412308, Maharashtra",
    'consignee'=>'Example Customer Private Limited',
    'destination'=>'Pune, Maharashtra',
    'date'=>'2026-11-21','source_freight'=>245000,'freight_mode'=>'base',
    'value'=>'','charges'=>['bc'=>5000,'hamali'=>0,'other'=>0,'delivery'=>0,'risk'=>0]
  ];
  $item=['product'=>'Packaging materials','qty'=>'12','obd'=>'REF-1001',
         'ref2'=>'','weight'=>'320','litres'=>''];
  $groups=[];
  foreach([1,2] as $i)$groups[]=['id'=>'PROOF-'.$i,'key'=>'proof-'.$i,
                                    'common'=>$common,'items'=>[$item]];
  $html=lrVisualHtml($groups,false,$template,false);
  $opts=new \Dompdf\Options();
  $opts->set('defaultMediaType','print');
  $opts->set('isRemoteEnabled',false);
  $opts->set('isPhpEnabled',false);
  $opts->set('isJavascriptEnabled',false);
  $pdf=new \Dompdf\Dompdf($opts);
  $pdf->setPaper('A4','portrait');
  $pdf->loadHtml($html,'UTF-8');$pdf->render();
  if($pdf->getCanvas()->get_page_count()!==1)
    throw new DomainException('PDF proof failed: two different LRs do not fit one A4 sheet. Adjust your design before publishing.');
}
