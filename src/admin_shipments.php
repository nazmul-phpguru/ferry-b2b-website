<?php
declare(strict_types=1);

function tracking_items(int $orderId): array {
    $value=wp_meta($orderId,'_wc_shipment_tracking_items');
    $items=$value!==null?@unserialize($value,['allowed_classes'=>false]):[];
    return is_array($items)?array_values(array_filter($items,'is_array')):[];
}
function admin_shipments(): void {
    $orders=rows("SELECT p.ID,p.post_date,p.post_status,m.meta_value tracking FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wc_shipment_tracking_items' WHERE p.post_type='shop_order' ORDER BY p.ID DESC LIMIT 100");
    admin_layout('Shipment Tracking',static function() use($orders){
        ?><div class="heading"><div><div class="eyebrow">E-COMMERCE</div><h1>Shipment Tracking</h1><p>Latest 100 orders with tracking</p></div></div>
        <form class="product-filters" action="<?=h(path('/admin/shipment'))?>" method="get"><label>Order number<input name="order" type="number" min="1" required></label><button class="secondary">Open order tracking</button></form>
        <div class="panel table-wrap"><table><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Provider</th><th>Tracking number</th><th>Action</th></tr></thead><tbody><?php foreach($orders as $order): $items=@unserialize($order['tracking'],['allowed_classes'=>false]); if(!is_array($items))$items=[]; foreach($items as $item): if(!is_array($item))continue; ?><tr><td><a href="<?=h(path('/admin/order?id='.$order['ID']))?>">#<?=h($order['ID'])?></a></td><td><?=h($order['post_date'])?></td><td><?=h($order['post_status'])?></td><td><?=h(($item['custom_tracking_provider'] ?? '') ?: ($item['tracking_provider'] ?? ''))?></td><td><?=h($item['tracking_number'] ?? '')?></td><td><a href="<?=h(path('/admin/shipment?order='.$order['ID']))?>">Edit</a></td></tr><?php endforeach; endforeach; ?></tbody></table></div><?php
    });
}
function admin_shipment(int $orderId): void {
    $order=row("SELECT ID,post_status FROM wp_posts WHERE ID=? AND post_type='shop_order'",[$orderId]);
    if (!$order) { http_response_code(404); exit('Order not found.'); }
    $items=tracking_items($orderId);
    admin_layout('Shipment tracking #'.$orderId,static function() use($order,$items){
        ?><div class="heading"><div><div class="eyebrow">ORDER #<?=h($order['ID'])?></div><h1>Shipment Tracking</h1></div><a class="secondary" href="<?=h(path('/admin/order?id='.$order['ID']))?>">Back to order</a></div>
        <section class="panel"><h2>Tracking details</h2><div class="table-wrap"><table><thead><tr><th>Provider</th><th>Tracking number</th><th>Date shipped</th><th>Action</th></tr></thead><tbody><?php foreach($items as $index=>$item): ?><tr><td><?=h(($item['custom_tracking_provider'] ?? '') ?: ($item['tracking_provider'] ?? ''))?></td><td><?=h($item['tracking_number'] ?? '')?></td><td><?=!empty($item['date_shipped'])?h(date('Y-m-d',(int)$item['date_shipped'])):'—'?></td><td><form method="post" action="<?=h(path('/admin/shipment/delete'))?>" onsubmit="return confirm('Remove this tracking entry?')"><?=csrf_field()?><input type="hidden" name="order" value="<?=h($order['ID'])?>"><input type="hidden" name="index" value="<?=h($index)?>"><button class="secondary">Remove</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
        <form class="panel edit-form" method="post" action="<?=h(path('/admin/shipment'))?>"><?=csrf_field()?><input type="hidden" name="order" value="<?=h($order['ID'])?>"><h2>Add tracking</h2><label>Provider<input name="provider" maxlength="100" placeholder="UPS Global" required></label><label>Tracking number<input name="number" maxlength="120" required></label><label>Date shipped<input name="date" type="date" value="<?=h(date('Y-m-d'))?>" required></label><label>Tracking URL (optional)<input name="url" type="url" maxlength="500" placeholder="https://"></label><button class="primary">Save tracking</button></form><?php
    });
}
function save_tracking(): never {
    $orderId=(int)($_POST['order'] ?? 0);
    if (!row("SELECT ID FROM wp_posts WHERE ID=? AND post_type='shop_order'",[$orderId])) { http_response_code(404); exit('Order not found.'); }
    $provider=trim((string)($_POST['provider'] ?? ''));
    $number=trim((string)($_POST['number'] ?? ''));
    $date=(string)($_POST['date'] ?? '');
    $url=trim((string)($_POST['url'] ?? ''));
    if ($provider==='' || mb_strlen($provider)>100 || $number==='' || mb_strlen($number)>120 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || strtotime($date)===false || ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !str_starts_with($url,'https://')))) {
        notice('Check the tracking details.'); redirect('/admin/shipment?order='.$orderId);
    }
    $items=tracking_items($orderId); $before=$items;
    $items[]=['tracking_provider'=>'','custom_tracking_provider'=>$provider,'custom_tracking_link'=>$url,'tracking_number'=>$number,'tracking_product_code'=>'','date_shipped'=>(string)strtotime($date),'products_list'=>'','status_shipped'=>'1','tracking_id'=>bin2hex(random_bytes(16))];
    db()->beginTransaction();
    set_wp_meta($orderId,'_wc_shipment_tracking_items',serialize($items));
    audit_change('shipment',$orderId,'add',$before,$items);
    db()->commit(); notice('Tracking saved.'); redirect('/admin/shipment?order='.$orderId);
}
function delete_tracking(): never {
    $orderId=(int)($_POST['order'] ?? 0); $index=(int)($_POST['index'] ?? -1);
    $items=tracking_items($orderId);
    if (!isset($items[$index])) { http_response_code(404); exit('Tracking entry not found.'); }
    $before=$items; array_splice($items,$index,1);
    db()->beginTransaction();
    set_wp_meta($orderId,'_wc_shipment_tracking_items',serialize($items));
    audit_change('shipment',$orderId,'remove',$before,$items);
    db()->commit(); notice('Tracking removed.'); redirect('/admin/shipment?order='.$orderId);
}
