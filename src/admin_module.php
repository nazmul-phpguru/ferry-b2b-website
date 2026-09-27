<?php
declare(strict_types=1);

function admin_module(): void {
    $key=(string)($_GET['key'] ?? '');
    if (!preg_match('/^[a-z0-9-]{2,64}$/',$key)) { http_response_code(404); exit('Module not found.'); }
    if($key==='updates') { admin_github_updates(); return; }
    $title=ucwords(str_replace('-',' ',$key));
    admin_layout($title,static function() use($title){
        ?><div class="heading"><div><div class="eyebrow">ADMIN MODULE</div><h1><?=h($title)?></h1></div></div><div class="panel"><p>This WordPress or plugin workflow is still being rebuilt for the custom PHP admin. The menu is present so its place in the old admin is clear, but this screen does not yet perform its original actions.</p><p><a class="secondary" href="<?=h(path('/admin'))?>">Back to dashboard</a></p></div><?php
    });
}
