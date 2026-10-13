<?php
// Atelier SQL — stats, correction des requêtes par le formateur, bonus, promo de référence
// (Lot 2 bis, sous-étape A). Calculs dans sc_sql_stats (db/stats_sql.sql).

const STATS_MIN_COMPARAISON = 3; // sous ce nombre de stagiaires, la vue « promo » stagiaire est masquée (anonymat)

function stats_entier(?string $v): ?int
{
    if ($v === null || $v === '') return null;
    if (!preg_match('/^\d{1,19}$/', $v)) Http::erreur(400, 'Identifiant invalide.');
    return (int)$v;
}

function stats_date(?string $v): ?string
{
    if ($v === null || $v === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) Http::erreur(400, 'Date invalide (AAAA-MM-JJ).');
    return $v;
}

/** Appelle sc_sql_stats. $du inclus, $au inclus (jour entier), interprétés en heure de Paris. */
function stats_calculer(?int $promo, ?int $stagiaire, ?string $du, ?string $au): array
{
    $r = Db::ligne(
        'SELECT sc_sql_stats(CAST(:p AS bigint), CAST(:s AS bigint),
                CASE WHEN CAST(:d AS text) IS NULL THEN NULL ELSE CAST(:d AS date)::timestamp AT TIME ZONE \'Europe/Paris\' END,
                CASE WHEN CAST(:a AS text) IS NULL THEN NULL ELSE (CAST(:a AS date) + 1)::timestamp AT TIME ZONE \'Europe/Paris\' END) AS r',
        [':p' => $promo, ':s' => $stagiaire, ':d' => $du, ':a' => $au]
    );
    return $r['r'] ?? [];
}

function stats_promo_reference(int $promo): ?array
{
    return Db::ligne(
        'SELECT r."SAPRf_CodePromoRef"::text AS code, p."Pro_Libelle" AS libelle
           FROM "T_SqlAtelier_Promo_Ref" r JOIN "T_Promo" p ON p."Pro_CodePromo" = r."SAPRf_CodePromoRef"
          WHERE r."SAPRf_CodePromo" = :p',
        [':p' => $promo]
    );
}

/** Formateur : tuiles pour une promo (ou toutes les promos SQL), un stagiaire, une période. */
function route_admin_atelier_stats(): void
{
    Jeton::formateur();
    $promo = stats_entier($_GET['promo'] ?? null);
    $stag = stats_entier($_GET['stagiaire'] ?? null);
    $du = stats_date($_GET['du'] ?? null);
    $au = stats_date($_GET['au'] ?? null);
    $sortie = ['stats' => stats_calculer($promo, $stag, $du, $au), 'reference' => null];
    if ($promo !== null && ($ref = stats_promo_reference($promo))) {
        $sortie['reference'] = ['promo' => $ref, 'stats' => stats_calculer((int)$ref['code'], null, null, null)];
    }
    Http::json($sortie);
}

