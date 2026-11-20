<?php
declare(strict_types=1);
$release=dirname(__DIR__).'/release';
$manifest=[
  'schema'=>'DIGIOPS-RELEASE/1','name'=>'FreightSlip','version'=>'0.1.0',
  'builtAt'=>gmdate(DATE_ATOM),'sourceSha'=>getenv('GITHUB_SHA')?:'local',
  'branch'=>getenv('GITHUB_REF_NAME')?:'local','ciRunNumber'=>getenv('GITHUB_RUN_NUMBER')?:'',
  'ciRunId'=>getenv('GITHUB_RUN_ID')?:'','ciRunAttempt'=>getenv('GITHUB_RUN_ATTEMPT')?:'',
  'public'=>'public','private'=>'private','publicPath'=>'public_html/freightslip/',
  'privatePath'=>'private_html/freightslip/','persistentPaths'=>['storage/'],
];
$json=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
foreach (['RELEASE.json','private/build/release.json'] as $path) {
  if(file_put_contents($release.'/'.$path,$json)!==strlen($json))exit(1);
}
