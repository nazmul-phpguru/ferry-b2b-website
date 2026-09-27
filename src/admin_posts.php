<?php
declare(strict_types=1);

function admin_posts(): void {
    $status=(string)($_GET['status'] ?? '');
    if (!in_array($status,['','publish','draft','pending','private','future','trash'],true)) $status='';
    $q=trim((string)($_GET['q'] ?? ''));
    $category=max(0,(int)($_GET['category'] ?? 0));
    $tag=max(0,(int)($_GET['tag'] ?? 0));
    $page=max(1,(int)($_GET['page'] ?? 1)); $limit=(int)($_GET['per_page'] ?? 20); if(!in_array($limit,[10,20,50,100],true)) $limit=20; $offset=($page-1)*$limit;
    $states=rows("SELECT post_status,COUNT(*) n FROM wp_posts WHERE post_type='post' AND post_status<>'auto-draft' GROUP BY post_status");
    $totals=[]; foreach($states as $state) $totals[$state['post_status']]=(int)$state['n'];
    $where="p.post_type='post' AND p.post_status<>'auto-draft'"; $params=[];
    if ($status!=='') { $where.=' AND p.post_status=?'; $params[]=$status; }
    else $where.=" AND p.post_status<>'trash'";
    if ($q!=='') { $where.=' AND (p.post_title LIKE ? OR p.post_content LIKE ?)'; $params[]='%'.$q.'%'; $params[]='%'.$q.'%'; }
    if ($category) { $where.=" AND EXISTS (SELECT 1 FROM wp_term_relationships r JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=p.ID AND tt.taxonomy='category' AND tt.term_id=?)"; $params[]=$category; }
    if ($tag) { $where.=" AND EXISTS (SELECT 1 FROM wp_term_relationships r JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=p.ID AND tt.taxonomy='post_tag' AND tt.term_id=?)"; $params[]=$tag; }
    $count=(int)(row("SELECT COUNT(*) n FROM wp_posts p WHERE $where",$params)['n'] ?? 0);
    $items=rows("SELECT p.ID,p.post_title,p.post_status,p.post_date,p.post_modified,p.comment_count,u.display_name author,
        (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM wp_term_relationships r JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=r.term_taxonomy_id JOIN wp_terms t ON t.term_id=tt.term_id WHERE r.object_id=p.ID AND tt.taxonomy='category') categories,
        (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM wp_term_relationships r JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=r.term_taxonomy_id JOIN wp_terms t ON t.term_id=tt.term_id WHERE r.object_id=p.ID AND tt.taxonomy='post_tag') tags
        FROM wp_posts p LEFT JOIN wp_users u ON u.ID=p.post_author WHERE $where ORDER BY p.post_date DESC,p.ID DESC LIMIT $limit OFFSET $offset",$params);
    $categories=rows("SELECT t.term_id,t.name FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id AND tt.taxonomy='category' ORDER BY t.name");
    admin_layout('Posts',static function() use($status,$q,$category,$tag,$page,$limit,$totals,$count,$items,$categories){
        $all=array_sum(array_filter($totals,static fn($key)=>$key!=='trash',ARRAY_FILTER_USE_KEY));
        ?><div class="wp-posts-list"><div class="wp-list-top"><div class="wp-list-title"><h1>Posts</h1><a class="secondary" href="<?=h(path('/admin/post/new'))?>">Add New Post</a></div><details class="wp-list-screen"><summary aria-label="Screen Options" title="Screen Options"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2" fill="white"/><circle cx="15" cy="12" r="2" fill="white"/><circle cx="10" cy="17" r="2" fill="white"/></svg></summary><div class="wp-list-screen-panel"><strong>Columns</strong><?php foreach(['author'=>'Author','categories'=>'Categories','tags'=>'Tags','comments'=>'Comments','date'=>'Date'] as $key=>$label): ?><label><input type="checkbox" data-post-column="<?=h($key)?>" checked> <?=h($label)?></label><?php endforeach; ?><strong>Pagination</strong><form action="<?=h(path('/admin/posts'))?>"><input type="hidden" name="status" value="<?=h($status)?>"><input type="hidden" name="q" value="<?=h($q)?>"><input type="hidden" name="category" value="<?=h($category)?>"><input type="hidden" name="tag" value="<?=h($tag)?>"><label>Posts per page <select name="per_page" onchange="this.form.submit()"><?php foreach([10,20,50,100] as $number): ?><option value="<?=$number?>" <?=$limit===$number?'selected':''?>><?=$number?></option><?php endforeach; ?></select></label></form></div></details></div>
        <nav class="wp-status-links" aria-label="Post statuses"><a class="<?=$status===''?'active':''?>" href="<?=h(path('/admin/posts'))?>">All <span>(<?=$all?>)</span></a><?php foreach(['publish'=>'Published','draft'=>'Drafts','pending'=>'Pending','future'=>'Scheduled','private'=>'Private','trash'=>'Trash'] as $key=>$label): if(!($totals[$key] ?? 0) && $status!==$key) continue; ?><a class="<?=$status===$key?'active':''?>" href="<?=h(path('/admin/posts?status='.$key))?>"><?=h($label)?> <span>(<?=h($totals[$key] ?? 0)?>)</span></a><?php endforeach; ?></nav>
        <form class="wp-list-filter" action="<?=h(path('/admin/posts'))?>"><input type="hidden" name="status" value="<?=h($status)?>"><input type="hidden" name="tag" value="<?=h($tag)?>"><input type="hidden" name="per_page" value="<?=$limit?>"><label class="sr-only" for="post-category-filter">Filter by category</label><select id="post-category-filter" name="category"><option value="0">All categories</option><?php foreach($categories as $term): ?><option value="<?=h($term['term_id'])?>" <?=$category===$term['term_id']?'selected':''?>><?=h($term['name'])?></option><?php endforeach; ?></select><button class="secondary">Filter</button><div class="wp-list-search"><label class="sr-only" for="post-search">Search posts</label><input id="post-search" name="q" value="<?=h($q)?>"><button class="secondary">Search Posts</button></div></form>
        <form method="post" action="<?=h(path('/admin/posts/bulk'))?>" onsubmit="return this.querySelector('select[name=action]').value!=='delete'||confirm('Permanently delete the selected posts?')"><?=csrf_field()?><div class="wp-list-actions"><select name="action" aria-label="Bulk actions"><option value="">Bulk actions</option><option value="publish">Publish</option><option value="draft">Move to Draft</option><option value="trash">Move to Trash</option><?php if($status==='trash'): ?><option value="restore">Restore to Draft</option><option value="delete">Delete Permanently</option><?php endif; ?></select><button class="secondary">Apply</button><span><?=number_format($count)?> <?=$count===1?'item':'items'?></span></div>
        <div class="wp-list-table-wrap"><table class="wp-list-table"><thead><tr><th><input type="checkbox" aria-label="Select all posts" onclick="document.querySelectorAll('.post-select').forEach(box=>box.checked=this.checked)"></th><th>Title</th><th data-post-col="author">Author</th><th data-post-col="categories">Categories</th><th data-post-col="tags">Tags</th><th data-post-col="comments">Comments</th><th data-post-col="date">Date</th></tr></thead><tbody>
        <?php foreach($items as $item): ?><tr><td><input class="post-select" type="checkbox" name="ids[]" value="<?=h($item['ID'])?>" aria-label="Select <?=h($item['post_title'])?>"></td><td><a href="<?=h(path('/admin/post?id='.$item['ID']))?>"><strong><?=h($item['post_title'] ?: '(no title)')?></strong></a><div class="row-actions"><a href="<?=h(path('/admin/post?id='.$item['ID']))?>">Edit</a><?php if($item['post_status']==='trash'): ?><button name="row_action" value="restore:<?=h($item['ID'])?>">Restore</button><button name="row_action" value="delete:<?=h($item['ID'])?>" onclick="return confirm('Permanently delete this post?')">Delete Permanently</button><?php else: ?><button type="button" onclick="document.getElementById('post-quick-<?=h($item['ID'])?>').hidden=false">Quick Edit</button><button name="row_action" value="trash:<?=h($item['ID'])?>">Trash</button><?php endif; ?><a href="<?=h(path('/admin/post/preview?id='.$item['ID']))?>">Preview</a></div></td><td data-post-col="author"><?=h($item['author'] ?: '—')?></td><td data-post-col="categories"><?=h($item['categories'] ?: '—')?></td><td data-post-col="tags"><?=h($item['tags'] ?: '—')?></td><td data-post-col="comments"><?=h($item['comment_count'])?></td><td data-post-col="date"><?=h($item['post_date'])?></td></tr>
        <tr class="quick-edit-row" id="post-quick-<?=h($item['ID'])?>" hidden><td colspan="7"><div class="quick-grid"><label>Title<input name="quick[<?=h($item['ID'])?>][title]" value="<?=h($item['post_title'])?>"></label><label>Status<select name="quick[<?=h($item['ID'])?>][status]"><?php foreach(['publish'=>'Published','draft'=>'Draft','pending'=>'Pending','private'=>'Private'] as $value=>$label): ?><option value="<?=$value?>" <?=$item['post_status']===$value?'selected':''?>><?=$label?></option><?php endforeach; ?></select></label><button class="primary" name="row_action" value="quick:<?=h($item['ID'])?>">Update</button><button class="secondary" type="button" onclick="this.closest('tr').hidden=true">Cancel</button></div></td></tr>
        <?php endforeach; if(!$items): ?><tr><td colspan="7">No posts found. <a href="<?=h(path('/admin/post/new'))?>">Add your first post</a>.</td></tr><?php endif; ?></tbody></table></div></form><?=admin_pages_nav('/admin/posts',$page,$count,$limit,['q'=>$q,'status'=>$status,'category'=>$category,'tag'=>$tag,'per_page'=>$limit])?></div><link rel="stylesheet" href="<?=h(path('/assets/posts-list.css'))?>"><script src="<?=h(path('/assets/posts-list.js'))?>" defer></script><?php
    });
}

function admin_post(int $id=0): void {
    $post=$id?row("SELECT ID,post_title,post_name,post_excerpt,post_content,post_status,comment_status,post_date,post_author FROM wp_posts WHERE ID=? AND post_type='post'",[$id]):null;
    if ($id && !$post) { http_response_code(404); exit('Post not found.'); }
    $categories=rows("SELECT t.term_id,t.name,tt.term_taxonomy_id FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id AND tt.taxonomy='category' ORDER BY t.name");
    $selected=[]; $tags=[]; $format='standard';
    if($id) foreach(rows("SELECT tt.taxonomy,tt.term_taxonomy_id,t.name FROM wp_term_relationships r JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=r.term_taxonomy_id JOIN wp_terms t ON t.term_id=tt.term_id WHERE r.object_id=? AND tt.taxonomy IN ('category','post_tag')",[$id]) as $term) { if($term['taxonomy']==='category') $selected[]=(int)$term['term_taxonomy_id']; else $tags[]=$term['name']; }
    if($id) {
        $formatTerm=row("SELECT t.slug FROM wp_term_relationships r JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=r.term_taxonomy_id JOIN wp_terms t ON t.term_id=tt.term_id WHERE r.object_id=? AND tt.taxonomy='post_format' LIMIT 1",[$id]);
        if($formatTerm) $format=str_replace('post-format-','',(string)$formatTerm['slug']);
    }
    $thumbnailId=$id?(int)(row("SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key='_thumbnail_id' ORDER BY meta_id DESC LIMIT 1",[$id])['meta_value'] ?? 0):0;
    admin_layout($post?'Edit post':'Add post',static function() use($post,$categories,$selected,$tags,$format,$thumbnailId){
        ?><div class="wp-post-heading"><h1><?=$post?'Edit Post':'Add Post'?></h1><div class="post-screen-options"><details id="post-screen-options"><summary aria-label="Screen Options" title="Screen Options"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2" fill="white"/><circle cx="15" cy="12" r="2" fill="white"/><circle cx="10" cy="17" r="2" fill="white"/></svg></summary><div class="screen-options-content"><strong>Show on screen</strong><?php foreach(['publish'=>'Publish','format'=>'Format','categories'=>'Categories','tags'=>'Tags','featured'=>'Featured image','excerpt'=>'Excerpt','discussion'=>'Discussion'] as $key=>$label): ?><label><input type="checkbox" data-toggle-panel="<?=h($key)?>" checked> <?=h($label)?></label><?php endforeach; ?></div></details></div></div>
        <form id="post-form" method="post" action="<?=h(path('/admin/post'))?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($post['ID'] ?? 0)?>"><input type="hidden" name="featured_image_id" id="featured-image-id" value="<?=$thumbnailId?>">
        <label class="post-title-label"><span class="sr-only">Post title</span><input name="title" maxlength="300" value="<?=h($post['post_title'] ?? '')?>" placeholder="Add title" required></label>
        <?php if($post): ?><div class="post-permalink">Permalink: <span><?=h($post['post_name'])?></span></div><?php endif; ?>
        <div class="wp-post-grid"><div class="wp-post-main"><div class="post-media-row"><button type="button" class="secondary" data-open-media="insert">▣ Add Media</button><span>Visual editor</span></div><textarea id="post-content" name="content" aria-label="Post content"><?=h($post['post_content'] ?? '')?></textarea><section class="wp-metabox" data-screen-panel="excerpt"><h2>Excerpt</h2><div class="wp-metabox-body"><textarea name="excerpt" rows="4"><?=h($post['post_excerpt'] ?? '')?></textarea><small>Optional summary of the post.</small></div></section></div>
        <aside class="wp-post-sidebar"><section class="wp-metabox" data-screen-panel="publish"><h2>Publish</h2><div class="wp-metabox-body"><div class="publish-actions"><button class="secondary" name="save_as" value="draft" type="submit">Save Draft</button><?php if($post): ?><a class="secondary" target="_blank" href="<?=h(path('/admin/post/preview?id='.$post['ID']))?>">Preview</a><?php else: ?><button class="secondary" type="button" id="preview-unsaved">Preview</button><?php endif; ?></div><label>Status <select name="status" id="post-status"><?php foreach(['draft'=>'Draft','publish'=>'Published','pending'=>'Pending review','private'=>'Private'] as $value=>$label): ?><option value="<?=h($value)?>" <?=($post['post_status'] ?? 'draft')===$value?'selected':''?>><?=h($label)?></option><?php endforeach; ?></select></label><p>Visibility: <strong><?=($post['post_status'] ?? '')==='private'?'Private':'Public'?></strong></p><p>Publish: <?=h($post['post_date'] ?? 'Immediately')?></p><label>Slug<input name="slug" maxlength="200" value="<?=h($post['post_name'] ?? '')?>" placeholder="Generated from title"></label></div><div class="publish-footer"><?php if($post): ?><a href="<?=h(path('/admin/posts'))?>">All Posts</a><?php endif; ?><button class="primary" type="submit"><?=$post?'Update':'Publish'?></button></div></section>
        <section class="wp-metabox" data-screen-panel="format"><h2>Format</h2><div class="wp-metabox-body format-options"><?php foreach(['standard'=>'Standard','audio'=>'Audio','gallery'=>'Gallery','video'=>'Video','quote'=>'Quote','link'=>'Link'] as $value=>$label): ?><label><input type="radio" name="format" value="<?=h($value)?>" <?=$format===$value?'checked':''?>> <?=h($label)?></label><?php endforeach; ?></div></section>
        <section class="wp-metabox" data-screen-panel="categories"><h2>Categories</h2><div class="wp-metabox-body"><div class="post-category-list"><?php foreach($categories as $term): ?><label><input type="checkbox" name="categories[]" value="<?=h($term['term_taxonomy_id'])?>" <?=in_array((int)$term['term_taxonomy_id'],$selected,true)?'checked':''?>> <?=h($term['name'])?></label><?php endforeach; ?></div><a href="<?=h(path('/admin/taxonomy?taxonomy=category'))?>">+ Add New Category</a></div></section>
        <section class="wp-metabox" data-screen-panel="tags"><h2>Tags</h2><div class="wp-metabox-body"><label class="sr-only" for="post-tags">Tags</label><input id="post-tags" name="tags" value="<?=h(implode(', ',$tags))?>" placeholder="Separate tags with commas"><small>Separate tags with commas</small><a href="<?=h(path('/admin/taxonomy?taxonomy=post_tag'))?>">Manage tags</a></div></section>
        <section class="wp-metabox" data-screen-panel="featured"><h2>Featured image</h2><div class="wp-metabox-body"><img id="featured-image-preview" alt="Featured image" src="<?=$thumbnailId?h(path('/media/attachment?id='.$thumbnailId)):''?>" <?=$thumbnailId?'':'hidden'?>><button class="link-button" type="button" data-open-media="featured"><?=$thumbnailId?'Replace featured image':'Set featured image'?></button><button class="link-button" type="button" id="remove-featured" <?=$thumbnailId?'':'hidden'?>>Remove featured image</button></div></section>
        <section class="wp-metabox" data-screen-panel="discussion"><h2>Discussion</h2><div class="wp-metabox-body"><label><input type="checkbox" name="comments" value="open" <?=($post['comment_status'] ?? 'open')==='open'?'checked':''?>> Allow comments</label></div></section></aside></div></form>
        <dialog id="post-media-dialog" class="post-media-dialog"><div class="media-dialog-head"><h2>Media Library</h2><button type="button" id="close-media" aria-label="Close">×</button></div><div class="media-dialog-body"><div class="media-library-actions"><label>Search images<input id="media-search" type="search" placeholder="Search media"></label><label class="media-upload-label">Upload new image<input id="media-upload-input" type="file" accept="image/*"></label></div><div id="media-upload-status" role="status"></div><div class="media-library-layout"><div id="media-results" class="media-results"></div><div id="media-details" class="media-details" hidden><img id="media-detail-preview" alt=""><label>Title<input id="media-title" maxlength="200"></label><label>Alternative text<input id="media-alt" maxlength="500" placeholder="Describe the image"></label><div id="media-dimensions"></div><label>Display width (px)<input id="media-width" type="number" min="1" max="8000"></label><label>Display height (px)<input id="media-height" type="number" min="1" max="8000"></label><button type="button" class="secondary" id="save-media-details">Save image details</button><small>Click an inserted image and use TinyMCE’s Image button to edit its alt text and dimensions later.</small></div></div></div><div class="media-dialog-foot"><span id="media-selection"></span><button class="primary" type="button" id="use-media" disabled>Use image</button></div></dialog><link rel="stylesheet" href="<?=h(path('/assets/post-editor.css'))?>"><script src="<?=h(path('/assets/vendor/tinymce/tinymce.min.js'))?>"></script><script src="<?=h(path('/assets/media-upload.js'))?>"></script><script src="<?=h(path('/assets/post-editor.js'))?>" defer></script><?php
    });
}

function classic_post_editor(string $content): void {
    ?><div class="classic-editor" data-classic-editor><div class="classic-editor-head"><strong>Content</strong><div class="classic-tabs"><button type="button" class="active" data-editor-tab="visual">Visual</button><button type="button" data-editor-tab="text">Text</button></div></div><div class="classic-toolbar" role="toolbar" aria-label="Post formatting"><select data-format-block aria-label="Paragraph format"><option value="p">Paragraph</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option><option value="blockquote">Quote</option></select><button type="button" data-command="bold" title="Bold"><strong>B</strong></button><button type="button" data-command="italic" title="Italic"><em>I</em></button><button type="button" data-command="insertUnorderedList" title="Bulleted list">• List</button><button type="button" data-command="insertOrderedList" title="Numbered list">1. List</button><button type="button" data-link title="Insert link">Link</button><button type="button" data-command="removeFormat" title="Clear formatting">Clear</button></div><div class="classic-visual" contenteditable="true" role="textbox" aria-label="Post content visual editor" aria-multiline="true"></div><textarea class="classic-source" name="content" rows="18" aria-label="Post content HTML source" hidden><?=h($content)?></textarea><div class="classic-editor-foot">Classic editor · use Visual for formatting or Text for HTML.</div></div><script src="<?=h(path('/assets/classic-editor.js'))?>" defer></script><?php
}

function save_post(): never {
    $id=max(0,(int)($_POST['id'] ?? 0));
    $originalId=$id;
    $before=$id?row("SELECT ID,post_title,post_name,post_status,post_content FROM wp_posts WHERE ID=? AND post_type='post'",[$id]):null;
    if($id && !$before) { http_response_code(404); exit('Post not found.'); }
    $title=trim((string)($_POST['title'] ?? ''));
    $postSlug=slug((string)($_POST['slug'] ?? '')) ?: slug($title);
    $saveAs=(string)($_POST['save_as'] ?? '');
    $status=$saveAs==='draft'?'draft':($saveAs==='publish'?'publish':(string)($_POST['status'] ?? 'draft')); $comments=isset($_POST['comments'])?'open':'closed';
    $format=(string)($_POST['format'] ?? 'standard');
    $thumbnailId=max(0,(int)($_POST['featured_image_id'] ?? 0));
    if($title==='' || $postSlug==='' || !in_array($status,['draft','publish','pending','private'],true) || !in_array($format,['standard','audio','gallery','video','quote','link'],true)) { notice('Check the post title, slug and status.'); redirect($id?'/admin/post?id='.$id:'/admin/post/new'); }
    $categories=$_POST['categories'] ?? []; if(!is_array($categories)) { http_response_code(400); exit('Invalid categories.'); }
    $categoryIds=array_values(array_unique(array_filter(array_map('intval',$categories),static fn($n)=>$n>0)));
    if(!$categoryIds) {
        $defaultCategory=row("SELECT term_taxonomy_id FROM wp_term_taxonomy WHERE taxonomy='category' ORDER BY term_taxonomy_id LIMIT 1");
        if($defaultCategory) $categoryIds=[(int)$defaultCategory['term_taxonomy_id']];
    }
    $tagNames=array_values(array_unique(array_filter(array_map('trim',explode(',',(string)($_POST['tags'] ?? ''))))));
    if(count($tagNames)>30 || count($categoryIds)>50) { notice('Too many categories or tags.'); redirect($id?'/admin/post?id='.$id:'/admin/post/new'); }
    $content=(string)($_POST['content'] ?? ''); $excerpt=(string)($_POST['excerpt'] ?? '');
    db()->beginTransaction();
    try {
        $duplicate=row("SELECT ID FROM wp_posts WHERE post_type='post' AND post_name=? AND ID<>? LIMIT 1",[$postSlug,$id]);
        if($duplicate) throw new InvalidArgumentException('That post slug is already used.');
        if($id) exec_sql("UPDATE wp_posts SET post_title=?,post_name=?,post_content=?,post_excerpt=?,post_status=?,comment_status=?,post_modified=NOW(),post_modified_gmt=UTC_TIMESTAMP() WHERE ID=? AND post_type='post'",[$title,$postSlug,$content,$excerpt,$status,$comments,$id]);
        else { exec_sql("INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (?,NOW(),UTC_TIMESTAMP(),?,?,?,? ,?,'closed',?,'','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'post','',0)",[(int)(current_user()['id'] ?? 0),$content,$title,$excerpt,$status,$comments,$postSlug]); $id=(int)db()->lastInsertId(); }
        $taxonomyIds=[];
        foreach($categoryIds as $termTaxonomyId) { $term=row("SELECT term_taxonomy_id FROM wp_term_taxonomy WHERE term_taxonomy_id=? AND taxonomy='category'",[$termTaxonomyId]); if(!$term) throw new InvalidArgumentException('Unknown category.'); $taxonomyIds[]=$termTaxonomyId; }
        foreach($tagNames as $name) {
            if(mb_strlen($name)>200) throw new InvalidArgumentException('Tag name is too long.');
            $tagSlug=slug($name); if($tagSlug==='') continue;
            $term=row("SELECT tt.term_taxonomy_id FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id AND tt.taxonomy='post_tag' WHERE t.slug=? LIMIT 1",[$tagSlug]);
            if(!$term) { exec_sql('INSERT INTO wp_terms (name,slug,term_group) VALUES (?,?,0)',[$name,$tagSlug]); $termId=(int)db()->lastInsertId(); exec_sql("INSERT INTO wp_term_taxonomy (term_id,taxonomy,description,parent,count) VALUES (?,'post_tag','',0,0)",[$termId]); $term=['term_taxonomy_id'=>(int)db()->lastInsertId()]; }
            $taxonomyIds[]=(int)$term['term_taxonomy_id'];
        }
        if($format!=='standard') {
            $formatSlug='post-format-'.$format;
            $term=row("SELECT tt.term_taxonomy_id FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id AND tt.taxonomy='post_format' WHERE t.slug=? LIMIT 1",[$formatSlug]);
            if(!$term) { exec_sql('INSERT INTO wp_terms (name,slug,term_group) VALUES (?,?,0)',[ucfirst($format),$formatSlug]); $termId=(int)db()->lastInsertId(); exec_sql("INSERT INTO wp_term_taxonomy (term_id,taxonomy,description,parent,count) VALUES (?,'post_format','',0,0)",[$termId]); $term=['term_taxonomy_id'=>(int)db()->lastInsertId()]; }
            $taxonomyIds[]=(int)$term['term_taxonomy_id'];
        }
        if($thumbnailId && !row("SELECT ID FROM wp_posts WHERE ID=? AND post_type='attachment' AND post_mime_type LIKE 'image/%'",[$thumbnailId])) throw new InvalidArgumentException('Choose a valid featured image.');
        exec_sql("DELETE FROM wp_postmeta WHERE post_id=? AND meta_key='_thumbnail_id'",[$id]);
        if($thumbnailId) exec_sql("INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (?,'_thumbnail_id',?)",[$id,(string)$thumbnailId]);
        $oldTermIds=array_map('intval',array_column(rows("SELECT r.term_taxonomy_id FROM wp_term_relationships r JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=? AND tt.taxonomy IN ('category','post_tag','post_format')",[$id]),'term_taxonomy_id'));
        exec_sql("DELETE r FROM wp_term_relationships r JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=? AND tt.taxonomy IN ('category','post_tag','post_format')",[$id]);
        foreach(array_unique($taxonomyIds) as $termTaxonomyId) exec_sql('INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (?,?,0)',[$id,$termTaxonomyId]);
        foreach(array_unique(array_merge($oldTermIds,$taxonomyIds)) as $termTaxonomyId) exec_sql("UPDATE wp_term_taxonomy SET count=(SELECT COUNT(*) FROM wp_term_relationships WHERE term_taxonomy_id=?) WHERE term_taxonomy_id=?",[$termTaxonomyId,$termTaxonomyId]);
        audit_change('post',$id,$before?'update':'create',$before ?? [],['title'=>$title,'slug'=>$postSlug,'status'=>$status,'categories'=>$categoryIds,'tags'=>$tagNames]);
        db()->commit(); notice('Post saved.'); redirect('/admin/post?id='.$id);
    } catch(Throwable $error) { db()->rollBack(); error_log((string)$error); notice($error instanceof InvalidArgumentException?$error->getMessage():'Could not save post.'); redirect($originalId?'/admin/post?id='.$originalId:'/admin/post/new'); }
}

function admin_post_preview(int $id): void {
    $post=row("SELECT ID,post_title,post_content,post_excerpt,post_status,post_date FROM wp_posts WHERE ID=? AND post_type='post'",[$id]);
    if(!$post) { http_response_code(404); exit('Post not found.'); }
    admin_layout('Preview post',static function() use($post){ ?><div class="heading"><div><div class="eyebrow">POST PREVIEW · <?=h($post['post_status'])?></div><h1><?=h($post['post_title'] ?: '(no title)')?></h1><p><?=h($post['post_date'])?></p></div><a class="secondary" href="<?=h(path('/admin/post?id='.$post['ID']))?>">Edit post</a></div><article class="panel post-preview"><p><?=nl2br(h(strip_tags($post['post_excerpt'])))?></p><div><?=nl2br(h(strip_tags($post['post_content'])))?></div></article><?php });
}

function bulk_post_actions(): never {
    $rowAction=(string)($_POST['row_action'] ?? ''); $action=(string)($_POST['action'] ?? '');
    $ids=$_POST['ids'] ?? [];
    if($rowAction!=='' && preg_match('/^(trash|restore|delete|quick):(\d+)$/',$rowAction,$matches)) { $action=$matches[1]; $ids=[(int)$matches[2]]; }
    if(!is_array($ids) || !in_array($action,['publish','draft','trash','restore','delete','quick'],true)) { notice('Choose a post action.'); redirect('/admin/posts'); }
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));
    if(!$ids || count($ids)>100) { notice('Select up to 100 posts.'); redirect('/admin/posts'); }
    $target=$action==='restore'?'draft':$action;
    db()->beginTransaction();
    try {
        foreach($ids as $id) {
            $post=row("SELECT ID,post_title,post_status FROM wp_posts WHERE ID=? AND post_type='post'",[$id]); if(!$post) continue;
            if($action==='restore' && $post['post_status']!=='trash') continue;
            if($action==='delete') {
                if($post['post_status']!=='trash') continue;
                $terms=array_map('intval',array_column(rows('SELECT term_taxonomy_id FROM wp_term_relationships WHERE object_id=?',[$id]),'term_taxonomy_id'));
                exec_sql('DELETE FROM wp_term_relationships WHERE object_id=?',[$id]);
                exec_sql('DELETE FROM wp_postmeta WHERE post_id=?',[$id]);
                exec_sql('DELETE cm FROM wp_commentmeta cm JOIN wp_comments c ON c.comment_ID=cm.comment_id WHERE c.comment_post_ID=?',[$id]);
                exec_sql('DELETE FROM wp_comments WHERE comment_post_ID=?',[$id]);
                exec_sql("DELETE FROM wp_posts WHERE ID=? AND post_type='post'",[$id]);
                foreach($terms as $termId) exec_sql('UPDATE wp_term_taxonomy SET count=(SELECT COUNT(*) FROM wp_term_relationships WHERE term_taxonomy_id=?) WHERE term_taxonomy_id=?',[$termId,$termId]);
                audit_change('post',$id,'delete',$post,[]);
            } elseif($action==='quick') {
                $input=$_POST['quick'][$id] ?? [];
                $title=trim((string)($input['title'] ?? ''));
                $status=(string)($input['status'] ?? '');
                if($title==='' || !in_array($status,['publish','draft','pending','private'],true)) throw new InvalidArgumentException('Check the quick edit fields.');
                exec_sql("UPDATE wp_posts SET post_title=?,post_status=?,post_modified=NOW(),post_modified_gmt=UTC_TIMESTAMP() WHERE ID=? AND post_type='post'",[$title,$status,$id]);
                audit_change('post',$id,'quick-edit',$post,['post_title'=>$title,'post_status'=>$status]);
            } else {
                exec_sql("UPDATE wp_posts SET post_status=?,post_modified=NOW(),post_modified_gmt=UTC_TIMESTAMP() WHERE ID=?",[$target,$id]);
                audit_change('post',$id,$action,$post,['post_status'=>$target]);
            }
        }
        db()->commit(); notice('Posts updated.');
    } catch(Throwable $error) { db()->rollBack(); error_log((string)$error); notice($error instanceof InvalidArgumentException?$error->getMessage():'Could not update posts.'); }
    redirect('/admin/posts');
}
