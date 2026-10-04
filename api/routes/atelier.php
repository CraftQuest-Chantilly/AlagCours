<?php
// E8 — Atelier SQL. Remplace SqlRA (PAGE_CodeSQL/PAGE_ResultatSQL/PAGE_PresentationBase/…).
// Architecture validée avec Jérôme le 25/09/2026 (voir claude/plan-etape-6-atelier-sql.md) :
// plusieurs "bases" (schémas + données) paramétrables, exécution en lecture seule (SELECT
// uniquement) via un schéma Postgres "bac à sable" isolé + rôle Postgres restreint (voir la
// migration atelier_sql_infra : sc_sql_atelier_executer / sc_sql_atelier_schema / sc_sql_atelier_corriger,
// toutes SECURITY DEFINER, dont l'exécution réelle passe par une fonction interne dont le
// PROPRIÉTAIRE est le rôle restreint sqlra_lecteur — pas de SET ROLE, interdit par Postgres à
// l'intérieur d'une fonction SECURITY DEFINER, d'où ce détour par la propriété de fonction).
//
// Deux modes :
//   - Entraînement libre : le stagiaire choisit une base, tape ce qu'il veut, voit le résultat.
//   - Partiel : session chronométrée et limitée (tentatives max par question), auto-corrigée par
//     comparaison tolérante à l'ordre avec une requête de référence (sc_sql_atelier_corriger).
//     Consignes + requêtes de référence saisies par le formateur (mini-écran, routes /admin/atelier/*).

/** Liste des bases actives proposées à l'entraînement libre — aussi utilisée par le mini-écran
 *  formateur (sélecteur de base à la création d'un partiel), d'où Jeton::utilisateur() (stagiaire
 *  OU formateur) plutôt que Jeton::stagiaire(). */
function route_atelier_bases(): void
{
    Jeton::utilisateur();
    $r = Db::lignes(
        'SELECT "SAB_Code" AS code, "SAB_Nom" AS nom, "SAB_Description" AS description
           FROM "T_SqlAtelier_Base" WHERE "SAB_Actif" ORDER BY "SAB_Nom"'
    );
    Http::json($r);
}

/** Schéma (tables/colonnes) d'une base, pour affichage à côté de l'éditeur SQL.
 *  Code de base passé en query string (?base=ecole), pas en segment d'URL numérique. */
function route_atelier_schema(): void
{
    Jeton::utilisateur();
    $base = (string)($_GET['base'] ?? '');
    if (!preg_match('/^[a-z0-9_-]+$/', $base)) Http::erreur(400, 'Base invalide.');
    $r = Db::ligne('SELECT sc_sql_atelier_schema(:b) AS tables', [':b' => $base]);
    Http::json(['tables' => $r['tables'] ?? []]);
}

/** Exécute une requête SELECT du stagiaire en entraînement libre (lecture seule, bac à sable). */
function route_atelier_executer(): void
{
    $c = Jeton::utilisateur();
    $corps = Http::corps();
    $base = (string)($corps['base'] ?? '');
    $requete = trim((string)($corps['requete'] ?? ''));
    if (!preg_match('/^[a-z0-9_-]+$/', $base)) Http::erreur(400, 'Base invalide.');
    if ($requete === '') Http::erreur(400, 'Requête vide.');

    // Db::ligne()/lignes() quittent le script sur toute erreur (comportement voulu pour la
    // plupart des routes) : une requête SQL invalide tapée par un stagiaire n'est pas une panne
    // serveur, donc on utilise Db::tenter() qui renvoie l'erreur au lieu de terminer le script.
    $r = Db::tenter('SELECT sc_sql_atelier_executer(:b, :r) AS lignes', [':b' => $base, ':r' => $requete]);

    // Journalisation (E8bis, 26/09/2026) : l'entraînement libre n'était jusqu'ici jamais tracé,
    // contrairement aux consignes/partiels — impossible de détecter un abus de lancement de
    // requêtes sans ça (cf. route_admin_atelier_abus ci-dessous).
    if ($c['typ'] === 'stagiaire') {
        Db::exec(
            'INSERT INTO "T_SqlAtelier_Execution_Log" ("SAEL_CodeStagiaire","SAEL_Base","SAEL_Ok") VALUES (:s, :b, :ok)',
            [':s' => $c['sub'], ':b' => $base, ':ok' => $r['ok']]
        );
    }

    if (!$r['ok']) Http::erreur(400, atelier_message_erreur($r['erreur']));
    Http::json(['lignes' => $r['ligne']['lignes'] ?? []]);
}

/** Extrait un message lisible du texte d'erreur Postgres renvoyé par exec_sql (format
 *  "SQLSTATE[xxxxx]: …: n ERROR:  message\nCONTEXT: …\nLINE …\nQUERY: …" — on ne garde que le
 *  texte entre "ERROR:" et la ligne suivante, jamais le CONTEXT/QUERY qui exposerait la requête
 *  SQL interne). */
function atelier_message_erreur(string $messageBrut): string
{
    if ($messageBrut !== '' && preg_match('/ERROR:\s*(.+?)(?:\r?\n|$)/s', $messageBrut, $m)) {
        $msg = trim($m[1]);
        if ($msg !== '') return $msg;
    }
    return 'Requête invalide.';
}

