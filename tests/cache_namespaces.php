<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../classes/Cache.php';
$root = sys_get_temp_dir().'/karyasetu-cache-test-'.bin2hex(random_bytes(8));
putenv('APP_CACHE_DIR='.$root);
$a = new Cache(3600, 'retailer-a'); $b = new Cache(3600, 'retailer-b'); $legacy = new Cache();
$a->set('same-key', 'A'); $b->set('same-key', 'B'); $legacy->set('same-key', 'legacy');
if ($a->get('same-key') !== 'A' || $b->get('same-key') !== 'B' || $legacy->get('same-key') !== 'legacy') throw new RuntimeException('Cache namespaces overlap.');
$a->clear();
if ($a->get('same-key') !== null || $b->get('same-key') !== 'B' || $legacy->get('same-key') !== 'legacy') throw new RuntimeException('Clearing one namespace affected another.');
foreach (['', '../other', 'retailer/a'] as $invalid) {
    try { new Cache(3600, $invalid); throw new RuntimeException('Invalid namespace accepted.'); }
    catch (InvalidArgumentException $expected) {}
}
$b->clear(); $legacy->clear();
foreach (glob($root.'/tenant-*', GLOB_ONLYDIR) as $dir) rmdir($dir);
rmdir($root);
echo "Passed cache namespace isolation checks.\n";
