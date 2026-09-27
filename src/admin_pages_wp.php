<?php
declare(strict_types=1);

function admin_wp_pages(): void {
    $status=(string)($_GET['status'] ?? ''); if(!in_array($status,['','publish','draft','pending','private','future','trash'],true)) $status='';
    $q=trim((string)($_GET['q'] ?? '')); $page=max(1,(int)($_GET['page'] ?? 1));
    $limit=(int)($_GET['per_page'] ?? 20); if(!in_array($limit,[10,20,50,100],true)) $limit=20; $offset=($page-1)*$limit;
    $states=rows("SELECT post_status,COUNT(*) n FROM wp_posts WHERE post_type='page' AND post_status<>'auto-draft' GROUP BY post_status");
    $totals=[]; foreach($states as $state) $totals[$state['post_status']]=(int)$state['n'];
    $where="p.post_type='page' AND p.post_status<>'auto-draft'"; $params=[];
    if($status!=='') { $where.=' AND p.post_status=?'; $params[]=$status; } else $where.=" AND p.post_status<>'trash'";
    if($q!=='') { $where.=' AND (p.post_title LIKE ? OR p.post_content LIKE ?)'; array_push($params,'%'.$q.'%','%'.$q.'%'); }
    $count=(int)(row("SELECT COUNT(*) n FROM wp_posts p WHERE $where",$params)['n'] ?? 0);
    $items=rows("SELECT p.ID,p.post_title,p.post_name,p.post_status,p.post_date,p.post_modified,p.post_parent,p.menu_order,p.comment_count,u.display_name author FROM wp_posts p LEFT JOIN wp_users u ON u.ID=p.post_author WHERE $where ORDER BY p.menu_order,p.post_title LIMIT $limit OFFSET $offset",$params);
    admin_layout('Pages',static function() use($status,$q,$page,$limit,$totals,$count,$items){
      $all=array_sum(array_filter($totals,static fn($key)=>$key!=='trash',ARRAY_FILTER_USE_KEY)); ?>
      <div class="wp-posts-list" data-list-kind="pages"><div class="wp-list-top"><div class="wp-list-title"><h1>Pages</h1><a class="secondary" href="<?=h(path('/admin/page/new'))?>">Add New Page</a></div><details class="wp-list-screen"><summary aria-label="Screen Options" title="Screen Options"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2" fill="white"/><circle cx="15" cy="12" r="2" fill="white"/><circle cx="10" cy="17" r="2" fill="white"/></svg></summary><div class="wp-list-screen-panel"><strong>Columns</strong><?php foreach(['author'=>'Author','comments'=>'Comments','date'=>'Date'] as $key=>$label): ?><label><input type="checkbox" data-post-column="<?=h($key)?>" checked> <?=h($label)?></label><?php endforeach; ?><strong>Pagination</strong><form action="<?=h(path('/admin/pages'))?>"><input type="hidden" name="status" value="<?=h($status)?>"><input type="hidden" name="q" value="<?=h($q)?>"><label>Pages per page <select name="per_page" onchange="this.form.submit()"><?php foreach([10,20,50,100] as $number): ?><option value="<?=$number?>" <?=$limit===$number?'selected':''?>><?=$number?></option><?php endforeach; ?></select></label></form></div></details></div>
      <nav class="wp-status-links" aria-label="Page statuses"><a class="<?=$status===''?'active':''?>" href="<?=h(path('/admin/pages'))?>">All <span>(<?=$all?>)</span></a><?php foreach(['publish'=>'Published','draft'=>'Drafts','pending'=>'Pending','future'=>'Scheduled','private'=>'Private','trash'=>'Trash'] as $key=>$label): if(!($totals[$key] ?? 0) && $status!==$key) continue; ?><a class="<?=$status===$key?'active':''?>" href="<?=h(path('/admin/pages?status='.$key))?>"><?=h($label)?> <span>(<?=h($totals[$key] ?? 0)?>)</span></a><?php endforeach; ?></nav>
      <form class="wp-list-filter" action="<?=h(path('/admin/pages'))?>"><input type="hidden" name="status" value="<?=h($status)?>"><input type="hidden" name="per_page" value="<?=$limit?>"><div class="wp-list-search"><label class="sr-only" for="page-search">Search pages</label><input id="page-search" name="q" value="<?=h($q)?>"><button class="secondary">Search Pages</button></div></form>
      <form method="post" action="<?=h(path('/admin/pages/bulk'))?>" onsubmit="return this.querySelector('select[name=action]').value!=='delete'||confirm('Permanently delete selected pages?')"><?=csrf_field()?><div class="wp-list-actions"><select name="action" aria-label="Bulk actions"><option value="">Bulk actions</option><option value="publish">Publish</option><option value="draft">Move to Draft</option><option value="trash">Move to Trash</option><?php if($status==='trash'): ?><option value="restore">Restore to Draft</option><option value="delete">Delete Permanently</option><?php endif; ?></select><button class="secondary">Apply</button><span><?=number_format($count)?> <?=$count===1?'item':'items'?></span></div><div class="wp-list-table-wrap"><table class="wp-list-table"><thead><tr><th><input type="checkbox" aria-label="Select all pages" onclick="document.querySelectorAll('.page-select').forEach(box=>box.checked=this.checked)"></th><th>Title</th><th data-post-col="author">Author</th><th data-post-col="comments">Comments</th><th data-post-col="date">Date</th></tr></thead><tbody>
      <?php foreach($items as $item): ?><tr><td><input class="page-select" type="checkbox" name="ids[]" value="<?=h($item['ID'])?>" aria-label="Select <?=h($item['post_title'])?>"></td><td><a href="<?=h(path('/admin/page?id='.$item['ID']))?>"><strong><?=h($item['post_title'] ?: '(no title)')?></strong></a><?php if($item['post_status']!=='publish'): ?> <em class="wp-page-status">— <?=h(ucfirst($item['post_status']))?></em><?php endif; ?><div class="row-actions"><a href="<?=h(path('/admin/page?id='.$item['ID']))?>">Edit</a><?php if($item['post_status']==='trash'): ?><button name="row_action" value="restore:<?=h($item['ID'])?>">Restore</button><button name="row_action" value="delete:<?=h($item['ID'])?>" onclick="return confirm('Permanently delete this page?')">Delete Permanently</button><?php else: ?><button type="button" onclick="document.getElementById('page-quick-<?=h($item['ID'])?>').hidden=false">Quick Edit</button><button name="row_action" value="trash:<?=h($item['ID'])?>">Trash</button><?php endif; ?><a href="<?=h(path('/admin/page/preview?id='.$item['ID']))?>">View</a></div></td><td data-post-col="author"><?=h($item['author'] ?: '—')?></td><td data-post-col="comments"><?=h($item['comment_count'])?></td><td data-post-col="date"><?=h($item['post_modified'])?></td></tr><tr class="quick-edit-row" id="page-quick-<?=h($item['ID'])?>" hidden><td colspan="5"><div class="quick-grid"><label>Title<input name="quick[<?=h($item['ID'])?>][title]" value="<?=h($item['post_title'])?>"></label><label>Slug<input name="quick[<?=h($item['ID'])?>][slug]" value="<?=h($item['post_name'])?>"></label><label>Status<select name="quick[<?=h($item['ID'])?>][status]"><?php foreach(['publish'=>'Published','draft'=>'Draft','pending'=>'Pending','private'=>'Private'] as $value=>$label): ?><option value="<?=$value?>" <?=$item['post_status']===$value?'selected':''?>><?=$label?></option><?php endforeach; ?></select></label><button class="primary" name="row_action" value="quick:<?=h($item['ID'])?>">Update</button><button class="secondary" type="button" onclick="this.closest('tr').hidden=true">Cancel</button></div></td></tr><?php endforeach; if(!$items): ?><tr><td colspan="5">No pages found. <a href="<?=h(path('/admin/page/new'))?>">Add a page</a>.</td></tr><?php endif; ?></tbody></table></div></form><?=admin_pages_nav('/admin/pages',$page,$count,$limit,['q'=>$q,'status'=>$status,'per_page'=>$limit])?></div><link rel="stylesheet" href="<?=h(path('/assets/posts-list.css'))?>"><script src="<?=h(path('/assets/posts-list.js'))?>" defer></script><?php
    });
}

