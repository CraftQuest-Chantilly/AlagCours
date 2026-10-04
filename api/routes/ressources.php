<?php
// Ressources (E4) : reprend PAGE_Fichiers_Promo_TD (stagiaire) et la brique amont de
// PAGE_Fichiers_Adm / PAGE_Dossier_Creation côté formateur (création de dossiers, dépôt de
// fichiers). T_Fichier est un arbre (Fic_DossierPère = parent), 3 types de lignes :
//  - 'D' dossier, 'F' fichier physique, 'L' lien externe (Fic_NomFic/Fic_Chem = l'URL elle-même).
// Les fichiers physiques restent sur le disque OVH (jamais dans la base) : chemin réel =
// config['fichiers_racine'] + Fic_Chem + '/' + Fic_NomFic (vérifié sur un exemple réel).
//
// CORRECTIF (2026-09-25, signalé par Jérôme après test réel — aucun fichier ne s'affichait) :
// l'hypothèse initiale « un droit posé sur un dossier s'applique en cascade à son contenu »
// était fausse, vérifié sur les droits réels en base (8210 lignes, 422 promos) : les droits
// portent presque toujours directement sur un FICHIER (ou un lien), profondément imbriqué dans
// l'arborescence, jamais sur un dossier ancêtre — donc rien ne remontait jamais jusqu'à la
// racine avec l'ancienne logique de navigation dossier par dossier. Le modèle réel (confirmé par
// la RPC déjà en base `sc_stag_fichiers()`, jamais branchée à un front) est une LISTE PLATE : un
// stagiaire voit exactement les fichiers/liens pour lesquels un droit Visible=true existe sur sa
// promo + son groupe (ou groupe '-' = tous), sans notion de dossier ni d'héritage. Ce qui reste
// vrai : `fic_chemin_disque()` pour retrouver le fichier physique, et l'arborescence complète
// (formateur uniquement, cf. plus bas) pour gérer les fichiers et poser des droits.
//
// Portée volontairement limitée pour cette étape : les fichiers déposés par un formateur ici
// n'ont PAS de droits (T_Fichier_Droits) tant que ceux-ci ne sont pas posés ailleurs (écran
// PAGE_ModifFicDroit, à construire côté formateur) — donc invisibles pour les stagiaires jusqu'à
// ce qu'un droit leur soit explicitement donné.

/** Chemin disque réel d'un élément à partir de son Fic_Chem (dossier contenant) + son nom. */
function fic_chemin_disque(string $chem, string $nom): string
{
    $racine = rtrim((string)$GLOBALS['config']['fichiers_racine'], '/\\');
    $chem = trim(str_replace('\\', '/', $chem), '/');
    $nom = str_replace(['\\', '/'], '', $nom); // sécurité : jamais de séparateur dans un nom
    return $racine . ($chem !== '' ? '/' . $chem : '') . '/' . $nom;
}

/** Liste plate des éléments (fichiers/liens, et exceptionnellement dossiers : 62 des 8210 droits
 *  en pointent un — anomalie signalée, affichés tels quels sans navigation) pour lesquels un
 *  droit Visible=true correspond à la promo + au groupe (ou '-' = tous), dans la fenêtre de
 *  dates du droit le cas échéant. */
