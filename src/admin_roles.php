<?php
declare(strict_types=1);

function wholesale_roles(bool $includeInactive=false): array {
    return rows('SELECT id,slug,name,description,active FROM app_wholesale_roles'.($includeInactive?'':' WHERE active=1').' ORDER BY id');
}
function admin_roles(): void {
    $roles=wholesale_roles(true);
    $editId=(int)($_GET['edit'] ?? 0); $edit=null;
    foreach ($roles as $role) if ((int)$role['id']===$editId) $edit=$role;
    $counts=rows("SELECT meta_value,COUNT(*) n FROM wp_usermeta WHERE meta_key='wp_capabilities' GROUP BY meta_value");
    $roleCounts=[];
    foreach ($counts as $entry) {
        $assigned=@unserialize($entry['meta_value'],['allowed_classes'=>false]);
        if (is_array($assigned)) foreach (array_keys(array_filter($assigned)) as $slug) $roleCounts[$slug]=($roleCounts[$slug] ?? 0)+(int)$entry['n'];
    }
    admin_layout('Wholesale roles',static function() use($roles,$edit,$roleCounts){
        ?><div class="heading"><div><div class="eyebrow">WHOLESALE</div><h1>Roles</h1><p>Create and manage customer pricing roles</p></div></div>
        <div class="two-col"><section class="panel"><h2><?= $edit?'Edit role':'Add role' ?></h2>
        <form method="post" action="<?=h(path('/admin/role'))?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($edit['id'] ?? 0)?>">
        <label>Role name<input name="name" maxlength="120" value="<?=h($edit['name'] ?? '')?>" required></label>
        <label>Role key <small>Used in product price fields. Set once when creating a role.</small><input name="slug" maxlength="64" pattern="[A-Za-z][A-Za-z0-9_]{2,63}" value="<?=h($edit['slug'] ?? '')?>" <?=$edit?'readonly':''?> required></label>
        <label>Description<textarea name="description" rows="4"><?=h($edit['description'] ?? '')?></textarea></label>
        <?php if($edit): ?><label>Status<select name="active"><option value="1" <?=$edit['active']?'selected':''?>>Active</option><option value="0" <?=$edit['active']?'':'selected'?>>Inactive</option></select></label><?php endif; ?>
        <button class="primary">Save role</button></form></section>
        <section class="panel table-wrap"><table><thead><tr><th>Role</th><th>Key</th><th>Customers</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($roles as $role): ?><tr><td><a href="<?=h(path('/admin/roles?edit='.$role['id']))?>"><?=h($role['name'])?></a></td><td><?=h($role['slug'])?></td><td><?=h($roleCounts[$role['slug']] ?? 0)?></td><td><?=$role['active']?'Active':'Inactive'?></td><td><?php if($role['active']): ?><form method="post" action="<?=h(path('/admin/role/delete'))?>" onsubmit="return confirm('Remove this role from active pricing? Existing product prices are preserved.');"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($role['id'])?>"><button class="secondary" type="submit">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section></div><?php
    });
}
function save_role(): never {
    $id=(int)($_POST['id'] ?? 0); $name=trim((string)($_POST['name'] ?? ''));
    $slug=trim((string)($_POST['slug'] ?? '')); $description=trim((string)($_POST['description'] ?? ''));
    if ($name==='' || !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/',$slug)) { notice('Check the role name and key.'); redirect('/admin/roles'.($id?'?edit='.$id:'')); }
    $old=$id?row('SELECT id,slug,name,description,active FROM app_wholesale_roles WHERE id=?',[$id]):null;
    if ($id && !$old) { http_response_code(404); exit('Role not found.'); }
    if ($old && $slug!==$old['slug']) { http_response_code(400); exit('A role key cannot be changed after creation.'); }
    $active=$old?(int)(($_POST['active'] ?? '1')==='1'):1;
    if ($old && !$active && (int)$old['active']===1) {
        $assigned=role_assignment_count($slug);
        if ($assigned>0) { notice("Reassign $assigned customers before deactivating this role."); redirect('/admin/roles?edit='.$id); }
    }
    db()->beginTransaction();
    try {
        if ($old) exec_sql('UPDATE app_wholesale_roles SET name=?,description=?,active=? WHERE id=?',[$name,$description,$active,$id]);
        else { exec_sql('INSERT INTO app_wholesale_roles (slug,name,description) VALUES (?,?,?)',[$slug,$name,$description]); $id=(int)db()->lastInsertId(); }
        audit_change('wholesale_role',$id,$old?'update':'create',$old ?? [],['slug'=>$slug,'name'=>$name,'description'=>$description,'active'=>$active]);
        db()->commit(); notice('Role saved.');
    } catch (Throwable $error) { db()->rollBack(); error_log((string)$error); notice('Could not save role. Check that its key is unique.'); }
    redirect('/admin/roles');
}
function role_assignment_count(string $slug): int {
    return (int)(row("SELECT COUNT(*) n FROM wp_usermeta WHERE meta_key='wp_capabilities' AND meta_value LIKE ?",['%"'.$slug.'";b:1%'])['n'] ?? 0);
}
function delete_role(): never {
    $id=(int)($_POST['id'] ?? 0);
    $old=row('SELECT id,slug,name,description,active FROM app_wholesale_roles WHERE id=?',[$id]);
    if (!$old) { http_response_code(404); exit('Role not found.'); }
    $assigned=role_assignment_count($old['slug']);
    if ($assigned>0) { notice("Reassign $assigned customers before deleting this role."); redirect('/admin/roles'); }
    db()->beginTransaction();
    try {
        exec_sql('UPDATE app_wholesale_roles SET active=0 WHERE id=?',[$id]);
        audit_change('wholesale_role',$id,'deactivate',$old,['active'=>0]);
        db()->commit(); notice('Role removed from active pricing. Existing prices are preserved.');
    } catch (Throwable $error) {
        db()->rollBack(); error_log((string)$error); notice('Could not remove this role.');
    }
    redirect('/admin/roles');
}
