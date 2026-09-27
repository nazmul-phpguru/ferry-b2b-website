<?php
declare(strict_types=1);

function admin_comments(): void {
    $status=(string)($_GET['status'] ?? 'all'); if(!in_array($status,['all','pending','approved','spam','trash'],true)) $status='all';
    $q=trim((string)($_GET['q'] ?? '')); $page=max(1,(int)($_GET['page'] ?? 1));
    $limit=(int)($_GET['per_page'] ?? 20); if(!in_array($limit,[10,20,50,100],true)) $limit=20; $offset=($page-1)*$limit;
    $base="FROM wp_comments c JOIN wp_posts p ON p.ID=c.comment_post_ID WHERE c.comment_type IN ('','comment','review') AND p.post_type IN ('post','page','product')";
    $counts=rows("SELECT c.comment_approved,COUNT(*) n $base GROUP BY c.comment_approved"); $totals=[]; foreach($counts as $item) $totals[$item['comment_approved']]=(int)$item['n'];
    $where=$base; $params=[];
    if($status==='pending') $where.=" AND c.comment_approved='0'";
    elseif($status==='approved') $where.=" AND c.comment_approved='1'";
    elseif($status==='spam') $where.=" AND c.comment_approved='spam'";
    elseif($status==='trash') $where.=" AND c.comment_approved='trash'";
    else $where.=" AND c.comment_approved NOT IN ('spam','trash')";
    if($q!=='') { $where.=' AND (c.comment_content LIKE ? OR c.comment_author LIKE ? OR c.comment_author_email LIKE ? OR p.post_title LIKE ?)'; for($i=0;$i<4;$i++) $params[]='%'.$q.'%'; }
    $count=(int)(row("SELECT COUNT(*) n $where",$params)['n'] ?? 0);
    $items=rows("SELECT c.comment_ID,c.comment_post_ID,c.comment_author,c.comment_author_email,c.comment_author_url,c.comment_content,c.comment_approved,c.comment_date,c.comment_type,c.comment_parent,p.post_title,p.post_type FROM wp_comments c JOIN wp_posts p ON p.ID=c.comment_post_ID WHERE ".substr($where,strpos($where,' WHERE ')+7)." ORDER BY c.comment_date DESC,c.comment_ID DESC LIMIT $limit OFFSET $offset",$params);
    admin_layout('Comments',static function() use($status,$q,$page,$limit,$totals,$count,$items){ $all=($totals['0'] ?? 0)+($totals['1'] ?? 0); ?>
      <div class="wp-posts-list wp-comments-page" data-list-kind="comments"><div class="wp-list-top"><div class="wp-list-title"><h1>Comments</h1></div><details class="wp-list-screen"><summary aria-label="Screen Options" title="Screen Options"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2" fill="white"/><circle cx="15" cy="12" r="2" fill="white"/><circle cx="10" cy="17" r="2" fill="white"/></svg></summary><div class="wp-list-screen-panel"><strong>Columns</strong><?php foreach(['response'=>'In response to','date'=>'Submitted on'] as $key=>$label): ?><label><input type="checkbox" data-post-column="<?=h($key)?>" checked> <?=h($label)?></label><?php endforeach; ?><strong>Pagination</strong><form action="<?=h(path('/admin/comments'))?>"><input type="hidden" name="status" value="<?=h($status)?>"><input type="hidden" name="q" value="<?=h($q)?>"><label>Comments per page <select name="per_page" onchange="this.form.submit()"><?php foreach([10,20,50,100] as $number): ?><option value="<?=$number?>" <?=$limit===$number?'selected':''?>><?=$number?></option><?php endforeach; ?></select></label></form></div></details></div>
      <nav class="wp-status-links" aria-label="Comment statuses"><a class="<?=$status==='all'?'active':''?>" href="<?=h(path('/admin/comments'))?>">All <span>(<?=$all?>)</span></a><?php foreach(['pending'=>'Pending','approved'=>'Approved','spam'=>'Spam','trash'=>'Trash'] as $key=>$label): $value=['pending'=>'0','approved'=>'1','spam'=>'spam','trash'=>'trash'][$key]; if(!($totals[$value] ?? 0) && $status!==$key) continue; ?><a class="<?=$status===$key?'active':''?>" href="<?=h(path('/admin/comments?status='.$key))?>"><?=h($label)?> <span>(<?=h($totals[$value] ?? 0)?>)</span></a><?php endforeach; ?></nav>
      <form class="wp-list-filter" action="<?=h(path('/admin/comments'))?>"><input type="hidden" name="status" value="<?=h($status)?>"><input type="hidden" name="per_page" value="<?=$limit?>"><div class="wp-list-search"><label class="sr-only" for="comment-search">Search comments</label><input id="comment-search" name="q" value="<?=h($q)?>"><button class="secondary">Search Comments</button></div></form>
      <form method="post" action="<?=h(path('/admin/comments/bulk'))?>" id="comment-actions"><?=csrf_field()?><div class="wp-list-actions"><select name="action" aria-label="Bulk actions"><option value="">Bulk actions</option><option value="approve">Approve</option><option value="unapprove">Unapprove</option><option value="spam">Mark as Spam</option><option value="trash">Move to Trash</option><?php if(in_array($status,['spam','trash'],true)): ?><option value="restore">Restore</option><option value="delete">Delete Permanently</option><?php endif; ?></select><button class="secondary">Apply</button><span><?=number_format($count)?> <?=$count===1?'item':'items'?></span></div><div class="wp-list-table-wrap"><table class="wp-list-table comment-table"><thead><tr><th><input type="checkbox" aria-label="Select all comments" onclick="document.querySelectorAll('.comment-select').forEach(box=>box.checked=this.checked)"></th><th>Author</th><th>Comment</th><th data-post-col="response">In response to</th><th data-post-col="date">Submitted on</th></tr></thead><tbody>
      <?php foreach($items as $item): ?><tr><td><input class="comment-select" type="checkbox" name="ids[]" value="<?=h($item['comment_ID'])?>" aria-label="Select comment by <?=h($item['comment_author'])?>"></td><td><strong><?=h($item['comment_author'] ?: 'Anonymous')?></strong><small><?=h($item['comment_author_email'])?></small></td><td><div class="comment-excerpt"><?=h(mb_strimwidth((string)$item['comment_content'],0,400,'…'))?></div><div class="row-actions"><?php if($item['comment_approved']==='0'): ?><button name="row_action" value="approve:<?=h($item['comment_ID'])?>">Approve</button><?php elseif($item['comment_approved']==='1'): ?><button name="row_action" value="unapprove:<?=h($item['comment_ID'])?>">Unapprove</button><?php endif; ?><a href="<?=h(path('/admin/comment?id='.$item['comment_ID']))?>">Edit</a><?php if(in_array($item['comment_approved'],['spam','trash'],true)): ?><button name="row_action" value="restore:<?=h($item['comment_ID'])?>">Restore</button><button name="row_action" value="delete:<?=h($item['comment_ID'])?>" onclick="return confirm('Permanently delete this comment?')">Delete Permanently</button><?php else: ?><button type="button" data-reply-comment="<?=h($item['comment_ID'])?>" data-reply-post="<?=h($item['comment_post_ID'])?>">Reply</button><button name="row_action" value="spam:<?=h($item['comment_ID'])?>">Spam</button><button name="row_action" value="trash:<?=h($item['comment_ID'])?>">Trash</button><?php endif; ?></div></td><td data-post-col="response"><a href="<?=h(path(($item['post_type']==='page'?'/admin/page?id=':($item['post_type']==='product'?'/admin/product?id=':'/admin/post?id=')).$item['comment_post_ID']))?>"><?=h($item['post_title'] ?: '(no title)')?></a><small><?=h($item['post_type'])?></small></td><td data-post-col="date"><?=h($item['comment_date'])?></td></tr><?php endforeach; if(!$items): ?><tr><td colspan="5">No comments found.</td></tr><?php endif; ?></tbody></table></div></form><?=admin_pages_nav('/admin/comments',$page,$count,$limit,['q'=>$q,'status'=>$status,'per_page'=>$limit])?></div>
      <dialog id="comment-reply-dialog" class="comment-reply-dialog"><h2>Reply to Comment</h2><form method="post" action="<?=h(path('/admin/comments/reply'))?>"><?=csrf_field()?><input type="hidden" name="parent" id="reply-parent"><input type="hidden" name="post_id" id="reply-post"><label>Reply<textarea name="content" rows="7" required></textarea></label><div><button class="primary" type="submit">Post Reply</button><button class="secondary" type="button" id="close-comment-reply">Cancel</button></div></form></dialog><link rel="stylesheet" href="<?=h(path('/assets/posts-list.css'))?>"><link rel="stylesheet" href="<?=h(path('/assets/comments.css'))?>"><script src="<?=h(path('/assets/posts-list.js'))?>" defer></script><script src="<?=h(path('/assets/comments.js'))?>" defer></script><?php
    });
}

