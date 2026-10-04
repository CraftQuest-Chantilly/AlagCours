<?php
// E7 — Signature de présence. Remplace PAGE_Signe/PAGE_Signature d'al-cours (qui écrivait un PNG
// sur le disque du serveur). Nouvelle table T_Cours_Signature (une ligne par séance signée par un
// stagiaire) — même principe que les ressources (E4) : le fichier reste sur le disque OVH
// (config['signatures_racine']), jamais dans la base (décision Jérôme, 25/09/2026 : pas de
// Supabase Storage pour les signatures). Arborescence demandée par Jérôme : un dossier par promo,
// puis par stagiaire, puis un fichier par séance — pour pouvoir facilement retrouver/afficher les
// signatures d'un stagiaire donné depuis une future fiche récap formateur.
//   <signatures_racine>\<CodePromo>\<CodeStagiaire>\<NumCours>.png
//
// Hors créneau (décision Jérôme, 25/09/2026) : l'ancien "code" de déblocage était l'heure
// courante HHMM (devinable par n'importe qui, faille signalée en étape 1) — non reproduit.
// Remplacé par : une marge de tolérance autour du créneau réel de la séance (SIGNATURE_MARGE_MINUTES,
// valeur par défaut choisie à 30 min, à ajuster si besoin), + deux mécanismes de déblocage explicite :
//   - T_Cours_Signature_Deblocage : un couple séance+stagiaire précis, sans limite de durée
//     (route_signature_debloquer ; prête côté API mais toujours sans écran formateur pour
//     l'appeler — cf. recap-etape-3/4, non traité par E10 ci-dessous).
//   - T_Promo_Signature_Deblocage (E10, 30/09/2026) : TOUTE la promo (tous stagiaires, toutes
//     séances), pour une fenêtre de SIGNATURE_DEBLOCAGE_PROMO_MINUTES minutes depuis le clic du
//     formateur — bouton « Autoriser signatures pour cette promo » dans Gestion des fichiers
//     (route_signature_debloquer_promo). Un nouveau clic prolonge (remplace l'expiration).

const SIGNATURE_MARGE_MINUTES = 30;
const SIGNATURE_DEBLOCAGE_PROMO_MINUTES = 30;

/** Vrai si la promo a un déblocage temporaire actif (E10) — partagé entre route_signature_liste()
 *  et route_signature_signer() pour rester cohérent entre affichage et vérification serveur. */
function promo_signature_debloquee(string $codePromo): bool
{
    $r = Db::ligne(
        'SELECT 1 AS ok FROM "T_Promo_Signature_Deblocage"
          WHERE "PSD_CodePromo" = :p AND "PSD_DateExpiration" > now()',
        [':p' => $codePromo]
    );
    return (bool)$r;
}

/** Chemin disque réel du PNG de signature d'un stagiaire pour une séance donnée.
 *  <signatures_racine>\<CodePromo>\<CodeStagiaire>\<NumCours>.png (mêmes précautions que
 *  fic_chemin_disque() dans ressources.php : identifiants numériques uniquement, jamais de
 *  séparateur de chemin injectable — mais ceinture-bretelles puisque ce sont des entiers). */
function signature_chemin_disque(string $codePromo, string $codeStagiaire, string $numCours): string
{
    $racine = rtrim((string)$GLOBALS['config']['signatures_racine'], '/\\');
    $seg = fn (string $s) => preg_replace('/[^0-9]/', '', $s);
    return $racine . '/' . $seg($codePromo) . '/' . $seg($codeStagiaire) . '/' . $seg($numCours) . '.png';
}

