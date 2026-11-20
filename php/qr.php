<?php
declare(strict_types=1);
function lrAmounts(array $c):array {
  $f=$c['source_freight'];$fees=array_sum($c['charges']);
  $base=$f===null?null:($c['freight_mode']==='base'?$f:$f-$fees);
  return [$base,$base===null?null:$base+$fees];
}
function lrQrText(array $g,bool $draft):string {
  $c=$g['common'];[$base,$total]=lrAmounts($c);
  $out=['LR: '.$g['id'].($draft?' (DRAFT - NOT ISSUED)':''),'Date: '.$c['date']];
  $add=static function(string $label,string $value)use(&$out){
    if($value!=='')$out[]=$label.': '.trim((string)preg_replace('/\s+/u',' ',$value));
  };
  foreach(['carrier'=>'Transporter','address'=>'Transporter address','company'=>'Consignor','consignor_address'=>'Consignor address','consignee'=>'Consignee','destination'=>'Destination','value'=>'Declared value (INR)'] as $k=>$label)$add($label,$c[$k]);
  foreach($g['items'] as $i=>$item){
    $out[]='Item '.($i+1).': '.$item['product'];
    foreach(['qty'=>'Articles','obd'=>'OBD','ref2'=>'Reference 2','litres'=>'Litres','weight'=>'Weight (kg)'] as $k=>$label)$add($label,$item[$k]);
  }
  $add('Base freight (INR)',money($base));
  foreach(CHARGES as $key=>$label)$add($label.' (INR)',money($c['charges'][$key]));
  $add('Total freight (INR)',money($total));
  return implode("\n",$out);
}
function lrQrImages(array $groups,bool $draft):array {
  $base=getenv('LR_QR_DETAILS_ENDPOINT')?:'';
  $allowLink=!$draft && filter_var($base,FILTER_VALIDATE_URL) && str_starts_with($base,'https://') && !str_contains($base,'?') && !str_contains($base,'#');
  $entries=[];
  foreach($groups as $g)$entries[]=['text'=>lrQrText($g,$draft),'url'=>$allowLink?$base.'?action=details&key='.rawurlencode($g['key']):null];
  // A temp input stream prevents a pipe deadlock even on large batches.
  $input=tmpfile();if(!$input)throw new RuntimeException('Cannot prepare QR data.');
  try{
    $body=json_encode($entries,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if(fwrite($input,$body)!==strlen($body))throw new RuntimeException('Cannot write QR data.');
    rewind($input);
    $p=proc_open([getenv('LR_QR_PYTHON')?:'python3',__DIR__.'/qr_encode.py'],[0=>$input,1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);
    if(!is_resource($p))throw new RuntimeException('Cannot start QR encoder.');
    $json=stream_get_contents($pipes[1]);fclose($pipes[1]);$code=proc_close($p);
    $images=$code===0?json_decode($json,true):null;
    if(!is_array($images)||count($images)!==count($groups))throw new RuntimeException('QR encoder failed. Verify Python qrcode and Pillow are installed for the PHP process.');
    $out=[];foreach($groups as $i=>$g)$out[$g['key']]=$images[$i];return $out;
  }finally{fclose($input);}
}
