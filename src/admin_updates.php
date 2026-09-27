<?php
declare(strict_types=1);

function github_update_file(): string {return dirname(__DIR__).'/storage/github-update-connection.json';}

function github_update_config(): array {
    $file=github_update_file();
    if(!is_file($file))return [];
    $data=json_decode((string)file_get_contents($file),true);
    return is_array($data)?$data:[];
}

function github_update_token(array $config): string {
    if(empty($config['token_cipher']) || empty($config['token_iv']) || empty($config['token_tag']))return '';
    $plain=openssl_decrypt(
        (string)base64_decode((string)$config['token_cipher'],true),
        'aes-256-gcm',
        hash('sha256',(string)cfg('app_key'),true),
        OPENSSL_RAW_DATA,
        (string)base64_decode((string)$config['token_iv'],true),
        (string)base64_decode((string)$config['token_tag'],true)
    );
    return $plain===false?'':$plain;
}

function github_update_encrypt_token(string $token): array {
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($token,'aes-256-gcm',hash('sha256',(string)cfg('app_key'),true),OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false)throw new RuntimeException('Could not protect the GitHub token.');
    return ['token_cipher'=>base64_encode($cipher),'token_iv'=>base64_encode($iv),'token_tag'=>base64_encode($tag)];
}

function github_update_request(string $endpoint,string $token): array {
    if(!preg_match('#^/repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/commits/[A-Za-z0-9_.-]+)?$#',$endpoint))throw new InvalidArgumentException('Invalid GitHub endpoint.');
    $handle=curl_init('https://api.github.com'.$endpoint);
    curl_setopt_array($handle,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROXY=>'',
        CURLOPT_CONNECTTIMEOUT=>7,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>array_filter([
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: FerryTelecomAdmin/1.0',
            $token!==''?'Authorization: Bearer '.$token:null,
        ]),
    ]);
    $body=curl_exec($handle);
    $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
    $error=curl_error($handle);
    curl_close($handle);
    if($body===false)throw new RuntimeException('GitHub could not be reached: '.$error);
    $json=json_decode((string)$body,true);
    if($status<200 || $status>=300 || !is_array($json)) {
        $message=$status===404?'Repository or branch not found, or the token cannot access it.':($status===401?'GitHub rejected the token.':'GitHub returned HTTP '.$status.'.');
        if($status===403 && isset($json['message']) && is_string($json['message'])) {
            $detail=trim($json['message']);
            if($detail!=='' && strlen($detail)<200 && !str_contains($detail,$token))$message.=' '.$detail;
        }
        throw new RuntimeException($message);
    }
    return $json;
}