/** Liste des partiels de la promo visibles par le stagiaire connecté (avec état de la fenêtre). */
function route_atelier_partiels(string $codePromo): void
{
    $c = Jeton::stagiaire();
    inscription($c['sub'], $codePromo);

    $r = Db::lignes(
        'SELECT "SAP_NumPartiel" AS "numPartiel", "SAP_Titre" AS titre, "SAP_Base" AS base,
                "SAP_Debut" AS debut, "SAP_Fin" AS fin, "SAP_TentativesMax" AS "tentativesMax",
                (now() BETWEEN "SAP_Debut" AND "SAP_Fin") AS "dansFenetre",
                (now() < "SAP_Debut") AS "pasEncoreCommence"
           FROM "T_SqlAtelier_Partiel" WHERE "SAP_CodePromo" = :p ORDER BY "SAP_Debut" DESC',
        [':p' => $codePromo]
    );
    Http::json($r);
}

/** Questions d'un partiel (sans la requête de référence) + tentatives déjà faites par le stagiaire. */
function route_atelier_partiel_questions(string $codePromo, string $numPartiel): void
{
    $c = Jeton::stagiaire();
    inscription($c['sub'], $codePromo);

    $partiel = atelier_partiel_verifier($codePromo, $numPartiel);

    $questions = Db::lignes(
        'SELECT q."SAPQ_NumQuestion" AS "numQuestion", q."SAPQ_Ordre" AS ordre, q."SAPQ_Consigne" AS consigne,
                q."SAPQ_Bareme" AS bareme,
                (SELECT count(*) FROM "T_SqlAtelier_Partiel_Reponse" r
                  WHERE r."SAPR_NumQuestion" = q."SAPQ_NumQuestion" AND r."SAPR_CodeStagiaire" = :s) AS tentatives,
                (SELECT bool_or(r."SAPR_Correcte") FROM "T_SqlAtelier_Partiel_Reponse" r
                  WHERE r."SAPR_NumQuestion" = q."SAPQ_NumQuestion" AND r."SAPR_CodeStagiaire" = :s) AS "dejaReussi"
           FROM "T_SqlAtelier_Partiel_Question" q
          WHERE q."SAPQ_NumPartiel" = :n
          ORDER BY q."SAPQ_Ordre", q."SAPQ_NumQuestion"',
        [':n' => $numPartiel, ':s' => $c['sub']]
    );
    Http::json(['partiel' => $partiel, 'questions' => $questions]);
}

/** Soumet une tentative de réponse à une question de partiel (auto-corrigée). */
function route_atelier_partiel_repondre(string $codePromo, string $numPartiel, string $numQuestion): void
{
    $c = Jeton::stagiaire();
    inscription($c['sub'], $codePromo);

    $partiel = atelier_partiel_verifier($codePromo, $numPartiel);
    if (!$partiel['dansFenetre']) Http::erreur(403, 'Ce partiel n\'est pas ouvert actuellement.');

    $question = Db::ligne(
        'SELECT "SAPQ_NumQuestion" AS "numQuestion", "SAPQ_RequeteRef" AS "requeteRef"
           FROM "T_SqlAtelier_Partiel_Question" WHERE "SAPQ_NumQuestion" = :q AND "SAPQ_NumPartiel" = :n',
        [':q' => $numQuestion, ':n' => $numPartiel]
    );
    if (!$question) Http::erreur(404, 'Question introuvable.');

    $tentatives = Db::ligne(
        'SELECT count(*) AS n FROM "T_SqlAtelier_Partiel_Reponse" WHERE "SAPR_NumQuestion" = :q AND "SAPR_CodeStagiaire" = :s',
        [':q' => $numQuestion, ':s' => $c['sub']]
    );
    if ((int)$tentatives['n'] >= (int)$partiel['tentativesMax']) {
        Http::erreur(409, 'Nombre maximum de tentatives atteint pour cette question.');
    }

    $corps = Http::corps();
    $requete = trim((string)($corps['requete'] ?? ''));
    if ($requete === '') Http::erreur(400, 'Requête vide.');

    $res = Db::tenter(
        'SELECT sc_sql_atelier_corriger(:b, :req, :ref) AS r',
        [':b' => $partiel['base'], ':req' => $requete, ':ref' => $question['requeteRef']]
    );
    $correcte = $res['ok'] ? (bool)($res['ligne']['r']['correcte'] ?? false) : false;
    $erreur = $res['ok'] ? null : atelier_message_erreur($res['erreur']);

    Db::exec(
        'INSERT INTO "T_SqlAtelier_Partiel_Reponse"
            ("SAPR_NumQuestion", "SAPR_CodeStagiaire", "SAPR_Requete", "SAPR_Correcte", "SAPR_Erreur")
         VALUES (:q, :s, :r, :c, :e)',
        [':q' => $numQuestion, ':s' => $c['sub'], ':r' => $requete, ':c' => $correcte, ':e' => $erreur]
    );

    Http::json(['correcte' => $correcte, 'erreur' => $erreur, 'tentativesRestantes' => (int)$partiel['tentativesMax'] - (int)$tentatives['n'] - 1]);
}

/** Vérifie qu'un partiel existe pour cette promo et renvoie son état (fenêtre, base, tentatives). */
function atelier_partiel_verifier(string $codePromo, string $numPartiel): array
{
    $p = Db::ligne(
        'SELECT "SAP_NumPartiel" AS "numPartiel", "SAP_Titre" AS titre, "SAP_Base" AS base,
                "SAP_Fin" AS fin, "SAP_TentativesMax" AS "tentativesMax",
                (now() BETWEEN "SAP_Debut" AND "SAP_Fin") AS "dansFenetre"
           FROM "T_SqlAtelier_Partiel" WHERE "SAP_NumPartiel" = :n AND "SAP_CodePromo" = :p',
        [':n' => $numPartiel, ':p' => $codePromo]
    );
    if (!$p) Http::erreur(404, 'Partiel introuvable.');
    return $p;
}