function fic_droits_stagiaire(string $promo, string $groupe): array
{
    return Db::lignes(
        'SELECT f."Fic_NumFic" AS "numFic", f."Fic_NomFic" AS nom, f."Fic_TypeFouD" AS type,
                f."Fic_Chem" AS chem, f."Fic_TailleKO" AS "tailleKo",
                d."FicDroi_TD" AS td, d."FicDroi_Commentaire" AS commentaire
           FROM "T_Fichier_Droits" d
           JOIN "T_Fichier" f ON f."Fic_NumFic" = d."FicDroi_NumFic"
          WHERE d."FicDroi_CodePromo" = :promo AND d."FicDroi_Visible"
            AND (d."FicDroi_Groupe" = \'-\' OR d."FicDroi_Groupe" = :groupe)
            AND (d."FicDroi_Début" IS NULL OR d."FicDroi_Début" <= now())
            AND (d."FicDroi_Fin" IS NULL OR d."FicDroi_Fin" >= now())
          ORDER BY d."FicDroi_Ordre", f."Fic_NomFic"',
        [':promo' => $promo, ':groupe' => $groupe]
    );
}

/** Un droit Visible=true existe-t-il pour ce fichier précis, cette promo et ce groupe ?
 *  (utilisé par le téléchargement, mêmes critères que fic_droits_stagiaire mais sur un seul id) */
function fic_droit_stagiaire_existe(int $numFic, string $promo, string $groupe): bool
{
    $r = Db::ligne(
        'SELECT 1 AS ok FROM "T_Fichier_Droits" d
          WHERE d."FicDroi_NumFic" = :n AND d."FicDroi_CodePromo" = :promo AND d."FicDroi_Visible"
            AND (d."FicDroi_Groupe" = \'-\' OR d."FicDroi_Groupe" = :groupe)
            AND (d."FicDroi_Début" IS NULL OR d."FicDroi_Début" <= now())
            AND (d."FicDroi_Fin" IS NULL OR d."FicDroi_Fin" >= now())
          LIMIT 1',
        [':n' => $numFic, ':promo' => $promo, ':groupe' => $groupe]
    );
    return $r !== null;
}

/** Liste triée (dossiers d'abord) des enfants directs d'un dossier — utilisé côté formateur
 *  uniquement (arborescence réelle pour gérer les fichiers et les droits).
 *
 *  CORRECTIF (26/09/2026, signalé par Jérôme — panneau racine vide en production) : la racine
 *  était recherchée avec "Fic_DossierPère IS NULL", en supposant NULL comme valeur-sentinelle.
 *  Vérifié en base réelle : ZÉRO ligne n'a Fic_DossierPère à NULL ; les 12 vrais dossiers racine
 *  (Access, Excel, PowerBI, …) ont tous Fic_DossierPère = 0 — c'est la convention réelle héritée
 *  de l'app WebDev d'origine. $numFic vaut désormais 0 (jamais null) pour désigner la racine,
 *  passé explicitement par route_admin_ressources_dossier(). */
function fic_enfants(int $numFic): array
{
    return Db::lignes(
        'SELECT "Fic_NumFic" AS "numFic", "Fic_NomFic" AS nom, "Fic_TypeFouD" AS type,
                "Fic_Commentaire" AS commentaire, "Fic_TailleKO" AS "tailleKo", "Fic_Chem" AS chem
           FROM "T_Fichier" WHERE "Fic_DossierPère" = :p
          ORDER BY ("Fic_TypeFouD" = \'D\') DESC, "Fic_NomFic"',
        [':p' => $numFic]
    );
}

function fic_vers_json(array $e): array
{
    $item = [
        'numFic' => (string)$e['numFic'], 'nom' => $e['nom'], 'type' => $e['type'],
        'commentaire' => $e['commentaire'], 'tailleKo' => $e['tailleKo'],
    ];
    if (array_key_exists('td', $e)) $item['td'] = $e['td'];
    if ($e['type'] === 'L') $item['url'] = $e['chem']; // lien : l'URL est stockée dans Fic_Chem
    return $item;
}

// ─── Stagiaire : liste plate des fichiers/liens droités pour sa promo + son groupe ───────────

function route_ressources_dossier(string $codePromo): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    $groupe = (string)$i['groupe'];

    $resultat = array_map('fic_vers_json', fic_droits_stagiaire($codePromo, $groupe));
    Http::json(['fichiers' => $resultat]);
}

function route_ressources_telecharger(string $codePromo, string $numFicParam): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    $groupe = (string)$i['groupe'];
    $numFic = (int)$numFicParam;

    $f = Db::ligne('SELECT * FROM "T_Fichier" WHERE "Fic_NumFic" = :n', [':n' => $numFic]);
    if (!$f || $f['Fic_TypeFouD'] !== 'F') Http::erreur(404, 'Fichier introuvable.');
    if (!fic_droit_stagiaire_existe($numFic, $codePromo, $groupe)) Http::erreur(404, 'Fichier introuvable.');

    $chemin = fic_chemin_disque((string)$f['Fic_Chem'], (string)$f['Fic_NomFic']);
    if (!is_file($chemin)) Http::erreur(404, 'Fichier absent du serveur.');

    Db::exec(
        'INSERT INTO "TS_Download" ("Dow_Date","Dow_Heure","Dow_Identifiant","Dow_IP","Dow_Fichier","Dow_CodeUser","Dow_Info","Dow_CodePromo","Dow_Groupe")
         VALUES (:d, :h, :id, :ip, :fic, :cu, :info, :promo, :groupe)',
        [
            ':d' => date('Y-m-d'), ':h' => date('H:i:s'),
            ':id' => mb_substr(trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? '')), 0, 50),
            ':ip' => Http::ip(), ':fic' => mb_substr((string)$f['Fic_NomFic'], 0, 100),
            ':cu' => $c['sub'], ':info' => 'Portail alcours', ':promo' => $codePromo, ':groupe' => $groupe,
        ]
    );

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($chemin));
    header('Content-Disposition: attachment; filename="' . rawurlencode((string)$f['Fic_NomFic']) . '"');
    header('Cache-Control: no-store');
    readfile($chemin);
    exit;
}

