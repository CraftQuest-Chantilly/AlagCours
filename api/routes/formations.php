<?php
// Formations (promos) du stagiaire connecté : reprend PAGE__ChoixCours et PAGE__Accueil d'al-cours.

/** Inscription active du stagiaire à la promo, sinon 404 (on ne révèle pas l'existence de la promo). */
function inscription(string $codeStag, string $codePromo): array
{
    $i = Db::ligne(
        'SELECT "PromSta_Groupe" AS groupe FROM "T_Promo_Stagiaire"
          WHERE "PromSta_CodeStagiaire" = :s AND "PromSta_CodePromo" = :p AND "PromSta_Actif"
          LIMIT 1',
        [':s' => $codeStag, ':p' => $codePromo]
    );
    if (!$i) Http::erreur(404, 'Formation introuvable.');
    return $i;
}

/** Liste des formations (tableau « Choisir un cours » : Libellé, Sujet, V, SujetNiv, NbJours, Du, Au). */
function route_formations(): void
{
    $c = Jeton::stagiaire();
    $r = Db::lignes(
        'SELECT p."Pro_CodePromo"::text AS code, p."Pro_Libelle" AS libelle, p."Pro_SujetForm" AS sujet,
                p."Pro_SujetVersion" AS version, p."Pro_SujetNiv" AS niveau, p."Pro_NbJours" AS "nbJours",
                p."Pro_Du" AS du, p."Pro_Au" AS au, ps."PromSta_Groupe" AS groupe
           FROM "T_Promo_Stagiaire" ps
           JOIN "T_Promo" p ON p."Pro_CodePromo" = ps."PromSta_CodePromo"
          WHERE ps."PromSta_CodeStagiaire" = :s AND ps."PromSta_Actif"
          ORDER BY p."Pro_Du" DESC NULLS LAST, p."Pro_Libelle"',
        [':s' => $c['sub']]
    );
    Http::json($r);
}

/** Tableau de bord d'une formation (PAGE__Accueil) : en-tête + modules à afficher. */
function route_formation(string $codePromo): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    $p = Db::ligne(
        'SELECT "Pro_CodePromo"::text AS code, "Pro_Libelle" AS libelle, "Pro_SujetForm" AS sujet,
                "Pro_Du" AS du, "Pro_Au" AS au
           FROM "T_Promo" WHERE "Pro_CodePromo" = :p',
        [':p' => $codePromo]
    );
    if (!$p) Http::erreur(404, 'Formation introuvable.');

    // Modules activés pour la promo (E9, 30/09/2026) : remplace l'ancienne règle basée sur les
    // marqueurs texte de Pro_Doc1 (Contient() WLangage) par la table relationnelle
    // T_Promo_Option_Reel, désormais pilotée depuis la fiche promo (cases à cocher, côté
    // SuiviCours_Front — déjà en place, cf. T_Promo_Option/T_Promo_Option_Reel). Décision Jérôme
    // du 30/09/2026 : TOUTES les options passent par ce système, y compris « Fichiers »
    // (auparavant toujours affiché en dur, quelle que soit la promo).
    $optionsActives = array_column(
        Db::lignes(
            'SELECT "PORe_CodeOption" AS code FROM "T_Promo_Option_Reel" WHERE "PORe_CodePromo" = :p',
            [':p' => $codePromo]
        ),
        'code'
    );
    $actif = fn (string $code): bool => in_array($code, $optionsActives, true);
    // Correspondance code T_Promo_Option (POp_Code) ↔ clé du catalogue front (CATALOGUE dans
    // FormationPage.vue) : les noms diffèrent par endroits (positionnement/testPositionnement,
    // fichiers/ressources, atelier_sql/atelier) mais désignent les mêmes 7 tuiles.
    $modules = [
        'testPositionnement' => $actif('positionnement'),
        'signature'          => $actif('signature'),
        'ressources'         => $actif('fichiers'),
        'quizz'              => $actif('quizz'),
        'evaluation'         => $actif('evaluation'),
        'avis'               => $actif('avis'),
        'atelier'            => $actif('atelier_sql'),
    ];
    $p['groupe'] = $i['groupe'];
    $p['commence'] = $p['du'] !== null && $p['du'] <= date('Y-m-d');
    $p['modules'] = $modules;
    Http::json($p);
}

