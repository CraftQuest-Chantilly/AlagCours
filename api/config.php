<?php
// Configuration de l'API du portail stagiaire (alaginfo.net/alcours/api/).
// Les valeurs sensibles (base, secret des jetons) sont dans config.local.php,
// qui n'est PAS versionné : copier config.local.example.php et le remplir.
$config = [
    // Connexion PostgreSQL directe (pdo_pgsql) : INUTILISÉE en production — cette extension
    // est absente et interdite sur le serveur OVH mutualisé (« interdit de charger pdo_sql »).
    // Conservée uniquement pour le sandbox local de développement (Db.php bascule sur l'API
    // REST de Supabase ci-dessous dès que supabase_url/supabase_service_role_key sont renseignés).
    'db_dsn'      => '',
    'db_user'     => '',
    'db_password' => '',
    // Accès base via l'API REST de Supabase (PostgREST), en HTTPS pur — voie utilisée en
    // production car pdo_pgsql et curl sont tous deux absents sur le serveur OVH, seuls
    // allow_url_fopen + openssl sont disponibles (cf. diag.php). Db.php appelle les fonctions
    // SQL sc_exec_sql/sc_exec_write via file_get_contents(), qui exécutent le texte SQL existant
    // (placeholders :nom convertis en %L) sans rien changer aux requêtes des fichiers routes/*.
    // Clé SERVICE_ROLE uniquement (jamais la clé publique/anon) : ces fonctions sont réservées
    // au rôle service_role côté Supabase (revoke public/anon/authenticated dans la migration
    // rpc_exec_sql_pour_api_php_sans_pdo_pgsql, fonctions exec_sql/exec_write).
    'supabase_url'                => 'https://zykgfhahyuckuhohhmcz.supabase.co',
    'supabase_service_role_key'   => '',
    // Secret HMAC des jetons de session (au moins 32 caractères aléatoires).
    'jwt_secret'  => '',
    'jwt_duree'   => 8 * 3600,           // durée de validité d'une session (secondes)
    'fuseau'      => 'Europe/Paris',
    // Racine physique des fichiers de ressources sur le serveur (ex. D:\FTP\_Fichiers\_Suivicours\_Fichiers\Claro).
    'fichiers_racine' => '',
    // Racine physique des signatures de présence (E7), écrite par l'API (contrairement à
    // fichiers_racine qui n'est que lue) : <signatures_racine>\<CodePromo>\<CodeStagiaire>\<NumCours>.png.
    // Doit être hors de la racine web (jamais d'accès direct par URL, uniquement via l'API).
    // Ex. D:\FTP\_Fichiers\_Suivicours\_Signatures.
    'signatures_racine' => '',
    // Anti force brute : au-delà de N échecs sur la fenêtre, connexion refusée.
    'echecs_max'     => 5,              // par identifiant
    'echecs_max_ip'  => 30,             // par adresse IP
    'echecs_fenetre' => 15 * 60,         // secondes
];
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_merge($config, require $local);
}
return $config;