// ─── Formateur : arborescence complète (sans filtre de droits) + création / dépôt ───────────

function route_admin_ressources_dossier(?string $numFicParam = null): void
{
    Jeton::formateur();
    // 0 = racine (voir le correctif documenté sur fic_enfants() : la vraie valeur-sentinelle en
    // base est 0, pas NULL).
    $numFic = $numFicParam !== null ? (int)$numFicParam : 0;
    $resultat = array_map('fic_vers_json', fic_enfants($numFic));
    Http::json(['dossier' => $numFic !== 0 ? (string)$numFic : null, 'fichiers' => $resultat]);
}

/** Recherche de fichiers/liens par nom dans l'arbre global (1319 fichiers) — alternative à la
 *  navigation dossier par dossier pour retrouver rapidement un fichier à rattacher à une promo. */
function route_admin_ressources_recherche(): void
{
    Jeton::formateur();
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) Http::json([]);
    $r = Db::lignes(
        'SELECT "Fic_NumFic" AS "numFic", "Fic_NomFic" AS nom, "Fic_TypeFouD" AS type,
                "Fic_Chem" AS chem, "Fic_Commentaire" AS commentaire, "Fic_TailleKO" AS "tailleKo"
           FROM "T_Fichier"
          WHERE "Fic_TypeFouD" IN (\'F\',\'L\') AND "Fic_NomFic" ILIKE :q
          ORDER BY "Fic_NomFic" LIMIT 50',
        [':q' => '%' . $q . '%']
    );
    Http::json(array_map('fic_vers_json', $r));
}

/** Téléchargement d'un fichier par le formateur, SANS filtre de droit (contrairement à
 *  route_ressources_telecharger, réservé au stagiaire) — pour vérifier le contenu d'un fichier
 *  avant/après l'avoir rattaché à une promo (PAGE_Fichiers_Adm, 26/09/2026). N'écrit PAS dans
 *  TS_Download : ce journal trace les téléchargements STAGIAIRE (Dow_CodeUser/Dow_Groupe n'ont
 *  pas de sens pour un formateur), un usage différent hors périmètre de cette table. */
function route_admin_ressources_telecharger(string $numFicParam): void
{
    Jeton::formateur();
    $numFic = (int)$numFicParam;
    $f = Db::ligne('SELECT * FROM "T_Fichier" WHERE "Fic_NumFic" = :n AND "Fic_TypeFouD" = \'F\'', [':n' => $numFic]);
    if (!$f) Http::erreur(404, 'Fichier introuvable.');

    $chemin = fic_chemin_disque((string)$f['Fic_Chem'], (string)$f['Fic_NomFic']);
    if (!is_file($chemin)) Http::erreur(404, 'Fichier absent du serveur.');

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($chemin));
    header('Content-Disposition: attachment; filename="' . rawurlencode((string)$f['Fic_NomFic']) . '"');
    header('Cache-Control: no-store');
    readfile($chemin);
    exit;
}

// ─── Formateur : droits (PAGE_ModifFicDroit) — rattacher un fichier existant à une promo,
// avec groupe, regroupement (TD), visibilité, période, ordre d'affichage, commentaire ─────────

/** Groupes réels de stagiaires de la promo (pour le sélecteur — en plus de "-" = tous groupes,
 *  toujours proposé). Lister les groupes réels évite une faute de frappe qui rendrait un droit
 *  invisible à tout le monde sans que ce soit visible dans cet écran. */
