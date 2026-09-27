<?php
declare(strict_types=1);

function order_icon(string $name): string {
    return '<svg class="order-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#order-icon-'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'"></use></svg>';
}

function order_address_text(array $order, string $prefix): array {
    $name=trim(($order[$prefix.'_first_name'] ?? '').' '.($order[$prefix.'_last_name'] ?? ''));
    $company=trim((string)($order[$prefix.'_company'] ?? ''));
    $summary=trim(($company!==''?$company.' · ':'').$name);
    $lines=array_filter([
        $company,
        $name,
        trim(($order[$prefix.'_address_1'] ?? '').' '.($order[$prefix.'_address_2'] ?? '')),
        trim(($order[$prefix.'_postcode'] ?? '').' '.($order[$prefix.'_city'] ?? '')),
        trim(($order[$prefix.'_state'] ?? '').' '.($order[$prefix.'_country'] ?? '')),
        $prefix==='billing' ? ($order['billing_email'] ?? '') : '',
        $prefix==='billing' ? ($order['billing_phone'] ?? '') : '',
    ],static fn($value)=>trim((string)$value)!=='');
    return [$summary!==''?$summary:'—',array_values($lines)];
}

function admin_orders_list(): void {
    $status=(string)($_GET['status'] ?? '');
    if($status!=='' && $status!=='trash' && !preg_match('/^wc-[a-z-]+$/',$status)) $status='';
    $q=trim((string)($_GET['q'] ?? ''));
    $month=(string)($_GET['month'] ?? '');
    if($month!=='' && !preg_match('/^\d{6}$/',$month)) $month='';
    $role=(string)($_GET['role'] ?? '');
    if($role!=='' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/',$role)) $role='';
    $origin=(string)($_GET['origin'] ?? '');
    if(!in_array($origin,['','admin','checkout','store-api'],true)) $origin='';
    $payment=(string)($_GET['payment'] ?? '');
    if(mb_strlen($payment)>100)$payment='';
    $customerId=max(0,(int)($_GET['customer'] ?? 0));
    $shippingMethod=(string)($_GET['shipping'] ?? '');
    if(mb_strlen($shippingMethod)>150)$shippingMethod='';
    $date=(string)($_GET['date'] ?? '');
    if($date!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !strtotime($date)))$date='';
    $sort=(string)($_GET['sort'] ?? 'newest');
    if(!in_array($sort,['newest','oldest'],true))$sort='newest';
    $limit=(int)($_GET['per_page'] ?? 20);
    if(!in_array($limit,[10,20,50,100],true)) $limit=20;
    $page=max(1,(int)($_GET['page'] ?? 1));
    $offset=($page-1)*$limit;
    $where="p.post_type='shop_order'";
    $params=[];
    if($status!=='') {$where.=' AND p.post_status=?';$params[]=$status;}
    else $where.=" AND p.post_status<>'trash'";
    if($month!=='') {$where.=" AND DATE_FORMAT(p.post_date,'%Y%m')=?";$params[]=$month;}
    if($role!=='') {$where.=" AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_wwpp_wholesale_order_type' AND m.meta_value=?)";$params[]=$role;}
    if($origin!=='') {$where.=" AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_created_via' AND m.meta_value=?)";$params[]=$origin;}
    if($payment!=='') {$where.=" AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_payment_method' AND m.meta_value=?)";$params[]=$payment;}
    if($customerId>0) {$where.=" AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_customer_user' AND m.meta_value=?)";$params[]=(string)$customerId;}
    if($shippingMethod!=='') {$where.=" AND EXISTS (SELECT 1 FROM wp_woocommerce_order_items oi WHERE oi.order_id=p.ID AND oi.order_item_type='shipping' AND oi.order_item_name=?)";$params[]=$shippingMethod;}
    if($date!=='') {$where.=" AND DATE(p.post_date)=?";$params[]=$date;}
    if($q!=='') {
        $where.=" AND (p.ID=? OR EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key IN ('_billing_email','_billing_first_name','_billing_last_name','_billing_company','_wcpdf_invoice_number') AND m.meta_value LIKE ?))";
        $params[]=(int)ltrim($q,'#');$params[]='%'.$q.'%';
    }
    $count=(int)(row("SELECT COUNT(*) n FROM wp_posts p WHERE $where",$params)['n'] ?? 0);
    $keys=['_order_total'=>'total','_order_currency'=>'currency','_wcpdf_invoice_number'=>'invoice_number','_wcpdf_invoice_date'=>'invoice_created','_wcpdf_packing_slip_date'=>'packing_created','_ferry_invoice_pdf_downloaded'=>'invoice_downloaded','_ferry_packing_pdf_downloaded'=>'packing_downloaded','_wwpp_wholesale_order_type'=>'order_type','_created_via'=>'origin','_wc_shipment_tracking_items'=>'tracking'];
    foreach(['billing','shipping'] as $prefix) foreach(['first_name','last_name','company','address_1','address_2','city','state','postcode','country'] as $suffix) $keys['_'.$prefix.'_'.$suffix]=$prefix.'_'.$suffix;
    $keys['_billing_email']='billing_email';$keys['_billing_phone']='billing_phone';
    $fields=[];
    foreach($keys as $key=>$alias) $fields[]="(SELECT m.meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='$key' ORDER BY m.meta_id DESC LIMIT 1) $alias";
    $direction=$sort==='oldest'?'ASC':'DESC';
    $orders=rows('SELECT p.ID,p.post_date,p.post_status,'.implode(',',$fields)." FROM wp_posts p WHERE $where ORDER BY p.post_date $direction,p.ID $direction LIMIT $limit OFFSET $offset",$params);
    $statuses=rows("SELECT post_status,COUNT(*) n FROM wp_posts WHERE post_type='shop_order' AND post_status<>'trash' GROUP BY post_status ORDER BY n DESC");
    $trashCount=(int)(row("SELECT COUNT(*) n FROM wp_posts WHERE post_type='shop_order' AND post_status='trash'")['n'] ?? 0);
    $months=rows("SELECT DATE_FORMAT(post_date,'%Y%m') value,DATE_FORMAT(post_date,'%M %Y') label FROM wp_posts WHERE post_type='shop_order' GROUP BY value,label ORDER BY value DESC LIMIT 48");
    $roles=wholesale_roles();$roleNames=array_column($roles,'name','slug');
    $paymentMethods=rows("SELECT DISTINCT meta_value value FROM wp_postmeta WHERE meta_key='_payment_method' AND meta_value<>'' ORDER BY meta_value LIMIT 100");
    $shippingMethods=rows("SELECT DISTINCT order_item_name value FROM wp_woocommerce_order_items WHERE order_item_type='shipping' AND order_item_name<>'' ORDER BY order_item_name LIMIT 100");
    $registeredCustomers=rows("SELECT ID,display_name FROM wp_users WHERE ID IN (SELECT DISTINCT CAST(meta_value AS UNSIGNED) FROM wp_postmeta WHERE meta_key='_customer_user' AND meta_value REGEXP '^[0-9]+$' AND meta_value<>'0') ORDER BY display_name LIMIT 500");
    $urlParams=['q'=>$q,'status'=>$status,'month'=>$month,'role'=>$role,'origin'=>$origin,'payment'=>$payment,'customer'=>$customerId,'shipping'=>$shippingMethod,'date'=>$date,'sort'=>$sort,'per_page'=>$limit];
    admin_layout('Orders',static function() use($status,$q,$month,$role,$origin,$payment,$customerId,$shippingMethod,$date,$sort,$page,$limit,$count,$orders,$statuses,$trashCount,$months,$roles,$roleNames,$paymentMethods,$shippingMethods,$registeredCustomers,$urlParams) { ?>
    <div class="wp-posts-list wp-orders-list">
      <svg class="order-icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><defs>
        <symbol id="order-icon-check" viewBox="0 0 24 24"><path d="m5 12 4.5 4.5L19 7"/></symbol>
        <symbol id="order-icon-minus" viewBox="0 0 24 24"><path d="M5 12h14"/></symbol>
        <symbol id="order-icon-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></symbol>
        <symbol id="order-icon-chevron" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
        <symbol id="order-icon-invoice" viewBox="0 0 24 24"><path d="M6 2.75h8l4 4v14.5H6zM14 2.75v4h4M9 11h6M9 14h6M9 17h4"/></symbol>
        <symbol id="order-icon-packing" viewBox="0 0 24 24"><path d="M6 2.75h8l4 4v14.5H6zM14 2.75v4h4M9 11h6M9 14h2m2 0h2M9 17h2m2 0h2"/></symbol>
        <symbol id="order-icon-edit" viewBox="0 0 24 24"><path d="M4 20h16M5.5 15.5 15.9 5.1a2 2 0 0 1 2.8 0l.2.2a2 2 0 0 1 0 2.8L8.5 18.5l-4 .8zM14.4 6.6l3 3"/></symbol>
        <symbol id="order-icon-trash" viewBox="0 0 24 24"><path d="M4.5 7h15M9 7V4.5h6V7M7 7l.8 13h8.4L17 7M10 10v7M14 10v7"/></symbol>
        <symbol id="order-icon-restore" viewBox="0 0 24 24"><path d="M4.5 11.5a8 8 0 1 1 2.2 6M4.5 5.5v6h6"/></symbol>
        <symbol id="order-icon-screen" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16M8 4v4M15 10v4M11 16v4"/></symbol>
        <symbol id="order-icon-prev" viewBox="0 0 24 24"><path d="m15 5-7 7 7 7"/></symbol>
        <symbol id="order-icon-next" viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></symbol>
      </defs></svg>
      <div class="wp-list-top"><div class="wp-list-title"><h1>Orders</h1></div>
        <details class="wp-list-screen"><summary aria-label="Screen Options" title="Screen Options"><?=order_icon('screen')?></summary><div class="wp-list-screen-panel"><strong>Columns</strong>
          <?php foreach(['billing'=>'Billing','shipping'=>'Ship to','tracking'=>'Shipment tracking','type'=>'Order type','origin'=>'Origin'] as $key=>$label): ?><label><input type="checkbox" data-post-column="<?=h($key)?>" checked> <?=h($label)?></label><?php endforeach; ?>
          <strong>Pagination</strong><form action="<?=h(path('/admin/orders'))?>"><label>Orders per page <select name="per_page" onchange="this.form.submit()"><?php foreach([10,20,50,100] as $size): ?><option value="<?=$size?>" <?=$limit===$size?'selected':''?>><?=$size?></option><?php endforeach; ?></select></label></form>
        </div></details>
      </div>
      <nav class="wp-status-links" aria-label="Order statuses"><a class="<?=$status===''?'active':''?>" href="<?=h(path('/admin/orders'))?>">All <span>(<?=number_format(array_sum(array_column($statuses,'n')))?>)</span></a><?php foreach($statuses as $s): ?><a class="<?=$status===$s['post_status']?'active':''?>" href="<?=h(path('/admin/orders?status='.rawurlencode($s['post_status'])))?>"><?=h(ucwords(str_replace(['wc-','-'],['',' '],$s['post_status'])))?> <span>(<?=number_format((int)$s['n'])?>)</span></a><?php endforeach; if($trashCount): ?><a class="<?=$status==='trash'?'active':''?>" href="<?=h(path('/admin/orders?status=trash'))?>">Trash <span>(<?=number_format($trashCount)?>)</span></a><?php endif; ?></nav>
      <form class="orders-search-form" action="<?=h(path('/admin/orders'))?>"><input type="hidden" name="status" value="<?=h($status)?>"><label class="sr-only" for="orders-search">Search orders</label><input id="orders-search" name="q" value="<?=h($q)?>" placeholder="Order ID or customer"><button class="secondary">Search orders</button></form>
      <div class="orders-controls"><div class="orders-bulk"><label class="sr-only" for="orders-bulk-action">Bulk actions</label><select id="orders-bulk-action" name="action" form="orders-bulk-form"><option value="">Bulk actions</option><?php foreach(($status==='trash'?['restore'=>'Restore']:['wc-cancelled'=>'Mark cancelled','wc-on-hold'=>'Mark on hold','wc-processing'=>'Mark processing','wc-completed'=>'Mark completed','wc-awaiting-payment'=>'Mark awaiting payment','wc-paid'=>'Mark paid','wc-payment-reminder'=>'Mark payment reminder','trash'=>'Move to Trash']) as $value=>$label): ?><option value="<?=h($value)?>"><?=h($label)?></option><?php endforeach; ?></select><button class="secondary" type="submit" form="orders-bulk-form">Apply</button></div>
      <form class="orders-filter-form" action="<?=h(path('/admin/orders'))?>">
        <input type="hidden" name="status" value="<?=h($status)?>"><input type="hidden" name="q" value="<?=h($q)?>">
        <label class="sr-only" for="orders-date">Date</label><input id="orders-date" name="date" type="date" value="<?=h($date)?>" title="Filter by order date">
        <label class="sr-only" for="orders-role">Order type</label><select id="orders-role" name="role"><option value="">Show all order types</option><?php foreach($roles as $r): ?><option value="<?=h($r['slug'])?>" <?=$role===$r['slug']?'selected':''?>><?=h($r['name'])?></option><?php endforeach; ?></select>
        <label class="sr-only" for="orders-payment">Payment method</label><select id="orders-payment" name="payment"><option value="">All payment methods</option><?php foreach($paymentMethods as $method): ?><option value="<?=h($method['value'])?>" <?=$payment===$method['value']?'selected':''?>><?=h($method['value'])?></option><?php endforeach; ?></select>
        <label class="sr-only" for="orders-origin">Sales channel</label><select id="orders-origin" name="origin"><option value="">All sales channels</option><?php foreach(['admin'=>'Admin','checkout'=>'Checkout','store-api'=>'Store API'] as $value=>$label): ?><option value="<?=h($value)?>" <?=$origin===$value?'selected':''?>><?=h($label)?></option><?php endforeach; ?></select>
        <label class="sr-only" for="orders-customer">Registered customer</label><select id="orders-customer" name="customer"><option value="0">Filter by registered customer</option><?php foreach($registeredCustomers as $registered): ?><option value="<?=h($registered['ID'])?>" <?=$customerId===(int)$registered['ID']?'selected':''?>><?=h($registered['display_name'])?></option><?php endforeach; ?></select>
        <label class="sr-only" for="orders-shipping">Shipping provider</label><select id="orders-shipping" name="shipping"><option value="">Filter by shipping provider</option><?php foreach($shippingMethods as $method): ?><option value="<?=h($method['value'])?>" <?=$shippingMethod===$method['value']?'selected':''?>><?=h($method['value'])?></option><?php endforeach; ?></select>
        <button class="secondary">Filter</button><label class="sr-only" for="orders-sort">Sort</label><select id="orders-sort" name="sort" onchange="this.form.submit()"><option value="newest" <?=$sort==='newest'?'selected':''?>>Newest first</option><option value="oldest" <?=$sort==='oldest'?'selected':''?>>Oldest first</option></select>
      </form><a class="orders-export secondary" href="<?=h(path('/admin/orders/export?'.http_build_query($urlParams)))?>">Export to CSV</a></div>
      <div class="orders-list-meta"><span><?=number_format($count)?> orders · Page <?=$page?> of <?=max(1,(int)ceil($count/$limit))?></span><?php if($page>1): ?><a href="<?=h(path('/admin/orders?'.http_build_query($urlParams+['page'=>$page-1])))?>" aria-label="Previous page"><?=order_icon('prev')?></a><?php endif; ?><?php if($page*$limit<$count): ?><a href="<?=h(path('/admin/orders?'.http_build_query($urlParams+['page'=>$page+1])))?>" aria-label="Next page"><?=order_icon('next')?></a><?php endif; ?></div>
      <form id="orders-bulk-form" method="post" action="<?=h(path('/admin/orders/bulk'))?>"><?=csrf_field()?><input type="hidden" name="return_to" value="<?=h('/admin/orders'.(($_SERVER['QUERY_STRING'] ?? '')!==''?'?'.$_SERVER['QUERY_STRING']:''))?>">
        <div class="wp-list-table-wrap"><table class="wp-list-table orders-table"><thead><tr><th><input type="checkbox" aria-label="Select all orders" data-select-all-orders></th><th>Order ID/ Invoice ID</th><th>Date</th><th>Status</th><th data-post-col="billing">Billing</th><th data-post-col="shipping">Ship to</th><th>Total</th><th>Order tools</th><th data-post-col="tracking">Shipment tracking</th><th data-post-col="type">Order type</th><th data-post-col="origin">Origin</th><th>Action</th></tr></thead><tbody>
        <?php foreach($orders as $o): [$billingSummary,$billingLines]=order_address_text($o,'billing');[$shippingSummary,$shippingLines]=order_address_text($o,'shipping');$id=(int)$o['ID'];$statusLabel=ucwords(str_replace(['wc-','-'],['',' '],$o['post_status']));$statusClass=preg_replace('/[^a-z-]/','',substr($o['post_status'],3));$invoiceExists=trim((string)$o['invoice_created'])!=='' || !empty($o['invoice_downloaded']);$packingExists=trim((string)$o['packing_created'])!=='' || !empty($o['packing_downloaded']); ?>
          <tr>
            <td><input class="order-select" type="checkbox" name="ids[]" value="<?=$id?>" aria-label="Select order <?=$id?>"></td>
            <td class="order-id-cell"><strong><a href="<?=h(path('/admin/order?id='.$id))?>">#<?=$id?></a></strong><?php if(trim((string)$o['invoice_number'])!==''): ?><span class="order-invoice-inline"> / #<?=h($o['invoice_number'])?></span><?php endif; ?></td>
            <td class="order-date"><?=h(date('M j, Y',strtotime($o['post_date'])))?></td>
            <td><span class="order-status order-status-<?=h($statusClass)?>" title="<?=h($statusLabel)?>" aria-label="<?=h($statusLabel)?>"><span aria-hidden="true" class="order-status-symbol"><?=order_icon(in_array($statusClass,['paid','completed'],true)?'check':(in_array($statusClass,['cancelled','failed','refunded'],true)?'close':'minus'))?></span><span class="sr-only"><?=h($statusLabel)?></span></span></td>
            <?php foreach([['billing',$billingSummary,$billingLines],['shipping',$shippingSummary,$shippingLines]] as [$prefix,$summary,$lines]): ?><td data-post-col="<?=$prefix?>" class="order-address-cell"><button class="order-address-toggle" type="button" aria-expanded="false" aria-label="<?=h(ucfirst($prefix).' address for order #'.$id)?>"><span class="order-address-summary"><?=h($summary)?></span><span class="order-address-caret" aria-hidden="true"><?=order_icon('chevron')?></span></button><div class="order-address-details" hidden><?php foreach($lines as $line): ?><div><?=h($line)?></div><?php endforeach; ?><button type="button" class="order-address-close">Close</button></div></td><?php endforeach; ?>
            <td class="order-total"><?=currency($o['total'] ?? 0)?></td>
            <td><div class="order-actions order-tools"><?php $nextStatus=in_array($o['post_status'],['wc-pending','wc-on-hold'],true)?'wc-processing':($o['post_status']==='wc-processing'?'wc-completed':''); if($nextStatus!==''): $nextLabel=$nextStatus==='wc-processing'?'Processing':'Completed'; ?><button class="order-quick-status" type="submit" name="row_action" value="quick:<?=$id?>:<?=h($nextStatus)?>" title="Mark order #<?=$id?> as <?=$nextLabel?>" aria-label="Mark order #<?=$id?> as <?=$nextLabel?>"><?=order_icon('check')?></button><?php endif; ?><a class="order-pdf-button" href="<?=h(path('/admin/order/document?id='.$id.'&type=invoice'))?>" title="Download invoice PDF<?=$invoiceExists?' (created)':''?>" aria-label="Download invoice PDF for order #<?=$id?>"><?=order_icon('invoice')?><?php if($invoiceExists): ?><span class="order-pdf-check" aria-hidden="true"><?=order_icon('check')?></span><?php endif; ?></a><a class="order-pdf-button" href="<?=h(path('/admin/order/document?id='.$id.'&type=packing-slip'))?>" title="Download packing slip PDF<?=$packingExists?' (created)':''?>" aria-label="Download packing slip PDF for order #<?=$id?>"><?=order_icon('packing')?><?php if($packingExists): ?><span class="order-pdf-check" aria-hidden="true"><?=order_icon('check')?></span><?php endif; ?></a></div></td>
            <td data-post-col="tracking"><a href="<?=h(path('/admin/shipment?order='.$id))?>"><?=$o['tracking']?'Edit tracking':'Add tracking'?></a></td>
            <td data-post-col="type"><?=h($roleNames[$o['order_type']] ?? ($o['order_type'] ?: 'Retail'))?></td><td data-post-col="origin"><?=h($o['origin'] ?: '—')?></td><td><div class="order-actions order-row-actions"><a href="<?=h(path('/admin/order?id='.$id))?>" title="Edit order" aria-label="Edit order #<?=$id?>"><?=order_icon('edit')?></a><?php if($o['post_status']==='trash'): ?><button type="submit" name="row_action" value="restore:<?=$id?>" title="Restore order" aria-label="Restore order #<?=$id?>"><?=order_icon('restore')?></button><?php else: ?><button type="submit" name="row_action" value="trash:<?=$id?>" title="Move order to Trash" aria-label="Move order #<?=$id?> to Trash" onclick="return confirm('Move order #<?=$id?> to Trash?')"><?=order_icon('trash')?></button><?php endif; ?></div></td>
          </tr>
        <?php endforeach; if(!$orders): ?><tr><td colspan="12">No orders found.</td></tr><?php endif; ?>
        </tbody></table></div>
      </form>
      <?=admin_pages_nav('/admin/orders',$page,$count,$limit,$urlParams)?>
    </div><link rel="stylesheet" href="<?=h(path('/assets/posts-list.css'))?>"><link rel="stylesheet" href="<?=h(path('/assets/orders-list.css'))?>"><link rel="stylesheet" href="<?=h(path('/assets/orders-compact.css'))?>"><script src="<?=h(path('/assets/posts-list.js'))?>" defer></script><script src="<?=h(path('/assets/orders-list.js'))?>" defer></script>
    <?php });
}

