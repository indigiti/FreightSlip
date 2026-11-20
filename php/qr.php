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
function lrQrPng(string $text): ?string {
  // Bound the printed density; never truncate the receipt data.
  if(strlen($text)>520)return null;
  $code=\Endroid\QrCode\QrCode::create($text)
    ->setEncoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'))
    ->setErrorCorrectionLevel(\Endroid\QrCode\ErrorCorrectionLevel::Medium)
    ->setSize(540)->setMargin(110);
  return base64_encode((new \Endroid\QrCode\Writer\PngWriter())->write($code)->getString());
}
function lrQrImages(array $groups,bool $draft):array {
  vendor();
  $base=getenv('LR_QR_DETAILS_ENDPOINT')?:'';
  $allowLink=!$draft && filter_var($base,FILTER_VALIDATE_URL) && str_starts_with($base,'https://') && !str_contains($base,'?') && !str_contains($base,'#');
  $out=[];
  foreach($groups as $g){
    $png=lrQrPng(lrQrText($g,$draft));$mode='offline';
    if($png===null && $allowLink){$png=lrQrPng($base.'?action=details&key='.rawurlencode($g['key']));$mode='link';}
    $out[$g['key']]=['png'=>$png,'mode'=>$png===null?'unavailable':$mode];
  }
  return $out;
}
