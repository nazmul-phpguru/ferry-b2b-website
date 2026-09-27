<?php
declare(strict_types=1);

function store_reply(mixed $data,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($status<400?['ok'=>true,'data'=>$data]:['ok'=>false,'error'=>$data],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    exit;
}
function store_request_json(): array {
    $data=json_decode((string)file_get_contents('php://input'),true);
    return is_array($data)?$data:[];
}
function store_customer_session(): array {
    $id=(int)($_SESSION['store_customer_id']??0);
    $user=$id?row('SELECT ID,user_login,user_email,display_name FROM wp_users WHERE ID=?',[$id]):null;
    if(!$user){unset($_SESSION['store_customer_id']);return ['authenticated'=>false,'approved'=>false,'customer'=>null,'csrf'=>csrf_token()];}
    $meta=[];foreach(rows('SELECT meta_key,meta_value FROM wp_usermeta WHERE user_id=?',[$id]) as $entry)$meta[$entry['meta_key']]=$entry['meta_value'];
    $roles=wp_roles($id);$unapproved=in_array('wwlc_unapproved',$roles,true);
    $approved=!$unapproved&&(bool)array_intersect($roles,['administrator','shop_manager','customer','subscriber','wholesale_customer','wholesale_repair_shop','big_repair_shop_customer_account']);
    $group='customer';$pricingRoles=array_column(rows('SELECT slug FROM app_wholesale_roles WHERE active=1'),'slug');
    foreach($roles as $role){$normalized=strtolower(preg_replace('/[^a-z0-9]+/i','_',$role));if(in_array($role,$pricingRoles,true)||str_contains($normalized,'wholesale')||str_contains($normalized,'repair_shop')){$group=$role;break;}}
    return ['authenticated'=>true,'approved'=>$approved,'customer'=>[
        'id'=>$id,'username'=>$user['user_login'],'email'=>$user['user_email'],'display_name'=>$user['display_name'],
        'first_name'=>$meta['first_name']??'','last_name'=>$meta['last_name']??'','company'=>$meta['billing_company']??'',
        'phone'=>$meta['billing_phone']??'','country'=>$meta['billing_country']??'CH','customer_group'=>$group,
        'requested_group'=>$meta['wwlc_requested_wholesale_role']??$group,'roles'=>$roles,
    ],'csrf'=>csrf_token()];
}
function store_require_customer(): array {
    $session=store_customer_session();
    if(empty($session['authenticated']))store_reply('Please log in to continue.',401);
    if(empty($session['approved']))store_reply('Your wholesale account is awaiting approval.',403);
    return $session;
}
function store_resolve_price(array $meta,?array $session=null): array {
    $session=$session??store_customer_session();
    if(empty($session['approved'])) return ['price'=>null,'currency'=>'CHF','role'=>null,'role_label'=>null,'minimum_quantity'=>1,'quantity_step'=>1];
    $role=(string)($session['customer']['customer_group']??'customer');
    $retailRaw=$meta['_price']??$meta['_regular_price']??null;
    $retail=is_numeric($retailRaw)?(float)$retailRaw:null;
    $regularRaw=$meta[$role.'_wholesale_price']??null;
    $saleRaw=$meta[$role.'_wholesale_sale_price']??null;
    $price=is_numeric($regularRaw)?(float)$regularRaw:$retail;
    if(is_numeric($saleRaw) && (float)$saleRaw>=0 && ($price===null || (float)$saleRaw<$price)) $price=(float)$saleRaw;
    return [
        'price'=>$price,'currency'=>'CHF','role'=>$role,
        'role_label'=>ucwords(str_replace(['_','-'],' ',preg_replace('/(?<=[a-z0-9])(?=[A-Z])/',' ',$role))),
        'minimum_quantity'=>max(1,(int)($meta[$role.'_wholesale_minimum_order_quantity']??1)),
        'quantity_step'=>max(1,(int)($meta[$role.'_wholesale_order_quantity_step']??1)),
    ];
}
function store_user_meta(int $userId): array {
    $meta=[];
    foreach(rows('SELECT meta_key,meta_value FROM wp_usermeta WHERE user_id=?',[$userId]) as $entry)$meta[(string)$entry['meta_key']]=(string)$entry['meta_value'];
    return $meta;
}
function store_set_user_meta(int $userId,string $key,string $value): void {
    if(row('SELECT umeta_id FROM wp_usermeta WHERE user_id=? AND meta_key=? LIMIT 1',[$userId,$key]))exec_sql('UPDATE wp_usermeta SET meta_value=? WHERE user_id=? AND meta_key=?',[$value,$userId,$key]);
    else exec_sql('INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (?,?,?)',[$userId,$key,$value]);
}
function store_address(int $userId): ?array {
    $meta=store_user_meta($userId);
    if(trim($meta['billing_address_1']??'')==='')return null;
    return ['id'=>'billing-'.$userId,'type'=>'billing','name'=>trim(($meta['billing_first_name']??$meta['first_name']??'').' '.($meta['billing_last_name']??$meta['last_name']??'')),'company'=>$meta['billing_company']??'','phone'=>$meta['billing_phone']??'','line1'=>$meta['billing_address_1']??'','line2'=>$meta['billing_address_2']??'','city'=>$meta['billing_city']??'','state'=>$meta['billing_state']??'','postcode'=>$meta['billing_postcode']??'','country'=>$meta['billing_country']??'CH'];
}
function store_save_address(int $userId,array $address): array {
    $name=trim((string)($address['name']??''));$parts=preg_split('/\s+/', $name,2)?:[];
    $values=['first_name'=>$parts[0]??'','last_name'=>$parts[1]??'','company'=>(string)($address['company']??''),'phone'=>(string)($address['phone']??''),'address_1'=>(string)($address['line1']??''),'address_2'=>(string)($address['line2']??''),'city'=>(string)($address['city']??''),'state'=>(string)($address['state']??''),'postcode'=>(string)($address['postcode']??''),'country'=>strtoupper((string)($address['country']??'CH'))];
    foreach(['billing','shipping'] as $prefix)foreach($values as $key=>$value){if($prefix==='shipping'&&$key==='phone')continue;store_set_user_meta($userId,$prefix.'_'.$key,mb_substr(trim($value),0,190));}
    store_set_user_meta($userId,'first_name',$values['first_name']);store_set_user_meta($userId,'last_name',$values['last_name']);
    return store_address($userId)??[];
}
function store_checkout_quote(array $input,array $session): array {
    $requested=array_slice((array)($input['items']??[]),0,100);$ids=[];
    foreach($requested as $entry){$id=(int)($entry['id']??0);if($id>0)$ids[$id]=max(1,min(999,(int)($entry['qty']??1)));}
    if(!$ids)store_reply('Your cart is empty.',422);
    $marks=implode(',',array_fill(0,count($ids),'?'));$products=rows("SELECT ID,post_title FROM wp_posts WHERE ID IN ($marks) AND post_type='product' AND post_status='publish'",array_keys($ids));
    $metaRows=rows("SELECT post_id,meta_key,meta_value FROM wp_postmeta WHERE post_id IN ($marks) AND (meta_key IN ('_sku','_price','_regular_price','_stock','_stock_status','_manage_stock') OR meta_key LIKE '%_wholesale_%')",array_keys($ids));
    $meta=[];foreach($metaRows as $entry)$meta[(int)$entry['post_id']][(string)$entry['meta_key']]=(string)$entry['meta_value'];
    $productMap=[];foreach($products as $product)$productMap[(int)$product['ID']]=$product;
    $items=[];$subtotal=0.0;$validation=[];
    foreach($ids as $id=>$qty){
        $product=$productMap[$id]??null;$m=$meta[$id]??[];
        if(!$product){$items[]=['id'=>$id,'qty'=>$qty,'unit_price'=>0,'line_total'=>0,'validation_error'=>'This product is no longer available.','validation_code'=>'unavailable'];continue;}
        $resolved=store_resolve_price($m,$session);$price=(float)($resolved['price']??0);
        $stock=is_numeric($m['_stock']??null)?(int)$m['_stock']:0;$managed=($m['_manage_stock']??'no')==='yes';$error=null;
        if(($m['_stock_status']??'instock')!=='instock')$error='This product is out of stock.';
        elseif($managed&&$stock<$qty)$error='Only '.$stock.' unit'.($stock===1?' is':'s are').' available.';
        $line=round($price*$qty,2);$subtotal+=$line;
        $items[]=['id'=>$id,'name'=>$product['post_title'],'sku'=>$m['_sku']??'','qty'=>$qty,'unit_price'=>round($price,2),'line_total'=>$line,'available_quantity'=>$managed?$stock:null,'validation_error'=>$error,'validation_code'=>$error?'stock':null];
        if($error)$validation[]=$product['post_title'].': '.$error;
    }
    $couponCode=strtoupper(trim((string)($input['coupon_code']??'')));$coupon=null;$discount=0.0;
    if($couponCode==='WELCOME10'){$discount=round($subtotal*.10,2);$coupon=['code'=>'WELCOME10','label'=>'10% welcome discount'];}
    $base=max(0,$subtotal-$discount);$shippingMethods=[['id'=>'swiss_post_priority','name'=>'Swiss Post Priority','price'=>$base>=500?0:9.0,'free_from'=>500],['id'=>'collection','name'=>'Collection from Ferry Telecom','price'=>0.0,'free_from'=>0]];
    $selected=(string)($input['shipping_method']??'swiss_post_priority');$shipping=0.0;$selectedValid=false;
    foreach($shippingMethods as $method)if($method['id']===$selected){$shipping=(float)$method['price'];$selectedValid=true;break;}
    if(!$selectedValid){$selected=$shippingMethods[0]['id'];$shipping=(float)$shippingMethods[0]['price'];}
    $total=round($base+$shipping,2);$tax=round($total*8.1/108.1,2);
    return ['currency'=>'CHF','items'=>$items,'totals'=>['subtotal'=>round($subtotal,2),'discount'=>$discount,'shipping'=>$shipping,'tax'=>$tax,'total'=>$total,'tax_inclusive'=>true],'coupon'=>$coupon,'shipping_methods'=>$shippingMethods,'selected_shipping_method'=>$selected,'validation_errors'=>$validation];
}
function store_categories(): array {
    return array_map(static fn(array $c):array => [
        'id'=>(int)$c['term_id'],'name'=>$c['name'],'slug'=>$c['slug'],
        'parent_id'=>(int)$c['parent'] ?: null,'products_count'=>(int)$c['count'],'count'=>(int)$c['count'],
    ],product_categories());
}
function store_models(): array {
    static $cached=null; if($cached!==null)return $cached;
    $cacheFile=dirname(__DIR__).'/storage/storefront-models-cache-v2.json';
    if(is_file($cacheFile)&&filemtime($cacheFile)>time()-86400){$disk=json_decode((string)file_get_contents($cacheFile),true);if(is_array($disk))return $cached=$disk;}
    $titles=rows("SELECT DISTINCT post_title FROM wp_posts WHERE post_type='product' AND post_status='publish' AND (post_title LIKE '%iPhone%' OR post_title LIKE '%Galaxy%' OR post_title LIKE '%iPad%' OR post_title LIKE '%MacBook%' OR post_title LIKE '%Apple Watch%' OR post_title LIKE '%Pixel%' OR post_title LIKE '%Huawei%' OR post_title LIKE '%Xiaomi%' OR post_title LIKE '%Redmi%' OR post_title LIKE '%OnePlus%' OR post_title LIKE '%HONOR%' OR post_title LIKE '%Honor%') ORDER BY post_title LIMIT 12000");
    $models=[];
    foreach($titles as $row) {
        $title=(string)$row['post_title'];$found=[];
        foreach([
            'iPhone'=>'/\\biPhone\\s+(?:SE(?:\\s*\\([^)]*\\))?|(?:[6-8]|1[0-9]|XR|XS?)(?:\\s*(?:Pro(?:\\s+Max)?|Plus|Mini|Air|Max|e))?)/i',
            'Samsung Galaxy'=>'/\\b(?:Samsung\\s+)?Galaxy\\s+((?:[SAZMJ]\\s?\\d{1,2}(?:\\s?(?:5G|FE|Ultra|Plus|Edge|Lite|Pro|Classic))?|Note\\s?\\d{1,2}(?:\\s?(?:Ultra|Plus|5G))?|XCover\\s?\\d{1,2}(?:\\s?Pro)?))/i',
            'iPad'=>'/\\biPad(?:\\s+(?:Pro|Air|Mini))?(?:\\s+\\d{1,2})?/i',
            'Apple Watch'=>'/\\bApple Watch(?:\\s+(?:Ultra|Series)\\s*\\d{0,2})?/i',
            'MacBook'=>'/\\bMacBook(?:\\s+(?:Pro|Air))?(?:\\s+\\d{1,2})?/i',
            'Google Pixel'=>'/\\b(?:Google\\s+)?Pixel\\s+(?:Fold|[0-9][a-zA-Z0-9 ]{0,18}?)(?=\\s(?:OLED|LCD|Screen|Display|Battery|Back|Housing|Camera|Charging|Flex|Assembly|Glass|Replacement|$|[,\\/(\\-]))/i',
            'Huawei'=>'/\\bHuawei\\s+(?:P|Mate|Nova|Y)\\s?\\d{1,2}(?:\\s?(?:Pro|Plus|Lite|5G))?/i',
            'Xiaomi'=>'/\\b(?:Xiaomi\\s+)?(?:Redmi\\s+(?:Note\\s?)?\\d{1,2}(?:\\s?(?:Pro|Plus|5G|S))?|Mi\\s?\\d{1,2}(?:\\s?(?:Pro|Ultra))?)/i',
            'OnePlus'=>'/\\bOnePlus\\s+(?:Nord\\s+)?\\d{1,2}(?:\\s?(?:Pro|T|R|5G))?/i',
            'HONOR'=>'/\\bHONOR\\s+(?:Magic\\s?)?\\d{1,2}(?:\\s?(?:Pro|Lite|X|5G))?/i'
        ] as $brand=>$pattern) if(preg_match_all($pattern,$title,$matches)) foreach($matches[0] as $match){$name=trim(preg_replace('/\\s+/',' ',ucwords(strtolower($match))));if($brand==='iPhone')$name=preg_replace('/^Iphone/','iPhone',$name);if($brand==='iPad')$name=preg_replace('/^Ipad/','iPad',$name);if($brand==='MacBook')$name=preg_replace('/^Macbook/','MacBook',$name);if($brand==='Google Pixel'&&!str_starts_with(strtolower($name),'google '))$name='Google '.$name;if($brand==='Samsung Galaxy'&&!str_starts_with(strtolower($name),'samsung '))$name='Samsung '.$name;$found[$name]=$brand;}
        foreach($found as $name=>$brand){$series=preg_match('/\\d{1,2}/',$name,$m)?trim($m[0].' Series'):'Other models';$family=$brand==='Samsung Galaxy'?'Galaxy '.(preg_match('/Galaxy\\s+Note/i',$name)?'Note':(preg_match('/Galaxy\\s+XCover/i',$name)?'XCover':(preg_match('/Galaxy\\s+([SAZMJ])/i',$name,$m)?strtoupper($m[1]):'S'))):$brand;$models[$name]=['name'=>$name,'brand'=>$brand,'family'=>$family,'series'=>$series,'url'=>path('/shop/'.slug($name))];}
    }
    $cached=array_values($models);usort($cached,static fn($a,$b)=>strnatcasecmp($b['name'],$a['name']));
    @file_put_contents($cacheFile,json_encode($cached,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX);return $cached;
}
function store_product(array $p): array {
    return [
        'id'=>(int)$p['ID'],'name'=>$p['post_title'],'slug'=>$p['post_name'],'sku'=>$p['sku']??$p['_sku']??'',
        'image'=>$p['image'],'price'=>array_key_exists('price',$p)?(is_numeric($p['price'])?(float)$p['price']:null):(isset($p['_price'])?(float)$p['_price']:null),
        'stock_quantity'=>is_numeric($p['stock']??$p['_stock']??null)?(int)($p['stock']??$p['_stock']):0,
        'stock'=>is_numeric($p['stock']??$p['_stock']??null)?(int)($p['stock']??$p['_stock']):0,
        'stock_status'=>$p['stock_status']??$p['_stock_status']??'instock','description'=>$p['post_content']??'',
        'short_description'=>$p['post_excerpt']??'','currency'=>'CHF',
        'price_role'=>$p['price_role']??null,'price_role_label'=>$p['price_role_label']??null,
        'minimum_order_qty'=>max(1,(int)($p['minimum_order_qty']??1)),'quantity_step'=>max(1,(int)($p['quantity_step']??1)),
    ];
}
function store_list(array $query,int $perPage=10): array {
    $categories=store_categories();
    $session=store_customer_session();
    $slug=(string)($query['category']??'');
    $matched=null;
    foreach($categories as $category) if($category['slug']===$slug || (string)$category['id']===$slug) {$matched=$category;break;}
    $categoryId=$matched['id']??0;
    $page=min(1000,max(1,(int)($query['page']??1)));
    $search=mb_substr(trim((string)($query['q']??'')),0,80);
    $limit=min(60,max(1,(int)($query['per_page']??$perPage)));
    $where="p.post_type='product' AND p.post_status='publish'";
    $params=[];
    if($search!=='') {$where.=' AND (p.post_title LIKE ? OR p.post_excerpt LIKE ?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';}
    if($categoryId>0) {$where.=" AND EXISTS (SELECT 1 FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy='product_cat' AND tt.term_id=?)";$params[]=$categoryId;}
    $inStock=($query['stock']??null)==='instock' || in_array('instock',(array)($query['stock']??[]),true);
    if($inStock) $where.=" AND EXISTS (SELECT 1 FROM wp_postmeta ss WHERE ss.post_id=p.ID AND ss.meta_key='_stock_status' AND ss.meta_value='instock')";
    $offset=($page-1)*$limit;
    $sort=(string)($query['sort']??'');
    // IDs follow import/creation order and use the posts primary key. Avoid sorting
    // the whole catalogue through a correlated metadata subquery on every request.
    $order=$sort==='name'?'p.post_title ASC, p.ID DESC':'p.ID DESC';
    $items=rows("SELECT p.ID,p.post_title,p.post_name,p.post_excerpt FROM wp_posts p WHERE $where ORDER BY $order LIMIT $limit OFFSET $offset",$params);
    if($items) {
        $ids=array_column($items,'ID');$marks=implode(',',array_fill(0,count($ids),'?'));
        $meta=rows("SELECT post_id,meta_key,meta_value FROM wp_postmeta WHERE post_id IN ($marks) AND (meta_key IN ('_sku','_price','_regular_price','_stock','_stock_status','_thumbnail_id') OR meta_key LIKE '%_wholesale_%')",$ids);
        $byId=[];foreach($meta as $entry)$byId[$entry['post_id']][$entry['meta_key']]=$entry['meta_value'];
        foreach($items as &$item){$m=$byId[$item['ID']]??[];$pricing=store_resolve_price($m,$session);$item['sku']=$m['_sku']??'';$item['price']=$pricing['price'];$item['price_role']=$pricing['role'];$item['price_role_label']=$pricing['role_label'];$item['minimum_order_qty']=$pricing['minimum_quantity'];$item['quantity_step']=$pricing['quantity_step'];$item['stock']=$m['_stock']??0;$item['stock_status']=$m['_stock_status']??'instock';$item['image']=catalog_product_image((int)$item['ID'],(string)$item['sku'],(int)($m['_thumbnail_id']??0));}unset($item);
    }
    $total=!empty($query['fast'])?count($items):(int)(row("SELECT COUNT(*) n FROM wp_posts p WHERE $where",$params)['n']??0);
    return ['items'=>array_map('store_product',$items),'total'=>$total,'page'=>$page,'per_page'=>$limit,'facets'=>[]];
}
function store_cart_prices(array $input,array $session): array {
    $ids=[];
    foreach(array_slice((array)($input['ids']??[]),0,100) as $rawId){$id=(int)$rawId;if($id>0&&!isset($ids[$id]))$ids[$id]=true;}
    if(!$ids)return ['currency'=>'CHF','items'=>[]];
    $orderedIds=array_keys($ids);$marks=implode(',',array_fill(0,count($orderedIds),'?'));
    $products=rows("SELECT ID,post_title,post_name,post_excerpt FROM wp_posts WHERE ID IN ($marks) AND post_type='product' AND post_status='publish'",$orderedIds);
    $metaRows=rows("SELECT post_id,meta_key,meta_value FROM wp_postmeta WHERE post_id IN ($marks) AND (meta_key IN ('_sku','_price','_regular_price','_stock','_stock_status','_thumbnail_id') OR meta_key LIKE '%_wholesale_%')",$orderedIds);
    $meta=[];foreach($metaRows as $entry)$meta[(int)$entry['post_id']][(string)$entry['meta_key']]=(string)$entry['meta_value'];
    $byId=[];
    foreach($products as $product){
        $id=(int)$product['ID'];$m=$meta[$id]??[];$pricing=store_resolve_price($m,$session);
        $byId[$id]=store_product([
            'ID'=>$id,'post_title'=>$product['post_title'],'post_name'=>$product['post_name'],'post_excerpt'=>$product['post_excerpt'],
            'sku'=>$m['_sku']??'','price'=>$pricing['price'],'price_role'=>$pricing['role'],'price_role_label'=>$pricing['role_label'],
            'minimum_order_qty'=>$pricing['minimum_quantity'],'quantity_step'=>$pricing['quantity_step'],
            'stock'=>$m['_stock']??0,'stock_status'=>$m['_stock_status']??'instock',
            'image'=>catalog_product_image($id,(string)($m['_sku']??''),(int)($m['_thumbnail_id']??0)),
        ]);
    }
    $items=[];foreach($orderedIds as $id)if(isset($byId[$id]))$items[]=$byId[$id];
    return ['currency'=>'CHF','items'=>$items];
}
function storefront_api_route(string $uri): never {
    $route=substr($uri,strlen('/api/store/'));
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    try {
        if($route==='customer-auth/me'&&$method==='GET') store_reply(store_customer_session());
        if($route==='customer-auth/login'&&$method==='POST') {
            $input=store_request_json();$identity=trim((string)($input['identity']??''));$password=(string)($input['password']??'');
            if($identity===''||$password===''||!login($identity,$password))store_reply('Invalid email, username, or password.',401);
            $_SESSION['store_customer_id']=(int)(current_user()['id']??0);store_reply(store_customer_session());
        }
        if($route==='customer-auth/logout'&&$method==='POST') {
            unset($_SESSION['store_customer_id']);if(!is_admin())unset($_SESSION['user']);session_regenerate_id(true);
            store_reply(['authenticated'=>false,'approved'=>false,'customer'=>null,'csrf'=>csrf_token()]);
        }
        if($route==='customer-auth/register'&&$method==='POST') {
            $input=store_request_json();$email=mb_strtolower(trim((string)($input['email']??'')));$username=trim((string)($input['username']??''));
            $password=(string)($input['password']??'');$confirm=(string)($input['password_confirm']??'');$country=strtoupper(trim((string)($input['country']??'CH')));
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!preg_match('/^[A-Za-z0-9._-]{3,60}$/',$username)||strlen($password)<10||$password!==$confirm||!preg_match('/^[A-Z]{2}$/',$country))store_reply('Check the email, username, country, and matching password fields.',422);
            if(row('SELECT ID FROM wp_users WHERE user_login=? OR user_email=? LIMIT 1',[$username,$email]))store_reply('That email or username is already registered.',409);
            db()->beginTransaction();try{
                exec_sql('INSERT INTO wp_users (user_login,user_pass,user_nicename,user_email,user_registered,user_status,display_name) VALUES (?,?,?,?,NOW(),0,?)',[$username,password_hash($password,PASSWORD_DEFAULT),slug($username),$email,trim((string)($input['first_name']??'').' '.(string)($input['last_name']??''))?:$username]);
                $id=(int)db()->lastInsertId();$meta=['wp_capabilities'=>serialize(['wwlc_unapproved'=>true]),'wp_user_level'=>'0','first_name'=>(string)($input['first_name']??''),'last_name'=>(string)($input['last_name']??''),'billing_phone'=>(string)($input['phone']??''),'billing_company'=>(string)($input['company']??''),'billing_country'=>$country,'billing_address_1'=>(string)($input['line1']??''),'billing_address_2'=>(string)($input['line2']??''),'billing_city'=>(string)($input['city']??''),'billing_state'=>(string)($input['state']??''),'billing_postcode'=>(string)($input['postcode']??''),'wwlc_requested_wholesale_role'=>(string)($input['business_type']??'customer'),'wwlc_cf_vat_id'=>(string)($input['vat_id']??''),'wwlc_cf_eori_number'=>(string)($input['eori_number']??'')];
                foreach($meta as $key=>$value)exec_sql('INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (?,?,?)',[$id,$key,$value]);db()->commit();
            }catch(Throwable $error){db()->rollBack();throw $error;}
            store_reply(['message'=>'Registration received. An administrator must approve the account before wholesale ordering is enabled.'],201);
        }
        if($route==='customer-auth/addresses'&&$method==='GET') {
            $session=store_require_customer();$address=store_address((int)$session['customer']['id']);
            store_reply(['items'=>$address?[$address]:[]]);
        }
        if($route==='customer-auth/addresses'&&$method==='POST') {
            $session=store_require_customer();$input=store_request_json();
            foreach(['name','phone','line1','city','postcode','country'] as $field)if(trim((string)($input[$field]??''))==='')store_reply('Complete all required address fields.',422);
            store_reply(store_save_address((int)$session['customer']['id'],$input),201);
        }
        if($route==='products/cart-prices'&&$method==='POST') {
            $session=store_require_customer();
            store_reply(store_cart_prices(store_request_json(),$session));
        }
        if(str_starts_with($route,'customer-auth/addresses/')&&$method==='DELETE') {
            $session=store_require_customer();$userId=(int)$session['customer']['id'];
            foreach(['billing','shipping'] as $prefix)foreach(['first_name','last_name','company','phone','address_1','address_2','city','state','postcode','country'] as $key)exec_sql('DELETE FROM wp_usermeta WHERE user_id=? AND meta_key=?',[$userId,$prefix.'_'.$key]);
            store_reply(['deleted'=>true]);
        }
        if($route==='checkout/bootstrap'&&$method==='GET') {
            $session=store_require_customer();$address=store_address((int)$session['customer']['id']);
            store_reply(['countries'=>['CH'=>'Switzerland','DE'=>'Germany','FR'=>'France','IT'=>'Italy','AT'=>'Austria','GB'=>'United Kingdom','US'=>'United States','NL'=>'Netherlands','BE'=>'Belgium','ES'=>'Spain'],'addresses'=>$address?[$address]:[],'customer'=>$session['customer'],'currency'=>'CHF','shipping_methods'=>[['id'=>'swiss_post_priority','name'=>'Swiss Post Priority','price'=>9,'free_from'=>500],['id'=>'collection','name'=>'Collection from Ferry Telecom','price'=>0,'free_from'=>0]],'tax'=>['label'=>'Swiss VAT','rate'=>8.1,'inclusive'=>true],'gateways'=>['invoice'=>true,'bank_transfer'=>true],'gateway_details'=>['invoice'=>['title'=>'Invoice','description'=>'Pay by invoice after your order is reviewed.','image_position'=>'before','mode'=>'offline'],'bank_transfer'=>['title'=>'Bank transfer','description'=>'Bank instructions are provided with your order confirmation.','image_position'=>'before','mode'=>'offline']]]);
        }
        if($route==='checkout/quote'&&$method==='POST') {
            $session=store_require_customer();store_reply(store_checkout_quote(store_request_json(),$session));
        }
        if($route==='checkout/orders'&&$method==='GET') {
            $session=store_require_customer();$userId=(int)$session['customer']['id'];
            $orders=rows("SELECT p.ID id,p.post_date created_at,p.post_status status FROM wp_postmeta cu STRAIGHT_JOIN wp_posts p ON p.ID=cu.post_id AND p.post_type='shop_order' WHERE cu.meta_key='_customer_user' AND cu.meta_value=? ORDER BY p.ID DESC LIMIT 100",[$userId]);
            if($orders){
                $ids=array_column($orders,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
                $metaRows=rows("SELECT post_id,meta_key,meta_value FROM wp_postmeta WHERE post_id IN ($marks) AND meta_key IN ('_order_total','_order_currency','_tracking_number','_tracking_provider')",$ids);
                $meta=[];foreach($metaRows as $entry)$meta[(int)$entry['post_id']][(string)$entry['meta_key']]=(string)$entry['meta_value'];
                foreach($orders as &$order){$id=(int)$order['id'];$m=$meta[$id]??[];$order['id']=$id;$order['order_no']='#'.$id;$order['total']=(float)($m['_order_total']??0);$order['currency']=$m['_order_currency']??'CHF';$order['tracking_number']=$m['_tracking_number']??'';$order['tracking_carrier']=$m['_tracking_provider']??'';$order['status']=str_replace(['wc-','-'],['',' '],(string)$order['status']);$order['created_at']=date('M j, Y',strtotime((string)$order['created_at']));}unset($order);
            }
            store_reply(['items'=>$orders]);
        }
        if($route==='checkout/place'&&$method==='POST') {
            $session=store_require_customer();$input=store_request_json();$userId=(int)$session['customer']['id'];$key=trim((string)($input['idempotency_key']??''));
            if(strlen($key)<12)store_reply('The checkout session expired. Refresh and try again.',422);
            $existing=row("SELECT post_id FROM wp_postmeta WHERE meta_key='_checkout_idempotency_key' AND meta_value=? LIMIT 1",[$key]);
            if($existing){$orderId=(int)$existing['post_id'];store_reply(['redirect'=>path('/account?pane=orders&payment=success'),'clear_cart'=>true,'order_no'=>'#'.$orderId,'instructions'=>'This local test order was already created. No duplicate was added.']);}
            $address=(array)($input['address']??[]);foreach(['name','phone','line1','city','postcode','country'] as $field)if(trim((string)($address[$field]??''))==='')store_reply('Complete all required delivery fields.',422);
            $payment=(string)($input['payment_method']??'');if(!in_array($payment,['invoice','bank_transfer'],true))store_reply('Choose an available payment method.',422);
            $quote=store_checkout_quote($input,$session);if($quote['validation_errors'])store_reply('Review product availability before placing the order.',409);
            if(($input['quote_currency']??'')!=='CHF'||abs((float)($input['quote_total']??-1)-(float)$quote['totals']['total'])>.009)store_reply('Prices changed. Please confirm the latest checkout total.',409);
            $paymentTitle=$payment==='invoice'?'Invoice':'Bank transfer';$now=date('Y-m-d H:i:s');$name=trim((string)$address['name']);$nameParts=preg_split('/\s+/', $name,2)?:[];
            db()->beginTransaction();
            try {
                exec_sql("INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[$userId,$now,gmdate('Y-m-d H:i:s'),'','Order', '', 'wc-on-hold','open','closed','','','','',$now,gmdate('Y-m-d H:i:s'),'',0,'',0,'shop_order','',0]);
                $orderId=(int)db()->lastInsertId();exec_sql('UPDATE wp_posts SET post_title=?,post_name=?,guid=? WHERE ID=?',['Order – '.$name.' – '.$now,'order-'.$orderId,path('/?post_type=shop_order&p='.$orderId),$orderId]);
                $orderMeta=['_customer_user'=>(string)$userId,'_order_key'=>'wc_order_'.bin2hex(random_bytes(8)),'_checkout_idempotency_key'=>$key,'_created_via'=>'checkout','_order_currency'=>'CHF','_order_total'=>number_format((float)$quote['totals']['total'],2,'.',''),'_order_tax'=>number_format((float)$quote['totals']['tax'],2,'.',''),'_shipping_total'=>number_format((float)$quote['totals']['shipping'],2,'.',''),'_cart_discount'=>number_format((float)$quote['totals']['discount'],2,'.',''),'_payment_method'=>$payment,'_payment_method_title'=>$paymentTitle,'_billing_first_name'=>$nameParts[0]??'','_billing_last_name'=>$nameParts[1]??'','_billing_company'=>(string)($address['company']??''),'_billing_address_1'=>(string)$address['line1'],'_billing_address_2'=>(string)($address['line2']??''),'_billing_city'=>(string)$address['city'],'_billing_state'=>(string)($address['state']??''),'_billing_postcode'=>(string)$address['postcode'],'_billing_country'=>strtoupper((string)$address['country']),'_billing_email'=>(string)$session['customer']['email'],'_billing_phone'=>(string)$address['phone'],'_shipping_first_name'=>$nameParts[0]??'','_shipping_last_name'=>$nameParts[1]??'','_shipping_company'=>(string)($address['company']??''),'_shipping_address_1'=>(string)$address['line1'],'_shipping_address_2'=>(string)($address['line2']??''),'_shipping_city'=>(string)$address['city'],'_shipping_state'=>(string)($address['state']??''),'_shipping_postcode'=>(string)$address['postcode'],'_shipping_country'=>strtoupper((string)$address['country'])];
                foreach($orderMeta as $metaKey=>$value)exec_sql('INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (?,?,?)',[$orderId,$metaKey,$value]);
                foreach($quote['items'] as $line){exec_sql('INSERT INTO wp_woocommerce_order_items (order_item_name,order_item_type,order_id) VALUES (?,?,?)',[$line['name'],'line_item',$orderId]);$itemId=(int)db()->lastInsertId();foreach(['_product_id'=>$line['id'],'_variation_id'=>0,'_qty'=>$line['qty'],'_tax_class'=>'','_line_subtotal'=>number_format((float)$line['line_total'],2,'.',''),'_line_subtotal_tax'=>'0','_line_total'=>number_format((float)$line['line_total'],2,'.',''),'_line_tax'=>'0'] as $metaKey=>$value)exec_sql('INSERT INTO wp_woocommerce_order_itemmeta (order_item_id,meta_key,meta_value) VALUES (?,?,?)',[$itemId,$metaKey,(string)$value]);}
                $selectedShipping=(string)$quote['selected_shipping_method'];$shippingName=$selectedShipping==='collection'?'Collection from Ferry Telecom':'Swiss Post Priority';exec_sql('INSERT INTO wp_woocommerce_order_items (order_item_name,order_item_type,order_id) VALUES (?,?,?)',[$shippingName,'shipping',$orderId]);$shippingItem=(int)db()->lastInsertId();foreach(['method_id'=>$selectedShipping,'instance_id'=>'0','cost'=>number_format((float)$quote['totals']['shipping'],2,'.',''),'total_tax'=>'0'] as $metaKey=>$value)exec_sql('INSERT INTO wp_woocommerce_order_itemmeta (order_item_id,meta_key,meta_value) VALUES (?,?,?)',[$shippingItem,$metaKey,(string)$value]);
                store_save_address($userId,$address);db()->commit();
            } catch(Throwable $error){db()->rollBack();throw $error;}
            $instructions=$payment==='invoice'?'Your invoice request is recorded. Our team will review the order before payment is due.':'Use the bank details in your order confirmation and include order #'.$orderId.' as the reference.';
            store_reply(['redirect'=>path('/account?pane=orders&payment=success'),'clear_cart'=>true,'order_no'=>'#'.$orderId,'instructions'=>$instructions],201);
        }
        if($method!=='GET') store_reply('This action is not connected in the local preview yet.',501);
        if($route==='settings/markets') store_reply(['countries'=>['CH'=>'Switzerland'],'default_country'=>'CH']);
        if($route==='settings') store_reply(['currency'=>'CHF']);
        if($route==='attributes') store_reply([]);
        if($route==='navigation/primary') store_reply(['items'=>[]]);
        if($route==='categories') store_reply(store_categories());
        if($route==='models') store_reply(store_models());
        if(str_starts_with($route,'categories/')) {
            $slug=rawurldecode(substr($route,11));
            foreach(store_categories() as $category) if($category['slug']===$slug) store_reply(['category'=>$category]);
            store_reply('Category not found',404);
        }
        if($route==='home') {
            $pricingSession=store_customer_session();
            $pricingKey=empty($pricingSession['approved'])?'guest':preg_replace('/[^a-z0-9_-]/i','-',(string)($pricingSession['customer']['customer_group']??'customer'));
            $cache=dirname(__DIR__).'/storage/storefront-home-cache-v2-'.$pricingKey.'.json';
            if(is_file($cache) && filemtime($cache)>time()-600) {
                $cached=json_decode((string)file_get_contents($cache),true);
                if(is_array($cached)) store_reply($cached);
            }
            $popular=store_list(['page'=>1,'per_page'=>20,'stock'=>'instock','fast'=>1]);
            $newest=store_list(['page'=>2,'per_page'=>10,'stock'=>'instock','fast'=>1]);
            $brands=[];
            $brands['apple-parts']=store_list(['page'=>1,'per_page'=>20,'q'=>'iPhone','stock'=>'instock','fast'=>1]);
            $brands['samsung-parts']=store_list(['page'=>1,'per_page'=>10,'q'=>'Samsung','stock'=>'instock','fast'=>1]);
            $total=(int)(row("SELECT COUNT(*) n FROM wp_posts WHERE post_type='product' AND post_status='publish'")['n']??0);
            $payload=['categories'=>store_categories(),'popular'=>$popular,'newest'=>$newest,'brands'=>$brands,'total'=>$total];
            @file_put_contents($cache,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
            store_reply($payload);
        }
        if($route==='products') store_reply(store_list($_GET));
        if(str_starts_with($route,'products/')) {
            $slug=rawurldecode(substr($route,9));
            $found=row("SELECT ID FROM wp_posts WHERE post_type='product' AND post_status='publish' AND post_name=? LIMIT 1",[$slug]);
            $product=$found?product((int)$found['ID']):null;
            if(!$product) store_reply('Product not found',404);
            $pricingSession=store_customer_session();$pricing=store_resolve_price($product,$pricingSession);
            $product['price']=$pricing['price'];$product['price_role']=$pricing['role'];$product['price_role_label']=$pricing['role_label'];$product['minimum_order_qty']=$pricing['minimum_quantity'];$product['quantity_step']=$pricing['quantity_step'];
            $prices=empty($pricingSession['approved'])||$pricing['price']===null?[]:[['price'=>$pricing['price'],'currency'=>$pricing['currency'],'role'=>$pricing['role'],'role_label'=>$pricing['role_label'],'minimum_quantity'=>$pricing['minimum_quantity'],'quantity_step'=>$pricing['quantity_step']]];
            store_reply(['product'=>store_product($product),'prices'=>$prices,'attribute_prices'=>[],'categories'=>$product['categories'],'attributes'=>[],'media'=>[]]);
        }
        if($route==='content-items') {
            $type=($_GET['type']??'post')==='page'?'page':'post';
            $items=rows("SELECT ID id,post_title title,post_name slug,post_excerpt excerpt,post_content content,post_date published_at FROM wp_posts WHERE post_type=? AND post_status='publish' ORDER BY post_date DESC LIMIT 12",[$type]);
            store_reply(['items'=>$items,'total'=>count($items)]);
        }
        if(str_starts_with($route,'content-items/')) {
            $slug=rawurldecode(substr($route,14));
            $post=row("SELECT ID id,post_type type,post_title title,post_name slug,post_excerpt excerpt,post_content content,post_date published_at FROM wp_posts WHERE post_name=? AND post_type IN ('post','page') AND post_status='publish' LIMIT 1",[$slug]);
            if(!$post) store_reply('Page not found',404);
            store_reply(['post'=>$post]);
        }
        store_reply('This frontend feature is not connected yet.',501);
    } catch(Throwable $e) {
        error_log((string)$e);
        store_reply('Storefront data is temporarily unavailable.',500);
    }
}
