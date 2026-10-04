<?php
// Fichier de diagnostic temporaire — à supprimer une fois le problème résolu (ne doit jamais
// rester en ligne : phpinfo() et les détails serveur sont sensibles).
// Dépose ce fichier dans alcours/api/diag.php et ouvre https://alaginfo.net/alcours/api/diag.php
header('Content-Type: text/plain; charset=utf-8');
echo "PHP : " . PHP_VERSION . "\n";
echo "pdo_pgsql : " . (extension_loaded('pdo_pgsql') ? 'OK' : 'ABSENT') . "\n";
echo "pdo : " . (extension_loaded('pdo') ? 'OK' : 'ABSENT') . "\n";
echo "Extensions chargées : " . implode(', ', get_loaded_extensions()) . "\n";
echo "config.php lisible : ";
try {
    $c = require __DIR__ . '/config.php';
    echo "OK\n";
    echo "config.local.php present : " . (is_file(__DIR__ . '/config.local.php') ? 'OUI' : 'NON') . "\n";
    echo "fichiers_racine : " . ($c['fichiers_racine'] ?? '(vide)') . "\n";
} catch (Throwable $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
}
echo "Connexion base (PDO pgsql) : ";
try {
    $pdo = new PDO($c['db_dsn'], $c['db_user'], $c['db_password']);
    echo "OK\n";
} catch (Throwable $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
}

// pdo_pgsql interdit sur ce serveur : on vérifie la voie de secours (API REST de Supabase en
// HTTPS pur, sans driver base de données côté PHP).
echo "\n--- Voie de secours : appel HTTPS direct (sans pdo_pgsql) ---\n";
echo "curl_init : " . (function_exists('curl_init') ? 'OK' : 'ABSENT') . "\n";
echo "allow_url_fopen : " . (ini_get('allow_url_fopen') ? 'OK' : 'ABSENT') . "\n";
echo "openssl : " . (extension_loaded('openssl') ? 'OK' : 'ABSENT') . "\n";

echo "Test HTTPS (file_get_contents vers supabase.co) : ";
try {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
    $r = @file_get_contents('https://zykgfhahyuckuhohhmcz.supabase.co/rest/v1/', false, $ctx);
    echo ($r !== false ? 'OK (' . strlen($r) . ' octets reçus)' : 'ÉCHEC') . "\n";
} catch (Throwable $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
}
