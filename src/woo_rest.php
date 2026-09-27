<?php
declare(strict_types=1);

function woo_error(string $code,string $message,int $status): never {
    json_response(['code'=>$code,'message'=>$message,'data'=>['status'=>$status]],$status);
}
function woo_key_auth(string $method): array {
    $host=(string)($_SERVER['HTTP_HOST']??'');
    if(empty($_SERVER['HTTPS']) && preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/',$host)!==1) woo_error('woocommerce_rest_authentication_error','This API requires HTTPS.',403);
    $key=(string)($_SERVER['PHP_AUTH_USER']??'');
    $secret=(string)($_SERVER['PHP_AUTH_PW']??'');
    if($key==='' && isset($_SERVER['HTTP_AUTHORIZATION']) && str_starts_with($_SERVER['HTTP_AUTHORIZATION'],'Basic ')) {
        $decoded=base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'],6),true);
        if($decoded!==false && str_contains($decoded,':')) [$key,$secret]=explode(':',$decoded,2);
    }
    // WooCommerce clients can use query authentication when a host strips Authorization.
    if($key==='' && isset($_GET['consumer_key'],$_GET['consumer_secret'])) {
        $host=(string)($_SERVER['HTTP_HOST']??'');$local=preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/',$host)===1;
        if(!$local && empty($_SERVER['HTTPS'])) woo_error('woocommerce_rest_authentication_error','Consumer keys require HTTPS.',403);
        $key=(string)$_GET['consumer_key'];$secret=(string)$_GET['consumer_secret'];
    }
    if(!preg_match('/^ck_[a-f0-9]{40}$/i',$key) || !preg_match('/^cs_[a-f0-9]{40}$/i',$secret)) woo_error('woocommerce_rest_authentication_error','Consumer key is missing or invalid.',401);
    $record=row('SELECT key_id,user_id,permissions,consumer_secret FROM wp_woocommerce_api_keys WHERE consumer_key=? LIMIT 1',[hash('sha256',$key)]);
    if(!$record || !hash_equals((string)$record['consumer_secret'],$secret)) woo_error('woocommerce_rest_authentication_error','Invalid consumer key or secret.',401);
    $roles=wp_roles((int)$record['user_id']);
    if(!array_intersect($roles,['administrator','shop_manager'])) woo_error('woocommerce_rest_authentication_error','The key owner lacks store access.',403);
    $need=in_array($method,['GET','HEAD'],true)?'read':'write';
    if(!in_array($record['permissions'],[$need,'read_write'],true)) woo_error('woocommerce_rest_authentication_error','This key does not have permission for this request.',403);
    exec_sql('UPDATE wp_woocommerce_api_keys SET last_access=NOW() WHERE key_id=?',[$record['key_id']]);
    return $record;
}
function woo_iso(?string $value): ?string {return $value?str_replace(' ','T',$value):null;}
function woo_page(): array {
    $page=max(1,min(100000,(int)($_GET['page']??1)));
    $per=max(1,min(100,(int)($_GET['per_page']??10)));
    return [$page,$per,($page-1)*$per];
}
function woo_list_headers(int $count,int $per): void {
    header('X-WP-Total: '.$count);header('X-WP-TotalPages: '.(int)ceil($count/$per));
}
function woo_product_data(array $p): array {
    $id=(int)$p['ID'];
    $meta=rows('SELECT meta_key,meta_value FROM wp_postmeta WHERE post_id=? AND meta_key IN ("_sku","_regular_price","_sale_price","_price","_stock","_stock_status","_manage_stock","_virtual","_downloadable","_tax_status","_tax_class","_weight","_length","_width","_height")',[$id]);
    $m=[];foreach($meta as $r)$m[$r['meta_key']]=$r['meta_value'];
    $terms=rows("SELECT t.term_id,t.name,t.slug,tt.taxonomy FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN wp_terms t ON t.term_id=tt.term_id WHERE tr.object_id=? AND tt.taxonomy IN ('product_cat','product_tag')",[$id]);
    $categories=[];$tags=[];foreach($terms as $t){$v=['id'=>(int)$t['term_id'],'name'=>$t['name'],'slug'=>$t['slug']];if($t['taxonomy']==='product_cat')$categories[]=$v;else $tags[]=$v;}
    $imageId=(int)(wp_meta($id,'_thumbnail_id')??0);
    return ['id'=>$id,'name'=>$p['post_title'],'slug'=>$p['post_name'],'permalink'=>$p['guid'],'date_created'=>woo_iso($p['post_date']),'date_modified'=>woo_iso($p['post_modified']),'type'=>'simple','status'=>$p['post_status'],'featured'=>false,'catalog_visibility'=>'visible','description'=>$p['post_content'],'short_description'=>$p['post_excerpt'],'sku'=>$m['_sku']??'','price'=>$m['_price']??'','regular_price'=>$m['_regular_price']??'','sale_price'=>$m['_sale_price']??'','on_sale'=>($m['_sale_price']??'')!=='','purchasable'=>true,'total_sales'=>0,'virtual'=>($m['_virtual']??'')==='yes','downloadable'=>($m['_downloadable']??'')==='yes','tax_status'=>$m['_tax_status']??'taxable','tax_class'=>$m['_tax_class']??'','manage_stock'=>($m['_manage_stock']??'')==='yes','stock_quantity'=>is_numeric($m['_stock']??null)?(int)$m['_stock']:null,'stock_status'=>$m['_stock_status']??'instock','weight'=>$m['_weight']??'','dimensions'=>['length'=>$m['_length']??'','width'=>$m['_width']??'','height'=>$m['_height']??''],'categories'=>$categories,'tags'=>$tags,'images'=>$imageId?[['id'=>$imageId,'src'=>path('/media/attachment?id='.$imageId),'name'=>'','alt'=>wp_meta($imageId,'_wp_attachment_image_alt')??'']]:[],'attributes'=>[],'variations'=>[],'meta_data'=>[]];
}
function woo_order_data(array $o): array {
    if(isset($o['ID'])){
        $id=(int)$o['ID'];$original=$o;$o=['id'=>$id,'parent_order_id'=>$original['post_parent'],'status'=>$original['post_status'],'currency'=>wp_meta($id,'_order_currency')??'CHF','date_created_gmt'=>$original['post_date_gmt'],'date_updated_gmt'=>$original['post_modified_gmt'],'total_amount'=>wp_meta($id,'_order_total')??'0','tax_amount'=>wp_meta($id,'_order_tax')??'0','customer_id'=>wp_meta($id,'_customer_user')??'0','customer_note'=>$original['post_excerpt'],'payment_method'=>wp_meta($id,'_payment_method')??'','payment_method_title'=>wp_meta($id,'_payment_method_title')??'','transaction_id'=>wp_meta($id,'_transaction_id')??''];
        $addresses=[];foreach(['billing','shipping'] as $kind){$a=['address_type'=>$kind];foreach(['first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone'] as $key)$a[$key]=wp_meta($id,'_'.$kind.'_'.$key)??'';$addresses[]=$a;}
    }else{$id=(int)$o['id'];$addresses=rows('SELECT * FROM wp_wc_order_addresses WHERE order_id=?',[$id]);}
    $billing=[];$shipping=[];foreach($addresses as $a){$out=[];foreach(['first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone'] as $k)$out[$k]=$a[$k]??'';if($a['address_type']==='billing')$billing=$out;else if($a['address_type']==='shipping')$shipping=$out;}
    $items=rows("SELECT i.order_item_id,i.order_item_name FROM wp_woocommerce_order_items i WHERE i.order_id=? AND i.order_item_type='line_item'",[$id]);
    $line=[];foreach($items as $i){$im=rows('SELECT meta_key,meta_value FROM wp_woocommerce_order_itemmeta WHERE order_item_id=? AND meta_key IN ("_product_id","_variation_id","_qty","_line_total","_line_tax","_line_subtotal")',[$i['order_item_id']]);$x=[];foreach($im as $v)$x[$v['meta_key']]=$v['meta_value'];$line[]=['id'=>(int)$i['order_item_id'],'name'=>$i['order_item_name'],'product_id'=>(int)($x['_product_id']??0),'variation_id'=>(int)($x['_variation_id']??0),'quantity'=>(int)($x['_qty']??0),'subtotal'=>$x['_line_subtotal']??'0','total'=>$x['_line_total']??'0','total_tax'=>$x['_line_tax']??'0','meta_data'=>[]];}
    return ['id'=>$id,'parent_id'=>(int)$o['parent_order_id'],'number'=>(string)$id,'order_key'=>'','created_via'=>'rest-api','version'=>'','status'=>preg_replace('/^wc-/','',(string)$o['status']),'currency'=>$o['currency'],'date_created'=>woo_iso($o['date_created_gmt']),'date_modified'=>woo_iso($o['date_updated_gmt']),'discount_total'=>'0','shipping_total'=>'0','total'=>$o['total_amount'],'total_tax'=>$o['tax_amount'],'prices_include_tax'=>false,'customer_id'=>(int)$o['customer_id'],'customer_note'=>$o['customer_note'],'billing'=>$billing,'shipping'=>$shipping,'payment_method'=>$o['payment_method'],'payment_method_title'=>$o['payment_method_title'],'transaction_id'=>$o['transaction_id'],'date_paid'=>null,'date_completed'=>null,'line_items'=>$line,'tax_lines'=>[],'shipping_lines'=>[],'fee_lines'=>[],'coupon_lines'=>[],'refunds'=>[],'meta_data'=>[]];
}
function woo_customer_data(array $u): array {
    $id=(int)$u['ID'];$meta=rows("SELECT meta_key,meta_value FROM wp_usermeta WHERE user_id=? AND (meta_key LIKE 'billing_%' OR meta_key LIKE 'shipping_%' OR meta_key IN ('first_name','last_name'))",[$id]);$m=[];foreach($meta as $r)$m[$r['meta_key']]=$r['meta_value'];
    $address=function(string $prefix)use($m):array{$out=[];foreach(['first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone'] as $k)$out[$k]=$m[$prefix.$k]??'';return $out;};
    $roles=wp_roles($id);
    return ['id'=>$id,'date_created'=>woo_iso($u['user_registered']),'email'=>$u['user_email'],'first_name'=>$m['first_name']??'','last_name'=>$m['last_name']??'','role'=>$roles[0]??'customer','username'=>$u['user_login'],'billing'=>$address('billing_'),'shipping'=>$address('shipping_'),'is_paying_customer'=>false,'avatar_url'=>'','meta_data'=>[]];
}
function woo_product_write(?array $existing,array $body,int $keyId): array {
    $allowed=['name','status','description','short_description','sku','regular_price','sale_price','manage_stock','stock_quantity'];
    if(array_diff(array_keys($body),$allowed))woo_error('woocommerce_rest_cannot_edit','One or more product fields are unsupported.',501);
    $title=trim((string)($body['name']??$existing['post_title']??''));$status=(string)($body['status']??$existing['post_status']??'draft');
    if($title===''||mb_strlen($title)>200||!in_array($status,['publish','draft','private','pending'],true))woo_error('woocommerce_rest_invalid_product','Invalid product name or status.',400);
    foreach(['description','short_description'] as $field)if(isset($body[$field])&&(!is_string($body[$field])||strlen($body[$field])>2000000))woo_error('woocommerce_rest_invalid_product','Invalid description.',400);
    $sku=isset($body['sku'])?trim((string)$body['sku']):null;if($sku!==null && (mb_strlen($sku)>100||($sku!==''&&row("SELECT post_id FROM wp_postmeta WHERE meta_key='_sku' AND meta_value=? AND post_id<>? LIMIT 1",[$sku,(int)($existing['ID']??0)]))))woo_error('woocommerce_rest_product_invalid_sku','Invalid or duplicate SKU.',400);
    foreach(['regular_price','sale_price'] as $field)if(isset($body[$field])&&$body[$field]!==''&&(!is_numeric($body[$field])||(float)$body[$field]<0))woo_error('woocommerce_rest_invalid_product','Invalid price.',400);
    if(isset($body['stock_quantity'])&&$body['stock_quantity']!==null&&(!is_numeric($body['stock_quantity'])||(int)$body['stock_quantity']<0))woo_error('woocommerce_rest_invalid_product','Invalid stock quantity.',400);
    db()->beginTransaction();try{
        if($existing){$id=(int)$existing['ID'];exec_sql("UPDATE wp_posts SET post_title=?,post_status=?,post_content=?,post_excerpt=?,post_modified=NOW(),post_modified_gmt=UTC_TIMESTAMP() WHERE ID=? AND post_type='product'",[$title,$status,$body['description']??$existing['post_content'],$body['short_description']??$existing['post_excerpt'],$id]);$action='update';}
        else{exec_sql("INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (0,NOW(),UTC_TIMESTAMP(),?,?,? ,?,'open','closed',?,'','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'product','',0)",[$body['description']??'',$title,$body['short_description']??'',$status,slug($title)]);$id=(int)db()->lastInsertId();exec_sql('UPDATE wp_posts SET post_name=?,guid=? WHERE ID=?',[slug($title).'-'.$id,'urn:product:'.$id,$id]);$action='create';}
        if($sku!==null)set_wp_meta($id,'_sku',$sku);
        foreach(['regular_price'=>'_regular_price','sale_price'=>'_sale_price'] as $input=>$meta)if(array_key_exists($input,$body))set_wp_meta($id,$meta,(string)$body[$input]);
        if(isset($body['regular_price'])||isset($body['sale_price'])){$sale=$body['sale_price']??wp_meta($id,'_sale_price')??'';$regular=$body['regular_price']??wp_meta($id,'_regular_price')??'';set_wp_meta($id,'_price',(string)($sale!==''?$sale:$regular));}
        if(array_key_exists('manage_stock',$body))set_wp_meta($id,'_manage_stock',$body['manage_stock']?'yes':'no');
        if(array_key_exists('stock_quantity',$body)){set_wp_meta($id,'_stock',(string)$body['stock_quantity']);set_wp_meta($id,'_stock_status',(int)$body['stock_quantity']>0?'instock':'outofstock');}
        audit_change('product',$id,'api_'.$action,[],['fields'=>array_keys($body),'api_key_id'=>$keyId]);db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    $updated=row('SELECT * FROM wp_posts WHERE ID=?',[$id]);woo_webhook_after_change('product.'.($action==='create'?'created':'updated'),$id);return woo_product_data($updated);
}
function woo_rest_route(string $uri): never {
    $method=$_SERVER['REQUEST_METHOD']??'GET';$auth=woo_key_auth($method);
    $resource=substr($uri,strlen('/wp-json/wc/v3/'));
    if($resource==='products/categories'||preg_match('~^products/categories/(\d+)$~',$resource,$termMatch)){
        if($method!=='GET')woo_error('woocommerce_rest_cannot_edit','This category operation is not available yet.',501);
        $where="tt.taxonomy='product_cat'";$params=[];if(isset($termMatch[1])){$where.=' AND t.term_id=?';$params[]=(int)$termMatch[1];}
        [$page,$per,$offset]=woo_page();$count=(int)row("SELECT COUNT(*) n FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE $where",$params)['n'];
        $terms=rows("SELECT t.term_id,t.name,t.slug,tt.parent,tt.description,tt.count FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE $where ORDER BY t.name LIMIT $per OFFSET $offset",$params);
        $out=array_map(static fn($t)=>['id'=>(int)$t['term_id'],'name'=>$t['name'],'slug'=>$t['slug'],'parent'=>(int)$t['parent'],'description'=>$t['description'],'display'=>'default','image'=>null,'menu_order'=>0,'count'=>(int)$t['count']],$terms);
        if(isset($termMatch[1])){if(!$out)woo_error('woocommerce_rest_term_invalid','Invalid category ID.',404);json_response($out[0]);}woo_list_headers($count,$per);json_response($out);
    }
    if($resource==='coupons'||preg_match('~^coupons/(\d+)$~',$resource,$couponMatch)){
        if($method!=='GET')woo_error('woocommerce_rest_cannot_edit','This coupon operation is not available yet.',501);
        $where="post_type='shop_coupon' AND post_status!='trash'";$params=[];if(isset($couponMatch[1])){$where.=' AND ID=?';$params[]=(int)$couponMatch[1];}
        [$page,$per,$offset]=woo_page();$count=(int)row("SELECT COUNT(*) n FROM wp_posts WHERE $where",$params)['n'];$coupons=rows("SELECT ID,post_title,post_date,post_modified,post_excerpt FROM wp_posts WHERE $where ORDER BY ID DESC LIMIT $per OFFSET $offset",$params);
        $out=[];foreach($coupons as $c){$id=(int)$c['ID'];$out[]=['id'=>$id,'code'=>$c['post_title'],'amount'=>wp_meta($id,'coupon_amount')??'0','status'=>'publish','date_created'=>woo_iso($c['post_date']),'date_modified'=>woo_iso($c['post_modified']),'discount_type'=>wp_meta($id,'discount_type')??'fixed_cart','description'=>$c['post_excerpt'],'date_expires'=>woo_iso(wp_meta($id,'date_expires')),'usage_count'=>(int)(wp_meta($id,'usage_count')??0),'individual_use'=>wp_meta($id,'individual_use')==='yes','product_ids'=>[],'excluded_product_ids'=>[],'usage_limit'=>(int)(wp_meta($id,'usage_limit')??0),'usage_limit_per_user'=>(int)(wp_meta($id,'usage_limit_per_user')??0),'limit_usage_to_x_items'=>(int)(wp_meta($id,'limit_usage_to_x_items')??0),'free_shipping'=>wp_meta($id,'free_shipping')==='yes','product_categories'=>[],'excluded_product_categories'=>[],'exclude_sale_items'=>wp_meta($id,'exclude_sale_items')==='yes','minimum_amount'=>wp_meta($id,'minimum_amount')??'0','maximum_amount'=>wp_meta($id,'maximum_amount')??'0','email_restrictions'=>[],'meta_data'=>[]];}
        if(isset($couponMatch[1])){if(!$out)woo_error('woocommerce_rest_invalid_id','Invalid coupon ID.',404);json_response($out[0]);}woo_list_headers($count,$per);json_response($out);
    }
    if($resource==='products'||preg_match('~^products/(\d+)$~',$resource,$match)){
        if(isset($match[1])){$p=row("SELECT * FROM wp_posts WHERE ID=? AND post_type='product'",[(int)$match[1]]);if(!$p)woo_error('woocommerce_rest_product_invalid_id','Invalid product ID.',404);if(in_array($method,['PUT','PATCH'],true)){$body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))woo_error('woocommerce_rest_invalid_json','Invalid JSON body.',400);json_response(woo_product_write($p,$body,(int)$auth['key_id']));}if($method!=='GET')woo_error('woocommerce_rest_cannot_edit','This product operation is not available yet.',501);json_response(woo_product_data($p));}
        if($method==='POST'){$body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))woo_error('woocommerce_rest_invalid_json','Invalid JSON body.',400);json_response(woo_product_write(null,$body,(int)$auth['key_id']),201);}
        if($method!=='GET') woo_error('woocommerce_rest_cannot_edit','This product operation is not available yet.',501);
        [$page,$per,$offset]=woo_page();$where="post_type='product' AND post_status!='trash'";$params=[];
        if(isset($_GET['status'])){$where.=' AND post_status=?';$params[]=(string)$_GET['status'];}
        if(isset($_GET['search'])){$where.=' AND post_title LIKE ?';$params[]='%'.mb_substr((string)$_GET['search'],0,80).'%';}
        if(isset($_GET['sku'])){$where.=" AND ID IN (SELECT post_id FROM wp_postmeta WHERE meta_key='_sku' AND meta_value=?)";$params[]=(string)$_GET['sku'];}
        $count=(int)row("SELECT COUNT(*) n FROM wp_posts WHERE $where",$params)['n'];$items=rows("SELECT * FROM wp_posts WHERE $where ORDER BY ID DESC LIMIT $per OFFSET $offset",$params);woo_list_headers($count,$per);json_response(array_map('woo_product_data',$items));
    }
    if($resource==='orders'||preg_match('~^orders/(\d+)$~',$resource,$match)){
        if(isset($match[1])){
            $id=(int)$match[1];$o=row("SELECT * FROM wp_posts WHERE ID=? AND post_type='shop_order'",[$id]);if(!$o)woo_error('woocommerce_rest_shop_order_invalid_id','Invalid order ID.',404);
            if(in_array($method,['PUT','PATCH'],true)){
                $body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))woo_error('woocommerce_rest_invalid_json','Invalid JSON body.',400);
                if(array_diff(array_keys($body),['status']))woo_error('woocommerce_rest_cannot_edit','Only order status can be updated through this endpoint.',501);
                $status=(string)($body['status']??'');if(!preg_match('/^[a-z][a-z0-9-]{0,30}$/',$status))woo_error('woocommerce_rest_invalid_status','Invalid order status.',400);
                exec_sql('UPDATE wp_posts SET post_status=?,post_modified=NOW(),post_modified_gmt=UTC_TIMESTAMP() WHERE ID=?',['wc-'.$status,$id]);
                audit_change('order',$id,'api_status',[],['status'=>$status,'api_key_id'=>(int)$auth['key_id']]);
                $o=row('SELECT * FROM wp_posts WHERE ID=?',[$id]);woo_emit_webhooks('order.updated',$id,woo_order_data($o));
            } elseif($method!=='GET') woo_error('woocommerce_rest_cannot_edit','This order operation is not available yet.',501);
            json_response(woo_order_data($o));
        }
        if($method!=='GET')woo_error('woocommerce_rest_cannot_edit','This order operation is not available yet.',501);
        [$page,$per,$offset]=woo_page();$where="post_type='shop_order' AND post_status<>'trash'";$params=[];
        if(isset($_GET['status'])){$where.=' AND post_status=?';$params[]='wc-'.(string)$_GET['status'];}
        if(isset($_GET['customer'])){$where.=" AND ID IN (SELECT post_id FROM wp_postmeta WHERE meta_key='_customer_user' AND meta_value=?)";$params[]=(string)(int)$_GET['customer'];}
        $count=(int)row("SELECT COUNT(*) n FROM wp_posts WHERE $where",$params)['n'];$items=rows("SELECT * FROM wp_posts WHERE $where ORDER BY ID DESC LIMIT $per OFFSET $offset",$params);woo_list_headers($count,$per);json_response(array_map('woo_order_data',$items));
    }
    if($resource==='customers'||preg_match('~^customers/(\d+)$~',$resource,$match)){
        if($method!=='GET')woo_error('woocommerce_rest_cannot_edit','This customer operation is not available yet.',501);
        if(isset($match[1])){$u=row('SELECT * FROM wp_users WHERE ID=?',[(int)$match[1]]);if(!$u||array_intersect(wp_roles((int)$u['ID']),['administrator','shop_manager']))woo_error('woocommerce_rest_invalid_id','Invalid customer ID.',404);json_response(woo_customer_data($u));}
        [$page,$per,$offset]=woo_page();$where="EXISTS (SELECT 1 FROM wp_usermeta m WHERE m.user_id=u.ID AND m.meta_key='wp_capabilities' AND m.meta_value NOT LIKE '%administrator%' AND m.meta_value NOT LIKE '%shop_manager%')";
        $count=(int)row("SELECT COUNT(*) n FROM wp_users u WHERE $where")['n'];$items=rows("SELECT u.* FROM wp_users u WHERE $where ORDER BY u.ID DESC LIMIT $per OFFSET $offset");woo_list_headers($count,$per);json_response(array_map('woo_customer_data',$items));
    }
    if($resource==='webhooks'){
        if($method!=='GET')woo_error('woocommerce_rest_cannot_edit','Manage webhooks in the admin screen.',501);
        [$page,$per,$offset]=woo_page();$count=(int)row('SELECT COUNT(*) n FROM wp_wc_webhooks')['n'];$items=rows("SELECT webhook_id,name,status,topic,delivery_url,date_created,date_modified FROM wp_wc_webhooks ORDER BY webhook_id DESC LIMIT $per OFFSET $offset");woo_list_headers($count,$per);foreach($items as &$item)$item['id']=(int)$item['webhook_id'];json_response($items);
    }
    woo_error('rest_no_route','No route was found matching the URL and request method.',404);
}
function woo_emit_webhooks(string $topic,int $id,array $payload): void {
    if(cfg('webhook_delivery_enabled',false)!==true)return;
    if(!function_exists('curl_init'))return;
    $hooks=rows("SELECT webhook_id,delivery_url,secret FROM wp_wc_webhooks WHERE status='active' AND topic IN (?,?)",[$topic,explode('.',$topic)[0].'.updated']);
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    foreach($hooks as $hook){
        $url=(string)$hook['delivery_url'];$parts=parse_url($url);if(!$parts||($parts['scheme']??'')!=='https'||empty($parts['host']))continue;
        $host=$parts['host'];$ips=gethostbynamel($host)?:[];if(!$ips)continue;$public=true;foreach($ips as $ip){if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))$public=false;}if(!$public)continue;
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-WC-Webhook-Topic: '.$topic,'X-WC-Webhook-Resource: '.explode('.',$topic)[0],'X-WC-Webhook-Event: '.explode('.',$topic)[1],'X-WC-Webhook-ID: '.$hook['webhook_id'],'X-WC-Webhook-Signature: '.base64_encode(hash_hmac('sha256',$json,(string)$hook['secret'],true))],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]]]);
        curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        $successful=($code>=200&&$code<300)||$code===301||$code===302;
        $failures=$successful?0:((int)(row('SELECT failure_count FROM wp_wc_webhooks WHERE webhook_id=?',[$hook['webhook_id']])['failure_count']??0)+1);
        exec_sql('UPDATE wp_wc_webhooks SET failure_count=?,status=IF(? > 5,"disabled",status) WHERE webhook_id=?',[$failures,$failures,$hook['webhook_id']]);
    }
}
function woo_webhook_after_change(string $topic,int $id): void {
    try {
        $type=explode('.',$topic)[0];
        if($type==='customer'){$user=row('SELECT * FROM wp_users WHERE ID=?',[$id]);if($user)woo_emit_webhooks($topic,$id,woo_customer_data($user));return;}
        $post=row('SELECT * FROM wp_posts WHERE ID=? AND post_type=?',[$id,match($type){'order'=>'shop_order','coupon'=>'shop_coupon',default=>'product'}]);
        if($post){$payload=match($type){'order'=>woo_order_data($post),'product'=>woo_product_data($post),'coupon'=>['id'=>$id,'code'=>$post['post_title'],'amount'=>wp_meta($id,'coupon_amount')??'0','discount_type'=>wp_meta($id,'discount_type')??'fixed_cart','status'=>$post['post_status']],default=>[]};woo_emit_webhooks($topic,$id,$payload);}
    } catch(Throwable $e) {error_log('Webhook delivery failed for '.$topic.' #'.$id.': '.$e->getMessage());}
}