function github_update_verify(string $owner,string $repo,string $branch,string $token): array {
    try {$repository=github_update_request('/repos/'.$owner.'/'.$repo,$token);}
    catch(RuntimeException $error){throw new RuntimeException('Repository check: '.$error->getMessage(),0,$error);}
    $resolvedBranch=$branch!==''?$branch:(string)($repository['default_branch'] ?? '');
    if($resolvedBranch==='' || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/',$resolvedBranch))throw new RuntimeException('Choose a simple branch name to check.');
    try {$commit=github_update_request('/repos/'.$owner.'/'.$repo.'/commits/'.$resolvedBranch,$token);}
    catch(RuntimeException $error){
        if(str_contains($error->getMessage(),'HTTP 403') && str_contains($error->getMessage(),'Resource not accessible by personal access token')) {
            throw new RuntimeException('The token can see the repository, but cannot read commits. Grant this repository Contents: Read-only permission in GitHub, then reconnect.',0,$error);
        }
        throw new RuntimeException('Commit check: '.$error->getMessage(),0,$error);
    }
    $sha=(string)($commit['sha'] ?? '');
    if(!preg_match('/^[a-f0-9]{40}$/',$sha))throw new RuntimeException('GitHub did not return a valid commit.');
    return [
        'branch'=>$resolvedBranch,
        'visibility'=>!empty($repository['private'])?'Private':'Public',
        'latest_sha'=>$sha,
        'latest_message'=>(string)($commit['commit']['message'] ?? ''),
        'latest_date'=>(string)($commit['commit']['committer']['date'] ?? ''),
        'checked_at'=>gmdate('c'),
    ];
}

function github_update_write(array $config): void {
    $file=github_update_file();
    $temp=$file.'.'.bin2hex(random_bytes(5)).'.tmp';
    $json=json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(file_put_contents($temp,$json,LOCK_EX)===false)throw new RuntimeException('Could not save the GitHub connection.');
    if(!rename($temp,$file)){@unlink($temp);throw new RuntimeException('Could not finish saving the GitHub connection.');}
}

function github_update_redirect_error(Throwable $error): never {
    error_log('GitHub update connection: '.$error->getMessage());
    notice($error->getMessage());
    redirect('/admin/module?key=updates');
}

function save_github_update_connection(): never {
    $url=trim((string)($_POST['repository_url'] ?? ''));
    $branch=trim((string)($_POST['branch'] ?? ''));
    $token=trim((string)($_POST['token'] ?? ''));
    if(!preg_match('#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#',$url,$match) || strlen($url)>255 || strlen($token)>500 || ($branch!=='' && !preg_match('/^[A-Za-z0-9_.-]{1,100}$/',$branch))) {
        notice('Enter a GitHub repository URL and a valid branch.');redirect('/admin/module?key=updates');
    }
    $owner=$match[1];$repo=$match[2];
    $old=github_update_config();
    if($token==='' && ($old['owner'] ?? '')===$owner && ($old['repo'] ?? '')===$repo)$token=github_update_token($old);
    try {
        $verified=github_update_verify($owner,$repo,$branch,$token);
        $config=['repository_url'=>'https://github.com/'.$owner.'/'.$repo,'owner'=>$owner,'repo'=>$repo]+$verified+($token!==''?github_update_encrypt_token($token):[]);
        github_update_write($config);
        audit_change('github_updates',0,'connect',[],['repository'=>$config['repository_url'],'branch'=>$config['branch'],'latest_sha'=>$config['latest_sha']]);
        notice('GitHub repository connected and verified.');
    }catch(Throwable $error){github_update_redirect_error($error);}
    redirect('/admin/module?key=updates');
}

function check_github_update_connection(): never {
    $config=github_update_config();
    if(!$config){notice('Connect a repository first.');redirect('/admin/module?key=updates');}
    try {
        $verified=github_update_verify((string)$config['owner'],(string)$config['repo'],(string)$config['branch'],github_update_token($config));
        github_update_write(array_merge($config,$verified));
        notice('GitHub connection verified. Latest commit: '.substr($verified['latest_sha'],0,10).'.');
    }catch(Throwable $error){github_update_redirect_error($error);}
    redirect('/admin/module?key=updates');
}

function disconnect_github_update_connection(): never {
    $config=github_update_config();
    if($config && is_file(github_update_file()))unlink(github_update_file());
    audit_change('github_updates',0,'disconnect',['repository'=>$config['repository_url'] ?? ''],[]);
    notice('GitHub repository disconnected.');redirect('/admin/module?key=updates');
}

function admin_github_updates(): void {
    $config=github_update_config();
    $suggestedUrl=(string)cfg('github_update_repository_url','https://github.com/ferrytelecom/ferry-b2b');
    admin_layout('Updates',static function() use($config,$suggestedUrl) { ?>
      <div class="github-updates"><div class="github-updates-heading"><p>Dashboard / Updates</p><h1>GitHub updates</h1><span>Connect this PHP app to its source repository. Automatic updates will be configured later.</span></div>
        <?php if($config): ?><section class="github-updates-card"><div class="github-updates-card-head"><h2>Repository connection</h2><span class="github-connected">Connected</span></div><dl><dt>Repository</dt><dd><a href="<?=h($config['repository_url'])?>" target="_blank" rel="noopener noreferrer"><?=h($config['repository_url'])?></a></dd><dt>Branch</dt><dd><?=h($config['branch'])?></dd><dt>Visibility</dt><dd><?=h($config['visibility'] ?? '—')?></dd><dt>Latest commit</dt><dd><code><?=h(substr((string)($config['latest_sha'] ?? ''),0,12))?></code></dd><dt>Last checked</dt><dd><?=h($config['checked_at'] ?? '—')?></dd><dt>Token</dt><dd><?=empty($config['token_cipher'])?'No token saved':'Saved securely; never shown here'?></dd></dl><div class="github-updates-actions"><form method="post" action="<?=h(path('/admin/updates/check'))?>"><?=csrf_field()?><button class="secondary">Check connection</button></form><form method="post" action="<?=h(path('/admin/updates/disconnect'))?>" onsubmit="return confirm('Disconnect this GitHub repository?')"><?=csrf_field()?><button class="github-disconnect">Disconnect</button></form></div></section><?php endif; ?>
        <section class="github-updates-card"><h2><?=$config?'Change repository':'Connect repository'?></h2><p>Use the repository URL and its branch. For a private repository, enter a fine-grained token with read access to repository contents.</p><form method="post" action="<?=h(path('/admin/updates/connect'))?>" autocomplete="off"><?=csrf_field()?><label>GitHub repository URL<input name="repository_url" type="url" required placeholder="https://github.com/owner/repository" value="<?=h($config['repository_url'] ?? $suggestedUrl)?>"></label><label>Branch <small>Leave blank to use the repository default.</small><input name="branch" placeholder="main" value="<?=h($config['branch'] ?? '')?>"></label><label>Read-only token <small><?=$config?'Leave blank to keep the saved token for this repository.':'Required for private repositories.'?></small><input name="token" type="password" autocomplete="new-password" spellcheck="false" placeholder="<?=$config?'Saved token will be kept':'github_pat_…'?>"></label><button class="primary">Connect and verify</button></form></section>
      </div><link rel="stylesheet" href="<?=h(path('/assets/github-updates.css'))?>">
    <?php });
}
