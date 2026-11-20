<?php
declare(strict_types=1);

function lrCleanHalf(array $entry, bool $draft, bool $lower, array $qr): string {
    [$g,$items,$index,$count]=$entry;
    $c=$g['common']; [$base,$total]=lrAmounts($c);
    $t='lrText';
    $box=static function($x,$y,$w,$h,$css): string {
        return '<div aria-hidden="true" style="position:absolute;left:'.$x.'pt;top:'.$y.'pt;width:'.$w.'pt;height:'.$h.'pt;'.$css.'"></div>';
    };
    $h=static fn($x,$y,$w,$heavy=false)=>$box($x,$y,$w,0,'border-top:'.($heavy?'.65':'.45').'pt solid #606060');
    $v=static fn($x,$y,$height)=>$box($x,$y,0,$height,'border-left:.45pt solid #606060');
    $html='<div class="half clean'.($lower?' lower':'').'">';
    $html.=$box(27,181,541,24,'background:#f5f5f5').$box(393,330,175,30,'background:#f5f5f5');
    foreach([96,163,181,205,360,406] as $y)$html.=$h(27,$y,541,true);
    foreach([27,93,334,393,485,568] as $x)$html.=$v($x,181,179);
    foreach([245,260,274,288,302,316,330] as $y)$html.=$h(393,$y,175);
    $html.=$h(334,260,59);
    $addr=trim((string)preg_replace('/,\s*(Maharashtra)$/iu',",\n$1",trim($c['address'])));
    if(!str_contains($addr,"\n"))$addr=lrWrap($addr,66);
    $from=preg_split('/\R/u',trim($c['consignor_address']),2);
    if(count($from)===1)$from=explode("\n",lrWrap($from[0],57),2);
    $html.=$t($c['carrier'],101,33,300,21,'bold center').$t($addr,100,60,300,8.3,'center');
    $html.=$t('LR No.',409,44,49,9).$t($g['id'],454,39,96,14,'right bold').$t('Date',409,64,45,9).$t(date('d-m-Y',strtotime($c['date'])),455,63,95,10,'right');
    $html.=$t('CONSIGNOR M/S.',28,101,265,8,'bold muted').$t('CONSIGNEE M/S.',307,101,251,8,'bold muted');
    $html.=$t(lrWrap($c['company'],51),28,114,265,10,'bold').$t(lrWrap($c['consignee'],46),307,114,251,10,'bold');
    $html.=$t($from[0]??'',28,139,265,8).$t($from[1]??'',28,150,265,8).$t(lrWrap($c['destination'],50),307,139,251,9,'italic');
    $html.=$t('To.',334,166,40,8.5,'center');
    foreach([[27,183,66,"No. of\nArticles"],[93,187,241,'Particulars'],[334,187,59,'K.G.'],[393,187,92,'Private Marks'],[485,187,83,'Freight (Rs.)']] as [$x,$y,$w,$label])$html.=$t($label,$x,$y,$w,9,'bold center');
    $html.=$t('For:',397,390,28,9).$t($c['carrier'],425,389,133,10,'right italic');
    if($draft)$html.=$t('DRAFT · NOT ISSUED',28,384,210,9,'bold muted');
    if($count>1)$html.=$t('Continuation '.($index+1).' / '.$count,102,343,135,8,'italic');
    foreach($items as $j=>$item){
        $y=216+30*$j;
        $refs=$item['obd'].($item['ref2']!==''?' / '.$item['ref2']:'');
        $html.=$t('Ref. No.: '.$refs,103,$y,$j<2?224:134,9);
        $desc=$item['product'].($item['litres']!==''?' · '.$item['litres'].' L':'');
        if(count($items)===1)$html.=$t('Product',103,242,134,8,'bold muted').$t(lrWrap($desc,29),103,255,134,11,'bold');
        else $html.=$t($desc,103,$y+12,$j<2?224:134,8.5,'bold');
        $html.=$t($item['qty'],30,count($items)===1?234:$y+4,60,count($items)===1?20:13,'bold center');
    }
    if(!in_array('',array_column($items,'weight'),true))$html.=$t(count($items)===1?$items[0]['weight']:(string)array_sum(array_column($items,'weight')),335,230,57,10,'center');
    $html.=$t('Value Rs.',343,263,49,8.5);
    foreach(array_combine(array_keys(CHARGES),[246,260,274,288,302]) as $key=>$y){
        $html.=$t(CHARGES[$key],394,$y,83,9,'right');
        if($index===$count-1)$html.=$t(money($c['charges'][$key]),488,$y,69,10,'right');
    }
    $html.=$t('Total',394,338,83,10,'right bold');
    if($index===$count-1){
        $html.=$t(money($base),488,222,69,10,'right').$t(money($total),488,337,69,11,'right bold');
        if($c['value']!=='')$html.=$t(money(paise($c['value'])),336,279,55,8,'center');
    }
    if($qr['png']){
        $label=$qr['mode']==='link'?'Scan for LR details online':'Scan for LR details';
        $html.='<img class="lr-qr" alt="'.e($label).'" src="data:image/png;base64,'.$qr['png'].'">'.$t($label,241,349,86,6,'center');
    }else $html.=$t("Full LR exceeds print QR capacity.\nView details in the LR register.",241,307,86,7,'center');
    return $html.'</div>';
}
