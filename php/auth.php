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
function lrSessionDirectory(): string {
    // Avoid Cloudways PHP-FPM's global session.save_path: it may be inaccessible
    // to this application. Keep sessions beside the persistent private JSON store.
    $directory=dbRoot().'/sessions';
    if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory))
        throw new RuntimeException('Cannot create private session storage.');
    if (!is_writable($directory)) throw new RuntimeException('Private session storage is not writable.');
    return $directory;
}
function lrSession(): void {
    if (session_status()===PHP_SESSION_ACTIVE) return;
    $directory=lrSessionDirectory();
    ini_set('session.save_handler','files');
    ini_set('session.save_path',$directory);
    ini_set('session.gc_maxlifetime','86400');
    if (ini_get('session.save_handler')!=='files' || ini_get('session.save_path')!==$directory)
        throw new RuntimeException('PHP session storage cannot be configured.');
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.use_trans_sid','0');
    session_name('FREIGHTSLIP_SESSION');
    session_set_cookie_params(['lifetime'=>0,'path'=>lrCookiePath(),
        'secure'=>lrCookieSecure(),'httponly'=>true,'samesite'=>'Lax']);
    if (!@session_start()) throw new RuntimeException('Unable to start FreightSlip session.');
}
function lrAuthFingerprint(array $auth): string {
    return hash('sha256',json_encode($auth,JSON_THROW_ON_ERROR));
}
function lrSignedIn(array $auth): bool {
    $now=time();
    $session=$_SESSION['lr_auth']??null;
    if(!is_array($session))return false;
    $last=(int)($session['last']??0);$since=(int)($session['since']??0);
    $valid=($last>0 && $since>0 && $now-$last<=8*3600 && $now-$since<=24*3600);
    if($valid && ($session['role']??'admin')==='admin'){
        // Preserve sessions issued by the original one-admin authentication system.
        $valid=($session['user']??null)===$auth['username']
            && hash_equals((string)($session['fingerprint']??''),lrAuthFingerprint($auth));
    } elseif($valid && ($session['role']??null)==='download'){
        $user=lrManagedUser((string)($session['user']??''));
        $hash=is_array($user)?($user['password_hash']??null):null;
        $valid=is_array($user) && ($user['enabled']??false)===true
            && ($user['role']??null)==='download' && is_string($hash)
            && hash_equals((string)($session['fingerprint']??''),hash('sha256',$hash));
    } else $valid=false;
    if(!$valid){unset($_SESSION['lr_auth'],$_SESSION['lr_csrf']);return false;}
    $_SESSION['lr_auth']['last']=$now;
    return true;
}
function lrCurrentRole(): string {
    return ($_SESSION['lr_auth']['role']??'admin')==='download'?'download':'admin';
}
function lrCurrentWorkspaces(): array {
    if(lrCurrentRole()==='admin')return [];
    $user=lrManagedUser((string)($_SESSION['lr_auth']['user']??''));
    if(!is_array($user)||($user['enabled']??false)!==true)return [];
    $ids=$user['workspaces']??[];
    return is_array($ids)?$ids:[];
}
function lrWorkspaceAllowed(string $workspaceId): bool {
    return lrCurrentRole()==='admin'||in_array($workspaceId,lrCurrentWorkspaces(),true);
}
function lrRequireAdmin(): void {
    if(lrCurrentRole()!=='admin')respond(['error'=>'Administrator access required.'],403);
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
    try { lrSessionDirectory(); }
    catch (Throwable $error) { error_log('FreightSlip: private session storage unavailable'); respond(['error'=>'FreightSlip session storage unavailable.'],503); }
    respond(['status'=>'ok']);
}
function lrAuthenticate(string $action,string $method): void {
    $auth=lrCredentials();
    if($action==='health' && $method==='GET')lrHealth($auth??[]);
    if(!lrCredentialsValid($auth))respond(['error'=>'Configure private storage/auth.json before using FreightSlip.'],503);
    lrSession();
    if($action==='session' && $method==='GET'){
        if(!lrSignedIn($auth))respond(['authenticated'=>false,'error'=>'Sign in to FreightSlip.'],401);
        respond(['authenticated'=>true,'username'=>$_SESSION['lr_auth']['user'],
            'role'=>lrCurrentRole(),'workspaces'=>lrCurrentWorkspaces(),
            'print_defaults'=>['layout'=>lrPrintDesignRead()['design']['default_layout'],
                              'qr'=>lrPrintDesignRead()['design']['default_qr']],
            'csrf'=>lrCsrf()]);
    }
    if($action==='login' && $method==='POST'){
        if(($_SERVER['HTTP_X_LR_STUDIO']??'')!=='1')respond(['error'=>'Use the FreightSlip sign-in screen.'],403);
        lrSafeOrigin();
        $data=payload();$name=$data['username']??null;$pass=$data['password']??null;
        if(!is_string($name)||!is_string($pass)||strlen($name)>64||strlen($pass)>4096)
            respond(['error'=>'Invalid username or password.'],401);
        if(lrLoginThrottle($name))respond(['error'=>'Too many attempts. Try again in 15 minutes.'],429);
        $isAdmin=hash_equals($auth['username'],$name);
        $account=$isAdmin?null:lrManagedUser($name);
        $valid=$isAdmin?lrVerifyPassword($auth,$pass):
            (is_array($account) && ($account['enabled']??false)===true
             && ($account['role']??null)==='download'
             && is_string($account['password_hash']??null)
             && password_verify($pass,$account['password_hash']));
        if(!$valid){
            lrLoginThrottle($name,true);
            respond(['error'=>'Incorrect username or password.'],401);
        }
        $role=$isAdmin?'admin':'download';
        $username=$isAdmin?$auth['username']:$account['username'];
        $fingerprint=$isAdmin?lrAuthFingerprint($auth):hash('sha256',$account['password_hash']);
        session_regenerate_id(true);
        $_SESSION['lr_auth']=['user'=>$username,'role'=>$role,'fingerprint'=>$fingerprint,
            'since'=>time(),'last'=>time()];
        unset($_SESSION['lr_csrf']);
        lrLoginThrottle($name,false);
        respond(['authenticated'=>true,'username'=>$username,'role'=>$role,
            'workspaces'=>$isAdmin?[]:$account['workspaces'],
            'print_defaults'=>['layout'=>lrPrintDesignRead()['design']['default_layout'],
                              'qr'=>lrPrintDesignRead()['design']['default_qr']],
            'csrf'=>lrCsrf()]);
    }
    if(!lrSignedIn($auth))
        respond(['authenticated'=>false,'error'=>'Your session has expired. Sign in again.'],401);
    if($method==='POST'){
        if(($_SERVER['HTTP_X_LR_STUDIO']??'')!=='1')
            respond(['error'=>'Submit requests through FreightSlip.'],403);
        lrSafeOrigin();lrCheckCsrf();
    }
    if($action==='logout' && $method==='POST'){
        lrClearSession();respond(['signedOut'=>true]);
    }
    // Fail closed. Hiding admin menu items in React is not an access check.
    if(lrCurrentRole()==='download'
       && !($method==='GET' && in_array($action,['register','preview','pdf','details'],true)))
        respond(['error'=>'This account can only view and download assigned LRs.'],403);
    // Let long PDF renders/imports proceed without holding the user session lock.
    session_write_close();
}