/** Séances de la formation visibles par le groupe du stagiaire (agenda).
 *  « Points vus » (E5) : PAS le texte libre `Cours_PointsVus` (usage interne formateur, jamais
 *  montré au stagiaire — signalé par Jérôme) mais la liste des points cochés pour la séance
 *  (`T_Cours_Points_Vus` → `T_Doc_Exo_Point.DEP_NomPoint`, ex. « Les RECHERCHEV »). Formateur :
 *  prénom + nom (les initiales sont un usage interne), plus lisible pour un stagiaire. */
function route_seances(string $codePromo): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    $r = Db::lignes(
        'SELECT co."Cours_NumCours"::text AS code, co."Cours_Date" AS date,
                to_char(co."Cours_HeureDebut", \'HH24:MI\') AS debut, to_char(co."Cours_HeureFin", \'HH24:MI\') AS fin,
                co."Cours_NumX" AS "numTd", co."Cours_Groupe" AS groupe, co."Cours_Salle" AS salle,
                co."Cours_Adresse" AS adresse,
                f."Forma_Prenom" AS "formateurPrenom", f."Forma_Nom" AS "formateurNom",
                (SELECT COALESCE(jsonb_agg(dep."DEP_NomPoint" ORDER BY dep."DEP_Ordre", dep."DEP_NomPoint"), \'[]\'::jsonb)
                   FROM "T_Cours_Points_Vus" cpv
                   JOIN "T_Doc_Exo_Point" dep ON dep."DEP_NumPoint" = cpv."CPV_NumPoint"
                  WHERE cpv."CPV_NumCours" = co."Cours_NumCours") AS "pointsVus"
           FROM "T_Cours" co
           LEFT JOIN "T_Formateur" f ON f."Forma_CodeFormateur" = co."Cours_CodeFormateur"
          WHERE co."Cours_CodePromo" = :p
            AND (co."Cours_GroupeEntier" OR COALESCE(co."Cours_Groupe", \'\') = \'\' OR co."Cours_Groupe" = :g)
          ORDER BY co."Cours_Date", co."Cours_HeureDebut"',
        [':p' => $codePromo, ':g' => (string)$i['groupe']]
    );
    Http::json($r);
}

// ─── Formateur : toutes les promos (PAGE_ChoixPromo), pour gérer les fichiers/droits d'une
// formation. Décision Jérôme du 25/09 : TOUTES les promos sont listées, pas seulement celles du
// formateur connecté (plusieurs formateurs peuvent partager la gestion des ressources) ─────────

function route_admin_promos(): void
{
    Jeton::formateur();
    $q = trim((string)($_GET['q'] ?? ''));
    $params = [];
    $filtre = '';
    if ($q !== '') {
        $filtre = 'WHERE "Pro_Libelle" ILIKE :q OR "Pro_SujetForm" ILIKE :q OR "Pro_CodePromo"::text ILIKE :q';
        $params[':q'] = '%' . $q . '%';
    }
    $r = Db::lignes(
        'SELECT "Pro_CodePromo"::text AS code, "Pro_Libelle" AS libelle, "Pro_SujetForm" AS sujet,
                "Pro_Du" AS du, "Pro_Au" AS au
           FROM "T_Promo" ' . $filtre . '
          ORDER BY "Pro_Du" DESC NULLS LAST, "Pro_Libelle" LIMIT 200',
        $params
    );
    Http::json($r);
}

// ─── Formateur : clients + leurs cours (PAGE_Fichiers_Adm, 26/09/2026) — sélection de la promo
// cible pour l'ajout groupé de fichiers, en passant par client → cours (la promo du cours cliqué
// est la cible réelle des droits, cf. décision Jérôme : « on aura le numéro de la promo. Ce qui
// permet d'associer droit sur les fichiers à la promo et non juste au cours précis. ») ──────────

function route_admin_clients(): void
{
    Jeton::formateur();
    $q = trim((string)($_GET['q'] ?? ''));
    $params = [];
    $filtre = '';
    if ($q !== '') {
        $filtre = 'WHERE "Cli_RaisonSociale" ILIKE :q';
        $params[':q'] = '%' . $q . '%';
    }
    $r = Db::lignes(
        'SELECT "Cli_CodeClient"::text AS code, "Cli_RaisonSociale" AS nom
           FROM "T_Client" ' . $filtre . '
          ORDER BY "Cli_RaisonSociale" LIMIT 50',
        $params
    );
    Http::json($r);
}

/** Cours d'un client (toutes ses promos confondues), avec filtre de date optionnel. Sans
 *  paramètre, renvoie TOUT l'historique — c'est le front qui applique par défaut « à venir,
 *  aujourd'hui compris » (depuis=aujourd'hui) et propose les bascules passé/personnalisé, pour
 *  garder la logique de date au même endroit que route_agenda(). */
function route_admin_client_cours(string $codeClient): void
{
    Jeton::formateur();
    $depuis = trim((string)($_GET['depuis'] ?? ''));
    $jusqua = trim((string)($_GET['jusqua'] ?? ''));
    $filtres = '';
    $params = [':c' => $codeClient];
    if ($depuis !== '') { $filtres .= ' AND co."Cours_Date" >= :depuis'; $params[':depuis'] = $depuis; }
    if ($jusqua !== '') { $filtres .= ' AND co."Cours_Date" <= :jusqua'; $params[':jusqua'] = $jusqua; }

    $r = Db::lignes(
        'SELECT co."Cours_NumCours"::text AS "numCours", co."Cours_Date" AS date,
                to_char(co."Cours_HeureDebut", \'HH24:MI\') AS "heureDebut",
                to_char(co."Cours_HeureFin", \'HH24:MI\') AS "heureFin",
                p."Pro_CodePromo"::text AS "codePromo", p."Pro_Libelle" AS "libellePromo",
                p."Pro_SujetForm" AS sujet, co."Cours_Salle" AS salle
           FROM "T_Cours" co
           JOIN "T_Promo" p ON p."Pro_CodePromo" = co."Cours_CodePromo"
          WHERE p."Pro_CodeClient" = :c' . $filtres . '
          ORDER BY co."Cours_Date", co."Cours_HeureDebut" LIMIT 300',
        $params
    );
    Http::json($r);
}

/** Toutes les séances de toutes les formations actives (agenda de « Mes formations »). */
function route_agenda(): void
{
    $c = Jeton::stagiaire();
    $r = Db::lignes(
        'SELECT co."Cours_NumCours"::text AS code, p."Pro_CodePromo"::text AS "codePromo", p."Pro_Libelle" AS libelle,
                co."Cours_Date" AS date,
                to_char(co."Cours_HeureDebut", \'HH24:MI\') AS debut, to_char(co."Cours_HeureFin", \'HH24:MI\') AS fin,
                co."Cours_NumX" AS "numTd"
           FROM "T_Cours" co
           JOIN "T_Promo" p ON p."Pro_CodePromo" = co."Cours_CodePromo"
           JOIN "T_Promo_Stagiaire" ps ON ps."PromSta_CodePromo" = p."Pro_CodePromo"
                AND ps."PromSta_CodeStagiaire" = :s AND ps."PromSta_Actif"
          WHERE (co."Cours_GroupeEntier" OR COALESCE(co."Cours_Groupe", \'\') = \'\' OR co."Cours_Groupe" = ps."PromSta_Groupe")
          ORDER BY co."Cours_Date", co."Cours_HeureDebut"',
        [':s' => $c['sub']]
    );
    Http::json($r);
}
