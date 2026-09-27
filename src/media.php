<?php
declare(strict_types=1);

function serve_default_product_media(): never {
    $file=dirname(__DIR__).'/public/assets/img/no-image.svg';
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Content-Length: '.filesize($file));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=300');
    readfile($file);
    exit;
}

function serve_product_media(int $productId): never {
    $record=row("SELECT p.post_status,m.meta_value attached_file FROM wp_posts p
        JOIN wp_postmeta thumb ON thumb.post_id=p.ID AND thumb.meta_key='_thumbnail_id'
        JOIN wp_postmeta m ON m.post_id=CAST(thumb.meta_value AS UNSIGNED) AND m.meta_key='_wp_attached_file'
        WHERE p.ID=? AND p.post_type='product' LIMIT 1",[$productId]);
    if(!$record || ($record['post_status']!=='publish' && !is_admin())) serve_default_product_media();
    $relative=str_replace('\\','/',ltrim($record['attached_file'],'/\\'));
    if($relative==='' || str_contains($relative,'..') || str_contains($relative,"\0")) serve_default_product_media();
    $root=realpath(dirname(__DIR__).'/storage/private-uploads');
    $file=realpath(dirname(__DIR__).'/storage/private-uploads/'.$relative);
    if(!$root || !$file || !str_starts_with(strtolower($file),strtolower($root.DIRECTORY_SEPARATOR)) || !is_file($file)) serve_default_product_media();
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
    if(!in_array($mime,['image/jpeg','image/png','image/webp','image/avif','image/bmp'],true)) serve_default_product_media();
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($file));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    readfile($file);
    exit;
}
function serve_admin_attachment(int $attachmentId): never {
    require_admin();
    $record=row("SELECT m.meta_value attached_file FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wp_attached_file' WHERE p.ID=? AND p.post_type='attachment' AND p.post_mime_type LIKE 'image/%' LIMIT 1",[$attachmentId]);
    if(!$record) { http_response_code(404); exit; }
    $relative=str_replace('\\','/',ltrim($record['attached_file'],'/\\'));
    if($relative==='' || str_contains($relative,'..') || str_contains($relative,"\0")) { http_response_code(404); exit; }
    $root=realpath(dirname(__DIR__).'/storage/private-uploads');
    $file=realpath(dirname(__DIR__).'/storage/private-uploads/'.$relative);
    if(!$root || !$file || !str_starts_with(strtolower($file),strtolower($root.DIRECTORY_SEPARATOR)) || !is_file($file)) { http_response_code(404); exit; }
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
    if(!in_array($mime,['image/jpeg','image/png','image/webp','image/avif'],true)) { http_response_code(415); exit; }
    header('Content-Type: '.$mime); header('Content-Length: '.filesize($file));
    header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600');
    readfile($file); exit;
}

function upload_admin_media(): never {
    $upload=$_FILES['image'] ?? null;
    if(!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) json_response(['error'=>'Choose an image to upload.'],400);
    $size=(int)($upload['size'] ?? 0);
    if($size<1 || $size>12*1024*1024) json_response(['error'=>'Image must be under 12 MB.'],400);
    $tmp=(string)$upload['tmp_name'];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $info=@getimagesize($tmp);
    if($mime!=='image/webp' || !$info || $info[2]!==IMAGETYPE_WEBP || $info[0]<1 || $info[1]<1 || $info[0]>8000 || $info[1]>8000) json_response(['error'=>'Upload a valid WebP image.'],400);
    $title=trim((string)($_POST['title'] ?? ''));
    if($title==='') $title=pathinfo((string)($upload['name'] ?? 'image'),PATHINFO_FILENAME);
    $title=mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u','',$title) ?? ''),0,200);
    if($title==='') $title='Image';
    $alt=mb_substr(trim((string)($_POST['alt'] ?? '')),0,500);
    $directory=date('Y/m');
    $root=dirname(__DIR__).'/storage/private-uploads';
    $folder=$root.'/'.$directory;
    if(!is_dir($folder) && !mkdir($folder,0750,true) && !is_dir($folder)) json_response(['error'=>'Could not create upload folder.'],500);
    $filename='post-'.bin2hex(random_bytes(12)).'.webp';
    $relative=$directory.'/'.$filename; $target=$root.'/'.$relative;
    if(!move_uploaded_file($tmp,$target)) json_response(['error'=>'Could not save image.'],500);
    db()->beginTransaction();
    try {
        exec_sql("INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (?,NOW(),UTC_TIMESTAMP(),' ',?,'','inherit','closed','closed',?,'','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'attachment','image/webp',0)",[(int)(current_user()['id'] ?? 0),$title,pathinfo($filename,PATHINFO_FILENAME)]);
        $id=(int)db()->lastInsertId();
        exec_sql("UPDATE wp_posts SET guid=? WHERE ID=?",[path('/media/attachment?id='.$id),$id]);
        exec_sql("INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (?,'_wp_attached_file',?)",[$id,$relative]);
        exec_sql("INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (?,'_wp_attachment_image_alt',?)",[$id,$alt]);
        exec_sql("INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (?,'_wp_attachment_metadata',?)",[$id,serialize(['width'=>$info[0],'height'=>$info[1],'file'=>$relative,'sizes'=>[]])]);
        audit_change('attachment',$id,'create',[],['title'=>$title,'file'=>$relative,'width'=>$info[0],'height'=>$info[1]]);
        db()->commit();
        json_response(['id'=>$id,'title'=>$title,'alt'=>$alt,'url'=>path('/media/attachment?id='.$id),'width'=>$info[0],'height'=>$info[1]]);
    } catch(Throwable $error) {
        db()->rollBack(); @unlink($target); error_log((string)$error); json_response(['error'=>'Could not add image to Media Library.'],500);
    }
}

function update_admin_media(): never {
    $id=max(0,(int)($_POST['id'] ?? 0));
    $item=row("SELECT ID,post_title FROM wp_posts WHERE ID=? AND post_type='attachment' AND post_mime_type LIKE 'image/%'",[$id]);
    if(!$item) json_response(['error'=>'Image not found.'],404);
    $title=mb_substr(trim((string)($_POST['title'] ?? '')),0,200);
    $alt=mb_substr(trim((string)($_POST['alt'] ?? '')),0,500);
    if($title==='') json_response(['error'=>'Title is required.'],400);
    db()->beginTransaction();
    try {
        exec_sql('UPDATE wp_posts SET post_title=?,post_modified=NOW(),post_modified_gmt=UTC_TIMESTAMP() WHERE ID=?',[$title,$id]);
        exec_sql("DELETE FROM wp_postmeta WHERE post_id=? AND meta_key='_wp_attachment_image_alt'",[$id]);
        exec_sql("INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (?,'_wp_attachment_image_alt',?)",[$id,$alt]);
        audit_change('attachment',$id,'update',$item,['title'=>$title,'alt'=>$alt]);
        db()->commit(); json_response(['id'=>$id,'title'=>$title,'alt'=>$alt]);
    } catch(Throwable $error) { db()->rollBack(); error_log((string)$error); json_response(['error'=>'Could not update image.'],500); }
}
