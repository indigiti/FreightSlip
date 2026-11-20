<?php
declare(strict_types=1);
function vendor():void {
  $autoload=defined('FREIGHTSLIP_VENDOR_AUTOLOAD')?FREIGHTSLIP_VENDOR_AUTOLOAD:__DIR__.'/vendor/autoload.php';
  if(!is_file($autoload))throw new RuntimeException('Install PHP dependencies with composer install inside php/.');
  require_once $autoload;
}
function loadSheet(string $path,string $name,int $requested,string $requestedTab=""):array {
  if($requested<0 || $requested>100)throw new InvalidArgumentException('Header row must be between 1 and 100, or leave blank for auto-detection.');
  $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$matrix=[];$tab='CSV';$names=[];
  if($ext==='csv'){
    $f=fopen($path,'rb');if(!$f)throw new InvalidArgumentException('Could not read the CSV.');
    try{while(($cells=fgetcsv($f,0,',','"',''))!==false){if(count($matrix)>=5100 || count($cells)>80)throw new InvalidArgumentException('Limit: 5,100 source rows and 80 columns.');$matrix[]=array_map(fn($x)=>trim((string)$x),$cells);}}finally{fclose($f);}
  }elseif($ext==='xlsx'){
    vendor();$zip=new ZipArchive();if($zip->open($path)!==true)throw new InvalidArgumentException('The upload is not a valid XLSX file.');
    try{$total=0;if($zip->numFiles>300)throw new InvalidArgumentException('Workbook has too many archive entries.');for($i=0;$i<$zip->numFiles;$i++){$total+=$zip->statIndex($i)['size'];if($total>30000000)throw new InvalidArgumentException('Workbook expands beyond 30 MB.');}}finally{$zip->close();}
    $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();$tabs=$reader->listWorksheetInfo($path);$eligible=array_values(array_filter($tabs,fn($t)=>$t['totalRows']>=2 && $t['totalRows']<=5100 && $t['totalColumns']<=80));
    $names=array_column($eligible,'worksheetName');
    $index=$requestedTab===''?0:array_search($requestedTab,$names,true);
    $first=$index===false?null:($eligible[$index]??null);
    if(!$first || $first['totalRows']>5100 || $first['totalColumns']>80)throw new InvalidArgumentException('No eligible worksheet found or selected. Use a worksheet with 2–5,100 rows and up to 80 columns.');
    $tab=$first['worksheetName'];$reader->setLoadSheetsOnly($tab);$reader->setReadEmptyCells(false);$book=$reader->load($path);$ws=$book->getSheet(0);
    try{for($r=1;$r<=$first['totalRows'];$r++){$cells=[];for($i=1;$i<=$first['totalColumns'];$i++){
        $c=$ws->getCell([$i,$r]);$v=$c->getValue();
        if($c->getDataType()==='f'){$v=$c->getOldCalculatedValue();if($v===null || (is_string($v) && preg_match('/^#(?:REF!|VALUE!|N\/A|DIV\/0!|NUM!|NAME\?|NULL!)$/',$v)))throw new InvalidArgumentException("Row $r contains a formula with no usable cached value. Recalculate and save in Excel, or paste values.");}
        if(is_numeric($v) && !is_string($v)){
          $fmt=$c->getStyle()->getNumberFormat()->getFormatCode();
          if(\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTimeFormatCode($fmt))$v=\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d');
          elseif(preg_match('/^0{2,20}$/D',$fmt) && (float)$v===floor((float)$v))$v=str_pad((string)$v,strlen($fmt),'0',STR_PAD_LEFT);
          elseif(is_float($v))$v=rtrim(rtrim(sprintf('%.12F',$v),'0'),'.');
        }
        $cells[]=trim((string)$v);
      }$matrix[]=$cells;}}finally{$book->disconnectWorksheets();}
  }else throw new InvalidArgumentException('Choose an .xlsx or UTF-8 .csv file.');
  if(!$matrix)throw new InvalidArgumentException('The file has no rows.');
  foreach($matrix as $r=>&$row)foreach($row as &$cell){if(strlen($cell)>2048 || !preg_match('//u',$cell))throw new InvalidArgumentException('Oversized or non-UTF-8 cell at row '.($r+1).'.');$cell=preg_replace('/^\xEF\xBB\xBF/','',$cell);}unset($row,$cell);
  $h=$requested-1;if(!$requested){$best=0;$h=0;$firstNonEmpty=null;foreach(array_slice($matrix,0,30) as $i=>$row){if($firstNonEmpty===null && array_filter($row,fn($v)=>$v!==''))$firstNonEmpty=$i;$score=count(known($row));if($score>$best){$best=$score;$h=$i;}}if(!$best)$h=$firstNonEmpty??0;}
  if(!isset($matrix[$h]) || !array_filter($matrix[$h],fn($v)=>$v!==''))throw new InvalidArgumentException('Selected header row is empty or missing.');
  $rows=[];$width=count($matrix[$h]);foreach(array_slice($matrix,$h+1,null,true) as $i=>$cells){if(!array_filter($cells,fn($x)=>$x!==''))continue;$rows[]=['line'=>$i+1,'cells'=>$cells];$width=max($width,count($cells));}
  if(count($rows)>5000 || (!$rows && count($names)<2))throw new InvalidArgumentException('Expected 1–5,000 data rows below the header.');
  return ['headers'=>array_pad($matrix[$h],$width,''),'rows'=>$rows,'header_row'=>$h+1,'sheet'=>$tab,'sheets'=>$names,'file'=>$name];
}

function detectColumns(array $sheet):array {
  $headers=$sheet['headers'];$map=known($headers);$inferred=[];
  if(isset($map['carrier'])&&!isset($map['address'])&&!array_intersect(array_map('norm',$headers),array_slice(FIELDS['address'],1))){
    $col=$map['carrier']+1;
    if(($headers[$col]??null)==='' && !in_array($col,$map,true)){
      $seen=0;$safe=true;
      foreach($sheet['rows'] as $row){
        $value=$row['cells'][$col]??'';if($value==='')continue;
        if(!preg_match('/^MILKAT\s+2345\b.*PUNE\s+SASWAD\s+ROAD.*URULI\s+DEVACHI.*\b412308\b/iu',$value)||norm($row['cells'][$map['carrier']]??'')!=='SHRIRAM TRANSPORT'){$safe=false;break;}
        $seen++;
      }
      if($seen && $safe){$map['address']=$col;$inferred[]='address';}
    }
  }
  return [$map,$inferred];
}
