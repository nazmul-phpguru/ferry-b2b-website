<?php
declare(strict_types=1);

function admin_post_terms(string $taxonomy): void {
    if(!in_array($taxonomy,['category','post_tag'],true)) { http_response_code(404); return; }
    $isCategory=$taxonomy==='category'; $label=$isCategory?'Categories':'Tags'; $singular=$isCategory?'Category':'Tag';
    $search=trim((string)($_GET['q'] ?? '')); $page=max(1,(int)($_GET['page'] ?? 1));
    $limit=(int)($_GET['per_page'] ?? 20); if(!in_array($limit,[10,20,50,100],true)) $limit=20;
    $where='tt.taxonomy=?'; $params=[$taxonomy];
    if($search!=='') { $where.=' AND (t.name LIKE ? OR t.slug LIKE ? OR tt.description LIKE ?)'; array_push($params,'%'.$search.'%','%'.$search.'%','%'.$search.'%'); }
    $count=(int)(row("SELECT COUNT(*) n FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE $where",$params)['n'] ?? 0);
    $offset=($page-1)*$limit;
    $terms=rows("SELECT t.term_id,t.name,t.slug,tt.term_taxonomy_id,tt.description,tt.parent,tt.count FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE $where ORDER BY t.name LIMIT $limit OFFSET $offset",$params);
    $parents=$isCategory?rows("SELECT t.term_id,t.name,tt.parent FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy='category' ORDER BY t.name"):[];
    $editId=max(0,(int)($_GET['edit'] ?? 0));
    $edit=$editId?row('SELECT t.term_id,t.name,t.slug,tt.description,tt.parent FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE t.term_id=? AND tt.taxonomy=?',[$editId,$taxonomy]):null;
    $defaultCategory=$isCategory?(int)(row("SELECT option_value FROM wp_options WHERE option_name='default_category' LIMIT 1")['option_value'] ?? 0):0;
    admin_layout($label,static function() use($taxonomy,$label,$singular,$isCategory,$search,$page,$limit,$count,$terms,$parents,$edit,$defaultCategory){ ?>
      <div class="wp-terms-page"><div class="wp-terms-head"><h1><?=h($label)?></h1><details class="wp-list-screen"><summary aria-label="Screen Options" title="Screen Options"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2" fill="white"/><circle cx="15" cy="12" r="2" fill="white"/><circle cx="10" cy="17" r="2" fill="white"/></svg></summary><div class="wp-list-screen-panel"><strong>Columns</strong><?php foreach(['description'=>'Description','slug'=>'Slug','count'=>'Count'] as $key=>$text): ?><label><input type="checkbox" data-term-column="<?=h($key)?>" checked> <?=h($text)?></label><?php endforeach; ?><strong>Pagination</strong><form action="<?=h(path('/admin/taxonomy'))?>"><input type="hidden" name="taxonomy" value="<?=h($taxonomy)?>"><input type="hidden" name="q" value="<?=h($search)?>"><label>Number of items per page <select name="per_page" onchange="this.form.submit()"><?php foreach([10,20,50,100] as $number): ?><option value="<?=$number?>" <?=$limit===$number?'selected':''?>><?=$number?></option><?php endforeach; ?></select></label></form></div></details></div>
      <div class="wp-terms-grid"><section class="wp-term-create"><h2><?=$edit?'Edit':'Add New'?> <?=h($singular)?></h2><form method="post" action="<?=h(path('/admin/taxonomy'))?>"><?=csrf_field()?><input type="hidden" name="taxonomy" value="<?=h($taxonomy)?>"><input type="hidden" name="id" value="<?=h($edit['term_id'] ?? 0)?>"><label>Name<input name="name" maxlength="200" value="<?=h($edit['name'] ?? '')?>" required></label><p>The name is how it appears on your site.</p><label>Slug<input name="slug" maxlength="200" value="<?=h($edit['slug'] ?? '')?>"></label><p>The slug is the URL-friendly version of the name. Leave blank to generate it automatically.</p><?php if($isCategory): ?><label>Parent Category<select name="parent"><option value="0">None</option><?php foreach($parents as $parent): if($edit && (int)$edit['term_id']===(int)$parent['term_id']) continue; ?><option value="<?=h($parent['term_id'])?>" <?=($edit['parent'] ?? 0)==$parent['term_id']?'selected':''?>><?=h($parent['name'])?></option><?php endforeach; ?></select></label><p>Categories can have a hierarchy.</p><?php endif; ?><label>Description<textarea name="description" rows="5"><?=h($edit['description'] ?? '')?></textarea></label><p>The description is optional.</p><button class="primary" type="submit"><?=$edit?'Update':'Add New'?> <?=h($singular)?></button><?php if($edit): ?><a class="secondary" href="<?=h(path('/admin/taxonomy?taxonomy='.$taxonomy))?>">Cancel</a><?php endif; ?></form></section>
      <section class="wp-terms-table-area"><form class="wp-term-search" action="<?=h(path('/admin/taxonomy'))?>"><input type="hidden" name="taxonomy" value="<?=h($taxonomy)?>"><label class="sr-only" for="term-search">Search <?=h($label)?></label><input id="term-search" name="q" value="<?=h($search)?>"><button class="secondary">Search <?=h($label)?></button></form><form method="post" action="<?=h(path('/admin/post-terms/action'))?>" id="post-term-actions"><?=csrf_field()?><input type="hidden" name="taxonomy" value="<?=h($taxonomy)?>"><div class="wp-list-actions"><select name="action" aria-label="Bulk actions"><option value="">Bulk actions</option><option value="delete">Delete</option></select><button class="secondary" type="submit">Apply</button><span><?=number_format($count)?> items</span></div><div class="wp-list-table-wrap"><table class="wp-list-table wp-term-table"><thead><tr><th><input type="checkbox" aria-label="Select all <?=h($label)?>" onclick="document.querySelectorAll('.term-select').forEach(box=>box.checked=this.checked)"></th><th>Name</th><th data-term-col="description">Description</th><th data-term-col="slug">Slug</th><th data-term-col="count">Count</th></tr></thead><tbody>
      <?php foreach($terms as $term): ?><tr><td><input class="term-select" type="checkbox" name="ids[]" value="<?=h($term['term_id'])?>" aria-label="Select <?=h($term['name'])?>"></td><td><a href="<?=h(path('/admin/taxonomy?taxonomy='.$taxonomy.'&edit='.$term['term_id']))?>"><strong><?=h($term['name'])?></strong></a><div class="row-actions"><a href="<?=h(path('/admin/taxonomy?taxonomy='.$taxonomy.'&edit='.$term['term_id']))?>">Edit</a><button type="button" data-quick-term="<?=h($term['term_id'])?>">Quick Edit</button><?php if(!$isCategory || (int)$term['term_id']!==$defaultCategory): ?><button name="row_action" value="delete:<?=h($term['term_id'])?>" onclick="return confirm('Delete this <?=h(strtolower($singular))?>?')">Delete</button><?php endif; ?><a href="<?=h(path('/admin/posts?'.($isCategory?'category':'tag').'='.$term['term_id']))?>">View posts</a></div></td><td data-term-col="description"><?=h($term['description'] ?: '—')?></td><td data-term-col="slug"><?=h($term['slug'])?></td><td data-term-col="count"><a href="<?=h(path('/admin/posts?'.($isCategory?'category':'tag').'='.$term['term_id']))?>"><?=h($term['count'])?></a></td></tr><tr class="quick-edit-row" id="quick-term-<?=h($term['term_id'])?>" hidden><td colspan="5"><div class="quick-grid"><label>Name<input name="quick[<?=h($term['term_id'])?>][name]" value="<?=h($term['name'])?>"></label><label>Slug<input name="quick[<?=h($term['term_id'])?>][slug]" value="<?=h($term['slug'])?>"></label><button class="primary" name="row_action" value="quick:<?=h($term['term_id'])?>">Update <?=h($singular)?></button><button class="secondary" type="button" data-cancel-quick>Cancel</button></div></td></tr><?php endforeach; if(!$terms): ?><tr><td colspan="5">No <?=h(strtolower($label))?> found.</td></tr><?php endif; ?></tbody></table></div></form><?=admin_pages_nav('/admin/taxonomy',$page,$count,$limit,['taxonomy'=>$taxonomy,'q'=>$search,'per_page'=>$limit])?></section></div></div><link rel="stylesheet" href="<?=h(path('/assets/posts-list.css'))?>"><link rel="stylesheet" href="<?=h(path('/assets/post-terms.css'))?>"><script src="<?=h(path('/assets/post-terms.js'))?>" defer></script><?php
    });
}

