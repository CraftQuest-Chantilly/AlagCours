<?php
// Connexion : reprend PAGE__Login d'al-cours, qui accepte aussi bien un formateur qu'un
// stagiaire sur le même écran. On cherche d'abord dans T_Formateur (Forma_Login / Forma_PassWord,
// formateurs actifs uniquement), puis, si aucun formateur ne correspond, dans T_Stagiaire
// (Stag_Login / Stag_Mdp) — même ordre que l'original.
// Différences volontaires avec l'original, signalées dans recap-etape-2.md :
//  - le mot de passe saisi n'est PLUS écrit dans TS_LOG (l'original le journalisait en clair) ;
//  - le captcha après 3 échecs est remplacé par un blocage côté serveur
//    (N échecs sur une fenêtre de temps, comptés dans TS_LOG) ;
//  - un login présent sur plusieurs comptes (formateur ou stagiaire) est refusé (l'original
//    prenait le premier) ;
//  - un même identifiant existant à la fois côté formateur et côté stagiaire authentifiera
//    toujours la branche formateur (comme l'original) — à signaler si ce cas existe en pratique.

/** Vérifie un mot de passe stocké soit en bcrypt ($2a$/$2b$/$2y$...), soit en clair (legacy). */
function verifier_mdp(string $saisi, string $stocke): bool
{
    if ($stocke === '') return false;
    return preg_match('/^\$2[aby]\$/', $stocke) ? password_verify($saisi, $stocke) : hash_equals($stocke, $saisi);
}