function admin_comment(int $id): void {
    $comment=row("SELECT c.comment_ID,c.comment_post_ID,c.comment_author,c.comment_author_email,c.comment_author_url,c.comment_content,c.comment_approved,c.comment_date,p.post_title FROM wp_comments c JOIN wp_posts p ON p.ID=c.comment_post_ID WHERE c.comment_ID=? AND c.comment_type IN ('','comment','review') AND p.post_type IN ('post','page','product')",[$id]);
    if(!$comment) { http_response_code(404); exit('Comment not found.'); }
    admin_layout('Edit comment',static function() use($comment){ ?><div class="wp-posts-list comment-edit"><div class="wp-list-top"><div class="wp-list-title"><h1>Edit Comment</h1><a class="secondary" href="<?=h(path('/admin/comments'))?>">Back to Comments</a></div></div><form method="post" action="<?=h(path('/admin/comment'))?>" class="comment-edit-form"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($comment['comment_ID'])?>"><label>Author<input name="author" maxlength="245" value="<?=h($comment['comment_author'])?>" required></label><label>Email<input name="email" type="email" maxlength="100" value="<?=h($comment['comment_author_email'])?>"></label><label>URL<input name="url" type="url" maxlength="200" value="<?=h($comment['comment_author_url'])?>"></label><label>Comment<textarea name="content" rows="12" required><?=h($comment['comment_content'])?></textarea></label><label>Status<select name="status"><option value="1" <?=$comment['comment_approved']==='1'?'selected':''?>>Approved</option><option value="0" <?=$comment['comment_approved']==='0'?'selected':''?>>Pending</option><option value="spam" <?=$comment['comment_approved']==='spam'?'selected':''?>>Spam</option><option value="trash" <?=$comment['comment_approved']==='trash'?'selected':''?>>Trash</option></select></label><p>In response to: <strong><?=h($comment['post_title'])?></strong> · <?=h($comment['comment_date'])?></p><button class="primary">Update Comment</button></form></div><link rel="stylesheet" href="<?=h(path('/assets/posts-list.css'))?>"><link rel="stylesheet" href="<?=h(path('/assets/comments.css'))?>"><?php });
}

function refresh_comment_count(int $postId): void {
    exec_sql("UPDATE wp_posts SET comment_count=(SELECT COUNT(*) FROM wp_comments WHERE comment_post_ID=? AND comment_approved='1' AND comment_type IN ('','comment','review')) WHERE ID=?",[$postId,$postId]);
}

function save_admin_comment(): never {
    $id=max(0,(int)($_POST['id'] ?? 0));
    $before=row("SELECT c.comment_ID,c.comment_post_ID,c.comment_author,c.comment_approved FROM wp_comments c JOIN wp_posts p ON p.ID=c.comment_post_ID WHERE c.comment_ID=? AND c.comment_type IN ('','comment','review') AND p.post_type IN ('post','page','product')",[$id]);
    if(!$before) { http_response_code(404); exit('Comment not found.'); }
    $author=trim((string)($_POST['author'] ?? '')); $email=trim((string)($_POST['email'] ?? '')); $url=trim((string)($_POST['url'] ?? '')); $content=trim((string)($_POST['content'] ?? '')); $status=(string)($_POST['status'] ?? '');
    if($author==='' || $content==='' || !in_array($status,['0','1','spam','trash'],true) || ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) || ($url!=='' && !filter_var($url,FILTER_VALIDATE_URL))) { notice('Check the comment fields.'); redirect('/admin/comment?id='.$id); }
    db()->beginTransaction();
    try {
      exec_sql('UPDATE wp_comments SET comment_author=?,comment_author_email=?,comment_author_url=?,comment_content=?,comment_approved=? WHERE comment_ID=?',[$author,$email,$url,$content,$status,$id]);
      refresh_comment_count((int)$before['comment_post_ID']);
      audit_change('comment',$id,'update',$before,['author'=>$author,'status'=>$status]);
      db()->commit(); notice('Comment updated.');
    } catch(Throwable $error) { db()->rollBack(); error_log((string)$error); notice('Could not update comment.'); }
    redirect('/admin/comment?id='.$id);
}

function bulk_admin_comments(): never {
    $rowAction=(string)($_POST['row_action'] ?? ''); $action=(string)($_POST['action'] ?? ''); $ids=$_POST['ids'] ?? [];
    if($rowAction!=='' && preg_match('/^(approve|unapprove|spam|trash|restore|delete):(\d+)$/',$rowAction,$matches)) { $action=$matches[1]; $ids=[(int)$matches[2]]; }
    if(!is_array($ids) || !in_array($action,['approve','unapprove','spam','trash','restore','delete'],true)) { notice('Choose a comment action.'); redirect('/admin/comments'); }
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));
    if(!$ids || count($ids)>100) { notice('Select up to 100 comments.'); redirect('/admin/comments'); }
    db()->beginTransaction();
    try {
      $posts=[];
      foreach($ids as $id) {
        $comment=row("SELECT c.comment_ID,c.comment_post_ID,c.comment_approved FROM wp_comments c JOIN wp_posts p ON p.ID=c.comment_post_ID WHERE c.comment_ID=? AND c.comment_type IN ('','comment','review') AND p.post_type IN ('post','page','product') FOR UPDATE",[$id]);
        if(!$comment) continue; $posts[(int)$comment['comment_post_ID']]=true;
        if($action==='delete') {
          if(!in_array($comment['comment_approved'],['spam','trash'],true)) continue;
          exec_sql('DELETE FROM wp_commentmeta WHERE comment_id=?',[$id]);
          exec_sql('UPDATE wp_comments SET comment_parent=0 WHERE comment_parent=?',[$id]);
          exec_sql('DELETE FROM wp_comments WHERE comment_ID=?',[$id]);
          audit_change('comment',$id,'delete',$comment,[]);
        } else {
          $new=match($action) { 'approve'=>'1','unapprove'=>'0','spam'=>'spam','trash'=>'trash','restore'=>'0' };
          if($action==='restore' && !in_array($comment['comment_approved'],['spam','trash'],true)) continue;
          exec_sql('UPDATE wp_comments SET comment_approved=? WHERE comment_ID=?',[$new,$id]);
          audit_change('comment',$id,'status',$comment,['status'=>$new]);
        }
      }
      foreach(array_keys($posts) as $postId) refresh_comment_count((int)$postId);
      db()->commit(); notice('Comment action completed.');
    } catch(Throwable $error) { db()->rollBack(); error_log((string)$error); notice('Could not update comments.'); }
    redirect('/admin/comments');
}