// --- Formateur : mini-écran de création d'un partiel (en attendant l'interface formateur complète) ---

/** Formateur : crée un partiel avec ses questions. */
function route_admin_atelier_partiel_creer(): void
{
    $f = Jeton::formateur();
    $corps = Http::corps();

    $codePromo = (string)($corps['codePromo'] ?? '');
    $base = (string)($corps['base'] ?? '');
    $titre = trim((string)($corps['titre'] ?? ''));
    $debut = (string)($corps['debut'] ?? '');
    $fin = (string)($corps['fin'] ?? '');
    $tentativesMax = (int)($corps['tentativesMax'] ?? 5);
    $questions = $corps['questions'] ?? null;

    if (!preg_match('/^\d{1,19}$/', $codePromo)) Http::erreur(400, 'Promo invalide.');
    if ($base === '' || $titre === '' || $debut === '' || $fin === '') Http::erreur(400, 'Champs manquants.');
    if (!is_array($questions) || count($questions) === 0) Http::erreur(400, 'Au moins une question requise.');

    $baseExiste = Db::ligne('SELECT 1 AS ok FROM "T_SqlAtelier_Base" WHERE "SAB_Code" = :b AND "SAB_Actif"', [':b' => $base]);
    if (!$baseExiste) Http::erreur(400, 'Base inconnue ou inactive.');

    // $debut/$fin arrivent en texte "naïf" (ex. 2026-09-25T18:06:39, sans offset — c'est ce que
    // produira le <input type="datetime-local"> du futur écran formateur) et représentent l'heure
    // murale Europe/Paris choisie par le formateur, PAS de l'UTC. Un simple cast vers timestamptz
    // laisse Postgres interpréter la chaîne avec le fuseau de la session (constaté : décalage
    // silencieux, dansFenetre faussé). On force donc explicitement l'interprétation en
    // Europe/Paris, même idiome que signature.php ("now() AT TIME ZONE 'Europe/Paris'").
    $partiel = Db::ligne(
        'INSERT INTO "T_SqlAtelier_Partiel"
            ("SAP_CodePromo", "SAP_Base", "SAP_Titre", "SAP_Debut", "SAP_Fin", "SAP_TentativesMax", "SAP_CodeFormateur")
         VALUES (:p, :b, :t, :d::timestamp AT TIME ZONE \'Europe/Paris\', :f::timestamp AT TIME ZONE \'Europe/Paris\', :tm, :fo)
         RETURNING "SAP_NumPartiel" AS "numPartiel"',
        [':p' => $codePromo, ':b' => $base, ':t' => $titre, ':d' => $debut, ':f' => $fin, ':tm' => $tentativesMax, ':fo' => $f['sub']]
    );

    $ordre = 0;
    foreach ($questions as $q) {
        $consigne = trim((string)($q['consigne'] ?? ''));
        $requeteRef = trim((string)($q['requeteRef'] ?? ''));
        $bareme = (float)($q['bareme'] ?? 1);
        if ($consigne === '' || $requeteRef === '') Http::erreur(400, 'Consigne et requête de référence requises pour chaque question.');
        // \y est la syntaxe Postgres pour une frontière de mot (utilisée côté SQL dans les
        // fonctions sc_sql_atelier_*) ; en PCRE (PHP) c'est \b.
        if (!preg_match('/^select\b/i', $requeteRef)) Http::erreur(400, 'La requête de référence doit être un SELECT.');
        Db::exec(
            'INSERT INTO "T_SqlAtelier_Partiel_Question"
                ("SAPQ_NumPartiel", "SAPQ_Ordre", "SAPQ_Consigne", "SAPQ_RequeteRef", "SAPQ_Bareme")
             VALUES (:n, :o, :c, :r, :b)',
            [':n' => $partiel['numPartiel'], ':o' => $ordre++, ':c' => $consigne, ':r' => $requeteRef, ':b' => $bareme]
        );
    }

    Http::json(['ok' => true, 'numPartiel' => $partiel['numPartiel']]);
}

/** Formateur : liste des partiels créés pour une promo (pour l'écran de gestion). */
function route_admin_atelier_partiels(string $codePromo): void
{
    Jeton::formateur();
    $r = Db::lignes(
        'SELECT p."SAP_NumPartiel" AS "numPartiel", p."SAP_Titre" AS titre, p."SAP_Base" AS base,
                p."SAP_Debut" AS debut, p."SAP_Fin" AS fin, p."SAP_TentativesMax" AS "tentativesMax",
                (SELECT count(*) FROM "T_SqlAtelier_Partiel_Question" q WHERE q."SAPQ_NumPartiel" = p."SAP_NumPartiel") AS "nbQuestions"
           FROM "T_SqlAtelier_Partiel" p WHERE p."SAP_CodePromo" = :p ORDER BY p."SAP_Debut" DESC',
        [':p' => $codePromo]
    );
    Http::json($r);
}

// ─── Banque de consignes (E8bis, 25/09/2026) — remplace le vrai fonctionnement de l'ancien
// SqlRA (BackOffice/PAGE_CodeSQL) : des exercices permanents, résolus au rythme du stagiaire,
// distincts du "partiel" chronométré ci-dessus. Corrigées avec la même fonction que les partiels
// (sc_sql_atelier_corriger), en comparant à "SAC_Solution" plutôt qu'à une requête de référence
// par question. ───────────────────────────────────────────────────────────────────────────────

/** Stagiaire : consignes visibles d'une base, avec l'état des tentatives du stagiaire connecté. */
function route_atelier_consignes(): void
{
    $c = Jeton::stagiaire();
    $base = (string)($_GET['base'] ?? '');
    if (!preg_match('/^[a-z0-9_-]+$/', $base)) Http::erreur(400, 'Base invalide.');

    $r = Db::lignes(
        'SELECT co."SAC_NumConsigne" AS "numConsigne", co."SAC_Ordre" AS ordre, co."SAC_Consigne" AS consigne,
                co."SAC_NivDifficulte" AS "nivDifficulte", co."SAC_Notee" AS notee,
                (SELECT count(*) FROM "T_SqlAtelier_Consigne_Tentative" t
                  WHERE t."SACT_NumConsigne" = co."SAC_NumConsigne" AND t."SACT_CodeStagiaire" = :s) AS tentatives,
                (SELECT bool_or(t."SACT_Correcte") FROM "T_SqlAtelier_Consigne_Tentative" t
                  WHERE t."SACT_NumConsigne" = co."SAC_NumConsigne" AND t."SACT_CodeStagiaire" = :s) AS "dejaReussi"
           FROM "T_SqlAtelier_Consigne" co
          WHERE co."SAC_CodeBase" = :b AND co."SAC_Visible"
          ORDER BY co."SAC_Ordre", co."SAC_NumConsigne"',
        [':b' => $base, ':s' => $c['sub']]
    );
    Http::json($r);
}

/** Stagiaire : historique des tentatives du stagiaire connecté sur une consigne (comme l'ancien
 *  "chat"/historique de PAGE_CodeSQL — sans la requête solution, jamais renvoyée au stagiaire). */
function route_atelier_consigne_tentatives(string $numConsigne): void
{
    $c = Jeton::stagiaire();
    $r = Db::lignes(
        'SELECT "SACT_Requete" AS requete, "SACT_Correcte" AS correcte, "SACT_Erreur" AS erreur, "SACT_Date" AS date
           FROM "T_SqlAtelier_Consigne_Tentative"
          WHERE "SACT_NumConsigne" = :n AND "SACT_CodeStagiaire" = :s
          ORDER BY "SACT_Date" DESC LIMIT 20',
        [':n' => (int)$numConsigne, ':s' => $c['sub']]
    );
    Http::json($r);
}

/** Stagiaire : soumet une tentative sur une consigne (auto-corrigée, pas de limite de tentatives
 *  ni de fenêtre — contrairement au partiel, la consigne se travaille librement). */
function route_atelier_consigne_repondre(string $numConsigne): void
{
    $c = Jeton::stagiaire();
    $consigne = Db::ligne(
        'SELECT "SAC_CodeBase" AS base, "SAC_Solution" AS solution
           FROM "T_SqlAtelier_Consigne" WHERE "SAC_NumConsigne" = :n AND "SAC_Visible"',
        [':n' => (int)$numConsigne]
    );
    if (!$consigne) Http::erreur(404, 'Consigne introuvable.');

    $corps = Http::corps();
    $requete = trim((string)($corps['requete'] ?? ''));
    if ($requete === '') Http::erreur(400, 'Requête vide.');

    $res = Db::tenter(
        'SELECT sc_sql_atelier_corriger(:b, :req, :ref) AS r',
        [':b' => $consigne['base'], ':req' => $requete, ':ref' => $consigne['solution']]
    );
    $correcte = $res['ok'] ? (bool)($res['ligne']['r']['correcte'] ?? false) : false;
    $erreur = $res['ok'] ? null : atelier_message_erreur($res['erreur']);

    Db::exec(
        'INSERT INTO "T_SqlAtelier_Consigne_Tentative"
            ("SACT_NumConsigne", "SACT_CodeStagiaire", "SACT_Requete", "SACT_Correcte", "SACT_Erreur")
         VALUES (:n, :s, :r, :c, :e)',
        [':n' => (int)$numConsigne, ':s' => $c['sub'], ':r' => $requete, ':c' => $correcte, ':e' => $erreur]
    );
    Http::json(['correcte' => $correcte, 'erreur' => $erreur]);
}

/** Stagiaire : stats persos sur une base (équivalent "Mes stats" de l'ancien PAGE__Accueil,
 *  simplifié — pas de répartition par groupe TD, cette notion n'existe pas dans le nouveau portail
 *  pour l'atelier SQL). */
function route_atelier_stats(): void
{
    $c = Jeton::stagiaire();
    $base = (string)($_GET['base'] ?? '');
    if (!preg_match('/^[a-z0-9_-]+$/', $base)) Http::erreur(400, 'Base invalide.');

    $r = Db::ligne(
        'SELECT
            (SELECT count(*) FROM "T_SqlAtelier_Consigne_Tentative" t
               JOIN "T_SqlAtelier_Consigne" co ON co."SAC_NumConsigne" = t."SACT_NumConsigne"
              WHERE co."SAC_CodeBase" = :b AND t."SACT_CodeStagiaire" = :s) AS "essaisTotal",
            (SELECT count(*) FROM "T_SqlAtelier_Consigne" WHERE "SAC_CodeBase" = :b AND "SAC_Visible") AS "consignesTotal",
            (SELECT count(DISTINCT t."SACT_NumConsigne") FROM "T_SqlAtelier_Consigne_Tentative" t
               JOIN "T_SqlAtelier_Consigne" co ON co."SAC_NumConsigne" = t."SACT_NumConsigne"
              WHERE co."SAC_CodeBase" = :b AND t."SACT_CodeStagiaire" = :s AND t."SACT_Correcte") AS "consignesReussies"',
        [':b' => $base, ':s' => $c['sub']]
    );
    Http::json($r);
}

// ─── Formateur : BackOffice — gestion des consignes d'une base ──────────────────────────────

/** Toutes les consignes d'une base (visibles ou non), avec la solution — jamais exposée au
 *  stagiaire, uniquement ici. */
function route_admin_atelier_consignes(): void
{
    Jeton::formateur();
    $base = (string)($_GET['base'] ?? '');
    if (!preg_match('/^[a-z0-9_-]+$/', $base)) Http::erreur(400, 'Base invalide.');
    $r = Db::lignes(
        'SELECT co."SAC_NumConsigne" AS "numConsigne", co."SAC_Ordre" AS ordre, co."SAC_Consigne" AS consigne,
                co."SAC_Solution" AS solution, co."SAC_Visible" AS visible, co."SAC_Notee" AS notee,
                co."SAC_NivDifficulte" AS "nivDifficulte",
                (SELECT count(*) FROM "T_SqlAtelier_Consigne_Tentative" t WHERE t."SACT_NumConsigne" = co."SAC_NumConsigne") AS "nbTentatives",
                (SELECT count(DISTINCT t."SACT_CodeStagiaire") FROM "T_SqlAtelier_Consigne_Tentative" t
                  WHERE t."SACT_NumConsigne" = co."SAC_NumConsigne" AND t."SACT_Correcte") AS "nbReussi"
           FROM "T_SqlAtelier_Consigne" co WHERE co."SAC_CodeBase" = :b
          ORDER BY co."SAC_Ordre", co."SAC_NumConsigne"',
        [':b' => $base]
    );
    Http::json($r);
}

/** Crée une consigne. La solution doit être un SELECT (même garde-fou que la requête de référence
 *  d'un partiel — elle sera exécutée par sc_sql_atelier_corriger à chaque tentative stagiaire). */
function route_admin_atelier_consigne_creer(): void
{
    Jeton::formateur();
    $d = Http::corps();
    $base = (string)($d['base'] ?? '');
    $consigneTexte = trim((string)($d['consigne'] ?? ''));
    $solution = trim((string)($d['solution'] ?? ''));
    if (!preg_match('/^[a-z0-9_-]+$/', $base)) Http::erreur(400, 'Base invalide.');
    if ($consigneTexte === '' || $solution === '') Http::erreur(400, 'Consigne et solution requises.');
    if (!preg_match('/^select\b/i', $solution)) Http::erreur(400, 'La solution doit être un SELECT.');
    if (!Db::ligne('SELECT 1 AS ok FROM "T_SqlAtelier_Base" WHERE "SAB_Code" = :b', [':b' => $base])) {
        Http::erreur(400, 'Base inconnue.');
    }

    $ligne = Db::ligne(
        'INSERT INTO "T_SqlAtelier_Consigne"
            ("SAC_CodeBase","SAC_Ordre","SAC_Consigne","SAC_Solution","SAC_Visible","SAC_Notee","SAC_NivDifficulte")
         VALUES (:b, :o, :c, :s, :v, :n, :d)
         RETURNING "SAC_NumConsigne"::text AS id',
        [
            ':b' => $base, ':o' => trim((string)($d['ordre'] ?? '')) ?: null, ':c' => $consigneTexte, ':s' => $solution,
            ':v' => (bool)($d['visible'] ?? true), ':n' => (bool)($d['notee'] ?? false),
            ':d' => ($d['nivDifficulte'] ?? '') !== '' ? (int)$d['nivDifficulte'] : null,
        ]
    );
    Http::json(['id' => $ligne['id']], 201);
}

/** Modifie une consigne existante. */
function route_admin_atelier_consigne_modifier(string $numConsigne): void
{
    Jeton::formateur();
    $id = (int)$numConsigne;
    if (!Db::ligne('SELECT 1 AS ok FROM "T_SqlAtelier_Consigne" WHERE "SAC_NumConsigne" = :i', [':i' => $id])) {
        Http::erreur(404, 'Consigne introuvable.');
    }
    $d = Http::corps();
    $consigneTexte = trim((string)($d['consigne'] ?? ''));
    $solution = trim((string)($d['solution'] ?? ''));
    if ($consigneTexte === '' || $solution === '') Http::erreur(400, 'Consigne et solution requises.');
    if (!preg_match('/^select\b/i', $solution)) Http::erreur(400, 'La solution doit être un SELECT.');

    Db::exec(
        'UPDATE "T_SqlAtelier_Consigne" SET
            "SAC_Ordre" = :o, "SAC_Consigne" = :c, "SAC_Solution" = :s,
            "SAC_Visible" = :v, "SAC_Notee" = :n, "SAC_NivDifficulte" = :d
          WHERE "SAC_NumConsigne" = :i',
        [
            ':o' => trim((string)($d['ordre'] ?? '')) ?: null, ':c' => $consigneTexte, ':s' => $solution,
            ':v' => (bool)($d['visible'] ?? true), ':n' => (bool)($d['notee'] ?? false),
            ':d' => ($d['nivDifficulte'] ?? '') !== '' ? (int)$d['nivDifficulte'] : null,
            ':i' => $id,
        ]
    );
    Http::json(['ok' => true]);
}

/** Supprime une consigne (et ses tentatives, pour ne pas laisser de lignes orphelines). */
function route_admin_atelier_consigne_supprimer(string $numConsigne): void
{
    Jeton::formateur();
    $id = (int)$numConsigne;
    Db::exec('DELETE FROM "T_SqlAtelier_Consigne_Tentative" WHERE "SACT_NumConsigne" = :i', [':i' => $id]);
    Db::exec('DELETE FROM "T_SqlAtelier_Consigne" WHERE "SAC_NumConsigne" = :i', [':i' => $id]);
    Http::json(['ok' => true]);
}

/** Formateur : toutes les tentatives de tous les stagiaires sur une consigne, avec le nom du
 *  stagiaire et l'état "vu" — équivalent de la colonne SACT_Vu (prévue mais non exposée jusqu'ici,
 *  cf. remarque de la migration atelier_sql_consignes). Sert de "correction"/pointage manuel par
 *  le formateur, comme "Mettre Vu sur actions faites" dans l'ancien SqlRA. */
function route_admin_atelier_consigne_tentatives(string $numConsigne): void
{
    Jeton::formateur();
    $id = (int)$numConsigne;
    $r = Db::lignes(
        'SELECT t."SACT_Id"::text AS id, t."SACT_CodeStagiaire"::text AS "codeStagiaire",
                s."Stag_Nom" AS nom, s."Stag_Prenom" AS prenom,
                t."SACT_Requete" AS requete, t."SACT_Correcte" AS correcte, t."SACT_Erreur" AS erreur,
                t."SACT_Vu" AS vu, t."SACT_Date" AS date
           FROM "T_SqlAtelier_Consigne_Tentative" t
           LEFT JOIN "T_Stagiaire" s ON s."Stag_CodeStagiaire" = t."SACT_CodeStagiaire"
          WHERE t."SACT_NumConsigne" = :n
          ORDER BY t."SACT_Date" DESC',
        [':n' => $id]
    );
    Http::json($r);
}

/** Formateur : bascule l'état "vu" d'une tentative (pointage manuel, sans notion de note —
 *  la correction automatique existe déjà via SACT_Correcte). */
function route_admin_atelier_tentative_vu(string $idTentative): void
{
    Jeton::formateur();
    $id = (int)$idTentative;
    $corps = Http::corps();
    if (!Db::ligne('SELECT 1 AS ok FROM "T_SqlAtelier_Consigne_Tentative" WHERE "SACT_Id" = :i', [':i' => $id])) {
        Http::erreur(404, 'Tentative introuvable.');
    }
    Db::exec('UPDATE "T_SqlAtelier_Consigne_Tentative" SET "SACT_Vu" = :v WHERE "SACT_Id" = :i',
        [':v' => (bool)($corps['vu'] ?? true), ':i' => $id]);
    Http::json(['ok' => true]);
}

// ─── Formateur : résultats et suivi d'un partiel (E8bis, 26/09/2026) — équivalent "Graph partiel"
// et "Qui n'a pas rep" de l'ancien BackOffice SqlRA. Portée volontairement réduite : un tableau de
// taux de réussite par question (pas un graphique au sens littéral — aucune librairie de graphes
// n'est en place dans le front, cf. échange du 25/09) plutôt qu'un export image, et une liste de
// stagiaires de la promo n'ayant déposé aucune réponse, plutôt que le détail "essais/échecs" complet
// de l'original (hors périmètre annoncé ce soir). ────────────────────────────────────────────────

/** Formateur : taux de réussite par question d'un partiel (base du "Graph partiel"). */
function route_admin_atelier_partiel_resultats(string $numPartiel): void
{
    Jeton::formateur();
    $partiel = Db::ligne(
        'SELECT "SAP_NumPartiel" AS "numPartiel", "SAP_Titre" AS titre, "SAP_CodePromo"::text AS "codePromo"
           FROM "T_SqlAtelier_Partiel" WHERE "SAP_NumPartiel" = :n',
        [':n' => (int)$numPartiel]
    );
    if (!$partiel) Http::erreur(404, 'Partiel introuvable.');

    $questions = Db::lignes(
        'SELECT q."SAPQ_NumQuestion" AS "numQuestion", q."SAPQ_Ordre" AS ordre, q."SAPQ_Consigne" AS consigne,
                count(DISTINCT r."SAPR_CodeStagiaire") FILTER (WHERE r."SAPR_Correcte") AS "nbReussi",
                count(DISTINCT r."SAPR_CodeStagiaire") AS "nbRepondu"
           FROM "T_SqlAtelier_Partiel_Question" q
           LEFT JOIN "T_SqlAtelier_Partiel_Reponse" r ON r."SAPR_NumQuestion" = q."SAPQ_NumQuestion"
          WHERE q."SAPQ_NumPartiel" = :n
          GROUP BY q."SAPQ_NumQuestion", q."SAPQ_Ordre", q."SAPQ_Consigne"
          ORDER BY q."SAPQ_Ordre", q."SAPQ_NumQuestion"',
        [':n' => (int)$numPartiel]
    );

    $inscrits = Db::ligne(
        'SELECT count(*) AS n FROM "T_Promo_Stagiaire" WHERE "PromSta_CodePromo" = :p AND "PromSta_Actif"',
        [':p' => $partiel['codePromo']]
    );

    Http::json(['partiel' => $partiel, 'nbInscrits' => (int)$inscrits['n'], 'questions' => $questions]);
}

/** Formateur : stagiaires actifs de la promo du partiel n'ayant déposé aucune réponse
 *  (équivalent "Qui n'a pas répondu"). */
function route_admin_atelier_partiel_non_repondants(string $numPartiel): void
{
    Jeton::formateur();
    $partiel = Db::ligne(
        'SELECT "SAP_CodePromo"::text AS "codePromo" FROM "T_SqlAtelier_Partiel" WHERE "SAP_NumPartiel" = :n',
        [':n' => (int)$numPartiel]
    );
    if (!$partiel) Http::erreur(404, 'Partiel introuvable.');

    $r = Db::lignes(
        'SELECT s."Stag_CodeStagiaire"::text AS code, s."Stag_Nom" AS nom, s."Stag_Prenom" AS prenom
           FROM "T_Promo_Stagiaire" ps
           JOIN "T_Stagiaire" s ON s."Stag_CodeStagiaire" = ps."PromSta_CodeStagiaire"
          WHERE ps."PromSta_CodePromo" = :p AND ps."PromSta_Actif"
            AND NOT EXISTS (
              SELECT 1 FROM "T_SqlAtelier_Partiel_Reponse" r
                JOIN "T_SqlAtelier_Partiel_Question" q ON q."SAPQ_NumQuestion" = r."SAPR_NumQuestion"
               WHERE q."SAPQ_NumPartiel" = :n AND r."SAPR_CodeStagiaire" = s."Stag_CodeStagiaire"
            )
          ORDER BY s."Stag_Nom", s."Stag_Prenom"',
        [':p' => $partiel['codePromo'], ':n' => (int)$numPartiel]
    );
    Http::json($r);
}

// ─── Suivi en direct (E8bis, 26/09/2026) — présence + messagerie + détection d'abus, équivalent
// réduit du moniteur de connectés/chat de l'ancien SqlRA. Décision de Jérôme du 26/09 : PAS de
// dépendance à Supabase Realtime (tout doit rester autonome, comme le reste de l'API) — rafraîchi
// par sondage (polling) côté client, manuel ou automatique. La "messagerie" est un fil de messages
// persistés en base, pas un chat au sens strict (pas de notion de "en train d'écrire", pas de
// livraison instantanée). ─────────────────────────────────────────────────────────────────────

/** Stagiaire : signale son activité sur l'atelier SQL (appelé périodiquement par le front tant
 *  que le stagiaire est sur une page de l'atelier). Upsert — une seule ligne par stagiaire. */
function route_atelier_presence(): void
{
    $c = Jeton::stagiaire();
    Db::exec(
        'INSERT INTO "T_SqlAtelier_Presence" ("SAPR2_CodeStagiaire", "SAPR2_DerniereActivite")
         VALUES (:s, now())
         ON CONFLICT ("SAPR2_CodeStagiaire") DO UPDATE SET "SAPR2_DerniereActivite" = now()',
        [':s' => $c['sub']]
    );
    Http::json(['ok' => true]);
}

/** Formateur : stagiaires actifs sur l'atelier SQL dans les N dernières minutes (défaut 3). */
function route_admin_atelier_presence(): void
{
    Jeton::formateur();
    $minutes = max(1, min(60, (int)($_GET['minutes'] ?? 3)));
    $r = Db::lignes(
        'SELECT s."Stag_CodeStagiaire"::text AS code, s."Stag_Nom" AS nom, s."Stag_Prenom" AS prenom,
                p."SAPR2_DerniereActivite" AS "derniereActivite"
           FROM "T_SqlAtelier_Presence" p
           JOIN "T_Stagiaire" s ON s."Stag_CodeStagiaire" = p."SAPR2_CodeStagiaire"
          WHERE p."SAPR2_DerniereActivite" >= now() - (' . $minutes . ' || \' minutes\')::interval
          ORDER BY p."SAPR2_DerniereActivite" DESC'
    );
    Http::json($r);
}

/** Stagiaire : derniers messages de la messagerie de l'atelier SQL (fil unique, pas de canal par
 *  promo/base — cf. décision de Jérôme du 25/09 sur le périmètre de l'atelier). */
function route_atelier_messages(): void
{
    Jeton::stagiaire();
    $r = Db::lignes(
        'SELECT "SAM_Id"::text AS id, "SAM_TypeExpediteur" AS type, "SAM_NomExpediteur" AS nom,
                "SAM_Contenu" AS contenu, "SAM_Date" AS date
           FROM "T_SqlAtelier_Message" ORDER BY "SAM_Date" DESC LIMIT 100'
    );
    Http::json(array_reverse($r));
}

/** Stagiaire : poste un message dans la messagerie. */
function route_atelier_message_envoyer(): void
{
    $c = Jeton::stagiaire();
    $contenu = trim((string)(Http::corps()['contenu'] ?? ''));
    if ($contenu === '') Http::erreur(400, 'Message vide.');
    if (mb_strlen($contenu) > 1000) Http::erreur(400, 'Message trop long (1000 caractères max).');
    Db::exec(
        'INSERT INTO "T_SqlAtelier_Message" ("SAM_TypeExpediteur","SAM_CodeExpediteur","SAM_NomExpediteur","SAM_Contenu")
         VALUES (\'stagiaire\', :c, :n, :m)',
        [':c' => $c['sub'], ':n' => trim($c['prenom'] . ' ' . $c['nom']), ':m' => $contenu]
    );
    Http::json(['ok' => true], 201);
}

/** Formateur : mêmes messages (pas de vue différente, le formateur voit tout). */
function route_admin_atelier_messages(): void
{
    Jeton::formateur();
    $r = Db::lignes(
        'SELECT "SAM_Id"::text AS id, "SAM_TypeExpediteur" AS type, "SAM_NomExpediteur" AS nom,
                "SAM_Contenu" AS contenu, "SAM_Date" AS date
           FROM "T_SqlAtelier_Message" ORDER BY "SAM_Date" DESC LIMIT 100'
    );
    Http::json(array_reverse($r));
}

/** Formateur : poste un message (broadcast, vu par tous les stagiaires au prochain rafraîchissement). */
function route_admin_atelier_message_envoyer(): void
{
    $f = Jeton::formateur();
    $contenu = trim((string)(Http::corps()['contenu'] ?? ''));
    if ($contenu === '') Http::erreur(400, 'Message vide.');
    if (mb_strlen($contenu) > 1000) Http::erreur(400, 'Message trop long (1000 caractères max).');
    Db::exec(
        'INSERT INTO "T_SqlAtelier_Message" ("SAM_TypeExpediteur","SAM_CodeExpediteur","SAM_NomExpediteur","SAM_Contenu")
         VALUES (\'formateur\', :c, :n, :m)',
        [':c' => $f['sub'], ':n' => trim($f['prenom'] . ' ' . $f['nom']), ':m' => $contenu]
    );
    Http::json(['ok' => true], 201);
}

/** Formateur : stagiaires ayant dépassé un seuil de requêtes lancées sur une fenêtre de temps
 *  (entraînement libre + tentatives de consigne + réponses de partiel confondues — tout ce qui
 *  constitue un "lancement de requête"). Seuils par défaut raisonnables (20 requêtes / 60 s),
 *  ajustables via les paramètres de la requête — aucun blocage automatique, juste un signalement
 *  au formateur (l'original semblait fonctionner pareil : un bouton de consultation, pas un
 *  blocage). */
function route_admin_atelier_abus(): void
{
    Jeton::formateur();
    $seuil = max(1, (int)($_GET['seuil'] ?? 20));
    $fenetre = max(5, min(3600, (int)($_GET['fenetre'] ?? 60)));

    $r = Db::lignes(
        'WITH activite AS (
            SELECT "SACT_CodeStagiaire" AS code, "SACT_Date" AS date FROM "T_SqlAtelier_Consigne_Tentative"
            UNION ALL
            SELECT "SAPR_CodeStagiaire", "SAPR_Date" FROM "T_SqlAtelier_Partiel_Reponse"
            UNION ALL
            SELECT "SAEL_CodeStagiaire", "SAEL_Date" FROM "T_SqlAtelier_Execution_Log"
         )
         SELECT s."Stag_CodeStagiaire"::text AS code, s."Stag_Nom" AS nom, s."Stag_Prenom" AS prenom,
                count(*) AS "nbRequetes"
           FROM activite a
           JOIN "T_Stagiaire" s ON s."Stag_CodeStagiaire" = a.code
          WHERE a.date >= now() - (' . $fenetre . ' || \' seconds\')::interval
          GROUP BY s."Stag_CodeStagiaire", s."Stag_Nom", s."Stag_Prenom"
         HAVING count(*) >= ' . $seuil . '
          ORDER BY "nbRequetes" DESC'
    );
    Http::json(['seuil' => $seuil, 'fenetreSecondes' => $fenetre, 'stagiaires' => $r]);
}

// ─── Import Excel de consignes (E8bis, 26/09/2026) — équivalent "Importer via Excel" du BackOffice
// SqlRA, pour la banque de consignes uniquement (décision de Jérôme du 26/09). Le fichier est
// analysé côté navigateur (feuille de calcul → lignes JSON) : aucune librairie de lecture Excel
// n'est ajoutée côté PHP, qui reste sans dépendance externe sur l'hébergement OVH mutualisé. */

/** Formateur : import en masse de consignes (lignes déjà parsées côté front). Chaque ligne est
 *  validée indépendamment (comme la création unitaire) ; le résultat détaille les échecs sans
 *  jamais faire échouer l'import entier — une ligne mal formée ne doit pas bloquer les autres. */
function route_admin_atelier_consignes_importer(): void
{
    Jeton::formateur();
    $corps = Http::corps();
    $base = (string)($corps['base'] ?? '');
    $lignes = $corps['consignes'] ?? [];
    if (!preg_match('/^[a-z0-9_-]+$/', $base)) Http::erreur(400, 'Base invalide.');
    if (!Db::ligne('SELECT 1 AS ok FROM "T_SqlAtelier_Base" WHERE "SAB_Code" = :b', [':b' => $base])) {
        Http::erreur(400, 'Base inconnue.');
    }
    if (!is_array($lignes) || !$lignes) Http::erreur(400, 'Aucune ligne à importer.');

    $resultats = [];
    foreach ($lignes as $i => $l) {
        $consigneTexte = trim((string)($l['consigne'] ?? ''));
        $solution = trim((string)($l['solution'] ?? ''));
        if ($consigneTexte === '' || $solution === '') {
            $resultats[] = ['ligne' => $i + 1, 'ok' => false, 'erreur' => 'Consigne et solution requises.'];
            continue;
        }
        if (!preg_match('/^select\b/i', $solution)) {
            $resultats[] = ['ligne' => $i + 1, 'ok' => false, 'erreur' => 'La solution doit être un SELECT.'];
            continue;
        }
        $ligne = Db::ligne(
            'INSERT INTO "T_SqlAtelier_Consigne"
                ("SAC_CodeBase","SAC_Ordre","SAC_Consigne","SAC_Solution","SAC_Visible","SAC_Notee","SAC_NivDifficulte")
             VALUES (:b, :o, :c, :s, :v, :n, :d)
             RETURNING "SAC_NumConsigne"::text AS id',
            [
                ':b' => $base, ':o' => trim((string)($l['ordre'] ?? '')) ?: null, ':c' => $consigneTexte, ':s' => $solution,
                ':v' => (bool)($l['visible'] ?? true), ':n' => (bool)($l['notee'] ?? false),
                ':d' => ($l['nivDifficulte'] ?? '') !== '' ? (int)$l['nivDifficulte'] : null,
            ]
        );
        $resultats[] = ['ligne' => $i + 1, 'ok' => true, 'id' => $ligne['id']];
    }
    $nbOk = count(array_filter($resultats, fn($r) => $r['ok']));
    Http::json(['nbImportees' => $nbOk, 'nbTotal' => count($resultats), 'resultats' => $resultats]);
}
