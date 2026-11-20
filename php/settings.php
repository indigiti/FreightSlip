<?php
declare(strict_types=1);

// Operator-controlled values for NEW LR reviews; issued receipts are immutable snapshots.
const LR_DEFAULT_SETTINGS = [
  'consignor_address' => "C/O Shriram Enterprises\nUrulidevachi, Pune 412308, Maharashtra",
  'carrier' => 'SHRIRAM TRANSPORT',
  'address' => 'MILKAT 2345 , Pune Saswad Road , Uruli Devachi -412308 , Maharashtra',
];

function lrSettingsRead(): array {
  $path = dbRoot().'/settings.json';
  if (!is_file($path)) return ['version'=>0, 'settings'=>LR_DEFAULT_SETTINGS];
  $record = readJson($path);
  if (!is_int($record['version'] ?? null) || !is_array($record['settings'] ?? null))
    throw new RuntimeException('Private LR settings file is invalid.');
  return ['version'=>$record['version'], 'settings'=>lrSettingsValidate($record['settings'])];
}

function lrSettingsValidate(array $input): array {
  if (count($input)!==count(LR_DEFAULT_SETTINGS) || array_diff(array_keys($input), array_keys(LR_DEFAULT_SETTINGS)))
    throw new InvalidArgumentException('Provide only the three transport and consignor settings.');
  $output=[];
  foreach (LR_DEFAULT_SETTINGS as $name=>$default) {
    $value=$input[$name]??null;
    if (!is_string($value) || !preg_match('//u',$value))
      throw new InvalidArgumentException('Invalid '.str_replace('_',' ',$name).' setting.');
    $value=trim(str_replace(["\r\n","\r"],"\n",$value));
    if ($value==='' || strlen($value)>300 || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/u',$value))
      throw new InvalidArgumentException('Enter a valid '.str_replace('_',' ',$name).' (up to 300 characters).');
    if ($name==='carrier' && str_contains($value,"\n"))
      throw new InvalidArgumentException('Transport name must use one line.');
    if ($name!=='carrier' && substr_count($value,"\n")>1)
      throw new InvalidArgumentException('Address may contain up to two lines.');
    $output[$name]=$value;
  }
  return $output;
}

function lrSettingsSave(array $payload): array {
  $input=$payload['settings']??null;
  $version=$payload['version']??null;
  if (!is_array($input) || !is_int($version) || $version<0)
    throw new InvalidArgumentException('Settings or revision is missing.');
  $validated=lrSettingsValidate($input);
  $root=dbRoot();$lock=fopen($root.'/settings.lock','c');
  if (!$lock) throw new RuntimeException('Cannot open LR settings lock.');
  try {
    if (!flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock LR settings.');
    $current=lrSettingsRead();
    if ($current['version']!==$version)
      throw new DomainException('Settings were updated elsewhere. Reload Settings before saving.');
    if ($current['settings']===$validated) return $current;
    $new=['version'=>$version+1,'settings'=>$validated];
    writeJson($root.'/settings.json',$new);
    return $new;
  } finally { flock($lock,LOCK_UN);fclose($lock); }
}
