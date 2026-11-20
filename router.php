<?php
// Router for the PHP development server. Apache uses .htaccess instead.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('~(?:^|/)\.|^/(?:database|temp|tests)(?:/|$)|\.(?:sql|log|cache)$~i', $path)
    || in_array($path, ['/config.php', '/initialize.php', '/composer.json', '/composer.lock', '/router.php'], true)) {
    http_response_code(404);
    exit('Not found');
}
return false;