function export_orders_csv(): never {
    $status=(string)($_GET['status'] ?? '');
    if($status!=='' && $status!=='trash' && !preg_match('/^wc-[a-z-]+$/',$status))$status='';
    $q=trim((string)($_GET['q'] ?? ''));
    $role=(string)($_GET['role'] ?? '');
    $origin=(string)($_GET['origin'] ?? '');
    $payment=(string)($_GET['payment'] ?? '');
    $customerId=max(0,(int)($_GET['customer'] ?? 0));
    $shipping=(string)($_GET['shipping'] ?? '');
    $date=(string)($_GET['date'] ?? '');
    $sort=(string)($_GET['sort'] ?? 'newest');
    $where="p.post_type='shop_order'";
    $params=[];
    if($status!==''){$where.=' AND p.post_status=?';$params[]=$status;}
    else $where.=" AND p.post_status<>'trash'";
    if($role!=='' && preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/',$role)){$where.=" AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_wwpp_wholesale_order_type' AND m.meta_value=?)";$params[]=$role;}
    if(in_array($origin,['admin','checkout','store-api'],true)){$where.=" AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_created_via' AND m.meta_value=?)";$params[]=$origin;}
    if($payment!=='' && mb_strlen($payment)<=100){$where.=" AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_payment_method' AND m.meta_value=?)";$params[]=$payment;}
    if($customerId>0){$where.=" AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_customer_user' AND m.meta_value=?)";$params[]=(string)$customerId;}
    if($shipping!=='' && mb_strlen($shipping)<=150){$where.=" AND EXISTS (SELECT 1 FROM wp_woocommerce_order_items oi WHERE oi.order_id=p.ID AND oi.order_item_type='shipping' AND oi.order_item_name=?)";$params[]=$shipping;}
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) && strtotime($date)){$where.=" AND DATE(p.post_date)=?";$params[]=$date;}
    if($q!==''){$where.=" AND (p.ID=? OR EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key IN ('_billing_email','_billing_first_name','_billing_last_name','_billing_company','_wcpdf_invoice_number') AND m.meta_value LIKE ?))";$params[]=(int)ltrim($q,'#');$params[]='%'.$q.'%';}
    $direction=$sort==='oldest'?'ASC':'DESC';
    $meta=static fn(string $key): string=>"(SELECT m.meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='$key' ORDER BY m.meta_id DESC LIMIT 1)";
    $fields=['_wcpdf_invoice_number'=>'invoice_id','_billing_company'=>'billing_company','_billing_first_name'=>'billing_first','_billing_last_name'=>'billing_last','_billing_email'=>'billing_email','_shipping_company'=>'shipping_company','_shipping_first_name'=>'shipping_first','_shipping_last_name'=>'shipping_last','_order_total'=>'total','_order_currency'=>'currency','_payment_method'=>'payment_method','_created_via'=>'origin'];
    $columns=[];foreach($fields as $key=>$alias)$columns[]=$meta($key).' '.$alias;
    $query=db()->prepare('SELECT p.ID,p.post_date,p.post_status,'.implode(',',$columns)." FROM wp_posts p WHERE $where ORDER BY p.post_date $direction,p.ID $direction");
    $query->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="orders-'.date('Y-m-d').'.csv"');
    header('X-Content-Type-Options: nosniff');
    $output=fopen('php://output','wb');
    fwrite($output,"\xEF\xBB\xBF");
    fputcsv($output,['Order ID','Invoice ID','Date','Status','Billing company','Billing name','Billing email','Shipping company','Shipping name','Total','Currency','Payment method','Origin'],',','"','');
    while($item=$query->fetch(PDO::FETCH_ASSOC)) {
        $values=[$item['ID'],$item['invoice_id'],$item['post_date'],$item['post_status'],$item['billing_company'],trim(($item['billing_first'] ?? '').' '.($item['billing_last'] ?? '')),$item['billing_email'],$item['shipping_company'],trim(($item['shipping_first'] ?? '').' '.($item['shipping_last'] ?? '')),$item['total'],$item['currency'],$item['payment_method'],$item['origin']];
        $values=array_map(static function($value): string {$value=(string)$value;return preg_match('/^[\s]*[=+\-@]/u',$value)?"'".$value:$value;},$values);
        fputcsv($output,$values,',','"','');
    }
    fclose($output);
    exit;
}