function admin_wp_page(int $id=0): void {
    $p=$id?row("SELECT ID,post_title,post_name,post_content,post_excerpt,post_status,post_date,post_parent,menu_order,comment_status FROM wp_posts WHERE ID=? AND post_type='page'",[$id]):null;
    if($id && !$p) { http_response_code(404); exit('Page not found.'); }
    $parents=rows("SELECT ID,post_title FROM wp_posts WHERE post_type='page' AND post_status<>'trash' AND ID<>? ORDER BY post_title",[$id]);
    $template=$id?(string)(row("SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key='_wp_page_template' ORDER BY meta_id DESC LIMIT 1",[$id])['meta_value'] ?? 'default'):'default';
    $templates=rows("SELECT DISTINCT meta_value FROM wp_postmeta WHERE meta_key='_wp_page_template' AND meta_value<>'' AND meta_value<>'default' ORDER BY meta_value LIMIT 30");
    $thumbnailId=$id?(int)(row("SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key='_thumbnail_id' ORDER BY meta_id DESC LIMIT 1",[$id])['meta_value'] ?? 0):0;
    admin_layout($p?'Edit page':'Add page',static function() use($p,$parents,$template,$templates,$thumbnailId){ ?>
      <div class="wp-post-heading"><h1><?=$p?'Edit Page':'Add New Page'?></h1><div class="post-screen-options"><details id="post-screen-options"><summary aria-label="Screen Options" title="Screen Options"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2" fill="white"/><circle cx="15" cy="12" r="2" fill="white"/><circle cx="10" cy="17" r="2" fill="white"/></svg></summary><div class="screen-options-content"><strong>Show on screen</strong><?php foreach(['publish'=>'Publish','attributes'=>'Page Attributes','featured'=>'Featured image','excerpt'=>'Excerpt','discussion'=>'Discussion'] as $key=>$label): ?><label><input type="checkbox" data-toggle-panel="<?=h($key)?>" checked> <?=h($label)?></label><?php endforeach; ?></div></details></div></div>
      <form id="post-form" data-editor-kind="page" method="post" action="<?=h(path('/admin/page'))?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($p['ID'] ?? 0)?>"><input type="hidden" name="featured_image_id" id="featured-image-id" value="<?=$thumbnailId?>"><label class="post-title-label"><span class="sr-only">Page title</span><input name="title" maxlength="300" value="<?=h($p['post_title'] ?? '')?>" placeholder="Add title" required></label><?php if($p): ?><div class="post-permalink">Permalink: <span><?=h($p['post_name'])?></span></div><?php endif; ?>
      <div class="wp-post-grid"><div class="wp-post-main"><div class="post-media-row"><button type="button" class="secondary" data-open-media="insert">▣ Add Media</button><span>Visual editor</span></div><textarea id="post-content" name="content" aria-label="Page content"><?=h($p['post_content'] ?? '')?></textarea><section class="wp-metabox" data-screen-panel="excerpt"><h2>Excerpt</h2><div class="wp-metabox-body"><textarea name="excerpt" rows="4"><?=h($p['post_excerpt'] ?? '')?></textarea></div></section></div>
      <aside class="wp-post-sidebar"><section class="wp-metabox" data-screen-panel="publish"><h2>Publish</h2><div class="wp-metabox-body"><div class="publish-actions"><button class="secondary" name="save_as" value="draft" type="submit">Save Draft</button><?php if($p): ?><a class="secondary" target="_blank" href="<?=h(path('/admin/page/preview?id='.$p['ID']))?>">Preview</a><?php else: ?><button class="secondary" type="button" id="preview-unsaved">Preview</button><?php endif; ?></div><label>Status<select name="status" id="post-status"><?php foreach(['draft'=>'Draft','publish'=>'Published','pending'=>'Pending review','private'=>'Private'] as $value=>$label): ?><option value="<?=h($value)?>" <?=($p['post_status'] ?? 'draft')===$value?'selected':''?>><?=h($label)?></option><?php endforeach; ?></select></label><p>Visibility: <strong><?=($p['post_status'] ?? '')==='private'?'Private':'Public'?></strong></p><p>Publish: <?=h($p['post_date'] ?? 'Immediately')?></p><label>Slug<input name="slug" maxlength="200" value="<?=h($p['post_name'] ?? '')?>" placeholder="Generated from title"></label></div><div class="publish-footer"><?php if($p): ?><a href="<?=h(path('/admin/pages'))?>">All Pages</a><?php endif; ?><button class="primary" type="submit"><?=$p?'Update':'Publish'?></button></div></section>
      <section class="wp-metabox" data-screen-panel="attributes"><h2>Page Attributes</h2><div class="wp-metabox-body"><label>Parent<select name="parent"><option value="0">(no parent)</option><?php foreach($parents as $parent): ?><option value="<?=h($parent['ID'])?>" <?=($p['post_parent'] ?? 0)==$parent['ID']?'selected':''?>><?=h($parent['post_title'] ?: '(no title)')?></option><?php endforeach; ?></select></label><label>Template<select name="template"><option value="default" <?=$template==='default'?'selected':''?>>Default template</option><?php foreach($templates as $item): ?><option value="<?=h($item['meta_value'])?>" <?=$template===$item['meta_value']?'selected':''?>><?=h($item['meta_value'])?></option><?php endforeach; ?></select></label><label>Order<input name="menu_order" type="number" value="<?=h($p['menu_order'] ?? 0)?>"></label></div></section>
      <section class="wp-metabox" data-screen-panel="featured"><h2>Featured image</h2><div class="wp-metabox-body"><img id="featured-image-preview" alt="Featured image" src="<?=$thumbnailId?h(path('/media/attachment?id='.$thumbnailId)):''?>" <?=$thumbnailId?'':'hidden'?>><button class="link-button" type="button" data-open-media="featured"><?=$thumbnailId?'Replace featured image':'Set featured image'?></button><button class="link-button" type="button" id="remove-featured" <?=$thumbnailId?'':'hidden'?>>Remove featured image</button></div></section><section class="wp-metabox" data-screen-panel="discussion"><h2>Discussion</h2><div class="wp-metabox-body"><label><input type="checkbox" name="comments" value="open" <?=($p['comment_status'] ?? 'closed')==='open'?'checked':''?>> Allow comments</label></div></section></aside></div></form>
      <dialog id="post-media-dialog" class="post-media-dialog"><div class="media-dialog-head"><h2>Media Library</h2><button type="button" id="close-media" aria-label="Close">×</button></div><div class="media-dialog-body"><div class="media-library-actions"><label>Search images<input id="media-search" type="search" placeholder="Search media"></label><label class="media-upload-label">Upload new image<input id="media-upload-input" type="file" accept="image/*"></label></div><div id="media-upload-status" role="status"></div><div class="media-library-layout"><div id="media-results" class="media-results"></div><div id="media-details" class="media-details" hidden><img id="media-detail-preview" alt=""><label>Title<input id="media-title" maxlength="200"></label><label>Alternative text<input id="media-alt" maxlength="500" placeholder="Describe the image"></label><div id="media-dimensions"></div><label>Display width (px)<input id="media-width" type="number" min="1" max="8000"></label><label>Display height (px)<input id="media-height" type="number" min="1" max="8000"></label><button type="button" class="secondary" id="save-media-details">Save image details</button></div></div></div><div class="media-dialog-foot"><span id="media-selection"></span><button class="primary" type="button" id="use-media" disabled>Use image</button></div></dialog><link rel="stylesheet" href="<?=h(path('/assets/post-editor.css'))?>"><script src="<?=h(path('/assets/vendor/tinymce/tinymce.min.js'))?>"></script><script src="<?=h(path('/assets/media-upload.js'))?>"></script><script src="<?=h(path('/assets/post-editor.js'))?>" defer></script><?php
    });
}

