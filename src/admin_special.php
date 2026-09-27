<?php
declare(strict_types=1);

function admin_email_log(): void {
    $page=max(1,(int)($_GET['page'] ?? 1)); $limit=40; $offset=($page-1)*$limit;
    $count=(int)(row('SELECT COUNT(*) n FROM wp_wpml_mails')['n'] ?? 0);
    $mails=rows("SELECT mail_id,timestamp,receiver,subject,error FROM wp_wpml_mails ORDER BY mail_id DESC LIMIT $limit OFFSET $offset");
    admin_layout('Email Log',static function() use($mails,$count,$page,$limit){ ?><div class="heading"><div><div class="eyebrow">MAIL</div><h1>Email Log</h1><p><?=number_format($count)?> saved messages</p></div></div><div class="panel table-wrap"><table><thead><tr><th>Date</th><th>Recipient</th><th>Subject</th><th>Status</th></tr></thead><tbody><?php foreach($mails as $mail): ?><tr><td><?=h($mail['timestamp'])?></td><td><?=h($mail['receiver'])?></td><td><a href="<?=h(path('/admin/email?id='.$mail['mail_id']))?>"><?=h($mail['subject'])?></a></td><td><?=$mail['error']?'Error':'Logged'?></td></tr><?php endforeach; ?></tbody></table></div><?=admin_pages_nav('/admin/email-log',$page,$count,$limit)?><?php });
}
function admin_email(int $id): void {
    $mail=row('SELECT mail_id,timestamp,receiver,subject,message,headers,error FROM wp_wpml_mails WHERE mail_id=?',[$id]);
    if (!$mail) { http_response_code(404); exit('Email not found.'); }
    admin_layout('Email #'.$id,static function() use($mail){ ?><div class="heading"><div><div class="eyebrow">EMAIL #<?=h($mail['mail_id'])?></div><h1><?=h($mail['subject'])?></h1><p><?=h($mail['timestamp'])?></p></div><a class="secondary" href="<?=h(path('/admin/email-log'))?>">Back to email log</a></div><section class="panel"><dl class="facts"><dt>Recipient</dt><dd><?=h($mail['receiver'])?></dd><dt>Error</dt><dd><?=h($mail['error'] ?: 'None')?></dd></dl><h2>Message</h2><pre class="email-content"><?=h($mail['message'])?></pre></section><?php });
}
function admin_scheduled_actions(): void {
    $status=(string)($_GET['status'] ?? ''); if (!in_array($status,['','pending','complete','failed','canceled','in-progress'],true)) $status='';
    $page=max(1,(int)($_GET['page'] ?? 1)); $limit=40; $offset=($page-1)*$limit;
    $where=$status?'WHERE status=?':''; $params=$status?[$status]:[];
    $count=(int)(row("SELECT COUNT(*) n FROM wp_actionscheduler_actions $where",$params)['n'] ?? 0);
    $actions=rows("SELECT action_id,hook,status,scheduled_date_local,attempts,last_attempt_local FROM wp_actionscheduler_actions $where ORDER BY action_id DESC LIMIT $limit OFFSET $offset",$params);
    $counts=rows('SELECT status,COUNT(*) n FROM wp_actionscheduler_actions GROUP BY status');
    admin_layout('Scheduled Actions',static function() use($status,$actions,$counts,$count,$page,$limit){ ?><div class="heading"><div><div class="eyebrow">TOOLS</div><h1>Scheduled Actions</h1><p><?=number_format($count)?> actions</p></div></div><div class="filters"><a class="<?=$status===''?'active':''?>" href="<?=h(path('/admin/scheduled-actions'))?>">All</a><?php foreach($counts as $entry): ?><a class="<?=$status===$entry['status']?'active':''?>" href="<?=h(path('/admin/scheduled-actions?status='.rawurlencode($entry['status'])))?>"><?=h(ucfirst($entry['status']))?> <span><?=h($entry['n'])?></span></a><?php endforeach; ?></div><div class="panel table-wrap"><table><thead><tr><th>ID</th><th>Hook</th><th>Status</th><th>Scheduled</th><th>Attempts</th><th>Last attempt</th></tr></thead><tbody><?php foreach($actions as $action): ?><tr><td><?=h($action['action_id'])?></td><td><?=h($action['hook'])?></td><td><?=h($action['status'])?></td><td><?=h($action['scheduled_date_local'])?></td><td><?=h($action['attempts'])?></td><td><?=h($action['last_attempt_local'])?></td></tr><?php endforeach; ?></tbody></table></div><?=admin_pages_nav('/admin/scheduled-actions',$page,$count,$limit,['status'=>$status])?><?php });
}
function admin_invoices(string $kind): void {
    $qr=$kind==='qr'; $key=$qr?'_qr_invoice_status':'_wcpdf_invoice_number'; $title=$qr?'QR Invoices':'PDF Invoices';
    $page=max(1,(int)($_GET['page'] ?? 1)); $limit=40; $offset=($page-1)*$limit;
    $count=(int)(row("SELECT COUNT(DISTINCT post_id) n FROM wp_postmeta WHERE meta_key=?",[$key])['n'] ?? 0);
    $invoices=rows("SELECT p.ID,p.post_date,p.post_status,m.meta_value invoice_value,
        (SELECT t.meta_value FROM wp_postmeta t WHERE t.post_id=p.ID AND t.meta_key='_order_total' ORDER BY t.meta_id DESC LIMIT 1) total
        FROM wp_postmeta m JOIN wp_posts p ON p.ID=m.post_id AND p.post_type='shop_order' WHERE m.meta_key=? ORDER BY p.ID DESC LIMIT $limit OFFSET $offset",[$key]);
    admin_layout($title,static function() use($title,$qr,$invoices,$count,$page,$limit){ ?><div class="heading"><div><div class="eyebrow">E-COMMERCE</div><h1><?=h($title)?></h1><p><?=number_format($count)?> orders with invoice records</p></div></div><div class="panel table-wrap"><table><thead><tr><th>Order</th><th>Date</th><th>Order status</th><th><?=$qr?'QR status':'Invoice number'?></th><th>Total</th></tr></thead><tbody><?php foreach($invoices as $invoice): ?><tr><td><a href="<?=h(path('/admin/order?id='.$invoice['ID']))?>">#<?=h($invoice['ID'])?></a></td><td><?=h($invoice['post_date'])?></td><td><?=h($invoice['post_status'])?></td><td><?=h($invoice['invoice_value'])?></td><td><?=currency($invoice['total'] ?? 0)?></td></tr><?php endforeach; ?></tbody></table></div><?=admin_pages_nav($qr?'/admin/qr-invoices':'/admin/pdf-invoices',$page,$count,$limit)?><?php });
}
function admin_leads(): void {
    $leads=rows("SELECT u.ID,u.user_login,u.user_email,u.display_name,u.user_registered FROM wp_users u JOIN wp_usermeta m ON m.user_id=u.ID AND m.meta_key='wp_capabilities' AND m.meta_value LIKE '%\"wwlc_unapproved\";b:1%' ORDER BY u.ID DESC LIMIT 100");
    admin_layout('Leads',static function() use($leads){ ?><div class="heading"><div><div class="eyebrow">WHOLESALE</div><h1>Leads</h1><p><?=count($leads)?> unapproved accounts</p></div></div><div class="panel table-wrap"><table><thead><tr><th>Username</th><th>Name</th><th>Email</th><th>Registered</th><th>Action</th></tr></thead><tbody><?php foreach($leads as $lead): ?><tr><td><?=h($lead['user_login'])?></td><td><?=h($lead['display_name'])?></td><td><?=h($lead['user_email'])?></td><td><?=h($lead['user_registered'])?></td><td><a href="<?=h(path('/admin/user?id='.$lead['ID']))?>">Review</a></td></tr><?php endforeach; ?></tbody></table></div><?php });
}