/** Formateur : promos ayant l'option Atelier SQL (filtre de l'écran de stats). */
function route_admin_atelier_promos_sql(): void
{
    Jeton::formateur();
    Http::json(Db::lignes(
        'SELECT p."Pro_CodePromo"::text AS code, p."Pro_Libelle" AS libelle, p."Pro_SujetForm" AS sujet,
                p."Pro_Du" AS du, p."Pro_Au" AS au
           FROM "T_Promo" p
          WHERE EXISTS (SELECT 1 FROM "T_Promo_Option_Reel" o WHERE o."PORe_CodePromo" = p."Pro_CodePromo" AND o."PORe_CodeOption" = \'atelier_sql\')
          ORDER BY p."Pro_Du" DESC NULLS LAST, p."Pro_Libelle"'
    ));
}

/** Formateur : liste des requêtes (écran de correction). Filtres : promo, stagiaire, nom, prénom,
 *  groupe, dates, vu (0/1), echecs (1), notee (1 = consignes notées), nonNotees (1 = pas encore notées par le formateur). */
function route_admin_atelier_requetes(): void
{
    Jeton::formateur();
    $promo = stats_entier($_GET['promo'] ?? null);
    $stag = stats_entier($_GET['stagiaire'] ?? null);
    $du = stats_date($_GET['du'] ?? null);
    $au = stats_date($_GET['au'] ?? null);
    $q = fn(string $k) => trim((string)($_GET[$k] ?? ''));
    $where = ['1=1'];
    $p = [];
    if ($promo !== null) { $where[] = 'EXISTS (SELECT 1 FROM "T_Promo_Stagiaire" ps WHERE ps."PromSta_CodeStagiaire" = t."SACT_CodeStagiaire" AND ps."PromSta_CodePromo" = :promo)'; $p[':promo'] = $promo; }
    else $where[] = 'EXISTS (SELECT 1 FROM "T_Promo_Stagiaire" ps JOIN "T_Promo_Option_Reel" o ON o."PORe_CodePromo" = ps."PromSta_CodePromo" AND o."PORe_CodeOption" = \'atelier_sql\' WHERE ps."PromSta_CodeStagiaire" = t."SACT_CodeStagiaire")';
    if ($stag !== null) { $where[] = 't."SACT_CodeStagiaire" = :stag'; $p[':stag'] = $stag; }
    if ($du !== null) { $where[] = 't."SACT_Date" >= CAST(:du AS date)::timestamp AT TIME ZONE \'Europe/Paris\''; $p[':du'] = $du; }
    if ($au !== null) { $where[] = 't."SACT_Date" < (CAST(:au AS date) + 1)::timestamp AT TIME ZONE \'Europe/Paris\''; $p[':au'] = $au; }
    if ($q('nom') !== '') { $where[] = 's."Stag_Nom" ILIKE :nom'; $p[':nom'] = '%' . $q('nom') . '%'; }
    if ($q('prenom') !== '') { $where[] = 's."Stag_Prenom" ILIKE :prenom'; $p[':prenom'] = '%' . $q('prenom') . '%'; }
    if ($q('groupe') !== '') { $where[] = 'EXISTS (SELECT 1 FROM "T_Promo_Stagiaire" g WHERE g."PromSta_CodeStagiaire" = t."SACT_CodeStagiaire" AND g."PromSta_Groupe" ILIKE :groupe)'; $p[':groupe'] = $q('groupe'); }
    if ($q('vu') === '0') $where[] = 'NOT t."SACT_Vu"';
    if ($q('vu') === '1') $where[] = 't."SACT_Vu"';
    if ($q('echecs') === '1') $where[] = 'NOT t."SACT_Correcte"';
    if ($q('notee') === '1') $where[] = 'co."SAC_Notee"';
    if ($q('nonNotees') === '1') $where[] = 't."SACT_Note" IS NULL';
    Http::json(Db::lignes(
        'SELECT t."SACT_Id"::text AS id, t."SACT_Date" AS date, t."SACT_Requete" AS requete, t."SACT_Correcte" AS correcte,
                t."SACT_Erreur" AS erreur, t."SACT_Vu" AS vu, t."SACT_Bonus" AS bonus, t."SACT_Note" AS note,
                s."Stag_CodeStagiaire"::text AS "codeStagiaire", s."Stag_Nom" AS nom, s."Stag_Prenom" AS prenom,
                co."SAC_NumConsigne" AS "numConsigne", co."SAC_Ordre" AS ordre, co."SAC_Consigne" AS consigne,
                co."SAC_Notee" AS notee, co."SAC_NivDifficulte" AS niveau
           FROM "T_SqlAtelier_Consigne_Tentative" t
           JOIN "T_SqlAtelier_Consigne" co ON co."SAC_NumConsigne" = t."SACT_NumConsigne"
           JOIN "T_Stagiaire" s ON s."Stag_CodeStagiaire" = t."SACT_CodeStagiaire"
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY t."SACT_Date" DESC LIMIT 200',
        $p
    ));
}

/** Formateur : vu / bonus / note (0, 0.5, 1 ou null) d'une tentative. Seuls les champs envoyés changent. */
function route_admin_atelier_requete_modifier(string $id): void
{
    $f = Jeton::formateur();
    $d = Http::corps();
    $sets = [];
    $p = [':id' => (int)$id];
    if (array_key_exists('vu', $d)) { $sets[] = '"SACT_Vu" = :vu'; $p[':vu'] = (bool)$d['vu']; }
    if (array_key_exists('bonus', $d)) { $sets[] = '"SACT_Bonus" = :bonus'; $p[':bonus'] = (bool)$d['bonus']; }
    if (array_key_exists('note', $d)) {
        $n = $d['note'];
        if ($n !== null && !in_array((float)$n, [0.0, 0.5, 1.0], true)) Http::erreur(400, 'Note : 0, 0,5 ou 1.');
        $sets[] = '"SACT_Note" = :note, "SACT_NoteePar" = :par, "SACT_NoteeLe" = CASE WHEN :note2 IS NULL THEN NULL ELSE now() END';
        $p[':note'] = $n === null ? null : (float)$n;
        $p[':note2'] = $n === null ? null : (float)$n;
        $p[':par'] = $n === null ? null : (int)$f['sub'];
    }
    if (!$sets) Http::erreur(400, 'Rien à modifier.');
    $n = Db::exec('UPDATE "T_SqlAtelier_Consigne_Tentative" SET ' . implode(', ', $sets) . ' WHERE "SACT_Id" = :id', $p);
    if ($n === 0) Http::erreur(404, 'Tentative introuvable.');
    Http::json(['ok' => true]);
}

/** Formateur : promo de référence d'une promo. */
function route_admin_promo_sql_reference_lire(string $code): void
{
    Jeton::formateur();
    Http::json(stats_promo_reference((int)$code) ?? ['code' => null, 'libelle' => null]);
}

function route_admin_promo_sql_reference_ecrire(string $code): void
{
    Jeton::formateur();
    $ref = Http::corps()['promoRef'] ?? null;
    if ($ref === null || $ref === '') {
        Db::exec('DELETE FROM "T_SqlAtelier_Promo_Ref" WHERE "SAPRf_CodePromo" = :p', [':p' => (int)$code]);
        Http::json(['ok' => true]);
    }
    if (!preg_match('/^\d{1,19}$/', (string)$ref)) Http::erreur(400, 'Promo de référence invalide.');
    if ((string)$ref === $code) Http::erreur(400, 'Une promo ne peut pas être sa propre référence.');
    if (!Db::ligne('SELECT 1 AS ok FROM "T_Promo" WHERE "Pro_CodePromo" = :p', [':p' => (int)$ref])) Http::erreur(404, 'Promo de référence introuvable.');
    Db::exec(
        'INSERT INTO "T_SqlAtelier_Promo_Ref" ("SAPRf_CodePromo", "SAPRf_CodePromoRef") VALUES (:p, :r)
         ON CONFLICT ("SAPRf_CodePromo") DO UPDATE SET "SAPRf_CodePromoRef" = EXCLUDED."SAPRf_CodePromoRef"',
        [':p' => (int)$code, ':r' => (int)$ref]
    );
    Http::json(['ok' => true]);
}

/** Formateur : stagiaires d'une promo ayant au moins un bonus, avec points ajoutés et somme des notes. */
function route_admin_atelier_bonus(string $code): void
{
    Jeton::formateur();
    Http::json(Db::lignes(
        'SELECT s."Stag_CodeStagiaire"::text AS "codeStagiaire", s."Stag_Nom" AS nom, s."Stag_Prenom" AS prenom,
                count(*) FILTER (WHERE t."SACT_Bonus") AS bonus,
                coalesce(sum(t."SACT_Note"), 0) AS "sommeNotes",
                coalesce(max(pa."SAPA_Points"), 0) AS "pointsAjoutes"
           FROM "T_Promo_Stagiaire" ps
           JOIN "T_Stagiaire" s ON s."Stag_CodeStagiaire" = ps."PromSta_CodeStagiaire"
           JOIN "T_SqlAtelier_Consigne_Tentative" t ON t."SACT_CodeStagiaire" = s."Stag_CodeStagiaire"
           LEFT JOIN "T_SqlAtelier_Points_Ajoutes" pa ON pa."SAPA_CodePromo" = ps."PromSta_CodePromo" AND pa."SAPA_CodeStagiaire" = s."Stag_CodeStagiaire"
          WHERE ps."PromSta_CodePromo" = :p AND ps."PromSta_Actif"
          GROUP BY s."Stag_CodeStagiaire", s."Stag_Nom", s."Stag_Prenom"
         HAVING count(*) FILTER (WHERE t."SACT_Bonus") > 0
          ORDER BY s."Stag_Nom", s."Stag_Prenom"',
        [':p' => (int)$code]
    ));
}

function route_admin_atelier_points_ecrire(string $code, string $stagiaire): void
{
    $f = Jeton::formateur();
    $pts = Http::corps()['points'] ?? null;
    if (!is_numeric($pts) || abs((float)$pts) > 100) Http::erreur(400, 'Points invalides.');
    Db::exec(
        'INSERT INTO "T_SqlAtelier_Points_Ajoutes" ("SAPA_CodePromo", "SAPA_CodeStagiaire", "SAPA_Points", "SAPA_Par")
         VALUES (:p, :s, :pts, :par)
         ON CONFLICT ("SAPA_CodePromo", "SAPA_CodeStagiaire") DO UPDATE
           SET "SAPA_Points" = EXCLUDED."SAPA_Points", "SAPA_Par" = EXCLUDED."SAPA_Par", "SAPA_Le" = now()',
        [':p' => (int)$code, ':s' => (int)$stagiaire, ':pts' => (float)$pts, ':par' => (int)$f['sub']]
    );
    Http::json(['ok' => true]);
}

/** Stagiaire : ses stats + celles de sa promo (agrégées, jamais nominatives) + celles de la promo de
 *  référence. La promo est vérifiée côté serveur : le stagiaire ne peut interroger que les siennes. */
function route_atelier_mes_stats(): void
{
    $c = Jeton::stagiaire();
    $promo = stats_entier($_GET['promo'] ?? null);
    $promos = Db::lignes(
        'SELECT p."Pro_CodePromo"::text AS code, p."Pro_Libelle" AS libelle
           FROM "T_Promo_Stagiaire" ps JOIN "T_Promo" p ON p."Pro_CodePromo" = ps."PromSta_CodePromo"
          WHERE ps."PromSta_CodeStagiaire" = :s AND ps."PromSta_Actif"
            AND EXISTS (SELECT 1 FROM "T_Promo_Option_Reel" o WHERE o."PORe_CodePromo" = p."Pro_CodePromo" AND o."PORe_CodeOption" = \'atelier_sql\')
          ORDER BY p."Pro_Du" DESC NULLS LAST',
        [':s' => (int)$c['sub']]
    );
    if (!$promos) Http::json(['promos' => [], 'promo' => null, 'moi' => null, 'laPromo' => null, 'reference' => null]);
    $choisie = $promos[0];
    if ($promo !== null) {
        $choisie = null;
        foreach ($promos as $p) if ((int)$p['code'] === $promo) $choisie = $p;
        if (!$choisie) Http::erreur(403, 'Cette promo n\'est pas la vôtre.');
    }
    $code = (int)$choisie['code'];
    $laPromo = stats_calculer($code, null, null, null);
    $comparable = ((int)($laPromo['nbStagiaires'] ?? 0)) >= STATS_MIN_COMPARAISON;
    $ref = stats_promo_reference($code);
    $statsRef = $ref ? stats_calculer((int)$ref['code'], null, null, null) : null;
    Http::json([
        'promos' => $promos,
        'promo' => $choisie,
        'moi' => stats_calculer($code, (int)$c['sub'], null, null),
        'laPromo' => $comparable ? $laPromo : null,
        'reference' => ($statsRef && ((int)($statsRef['nbStagiaires'] ?? 0)) >= STATS_MIN_COMPARAISON) ? ['promo' => $ref, 'stats' => $statsRef] : null,
        'comparaisonMasquee' => !$comparable
    ]);
}