function admin_wp_page_preview(int $id): void {
    $p=row("SELECT ID,post_title,post_content,post_excerpt,post_status,post_date FROM wp_posts WHERE ID=? AND post_type='page'",[$id]);
    if(!$p) { http_response_code(404); exit('Page not found.'); }
    admin_layout('Preview page',static function() use($p){ ?><div class="heading"><div><div class="eyebrow">PAGE PREVIEW · <?=h($p['post_status'])?></div><h1><?=h($p['post_title'] ?: '(no title)')?></h1><p><?=h($p['post_date'])?></p></div><a class="secondary" href="<?=h(path('/admin/page?id='.$p['ID']))?>">Edit page</a></div><article class="panel post-preview"><p><?=h($p['post_excerpt'])?></p><div><?=nl2br(h(strip_tags($p['post_content'])))?></div></article><?php });
}

function save_wp_page(): never {
    $id=max(0,(int)($_POST['id'] ?? 0)); $originalId=$id;
    $before=$id?row("SELECT ID,post_title,post_name,post_status,post_parent,menu_order,comment_status FROM wp_posts WHERE ID=? AND post_type='page'",[$id]):null;
    if($id && !$before) { http_response_code(404); exit('Page not found.'); }
    $title=trim((string)($_POST['title'] ?? '')); $pageSlug=slug((string)($_POST['slug'] ?? '')) ?: slug($title);
    $saveAs=(string)($_POST['save_as'] ?? ''); $status=$saveAs==='draft'?'draft':($saveAs==='publish'?'publish':(string)($_POST['status'] ?? 'draft'));
    $parent=max(0,(int)($_POST['parent'] ?? 0)); $order=(int)($_POST['menu_order'] ?? 0); $comments=isset($_POST['comments'])?'open':'closed';
    $template=(string)($_POST['template'] ?? 'default'); $thumbnailId=max(0,(int)($_POST['featured_image_id'] ?? 0));
    if($title==='' || $pageSlug==='' || !in_array($status,['draft','publish','pending','private'],true) || mb_strlen($template)>255) { notice('Check the page title and status.'); redirect($id?'/admin/page?id='.$id:'/admin/page/new'); }
    $content=(string)($_POST['content'] ?? ''); $excerpt=(string)($_POST['excerpt'] ?? '');
    db()->beginTransaction();
    try {
      if($parent) {
        $ancestor=$parent; $seen=[];
        while($ancestor) {
          if($ancestor===$id || isset($seen[$ancestor])) throw new InvalidArgumentException('A page cannot be its own parent.');
          $seen[$ancestor]=true;
          $record=row("SELECT post_parent FROM wp_posts WHERE ID=? AND post_type='page' AND post_status<>'trash'",[$ancestor]);
          if(!$record) throw new InvalidArgumentException('Choose a valid parent page.');
          $ancestor=(int)$record['post_parent'];
        }
      }
      $duplicate=row("SELECT ID FROM wp_posts WHERE post_type='page' AND post_name=? AND post_parent=? AND ID<>? LIMIT 1",[$pageSlug,$parent,$id]);
      if($duplicate) throw new InvalidArgumentException('A page with that slug already exists under this parent.');
      if($thumbnailId && !row("SELECT ID FROM wp_posts WHERE ID=? AND post_type='attachment' AND post_mime_type LIKE 'image/%'",[$thumbnailId])) throw new InvalidArgumentException('Choose a valid featured image.');
      if($id) exec_sql("UPDATE wp_posts SET post_title=?,post_name=?,post_content=?,post_excerpt=?,post_status=?,comment_status=?,post_parent=?,menu_order=?,post_modified=NOW(),post_modified_gmt=UTC_TIMESTAMP() WHERE ID=? AND post_type='page'",[$title,$pageSlug,$content,$excerpt,$status,$comments,$parent,$order,$id]);
      else { exec_sql("INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (?,NOW(),UTC_TIMESTAMP(),?,?,?,?,?,'closed',?,'','',NOW(),UTC_TIMESTAMP(),'',?,'',?,'page','',0)",[(int)(current_user()['id'] ?? 0),$content,$title,$excerpt,$status,$comments,$pageSlug,$parent,$order]); $id=(int)db()->lastInsertId(); }
      exec_sql("DELETE FROM wp_postmeta WHERE post_id=? AND meta_key IN ('_wp_page_template','_thumbnail_id')",[$id]);
      exec_sql("INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (?,'_wp_page_template',?)",[$id,$template]);
      if($thumbnailId) exec_sql("INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (?,'_thumbnail_id',?)",[$id,(string)$thumbnailId]);
      audit_change('page',$id,$before?'update':'create',$before ?? [],['title'=>$title,'slug'=>$pageSlug,'status'=>$status,'parent'=>$parent,'order'=>$order]);
      db()->commit(); notice('Page saved.'); redirect('/admin/page?id='.$id);
    } catch(Throwable $error) { db()->rollBack(); error_log((string)$error); notice($error instanceof InvalidArgumentException?$error->getMessage():'Could not save page.'); redirect($originalId?'/admin/page?id='.$originalId:'/admin/page/new'); }
}

