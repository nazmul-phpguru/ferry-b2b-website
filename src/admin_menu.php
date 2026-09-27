<?php
declare(strict_types=1);

function admin_menu(): void {
    $module=static fn(string $key): string=>'/admin/module?key='.$key;
    $menus=[
        ['Dashboard','/admin',[['Home','/admin'],['Updates',$module('updates')]]],
        ['Posts','/admin/posts',[['All Posts','/admin/posts'],['Add Post','/admin/post/new'],['Categories','/admin/taxonomy?taxonomy=category'],['Tags','/admin/taxonomy?taxonomy=post_tag']]],
        ['Media','/admin/media',[['Library','/admin/media'],['Add Media File','/admin/media#media-page-upload']]],
        ['Pages','/admin/pages',[['All Pages','/admin/pages'],['Add Page','/admin/page/new']]],
        ['Comments','/admin/comments',[['All Comments','/admin/comments'],['Pending','/admin/comments?status=pending'],['Spam','/admin/comments?status=spam'],['Trash','/admin/comments?status=trash']]],
        ['E-commerce','/admin/e-commerce',[['Overview','/admin/e-commerce'],['Orders','/admin/orders'],['QR Invoices','/admin/qr-invoices'],['Currency rules',$module('currency-switcher')],['Product Filter',$module('product-filter')],['Coupons','/admin/coupons'],['Store Search',$module('search')],['Reports','/admin/reports'],['Settings','/admin/e-commerce/settings'],['Store usability',$module('woo-usability')],['Status',$module('woo-status')],['Extensions',$module('woo-extensions')],['Shipment Tracking','/admin/shipments'],['QR Settings',$module('qr-settings')],['PDF Invoices','/admin/pdf-invoices']]],
        ['Products','/admin/products',[['All Products','/admin/products'],['Add new product','/admin/product/new'],['Brands','/admin/brands'],['Categories','/admin/taxonomy?taxonomy=product_cat'],['Tags','/admin/taxonomy?taxonomy=product_tag'],['Attributes','/admin/attributes'],['Reviews','/admin/reviews'],['WOOBE Bulk Editor',$module('bulk-editor')]]],
        ['Wholesale','/admin/wholesale',[['Dashboard','/admin/wholesale'],['Orders',$module('wholesale-orders')],['Leads','/admin/leads'],['Order Forms',$module('order-forms')],['Roles','/admin/roles'],['Wholesale Quotes',$module('quotes')],['Settings',$module('wholesale-settings')],['Payments',$module('wholesale-payments')],['Reports',$module('wholesale-reports')],['License',$module('wholesale-license')],['About',$module('wholesale-about')],['Help',$module('wholesale-help')]]],
        ['Payments',$module('payments'),[]],
        ['Marketing',$module('marketing'),[['Overview',$module('marketing')],['Coupons','/admin/coupons'],['Advanced Coupons',$module('advanced-coupons')]]],
        ['Appearance',$module('appearance'),[['Themes',$module('themes')],['Design',$module('design')],['Customize',$module('customize')],['Widgets',$module('widgets')],['Fonts',$module('fonts')],['Menus',$module('menus')],['UberMenu',$module('ubermenu')],['Theme File Editor',$module('theme-editor')]]],
        ['Plugins',$module('plugins'),[['Installed Plugins',$module('plugins')],['Add Plugin',$module('add-plugin')],['Plugin File Editor',$module('plugin-editor')]]],
        ['Users','/admin/users',[['All Users','/admin/users'],['Add User',$module('add-user')],['Profile',$module('profile')]]],
        ['Tools',$module('tools'),[['Available Tools',$module('tools')],['Import',$module('import')],['Export',$module('export')],['Site Health',$module('site-health')],['Export Personal Data',$module('export-personal-data')],['Erase Personal Data',$module('erase-personal-data')],['Network Setup',$module('network')],['Scheduled Actions','/admin/scheduled-actions']]],
        ['Settings',$module('settings'),[['General',$module('settings')],['Connectors',$module('connectors')],['Writing',$module('writing')],['Reading',$module('reading')],['Discussion',$module('discussion')],['Media',$module('media-settings')],['Permalinks',$module('permalinks')],['Privacy',$module('privacy')],['GTranslate',$module('gtranslate')],['WPS Hide Login',$module('hide-login')],['Admin Columns',$module('admin-columns')]]],
        ['WP Mail SMTP',$module('mail-smtp'),[['Settings',$module('mail-smtp')],['Email Log','/admin/email-log'],['Email Reports',$module('email-reports')],['Tools',$module('mail-tools')],['Privacy Compliance',$module('mail-privacy')],['About Us',$module('mail-about')]]],
        ['Role Control',$module('role-control'),[]],
        ['WP Mail Logging',$module('mail-logging'),[['Email Log','/admin/email-log'],['Settings',$module('mail-logging')],['SMTP',$module('mail-smtp')],['Spam Protection',$module('spam-protection')]]],
        ['Smush',$module('smush'),[['Dashboard',$module('smush')],['Lazy Load & Preload',$module('lazy-load')],['CDN',$module('cdn')],['Directory Smush',$module('directory-smush')],['Settings',$module('smush-settings')]]],
        ['Theme Options',$module('theme-options'),[]],
    ];
    $current=parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH) ?: '';
    $query=(string)($_GET['key'] ?? '');
    echo '<nav class="wp-menu" aria-label="Administration">';
    foreach ($menus as [$label,$href,$children]) {
        $active=$current===parse_url($href,PHP_URL_PATH) && ($current!=='/admin/module' || $query===($href==='/'?'':(string)(parse_url($href,PHP_URL_QUERY)?substr((string)parse_url($href,PHP_URL_QUERY),4):'')));
        foreach ($children as [$childLabel,$childHref]) if ($childHref===($_SERVER['REQUEST_URI'] ?? '')) $active=true;
        echo '<details class="menu-group"'.($active?' open':'').'><summary><span>'.h($label).'</span></summary>';
        if ($children) { echo '<div class="submenu">'; foreach ($children as [$childLabel,$childHref]) echo '<a href="'.h(path($childHref)).'">'.h($childLabel).'</a>'; echo '</div>'; }
        echo '</details>';
    }
    echo '</nav>';
}
