# Mobile Parts Wholesale PHP backend

This is a local, dependency-free PHP/MySQL administration backend. It does not replace or modify the existing storefront. The customer-facing site is not connected to this backend yet.

## Current status

- Runs on PHP 8.2+ and MySQL/MariaDB with plain PHP, HTML, CSS, and JavaScript.
- The preview currently uses `ferry_app`, a copy of the older XAMPP WordPress data. A limited live sample export has been imported into a separate `ferry_live_snapshot` database; the preview has not been switched to it yet, per the requested admin-first review.
- Administrator and shop manager login accepts the existing WordPress password hashes. Customer login and checkout are disabled pending the live migration and business rules.
- Product, order, user, and page editing is available. Wholesale roles can be created, renamed, or deactivated. The product editor now has WooCommerce-style data tabs, regular and sale prices, role prices, inventory, shipping class, linked products, custom attributes, categories, tags, brands, TinyMCE, image and gallery controls, and existing variation price/stock editing. The order editor shows line items, totals, addresses, customer, attribution, invoice metadata, tracking, and notes; administrators can update status, date, customer, and addresses, and add or delete notes. Product and order lists have WordPress-style columns, filters, and key actions.
- Posts now have a database-backed list, filters, bulk actions, Quick Edit, categories, tags, and a WordPress-style editor with Screen Options and right-side publishing panels. The rich text editor is self-hosted TinyMCE 8.9.2 under its GPL license (`public/assets/vendor/tinymce/license.md`); no CDN is required.
- Posts → Categories and Posts → Tags now use WordPress-style add forms and list tables with search, Quick Edit, bulk delete, and Screen Options. Deleting a category preserves its posts and protects the default category.
- Pages now use the same compact WordPress-style list and classic TinyMCE editor. Administrators can add, update, draft, publish, quick edit, trash, and restore pages; set parent, template, order, excerpt, featured image, and comments; and use Screen Options.
- The post editor can choose existing images from the imported Media Library or upload new ones. New image uploads are resized in the browser to at most 1920 pixels and converted to WebP before PHP validates and stores them. Media title and alt text are saved in WordPress-compatible attachment records; TinyMCE supports editing inserted image alt text and dimensions. Existing imported files are served in their original formats until migrated separately.
- The Media Library has grid and list views, type/date/search filters, an upload drop zone, and an attachment details panel for viewing and editing image title and alt text. The view preference is saved locally in the browser.
- WooCommerce Home, Orders, Coupons, and core store Settings now use database records; Coupons supports creating, editing, filtering, trashing, and restoring codes. The menu mirrors the old WordPress structure. A menu item that opens a page saying it is still being rebuilt is not functional yet. Order line editing, refunds, transactional email sending, QR/PDF document generation, several plugin settings, payment processing, bulk editor parity, and customer checkout remain unfinished. This is not complete WooCommerce or plugin feature parity.
- Product images are served from private storage through a checked media route. Sensitive original uploads are not directly web accessible.

## Local setup

1. Copy `config.example.php` to `config.php` and set local database credentials and a random 64-character hexadecimal `app_key`.
2. Import the WordPress data to a separate database. For the existing XAMPP source, `php bin/clone-db.php` copies tables to the configured app database. For a phpMyAdmin SQL export, use `php bin/import-phpmyadmin-export.php path/to/export.sql.gz`, followed by `php bin/build-live-app-database.php YOUR_TABLE_PREFIX`; then set `db_name` in `config.php` to `ferry_app_live`.
3. Run `php bin/init-app.php` to create audit and login attempt tables.
4. Start a local PHP server from this folder: `php -S 127.0.0.1:8099 -t public router.php`.
5. Open `http://127.0.0.1:8099/admin/login`.

Do not deploy this application or connect the public storefront until the production data import and business workflows are verified.

## Available routes

Admin: `/admin`, `/admin/posts`, `/admin/post/new`, `/admin/products`, `/admin/product/new`, `/admin/orders`, `/admin/users`, `/admin/roles`, `/admin/pages`, `/admin/page/new`, `/admin/taxonomy`, `/admin/attributes`, `/admin/reviews`, `/admin/media`, `/admin/shipments`, `/admin/qr-invoices`, `/admin/pdf-invoices`, `/admin/email-log`, `/admin/scheduled-actions`, `/admin/leads`, `/admin/coupons`, `/admin/shipping`, `/admin/taxes`.

Read-only public API: `/api/v1/health`, `/api/v1/categories`, `/api/v1/catalog`, `/api/v1/product`, `/media/product`.

### WooCommerce REST compatibility

Admin pages: `/admin/woocommerce/api-keys` and `/admin/woocommerce/webhooks`, under Settings → Advanced. Imported WooCommerce API keys and webhook records appear there. New keys show the consumer secret once. Existing client keys continue to authenticate when the client still has its original full key and secret.

API base: `/wp-json/wc/v3/`. Use HTTP Basic authentication with the WooCommerce consumer key and secret. HTTPS is required outside localhost. Key permissions and the owning user's store role are checked. `page` and `per_page` are supported on collection routes, with `X-WP-Total` and `X-WP-TotalPages` headers.

Available routes: products, product categories, orders, customers, coupons, and webhooks (GET collection and item where implemented). Simple products support POST create and PUT/PATCH update for the fields declared by the API handler. Orders support PUT/PATCH status updates. Other WooCommerce REST resources and write operations return an explicit error; this is not full WooCommerce API parity yet.

Webhooks: imported records can be viewed and edited; order, product, customer, and coupon changes made through the connected admin paths emit a signed JSON payload when delivery is enabled. The signature uses the WooCommerce `X-WC-Webhook-Signature` HMAC-SHA256 header. Delivery is disabled by default in this local preview because the imported active order webhook targets a live fulfillment service. Set `webhook_delivery_enabled` to `true` in production config only after verifying that destination. Delivery requires an HTTPS public destination. Delivery queueing and history are not implemented yet.

## Data protection

`config.php` and `storage/` are excluded from version control. Production data includes customer and order information; keep exports and media in private storage. Revoke the shared cPanel API token when the transfer is complete.

