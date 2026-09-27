<?php
declare(strict_types=1);

$allProducts = array_sum($statusCounts) - ($statusCounts['trash'] ?? 0);
$screenColumns = [
    'sku' => ['SKU', false],
    'stock' => ['Stock', false],
    'price' => ['Price', false],
    'categories' => ['Categories', false],
    'date' => ['Date', false],
    'gtin' => ['GTIN / UPC / EAN', true],
    'wholesale-sale' => ['Wholesale sale', true],
    'wholesale' => ['Wholesale prices', true],
    'tags' => ['Tags', true],
];
?>
<div class="wp-posts-list wp-products-list" data-list-kind="products">
  <div class="wp-list-top">
    <div class="wp-list-title">
      <h1>Products</h1>
      <a class="secondary" href="<?=h(path('/admin/product/new'))?>">Add new product</a>
    </div>
    <details class="wp-list-screen">
      <summary aria-label="Screen Options" title="Screen Options">
        <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2" fill="white"/><circle cx="15" cy="12" r="2" fill="white"/><circle cx="10" cy="17" r="2" fill="white"/></svg>
      </summary>
      <div class="wp-list-screen-panel">
        <strong>Columns</strong>
        <?php foreach ($screenColumns as $key => [$label, $hidden]): ?>
          <label><input type="checkbox" data-post-column="<?=h($key)?>" <?=$hidden?'data-default-hidden':''?>> <?=h($label)?></label>
        <?php endforeach; ?>
        <strong>Pagination</strong>
        <form action="<?=h(path('/admin/products'))?>" method="get">
          <?php foreach ($query as $key => $value): if ($key === 'per_page' || $value === '' || $value === 0) continue; ?>
            <input type="hidden" name="<?=h($key)?>" value="<?=h($value)?>">
          <?php endforeach; ?>
          <label>Products per page
            <select name="per_page" onchange="this.form.submit()">
              <?php foreach ([10,20,40] as $number): ?><option value="<?=$number?>" <?=$limit===$number?'selected':''?>><?=$number?></option><?php endforeach; ?>
            </select>
          </label>
        </form>
      </div>
    </details>
  </div>

  <nav class="wp-status-links" aria-label="Product statuses">
    <a class="<?=$status===''?'active':''?>" href="<?=h(path('/admin/products'))?>">All <span>(<?=number_format($allProducts)?>)</span></a>
    <?php foreach (['publish'=>'Published','draft'=>'Drafts','private'=>'Private','trash'=>'Trash'] as $value => $label): if (!($statusCounts[$value] ?? 0) && $status !== $value) continue; ?>
      <a class="<?=$status===$value?'active':''?>" href="<?=h(path('/admin/products?status='.$value))?>"><?=h($label)?> <span>(<?=number_format($statusCounts[$value] ?? 0)?>)</span></a>
    <?php endforeach; ?>
  </nav>

  <form class="wp-list-filter product-list-filter" action="<?=h(path('/admin/products'))?>" method="get">
    <input type="hidden" name="status" value="<?=h($status)?>">
    <input type="hidden" name="per_page" value="<?=$limit?>">
    <label class="sr-only" for="product-category">Category</label>
    <select id="product-category" name="category"><option value="0">All categories</option><?php foreach ($categories as $item): ?><option value="<?=h($item['term_id'])?>" <?=$category===(int)$item['term_id']?'selected':''?>><?=h($item['name'])?></option><?php endforeach; ?></select>
    <label class="sr-only" for="product-type">Product type</label>
    <select id="product-type" name="type"><option value="0">All product types</option><?php foreach ($types as $item): ?><option value="<?=h($item['term_id'])?>" <?=$type===(int)$item['term_id']?'selected':''?>><?=h($item['name'])?></option><?php endforeach; ?></select>
    <label class="sr-only" for="product-stock">Stock</label>
    <select id="product-stock" name="stock"><option value="">All stock statuses</option><option value="instock" <?=$stock==='instock'?'selected':''?>>In stock</option><option value="outofstock" <?=$stock==='outofstock'?'selected':''?>>Out of stock</option><option value="onbackorder" <?=$stock==='onbackorder'?'selected':''?>>On backorder</option></select>
    <label class="sr-only" for="product-quality">Quality</label>
    <select id="product-quality" name="quality"><option value="0">All qualities</option><?php foreach ($qualities as $item): ?><option value="<?=h($item['term_id'])?>" <?=$quality===(int)$item['term_id']?'selected':''?>><?=h($item['name'])?></option><?php endforeach; ?></select>
    <label class="sr-only" for="product-brand">Brand</label>
    <select id="product-brand" name="brand"><option value="0">All brands</option><?php foreach ($brands as $item): ?><option value="<?=h($item['term_id'])?>" <?=$brand===(int)$item['term_id']?'selected':''?>><?=h($item['name'])?></option><?php endforeach; ?></select>
    <button class="secondary" type="submit">Filter</button>
    <div class="wp-list-search">
      <label class="sr-only" for="product-search">Search products</label>
      <input id="product-search" name="q" value="<?=h($q)?>" placeholder="Search name or SKU">
      <button class="secondary" type="submit">Search products</button>
    </div>
  </form>

  <form method="post" action="<?=h(path('/admin/products/bulk'))?>">
    <?=csrf_field()?>
    <div class="wp-list-actions">
      <select name="action" aria-label="Bulk actions"><option value="">Bulk actions</option><option value="draft">Move to Draft</option><option value="publish">Publish</option><option value="private">Make private</option><option value="trash">Move to Trash</option></select>
      <button class="secondary" type="submit">Apply</button>
      <span><?=number_format($count)?> <?=$count===1?'item':'items'?></span>
    </div>
    <div class="wp-list-table-wrap">
      <table class="wp-list-table products-table">
        <thead><tr>
          <th><input type="checkbox" aria-label="Select all products" data-select-all-products></th>
          <th class="product-image-heading"><span class="sr-only">Image</span></th>
          <th>Name</th>
          <th data-post-col="sku">SKU</th>
          <th data-post-col="stock">Stock</th>
          <th data-post-col="price">Price</th>
          <th data-post-col="categories">Categories</th>
          <th data-post-col="date">Date</th>
          <th data-post-col="gtin">GTIN / UPC / EAN</th>
          <th data-post-col="wholesale-sale">Wholesale sale</th>
          <th data-post-col="wholesale">Wholesale prices</th>
          <th data-post-col="tags">Tags</th>
        </tr></thead>
        <tbody>
        <?php foreach ($products as $p):
          $id=(int)$p['ID'];
          $stockValue=(string)($p['stock_status'] ?? '');
          $stockLabel=['instock'=>'In stock','outofstock'=>'Out of stock','onbackorder'=>'On backorder'][$stockValue] ?? 'Not set';
          $dateValue=strtotime((string)$p['post_date']);
          $dateLabel=$dateValue?date('M j, Y',$dateValue):'—';
          $statusLabel=['publish'=>'Published','draft'=>'Draft','private'=>'Private','trash'=>'Trash'][$p['post_status']] ?? ucfirst((string)$p['post_status']);
        ?>
          <tr class="product-list-row">
            <td><input class="product-select" type="checkbox" name="ids[]" value="<?=$id?>" aria-label="Select <?=h($p['post_title'])?>"></td>
            <td><img class="product-thumb" src="<?=h((int)$p['thumbnail_id']>0?path('/media/product?id='.$id):path('/assets/no-image.svg'))?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?=h(path('/assets/no-image.svg'))?>'"></td>
            <td class="product-name-cell"><strong><a href="<?=h(path('/admin/product?id='.$id))?>"><?=h($p['post_title'] ?: '(untitled product)')?></a></strong><?php if ($p['post_status']!=='publish'): ?><span class="product-state"><?=h($statusLabel)?></span><?php endif; ?>
              <div class="row-actions"><a href="<?=h(path('/admin/product?id='.$id))?>">Edit</a><?php if ($p['post_status']!=='trash'): ?><button type="button" data-quick-edit="<?=$id?>">Quick Edit</button><button type="submit" name="row_action" value="trash:<?=$id?>" onclick="return confirm('Move this product to Trash?')">Trash</button><?php else: ?><button type="submit" name="row_action" value="draft:<?=$id?>">Restore to Draft</button><?php endif; ?><a href="<?=h(path('/admin/product/preview?id='.$id))?>">Preview</a><button type="submit" name="row_action" value="duplicate:<?=$id?>">Duplicate</button></div>
            </td>
            <td data-post-col="sku"><span class="product-sku"><?=h($p['sku'] ?: '—')?></span></td>
            <td data-post-col="stock"><span class="product-stock product-stock--<?=h($stockValue ?: 'unknown')?>"><?=h($stockLabel)?></span><?php if (is_numeric($p['stock'])): ?><small><?=h($p['stock'])?> available</small><?php endif; ?></td>
            <td data-post-col="price" class="product-price"><?=is_numeric($p['price'])?currency($p['price']):'—'?></td>
            <td data-post-col="categories" class="product-categories"><?=h($p['categories'] ?: '—')?></td>
            <td data-post-col="date"><span class="product-date-state"><?=h($statusLabel)?></span><small><?=h($dateLabel)?></small></td>
            <td data-post-col="gtin"><?=h($p['gtin'] ?: '—')?></td>
            <td data-post-col="wholesale-sale"><?=is_numeric($p['wholesale_sale'])?currency($p['wholesale_sale']):'—'?></td>
            <td data-post-col="wholesale"><?php foreach ($priceByProduct[$id] ?? [] as [$label,$value]): ?><small><?=h($label)?>: <?=currency($value)?></small><?php endforeach; if (empty($priceByProduct[$id])) echo '—'; ?></td>
            <td data-post-col="tags"><?=h($p['tags'] ?: '—')?></td>
          </tr>
          <tr class="quick-edit-row" id="quick-<?=$id?>" hidden><td colspan="12"><div class="quick-grid"><label>Product name<input name="quick[<?=$id?>][title]" value="<?=h($p['post_title'])?>"></label><label>SKU<input name="quick[<?=$id?>][sku]" value="<?=h($p['sku'])?>"></label><label>Status<select name="quick[<?=$id?>][status]"><option value="publish" <?=$p['post_status']==='publish'?'selected':''?>>Published</option><option value="draft" <?=$p['post_status']==='draft'?'selected':''?>>Draft</option><option value="private" <?=$p['post_status']==='private'?'selected':''?>>Private</option></select></label><label>Price<input name="quick[<?=$id?>][price]" type="number" min="0" step="0.01" value="<?=h($p['price'] ?? 0)?>"></label><label>Stock<input name="quick[<?=$id?>][stock]" type="number" min="0" step="1" value="<?=h($p['stock'] ?? 0)?>"></label><button class="primary" type="submit" name="row_action" value="quick:<?=$id?>">Update</button><button class="secondary" type="button" data-quick-cancel="<?=$id?>">Cancel</button></div></td></tr>
        <?php endforeach; if (!$products): ?><tr><td class="product-empty" colspan="12">No products found. Try another search or filter.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>
  <?=admin_pages_nav('/admin/products',$page,$count,$limit,$query)?>
</div>
<link rel="stylesheet" href="<?=h(path('/assets/posts-list.css?v=2'))?>">
<link rel="stylesheet" href="<?=h(path('/assets/products-list.css?v=2'))?>">
<script src="<?=h(path('/assets/posts-list.js?v=2'))?>" defer></script>
<script src="<?=h(path('/assets/products-list.js?v=1'))?>" defer></script>
