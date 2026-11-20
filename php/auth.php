<?php
declare(strict_types=1);

// Authentication boundary. The receipt/import domain never sees passwords or credentials.
function lrCredentials(): ?array {
    $path = dbRoot().'/auth.json';
    if (!is_readable($path)) return null;
    try { $value = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { error_log('FreightSlip: invalid private auth configuration'); return null; }
    return is_array($value) ? $value : null;
}
function lrCredentialsValid(mixed $auth): bool {
    // Existing usernames containing dots, underscores or hyphens remain valid for migration.
    // The new setup wizard accepts alphanumeric usernames only.
    if (!is_array($auth) || !is_string($auth['username']??null)
        || !preg_match('/^[A-Za-z0-9._-]{3,64}$/D', $auth['username'])) return false;
    if (is_string($auth['password_hash']??null)) {
        return password_get_info($auth['password_hash'])['algo'] !== null;
    }
    $kdf=$auth['pbkdf2_sha256']??null;
    if (!is_array($kdf) || ($kdf['iterations']??null)!==600000
        || !is_string($kdf['salt']??null) || !is_string($kdf['hash']??null)) return false;
    $salt=base64_decode($kdf['salt'],true);$hash=base64_decode($kdf['hash'],true);
    return $salt!==false && strlen($salt)===16 && $hash!==false && strlen($hash)===32;
}
function lrVerifyPassword(array $auth,string $password): bool {
    if (isset($auth['password_hash'])) return password_verify($password,$auth['password_hash']);
    $kdf=$auth['pbkdf2_sha256'];
    return hash_equals(base64_decode($kdf['hash'],true),
        hash_pbkdf2('sha256',$password,base64_decode($kdf['salt'],true),600000,32,true));
}
function lrCookiePath(): string {
    // Handles /freightslip/api/index.php on Cloudways and /index.php in local tests.
    $script=$_SERVER['SCRIPT_NAME']??'/index.php';
    if (str_ends_with($script,'/api/index.php')) {
        $base=substr($script,0,-strlen('/api/index.php'));
        return $base===''?'/':$base.'/';
    }
    return '/';
}
function lrCookieSecure(): bool {
    $host=strtolower(explode(':',(string)($_SERVER['HTTP_HOST']??'localhost'))[0]);
    return (!in_array($host,['localhost','127.0.0.1','::1'],true))
        || getenv('FREIGHTSLIP_SECURE_COOKIES')==='1'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')
        || (int)($_SERVER['SERVER_PORT']??0)===443;
}
function lrSession(): void {
    if (session_status()===PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.use_trans_sid','0');
    session_name('FREIGHTSLIP_SESSION');
    session_set_cookie_params(['lifetime'=>0,'path'=>lrCookiePath(),
        'secure'=>lrCookieSecure(),'httponly'=>true,'samesite'=>'Lax']);
    if (!session_start()) throw new RuntimeException('Unable to start FreightSlip session.');
}
function lrAuthFingerprint(array $auth): string {
    return hash('sha256',json_encode($auth,JSON_THROW_ON_ERROR));
}
function lrSignedIn(array $auth): bool {
    $now=time();
    if (!isset($_SESSION['lr_auth']) || !is_array($_SESSION['lr_auth'])) return false;
    $session=$_SESSION['lr_auth'];
    if (($session['user']??null)!==$auth['username']
        || !hash_equals((string)($session['fingerprint']??''),lrAuthFingerprint($auth))
        || $now-(int)($session['last']??0)>8*3600
        || $now-(int)($session['since']??0)>24*3600) {
        unset($_SESSION['lr_auth'],$_SESSION['lr_csrf']);return false;
    }
    $_SESSION['lr_auth']['last']=$now;
    return true;
}
function lrCsrf(): string {
    if (empty($_SESSION['lr_csrf'])) $_SESSION['lr_csrf']=bin2hex(random_bytes(32));
    return $_SESSION['lr_csrf'];
}
function lrCheckCsrf(): void {
    $token=$_SERVER['HTTP_X_LR_CSRF']??'';
    if (!is_string($token)||$token===''||!hash_equals(lrCsrf(),$token))
        respond(['error'=>'Security token expired. Reload the page and try again.'],403);
}
function lrSafeOrigin(): void {
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    if ($origin==='') return;
    $host=$_SERVER['HTTP_HOST']??'';
    $expected=parse_url($origin,PHP_URL_HOST);
    $port=parse_url($origin,PHP_URL_PORT);
    if (!is_string($expected)||strtolower($expected.($port?':'.$port:''))!==strtolower($host))
        respond(['error'=>'Cross-origin requests are not permitted.'],403);
    if (lrCookieSecure() && parse_url($origin,PHP_URL_SCHEME)!=='https')
        respond(['error'=>'A secure connection is required.'],403);
}
function lrLoginThrottle(string $username,bool $failed=false): bool {
    // Bound concurrent failed sign-ins without introducing a database or sending user details to logs.
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    $id=hash('sha256',$ip);
    $path=dbRoot().'/.login-'.$id;
    $fp=fopen($path,'c+');if (!$fp) throw new RuntimeException('Cannot access sign-in rate limiter.');
    try {
        if (!flock($fp,LOCK_EX)) throw new RuntimeException('Cannot lock sign-in limiter.');
        $raw=stream_get_contents($fp);$current=json_decode((string)$raw,true);
        if (!is_array($current) || (int)($current['at']??0)<time()-900) $current=['at'=>time(),'count'=>0];
        $limited=(int)$current['count']>=8;
        if ($failed && !$limited) $current['count']++;
        if (!$failed && !$limited) $current=['at'=>time(),'count'=>0];
        rewind($fp); ftruncate($fp,0);fwrite($fp,json_encode($current,JSON_THROW_ON_ERROR));fflush($fp);
        return $limited;
    } finally { flock($fp,LOCK_UN);fclose($fp); }
}
function lrClearSession(): void {
    $_SESSION=[];
    $cookie=session_get_cookie_params();
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>$cookie['path'],
            'secure'=>$cookie['secure'],'httponly'=>true,'samesite'=>'Lax']);
    }
    session_destroy();
}
function lrHealth(array $auth): never {
    if (!lrCredentialsValid($auth) || !is_writable(dbRoot()) || PHP_VERSION_ID<80200)
        respond(['error'=>'FreightSlip runtime not ready.'],503);
    foreach (['gd','zip','dom','mbstring','xml','xmlreader','xmlwriter','simplexml','ctype','iconv','fileinfo','filter','zlib'] as $ext) {
        if (!extension_loaded($ext)) respond(['error'=>'FreightSlip runtime not ready.'],503);
    }
    respond(['status'=>'ok']);
}
function lrAuthenticate(string $action,string $method): void {
    $auth=lrCredentials();
    if ($action==='health' && $method==='GET') lrHealth($auth??[]);
    if (!lrCredentialsValid($auth)) respond(['error'=>'Configure private storage/auth.json before using FreightSlip.'],503);
    lrSession();
    if ($action==='session' && $method==='GET') {
        if (!lrSignedIn($auth)) respond(['authenticated'=>false,'error'=>'Sign in to FreightSlip.'],401);
        respond(['authenticated'=>true,'username'=>$auth['username'],'csrf'=>lrCsrf()]);
    }
    if ($action==='login' && $method==='POST') {
        if (($_SERVER['HTTP_X_LR_STUDIO']??'')!=='1') respond(['error'=>'Use the FreightSlip sign-in screen.'],403);
        lrSafeOrigin();
        $data=payload();
        $user=$data['username']??null;$pass=$data['password']??null;
        if (!is_string($user) || !is_string($pass) || strlen($user)>64 || strlen($pass)>4096)
            respond(['error'=>'Invalid username or password.'],401);
        if (lrLoginThrottle($user)) respond(['error'=>'Too many attempts. Try again in 15 minutes.'],429);
        $correct=lrVerifyPassword($auth,$pass);
        if (!hash_equals($auth['username'],$user) || !$correct) {
            lrLoginThrottle($user,true);
            respond(['error'=>'Incorrect username or password.'],401);
        }
        session_regenerate_id(true);
        $_SESSION['lr_auth']=['user'=>$auth['username'],'fingerprint'=>lrAuthFingerprint($auth),
            'since'=>time(),'last'=>time()];
        unset($_SESSION['lr_csrf']);
        lrLoginThrottle($user,false);
        respond(['authenticated'=>true,'username'=>$auth['username'],'csrf'=>lrCsrf()]);
    }
    if (!lrSignedIn($auth)) respond(['authenticated'=>false,'error'=>'Your session has expired. Sign in again.'],401);
    if ($method==='POST') {
        if (($_SERVER['HTTP_X_LR_STUDIO']??'')!=='1') respond(['error'=>'Submit requests through FreightSlip.'],403);
        lrSafeOrigin();lrCheckCsrf();
    }
    if ($action==='logout' && $method==='POST') {
        lrClearSession();respond(['signedOut'=>true]);
    }
    // Release the session file lock during XLSX import, PDF rendering and AI suggestions.
    session_write_close();
}
