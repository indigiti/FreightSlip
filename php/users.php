<?php
declare(strict_types=1);

// Managed downloader accounts. The primary admin remains in private auth.json;
// managed accounts are never granted a route that can import, issue or delete an LR.
function lrUsersRead(): array {
    return readJson(dbRoot().'/users.json');
}
function lrManagedUser(string $username): ?array {
    $users=lrUsersRead();
    $user=$users[strtolower($username)]??null;
    return is_array($user)?$user:null;
}
function lrUserPublic(array $user): array {
    return [
        'username'=>$user['username'], 'role'=>'download',
        'enabled'=>($user['enabled']??false)===true,
        'workspaces'=>array_values($user['workspaces']??[]),
        'created_at'=>(string)($user['created_at']??''),
        'updated_at'=>(string)($user['updated_at']??''),
    ];
}
function lrUserList(): array {
    $users=[];
    foreach(lrUsersRead() as $user) if(is_array($user))$users[]=lrUserPublic($user);
    usort($users,static fn($a,$b)=>strcmp(strtolower($a['username']),strtolower($b['username'])));
    return ['admin'=>(string)(lrCredentials()['username']??''),'users'=>$users];
}
function lrUserName(mixed $name): string {
    if(!is_string($name)||!preg_match('/^[A-Za-z0-9]{3,64}$/D',$name))
        throw new InvalidArgumentException('Username must contain 3–64 letters or numbers.');
    $primary=lrCredentials()['username']??'';
    if(strcasecmp($primary,$name)===0)throw new DomainException('The primary administrator already uses that username.');
    return strtolower($name);
}
function lrUserPassword(mixed $password): string {
    if(!is_string($password)||strlen($password)<14||strlen($password)>256)
        throw new InvalidArgumentException('Choose a unique password between 14 and 256 bytes.');
    $hash=password_hash($password,PASSWORD_DEFAULT);
    if(!is_string($hash))throw new RuntimeException('Could not hash user password.');
    return $hash;
}
function lrUserWorkspaceIds(mixed $values): array {
    if(!is_array($values)||!array_is_list($values)||count($values)>500)
        throw new InvalidArgumentException('Select up to 500 workspaces.');
    $current=[];
    foreach(lrWorkspaceIndex(registryAll()) as $ws)$current[$ws['id']]=true;
    $out=[];
    foreach($values as $id){
        if(!lrWorkspaceIdValid($id)||!isset($current[$id]))
            throw new InvalidArgumentException('One or more workspaces are unavailable. Reload the workspace list.');
        $out[$id]=true;
    }
    return array_keys($out);
}
function lrUsersMutate(callable $fn): array {
    $file=dbRoot().'/users.lock';$fp=fopen($file,'c');
    if(!$fp)throw new RuntimeException('Cannot open user lock.');
    try {
        if(!flock($fp,LOCK_EX))throw new RuntimeException('Cannot lock managed users.');
        $users=lrUsersRead();$answer=$fn($users);
        writeJson(dbRoot().'/users.json',$users);
        return $answer;
    } finally {flock($fp,LOCK_UN);fclose($fp);}
}
function lrUserCreate(array $payload): array {
    $key=lrUserName($payload['username']??null);
    $password=lrUserPassword($payload['password']??null);
    $workspaces=lrUserWorkspaceIds($payload['workspaces']??null);
    $enabled=$payload['enabled']??true;
    if(!is_bool($enabled))throw new InvalidArgumentException('Invalid account status.');
    return lrUsersMutate(static function(array &$users) use($key,$password,$workspaces,$enabled):array {
        if(isset($users[$key]))throw new DomainException('This username is already registered.');
        if(count($users)>=1000)throw new DomainException('User limit reached.');
        $at=gmdate(DATE_ATOM);
        $users[$key]=['username'=>$key,'role'=>'download','enabled'=>$enabled,
            'password_hash'=>$password,'workspaces'=>$workspaces,'created_at'=>$at,'updated_at'=>$at];
        return lrUserPublic($users[$key]);
    });
}
function lrUserUpdate(array $payload): array {
    $key=lrUserName($payload['username']??null);
    $workspaces=lrUserWorkspaceIds($payload['workspaces']??null);
    $enabled=$payload['enabled']??null;
    if(!is_bool($enabled))throw new InvalidArgumentException('Invalid account status.');
    $password=$payload['password']??'';
    $hash=$password===''?null:lrUserPassword($password);
    return lrUsersMutate(static function(array &$users) use($key,$workspaces,$enabled,$hash):array {
        if(!isset($users[$key])||!is_array($users[$key]))throw new DomainException('User not found.');
        $users[$key]['workspaces']=$workspaces;
        $users[$key]['enabled']=$enabled;
        $users[$key]['updated_at']=gmdate(DATE_ATOM);
        if($hash!==null)$users[$key]['password_hash']=$hash;
        return lrUserPublic($users[$key]);
    });
}
function lrUserDelete(array $payload): array {
    $key=lrUserName($payload['username']??null);
    return lrUsersMutate(static function(array &$users)use($key):array {
        if(!isset($users[$key]))throw new DomainException('User not found.');
        unset($users[$key]);
        return ['username'=>$key,'deleted'=>true];
    });
}
