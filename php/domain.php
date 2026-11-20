<?php
declare(strict_types=1);
const FIELDS = [
'lr_no'=>['LR number','LR NO','LR NUMBER','L R NO','LORRY RECEIPT NO'], 'lr_date'=>['LR issue date','LR DATE','ISSUE DATE'], 'so_date'=>['Sales order date','SO DATE'],
'consignee'=>['Consignee','SOTP NAME','CONSIGNEE'], 'destination'=>['Destination','SHTP DEST NAME','SHIP TO DEST NAME','DESTINATION'],
'obd'=>['OBD reference','OBD NO','OBD NUMBER'], 'ref2'=>['Second reference','REF 2','REF NO 2'], 'qty'=>['Articles','QTY','ARTICLES'],
'litres'=>['Litres','QUANTITY IN LTR','QUANTITY IN LITRES','QTY LTR','LITRES'], 'weight'=>['Weight (kg)','WEIGHT KG','KG'], 'freight'=>['Freight','FRIGHT','FREIGHT','FREIGHT AMOUNT'],
'product'=>['Product','TYPE','PRODUCT'], 'company'=>['Consignor','COMPANY','CONSIGNOR'], 'consignor_address'=>['Consignor address','CONSIGNOR ADDRESS'],
'carrier'=>['Transporter','TRANSPORT NAME','TRANSPORTER NAME','TRANSPORTER'], 'address'=>['Transporter address','TRANSPORT ADDRESS','TRANSPORTER ADDRESS'],
'bc'=>['B.C.','BC','BOOKING CHARGE'], 'hamali'=>['Hamali','HAMALI'], 'other'=>['Other charges','OTHERS','OTHER CHARGES'],
'delivery'=>['Door delivery','DOOR DELIVERY'], 'risk'=>['Risk charge','RISK CHG','RISK CHARGE'], 'value'=>['Declared value','VALUE RS','DECLARED VALUE']];
const CHARGES=['bc'=>'B.C.','hamali'=>'Hamali','other'=>'Others','delivery'=>'Door Delivery','risk'=>'Risk Chg.'];
const REQUIRED=['lr_no','consignee','destination','qty','freight','company','carrier','product'];
function norm(string $v):string {return trim(preg_replace('/[^A-Z0-9]+/',' ',strtoupper($v)));}
function known(array $headers):array {
    $map=[];foreach(FIELDS as $key=>$spec){$aliases=array_slice($spec,1);$hits=[];foreach($headers as $i=>$h){if(in_array(norm($h),$aliases,true)) $hits[]=$i;}if(count($hits)===1)$map[$key]=$hits[0];}return $map;
}
function dateValue(string $raw):?string {
    foreach(['Y-m-d','d-m-Y','d/m/Y','d.m.Y','j/n/Y','j-n-Y'] as $fmt){$v=DateTimeImmutable::createFromFormat('!'.$fmt,$raw);if($v && $v->format($fmt)===$raw && (int)$v->format('Y')>=1900)return $v->format('Y-m-d');}return null;
}
function numberValue(string $raw,bool $integer=false):?string {
    $raw=trim(preg_replace('/^(₹|Rs\.?)[\s]*/iu','',$raw));
    if(!preg_match('/^(?:\d+|\d{1,3}(?:,\d{3})+|\d{1,2}(?:,\d{2})+,\d{3})(?:\.\d{1,3})?$/D',$raw))return null;
    $v=str_replace(',','',$raw);if((float)$v>999999999 || ($integer && (float)$v!==floor((float)$v)))return null;
    return $integer?(string)(int)$v:$v;
}
function paise(string $v):?int {if(!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D',$v,$m))return null;return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');}
function analyze(array $sheet,array $map,array $rules,array $issued,array $configured=[]):array {
    $seen=[];foreach($map as $f=>$col){if(!isset(FIELDS[$f]) || !is_int($col) || !array_key_exists($col,$sheet['headers']) || isset($seen[$col]))throw new InvalidArgumentException('Map each source column to no more than one valid field.');$seen[$col]=true;}
    if(!in_array($rules['freight']??'',['base','total'],true)) throw new InvalidArgumentException('Choose whether FRIGHT is base freight or final total.');
    if(!in_array($rules['date']??'',['lr_date','so_date','override'],true)) throw new InvalidArgumentException('Select an LR issue date source.');
    if($rules['date']==='override' && !dateValue((string)($rules['override']??''))) throw new InvalidArgumentException('Enter a valid batch issue date.');
    $groups=[];
    foreach($sheet['rows'] as $row){$line=$row['line'];$v=[];$errors=[];
      foreach(FIELDS as $f=>$_){$v[$f]=isset($map[$f])?trim((string)($row['cells'][$map[$f]]??'')):'';}
      // Centrally configured parties override source columns for new drafts only.
      foreach (['consignor_address','carrier','address'] as $field)
        if (($configured[$field]??'')!=='') $v[$field]=$configured[$field];
      foreach(REQUIRED as $f)if($v[$f]==='')$errors[]="Row $line: missing ".FIELDS[$f][0].'.';
      foreach($v as $f=>$s) if(strlen($s)>300 || !preg_match('//u',$s)) $errors[]="Row $line: invalid or oversized ".FIELDS[$f][0].'.';
      $id=$v['lr_no'];if($id!=='' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',$id))$errors[]="Row $line: LR number must use letters, numbers, dots, underscores or hyphens.";
      $date=dateValue($rules['date']==='override'?(string)$rules['override']:$v[$rules['date']]);if(!$date)$errors[]="Row $line: missing or invalid LR issue date.";
      foreach(['qty','litres','weight','freight','bc','hamali','other','delivery','risk','value'] as $f){if($v[$f]==='')continue;$n=numberValue($v[$f],$f==='qty');if($n===null || (in_array($f,['freight','bc','hamali','other','delivery','risk','value'],true)&&paise($n)===null))$errors[]="Row $line: invalid ".FIELDS[$f][0].'.';$v[$f]=$n??'';}
      if($v['qty']!=='' && (int)$v['qty']===0)$errors[]="Row $line: articles must be greater than zero.";
      $freight=$v['freight']!==''?paise($v['freight']):null;
      $charge=[];foreach(CHARGES as $key=>$_)$charge[$key]=$v[$key]===''?null:paise($v[$key]);
      $fee=array_sum($charge);
      if($freight===0 && ($rules['allow_zero']??false)!==true)$errors[]="Row $line: zero freight requires explicit approval.";
      if($rules['freight']==='total' && $freight!==null && $fee>$freight)$errors[]="Row $line: charges exceed the supplied final total.";
      $common=array_intersect_key($v,array_flip(['consignee','destination','company','consignor_address','carrier','address','value']));
      $common['date']=$date; $common['charges']=$charge; $common['source_freight']=$freight; $common['freight_mode']=$rules['freight'];
      $key=$id!==''?strtoupper($id):"@ROW-$line";
      if(!isset($groups[$key]))$groups[$key]=['id'=>$id,'key'=>$key,'common'=>$common,'items'=>[],'rows'=>[],'issues'=>[]];
      $g=&$groups[$key];if($common!==$g['common'])$errors[]="Row $line: repeated LR has conflicting receipt-level fields or amounts; correct the source before issuing.";
      $item=array_intersect_key($v,array_flip(['obd','ref2','qty','litres','weight','product']));
      if(in_array($item,$g['items'],true))$errors[]="Row $line: duplicate item in the same LR; verify the source.";
      $g['items'][]=$item;$g['rows'][]=$line;$g['issues']=array_merge($g['issues'],$errors);unset($g);
    }
    foreach($groups as &$g){$g['status']=isset($issued[$g['key']])?'issued':($g['issues']?'blocked':'ready');if(isset($issued[$g['key']])) $g['issues'][]='Already issued. Reprint the saved receipt from the register.';}
    return array_values($groups);
}
