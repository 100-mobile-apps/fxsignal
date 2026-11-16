# FX Signals Live: cPanel website deployment

The cPanel document root has an API `index.php` and `.htaccess` assembled from the backend package. Preserve both when publishing.

1. Copy this directory's public files to `/home/globacom/forexsignal.bulksend.top/web/`.
2. In the document root `.htaccess`, after `RewriteEngine On`, add `RewriteRule ^$ web/index.php [QSA,L]`. Before the catch-all API router add `RewriteRule ^web(/|$) - [L]`.
3. The server's existing root `index.php` is selected for `/` by LiteSpeed. Immediately after `declare(strict_types=1);`, add:
   `if (parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) === "/" && !isset($_GET["r"])) { require __DIR__ . "/web/index.php"; exit; }`
4. Back up the original root files outside the document root. Check `/`, `/?page=login`, `/web/admin.php`, and `/api/me` (the latter should still return HTTP 401 without a token).
5. Configure `web_admin_password_hash` in the private `api/src/config.php` through cPanel before using the admin panel. Never commit the hash or password.

The website uses the existing API and database. The `/web/` directory is a physical implementation path; public links and canonical URLs use the main subdomain.
