<?php
declare(strict_types=1);
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function money(?int $p):string{return $p===null?'':number_format($p/100,2,'.','');}
function lrText(string $text,float $x,float $y,float $w,float $size,string $classes=''):string {
  if($text==='')return '';
  $longest=max(array_map(function($s){
    preg_match_all('/./us',$s,$matches);
    $width=0.0;
    foreach($matches[0] as $char)$width+=match(true){
      preg_match('/^[ilI1.,:!| ]$/',$char)===1=>.43,
      preg_match('/^[MW@%]$/',$char)===1=>1.0,
      preg_match('/^[A-Z0-9]$/',$char)===1=>.75,
      strlen($char)>1=>1.05,
      default=>.61,
    };
    return $width;
  },explode("\n",$text)));
  // Size against a conservative character allowance. Never silently clip a field.
  $size=min($size,$w/max(1,$longest));
  return '<div class="field '.e($classes).'" style="left:'.$x.'pt;top:'.$y.'pt;width:'.$w.'pt;font-size:'.round($size,2).'pt">'.nl2br(e($text),false).'</div>';
}
function lrWrap(string $s,int $chars):string {
  $s=trim((string)preg_replace('/\s+/u',' ',$s));
  if(preg_match_all('/./us',$s)<=$chars)return $s;
  preg_match('/^(.{1,'.$chars.'})\s+(.+)$/u',$s,$m);
  return isset($m[2])?$m[1]."\n".$m[2]:$s;
}
function lrHalf(array $entry,bool $draft,bool $lower,array $qr):string {
  [$g,$items,$i,$n]=$entry;$c=$g['common'];$last=$i===$n-1;
  [$base,$total]=lrAmounts($c);
  $carrier=$c['carrier'];$addr=trim($c['address']);
  $addr=preg_replace('/,\s*(Maharashtra)$/iu',",\n$1",$addr);if(!str_contains($addr,"\n"))$addr=lrWrap($addr,75);
  $from=preg_split('/\R/u',trim($c['consignor_address']),2);if(count($from)===1)$from=explode("\n",lrWrap($from[0],54),2);
  $date=$c['date']?date('d-m-Y',strtotime($c['date'])):'';
  $html='<div class="half'.($lower?' lower':'').'">';
  $html.=lrText($carrier,106,34.5,304,20,'center').lrText($addr,103,63.8,300,8,'center');
  $html.=lrText($g['id'],460.77,51.5,104,11).lrText($date,446.22,73.2,116,10);
  $html.=lrText($c['company'],106.53,106.3,196,8,'bi').lrText($c['consignee'],383.91,106.5,179,10,'bi');
  $html.=lrText($from[0]??'',49.25,124.2,250,8,'bi muted').lrText($from[1]??'',49.25,142.2,250,8,'bi muted').lrText($c['destination'],380.9,124.1,179,10,'italic');
  $html.=lrText($carrier,432.14,392.6,131,10,'italic');
  if($draft)$html.=lrText('DRAFT · NOT ISSUED',28,371,230,9,'bi muted');
  if($n>1)$html.=lrText('Continuation '.($i+1).' / '.$n,114,342,125,8,'italic');
  foreach($items as $j=>$item){
    $y=220.5+30*$j;
    // Original form supplies the first Ref.No. label; repeat it only on continuation item rows.
    if($j>0)$html.=lrText('Ref.No.:',97.49,$y,41,9,'italic');
    $html.=lrText($item['obd'],139.71,$y,60,10,'italic');
    if($item['ref2']!=='')$html.=lrText('/',202,$y,6,9,'italic').lrText($item['ref2'],211,$y,31,9,'italic');
    if(count($items)>1 || $item['litres']!=='')$html.=lrText(trim((count($items)>1?$item['product']:'').($item['litres']!==''?' · '.$item['litres'].' L':''),' ·'),97.49,$y+13,144,8,'italic');
    $html.=lrText($item['qty'],30,234.9+30*$j,62,count($items)>1?13:20,'center');
    // The original weight box is shallow. Use one subtotal for multiple items, never litres.
    if(count($items)===1)$html.=lrText($item['weight'],339,229.4,52,8,'center');
  }
  if(count($items)>1 && !in_array('',array_column($items,'weight'),true))$html.=lrText((string)array_sum(array_column($items,'weight')),339,229.4,52,8,'center');
  if(count($items)===1){
    $product=$items[0]['product'];
    $html.=lrText(lrWrap($product,19),339,342.5,53,8,'center italic');
    if(preg_match_all('/./us',$product)>30)$html.=lrText($product,97.49,251,144,8,'italic');
  }
  if($qr['png']){
    $label=$qr['mode']==='link'?'Scan for LR details online':'Scan for LR details';
    $html.='<img class="lr-qr" alt="'.e($label).'" src="data:image/png;base64,'.$qr['png'].'">';
    $html.=lrText($label,241,349,86,6,'center');
  }elseif(($qr['mode']??'')!=='off') $html.=lrText("Full LR exceeds print QR capacity.\nView details in the LR register.",241,307,86,7,'center');
  if($last){
    $html.=lrText(money($base),480,222.6,80,11,'right');
    foreach(array_combine(array_keys(CHARGES),[242,259,277.1,295.2,313.2]) as $key=>$y)$html.=lrText(money($c['charges'][$key]),480,$y,80,$key==='bc'?12:9,'right');
    $html.=lrText($c['value']!==''?money(paise($c['value'])):'',340,278,52,8,'center').lrText(money($total),480,338.6,80,11,'right');
  }
  return $html.'</div>';
}
function receiptHtml(array $groups,bool $draft=false,string $layout='classic',bool $includeQr=true):string {
  require_once __DIR__.'/receipt-clean.php';
  $clean=$layout==='clean';
  $render=$clean?'lrCleanHalf':'lrHalf';
  $img='data:image/png;base64,'.base64_encode(file_get_contents(__DIR__.'/assets/lr-half.png'));
  $pages=[];$qr=$includeQr?lrQrImages($groups,$draft):[];
  $noQr=['png'=>null,'mode'=>'off'];
  foreach($groups as $g){
    $chunks=array_chunk($g['items'],4);$n=count($chunks);
    foreach($chunks as $i=>$items){
      $entry=[$g,$items,$i,$n];
      $last=array_key_last($pages);
      if($last!==null && $pages[$last][1]===null && $pages[$last][0][0]['id']!==$g['id'])$pages[$last][1]=$entry;
      else $pages[]=[$entry,null];
    }
  }
  $css='@page{size:A4 portrait;margin:0}*{box-sizing:border-box}html,body{margin:0;padding:0;color:#000;font-family:Verdana,"DejaVu Sans",sans-serif}.sheet{position:relative;width:595.27pt;height:828pt;background:#fff;overflow:hidden}.sheet.next{page-break-before:always}.half{position:absolute;left:0;top:0;width:595.27pt;height:420pt;background-image:url("'.$img.'");background-position:0 0;background-repeat:no-repeat;background-size:595.27pt 420pt;print-color-adjust:exact;-webkit-print-color-adjust:exact}.half.lower{top:403.42pt}.lr-qr{position:absolute;left:242pt;top:264pt;width:84pt;height:84pt}.field{position:absolute;line-height:1.13}.center{text-align:center}.right{text-align:right}.italic,.bi{font-style:italic}.bi{font-weight:bold}.muted{color:#555}@media screen{body{background:#e5e7eb}.sheet{margin:12px auto;box-shadow:0 1px 5px #888}}';
  if($clean)$css.='.half.clean{height:410pt;background-image:none;font-family:"DejaVu Sans",sans-serif}.half.clean.lower{top:415.9pt}.bold{font-weight:bold}.clean-cut{position:absolute;top:420.9pt;left:27pt;width:541pt;border-top:.5pt dashed #606060}';
  $html='<!doctype html><html lang="en"><meta charset="utf-8"><title>Lorry receipt · A4 portrait</title><style>'.$css.'</style><body data-layout="'.e($layout).'">';
  foreach($pages as $i=>[$top,$bottom])$html.='<div class="sheet'.($i>0?' next':'').'">'.$render($top,$draft,false,($includeQr?$qr[$top[0]['key']]:$noQr)).($bottom?($clean?'<div class="clean-cut" aria-hidden="true"></div>':'').$render($bottom,$draft,true,($includeQr?$qr[$bottom[0]['key']]:$noQr)):'').'</div>';
  return $html.'</body></html>';
}
