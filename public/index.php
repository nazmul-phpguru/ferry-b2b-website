<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/admin_menu.php';
require dirname(__DIR__).'/src/admin.php';
require dirname(__DIR__).'/src/admin_extra.php';
require dirname(__DIR__).'/src/admin_roles.php';
require dirname(__DIR__).'/src/admin_module.php';
require dirname(__DIR__).'/src/admin_updates.php';
require dirname(__DIR__).'/src/admin_product_actions.php';
require dirname(__DIR__).'/src/admin_shipments.php';
require dirname(__DIR__).'/src/admin_special.php';
require dirname(__DIR__).'/src/admin_posts.php';
require dirname(__DIR__).'/src/admin_post_terms.php';
require dirname(__DIR__).'/src/admin_pages_wp.php';
require dirname(__DIR__).'/src/admin_comments.php';
require dirname(__DIR__).'/src/admin_woocommerce.php';
require dirname(__DIR__).'/src/admin_store_config.php';
require dirname(__DIR__).'/src/admin_store_tabs.php';
require dirname(__DIR__).'/src/admin_orders_list.php';
require dirname(__DIR__).'/src/swiss_qr.php';
require dirname(__DIR__).'/src/admin_order_pdf.php';
require dirname(__DIR__).'/src/admin_woo_edit.php';
require dirname(__DIR__).'/src/admin_order_editor_reference.php';
require dirname(__DIR__).'/src/api.php';
require dirname(__DIR__).'/src/storefront_api.php';
require dirname(__DIR__).'/src/woo_rest.php';
require dirname(__DIR__).'/src/admin_woo_api.php';
require dirname(__DIR__).'/src/media.php';

$uri=parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/';
$base=rtrim((string)cfg('base_path',''),'/');
if ($base!=='' && str_starts_with($uri,$base)) $uri=substr($uri,strlen($base)) ?: '/';
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
if($method==='GET' && $uri==='/shop'){
    $legacySearch=trim((string)($_GET['q']??$_GET['s']??''));
    if($legacySearch!==''){header('Location: '.path('/shop/'.slug($legacySearch)),true,301);exit;}
}
if (str_starts_with($uri,'/api/')) api_route($uri);
if (str_starts_with($uri,'/wp-json/wc/v3/')) woo_rest_route($uri);
if ($uri==='/assets/img/product') serve_product_media((int)($_GET['id'] ?? 0));
if ($uri==='/media/product') serve_product_media((int)($_GET['id'] ?? 0));
if ($uri==='/media/attachment') serve_admin_attachment((int)($_GET['id'] ?? 0));

