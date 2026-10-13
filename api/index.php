<?php
// API du portail stagiaire al-cours — point d'entrée unique.
// PHP >= 7.4. Aucune dépendance externe. Accès base SANS pdo_pgsql (absent/interdit sur le
// serveur OVH) : Db.php appelle l'API REST de Supabase en HTTPS pur (cf. lib/Db.php).
//
// Routage par paramètre ?route=... (et non par réécriture d'URL façon /api/formations) :
// le serveur mutualisé OVH n'a pas le module IIS URL Rewrite (constaté avec le site SVM sur
// le même serveur, cf. son web.config), donc /alcours/api/index.php doit rester une ressource
// physique directement adressable, jamais un chemin virtuel à réécrire.
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['fuseau']);

require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Jeton.php';
require __DIR__ . '/routes/auth.php';
require __DIR__ . '/routes/formations.php';
require __DIR__ . '/routes/ressources.php';
require __DIR__ . '/routes/evaluation.php';
require __DIR__ . '/routes/quizz.php';
require __DIR__ . '/routes/signature.php';
require __DIR__ . '/routes/avis.php';
require __DIR__ . '/routes/atelier.php';
require __DIR__ . '/routes/atelier_stats.php';

set_exception_handler(function (Throwable $e) {
    error_log('[alcours api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Http::erreur(500, 'Erreur interne du serveur.');
});

// Ex. index.php?route=/formations/123/seances
$chemin = '/' . trim((string)($_GET['route'] ?? ''), '/');
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$routes = [
    ['POST', '#^/login$#',                     'route_login'],
    ['GET',  '#^/me$#',                        'route_me'],
    ['PUT',  '#^/me/mot-de-passe$#',           'route_stagiaire_changer_mdp'],
    ['GET',  '#^/formations$#',                'route_formations'],
    ['GET',  '#^/agenda$#',                    'route_agenda'],
    ['GET',  '#^/formations/(\d+)$#',          'route_formation'],
    ['GET',  '#^/formations/(\d+)/seances$#',  'route_seances'],
    // Ressources (stagiaire) : liste plate des fichiers/liens droités + téléchargement.
    ['GET',  '#^/formations/(\d+)/ressources$#',                    'route_ressources_dossier'],
    ['GET',  '#^/formations/(\d+)/ressources/(\d+)/telecharger$#',  'route_ressources_telecharger'],
    // Ressources (formateur) : arborescence complète + création de dossier + dépôt de fichier.
    ['GET',  '#^/admin/ressources$#',                'route_admin_ressources_dossier'],
    ['GET',  '#^/admin/ressources/recherche$#',      'route_admin_ressources_recherche'],
    ['GET',  '#^/admin/ressources/(\d+)/telecharger$#', 'route_admin_ressources_telecharger'],
    ['GET',  '#^/admin/ressources/(\d+)$#',          'route_admin_ressources_dossier'],
    ['POST', '#^/admin/ressources/(\d+)/dossier$#',  'route_admin_ressources_creer_dossier'],
    ['POST', '#^/admin/ressources/(\d+)/fichier$#',  'route_admin_ressources_upload'],
    // Formateur : promos (PAGE_ChoixPromo) + droits fichiers par promo (PAGE_ModifFicDroit).
    ['GET',    '#^/admin/promos$#',                'route_admin_promos'],
    ['GET',    '#^/admin/promos/(\d+)/groupes$#',  'route_admin_promo_groupes'],
    ['GET',    '#^/admin/promos/(\d+)/td$#',       'route_admin_promo_td'],
    ['GET',    '#^/admin/promos/(\d+)/droits$#',   'route_admin_promo_droits'],
    ['POST',   '#^/admin/promos/(\d+)/droits$#',   'route_admin_promo_droit_creer'],
    ['POST',   '#^/admin/promos/(\d+)/droits/lot$#', 'route_admin_promo_droits_lot'],
    ['PUT',    '#^/admin/droits/(\d+)$#',          'route_admin_droit_modifier'],
    ['DELETE', '#^/admin/droits/(\d+)$#',          'route_admin_droit_supprimer'],
    // Formateur : clients + leurs cours (sélection de la promo cible, PAGE_Fichiers_Adm).
    ['GET',    '#^/admin/clients$#',               'route_admin_clients'],
    ['GET',    '#^/admin/clients/(\d+)/cours$#',   'route_admin_client_cours'],
    // Évaluation de la formation (E6, système "Eval" de T_Quizz).
    ['GET',  '#^/formations/(\d+)/evaluation$#',  'route_evaluation'],
    ['POST', '#^/formations/(\d+)/evaluation$#',  'route_evaluation_soumettre'],
    // Évaluation de la formation — récapitulatifs formateur (E12) : recap anonyme + détail par
    // stagiaire, exportés en PDF depuis « Gestion des fichiers ».
    ['GET',  '#^/admin/promos/(\d+)/evaluation$#', 'route_admin_evaluation_promo'],
    // QCM noté (E6, système T_QuizzPromo, tuile « Quizz »).
    ['GET',  '#^/formations/(\d+)/quizz$#',                     'route_quizz_liste'],
    ['GET',  '#^/formations/(\d+)/quizz/(\d+)$#',                'route_quizz_questions'],
    ['POST', '#^/formations/(\d+)/quizz/(\d+)/repondre$#',       'route_quizz_repondre'],
    // Signature de présence (E7).
    ['GET',  '#^/formations/(\d+)/signature$#',              'route_signature_liste'],
    ['POST', '#^/formations/(\d+)/signature/(\d+)$#',        'route_signature_signer'],
    ['GET',  '#^/formations/(\d+)/signature/(\d+)/image$#',  'route_signature_image'],
    ['POST', '#^/admin/signature/deblocage$#',               'route_signature_debloquer'],
    // Déblocage temporaire de signature pour TOUTE une promo (E10) — bouton « Gestion des fichiers ».
    ['POST', '#^/admin/promos/(\d+)/signatures/deblocage$#', 'route_signature_debloquer_promo'],
    // Feuille de présence formateur (E11) : liste (+ ?numCours= pour une séance précise) et image.
    ['GET',  '#^/admin/promos/(\d+)/signatures$#',            'route_admin_signatures_promo'],
    ['GET',  '#^/admin/cours/(\d+)/signatures/(\d+)/image$#', 'route_admin_signature_image'],
    // Avis (E6bis).
    ['GET',  '#^/formations/(\d+)/avis$#',  'route_avis'],
    ['POST', '#^/formations/(\d+)/avis$#',  'route_avis_soumettre'],
    // Atelier SQL (E8) — entraînement libre (pas de scoping par promo, base commune). Le code de
    // base ("ecole"…) n'est PAS un identifiant numérique comme les autres routes : il est passé
    // en query string / corps JSON plutôt qu'en segment d'URL, pour ne pas casser le dispatch
    // générique ci-dessous qui force tout paramètre capturé en chiffres (Http::id()).
    ['GET',  '#^/atelier/bases$#',      'route_atelier_bases'],
    ['GET',  '#^/atelier/schema$#',     'route_atelier_schema'],
    ['GET',  '#^/atelier/relations$#',  'route_atelier_relations'],
    ['POST', '#^/atelier/executer$#',   'route_atelier_executer'],
    // Atelier SQL — partiel (stagiaire).
    ['GET',  '#^/formations/(\d+)/atelier/partiels$#',                              'route_atelier_partiels'],
    ['GET',  '#^/formations/(\d+)/atelier/partiels/(\d+)$#',                        'route_atelier_partiel_questions'],
    ['POST', '#^/formations/(\d+)/atelier/partiels/(\d+)/questions/(\d+)/repondre$#', 'route_atelier_partiel_repondre'],
    // Atelier SQL — mini-écran formateur.
    ['GET',  '#^/admin/atelier/partiels/(\d+)$#',  'route_admin_atelier_partiels'],
    ['POST', '#^/admin/atelier/partiels$#',        'route_admin_atelier_partiel_creer'],
    // Atelier SQL — résultats/suivi d'un partiel (formateur) : équivalent "Graph partiel" / "Qui n'a pas rep".
    ['GET',  '#^/admin/atelier/partiel/(\d+)/resultats$#',       'route_admin_atelier_partiel_resultats'],
    ['GET',  '#^/admin/atelier/partiel/(\d+)/non-repondants$#',  'route_admin_atelier_partiel_non_repondants'],
    // Atelier SQL — banque de consignes (stagiaire : pratique libre permanente, distincte du partiel).
    ['GET',  '#^/atelier/consignes$#',                          'route_atelier_consignes'],
    ['GET',  '#^/atelier/consignes/(\d+)/tentatives$#',         'route_atelier_consigne_tentatives'],
    ['POST', '#^/atelier/consignes/(\d+)/repondre$#',           'route_atelier_consigne_repondre'],
    ['GET',  '#^/atelier/stats$#',                              'route_atelier_stats'],
    ['GET',  '#^/atelier/mes-stats$#',                          'route_atelier_mes_stats'],
    // Atelier SQL — stats, correction, bonus, promo de référence (formateur).
    ['GET',  '#^/admin/atelier/stats$#',                        'route_admin_atelier_stats'],
    ['GET',  '#^/admin/atelier/promos-sql$#',                   'route_admin_atelier_promos_sql'],
    ['GET',  '#^/admin/atelier/requetes$#',                     'route_admin_atelier_requetes'],
    ['PUT',  '#^/admin/atelier/requetes/(\d+)$#',               'route_admin_atelier_requete_modifier'],
    ['GET',  '#^/admin/promos/(\d+)/sql-reference$#',           'route_admin_promo_sql_reference_lire'],
    ['PUT',  '#^/admin/promos/(\d+)/sql-reference$#',           'route_admin_promo_sql_reference_ecrire'],
    ['GET',  '#^/admin/promos/(\d+)/sql-bonus$#',               'route_admin_atelier_bonus'],
    ['PUT',  '#^/admin/promos/(\d+)/sql-points/(\d+)$#',        'route_admin_atelier_points_ecrire'],
    // Atelier SQL — banque de consignes (formateur : BackOffice).
    ['GET',    '#^/admin/atelier/consignes$#',          'route_admin_atelier_consignes'],
    ['POST',   '#^/admin/atelier/consignes$#',          'route_admin_atelier_consigne_creer'],
    ['PUT',    '#^/admin/atelier/consignes/(\d+)$#',    'route_admin_atelier_consigne_modifier'],
    ['DELETE', '#^/admin/atelier/consignes/(\d+)$#',    'route_admin_atelier_consigne_supprimer'],
    // Atelier SQL — pointage des tentatives d'une consigne (formateur) : exploite enfin SACT_Vu.
    ['GET',  '#^/admin/atelier/consignes/(\d+)/tentatives$#',  'route_admin_atelier_consigne_tentatives'],
    ['PUT',  '#^/admin/atelier/tentatives/(\d+)/vu$#',          'route_admin_atelier_tentative_vu'],
    // Journal des connexions (formateur) — équivalent "LogCnx" du BackOffice SqlRA.
    ['GET',  '#^/admin/journal$#',  'route_admin_journal'],
    // Atelier SQL — suivi en direct (présence, messagerie, abus) : sondage côté client, pas de
    // dépendance Supabase Realtime (décision de Jérôme du 26/09).
    ['POST', '#^/atelier/presence$#',         'route_atelier_presence'],
    ['GET',  '#^/admin/atelier/presence$#',   'route_admin_atelier_presence'],
    ['GET',  '#^/atelier/messages$#',         'route_atelier_messages'],
    ['POST', '#^/atelier/messages$#',         'route_atelier_message_envoyer'],
    ['GET',  '#^/admin/atelier/messages$#',       'route_admin_atelier_messages'],
    ['POST', '#^/admin/atelier/messages$#',       'route_admin_atelier_message_envoyer'],
    ['GET',  '#^/admin/atelier/abus$#',           'route_admin_atelier_abus'],
    ['POST', '#^/admin/atelier/consignes/importer$#', 'route_admin_atelier_consignes_importer'],
];

foreach ($routes as [$m, $motif, $fn]) {
    if ($m === $methode && preg_match($motif, $chemin, $args)) {
        array_shift($args);
        $fn(...array_map([Http::class, 'id'], $args));
        exit;
    }
}
Http::erreur(404, 'Route inconnue.');
