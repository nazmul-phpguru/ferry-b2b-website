<?php
declare(strict_types=1);

function admin_new_product(): void {
    $roles=wholesale_roles();
    admin_layout('Add new product',static function() use($roles){
        ?><div class="heading"><div><div class="eyebrow">PRODUCTS</div><h1>Add new product</h1></div><a class="secondary" href="<?=h(path('/admin/products'))?>">Back to products</a></div>
        <form class="panel edit-form" method="post" action="<?=h(path('/admin/product/new'))?>"><?=csrf_field()?><div class="form-grid">
        <label class="wide">Product name<input name="title" maxlength="200" required></label><label>SKU<input name="sku" maxlength="100"></label>
        <label>Status<select name="status"><option value="draft">Draft</option><option value="publish">Published</option><option value="private">Private</option></select></label>
        <label>Regular price (CHF)<input name="price" type="number" min="0" step="0.01" value="0" required></label>
        <label>Stock<input name="stock" type="number" min="0" step="1" value="0" required></label>
        <?php foreach($roles as $role): ?><label><?=h($role['name'])?> price (CHF)<input name="role_prices[<?=h($role['slug'])?>]" type="number" min="0" step="0.01"></label><?php endforeach; ?>
        <label class="wide">Short description<textarea name="excerpt" rows="4"></textarea></label><label class="wide">Description<textarea name="description" rows="10"></textarea></label></div>
        <button class="primary">Create product</button></form><?php
    });
}
function create_product(): never {
    $title=trim((string)($_POST['title'] ?? ''));
    $sku=trim((string)($_POST['sku'] ?? ''));
    $status=(string)($_POST['status'] ?? 'draft');
    $price=filter_var($_POST['price'] ?? '',FILTER_VALIDATE_FLOAT);
    $stock=filter_var($_POST['stock'] ?? '',FILTER_VALIDATE_INT);
    $rolePrices=$_POST['role_prices'] ?? [];
    if ($title==='' || mb_strlen($title)>200 || !in_array($status,['draft','publish','private'],true) || $price===false || $price<0 || $stock===false || $stock<0 || !is_array($rolePrices)) {
        notice('Check the product fields.'); redirect('/admin/product/new');
    }
    if ($sku!=='' && row("SELECT post_id FROM wp_postmeta WHERE meta_key='_sku' AND meta_value=? LIMIT 1",[$sku])) {
        notice('SKU already exists.'); redirect('/admin/product/new');
    }
    $validated=[];
    foreach(wholesale_roles() as $role) {
        $raw=trim((string)($rolePrices[$role['slug']] ?? ''));
        if ($raw==='') continue;
        $value=filter_var($raw,FILTER_VALIDATE_FLOAT);
        if ($value===false || $value<0) { notice('Check the role prices.'); redirect('/admin/product/new'); }
        $validated[$role['slug']]=number_format($value,2,'.','');
    }
    db()->beginTransaction();
    try {
        $slug=slug($title); if ($slug==='') $slug='product';
        exec_sql("INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (?,NOW(),UTC_TIMESTAMP(),?,?,? ,?,'open','closed',?,'','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'product','',0)",[(int)(current_user()['id'] ?? 0),(string)($_POST['description'] ?? ''),$title,(string)($_POST['excerpt'] ?? ''),$status,$slug]);
        $id=(int)db()->lastInsertId();
        exec_sql('UPDATE wp_posts SET post_name=?,guid=? WHERE ID=?',[$slug.'-'.$id,'urn:product:'.$id,$id]);
        foreach(['_sku'=>$sku,'_regular_price'=>number_format($price,2,'.',''),'_price'=>number_format($price,2,'.',''),'_stock'=>(string)$stock,'_stock_status'=>$stock>0?'instock':'outofstock','_manage_stock'=>'yes'] as $key=>$value) set_wp_meta($id,$key,$value);
        foreach($validated as $role=>$value) { set_wp_meta($id,$role.'_wholesale_price',$value); set_wp_meta($id,$role.'_have_wholesale_price','yes'); }
        $type=row("SELECT term_taxonomy_id FROM wp_term_taxonomy tt JOIN wp_terms t ON t.term_id=tt.term_id WHERE tt.taxonomy='product_type' AND t.slug='simple' LIMIT 1");
        if ($type) { exec_sql('INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (?,?,0)',[$id,$type['term_taxonomy_id']]); exec_sql('UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id=?',[$type['term_taxonomy_id']]); }
        audit_change('product',$id,'create',[],['title'=>$title,'sku'=>$sku,'status'=>$status,'price'=>$price,'stock'=>$stock,'role_prices'=>$validated]);
        db()->commit(); woo_webhook_after_change('product.created',$id); notice('Product created.'); redirect('/admin/product?id='.$id);
    } catch(Throwable $error) { db()->rollBack(); error_log((string)$error); notice('Could not create product.'); redirect('/admin/product/new'); }
}
function admin_product_preview(int $id): void {
    $p=product($id); if (!$p) { http_response_code(404); exit('Product not found.'); }
    $roles=wholesale_roles();
    admin_layout('Preview product',static function() use($p,$roles){
        ?><div class="heading"><div><div class="eyebrow">PRODUCT PREVIEW #<?=h($p['ID'])?></div><h1><?=h($p['post_title'])?></h1><p><?=h($p['post_status'])?></p></div><a class="secondary" href="<?=h(path('/admin/product?id='.$p['ID']))?>">Edit product</a></div>
        <div class="two-col"><section class="panel"><img class="preview-product-image" src="<?=h($p['image'])?>" alt=""><h2>Description</h2><p><?=nl2br(h(strip_tags($p['post_excerpt'])))?></p><p><?=nl2br(h(strip_tags($p['post_content'])))?></p></section><section class="panel"><h2>Prices and inventory</h2><dl class="facts"><dt>SKU</dt><dd><?=h($p['_sku'] ?? '')?></dd><dt>Retail</dt><dd><?=currency($p['_price'] ?? 0)?></dd><dt>Stock</dt><dd><?=h($p['_stock'] ?? '')?></dd><?php foreach($roles as $role): $price=$p[$role['slug'].'_wholesale_price'] ?? ''; ?><dt><?=h($role['name'])?></dt><dd><?=is_numeric($price)?currency($price):'—'?></dd><?php endforeach; ?></dl></section></div><?php
    });
}
function bulk_product_actions(): never {
    $action=(string)($_POST['action'] ?? '');
    $ids=$_POST['ids'] ?? [];
    if (!is_array($ids)) $ids=[];
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));
    if (count($ids)>40) { http_response_code(400); exit('Too many products selected.'); }
    $rowAction=(string)($_POST['row_action'] ?? '');
    if (preg_match('/^(duplicate|trash|draft|quick):(\d+)$/',$rowAction,$matches)) { $ids=[(int)$matches[2]]; $action=$matches[1]; }
    if (!$ids || !in_array($action,['duplicate','trash','publish','draft','private','quick'],true)) { notice('Select products and an action.'); redirect('/admin/products'); }
    db()->beginTransaction();
    try {
        foreach($ids as $id) {
            $before=row("SELECT ID,post_title,post_status FROM wp_posts WHERE ID=? AND post_type='product'",[$id]);
            if (!$before) continue;
            if ($action==='quick') {
                $quick=$_POST['quick'][$id] ?? [];
                if (!is_array($quick)) throw new InvalidArgumentException('Invalid quick edit.');
                $title=trim((string)($quick['title'] ?? ''));
                $sku=trim((string)($quick['sku'] ?? ''));
                $status=(string)($quick['status'] ?? '');
                $price=filter_var($quick['price'] ?? '',FILTER_VALIDATE_FLOAT);
                $stock=filter_var($quick['stock'] ?? '',FILTER_VALIDATE_INT);
                if ($title==='' || !in_array($status,['publish','draft','private'],true) || $price===false || $price<0 || $stock===false || $stock<0) throw new InvalidArgumentException('Invalid quick edit fields.');
                exec_sql("UPDATE wp_posts SET post_title=?,post_status=?,post_modified=NOW() WHERE ID=? AND post_type='product'",[$title,$status,$id]);
                foreach(['_sku'=>$sku,'_regular_price'=>number_format($price,2,'.',''),'_price'=>number_format($price,2,'.',''),'_stock'=>(string)$stock,'_stock_status'=>$stock>0?'instock':'outofstock'] as $key=>$value) set_wp_meta($id,$key,$value);
                audit_change('product',$id,'quick_edit',$before,['title'=>$title,'sku'=>$sku,'status'=>$status,'price'=>$price,'stock'=>$stock]);
            } elseif ($action==='duplicate') {
                exec_sql("INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) SELECT ?,NOW(),UTC_TIMESTAMP(),post_content,CONCAT(post_title,' (Copy)'),post_excerpt,'draft',comment_status,ping_status,'',CONCAT(post_name,'-copy'),'','',NOW(),UTC_TIMESTAMP(),post_content_filtered,0,'',menu_order,'product','',0 FROM wp_posts WHERE ID=?",[(int)(current_user()['id'] ?? 0),$id]);
                $newId=(int)db()->lastInsertId();
                exec_sql('UPDATE wp_posts SET post_name=?,guid=? WHERE ID=?',['product-copy-'.$newId,'urn:product:'.$newId,$newId]);
                exec_sql('INSERT INTO wp_postmeta (post_id,meta_key,meta_value) SELECT ?,meta_key,meta_value FROM wp_postmeta WHERE post_id=?',[$newId,$id]);
                exec_sql("DELETE FROM wp_postmeta WHERE post_id=? AND meta_key='_sku'",[$newId]);
                exec_sql('INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) SELECT ?,term_taxonomy_id,term_order FROM wp_term_relationships WHERE object_id=?',[$newId,$id]);
                exec_sql('UPDATE wp_term_taxonomy tt JOIN wp_term_relationships tr ON tr.term_taxonomy_id=tt.term_taxonomy_id SET tt.count=tt.count+1 WHERE tr.object_id=?',[$newId]);
                audit_change('product',$newId,'duplicate',['source_id'=>$id],['title'=>$before['post_title'].' (Copy)']);
            } else {
                $status=$action==='trash'?'trash':$action;
                exec_sql("UPDATE wp_posts SET post_status=?,post_modified=NOW() WHERE ID=? AND post_type='product'",[$status,$id]);
                audit_change('product',$id,'status',['status'=>$before['post_status']],['status'=>$status]);
            }
        }
        db()->commit(); notice('Product action completed.');
    } catch(Throwable $error) { db()->rollBack(); error_log((string)$error); notice('Product action failed.'); }
    redirect('/admin/products');
}
