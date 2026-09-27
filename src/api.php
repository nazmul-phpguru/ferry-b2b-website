<?php
declare(strict_types=1);
require_once __DIR__.'/catalog.php';

function json_response(array $data,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    exit;
}
function api_route(string $uri): never {
    if (str_starts_with($uri,'/api/store/')) storefront_api_route($uri);
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET') json_response(['error'=>'Method not allowed'],405);
    switch($uri) {
        case '/api/admin/media':
            require_admin();
            $q=trim((string)($_GET['q'] ?? ''));
            if(mb_strlen($q)>80) json_response(['error'=>'Search query too long'],400);
            $params=[]; $where="p.post_type='attachment' AND p.post_mime_type LIKE 'image/%'";
            if($q!=='') { $where.=' AND (p.post_title LIKE ? OR p.guid LIKE ?)'; $params=['%'.$q.'%','%'.$q.'%']; }
            $items=rows("SELECT p.ID,p.post_title,p.post_mime_type,(SELECT meta_value FROM wp_postmeta WHERE post_id=p.ID AND meta_key='_wp_attachment_image_alt' ORDER BY meta_id DESC LIMIT 1) alt,(SELECT meta_value FROM wp_postmeta WHERE post_id=p.ID AND meta_key='_wp_attachment_metadata' ORDER BY meta_id DESC LIMIT 1) metadata FROM wp_posts p WHERE $where ORDER BY p.ID DESC LIMIT 40",$params);
            foreach($items as &$item) {
                $meta=@unserialize((string)($item['metadata'] ?? ''),['allowed_classes'=>false]);
                $item=['id'=>(int)$item['ID'],'title'=>$item['post_title'],'alt'=>$item['alt'] ?? '','url'=>path('/media/attachment?id='.$item['ID']),'width'=>(int)($meta['width'] ?? 0),'height'=>(int)($meta['height'] ?? 0)];
            }
            json_response(['items'=>$items]);
        case '/api/v1/health':
            db()->query('SELECT 1');
            json_response(['status'=>'ok']);
        case '/api/v1/categories':
            json_response(['items'=>product_categories()]);
        case '/api/v1/catalog':
            $q=trim((string)($_GET['q'] ?? ''));
            if(mb_strlen($q)>80) json_response(['error'=>'Search query too long'],400);
            $category=max(0,(int)($_GET['category'] ?? 0));
            $page=min(1000,max(1,(int)($_GET['page'] ?? 1)));
            $result=catalog_products($q,$category,$page,24);
            foreach($result['items'] as &$p) {
                unset($p['price']); // Customer pricing is private until account policy and hosting are configured.
                $p=['id'=>(int)$p['ID'],'name'=>$p['post_title'],'slug'=>$p['post_name'],'sku'=>$p['sku'],'stock_quantity'=>is_numeric($p['stock'])?(int)$p['stock']:null,'stock_status'=>$p['stock_status'],'image'=>$p['image']];
            }
            json_response($result);
        case '/api/v1/product':
            $p=product((int)($_GET['id'] ?? 0));
            if(!$p || $p['post_status']!=='publish') json_response(['error'=>'Product not found'],404);
            json_response(['id'=>(int)$p['ID'],'name'=>$p['post_title'],'slug'=>$p['post_name'],'sku'=>$p['_sku'] ?? '', 'description'=>strip_tags($p['post_content']),'summary'=>strip_tags($p['post_excerpt']),'stock_quantity'=>is_numeric($p['_stock'] ?? null)?(int)$p['_stock']:null,'stock_status'=>$p['_stock_status'] ?? '', 'image'=>$p['image'],'categories'=>$p['categories']]);
        default: json_response(['error'=>'Not found'],404);
    }
}