function route_admin_promo_groupes(string $codePromo): void
{
    Jeton::formateur();
    $r = Db::lignes(
        'SELECT DISTINCT "PromSta_Groupe" AS groupe FROM "T_Promo_Stagiaire"
          WHERE "PromSta_CodePromo" = :p AND "PromSta_Actif" AND COALESCE("PromSta_Groupe", \'\') <> \'\'
          ORDER BY 1',
        [':p' => $codePromo]
    );
    Http::json(array_values(array_map(static fn($x) => $x['groupe'], $r)));
}

/** Regroupements (TD) déjà utilisés sur cette promo, pour l'autocomplétion du champ libre
 *  (décision Jérôme du 25/09 : texte libre, pas une liste de regroupements à créer d'abord). */
function route_admin_promo_td(string $codePromo): void
{
    Jeton::formateur();
    $r = Db::lignes(
        'SELECT DISTINCT "FicDroi_TD" AS td FROM "T_Fichier_Droits"
          WHERE "FicDroi_CodePromo" = :p AND "FicDroi_TD" IS NOT NULL AND "FicDroi_TD" <> \'-\'
          ORDER BY 1',
        [':p' => $codePromo]
    );
    Http::json(array_values(array_map(static fn($x) => $x['td'], $r)));
}

/** Tous les droits (fichiers rattachés) de cette promo, tous groupes confondus — vue de gestion
 *  formateur (contrairement à fic_droits_stagiaire(), pas de filtre Visible/groupe/fenêtre : le
 *  formateur doit voir aussi ce qu'un stagiaire ne verrait pas encore/plus). */
function route_admin_promo_droits(string $codePromo): void
{
    Jeton::formateur();
    $r = Db::lignes(
        'SELECT d."FicDroi_Id"::text AS id, f."Fic_NumFic"::text AS "numFic", f."Fic_NomFic" AS nom,
                f."Fic_TypeFouD" AS type, f."Fic_Chem" AS chem,
                d."FicDroi_Groupe" AS groupe, d."FicDroi_TD" AS td, d."FicDroi_Visible" AS visible,
                d."FicDroi_Commentaire" AS commentaire, d."FicDroi_Ordre" AS ordre,
                d."FicDroi_Début" AS debut, d."FicDroi_Fin" AS fin,
                d."FicDroi_Fait" AS fait, d."FicDroi_FaitLe" AS "faitLe"
           FROM "T_Fichier_Droits" d
           JOIN "T_Fichier" f ON f."Fic_NumFic" = d."FicDroi_NumFic"
          WHERE d."FicDroi_CodePromo" = :p
          ORDER BY d."FicDroi_Ordre", f."Fic_NomFic"',
        [':p' => $codePromo]
    );
    Http::json($r);
}

/** Rattache un fichier déjà existant dans l'arbre (trouvé via recherche ou navigation) à cette
 *  promo : crée une ligne T_Fichier_Droits. Ne touche jamais au fichier physique lui-même. */
function route_admin_promo_droit_creer(string $codePromo): void
{
    Jeton::formateur();
    $d = Http::corps();
    $numFic = (int)($d['numFic'] ?? 0);
    if ($numFic <= 0) Http::erreur(400, 'Fichier manquant.');
    if (!Db::ligne('SELECT 1 AS x FROM "T_Fichier" WHERE "Fic_NumFic" = :n', [':n' => $numFic])) {
        Http::erreur(404, 'Fichier introuvable.');
    }

    $groupe = trim((string)($d['groupe'] ?? '')) ?: '-';
    $td = trim((string)($d['td'] ?? ''));
    $commentaire = trim((string)($d['commentaire'] ?? ''));
    $ordre = trim((string)($d['ordre'] ?? ''));
    $debut = trim((string)($d['debut'] ?? ''));
    $fin = trim((string)($d['fin'] ?? ''));

    // FicDroi_Fait n'est pas renseigné ici : colonne à défaut ("false") côté base, sans lien
    // avec ce formulaire (usage historique différent, hors périmètre de cette étape).
    $ligne = Db::ligne(
        'INSERT INTO "T_Fichier_Droits"
            ("FicDroi_NumFic","FicDroi_CodePromo","FicDroi_Groupe","FicDroi_Visible","FicDroi_TD",
             "FicDroi_Commentaire","FicDroi_Ordre","FicDroi_Début","FicDroi_Fin")
         VALUES (:n, :p, :g, :v, :td, :c, :o, :d, :f)
         RETURNING "FicDroi_Id"::text AS id',
        [
            ':n' => $numFic, ':p' => $codePromo, ':g' => $groupe, ':v' => (bool)($d['visible'] ?? true),
            ':td' => $td !== '' ? $td : null, ':c' => $commentaire !== '' ? $commentaire : null,
            ':o' => $ordre !== '' ? $ordre : null,
            ':d' => $debut !== '' ? $debut : null, ':f' => $fin !== '' ? $fin : null,
        ]
    );
    Http::json(['id' => $ligne['id']], 201);
}

/** Rattache PLUSIEURS fichiers déjà existants à cette promo en une fois, avec le même
 *  regroupement (TD) et les mêmes réglages — PAGE_Fichiers_Adm (26/09/2026) : sélection multiple
 *  par case à cocher sur l'arborescence, ajout groupé en un clic. Contrairement à
 *  route_admin_promo_droit_creer(), le TD est obligatoire ici (c'est le principe même de l'écran :
 *  on tape le regroupement puis on y ajoute des fichiers). Un fichier déjà rattaché à cette promo
 *  avec EXACTEMENT ce groupe + ce TD est sauté (pas de doublon silencieux de droit) plutôt que
 *  dupliqué — signalé dans le résultat par fichier, pas une erreur globale. */
function route_admin_promo_droits_lot(string $codePromo): void
{
    Jeton::formateur();
    $d = Http::corps();
    $numFics = $d['numFics'] ?? [];
    if (!is_array($numFics) || !$numFics) Http::erreur(400, 'Aucun fichier sélectionné.');

    $groupe = trim((string)($d['groupe'] ?? '')) ?: '-';
    $td = trim((string)($d['td'] ?? ''));
    $commentaire = trim((string)($d['commentaire'] ?? ''));
    $ordre = trim((string)($d['ordre'] ?? ''));
    $debut = trim((string)($d['debut'] ?? ''));
    $fin = trim((string)($d['fin'] ?? ''));
    if ($td === '') Http::erreur(400, 'Le regroupement (TD) est obligatoire pour un ajout groupé.');

    $resultats = [];
    foreach ($numFics as $nf) {
        $numFic = (int)$nf;
        if ($numFic <= 0) {
            $resultats[] = ['numFic' => (string)$nf, 'ok' => false, 'erreur' => 'Identifiant invalide.'];
            continue;
        }
        $f = Db::ligne('SELECT "Fic_NomFic" AS nom FROM "T_Fichier" WHERE "Fic_NumFic" = :n', [':n' => $numFic]);
        if (!$f) {
            $resultats[] = ['numFic' => (string)$numFic, 'ok' => false, 'erreur' => 'Fichier introuvable.'];
            continue;
        }
        $existe = Db::ligne(
            'SELECT 1 AS x FROM "T_Fichier_Droits"
              WHERE "FicDroi_NumFic" = :n AND "FicDroi_CodePromo" = :p
                AND "FicDroi_Groupe" = :g AND "FicDroi_TD" = :td',
            [':n' => $numFic, ':p' => $codePromo, ':g' => $groupe, ':td' => $td]
        );
        if ($existe) {
            $resultats[] = ['numFic' => (string)$numFic, 'nom' => $f['nom'], 'ok' => false, 'erreur' => 'Déjà rattaché à ce regroupement.'];
            continue;
        }

        $ligne = Db::ligne(
            'INSERT INTO "T_Fichier_Droits"
                ("FicDroi_NumFic","FicDroi_CodePromo","FicDroi_Groupe","FicDroi_Visible","FicDroi_TD",
                 "FicDroi_Commentaire","FicDroi_Ordre","FicDroi_Début","FicDroi_Fin")
             VALUES (:n, :p, :g, :v, :td, :c, :o, :d, :f)
             RETURNING "FicDroi_Id"::text AS id',
            [
                ':n' => $numFic, ':p' => $codePromo, ':g' => $groupe, ':v' => (bool)($d['visible'] ?? true),
                ':td' => $td, ':c' => $commentaire !== '' ? $commentaire : null,
                ':o' => $ordre !== '' ? $ordre : null,
                ':d' => $debut !== '' ? $debut : null, ':f' => $fin !== '' ? $fin : null,
            ]
        );
        $resultats[] = ['numFic' => (string)$numFic, 'nom' => $f['nom'], 'ok' => true, 'id' => $ligne['id']];
    }
    $nbOk = count(array_filter($resultats, fn($r) => $r['ok']));
    Http::json(['nbAjoutes' => $nbOk, 'nbTotal' => count($resultats), 'resultats' => $resultats]);
}

/** Modifie un droit existant (groupe, TD, visibilité, commentaire, ordre, période, et maintenant
 *  "Fait" — PAGE_Fichiers_Adm, 26/09/2026 : Jérôme veut pouvoir cocher directement dans l'aperçu
 *  si le regroupement a été fait ou non, en plus de visible/non visible). "fait" est optionnel
 *  dans le corps (les appelants qui ne le gèrent pas, ex. anciens clients, laissent la valeur en
 *  base inchangée) ; quand il est fourni, "FicDroi_FaitLe" est calculé côté serveur — jamais
 *  envoyé par le client — pour dater le PREMIER passage à "fait" (repasser à "fait" alors que
 *  c'est déjà "fait" ne change pas la date ; décocher efface la date). */
function route_admin_droit_modifier(string $idParam): void
{
    Jeton::formateur();
    $id = (int)$idParam;
    $actuel = Db::ligne('SELECT "FicDroi_Fait" FROM "T_Fichier_Droits" WHERE "FicDroi_Id" = :i', [':i' => $id]);
    if (!$actuel) Http::erreur(404, 'Droit introuvable.');

    $d = Http::corps();
    $groupe = trim((string)($d['groupe'] ?? '')) ?: '-';
    $td = trim((string)($d['td'] ?? ''));
    $commentaire = trim((string)($d['commentaire'] ?? ''));
    $ordre = trim((string)($d['ordre'] ?? ''));
    $debut = trim((string)($d['debut'] ?? ''));
    $fin = trim((string)($d['fin'] ?? ''));

    $faitAvant = (bool)$actuel['FicDroi_Fait'];
    $faitFourni = array_key_exists('fait', $d);
    $fait = $faitFourni ? (bool)$d['fait'] : $faitAvant;
    if (!$faitFourni) {
        $faitLeSql = '"FicDroi_FaitLe"'; // non touché
    } elseif ($fait && $faitAvant) {
        $faitLeSql = '"FicDroi_FaitLe"'; // déjà fait : on ne réécrit pas la date d'origine
    } elseif ($fait) {
        $faitLeSql = ':faitLe';
    } else {
        $faitLeSql = 'NULL';
    }

    $params = [
        ':g' => $groupe, ':v' => (bool)($d['visible'] ?? true), ':td' => $td !== '' ? $td : null,
        ':c' => $commentaire !== '' ? $commentaire : null, ':o' => $ordre !== '' ? $ordre : null,
        ':d' => $debut !== '' ? $debut : null, ':f' => $fin !== '' ? $fin : null, ':i' => $id, ':fait' => $fait,
    ];
    if ($faitLeSql === ':faitLe') $params[':faitLe'] = date('Y-m-d');

    Db::exec(
        'UPDATE "T_Fichier_Droits" SET
            "FicDroi_Groupe" = :g, "FicDroi_Visible" = :v, "FicDroi_TD" = :td,
            "FicDroi_Commentaire" = :c, "FicDroi_Ordre" = :o, "FicDroi_Début" = :d, "FicDroi_Fin" = :f,
            "FicDroi_Fait" = :fait, "FicDroi_FaitLe" = ' . $faitLeSql . '
          WHERE "FicDroi_Id" = :i',
        $params
    );
    Http::json(['ok' => true]);
}

/** Supprime un droit : retire le fichier de cette promo (ne supprime jamais le fichier physique
 *  ni sa ligne T_Fichier, qui peut être rattachée à d'autres promos). */
function route_admin_droit_supprimer(string $idParam): void
{
    Jeton::formateur();
    Db::exec('DELETE FROM "T_Fichier_Droits" WHERE "FicDroi_Id" = :i', [':i' => (int)$idParam]);
    Http::json(['ok' => true]);
}

/** Chem à donner aux enfants d'un dossier = son propre Chem + son nom. */
function fic_dossier_pour_enfants(array $dossier): string
{
    $chem = trim((string)$dossier['Fic_Chem'], '/');
    return ($chem !== '' ? '/' . $chem : '') . '/' . $dossier['Fic_NomFic'];
}

function route_admin_ressources_creer_dossier(string $numFicParam): void
{
    Jeton::formateur();
    $parent = (int)$numFicParam;
    $p = Db::ligne('SELECT * FROM "T_Fichier" WHERE "Fic_NumFic" = :n AND "Fic_TypeFouD" = \'D\'', [':n' => $parent]);
    if (!$p) Http::erreur(404, 'Dossier parent introuvable.');

    $d = Http::corps();
    $nom = trim((string)($d['nom'] ?? ''));
    if ($nom === '' || preg_match('#[\\\\/]#', $nom)) Http::erreur(400, 'Nom de dossier invalide.');

    $chemEnfants = fic_dossier_pour_enfants($p);
    $chemin = fic_chemin_disque((string)$p['Fic_Chem'], (string)$p['Fic_NomFic']) . '/' . $nom;
    if (!is_dir($chemin) && !mkdir($chemin, 0755, true)) Http::erreur(500, 'Impossible de créer le dossier sur le serveur.');

    $id = Db::ligne(
        'INSERT INTO "T_Fichier" ("Fic_NomFic","Fic_Chem","Fic_DossierPère","Fic_TypeFouD","Fic_DateCréation","Fic_InvisibleDeBase")
         VALUES (:nom, :chem, :parent, \'D\', :date, false) RETURNING "Fic_NumFic" AS id',
        [':nom' => $nom, ':chem' => $chemEnfants, ':parent' => $parent, ':date' => date('Y-m-d')]
    );
    Http::json(['numFic' => (string)$id['id'], 'nom' => $nom, 'type' => 'D'], 201);
}

function route_admin_ressources_upload(string $numFicParam): void
{
    Jeton::formateur();
    $parent = (int)$numFicParam;
    $p = Db::ligne('SELECT * FROM "T_Fichier" WHERE "Fic_NumFic" = :n AND "Fic_TypeFouD" = \'D\'', [':n' => $parent]);
    if (!$p) Http::erreur(404, 'Dossier parent introuvable.');

    if (empty($_FILES['fichier']) || ($_FILES['fichier']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        Http::erreur(400, "Fichier manquant ou erreur d'upload.");
    }
    $f = $_FILES['fichier'];
    $nom = basename(str_replace('\\', '/', (string)$f['name']));
    if ($nom === '' || preg_match('#[\\\\/]#', $nom)) Http::erreur(400, 'Nom de fichier invalide.');

    $destDir = fic_chemin_disque((string)$p['Fic_Chem'], (string)$p['Fic_NomFic']);
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) Http::erreur(500, 'Dossier de destination introuvable sur le serveur.');
    $dest = $destDir . '/' . $nom;

    if (is_file($dest)) Http::erreur(409, 'Un fichier du même nom existe déjà dans ce dossier.');
    if (!move_uploaded_file($f['tmp_name'], $dest)) Http::erreur(500, "Échec de l'enregistrement du fichier sur le serveur.");

    $tailleKo = (int)round(filesize($dest) / 1024);
    $id = Db::ligne(
        'INSERT INTO "T_Fichier" ("Fic_NomFic","Fic_Chem","Fic_DossierPère","Fic_TypeFouD","Fic_TailleKO","Fic_DateCréation","Fic_InvisibleDeBase")
         VALUES (:nom, :chem, :parent, \'F\', :taille, :date, false) RETURNING "Fic_NumFic" AS id',
        [':nom' => $nom, ':chem' => fic_dossier_pour_enfants($p), ':parent' => $parent, ':taille' => $tailleKo, ':date' => date('Y-m-d')]
    );
    Http::json(['numFic' => (string)$id['id'], 'nom' => $nom, 'type' => 'F', 'tailleKo' => $tailleKo], 201);
}