function bulk_wp_pages(): never {
    $rowAction=(string)($_POST['row_action'] ?? ''); $action=(string)($_POST['action'] ?? ''); $ids=$_POST['ids'] ?? [];
    if($rowAction!=='' && preg_match('/^(trash|restore|delete|quick):(\d+)$/',$rowAction,$matches)) { $action=$matches[1]; $ids=[(int)$matches[2]]; }
    if(!is_array($ids) || !in_array($action,['publish','draft','trash','restore','delete','quick'],true)) { notice('Choose a page action.'); redirect('/admin/pages'); }
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));
    if(!$ids || count($ids)>100) { notice('Select up to 100 pages.'); redirect('/admin/pages'); }
    db()->beginTransaction();
    try {
      foreach($ids as $id) {
        $page=row("SELECT ID,post_title,post_name,post_status,post_parent FROM wp_posts WHERE ID=? AND post_type='page' FOR UPDATE",[$id]); if(!$page) continue;
        if($action==='delete') {
          if($page['post_status']!=='trash') continue;
          exec_sql("UPDATE wp_posts SET post_parent=0 WHERE post_parent=? AND post_type='page'",[$id]);
          exec_sql('DELETE FROM wp_term_relationships WHERE object_id=?',[$id]);
          exec_sql('DELETE FROM wp_postmeta WHERE post_id=?',[$id]);
          exec_sql('DELETE cm FROM wp_commentmeta cm JOIN wp_comments c ON c.comment_ID=cm.comment_id WHERE c.comment_post_ID=?',[$id]);
          exec_sql('DELETE FROM wp_comments WHERE comment_post_ID=?',[$id]);
          exec_sql("DELETE FROM wp_posts WHERE ID=? AND post_type='page'",[$id]);
          audit_change('page',$id,'delete',$page,[]);
        } elseif($action==='quick') {
          $input=$_POST['quick'][$id] ?? []; $title=trim((string)($input['title'] ?? '')); $pageSlug=slug((string)($input['slug'] ?? '')) ?: slug($title); $status=(string)($input['status'] ?? '');
          if($title==='' || $pageSlug==='' || !in_array($status,['publish','draft','pending','private'],true)) throw new InvalidArgumentException('Check Quick Edit fields.');
          exec_sql("UPDATE wp_posts SET post_title=?,post_name=?,post_status=?,post_modified=NOW(),post_modified_gmt=UTC_TIMESTAMP() WHERE ID=? AND post_type='page'",[$title,$pageSlug,$status,$id]);
          audit_change('page',$id,'update',$page,['title'=>$title,'slug'=>$pageSlug,'status'=>$status]);
        } else {
          if($action==='restore' && $page['post_status']!=='trash') continue;
          $status=$action==='restore'?'draft':$action;
          exec_sql("UPDATE wp_posts SET post_status=?,post_modified=NOW(),post_modified_gmt=UTC_TIMESTAMP() WHERE ID=? AND post_type='page'",[$status,$id]);
          audit_change('page',$id,'status',$page,['status'=>$status]);
        }
      }
      db()->commit(); notice('Page action completed.');
    } catch(Throwable $error) { db()->rollBack(); error_log((string)$error); notice($error instanceof InvalidArgumentException?$error->getMessage():'Could not update pages.'); }
    redirect('/admin/pages');
}
