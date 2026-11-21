<?php
declare(strict_types=1);

// Versioned, private print-only settings. LR data, legal labels, QR payload and
// two-receipts-per-A4 positioning are deliberately not editable.
const LR_PRINT_DESIGN_DEFAULTS=[
  'default_layout'=>'classic',
  'default_qr'=>true,
  'classic'=>['accent'=>'#000000','header_pt'=>20,'font'=>'sans'],
  'clean'=>['accent'=>'#000000','header_pt'=>21,'font'=>'sans',
            'shade'=>'#f5f5f5','border'=>'standard'],
];
const LR_PRINT_ACCENTS=['#000000','#1e293b','#4338ca','#047857'];
const LR_PRINT_SHADES=['#f5f5f5','#ffffff','#f0f4ff','#ecfdf5','#fff7ed'];

function lrPrintDesignValidate(array $input):array {
  $defaults=LR_PRINT_DESIGN_DEFAULTS;
  if(array_keys($input)!==array_keys($defaults))
    throw new InvalidArgumentException('Print design fields do not match the supported options.');
  if(!in_array($input['default_layout'],['classic','clean'],true))
    throw new InvalidArgumentException('Select a valid default print layout.');
  if(!is_bool($input['default_qr']))
    throw new InvalidArgumentException('Default QR setting must be on or off.');
  $result=['default_layout'=>$input['default_layout'],'default_qr'=>$input['default_qr']];
  foreach(['classic','clean'] as $layout){
    $options=$input[$layout];
    if(!is_array($options)||array_keys($options)!==array_keys($defaults[$layout]))
      throw new InvalidArgumentException('Unsupported fields in '.$layout.' print design.');
    if(!is_string($options['accent'])||!in_array($options['accent'],LR_PRINT_ACCENTS,true))
      throw new InvalidArgumentException('Select a supported accent color.');
    $size=$options['header_pt'];
    if(!is_int($size)||$size<17||$size>23)
      throw new InvalidArgumentException('Heading size must be from 17 to 23 pt.');
    if(!in_array($options['font'],['sans','serif'],true))
      throw new InvalidArgumentException('Choose a supported heading font.');
    $clean=['accent'=>$options['accent'],'header_pt'=>$size,'font'=>$options['font']];
    if($layout==='clean'){
      if(!is_string($options['shade'])||!in_array($options['shade'],LR_PRINT_SHADES,true))
        throw new InvalidArgumentException('Select a supported Print Clean background.');
      if(!in_array($options['border'],['standard','strong'],true))
        throw new InvalidArgumentException('Select a supported Print Clean border weight.');
      $clean['shade']=$options['shade'];$clean['border']=$options['border'];
    }
    $result[$layout]=$clean;
  }
  return $result;
}
function lrPrintDesignRead():array {
  $path=dbRoot().'/print-design.json';
  if(!is_file($path))return ['version'=>0,'design'=>LR_PRINT_DESIGN_DEFAULTS];
  $record=readJson($path);
  if(!is_int($record['version']??null)||$record['version']<0||!is_array($record['design']??null))
    throw new RuntimeException('Private print design file is invalid.');
  return ['version'=>$record['version'],'design'=>lrPrintDesignValidate($record['design'])];
}
function lrPrintDesignSave(array $payload):array {
  if(!is_int($payload['version']??null)||($payload['version']??-1)<0||
     !is_array($payload['design']??null))
    throw new InvalidArgumentException('A version and print design are required.');
  $valid=lrPrintDesignValidate($payload['design']);
  $lock=fopen(dbRoot().'/print-design.lock','c');
  if(!$lock)throw new RuntimeException('Cannot open print-design lock.');
  try{
    if(!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock print design.');
    $previous=lrPrintDesignRead();
    if($previous['version']!==$payload['version'])
      throw new DomainException('Print design was changed by another admin. Reload and try again.');
    if($previous['design']===$valid)return $previous;
    $next=['version'=>$previous['version']+1,'design'=>$valid];
    writeJson(dbRoot().'/print-design.json',$next);
    return $next;
  }finally{flock($lock,LOCK_UN);fclose($lock);}
}
