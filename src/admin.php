<?php
declare(strict_types=1);
require_once __DIR__.'/catalog.php';

function admin_layout(string $title,callable $body): void {
    $notice=take_notice(); $user=current_user();
    $displayName=trim((string)($user['name'] ?? 'Administrator'));
    $initials=''; foreach(preg_split('/\s+/u',$displayName) ?: [] as $part) { if($part!=='') $initials.=mb_strtoupper(mb_substr($part,0,1)); if(mb_strlen($initials)>=2) break; }
    if($initials==='') $initials='A';
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?> · Ferry Admin</title><link rel="icon" type="image/png" href="<?=h(path('/assets/ferry-favicon.png'))?>"><link rel="stylesheet" href="<?=h(path('/assets/admin.css?v=2'))?>"></head><body><div class="shell"><aside class="sidebar"><a class="brand" href="<?=h(path('/admin'))?>" aria-label="Ferry Telecom dashboard"><img src="<?=h(path('/assets/ferrytelecom-logo.webp'))?>" alt="Ferry Telecom" width="180" height="49"></a><?php admin_menu(); ?><div class="sidebar-foot">Signed in as <?=h($displayName)?><form action="<?=h(path('/admin/logout'))?>" method="post"><?=csrf_field()?><button type="submit">Log out</button></form></div></aside><main class="main"><div class="topline"><div>Ferry Telecom / Admin</div><details class="account-menu"><summary aria-label="Account menu for <?=h($displayName)?>"><span class="account-avatar" aria-hidden="true"><?=h($initials)?></span><span class="account-caret" aria-hidden="true">▾</span></summary><div class="account-dropdown"><div class="account-identity"><span class="account-avatar account-avatar-large" aria-hidden="true"><?=h($initials)?></span><div><strong><?=h($displayName)?></strong><small><?=h($user['email'] ?? '')?></small></div></div><a href="<?=h(path('/admin/user?id='.(int)($user['id'] ?? 0)))?>">Edit My Profile</a><a href="<?=h(path('/admin'))?>">Dashboard</a><form action="<?=h(path('/admin/logout'))?>" method="post"><?=csrf_field()?><button type="submit">Log Out</button></form></div></details></div><div class="content"><?php if($notice): ?><div class="notice" role="status"><?=h($notice)?></div><?php endif; ?><?php $body(); ?></div></main></div><script>document.addEventListener('click',function(event){const menu=document.querySelector('.account-menu');if(menu&&menu.open&&!menu.contains(event.target))menu.open=false});document.addEventListener('keydown',function(event){if(event.key==='Escape'){const menu=document.querySelector('.account-menu');if(menu&&menu.open){menu.open=false;menu.querySelector('summary').focus()}}});</script></body></html><?php
}
function admin_login(): void {
    if(is_admin()) redirect('/admin'); $notice=take_notice();
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin login · Ferry Telecom</title><link rel="icon" type="image/png" href="<?=h(path('/assets/ferry-favicon.png'))?>"><link rel="stylesheet" href="<?=h(path('/assets/admin.css?v=2'))?>"></head><body class="login-page"><main class="login-card"><div class="brand"><span>◈</span> ferrytelecom</div><p>Administration</p><?php if($notice): ?><div class="notice error" role="alert"><?=h($notice)?></div><?php endif; ?><form method="post" action="<?=h(path('/admin/login'))?>"><?=csrf_field()?><label>Username or email address<input name="identity" autocomplete="username" required></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><button class="primary" type="submit">Log in</button></form></main></body></html><?php
}
function admin_dashboard(): void {
    $counts=rows("SELECT post_type,post_status,COUNT(*) n FROM wp_posts WHERE post_type IN ('product','shop_order','page') GROUP BY post_type,post_status");
    $map=[]; foreach($counts as $c) $map[$c['post_type']][$c['post_status']]=(int)$c['n'];
    $users=(int)(row('SELECT COUNT(*) n FROM wp_users')['n'] ?? 0);
    $latest=rows("SELECT p.ID,p.post_date,p.post_status,(SELECT pm.meta_value FROM wp_postmeta pm WHERE pm.post_id=p.ID AND pm.meta_key='_order_total' ORDER BY pm.meta_id DESC LIMIT 1) total FROM wp_posts p WHERE p.post_type='shop_order' ORDER BY p.post_date DESC,p.ID DESC LIMIT 10");
    admin_layout('Dashboard',static function() use($map,$users,$latest){ ?><div class="heading"><div><div class="eyebrow">STORE ADMIN</div><h1>Dashboard</h1></div></div><div class="stats"><a href="<?=h(path('/admin/products'))?>"><strong><?=number_format($map['product']['publish'] ?? 0,0,',',' ')?></strong><span>Published products</span></a><a href="<?=h(path('/admin/orders'))?>"><strong><?=number_format(array_sum($map['shop_order'] ?? []),0,',',' ')?></strong><span>Historical orders</span></a><a href="<?=h(path('/admin/users'))?>"><strong><?=number_format($users,0,',',' ')?></strong><span>Users</span></a><a href="<?=h(path('/admin/pages'))?>"><strong><?=number_format($map['page']['publish'] ?? 0,0,',',' ')?></strong><span>Pages</span></a></div><section class="panel"><h2>Recent orders</h2><div class="table-wrap"><table><thead><tr><th>Number</th><th>Date</th><th>Status</th><th>Total</th></tr></thead><tbody><?php foreach($latest as $o): ?><tr><td><a href="<?=h(path('/admin/order?id='.$o['ID']))?>">#<?=h($o['ID'])?></a></td><td><?=h($o['post_date'])?></td><td><span class="badge"><?=h($o['post_status'])?></span></td><td><?=currency($o['total'] ?? 0)?></td></tr><?php endforeach; ?></tbody></table></div></section><?php });
}
function admin_products(): void {
    $q=trim((string)($_GET['q'] ?? ''));
    $status=(string)($_GET['status'] ?? '');
    $stock=(string)($_GET['stock'] ?? '');
    $category=max(0,(int)($_GET['category'] ?? 0));
    $quality=max(0,(int)($_GET['quality'] ?? 0));
    $brand=max(0,(int)($_GET['brand'] ?? 0));
    $type=max(0,(int)($_GET['type'] ?? 0));
    if (!in_array($status,['','publish','draft','private','trash'],true)) $status='';
    if (!in_array($stock,['','instock','outofstock','onbackorder'],true)) $stock='';
    $page=max(1,(int)($_GET['page'] ?? 1)); $limit=40; $offset=($page-1)*$limit;
    $where="p.post_type='product'"; $params=[];
    if ($status==='') $where.=" AND p.post_status<>'trash'";
    if ($status!=='') { $where.=' AND p.post_status=?'; $params[]=$status; }
    if ($q!=='') {
        $where.=" AND (p.post_title LIKE ? OR EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_sku' AND m.meta_value LIKE ?))";
        $params[]='%'.$q.'%'; $params[]='%'.$q.'%';
    }
    if ($stock!=='') {
        $where.=" AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_stock_status' AND m.meta_value=?)";
        $params[]=$stock;
    }
    if ($category) {
        $where.=" AND EXISTS (SELECT 1 FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy='product_cat' AND tt.term_id=?)";
        $params[]=$category;
    }
    foreach (['pa_quality'=>$quality,'product_type'=>$type] as $taxonomy=>$termId) if ($termId) {
        $where.=" AND EXISTS (SELECT 1 FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy=? AND tt.term_id=?)";
        $params[]=$taxonomy; $params[]=$termId;
    }
    if ($brand) {
        $where.=" AND EXISTS (SELECT 1 FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy IN ('product_brand','pa_branding') AND tt.term_id=?)";
        $params[]=$brand;
    }
    $count=(int)(row("SELECT COUNT(*) n FROM wp_posts p WHERE $where",$params)['n'] ?? 0);
    $products=rows("SELECT p.ID,p.post_title,p.post_status,p.post_date,
        (SELECT m.meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_sku' ORDER BY m.meta_id DESC LIMIT 1) sku,
        (SELECT m.meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_price' ORDER BY m.meta_id DESC LIMIT 1) price,
        (SELECT m.meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_stock' ORDER BY m.meta_id DESC LIMIT 1) stock,
        (SELECT m.meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_stock_status' ORDER BY m.meta_id DESC LIMIT 1) stock_status,
        (SELECT m.meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='wholesale_customer_wholesale_price' ORDER BY m.meta_id DESC LIMIT 1) wholesale_price,
        (SELECT m.meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='wholesale_customer_wholesale_sale_price' ORDER BY m.meta_id DESC LIMIT 1) wholesale_sale,
        (SELECT m.meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_global_unique_id' ORDER BY m.meta_id DESC LIMIT 1) gtin,
        (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN wp_terms t ON t.term_id=tt.term_id WHERE tr.object_id=p.ID AND tt.taxonomy='product_cat') categories,
        (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN wp_terms t ON t.term_id=tt.term_id WHERE tr.object_id=p.ID AND tt.taxonomy='product_tag') tags,
        (SELECT m.meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_thumbnail_id' ORDER BY m.meta_id DESC LIMIT 1) thumbnail_id
        FROM wp_posts p WHERE $where ORDER BY p.ID DESC LIMIT $limit OFFSET $offset",$params);
    $roles=wholesale_roles();
    $roleByKey=[]; foreach($roles as $item) $roleByKey[$item['slug'].'_wholesale_price']=$item['name'];
    $priceByProduct=[];
    if ($products && $roleByKey) {
        $ids=array_column($products,'ID');
        $holders=implode(',',array_fill(0,count($ids),'?'));
        $keys=implode(',',array_fill(0,count($roleByKey),'?'));
        foreach(rows("SELECT post_id,meta_key,meta_value FROM wp_postmeta WHERE post_id IN ($holders) AND meta_key IN ($keys)",array_merge($ids,array_keys($roleByKey))) as $entry) {
            if (is_numeric($entry['meta_value'])) $priceByProduct[$entry['post_id']][]=[$roleByKey[$entry['meta_key']],$entry['meta_value']];
        }
    }
    $categories=rows("SELECT t.term_id,t.name FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' ORDER BY t.name");
    $qualities=rows("SELECT t.term_id,t.name FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy='pa_quality' ORDER BY t.name");
    $brands=rows("SELECT DISTINCT t.term_id,t.name FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy IN ('product_brand','pa_branding') ORDER BY t.name");
    $types=rows("SELECT t.term_id,t.name FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_type' ORDER BY t.name");
    $counts=rows("SELECT post_status,COUNT(*) n FROM wp_posts WHERE post_type='product' GROUP BY post_status");
    $statusCounts=[]; foreach($counts as $entry) $statusCounts[$entry['post_status']]=(int)$entry['n'];
    $query=['q'=>$q,'status'=>$status,'stock'=>$stock,'category'=>$category,'quality'=>$quality,'brand'=>$brand,'type'=>$type];
    admin_layout('Products',static function() use($q,$status,$stock,$category,$quality,$brand,$type,$count,$products,$categories,$qualities,$brands,$types,$statusCounts,$page,$limit,$query,$priceByProduct){
        ?><div class="heading"><div><div class="eyebrow">CATALOG</div><h1>Products</h1><p><?=number_format($count)?> products</p></div><a class="secondary" href="<?=h(path('/admin/product/new'))?>">Add new product</a></div>
        <div class="filters"><a class="<?=$status===''?'active':''?>" href="<?=h(path('/admin/products'))?>">All <span><?=array_sum($statusCounts)-($statusCounts['trash'] ?? 0)?></span></a><?php foreach(['publish'=>'Published','draft'=>'Drafts','private'=>'Private','trash'=>'Trash'] as $value=>$label): ?><a class="<?=$status===$value?'active':''?>" href="<?=h(path('/admin/products?'.http_build_query(['status'=>$value])))?>"><?=$label?> <span><?=$statusCounts[$value] ?? 0?></span></a><?php endforeach; ?></div>
        <form class="product-filters" action="<?=h(path('/admin/products'))?>" method="get"><input type="hidden" name="status" value="<?=h($status)?>"><label>Search products<input name="q" value="<?=h($q)?>" placeholder="Product name or SKU"></label><label>Quality<select name="quality"><option value="0">Filter by Quality</option><?php foreach($qualities as $item): ?><option value="<?=h($item['term_id'])?>" <?=$quality==(int)$item['term_id']?'selected':''?>><?=h($item['name'])?></option><?php endforeach; ?></select></label><label>Category<select name="category"><option value="0">All categories</option><?php foreach($categories as $item): ?><option value="<?=h($item['term_id'])?>" <?=$category==(int)$item['term_id']?'selected':''?>><?=h($item['name'])?></option><?php endforeach; ?></select></label><label>Product type<select name="type"><option value="0">Filter by product type</option><?php foreach($types as $item): ?><option value="<?=h($item['term_id'])?>" <?=$type==(int)$item['term_id']?'selected':''?>><?=h($item['name'])?></option><?php endforeach; ?></select></label><label>Stock<select name="stock"><option value="">Filter by stock status</option><option value="instock" <?=$stock==='instock'?'selected':''?>>In stock</option><option value="outofstock" <?=$stock==='outofstock'?'selected':''?>>Out of stock</option><option value="onbackorder" <?=$stock==='onbackorder'?'selected':''?>>On backorder</option></select></label><label>Brand<select name="brand"><option value="0">Filter by brand</option><?php foreach($brands as $item): ?><option value="<?=h($item['term_id'])?>" <?=$brand==(int)$item['term_id']?'selected':''?>><?=h($item['name'])?></option><?php endforeach; ?></select></label><button class="secondary">Filter</button></form>
        <form method="post" action="<?=h(path('/admin/products/bulk'))?>"><?=csrf_field()?><div class="bulk-actions"><select name="action" aria-label="Select bulk action"><option value="">Bulk actions</option><option value="draft">Bulk edit: Draft</option><option value="publish">Bulk edit: Published</option><option value="private">Bulk edit: Private</option><option value="trash">Move to Trash</option></select><button class="secondary">Apply</button></div>
        <div class="panel table-wrap"><table><thead><tr><th><input type="checkbox" aria-label="Select all products" onclick="document.querySelectorAll('.product-select').forEach(box=>box.checked=this.checked)"></th><th>Image</th><th>Name</th><th>SKU</th><th>GTIN, UPC, EAN, or ISBN</th><th>Stock</th><th>Price</th><th>Wholesale Sale price</th><th>Wholesale Price</th><th>Categories</th><th>Tags</th><th>Date</th></tr></thead><tbody><?php foreach($products as $p): ?><tr><td><input class="product-select" type="checkbox" name="ids[]" value="<?=h($p['ID'])?>" aria-label="Select <?=h($p['post_title'])?>"></td><td><img class="product-thumb" src="<?=h((int)$p['thumbnail_id']>0?path('/media/product?id='.$p['ID']):path('/assets/no-image.svg'))?>" alt="" loading="lazy"></td><td><a href="<?=h(path('/admin/product?id='.$p['ID']))?>"><?=h($p['post_title'])?></a><small>#<?=h($p['ID'])?> · <?=h($p['post_status'])?></small><div class="row-actions"><a href="<?=h(path('/admin/product?id='.$p['ID']))?>">Edit</a><button type="button" onclick="document.getElementById('quick-<?=h($p['ID'])?>').hidden=false">Quick Edit</button><?php if($p['post_status']==='trash'): ?><button type="submit" name="row_action" value="draft:<?=h($p['ID'])?>">Restore to Draft</button><?php else: ?><button type="submit" name="row_action" value="trash:<?=h($p['ID'])?>" onclick="return confirm('Move this product to Trash?')">Trash</button><?php endif; ?><a href="<?=h(path('/admin/product/preview?id='.$p['ID']))?>">Preview</a><button type="submit" name="row_action" value="duplicate:<?=h($p['ID'])?>">Duplicate</button></div></td><td><?=h($p['sku'])?></td><td><?=h($p['gtin'] ?: '—')?></td><td><?=h($p['stock_status'] ?: '—')?><?=is_numeric($p['stock'])?' ('.h($p['stock']).')':''?></td><td><?=currency($p['price'] ?? 0)?></td><td><?=is_numeric($p['wholesale_sale'])?currency($p['wholesale_sale']):'—'?></td><td><?php foreach($priceByProduct[$p['ID']] ?? [] as [$label,$value]): ?><small><?=h($label)?>: <?=currency($value)?></small><?php endforeach; ?></td><td><?=h($p['categories'] ?: '—')?></td><td><?=h($p['tags'] ?: '—')?></td><td><?=h($p['post_date'])?></td></tr><tr class="quick-edit-row" id="quick-<?=h($p['ID'])?>" hidden><td colspan="12"><div class="quick-grid"><label>Product name<input name="quick[<?=h($p['ID'])?>][title]" value="<?=h($p['post_title'])?>"></label><label>SKU<input name="quick[<?=h($p['ID'])?>][sku]" value="<?=h($p['sku'])?>"></label><label>Status<select name="quick[<?=h($p['ID'])?>][status]"><option value="publish" <?=$p['post_status']==='publish'?'selected':''?>>Published</option><option value="draft" <?=$p['post_status']==='draft'?'selected':''?>>Draft</option><option value="private" <?=$p['post_status']==='private'?'selected':''?>>Private</option></select></label><label>Price<input name="quick[<?=h($p['ID'])?>][price]" type="number" min="0" step="0.01" value="<?=h($p['price'] ?? 0)?>"></label><label>Stock<input name="quick[<?=h($p['ID'])?>][stock]" type="number" min="0" step="1" value="<?=h($p['stock'] ?? 0)?>"></label><button class="primary" type="submit" name="row_action" value="quick:<?=h($p['ID'])?>">Update</button><button class="secondary" type="button" onclick="this.closest('tr').hidden=true">Cancel</button></div></td></tr><?php endforeach; ?></tbody></table></div></form><?=admin_pages_nav('/admin/products',$page,$count,$limit,$query)?><?php
    });
}
function admin_product(int $id): void {
    $p=product($id); if(!$p) { http_response_code(404); exit('Product not found.'); }
    $roles=wholesale_roles();
    admin_layout('Edit product',static function() use($p,$roles){ ?><div class="heading"><div><div class="eyebrow">PRODUCT #<?=h($p['ID'])?></div><h1><?=h($p['post_title'])?></h1></div><a class="secondary" href="<?=h(path('/admin/products'))?>">Back to products</a></div><form class="panel edit-form" method="post" action="<?=h(path('/admin/product'))?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($p['ID'])?>"><div class="form-grid"><label class="wide">Product name<input name="title" value="<?=h($p['post_title'])?>" required></label><label>SKU<input name="sku" value="<?=h($p['_sku'] ?? '')?>"></label><label>Status<select name="status"><?php foreach(['publish'=>'Published','draft'=>'Draft','private'=>'Private'] as $value=>$label): ?><option value="<?=$value?>" <?=($p['post_status']===$value)?'selected':''?>><?=$label?></option><?php endforeach; ?></select></label><label>Regular price (CHF)<input name="price" type="number" min="0" step="0.01" value="<?=h($p['_regular_price'] ?? $p['_price'] ?? '0')?>" required></label><label>Stock<input name="stock" type="number" min="0" step="1" value="<?=h($p['_stock'] ?? '0')?>" required></label><div class="wide"><h2>Wholesale Prices</h2><div class="table-wrap"><table class="role-price-table"><thead><tr><th>Role</th><th>Wholesale price (CHF)</th><th>Sale price (CHF)</th><th>Minimum quantity</th><th>Quantity step</th></tr></thead><tbody><?php foreach($roles as $role): $slug=$role['slug']; ?><tr><th scope="row"><?=h($role['name'])?></th><td><input aria-label="<?=h($role['name'])?> wholesale price" name="role_prices[<?=h($slug)?>]" type="number" min="0" step="0.01" value="<?=h($p[$slug.'_wholesale_price'] ?? '')?>"></td><td><input aria-label="<?=h($role['name'])?> sale price" name="role_sale_prices[<?=h($slug)?>]" type="number" min="0" step="0.01" value="<?=h($p[$slug.'_wholesale_sale_price'] ?? '')?>"></td><td><input aria-label="<?=h($role['name'])?> minimum quantity" name="role_minimums[<?=h($slug)?>]" type="number" min="1" step="1" value="<?=h($p[$slug.'_wholesale_minimum_order_quantity'] ?? '')?>"></td><td><input aria-label="<?=h($role['name'])?> quantity step" name="role_steps[<?=h($slug)?>]" type="number" min="1" step="1" value="<?=h($p[$slug.'_wholesale_order_quantity_step'] ?? '')?>"></td></tr><?php endforeach; ?></tbody></table></div></div><label class="wide">Short description<textarea name="excerpt" rows="4"><?=h($p['post_excerpt'])?></textarea></label><label class="wide">Description<textarea name="description" rows="10"><?=h($p['post_content'])?></textarea></label></div><button class="primary" type="submit">Save product</button></form><?php });
}
function save_product(): never {
    $id=(int)($_POST['id'] ?? 0); $p=product($id); if(!$p) { http_response_code(404); exit('Product not found.'); }
    $title=trim((string)($_POST['title'] ?? ''));
    $status=(string)($_POST['status'] ?? '');
    $price=filter_var($_POST['price'] ?? '',FILTER_VALIDATE_FLOAT);
    $stock=filter_var($_POST['stock'] ?? '',FILTER_VALIDATE_INT);
    if($title==='' || !in_array($status,['publish','draft','private'],true) || $price===false || $price<0 || $stock===false || $stock<0) { notice('Check the required fields.'); redirect('/admin/product?id='.$id); }
    db()->beginTransaction();
    try {
        exec_sql("UPDATE wp_posts SET post_title=?,post_excerpt=?,post_content=?,post_status=?,post_modified=NOW() WHERE ID=? AND post_type='product'",[$title,(string)($_POST['excerpt'] ?? ''),(string)($_POST['description'] ?? ''),$status,$id]);
        foreach(['_sku'=>(string)($_POST['sku'] ?? ''),'_regular_price'=>(string)$price,'_price'=>(string)$price,'_stock'=>(string)$stock,'_stock_status'=>$stock>0?'instock':'outofstock'] as $key=>$value) set_wp_meta($id,$key,$value);
        foreach (['role_prices'=>'_wholesale_price','role_sale_prices'=>'_wholesale_sale_price','role_minimums'=>'_wholesale_minimum_order_quantity','role_steps'=>'_wholesale_order_quantity_step'] as $field=>$suffix) {
            $values=$_POST[$field] ?? [];
            if (!is_array($values)) throw new InvalidArgumentException('Invalid role pricing.');
            foreach (wholesale_roles() as $role) {
                $slug=$role['slug']; $raw=trim((string)($values[$slug] ?? ''));
                $key=$slug.$suffix;
                if ($raw==='') {
                    exec_sql('DELETE FROM wp_postmeta WHERE post_id=? AND meta_key=?',[$id,$key]);
                    if ($suffix==='_wholesale_price') set_wp_meta($id,$slug.'_have_wholesale_price','no');
                    continue;
                }
                $integer=in_array($field,['role_minimums','role_steps'],true);
                $value=filter_var($raw,$integer?FILTER_VALIDATE_INT:FILTER_VALIDATE_FLOAT);
                if ($value===false || $value<($integer?1:0)) throw new InvalidArgumentException('Invalid role pricing value.');
                set_wp_meta($id,$key,$integer?(string)$value:number_format($value,2,'.',''));
                if ($suffix==='_wholesale_price') set_wp_meta($id,$slug.'_have_wholesale_price','yes');
            }
        }
        audit_change('product',$id,'update',['title'=>$p['post_title'],'status'=>$p['post_status'],'sku'=>$p['_sku'] ?? '', 'price'=>$p['_price'] ?? '', 'stock'=>$p['_stock'] ?? ''],['title'=>$title,'status'=>$status,'sku'=>(string)($_POST['sku'] ?? ''),'price'=>(string)$price,'stock'=>(string)$stock]);
        db()->commit(); notice('Product saved.');
    } catch(Throwable $e) { db()->rollBack(); notice('Could not save product.'); }
    redirect('/admin/product?id='.$id);
}
function admin_pages_nav(string $route,int $page,int $count,int $limit,array $query=[]): string {
    $pages=(int)ceil($count/$limit); if($pages<=1) return '';
    $html='<nav class="pagination" aria-label="Pages">';
    foreach(range(max(1,$page-2),min($pages,$page+2)) as $n) $html.='<a class="'.($n===$page?'current':'').'" href="'.h(path($route.'?'.http_build_query($query+['page'=>$n]))).'">'.$n.'</a>';
    return $html.'</nav>';
}
function admin_orders(): void {
    $status=(string)($_GET['status'] ?? '');
    $q=trim((string)($_GET['q'] ?? ''));
    $month=(string)($_GET['month'] ?? '');
    $role=(string)($_GET['role'] ?? '');
    $origin=(string)($_GET['origin'] ?? '');
    if ($status!=='' && !preg_match('/^wc-[a-z-]+$/',$status)) $status='';
    if ($month!=='' && !preg_match('/^\d{6}$/',$month)) $month='';
    if ($role!=='' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/',$role)) $role='';
    if (!in_array($origin,['','admin','checkout','store-api'],true)) $origin='';
    $page=max(1,(int)($_GET['page'] ?? 1)); $limit=40; $offset=($page-1)*$limit;
    $where="p.post_type='shop_order'"; $params=[];
    if ($status!=='') { $where.=' AND p.post_status=?'; $params[]=$status; }
    if ($month!=='') { $where.=" AND DATE_FORMAT(p.post_date,'%Y%m')=?"; $params[]=$month; }
    if ($role!=='') { $where.=" AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_wwpp_wholesale_order_type' AND m.meta_value=?)"; $params[]=$role; }
    if ($origin!=='') { $where.=" AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_created_via' AND m.meta_value=?)"; $params[]=$origin; }
    if ($q!=='') {
        $where.=" AND (p.ID=? OR EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key IN ('_billing_email','_billing_first_name','_billing_last_name','_billing_company') AND m.meta_value LIKE ?))";
        $params[]=(int)ltrim($q,'#'); $params[]='%'.$q.'%';
    }
    $count=(int)(row("SELECT COUNT(*) n FROM wp_posts p WHERE $where",$params)['n'] ?? 0);
    $meta=static fn(string $key): string=>"(SELECT m.meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='".$key."' ORDER BY m.meta_id DESC LIMIT 1)";
    $fields=['_order_total'=>'total','_order_currency'=>'currency','_billing_first_name'=>'first_name','_billing_last_name'=>'last_name','_billing_company'=>'company','_billing_email'=>'email','_billing_address_1'=>'billing_address','_billing_city'=>'billing_city','_shipping_first_name'=>'shipping_first','_shipping_last_name'=>'shipping_last','_shipping_company'=>'shipping_company','_shipping_address_1'=>'shipping_address','_shipping_city'=>'shipping_city','_wcpdf_invoice_number'=>'invoice_number','_wwpp_wholesale_order_type'=>'order_type','_created_via'=>'origin','_wc_shipment_tracking_items'=>'tracking'];
    $columns=[]; foreach($fields as $key=>$alias) $columns[]=$meta($key).' '.$alias;
    $orders=rows("SELECT p.ID,p.post_date,p.post_status,".implode(',',$columns)." FROM wp_posts p WHERE $where ORDER BY p.post_date DESC,p.ID DESC LIMIT $limit OFFSET $offset",$params);
    $statuses=rows("SELECT post_status,COUNT(*) n FROM wp_posts WHERE post_type='shop_order' GROUP BY post_status ORDER BY n DESC");
    $months=rows("SELECT DATE_FORMAT(post_date,'%Y%m') value,DATE_FORMAT(post_date,'%M %Y') label FROM wp_posts WHERE post_type='shop_order' GROUP BY value,label ORDER BY value DESC LIMIT 36");
    $roles=wholesale_roles(); $roleNames=array_column($roles,'name','slug');
    admin_layout('Orders',static function() use($status,$q,$month,$role,$origin,$page,$count,$limit,$orders,$statuses,$months,$roles,$roleNames){
        ?><div class="heading"><div><div class="eyebrow">COMMERCE</div><h1>Orders</h1><p><?=number_format($count)?> orders</p></div></div>
        <div class="filters"><a class="<?=$status===''?'active':''?>" href="<?=h(path('/admin/orders'))?>">All</a><?php foreach($statuses as $entry): ?><a class="<?=$status===$entry['post_status']?'active':''?>" href="<?=h(path('/admin/orders?status='.rawurlencode($entry['post_status'])))?>"><?=h(ucwords(str_replace(['wc-','-'],['',' '],$entry['post_status'])))?> <span><?=h($entry['n'])?></span></a><?php endforeach; ?></div>
        <form class="product-filters" action="<?=h(path('/admin/orders'))?>" method="get"><input type="hidden" name="status" value="<?=h($status)?>"><label>Search orders<input name="q" value="<?=h($q)?>" placeholder="Order number or customer"></label><label>Date<select name="month"><option value="">All dates</option><?php foreach($months as $item): ?><option value="<?=h($item['value'])?>" <?=$month===$item['value']?'selected':''?>><?=h($item['label'])?></option><?php endforeach; ?></select></label><label>Order type<select name="role"><option value="">Show all order types</option><?php foreach($roles as $item): ?><option value="<?=h($item['slug'])?>" <?=$role===$item['slug']?'selected':''?>><?=h($item['name'])?></option><?php endforeach; ?></select></label><label>Sales channel<select name="origin"><option value="">All sales channels</option><option value="admin" <?=$origin==='admin'?'selected':''?>>Admin</option><option value="checkout" <?=$origin==='checkout'?'selected':''?>>Checkout</option><option value="store-api" <?=$origin==='store-api'?'selected':''?>>Store API</option></select></label><button class="secondary">Filter</button></form>
        <div class="panel table-wrap"><table><thead><tr><th>Order</th><th>Invoice Number</th><th>Date</th><th>Status</th><th>Billing</th><th>Ship to</th><th>Total</th><th>Actions</th><th>Shipment Tracking</th><th>Order Type</th><th>Origin</th></tr></thead><tbody><?php foreach($orders as $o): $billing=trim(($o['company']? $o['company'].', ':'').($o['first_name'] ?? '').' '.($o['last_name'] ?? '')); $shipping=trim(($o['shipping_company']?$o['shipping_company'].', ':'').($o['shipping_first'] ?? '').' '.($o['shipping_last'] ?? '')); ?><tr><td><a href="<?=h(path('/admin/order?id='.$o['ID']))?>">#<?=h($o['ID'])?> <?=h($o['first_name'].' '.$o['last_name'])?></a></td><td><?=h($o['invoice_number'] ?: '—')?></td><td><?=h($o['post_date'])?></td><td><span class="badge"><?=h(ucwords(str_replace(['wc-','-'],['',' '],$o['post_status'])))?></span></td><td><?=h($billing)?><small><?=h(trim(($o['billing_address'] ?? '').', '.($o['billing_city'] ?? ''),', '))?></small><small><?=h($o['email'])?></small></td><td><?=h($shipping)?><small><?=h(trim(($o['shipping_address'] ?? '').', '.($o['shipping_city'] ?? ''),', '))?></small></td><td><?=currency($o['total'] ?? 0)?></td><td><a class="secondary" href="<?=h(path('/admin/order?id='.$o['ID']))?>">View</a></td><td><a href="<?=h(path('/admin/shipment?order='.$o['ID']))?>"><?=$o['tracking']?'Edit tracking':'Add tracking'?></a></td><td><?=h($roleNames[$o['order_type']] ?? ($o['order_type'] ?: 'Retail'))?></td><td><?=h($o['origin'] ?: '—')?></td></tr><?php endforeach; ?></tbody></table></div><?=admin_pages_nav('/admin/orders',$page,$count,$limit,['q'=>$q,'status'=>$status,'month'=>$month,'role'=>$role,'origin'=>$origin])?><?php
    });
}
function admin_order(int $id): void {
    $o=order_summary($id); if(!$o) { http_response_code(404); exit('Order not found.'); }
    admin_layout('Order #'.$id,static function() use($o){ ?><div class="heading"><div><div class="eyebrow">ORDER #<?=h($o['ID'])?></div><h1>View order</h1><p><?=h($o['post_date'])?></p></div><a class="secondary" href="<?=h(path('/admin/orders'))?>">Back to orders</a></div><div class="two-col"><section class="panel"><h2>Items</h2><div class="table-wrap"><table><thead><tr><th>Item</th><th>Type</th><th>Quantity</th><th>Total</th></tr></thead><tbody><?php foreach($o['items'] as $item): ?><tr><td><?=h($item['order_item_name'])?></td><td><?=h($item['order_item_type'])?></td><td><?=h($item['qty'] ?? '—')?></td><td><?=currency($item['total'] ?? 0)?></td></tr><?php endforeach; ?></tbody></table></div><div class="total">Total <strong><?=currency($o['_order_total'] ?? 0)?></strong></div></section><section class="panel"><h2>Details</h2><dl class="facts"><dt>Customer</dt><dd><?=h(trim(($o['_billing_first_name'] ?? '').' '.($o['_billing_last_name'] ?? '')))?></dd><dt>Email</dt><dd><?=h($o['_billing_email'] ?? '')?></dd><dt>Phone</dt><dd><?=h($o['_billing_phone'] ?? '')?></dd><dt>Address</dt><dd><?=h($o['_billing_address_1'] ?? '')?><br><?=h(($o['_billing_postcode'] ?? '').' '.($o['_billing_city'] ?? ''))?><br><?=h($o['_billing_country'] ?? '')?></dd><dt>Payment method</dt><dd><?=h($o['_payment_method_title'] ?? '')?></dd></dl><form method="post" action="<?=h(path('/admin/order'))?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($o['ID'])?>"><label>Status<select name="status"><?php foreach(['wc-pending','wc-awaiting-payment','wc-processing','wc-paid','wc-completed','wc-on-hold','wc-backordered','wc-cancelled','wc-refunded','wc-failed'] as $s): ?><option value="<?=$s?>" <?=$o['post_status']===$s?'selected':''?>><?=$s?></option><?php endforeach; ?></select></label><button class="primary">Save status</button></form></section></div><?php });
}
function save_order(): never {
    $id=(int)($_POST['id'] ?? 0); $status=(string)($_POST['status'] ?? '');
    $valid=['wc-pending','wc-awaiting-payment','wc-processing','wc-paid','wc-completed','wc-on-hold','wc-backordered','wc-cancelled','wc-refunded','wc-failed'];
    $order=order_summary($id);
    if(!in_array($status,$valid,true) || !$order) { http_response_code(400); exit('Invalid order.'); }
    db()->beginTransaction();
    exec_sql("UPDATE wp_posts SET post_status=?,post_modified=NOW() WHERE ID=? AND post_type='shop_order'",[$status,$id]);
    audit_change('order',$id,'status',['status'=>$order['post_status']],['status'=>$status]);
    db()->commit();
    notice('Order status saved.'); redirect('/admin/order?id='.$id);
}
function admin_users(): void {
    $q=trim((string)($_GET['q'] ?? ''));
    $role=(string)($_GET['role'] ?? '');
    if ($role!=='' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/',$role)) $role='';
    $page=max(1,(int)($_GET['page'] ?? 1)); $limit=40; $offset=($page-1)*$limit;
    $where='1=1'; $params=[];
    if ($q!=='') { $where.=' AND (u.user_login LIKE ? OR u.user_email LIKE ? OR u.display_name LIKE ?)'; $params=array_fill(0,3,'%'.$q.'%'); }
    if ($role!=='') { $where.=" AND EXISTS (SELECT 1 FROM wp_usermeta r WHERE r.user_id=u.ID AND r.meta_key='wp_capabilities' AND r.meta_value LIKE ?)"; $params[]='%"'.$role.'";b:1%'; }
    $count=(int)(row("SELECT COUNT(*) n FROM wp_users u WHERE $where",$params)['n'] ?? 0);
    $users=rows("SELECT u.ID,u.user_login,u.user_email,u.display_name,u.user_registered,
        (SELECT m.meta_value FROM wp_usermeta m WHERE m.user_id=u.ID AND m.meta_key='wp_capabilities' LIMIT 1) capabilities,
        (SELECT m.meta_value FROM wp_usermeta m WHERE m.user_id=u.ID AND m.meta_key='wwlc_approval_date' LIMIT 1) approval_date,
        (SELECT m.meta_value FROM wp_usermeta m WHERE m.user_id=u.ID AND m.meta_key='wwlc_rejection_date' LIMIT 1) rejection_date
        FROM wp_users u WHERE $where ORDER BY u.ID DESC LIMIT $limit OFFSET $offset",$params);
    $roleCounts=[];
    $allRoles=rows("SELECT meta_value,COUNT(*) n FROM wp_usermeta WHERE meta_key='wp_capabilities' GROUP BY meta_value");
    foreach ($allRoles as $entry) {
        $decoded=@unserialize($entry['meta_value'],['allowed_classes'=>false]);
        if (is_array($decoded)) foreach (array_keys(array_filter($decoded)) as $slug) $roleCounts[$slug]=($roleCounts[$slug] ?? 0)+(int)$entry['n'];
    }
    $roleNames=['administrator'=>'Administrator','shop_manager'=>'Shop manager','wwlc_unapproved'=>'Unapproved','wwlc_rejected'=>'Rejected','customer'=>'Customer'];
    foreach(wholesale_roles() as $entry) $roleNames[$entry['slug']]=$entry['name'];
    admin_layout('Users',static function() use($q,$role,$count,$users,$page,$limit,$roleCounts,$roleNames){
        ?><div class="heading"><div><div class="eyebrow">ACCOUNTS</div><h1>Users</h1><p><?=number_format($count)?> users</p></div></div>
        <div class="filters"><a class="<?=$role===''?'active':''?>" href="<?=h(path('/admin/users'))?>">All</a><?php foreach($roleCounts as $slug=>$n): if(!isset($roleNames[$slug])) continue; ?><a class="<?=$role===$slug?'active':''?>" href="<?=h(path('/admin/users?role='.rawurlencode($slug)))?>"><?=h($roleNames[$slug])?> <span><?=h($n)?></span></a><?php endforeach; ?></div>
        <form class="product-filters" action="<?=h(path('/admin/users'))?>"><input type="hidden" name="role" value="<?=h($role)?>"><label>Search users<input name="q" value="<?=h($q)?>" placeholder="Username, name or email"></label><button class="secondary">Search Users</button></form>
        <div class="panel table-wrap"><table><thead><tr><th>Username</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Registration Date</th><th>Approval Date</th><th>Rejection Date</th><th>Posts</th></tr></thead><tbody><?php foreach($users as $user): $decoded=@unserialize($user['capabilities'] ?? '',['allowed_classes'=>false]); $assigned=is_array($decoded)?array_keys(array_filter($decoded)):[]; $names=array_map(static fn($slug)=>$roleNames[$slug] ?? $slug,array_values(array_filter($assigned,static fn($slug)=>$slug!=='wwlc_inactive'))); $status=in_array('wwlc_unapproved',$assigned,true)?'Unapproved':(in_array('wwlc_rejected',$assigned,true)?'Rejected':(in_array('wwlc_inactive',$assigned,true)?'Inactive':'Approved')); ?><tr><td><a href="<?=h(path('/admin/user?id='.$user['ID']))?>"><?=h($user['user_login'])?></a></td><td><?=h($user['display_name'])?></td><td><?=h($user['user_email'])?></td><td><?=h(implode(', ',$names))?></td><td><span class="badge"><?=h($status)?></span></td><td><?=h($user['user_registered'])?></td><td><?=h($user['approval_date'])?></td><td><?=h($user['rejection_date'])?></td><td>—</td></tr><?php endforeach; ?></tbody></table></div><?=admin_pages_nav('/admin/users',$page,$count,$limit,['q'=>$q,'role'=>$role])?><?php
    });
}
function admin_user(int $id): void {
    $u=row('SELECT ID,user_login,user_email,display_name,user_registered FROM wp_users WHERE ID=?',[$id]); if(!$u) { http_response_code(404); exit('User not found.'); }
    $roles=wp_roles($id);
    $wholesaleRoles=wholesale_roles();
    $canChangeRole=!array_intersect($roles,['administrator','shop_manager']);
    $meta=rows("SELECT meta_key,meta_value FROM wp_usermeta WHERE user_id=? AND (meta_key LIKE 'billing_%' OR meta_key LIKE 'shipping_%') ORDER BY meta_key",[$id]);
    $orders=rows("SELECT p.ID,p.post_date,p.post_status,MAX(m.meta_value) total FROM wp_posts p JOIN wp_postmeta c ON c.post_id=p.ID AND c.meta_key='_customer_user' AND c.meta_value=? LEFT JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_order_total' WHERE p.post_type='shop_order' GROUP BY p.ID ORDER BY p.post_date DESC LIMIT 30",[(string)$id]);
    admin_layout('User #'.$id,static function() use($u,$roles,$wholesaleRoles,$canChangeRole,$meta,$orders){ ?><div class="heading"><div><div class="eyebrow">ACCOUNT #<?=h($u['ID'])?></div><h1><?=h($u['display_name'])?></h1><p><?=h(implode(', ',$roles))?></p></div><a class="secondary" href="<?=h(path('/admin/users'))?>">Back to users</a></div><div class="two-col"><section class="panel"><h2>Profile</h2><form method="post" action="<?=h(path('/admin/user'))?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($u['ID'])?>"><label>Display name<input name="display_name" value="<?=h($u['display_name'])?>" required></label><label>Email address<input name="email" type="email" value="<?=h($u['user_email'])?>" required></label><?php if($canChangeRole): ?><label>Customer pricing role<select name="role"><option value="" selected>Keep current role (<?=h(implode(', ',$roles))?>)</option><option value="customer">Retail customer</option><?php foreach($wholesaleRoles as $role): ?><option value="<?=h($role['slug'])?>" ><?=h($role['name'])?></option><?php endforeach; ?></select></label><?php endif; ?><button class="primary">Save profile</button></form><dl class="facts"><dt>Username</dt><dd><?=h($u['user_login'])?></dd><dt>Registered</dt><dd><?=h($u['user_registered'])?></dd><?php foreach($meta as $m): if($m['meta_value']==='') continue; ?><dt><?=h(str_replace('_',' ',$m['meta_key']))?></dt><dd><?=h($m['meta_value'])?></dd><?php endforeach; ?></dl></section><section class="panel"><h2>Orders</h2><div class="table-wrap"><table><thead><tr><th>Number</th><th>Date</th><th>Status</th><th>Total</th></tr></thead><tbody><?php foreach($orders as $o): ?><tr><td><a href="<?=h(path('/admin/order?id='.$o['ID']))?>">#<?=h($o['ID'])?></a></td><td><?=h($o['post_date'])?></td><td><?=h($o['post_status'])?></td><td><?=currency($o['total'] ?? 0)?></td></tr><?php endforeach; ?></tbody></table></div></section></div><?php });
}
function save_user(): never {
    $id=(int)($_POST['id'] ?? 0); $name=trim((string)($_POST['display_name'] ?? '')); $email=trim((string)($_POST['email'] ?? ''));
    if(!$id || $name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) { notice('Check the name and email address.'); redirect('/admin/user?id='.$id); }
    $before=row('SELECT display_name,user_email FROM wp_users WHERE ID=?',[$id]);
    if(!$before) { http_response_code(404); exit('User not found.'); }
    $oldRoles=wp_roles($id);
    $role=(string)($_POST['role'] ?? '');
    $canChangeRole=!array_intersect($oldRoles,['administrator','shop_manager']);
    if ($canChangeRole && $role!=='') {
        $allowed=array_merge(['customer'],array_column(wholesale_roles(),'slug'));
        if (!in_array($role,$allowed,true)) { http_response_code(400); exit('Invalid customer role.'); }
    }
    db()->beginTransaction();
    exec_sql('UPDATE wp_users SET display_name=?,user_email=? WHERE ID=?',[$name,$email,$id]);
    if ($canChangeRole && $role!=='') {
        exec_sql("DELETE FROM wp_usermeta WHERE user_id=? AND meta_key='wp_capabilities'",[$id]);
        $flags=array_intersect($oldRoles,['wwlc_inactive','wwlc_unapproved','wwlc_rejected']);
        $capabilities=[$role=>true]; foreach($flags as $flag) $capabilities[$flag]=true;
        exec_sql('INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (?,?,?)',[$id,'wp_capabilities',serialize($capabilities)]);
    }
    audit_change('user',$id,'update',$before+['roles'=>$oldRoles],['display_name'=>$name,'user_email'=>$email,'roles'=>$role!==''?[$role]:$oldRoles]);
    db()->commit();
    woo_webhook_after_change('customer.updated',$id);
    notice('Profile saved.'); redirect('/admin/user?id='.$id);
}
function admin_pages(): void {
    $pages=rows("SELECT ID,post_title,post_name,post_status,post_modified FROM wp_posts WHERE post_type='page' ORDER BY post_title");
    admin_layout('Pages',static function() use($pages){ ?><div class="heading"><div><div class="eyebrow">CONTENT</div><h1>Pages</h1><p><?=count($pages)?> pages</p></div></div><div class="panel table-wrap"><table><thead><tr><th>Title</th><th>Slug</th><th>Status</th><th>Updated</th></tr></thead><tbody><?php foreach($pages as $p): ?><tr><td><a href="<?=h(path('/admin/page?id='.$p['ID']))?>"><?=h($p['post_title'])?></a></td><td><?=h($p['post_name'])?></td><td><?=h($p['post_status'])?></td><td><?=h($p['post_modified'])?></td></tr><?php endforeach; ?></tbody></table></div><?php });
}
function admin_page(int $id): void {
    $p=row("SELECT ID,post_title,post_name,post_content,post_excerpt,post_status FROM wp_posts WHERE ID=? AND post_type='page'",[$id]); if(!$p) { http_response_code(404); exit('Page not found.'); }
    admin_layout('Edit page',static function() use($p){ ?><div class="heading"><div><div class="eyebrow">PAGE #<?=h($p['ID'])?></div><h1><?=h($p['post_title'])?></h1></div><a class="secondary" href="<?=h(path('/admin/pages'))?>">Back to pages</a></div><form class="panel edit-form" method="post" action="<?=h(path('/admin/page'))?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($p['ID'])?>"><label>Title<input name="title" value="<?=h($p['post_title'])?>" required></label><label>Slug<input name="slug" value="<?=h($p['post_name'])?>" required></label><label>Status<select name="status"><option value="publish" <?=$p['post_status']==='publish'?'selected':''?>>Published</option><option value="draft" <?=$p['post_status']==='draft'?'selected':''?>>Draft</option><option value="private" <?=$p['post_status']==='private'?'selected':''?>>Private</option></select></label><label>Summary<textarea name="excerpt" rows="3"><?=h($p['post_excerpt'])?></textarea></label><label>Content <small>HTML from WordPress is preserved.</small><textarea name="content" rows="20"><?=h($p['post_content'])?></textarea></label><button class="primary">Save page</button></form><?php });
}
function save_page(): never {
    $id=(int)($_POST['id'] ?? 0); $title=trim((string)($_POST['title'] ?? '')); $slug=trim((string)($_POST['slug'] ?? '')); $status=(string)($_POST['status'] ?? '');
    if(!$id || $title==='' || !preg_match('/^[a-z0-9-]+$/',$slug) || !in_array($status,['publish','draft','private'],true)) { notice('Check the title, slug and status.'); redirect('/admin/page?id='.$id); }
    $before=row("SELECT post_title,post_name,post_status FROM wp_posts WHERE ID=? AND post_type='page'",[$id]);
    if(!$before) { http_response_code(404); exit('Page not found.'); }
    db()->beginTransaction();
    exec_sql("UPDATE wp_posts SET post_title=?,post_name=?,post_excerpt=?,post_content=?,post_status=?,post_modified=NOW() WHERE ID=? AND post_type='page'",[$title,$slug,(string)($_POST['excerpt'] ?? ''),(string)($_POST['content'] ?? ''),$status,$id]);
    audit_change('page',$id,'update',$before,['post_title'=>$title,'post_name'=>$slug,'post_status'=>$status]);
    db()->commit();
    notice('Page saved.'); redirect('/admin/page?id='.$id);
}
function admin_categories(): void {
    $categories=rows("SELECT t.term_id,t.name,t.slug,tt.parent,tt.count FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' ORDER BY tt.parent,t.name");
    admin_layout('Categories',static function() use($categories){ ?><div class="heading"><div><div class="eyebrow">CATALOG</div><h1>Categories</h1><p><?=count($categories)?> categories</p></div></div><div class="panel table-wrap"><table><thead><tr><th>ID</th><th>Name</th><th>Slug</th><th>Parent</th><th>Products</th></tr></thead><tbody><?php foreach($categories as $c): ?><tr><td><?=h($c['term_id'])?></td><td><?=h($c['name'])?></td><td><?=h($c['slug'])?></td><td><?=h($c['parent'])?></td><td><?=h($c['count'])?></td></tr><?php endforeach; ?></tbody></table></div><?php });
}
function admin_coupons(): void {
    $coupons=rows("SELECT p.ID,p.post_title,p.post_status,p.post_date,MAX(CASE WHEN m.meta_key='discount_type' THEN m.meta_value END) discount_type,MAX(CASE WHEN m.meta_key='coupon_amount' THEN m.meta_value END) amount,MAX(CASE WHEN m.meta_key='usage_count' THEN m.meta_value END) usage_count FROM wp_posts p LEFT JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key IN ('discount_type','coupon_amount','usage_count') WHERE p.post_type='shop_coupon' GROUP BY p.ID ORDER BY p.ID DESC");
    admin_layout('Coupons',static function() use($coupons){ ?><div class="heading"><div><div class="eyebrow">SALES</div><h1>Coupons</h1><p><?=count($coupons)?> codes</p></div></div><div class="panel table-wrap"><table><thead><tr><th>Code</th><th>Status</th><th>Type</th><th>Amount</th><th>Used</th></tr></thead><tbody><?php foreach($coupons as $c): ?><tr><td><?=h($c['post_title'])?></td><td><?=h($c['post_status'])?></td><td><?=h($c['discount_type'])?></td><td><?=h($c['amount'])?></td><td><?=h($c['usage_count'])?></td></tr><?php endforeach; ?></tbody></table></div><?php });
}
function admin_shipping(): void {
    $zones=rows('SELECT zone_id,zone_name,zone_order FROM wp_woocommerce_shipping_zones ORDER BY zone_order,zone_id');
    $methods=rows('SELECT instance_id,zone_id,method_id,method_order,is_enabled FROM wp_woocommerce_shipping_zone_methods ORDER BY zone_id,method_order');
    $locations=rows('SELECT zone_id,location_code,location_type FROM wp_woocommerce_shipping_zone_locations ORDER BY zone_id,location_code');
    admin_layout('Shipping',static function() use($zones,$methods,$locations){ ?><div class="heading"><div><div class="eyebrow">SETTINGS</div><h1>Shipping zones</h1></div></div><div class="panel table-wrap"><table><thead><tr><th>Zone</th><th>Locations</th><th>Methods</th></tr></thead><tbody><?php foreach($zones as $z): ?><tr><td><?=h($z['zone_name'])?></td><td><?php foreach($locations as $l) if($l['zone_id']===$z['zone_id']) echo h($l['location_code']).' '; ?></td><td><?php foreach($methods as $m) if($m['zone_id']===$z['zone_id']) echo h($m['method_id']).($m['is_enabled']?' (enabled)':' (disabled)').'<br>'; ?></td></tr><?php endforeach; ?></tbody></table></div><?php });
}
function admin_taxes(): void {
    $rates=rows('SELECT tax_rate_id,tax_rate_country,tax_rate_state,tax_rate,tax_rate_name,tax_rate_priority,tax_rate_compound,tax_rate_shipping,tax_rate_class FROM wp_woocommerce_tax_rates ORDER BY tax_rate_order,tax_rate_id');
    admin_layout('Taxes',static function() use($rates){ ?><div class="heading"><div><div class="eyebrow">SETTINGS</div><h1>Tax rates</h1></div></div><div class="panel table-wrap"><table><thead><tr><th>Country</th><th>Region</th><th>Name</th><th>Rate</th><th>Class</th><th>Shipping</th></tr></thead><tbody><?php foreach($rates as $r): ?><tr><td><?=h($r['tax_rate_country'])?></td><td><?=h($r['tax_rate_state'])?></td><td><?=h($r['tax_rate_name'])?></td><td><?=h($r['tax_rate'])?>%</td><td><?=h($r['tax_rate_class'])?></td><td><?=$r['tax_rate_shipping']?'Yes':'No'?></td></tr><?php endforeach; ?></tbody></table></div><?php });
}