/** Liste des séances de la formation avec état de signature du stagiaire connecté. */
function route_signature_liste(string $codePromo): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);

    $r = Db::lignes(
        'SELECT co."Cours_NumCours"::text AS "numCours", co."Cours_Date" AS date,
                to_char(co."Cours_HeureDebut", \'HH24:MI\') AS "heureDebut", to_char(co."Cours_HeureFin", \'HH24:MI\') AS "heureFin",
                co."Cours_Salle" AS salle,
                (cs."CS_NumCours" IS NOT NULL) AS signe, cs."CS_DateSignature" AS "dateSignature",
                (cd."CSD_NumCours" IS NOT NULL) AS debloque,
                (psd."PSD_CodePromo" IS NOT NULL) AS "debloquePromo",
                (((now() AT TIME ZONE \'Europe/Paris\') BETWEEN
                   (co."Cours_Date" + COALESCE(co."Cours_HeureDebut", \'00:00\') - (' . SIGNATURE_MARGE_MINUTES . ' || \' minutes\')::interval)
                 AND (co."Cours_Date" + COALESCE(co."Cours_HeureFin", \'23:59:59\') + (' . SIGNATURE_MARGE_MINUTES . ' || \' minutes\')::interval)
                ) OR psd."PSD_CodePromo" IS NOT NULL) AS "dansFenetre"
           FROM "T_Cours" co
           LEFT JOIN "T_Cours_Signature" cs ON cs."CS_NumCours" = co."Cours_NumCours" AND cs."CS_CodeStagiaire" = :s
           LEFT JOIN "T_Cours_Signature_Deblocage" cd ON cd."CSD_NumCours" = co."Cours_NumCours" AND cd."CSD_CodeStagiaire" = :s
           LEFT JOIN "T_Promo_Signature_Deblocage" psd ON psd."PSD_CodePromo" = co."Cours_CodePromo" AND psd."PSD_DateExpiration" > now()
          WHERE co."Cours_CodePromo" = :p
            AND (co."Cours_GroupeEntier" OR COALESCE(co."Cours_Groupe", \'\') = \'\' OR co."Cours_Groupe" = :g)
          ORDER BY co."Cours_Date", co."Cours_HeureDebut"',
        [':p' => $codePromo, ':g' => (string)$i['groupe'], ':s' => $c['sub']]
    );
    Http::json($r);
}

/** Enregistre la signature (image PNG en base64) d'une séance. */
function route_signature_signer(string $codePromo, string $numCours): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);

    $seance = Db::ligne(
        'SELECT "Cours_NumCours"::text AS "numCours", "Cours_Date" AS date,
                "Cours_HeureDebut" AS "heureDebut", "Cours_HeureFin" AS "heureFin",
                "Cours_CodePromo"::text AS "codePromo",
                ("Cours_GroupeEntier" OR COALESCE("Cours_Groupe", \'\') = \'\' OR "Cours_Groupe" = :g) AS "dansGroupe"
           FROM "T_Cours" WHERE "Cours_NumCours" = :n',
        [':n' => $numCours, ':g' => (string)$i['groupe']]
    );
    if (!$seance || $seance['codePromo'] !== $codePromo || !$seance['dansGroupe']) Http::erreur(404, 'Séance introuvable.');

    $dejaSigne = Db::ligne(
        'SELECT 1 AS ok FROM "T_Cours_Signature" WHERE "CS_NumCours" = :n AND "CS_CodeStagiaire" = :s',
        [':n' => $numCours, ':s' => $c['sub']]
    );
    if ($dejaSigne) Http::erreur(409, 'Cette séance a déjà été signée.');

    $debloque = Db::ligne(
        'SELECT 1 AS ok FROM "T_Cours_Signature_Deblocage" WHERE "CSD_NumCours" = :n AND "CSD_CodeStagiaire" = :s',
        [':n' => $numCours, ':s' => $c['sub']]
    );

    // E10 : déblocage temporaire de toute la promo (bouton formateur), en plus du déblocage
    // séance+stagiaire ci-dessus.
    if (!$debloque && promo_signature_debloquee($codePromo)) {
        $debloque = ['ok' => 1];
    }

    if (!$debloque) {
        $fenetre = Db::ligne(
            'SELECT ((now() AT TIME ZONE \'Europe/Paris\') BETWEEN
                       (:d::date + COALESCE(:hd::time, \'00:00\') - (:m || \' minutes\')::interval)
                     AND (:d::date + COALESCE(:hf::time, \'23:59:59\') + (:m || \' minutes\')::interval)) AS ok',
            [':d' => $seance['date'], ':hd' => $seance['heureDebut'], ':hf' => $seance['heureFin'], ':m' => (string)SIGNATURE_MARGE_MINUTES]
        );
        if (!$fenetre || !$fenetre['ok']) Http::erreur(403, "Signature hors créneau. Demandez à votre formateur de vous débloquer si besoin.");
    }

    $corps = Http::corps();
    $image = (string)($corps['image'] ?? '');
    if (!preg_match('#^data:image/png;base64,(.+)$#', $image, $m)) Http::erreur(400, 'Image de signature invalide.');
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) < 50 || strlen($bin) > 2_000_000) Http::erreur(400, 'Image de signature invalide.');

    $chemin = signature_chemin_disque($codePromo, $c['sub'], $numCours);
    if (!is_dir(dirname($chemin)) && !@mkdir(dirname($chemin), 0777, true) && !is_dir(dirname($chemin))) {
        error_log('[alcours api] Impossible de créer le dossier de signature : ' . dirname($chemin));
        Http::erreur(503, "Échec de l'enregistrement du fichier.");
    }
    if (@file_put_contents($chemin, $bin) === false) {
        error_log('[alcours api] Échec écriture signature : ' . $chemin);
        Http::erreur(503, "Échec de l'enregistrement du fichier.");
    }

    Db::exec(
        'INSERT INTO "T_Cours_Signature" ("CS_NumCours", "CS_CodeStagiaire", "CS_Chemin") VALUES (:n, :s, :ch)',
        [':n' => $numCours, ':s' => $c['sub'], ':ch' => $chemin]
    );

    Http::json(['ok' => true]);
}

/** Sert l'image de la signature déjà enregistrée par le stagiaire connecté. */
function route_signature_image(string $codePromo, string $numCours): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    unset($i);

    $ligne = Db::ligne(
        'SELECT "CS_Chemin" AS chemin FROM "T_Cours_Signature" WHERE "CS_NumCours" = :n AND "CS_CodeStagiaire" = :s',
        [':n' => $numCours, ':s' => $c['sub']]
    );
    if (!$ligne || !is_file((string)$ligne['chemin'])) Http::erreur(404, 'Signature introuvable.');

    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . (string)filesize((string)$ligne['chemin']));
    readfile((string)$ligne['chemin']);
    exit;
}

/** Formateur : débloque la signature hors créneau pour un stagiaire sur une séance donnée.
 *  Route prête ; l'écran formateur pour l'appeler viendra avec l'interface formateur. */
function route_signature_debloquer(): void
{
    $f = Jeton::formateur();

    $corps = Http::corps();
    $numCours = (string)($corps['numCours'] ?? '');
    $codeStagiaire = (string)($corps['codeStagiaire'] ?? '');
    if (!preg_match('/^\d{1,19}$/', $numCours) || !preg_match('/^\d{1,19}$/', $codeStagiaire)) {
        Http::erreur(400, 'Paramètres invalides.');
    }

    $existe = Db::ligne('SELECT 1 AS ok FROM "T_Cours" WHERE "Cours_NumCours" = :n', [':n' => $numCours]);
    if (!$existe) Http::erreur(404, 'Séance introuvable.');

    Db::exec(
        'INSERT INTO "T_Cours_Signature_Deblocage" ("CSD_NumCours", "CSD_CodeStagiaire", "CSD_CodeFormateur")
         VALUES (:n, :s, :f)
         ON CONFLICT ("CSD_NumCours", "CSD_CodeStagiaire")
         DO UPDATE SET "CSD_CodeFormateur" = :f, "CSD_DateDeblocage" = now()',
        [':n' => $numCours, ':s' => $codeStagiaire, ':f' => $f['sub']]
    );

    Http::json(['ok' => true]);
}

/** Formateur : débloque temporairement la signature pour TOUTE une promo (tous stagiaires, toutes
 *  séances), pendant SIGNATURE_DEBLOCAGE_PROMO_MINUTES minutes à partir de maintenant (E10,
 *  30/09/2026 — bouton « Autoriser signatures pour cette promo » dans Gestion des fichiers). Un
 *  nouvel appel prolonge (remplace l'expiration par maintenant + la même durée). */
function route_signature_debloquer_promo(string $codePromo): void
{
    $f = Jeton::formateur();

    $existe = Db::ligne('SELECT 1 AS ok FROM "T_Promo" WHERE "Pro_CodePromo" = :p', [':p' => $codePromo]);
    if (!$existe) Http::erreur(404, 'Promo introuvable.');

    Db::exec(
        'INSERT INTO "T_Promo_Signature_Deblocage" ("PSD_CodePromo", "PSD_DateExpiration", "PSD_CodeFormateur")
         VALUES (:p, now() + (:m || \' minutes\')::interval, :f)
         ON CONFLICT ("PSD_CodePromo")
         DO UPDATE SET "PSD_DateExpiration" = now() + (:m || \' minutes\')::interval,
                        "PSD_CodeFormateur" = :f, "PSD_DateDeblocage" = now()',
        [':p' => $codePromo, ':m' => (string)SIGNATURE_DEBLOCAGE_PROMO_MINUTES, ':f' => $f['sub']]
    );

    $expiration = Db::ligne(
        'SELECT "PSD_DateExpiration" AS expiration FROM "T_Promo_Signature_Deblocage" WHERE "PSD_CodePromo" = :p',
        [':p' => $codePromo]
    );

    Http::json(['ok' => true, 'expiration' => $expiration['expiration'] ?? null, 'minutes' => SIGNATURE_DEBLOCAGE_PROMO_MINUTES]);
}

// ─── E11 (30/09/2026) : récupération des signatures par le formateur (« feuille de présence »),
// remplace PAGE_Feuille_Presence de l'ancien site (PDF assemblé côté client, faute d'extension PDF
// fiable sur l'hébergement OVH mutualisé — même choix que le zip groupé de E7 et l'import Excel de
// E8bis). Deux routes : la liste (promo + séances + stagiaires + état signature) et l'image d'une
// signature donnée. Périmètre : une séance précise (paramètre ?numCours=) ou toute la promo.

/** Formateur : promo + client + séances (filtrées par ?numCours= si fourni) + pour chaque séance,
 *  les stagiaires concernés (même règle de groupe que route_seances/route_signature_liste) avec
 *  leur état de signature. Pas de restriction par formateur : comme route_admin_promos, tous les
 *  formateurs voient toutes les promos (décision Jérôme du 25/09, réutilisée ici). */
function route_admin_signatures_promo(string $codePromo): void
{
    Jeton::formateur();

    $promo = Db::ligne(
        'SELECT p."Pro_CodePromo"::text AS code, p."Pro_Libelle" AS libelle, p."Pro_SujetForm" AS sujet,
                p."Pro_Du" AS du, p."Pro_Au" AS au, c."Cli_RaisonSociale" AS client
           FROM "T_Promo" p LEFT JOIN "T_Client" c ON c."Cli_CodeClient" = p."Pro_CodeClient"
          WHERE p."Pro_CodePromo" = :p',
        [':p' => $codePromo]
    );
    if (!$promo) Http::erreur(404, 'Promo introuvable.');

    $numCoursFiltre = trim((string)($_GET['numCours'] ?? ''));
    $filtreNumCours = '';
    $params = [':p' => $codePromo];
    if ($numCoursFiltre !== '') {
        if (!preg_match('/^\d{1,19}$/', $numCoursFiltre)) Http::erreur(400, 'Séance invalide.');
        $filtreNumCours = ' AND co."Cours_NumCours" = :n';
        $params[':n'] = $numCoursFiltre;
    }

    $lignes = Db::lignes(
        'SELECT co."Cours_NumCours"::text AS "numCours", co."Cours_Date" AS date,
                to_char(co."Cours_HeureDebut", \'HH24:MI\') AS "heureDebut", to_char(co."Cours_HeureFin", \'HH24:MI\') AS "heureFin",
                co."Cours_Salle" AS salle,
                f."Forma_Prenom" AS "formateurPrenom", f."Forma_Nom" AS "formateurNom",
                s."Stag_CodeStagiaire"::text AS "codeStagiaire", s."Stag_Nom" AS "stagiaireNom", s."Stag_Prenom" AS "stagiairePrenom",
                (cs."CS_NumCours" IS NOT NULL) AS signe, cs."CS_DateSignature" AS "dateSignature"
           FROM "T_Cours" co
           LEFT JOIN "T_Formateur" f ON f."Forma_CodeFormateur" = co."Cours_CodeFormateur"
           JOIN "T_Promo_Stagiaire" ps ON ps."PromSta_CodePromo" = co."Cours_CodePromo" AND ps."PromSta_Actif"
                AND (co."Cours_GroupeEntier" OR COALESCE(co."Cours_Groupe", \'\') = \'\' OR co."Cours_Groupe" = ps."PromSta_Groupe")
           JOIN "T_Stagiaire" s ON s."Stag_CodeStagiaire" = ps."PromSta_CodeStagiaire"
           LEFT JOIN "T_Cours_Signature" cs ON cs."CS_NumCours" = co."Cours_NumCours" AND cs."CS_CodeStagiaire" = s."Stag_CodeStagiaire"
          WHERE co."Cours_CodePromo" = :p' . $filtreNumCours . '
          ORDER BY co."Cours_Date", co."Cours_HeureDebut", s."Stag_Nom", s."Stag_Prenom"',
        $params
    );
    if ($numCoursFiltre !== '' && !$lignes) Http::erreur(404, 'Séance introuvable.');

    // Regroupement par séance (fait en PHP : une ligne SQL par couple séance/stagiaire).
    $seances = [];
    foreach ($lignes as $l) {
        $n = $l['numCours'];
        if (!isset($seances[$n])) {
            $seances[$n] = [
                'numCours' => $n, 'date' => $l['date'], 'heureDebut' => $l['heureDebut'], 'heureFin' => $l['heureFin'],
                'salle' => $l['salle'],
                'formateur' => trim(($l['formateurPrenom'] ?? '') . ' ' . ($l['formateurNom'] ?? '')),
                'stagiaires' => [],
            ];
        }
        $seances[$n]['stagiaires'][] = [
            'codeStagiaire' => $l['codeStagiaire'],
            'nom' => $l['stagiaireNom'], 'prenom' => $l['stagiairePrenom'],
            'signe' => $l['signe'], 'dateSignature' => $l['dateSignature'],
        ];
    }

    $promo['seances'] = array_values($seances);
    Http::json($promo);
}

/** Formateur : image PNG d'une signature (n'importe quel stagiaire/séance — pas de restriction au
 *  stagiaire connecté, contrairement à route_signature_image côté espace stagiaire). */
function route_admin_signature_image(string $numCours, string $codeStagiaire): void
{
    Jeton::formateur();

    $ligne = Db::ligne(
        'SELECT "CS_Chemin" AS chemin FROM "T_Cours_Signature" WHERE "CS_NumCours" = :n AND "CS_CodeStagiaire" = :s',
        [':n' => $numCours, ':s' => $codeStagiaire]
    );
    if (!$ligne || !is_file((string)$ligne['chemin'])) Http::erreur(404, 'Signature introuvable.');

    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . (string)filesize((string)$ligne['chemin']));
    readfile((string)$ligne['chemin']);
    exit;
}
