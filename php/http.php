<?php
declare(strict_types=1);
// Minimal HTTP adapters; all sensitive operations remain under session auth.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
function respond(array $data,int $code=200):never {
  http_response_code($code);header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
function requiredArray(mixed $x):array {
  if(!is_array($x))throw new InvalidArgumentException('Expected an array or object.');return $x;
}
function payload():array {
  $s=file_get_contents('php://input',false,null,0,65537);
  if(strlen($s)>65536)throw new InvalidArgumentException('Request too large.');
  return requiredArray(json_decode($s,true,32,JSON_THROW_ON_ERROR));
}
