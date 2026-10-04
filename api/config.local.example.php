<?php
// Copier ce fichier en config.local.php (même dossier) et renseigner les valeurs.
// config.local.php ne doit jamais être publié ni versionné.
return [
    // db_dsn/db_user/db_password : uniquement pour un sandbox local avec pdo_pgsql installé.
    // En production (serveur OVH), ces trois clés restent vides/inutilisées — voir supabase_*
    // ci-dessous, seule voie utilisée réellement (pdo_pgsql interdit sur ce serveur).
    'db_dsn'      => 'pgsql:host=HOTE;port=5432;dbname=postgres;sslmode=require',
    'db_user'     => 'UTILISATEUR',
    'db_password' => 'MOT_DE_PASSE',
    'jwt_secret'  => 'REMPLACER_PAR_UNE_CHAINE_ALEATOIRE_DE_64_CARACTERES',
    // Clé service_role Supabase (Project Settings → API → service_role secret).
    // JAMAIS la clé "anon"/publishable : exec_sql/exec_write sont réservées à service_role.
    'supabase_url'              => 'https://zykgfhahyuckuhohhmcz.supabase.co',
    'supabase_service_role_key' => 'COLLER_LA_CLE_SERVICE_ROLE_ICI',
    // Racine physique des fichiers de ressources (ex. D:\FTP\_Fichiers\_Suivicours\_Fichiers\Claro).
    'fichiers_racine' => 'D:\\FTP\\_Fichiers\\_Suivicours\\_Fichiers\\Claro',
    // Racine physique des signatures de présence (E7), hors de la racine web.
    'signatures_racine' => 'D:\\FTP\\_Fichiers\\_Suivicours\\_Signatures',
];
