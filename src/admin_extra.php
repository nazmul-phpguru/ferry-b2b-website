<?php
declare(strict_types=1);

function taxonomy_name(string $taxonomy): string {
    return match ($taxonomy) {
        'product_cat' => 'Categories',
        'product_tag' => 'Tags',
        'pa_branding' => 'Brands',
        'product_brand' => 'Brands',
        'category' => 'Categories',
        'post_tag' => 'Tags',
        default => '',
    };
}
function admin_brands(): void {
    $live=(int)(row("SELECT COUNT(*) n FROM wp_term_taxonomy WHERE taxonomy='product_brand'")['n'] ?? 0);
    $_GET['taxonomy']=$live?'product_brand':'pa_branding';
    admin_taxonomy();
}
function admin_taxonomy(): void {
    $taxonomy=(string)($_GET['taxonomy'] ?? 'product_cat');
    if(in_array($taxonomy,['category','post_tag'],true)) { admin_post_terms($taxonomy); return; }
    $title=taxonomy_name($taxonomy);
    if ($title==='') { http_response_code(404); exit('Taxonomy not found.'); }
    $terms=rows('SELECT t.term_id,t.name,t.slug,tt.description,tt.parent,tt.count FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy=? ORDER BY t.name',[$taxonomy]);
    $editId=(int)($_GET['edit'] ?? 0); $edit=null;
    foreach ($terms as $term) if ((int)$term['term_id']===$editId) $edit=$term;
    admin_layout($title,static function() use($taxonomy,$title,$terms,$edit){
        ?><div class="heading"><div><div class="eyebrow"><?=in_array($taxonomy,['category','post_tag'],true)?'POSTS':'PRODUCTS'?></div><h1><?=h($title)?></h1><p><?=count($terms)?> <?=h(strtolower($title))?></p></div></div>
        <div class="two-col"><section class="panel"><h2><?= $edit?'Edit':'Add' ?> <?=h(rtrim(strtolower($title),'s'))?></h2>
        <form method="post" action="<?=h(path('/admin/taxonomy'))?>"><?=csrf_field()?><input type="hidden" name="taxonomy" value="<?=h($taxonomy)?>"><input type="hidden" name="id" value="<?=h($edit['term_id'] ?? 0)?>">
        <label>Name<input name="name" maxlength="200" value="<?=h($edit['name'] ?? '')?>" required></label>
        <label>Slug<input name="slug" maxlength="200" value="<?=h($edit['slug'] ?? '')?>" required></label>
        <?php if(in_array($taxonomy,['product_cat','category'],true)): ?><label>Parent<select name="parent"><option value="0">None</option><?php foreach($terms as $term): if($edit && $term['term_id']===$edit['term_id']) continue; ?><option value="<?=h($term['term_id'])?>" <?=($edit['parent'] ?? 0)==$term['term_id']?'selected':''?>><?=h($term['name'])?></option><?php endforeach; ?></select></label><?php endif; ?>
        <label>Description<textarea name="description" rows="4"><?=h($edit['description'] ?? '')?></textarea></label><button class="primary">Save <?=h(rtrim(strtolower($title),'s'))?></button></form></section>
        <section class="panel table-wrap"><table><thead><tr><th>Name</th><th>Slug</th><th><?=in_array($taxonomy,['category','post_tag'],true)?'Posts':'Products'?></th></tr></thead><tbody><?php foreach($terms as $term): ?><tr><td><a href="<?=h(path('/admin/taxonomy?taxonomy='.rawurlencode($taxonomy).'&edit='.$term['term_id']))?>"><?=h($term['name'])?></a></td><td><?=h($term['slug'])?></td><td><?=h($term['count'])?></td></tr><?php endforeach; ?></tbody></table></section></div><?php
    });
}
function save_taxonomy(): never {
    $taxonomy=(string)($_POST['taxonomy'] ?? '');
    if (taxonomy_name($taxonomy)==='') { http_response_code(400); exit('Invalid taxonomy.'); }
    $id=(int)($_POST['id'] ?? 0); $name=trim((string)($_POST['name'] ?? ''));
    $slug=trim((string)($_POST['slug'] ?? '')); if($slug==='') $slug=slug($name); $description=trim((string)($_POST['description'] ?? ''));
    $parent=in_array($taxonomy,['product_cat','category'],true)?(int)($_POST['parent'] ?? 0):0;
    if ($name==='' || !preg_match('/^[a-z0-9-]+$/',$slug) || $parent===$id && $id>0) {
        notice('Check the name, slug and parent.'); redirect('/admin/taxonomy?taxonomy='.$taxonomy.($id?'&edit='.$id:''));
    }
    if ($parent && !row('SELECT term_taxonomy_id FROM wp_term_taxonomy WHERE term_id=? AND taxonomy=?',[$parent,$taxonomy])) {
        http_response_code(400); exit('Invalid parent.');
    }
    $existing=row('SELECT t.term_id,t.name,t.slug,tt.description,tt.parent FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE t.term_id=? AND tt.taxonomy=?',[$id,$taxonomy]);
    if ($id && !$existing) { http_response_code(404); exit('Term not found.'); }
    db()->beginTransaction();
    try {
        if ($id) {
            exec_sql('UPDATE wp_terms SET name=?,slug=? WHERE term_id=?',[$name,$slug,$id]);
            exec_sql('UPDATE wp_term_taxonomy SET description=?,parent=? WHERE term_id=? AND taxonomy=?',[$description,$parent,$id,$taxonomy]);
        } else {
            exec_sql('INSERT INTO wp_terms (name,slug,term_group) VALUES (?,?,0)',[$name,$slug]);
            $id=(int)db()->lastInsertId();
            exec_sql('INSERT INTO wp_term_taxonomy (term_id,taxonomy,description,parent,count) VALUES (?,?,?,?,0)',[$id,$taxonomy,$description,$parent]);
        }
        audit_change('taxonomy',$id,$existing?'update':'create',$existing ?? [],['taxonomy'=>$taxonomy,'name'=>$name,'slug'=>$slug,'description'=>$description,'parent'=>$parent]);
        db()->commit(); notice('Term saved.');
    } catch (Throwable $error) { db()->rollBack(); error_log((string)$error); notice('Could not save term. Check that its slug is unique.'); }
    redirect('/admin/taxonomy?taxonomy='.$taxonomy);
}
function admin_attributes(): void {
    $attributes=rows('SELECT attribute_id,attribute_name,attribute_label,attribute_type,attribute_orderby,attribute_public FROM wp_woocommerce_attribute_taxonomies ORDER BY attribute_label');
    admin_layout('Attributes',static function() use($attributes){ ?><div class="heading"><div><div class="eyebrow">PRODUCTS</div><h1>Attributes</h1><p><?=count($attributes)?> product attributes</p></div></div><div class="panel table-wrap"><table><thead><tr><th>Label</th><th>Slug</th><th>Type</th><th>Sort order</th><th>Visible</th></tr></thead><tbody><?php foreach($attributes as $a): ?><tr><td><?=h($a['attribute_label'])?></td><td>pa_<?=h($a['attribute_name'])?></td><td><?=h($a['attribute_type'])?></td><td><?=h($a['attribute_orderby'])?></td><td><?=$a['attribute_public']?'Yes':'No'?></td></tr><?php endforeach; ?></tbody></table></div><?php });
}
function admin_media(): void {
    $q=trim((string)($_GET['q'] ?? '')); $type=(string)($_GET['type'] ?? 'images'); if(!in_array($type,['images','all','other'],true)) $type='images';
    $month=(string)($_GET['month'] ?? ''); if($month!=='' && !preg_match('/^\d{4}-\d{2}$/',$month)) $month='';
    $page=max(1,(int)($_GET['page'] ?? 1)); $limit=(int)($_GET['per_page'] ?? 40); if(!in_array($limit,[20,40,80],true)) $limit=40; $offset=($page-1)*$limit;
    $where="p.post_type='attachment'"; $params=[];
    if($type==='images') $where.=" AND p.post_mime_type LIKE 'image/%'";
    if($type==='other') $where.=" AND p.post_mime_type NOT LIKE 'image/%'";
    if($q!=='') { $where.=' AND (p.post_title LIKE ? OR p.guid LIKE ?)'; array_push($params,'%'.$q.'%','%'.$q.'%'); }
    if($month!=='') { $where.=' AND p.post_date>=? AND p.post_date<?'; $start=$month.'-01 00:00:00'; $end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d H:i:s'); array_push($params,$start,$end); }
    $count=(int)(row("SELECT COUNT(*) n FROM wp_posts p WHERE $where",$params)['n'] ?? 0);
    $files=rows("SELECT p.ID,p.post_title,p.post_mime_type,p.post_date,p.guid,(SELECT meta_value FROM wp_postmeta WHERE post_id=p.ID AND meta_key='_wp_attachment_image_alt' ORDER BY meta_id DESC LIMIT 1) alt,(SELECT meta_value FROM wp_postmeta WHERE post_id=p.ID AND meta_key='_wp_attachment_metadata' ORDER BY meta_id DESC LIMIT 1) metadata,(SELECT meta_value FROM wp_postmeta WHERE post_id=p.ID AND meta_key='_wp_attached_file' ORDER BY meta_id DESC LIMIT 1) attached_file FROM wp_posts p WHERE $where ORDER BY p.ID DESC LIMIT $limit OFFSET $offset",$params);
    $months=rows("SELECT DATE_FORMAT(post_date,'%Y-%m') value,DATE_FORMAT(post_date,'%M %Y') label FROM wp_posts WHERE post_type='attachment' GROUP BY value,label ORDER BY value DESC LIMIT 36");
    admin_layout('Media Library',static function() use($q,$type,$month,$count,$files,$months,$page,$limit){ ?>
      <div class="media-page"><div class="media-page-head"><div><h1>Media Library</h1><p><?=number_format($count)?> files</p></div><div class="media-head-actions"><button class="secondary" type="button" id="open-media-upload">Add New Media File</button><details class="media-screen-options"><summary aria-label="Screen Options" title="Screen Options">⚙</summary><div class="media-screen-menu"><strong>Media Library</strong><label>Items per page <select id="media-per-page"><?php foreach([20,40,80] as $number): ?><option value="<?=$number?>" <?=$limit===$number?'selected':''?>><?=$number?></option><?php endforeach; ?></select></label></div></details></div></div>
      <section class="media-upload-panel" id="media-upload-panel" hidden><h2>Upload New Media</h2><form id="media-page-upload"><?=csrf_field()?><label class="media-drop-zone" id="media-drop-zone"><strong>Drop images here or click to select</strong><span>New uploads are resized and converted to WebP.</span><input type="file" name="image" accept="image/*" multiple required></label><button class="primary" type="submit">Upload Images</button><p id="media-page-status" role="status"></p></form></section>
      <form class="media-toolbar" action="<?=h(path('/admin/media'))?>"><div class="media-view-switch" role="group" aria-label="View"><button type="button" data-media-view="grid" aria-label="Grid view" title="Grid view">▦</button><button type="button" data-media-view="list" aria-label="List view" title="List view">☷</button></div><label class="sr-only" for="media-type">Media type</label><select id="media-type" name="type"><option value="images" <?=$type==='images'?'selected':''?>>Images</option><option value="all" <?=$type==='all'?'selected':''?>>All media items</option><option value="other" <?=$type==='other'?'selected':''?>>Other files</option></select><label class="sr-only" for="media-month">Upload month</label><select id="media-month" name="month"><option value="">All dates</option><?php foreach($months as $entry): ?><option value="<?=h($entry['value'])?>" <?=$month===$entry['value']?'selected':''?>><?=h($entry['label'])?></option><?php endforeach; ?></select><label class="sr-only" for="media-search">Search media</label><input id="media-search" name="q" value="<?=h($q)?>" placeholder="Search media"><button class="secondary">Search</button></form>
      <div class="media-library-shell"><div class="media-library-content"><div class="media-grid" id="media-items"><?php foreach($files as $file): $isImage=str_starts_with((string)$file['post_mime_type'],'image/'); $meta=@unserialize((string)($file['metadata'] ?? ''),['allowed_classes'=>false]); if(!is_array($meta)) $meta=[]; $filename=basename((string)($file['attached_file'] ?: (parse_url($file['guid'],PHP_URL_PATH) ?: ''))); ?><button type="button" class="media-card" data-id="<?=h($file['ID'])?>" data-title="<?=h($file['post_title'])?>" data-alt="<?=h($file['alt'] ?? '')?>" data-file="<?=h($filename)?>" data-mime="<?=h($file['post_mime_type'])?>" data-date="<?=h($file['post_date'])?>" data-width="<?=h($meta['width'] ?? '')?>" data-height="<?=h($meta['height'] ?? '')?>" data-url="<?=$isImage?h(path('/media/attachment?id='.$file['ID'])):''?>"><span class="media-card-image"><?php if($isImage): ?><img loading="lazy" src="<?=h(path('/media/attachment?id='.$file['ID']))?>" alt="" onerror="this.hidden=true;this.parentElement.classList.add('media-missing')"><?php else: ?><span class="media-file-icon">▤</span><?php endif; ?></span><span class="media-card-title"><?=h($file['post_title'] ?: $filename ?: 'Untitled file')?></span><span class="media-card-date"><?=h(substr((string)$file['post_date'],0,10))?></span></button><?php endforeach; if(!$files): ?><div class="media-empty">No media found for these filters.</div><?php endif; ?></div><?=admin_pages_nav('/admin/media',$page,$count,$limit,['q'=>$q,'type'=>$type,'month'=>$month,'per_page'=>$limit])?></div>
      <aside class="media-inspector" id="media-inspector" hidden><div class="media-inspector-head"><h2>Attachment Details</h2><button type="button" id="close-media-details" aria-label="Close details">×</button></div><div class="media-inspector-preview" id="media-inspector-preview"></div><p id="media-inspector-file"></p><dl><dt>Uploaded</dt><dd id="media-inspector-date"></dd><dt>File type</dt><dd id="media-inspector-type"></dd><dt>Dimensions</dt><dd id="media-inspector-dimensions"></dd></dl><form id="media-details-form"><?=csrf_field()?><input type="hidden" name="id"><label>Title<input name="title" maxlength="200" required></label><label>Alternative text<input name="alt" maxlength="500" placeholder="Describe the image"></label><button class="primary" type="submit">Save Details</button><p id="media-details-status" role="status"></p></form><a id="media-open-file" target="_blank" rel="noopener">View file</a></aside></div></div><link rel="stylesheet" href="<?=h(path('/assets/media-page.css'))?>"><script src="<?=h(path('/assets/media-upload.js'))?>"></script><script src="<?=h(path('/assets/media-page.js'))?>" defer></script><?php
    });
}
function admin_reviews(): void {
    $reviews=rows("SELECT c.comment_ID,c.comment_post_ID,c.comment_author,c.comment_date,c.comment_content,c.comment_approved,p.post_title FROM wp_comments c JOIN wp_posts p ON p.ID=c.comment_post_ID WHERE p.post_type='product' AND c.comment_type IN ('review','') ORDER BY c.comment_ID DESC LIMIT 100");
    admin_layout('Reviews',static function() use($reviews){ ?><div class="heading"><div><div class="eyebrow">PRODUCTS</div><h1>Reviews</h1><p><?=count($reviews)?> latest reviews</p></div></div><div class="panel table-wrap"><table><thead><tr><th>Product</th><th>Author</th><th>Review</th><th>Status</th><th>Date</th></tr></thead><tbody><?php foreach($reviews as $r): ?><tr><td><a href="<?=h(path('/admin/product?id='.$r['comment_post_ID']))?>"><?=h($r['post_title'])?></a></td><td><?=h($r['comment_author'])?></td><td><?=h(mb_strimwidth($r['comment_content'],0,240,'…'))?></td><td><?=h($r['comment_approved'])?></td><td><?=h($r['comment_date'])?></td></tr><?php endforeach; ?></tbody></table></div><?php });
}
function admin_wholesale(): void {
    $roles=rows("SELECT meta_value,COUNT(*) n FROM wp_usermeta WHERE meta_key='wp_capabilities' GROUP BY meta_value ORDER BY n DESC LIMIT 30");
    $prices=rows("SELECT meta_key,COUNT(*) n FROM wp_postmeta WHERE meta_key LIKE '%wholesale_price' GROUP BY meta_key ORDER BY n DESC");
    admin_layout('Wholesale',static function() use($roles,$prices){ ?><div class="heading"><div><div class="eyebrow">WHOLESALE</div><h1>Wholesale overview</h1><p>Customer roles and role prices in the imported store</p></div></div><div class="two-col"><section class="panel"><h2>Customer roles</h2><div class="table-wrap"><table><thead><tr><th>Role</th><th>Users</th></tr></thead><tbody><?php foreach($roles as $r): $values=@unserialize($r['meta_value'],['allowed_classes'=>false]); $label=is_array($values)?implode(', ',array_keys($values)):'Unknown'; ?><tr><td><?=h($label)?></td><td><?=h($r['n'])?></td></tr><?php endforeach; ?></tbody></table></div></section><section class="panel"><h2>Product price fields</h2><div class="table-wrap"><table><thead><tr><th>Field</th><th>Products</th></tr></thead><tbody><?php foreach($prices as $p): ?><tr><td><?=h($p['meta_key'])?></td><td><?=h($p['n'])?></td></tr><?php endforeach; ?></tbody></table></div></section></div><?php });
}
function admin_reports(): void {
    $statuses=rows("SELECT post_status,COUNT(*) n FROM wp_posts WHERE post_type='shop_order' GROUP BY post_status ORDER BY n DESC");
    admin_layout('Reports',static function() use($statuses){ ?><div class="heading"><div><div class="eyebrow">COMMERCE</div><h1>Order report</h1><p>Order counts by status</p></div></div><div class="panel table-wrap"><table><thead><tr><th>Status</th><th>Orders</th></tr></thead><tbody><?php foreach($statuses as $status): ?><tr><td><?=h($status['post_status'])?></td><td><?=h($status['n'])?></td></tr><?php endforeach; ?></tbody></table></div><?php });
}