function reply_admin_comment(): never {
    $parent=max(0,(int)($_POST['parent'] ?? 0)); $postId=max(0,(int)($_POST['post_id'] ?? 0)); $content=trim((string)($_POST['content'] ?? ''));
    $original=row("SELECT c.comment_post_ID,p.post_type FROM wp_comments c JOIN wp_posts p ON p.ID=c.comment_post_ID WHERE c.comment_ID=? AND c.comment_type IN ('','comment','review') AND p.post_type IN ('post','page','product')",[$parent]);
    if(!$original || (int)$original['comment_post_ID']!==$postId || $content==='') { notice('Check the reply.'); redirect('/admin/comments'); }
    $user=current_user(); $name=trim((string)($user['name'] ?? 'Administrator')); $email=(string)($user['email'] ?? '');
    db()->beginTransaction();
    try {
      exec_sql("INSERT INTO wp_comments (comment_post_ID,comment_author,comment_author_email,comment_author_url,comment_author_IP,comment_date,comment_date_gmt,comment_content,comment_karma,comment_approved,comment_agent,comment_type,comment_parent,user_id) VALUES (?,?,?,'','',NOW(),UTC_TIMESTAMP(),?,0,'1','','',?,?)",[$postId,$name,$email,$content,$parent,(int)($user['id'] ?? 0)]);
      $id=(int)db()->lastInsertId(); refresh_comment_count($postId);
      audit_change('comment',$id,'create',[],['post_id'=>$postId,'parent'=>$parent]);
      db()->commit(); notice('Reply posted.');
    } catch(Throwable $error) { db()->rollBack(); error_log((string)$error); notice('Could not post reply.'); }
    redirect('/admin/comments');
}
