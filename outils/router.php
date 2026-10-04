<?php
// Routeur du serveur PHP de test : imite la réécriture IIS.
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($p, '/alcours/api') === 0) {
  $_SERVER['SCRIPT_NAME'] = '/alcours/api/index.php';
  chdir(__DIR__ . '/alcours/api');
  require __DIR__ . '/alcours/api/index.php'; return true;
}
$f = __DIR__ . $p;
if (is_file($f)) return false;
if (strpos($p, '/alcours') === 0) { readfile(__DIR__ . '/alcours/index.html'); return true; }
return false;