function post_term_action(): never {
    $taxonomy=(string)($_POST['taxonomy'] ?? '');
    if(!in_array($taxonomy,['category','post_tag'],true)) { http_response_code(400); exit('Invalid taxonomy.'); }
    $rowAction=(string)($_POST['row_action'] ?? ''); $action=(string)($_POST['action'] ?? ''); $ids=$_POST['ids'] ?? [];
    if($rowAction!=='' && preg_match('/^(delete|quick):(\d+)$/',$rowAction,$parts)) { $action=$parts[1]; $ids=[(int)$parts[2]]; }
    if(!in_array($action,['delete','quick'],true) || !is_array($ids)) { notice('Choose an action.'); redirect('/admin/taxonomy?taxonomy='.$taxonomy); }
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));
    if(!$ids || count($ids)>100) { notice('Select up to 100 items.'); redirect('/admin/taxonomy?taxonomy='.$taxonomy); }
    $defaultCategory=$taxonomy==='category'?(int)(row("SELECT option_value FROM wp_options WHERE option_name='default_category' LIMIT 1")['option_value'] ?? 0):0;
    db()->beginTransaction();
    try {
      foreach($ids as $id) {
        $term=row('SELECT t.term_id,t.name,t.slug,tt.term_taxonomy_id,tt.parent FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE t.term_id=? AND tt.taxonomy=? FOR UPDATE',[$id,$taxonomy]);
        if(!$term) continue;
        if($action==='quick') {
          $input=$_POST['quick'][$id] ?? []; $name=trim((string)($input['name'] ?? '')); $slug=slug((string)($input['slug'] ?? '')) ?: slug($name);
          if($name==='' || $slug==='') throw new InvalidArgumentException('Name and slug are required.');
          exec_sql('UPDATE wp_terms SET name=?,slug=? WHERE term_id=?',[$name,$slug,$id]);
          audit_change('taxonomy',$id,'update',$term,['name'=>$name,'slug'=>$slug,'taxonomy'=>$taxonomy]);
        } else {
          if($taxonomy==='category' && $id===$defaultCategory) throw new InvalidArgumentException('The default category cannot be deleted.');
          $ttid=(int)$term['term_taxonomy_id'];
          if($taxonomy==='category' && $defaultCategory) {
            $default=row("SELECT term_taxonomy_id FROM wp_term_taxonomy WHERE taxonomy='category' AND term_id=?",[$defaultCategory]);
            if($default) {
              $postIds=array_column(rows("SELECT r.object_id FROM wp_term_relationships r JOIN wp_posts p ON p.ID=r.object_id AND p.post_type='post' WHERE r.term_taxonomy_id=?",[$ttid]),'object_id');
              foreach($postIds as $postId) {
                $other=row("SELECT r.object_id FROM wp_term_relationships r JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=? AND tt.taxonomy='category' AND r.term_taxonomy_id<>? LIMIT 1",[$postId,$ttid]);
                if(!$other) exec_sql('INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (?,?,0)',[$postId,$default['term_taxonomy_id']]);
              }
              exec_sql('UPDATE wp_term_taxonomy SET parent=? WHERE taxonomy=\'category\' AND parent=?',[(int)$term['parent'],$id]);
            }
          }
          exec_sql('DELETE FROM wp_term_relationships WHERE term_taxonomy_id=?',[$ttid]);
          exec_sql('DELETE FROM wp_term_taxonomy WHERE term_taxonomy_id=?',[$ttid]);
          if(!row('SELECT term_taxonomy_id FROM wp_term_taxonomy WHERE term_id=? LIMIT 1',[$id])) exec_sql('DELETE FROM wp_terms WHERE term_id=?',[$id]);
          audit_change('taxonomy',$id,'delete',$term,['taxonomy'=>$taxonomy]);
        }
      }
      exec_sql("UPDATE wp_term_taxonomy tt SET count=(SELECT COUNT(*) FROM wp_term_relationships r WHERE r.term_taxonomy_id=tt.term_taxonomy_id) WHERE tt.taxonomy=?",[$taxonomy]);
      db()->commit(); notice($action==='quick'?'Term updated.':'Terms deleted.');
    } catch(Throwable $error) { db()->rollBack(); error_log((string)$error); notice($error instanceof InvalidArgumentException?$error->getMessage():'Could not update terms.'); }
    redirect('/admin/taxonomy?taxonomy='.$taxonomy);
}