function journaliser_connexion(string $identifiant, string $statut, ?string $codeUser, string $info = ''): void
{
    Db::exec(
        'INSERT INTO "TS_LOG" ("LOG_IdLOG","LOG_Date","LOG_Heure","LOG_Identifiant","LOG_IP","LOG_Co_Deco","LOG_CodeUser","LOG_Info","LOG_Out","LOG_HeureDerGo")
         VALUES ((SELECT COALESCE(MAX("LOG_IdLOG"), 0) + 1 FROM "TS_LOG"), :d, :h, :id, :ip, :st, :cu, :info, \'0\', :h)',
        [
            ':d' => date('Y-m-d'), ':h' => date('H:i:s'),
            ':id' => mb_substr($identifiant, 0, 50), ':ip' => Http::ip(),
            ':st' => $statut, ':cu' => $codeUser, ':info' => mb_substr($info, 0, 50),
        ]
    );
}

function trop_d_echecs(string $identifiant): bool
{
    // Deux compteurs : par identifiant (seuil bas) et par adresse IP (seuil haut, car une
    // salle de formation entière sort souvent sur la même IP publique).
    $c = $GLOBALS['config'];
    $depuis = date('Y-m-d H:i:s', time() - (int)$c['echecs_fenetre']);
    $r = Db::ligne(
        'SELECT count(*) FILTER (WHERE "LOG_Identifiant" = :id) AS par_login,
                count(*) FILTER (WHERE "LOG_IP" = :ip) AS par_ip
           FROM "TS_LOG"
          WHERE "LOG_Co_Deco" = \'Echec_Co\' AND ("LOG_Date" + "LOG_Heure") >= :depuis',
        [':id' => $identifiant, ':ip' => Http::ip(), ':depuis' => $depuis]
    );
    return (int)$r['par_login'] >= (int)$c['echecs_max'] || (int)$r['par_ip'] >= (int)$c['echecs_max_ip'];
}

// ─── Formateur : journal des connexions (équivalent "LogCnx" du BackOffice SqlRA, E8bis
// 25/09/2026) — lecture seule de TS_LOG, déjà alimenté à chaque connexion/échec. Pas de notion de
// "déconnexion" fiable dans le nouveau portail (LOG_Out/LOG_DateHeureOUT restent à false/null,
// comme dans l'original qui ne les exploitait pas non plus de façon systématique d'après les PDF) :
// on affiche donc le journal des évènements (Co/Echec_Co), pas un statut "connecté maintenant".
function route_admin_journal(): void
{
    Jeton::formateur();
    $identifiant = trim((string)($_GET['identifiant'] ?? ''));
    $statut = trim((string)($_GET['statut'] ?? '')); // 'Co' | 'Echec_Co' | '' (tous)
    $limite = max(1, min(500, (int)($_GET['limite'] ?? 200)));

    $filtres = [];
    $params = [];
    if ($identifiant !== '') {
        $filtres[] = '"LOG_Identifiant" ILIKE :id';
        $params[':id'] = '%' . $identifiant . '%';
    }
    if ($statut !== '') {
        $filtres[] = '"LOG_Co_Deco" = :st';
        $params[':st'] = $statut;
    }
    $ou = $filtres ? 'WHERE ' . implode(' AND ', $filtres) : '';

    // $limite est un entier déjà validé/borné ci-dessus (max/min) : concaténation directe sans
    // placeholder, comme les autres LIMIT littéraux du projet (aucun précédent de LIMIT en
    // paramètre lié dans le code existant — évite tout risque de type sur le format %L).
    $r = Db::lignes(
        'SELECT "LOG_IdLOG" AS id, "LOG_Date" AS date, "LOG_Heure" AS heure, "LOG_Identifiant" AS identifiant,
                "LOG_IP" AS ip, "LOG_Co_Deco" AS statut, "LOG_CodeUser"::text AS "codeUser", "LOG_Info" AS info
           FROM "TS_LOG" ' . $ou . '
          ORDER BY "LOG_Date" DESC, "LOG_Heure" DESC LIMIT ' . $limite,
        $params
    );
    Http::json($r);
}

function route_login(): void
{
    $d = Http::corps();
    $login = trim((string)($d['login'] ?? ''));
    $mdp = (string)($d['mdp'] ?? '');
    if ($login === '' || $mdp === '') Http::erreur(400, 'Identifiant et mot de passe obligatoires.');

    if (trop_d_echecs($login)) {
        Http::erreur(429, 'Trop de tentatives. Réessayez dans quelques minutes.');
    }

    // 1) Formateur (actifs uniquement) — même priorité que PAGE__Login d'origine.
    $formateurs = Db::lignes(
        'SELECT "Forma_CodeFormateur"::text AS code, "Forma_Nom" AS nom, "Forma_Prenom" AS prenom,
                "Forma_Initiales" AS initiales, "Forma_PassWord" AS mdp
           FROM "T_Formateur" WHERE "Forma_Login" = :l AND "Forma_Actif" = true',
        [':l' => $login]
    );

    if (count($formateurs) > 1) {
        journaliser_connexion($login, 'Echec_Co', null, 'Login formateur en double');
        Http::erreur(409, 'Cet identifiant est partagé par plusieurs comptes. Contactez l\'administrateur.');
    }

    if ($formateurs) {
        $f = $formateurs[0];
        if (!verifier_mdp($mdp, (string)$f['mdp'])) {
            journaliser_connexion($login, 'Echec_Co', $f['code']);
            Http::erreur(401, 'Identifiant ou mot de passe incorrect.');
        }
        journaliser_connexion($login, 'Co', $f['code'], 'Portail alcours (formateur)');

        $role = in_array($f['initiales'], ['RA', 'NA'], true) ? 'admin' : 'formateur';
        $jeton = Jeton::emettre([
            'typ' => 'formateur', 'sub' => $f['code'], 'nom' => $f['nom'], 'prenom' => $f['prenom'], 'role' => $role,
        ]);
        Http::json([
            'jeton' => $jeton,
            'type' => 'formateur',
            'utilisateur' => ['code' => $f['code'], 'nom' => $f['nom'], 'prenom' => $f['prenom'], 'role' => $role],
        ]);
        return;
    }

    // 2) Sinon, stagiaire.
    $lignes = Db::lignes(
        'SELECT "Stag_CodeStagiaire"::text AS code, "Stag_Nom" AS nom, "Stag_Prenom" AS prenom,
                "Stag_Login" AS login, "Stag_Mdp" AS mdp
           FROM "T_Stagiaire" WHERE "Stag_Login" = :l',
        [':l' => $login]
    );

    if (count($lignes) > 1) {
        journaliser_connexion($login, 'Echec_Co', null, 'Login stagiaire en double');
        Http::erreur(409, 'Cet identifiant est partagé par plusieurs comptes. Contactez votre formateur.');
    }

    $s = $lignes[0] ?? null;
    $stocke = (string)($s['mdp'] ?? '');
    $ok = $s !== null && verifier_mdp($mdp, $stocke);

    if (!$ok) {
        journaliser_connexion($login, 'Echec_Co', $s['code'] ?? null);
        Http::erreur(401, 'Identifiant ou mot de passe incorrect.');
    }

    journaliser_connexion($login, 'Co', $s['code'], 'Portail alcours');

    $jeton = Jeton::emettre([
        'typ' => 'stagiaire',
        'sub' => $s['code'],
        'nom' => $s['nom'],
        'prenom' => $s['prenom'],
    ]);

    Http::json([
        'jeton' => $jeton,
        'type' => 'stagiaire',
        'utilisateur' => [
            'code' => $s['code'],
            'nom' => $s['nom'],
            'prenom' => $s['prenom'],
            // Mot de passe provisoire (= identifiant) : le front affiche un avertissement.
            'mdpProvisoire' => $stocke === $s['login'],
        ],
    ]);
}

function route_me(): void
{
    $c = Jeton::utilisateur();

    if ($c['typ'] === 'formateur') {
        $f = Db::ligne(
            'SELECT "Forma_CodeFormateur"::text AS code, "Forma_Nom" AS nom, "Forma_Prenom" AS prenom,
                    "Forma_Initiales" AS initiales
               FROM "T_Formateur" WHERE "Forma_CodeFormateur" = :c AND "Forma_Actif" = true',
            [':c' => $c['sub']]
        );
        if (!$f) Http::erreur(401, 'Compte introuvable.');
        $f['type'] = 'formateur';
        $f['role'] = in_array($f['initiales'], ['RA', 'NA'], true) ? 'admin' : 'formateur';
        unset($f['initiales']);
        Http::json($f);
        return;
    }

    $s = Db::ligne(
        'SELECT "Stag_CodeStagiaire"::text AS code, "Stag_Nom" AS nom, "Stag_Prenom" AS prenom,
                ("Stag_Mdp" = "Stag_Login") AS "mdpProvisoire"
           FROM "T_Stagiaire" WHERE "Stag_CodeStagiaire" = :c',
        [':c' => $c['sub']]
    );
    if (!$s) Http::erreur(401, 'Compte introuvable.');
    $s['type'] = 'stagiaire';
    Http::json($s);
}

/** Stagiaire : change son propre mot de passe (E8bis, 26/09/2026 — demandé par Jérôme en
 *  complément de la convention Stag_Login/Stag_Mdp existante, conservée telle quelle par
 *  ailleurs : pas de nouveau flux d'inscription/email construit ce soir). Toujours stocké en
 *  bcrypt désormais (verifier_mdp() reste compatible avec l'ancien stockage en clair pour les
 *  comptes qui n'auront pas encore utilisé cet écran). */
function route_stagiaire_changer_mdp(): void
{
    $c = Jeton::stagiaire();
    $corps = Http::corps();
    $actuel = (string)($corps['mdpActuel'] ?? '');
    $nouveau = (string)($corps['nouveauMdp'] ?? '');
    if ($actuel === '' || $nouveau === '') Http::erreur(400, 'Mot de passe actuel et nouveau mot de passe requis.');
    if (mb_strlen($nouveau) < 8) Http::erreur(400, 'Le nouveau mot de passe doit contenir au moins 8 caractères.');

    $s = Db::ligne('SELECT "Stag_Mdp" AS mdp FROM "T_Stagiaire" WHERE "Stag_CodeStagiaire" = :c', [':c' => $c['sub']]);
    if (!$s || !verifier_mdp($actuel, (string)$s['mdp'])) {
        Http::erreur(401, 'Mot de passe actuel incorrect.');
    }

    Db::exec('UPDATE "T_Stagiaire" SET "Stag_Mdp" = :m WHERE "Stag_CodeStagiaire" = :c',
        [':m' => password_hash($nouveau, PASSWORD_BCRYPT), ':c' => $c['sub']]);
    Http::json(['ok' => true]);
}
