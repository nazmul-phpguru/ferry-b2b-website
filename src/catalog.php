<?php
declare(strict_types=1);

function product_categories(): array {
    return rows("SELECT t.term_id,t.name,t.slug,tt.parent,tt.count FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id AND tt.taxonomy='product_cat' WHERE tt.count>0 ORDER BY tt.parent,t.name");
}
function catalog_product_image(int $productId,string $sku,int $thumbnailId): string {
    $safeSku=preg_match('/^[A-Za-z0-9_-]{1,80}$/',$sku)?$sku:'';
    if($safeSku!=='' && is_file(dirname(__DIR__).'/public/assets/images/'.$safeSku.'.webp')) return path('/assets/images/'.$safeSku.'.webp');
    return $thumbnailId>0 ? path('/media/product?id='.$productId) : path('/assets/no-image.svg');
}
function catalog_products(string $query='',int $category=0,int $page=1,int $perPage=24): array {
    $where="p.post_type='product' AND p.post_status='publish'";
    $params=[];
    if ($query!=='') {
        $where.=" AND (p.post_title LIKE ? OR sku.meta_value LIKE ? OR p.post_excerpt LIKE ?)";
        $like='%'.$query.'%'; array_push($params,$like,$like,$like);
    }
    if ($category>0) {
        $where.=" AND EXISTS (SELECT 1 FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy='product_cat' AND tt.term_id=?)";
        $params[]=$category;
    }
    $joins=" LEFT JOIN wp_postmeta sku ON sku.post_id=p.ID AND sku.meta_key='_sku'";
    $total=(int)(row("SELECT COUNT(DISTINCT p.ID) n FROM wp_posts p $joins WHERE $where",$params)['n'] ?? 0);
    $offset=max(0,($page-1)*$perPage);
    $sql="SELECT p.ID,p.post_title,p.post_name,p.post_excerpt,
        MAX(sku.meta_value) sku,MAX(price.meta_value) price,MAX(stock.meta_value) stock,
        MAX(status.meta_value) stock_status,MAX(thumb.meta_value) thumbnail_id
        FROM wp_posts p $joins
        LEFT JOIN wp_postmeta price ON price.post_id=p.ID AND price.meta_key='_price'
        LEFT JOIN wp_postmeta stock ON stock.post_id=p.ID AND stock.meta_key='_stock'
        LEFT JOIN wp_postmeta status ON status.post_id=p.ID AND status.meta_key='_stock_status'
        LEFT JOIN wp_postmeta thumb ON thumb.post_id=p.ID AND thumb.meta_key='_thumbnail_id'
        WHERE $where GROUP BY p.ID ORDER BY p.post_modified DESC,p.ID DESC LIMIT $perPage OFFSET $offset";
    $products=rows($sql,$params);
    foreach($products as &$product) $product['image']=catalog_product_image((int)$product['ID'],(string)($product['sku']??''),(int)($product['thumbnail_id']??0));
    return ['total'=>$total,'items'=>$products,'page'=>$page,'pages'=>(int)ceil($total/$perPage)];
}
function product(int $id): ?array {
    $p=row("SELECT ID,post_title,post_name,post_excerpt,post_content,post_status,post_modified,comment_status,menu_order FROM wp_posts WHERE ID=? AND post_type='product' LIMIT 1",[$id]);
    if (!$p) return null;
    $meta=rows('SELECT meta_key,meta_value FROM wp_postmeta WHERE post_id=?',[$id]);
    foreach($meta as $m) if (!isset($p[$m['meta_key']])) $p[$m['meta_key']]=$m['meta_value'];
    $thumb=(int)($p['_thumbnail_id'] ?? 0);
    $p['image']=catalog_product_image($id,(string)($p['_sku']??''),$thumb);
    $p['categories']=rows("SELECT t.name,t.slug,t.term_id FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id JOIN wp_term_relationships tr ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tr.object_id=? AND tt.taxonomy='product_cat' ORDER BY t.name",[$id]);
    return $p;
}
function product_price_for_role(array $product, ?string $roleSlug): array {
    $retail=(float)($product['_price'] ?? $product['_regular_price'] ?? 0);
    if ($roleSlug===null || $roleSlug==='') return ['price'=>$retail,'role'=>null,'minimum_quantity'=>1,'quantity_step'=>1];
    $role=row('SELECT slug FROM app_wholesale_roles WHERE slug=? AND active=1 LIMIT 1',[$roleSlug]);
    if (!$role) return ['price'=>$retail,'role'=>null,'minimum_quantity'=>1,'quantity_step'=>1];
    $regular=$product[$roleSlug.'_wholesale_price'] ?? '';
    $sale=$product[$roleSlug.'_wholesale_sale_price'] ?? '';
    $price=is_numeric($regular)?(float)$regular:$retail;
    if (is_numeric($sale) && (float)$sale>=0 && (float)$sale<$price) $price=(float)$sale;
    return [
        'price'=>$price,
        'role'=>$roleSlug,
        'minimum_quantity'=>max(1,(int)($product[$roleSlug.'_wholesale_minimum_order_quantity'] ?? 1)),
        'quantity_step'=>max(1,(int)($product[$roleSlug.'_wholesale_order_quantity_step'] ?? 1)),
    ];
}
function page_by_slug(string $slug): ?array {
    return row("SELECT ID,post_title,post_content,post_name FROM wp_posts WHERE post_type='page' AND post_status='publish' AND post_name=? LIMIT 1",[$slug]);
}
function order_summary(int $id): ?array {
    $order=row("SELECT ID,post_date,post_status,post_excerpt FROM wp_posts WHERE ID=? AND post_type='shop_order' LIMIT 1",[$id]);
    if (!$order) return null;
    foreach(rows("SELECT meta_key,meta_value FROM wp_postmeta WHERE post_id=? AND meta_key IN ('_order_total','_order_currency','_customer_user','_billing_first_name','_billing_last_name','_billing_email','_billing_phone','_billing_address_1','_billing_city','_billing_postcode','_billing_country','_payment_method_title')",[$id]) as $m) $order[$m['meta_key']]=$m['meta_value'];
    $order['items']=rows("SELECT oi.order_item_id,oi.order_item_name,oi.order_item_type,
        MAX(CASE WHEN im.meta_key='_qty' THEN im.meta_value END) qty,
        MAX(CASE WHEN im.meta_key='_line_total' THEN im.meta_value END) total,
        MAX(CASE WHEN im.meta_key='_product_id' THEN im.meta_value END) product_id
        FROM wp_woocommerce_order_items oi LEFT JOIN wp_woocommerce_order_itemmeta im ON im.order_item_id=oi.order_item_id
        WHERE oi.order_id=? GROUP BY oi.order_item_id ORDER BY oi.order_item_id",[$id]);
    return $order;
}