try {
    if ($method==='POST') {
        verify_csrf();
        switch($uri) {
            case '/admin/login':
                if (login(trim((string)($_POST['identity'] ?? '')),(string)($_POST['password'] ?? '')) && is_admin()) redirect('/admin');
                $_SESSION['user']=null; notice('Invalid administrator credentials.'); redirect('/admin/login');
            case '/admin/logout':
                $_SESSION=[]; session_regenerate_id(true); redirect('/admin/login');
            case '/admin/product': require_admin(); save_woo_product_editor();
            case '/admin/post': require_admin(); save_post();
            case '/admin/media/upload': require_admin(); upload_admin_media();
            case '/admin/media/update': require_admin(); update_admin_media();
            case '/admin/posts/bulk': require_admin(); bulk_post_actions();
            case '/admin/order': require_admin(); save_woo_order_editor();
            case '/admin/order/note': require_admin(); add_woo_order_note();
            case '/admin/order/note/delete': require_admin(); delete_woo_order_note();
            case '/admin/page': require_admin(); save_wp_page();
            case '/admin/pages/bulk': require_admin(); bulk_wp_pages();
            case '/admin/comments/bulk': require_admin(); bulk_admin_comments();
            case '/admin/comment': require_admin(); save_admin_comment();
            case '/admin/comments/reply': require_admin(); reply_admin_comment();
            case '/admin/coupon': require_admin(); save_woo_coupon();
            case '/admin/woocommerce/settings': require_admin(); save_woo_settings();
            case '/admin/woocommerce/settings/tab': require_admin(); save_store_settings_tab();
            case '/admin/woocommerce/api-key': require_admin(); save_woo_api_key();
            case '/admin/woocommerce/webhook': require_admin(); save_woo_webhook();
            case '/admin/woocommerce/visibility': require_admin(); save_store_visibility();
            case '/admin/woocommerce/payment': require_admin(); save_store_payment();
            case '/admin/woocommerce/zone': require_admin(); save_store_zone();
            case '/admin/woocommerce/method': require_admin(); save_store_method();
            case '/admin/woocommerce/tax-rate': require_admin(); save_store_tax_rate();
            case '/admin/orders/bulk': require_admin(); bulk_woo_orders();
            case '/admin/updates/connect': require_admin(); save_github_update_connection();
            case '/admin/updates/check': require_admin(); check_github_update_connection();
            case '/admin/updates/disconnect': require_admin(); disconnect_github_update_connection();
            case '/admin/coupons/bulk': require_admin(); bulk_woo_coupons();
            case '/admin/user': require_admin(); save_user();
            case '/admin/taxonomy': require_admin(); save_taxonomy();
            case '/admin/post-terms/action': require_admin(); post_term_action();
            case '/admin/role': require_admin(); save_role();
            case '/admin/role/delete': require_admin(); delete_role();
            case '/admin/product/new': require_admin(); create_product();
            case '/admin/products/bulk': require_admin(); bulk_product_actions();
            case '/admin/shipment': require_admin(); save_tracking();
            case '/admin/shipment/delete': require_admin(); delete_tracking();
            default: http_response_code(404); exit('Not found.');
        }
    }
    if ($uri==='/' || $uri==='/shop' || str_starts_with($uri,'/shop/') || $uri==='/categories' || str_starts_with($uri,'/categories/') || str_starts_with($uri,'/product/') || $uri==='/cart' || $uri==='/checkout' || $uri==='/account' || $uri==='/register' || $uri==='/login' || $uri==='/blog' || str_starts_with($uri,'/blog/') || in_array($uri,['/shipping-and-returns','/terms-and-conditions','/privacy-policy','/newsletter-confirm','/newsletter-unsubscribe'],true)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        readfile(__DIR__.'/storefront.html');
        exit;
    }
    if ($uri==='/admin/login') { admin_login(); exit; }
    require_admin();
    switch($uri) {
        case '/admin': admin_dashboard(); break;
        case '/admin/posts': admin_posts(); break;
        case '/admin/post': admin_post((int)($_GET['id'] ?? 0)); break;
        case '/admin/post/new': admin_post(); break;
        case '/admin/post/preview': admin_post_preview((int)($_GET['id'] ?? 0)); break;
        case '/admin/products': admin_products(); break;
        case '/admin/product': woo_product_editor((int)($_GET['id'] ?? 0)); break;
        case '/admin/product/new': admin_new_product(); break;
        case '/admin/product/preview': admin_product_preview((int)($_GET['id'] ?? 0)); break;
        case '/admin/shipments': admin_shipments(); break;
        case '/admin/shipment': admin_shipment((int)($_GET['order'] ?? 0)); break;
        case '/admin/qr-invoices': admin_invoices('qr'); break;
        case '/admin/pdf-invoices': admin_invoices('pdf'); break;
        case '/admin/email-log': admin_email_log(); break;
        case '/admin/email': admin_email((int)($_GET['id'] ?? 0)); break;
        case '/admin/scheduled-actions': admin_scheduled_actions(); break;
        case '/admin/leads': admin_leads(); break;
        case '/admin/orders': admin_orders_list(); break;
        case '/admin/orders/export': export_orders_csv(); break;
        case '/admin/order/document': download_order_document(); break;
        case '/admin/order': admin_order_editor_reference((int)($_GET['id'] ?? 0)); break;
        case '/admin/users': admin_users(); break;
        case '/admin/user': admin_user((int)($_GET['id'] ?? 0)); break;
        case '/admin/pages': admin_wp_pages(); break;
        case '/admin/page': admin_wp_page((int)($_GET['id'] ?? 0)); break;
        case '/admin/page/new': admin_wp_page(); break;
        case '/admin/page/preview': admin_wp_page_preview((int)($_GET['id'] ?? 0)); break;
        case '/admin/comments': admin_comments(); break;
        case '/admin/woocommerce': admin_woocommerce_home(); break;
        case '/admin/woocommerce/settings': admin_store_settings_tab((string)($_GET['tab']??'general')); break;
        case '/admin/woocommerce/api-keys': admin_woo_api_keys(); break;
        case '/admin/woocommerce/api-key': admin_woo_api_key(); break;
        case '/admin/woocommerce/webhooks': admin_woo_webhooks(); break;
        case '/admin/woocommerce/webhook': admin_woo_webhook(); break;
        case '/admin/woocommerce/order-statuses': admin_store_order_statuses(); break;
        case '/admin/woocommerce/payments': admin_store_payments(); break;
        case '/admin/woocommerce/payment': admin_store_payment(); break;
        case '/admin/woocommerce/shipping': admin_store_shipping(); break;
        case '/admin/woocommerce/shipping-classes': admin_store_shipping_classes(); break;
        case '/admin/woocommerce/zone': admin_store_zone(); break;
        case '/admin/woocommerce/method': admin_store_method(); break;
        case '/admin/woocommerce/tax': admin_store_taxes(); break;
        case '/admin/woocommerce/tax-rate': admin_store_tax_rate(); break;
        case '/admin/coupon': admin_woo_coupon((int)($_GET['id'] ?? 0)); break;
        case '/admin/coupon/new': admin_woo_coupon(); break;
        case '/admin/comment': admin_comment((int)($_GET['id'] ?? 0)); break;
        case '/admin/categories': admin_categories(); break;
        case '/admin/taxonomy': admin_taxonomy(); break;
        case '/admin/brands': admin_brands(); break;
        case '/admin/attributes': admin_attributes(); break;
        case '/admin/reviews': admin_reviews(); break;
        case '/admin/media': admin_media(); break;
        case '/admin/wholesale': admin_wholesale(); break;
        case '/admin/reports': admin_reports(); break;
        case '/admin/roles': admin_roles(); break;
        case '/admin/module': admin_module(); break;
        case '/admin/coupons': admin_woo_coupons_wp(); break;
        case '/admin/shipping': admin_store_shipping(); break;
        case '/admin/taxes': admin_store_taxes(); break;
        default: http_response_code(404); admin_layout('Not found',static fn()=>print('<p>This page does not exist.</p>'));
    }
} catch(Throwable $e) {
    error_log((string)$e);
    http_response_code(500);
    admin_layout('Error',static fn()=>print('<div class="notice error">The request could not be processed. Check the server log.</div>'));
}
