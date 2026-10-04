<?php
// E6 (partie 1/2) — Évaluation de la formation (PAGE_Evaluation d'al-cours), système "Eval" de
// T_Quizz / T_Quizz_Eval_Rep. L'autre système (QCM noté, T_QuizzPromo) est traité séparément
// dans routes/quizz.php sous la tuile « Quizz ».
//
// Décisions validées avec Jérôme le 25/09/2026, après analyse des 18945 réponses réelles :
//   - QER_Valeur = une note brute sur l'échelle 0..Q_NoteSur (ici 0..100), PAS un rang parmi des
//     choix : pour le quiz "Evaluation Alaguillaume" (Q_CodeQ=17), aucune des 14 questions n'a de
//     ligne T_QuestionRep, donc pas de liste de choix fixe — la notation d'origine se faisait par
//     un curseur/une note libre sur l'échelle.
//   - QER_ReponseTexte était peu fiable dans les données existantes (valeurs dupliquées/décalées
//     entre questions d'une même série de réponses, cf. plan-fusion : probablement un bug de
//     réutilisation de variable côté ancien site WebDev). Le formulaire d'origine (E6) ne
//     capturait donc qu'UNE seule remarque libre, rattachée à la dernière question.
//   - Révision du 30/09/2026 (Jérôme, E12) : le formulaire stagiaire passe de boutons
//     Insuffisant/Moyen/Bien/Très bien/Excellent à une note libre sur 100 ET une zone de
//     commentaire PAR question. QER_ReponseTexte est donc désormais rempli pour chaque question
//     individuellement (plus seulement la dernière).
//   - Aucune table ne reliait une promo à un quiz "Eval" dans l'ancien site : nouvelle table
//     T_Promo_Quizz_Eval (créée cette étape). Absence de ligne pour une promo → quiz par défaut
//     Q_CodeQ=17 ("Evaluation Alaguillaume", seul quiz réellement utilisé en production).
//   - Un stagiaire ne répond qu'une fois par promo (pas de renvoi dans l'ancien schéma) : l'API
//     vérifie l'existence de réponses avant d'accepter une soumission (409 sinon) et renvoie les
//     réponses déjà données en lecture seule si la formation a déjà été évaluée.

const EVAL_QUIZZ_PAR_DEFAUT = 17; // "Evaluation Alaguillaume"

/** Quiz d'évaluation associé à la promo (liaison explicite sinon valeur par défaut). */
function eval_code_quizz(string $codePromo): int
{
    $r = Db::ligne(
        'SELECT "PQE_CodeQ" AS code FROM "T_Promo_Quizz_Eval" WHERE "PQE_CodePromo" = :p',
        [':p' => $codePromo]
    );
    return $r ? (int)$r['code'] : EVAL_QUIZZ_PAR_DEFAUT;
}

/** Questions du quiz, dans l'ordre d'origine (Quest_Ordre). */
function eval_questions(int $codeQ): array
{
    return Db::lignes(
        'SELECT "Quest_CodeQuest" AS code, "Quest_LibQuest" AS libelle, "Quest_Ordre" AS ordre
           FROM "T_Question"
          WHERE "Quest_CodeQ" = :q
          ORDER BY "Quest_Ordre"::int NULLS LAST, "Quest_CodeQuest"',
        [':q' => $codeQ]
    );
}

function route_evaluation(string $codePromo): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    unset($i);

    $codeQ = eval_code_quizz($codePromo);
    $quizz = Db::ligne('SELECT "Q_NomQ" AS nom, "Q_NoteSur" AS "noteSur" FROM "T_Quizz" WHERE "Q_CodeQ" = :q', [':q' => $codeQ]);
    if (!$quizz) Http::erreur(500, "Quiz d'évaluation introuvable (configuration invalide).");

    $questions = eval_questions($codeQ);
    if (!$questions) Http::erreur(500, "Le quiz d'évaluation ne contient aucune question.");

    $reponses = Db::lignes(
        'SELECT "QER_CodeQuest" AS "codeQuest", "QER_Valeur" AS valeur, "QER_ReponseTexte" AS remarque
           FROM "T_Quizz_Eval_Rep"
          WHERE "QER_CodeStagiaire" = :s AND "QER_CodePromo" = :p
            AND "QER_CodeQuest" = ANY(:questions::int[])',
        [':s' => $c['sub'], ':p' => $codePromo, ':questions' => '{' . implode(',', array_column($questions, 'code')) . '}']
    );

    Http::json([
        'noteSur' => (int)$quizz['noteSur'],
        'questions' => $questions,
        'dejaRepondu' => count($reponses) > 0,
        'reponses' => $reponses,
    ]);
}

function route_evaluation_soumettre(string $codePromo): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    unset($i);

    $codeQ = eval_code_quizz($codePromo);
    $questions = eval_questions($codeQ);
    if (!$questions) Http::erreur(500, "Le quiz d'évaluation ne contient aucune question.");
    $quizz = Db::ligne('SELECT "Q_NoteSur" AS "noteSur" FROM "T_Quizz" WHERE "Q_CodeQ" = :q', [':q' => $codeQ]);
    $noteSur = (int)($quizz['noteSur'] ?? 100);

    // Un seul passage autorisé par promo.
    $existe = Db::ligne(
        'SELECT 1 AS ok FROM "T_Quizz_Eval_Rep"
          WHERE "QER_CodeStagiaire" = :s AND "QER_CodePromo" = :p
            AND "QER_CodeQuest" = ANY(:questions::int[]) LIMIT 1',
        [':s' => $c['sub'], ':p' => $codePromo, ':questions' => '{' . implode(',', array_column($questions, 'code')) . '}']
    );
    if ($existe) Http::erreur(409, 'Cette formation a déjà été évaluée.');

    $corps = Http::corps();
    $valeurs = $corps['valeurs'] ?? null; // { "<codeQuest>": 0..noteSur, ... }
    $remarques = $corps['remarques'] ?? []; // { "<codeQuest>": "commentaire libre", ... } (E12, 30/09/2026)
    if (!is_array($valeurs)) Http::erreur(400, 'Réponses manquantes.');
    if (!is_array($remarques)) Http::erreur(400, 'Format de remarques invalide.');

    foreach ($questions as $q) {
        $code = (int)$q['code'];
        if (!array_key_exists((string)$code, $valeurs)) Http::erreur(400, "Réponse manquante pour la question $code.");
        $v = $valeurs[(string)$code];
        if (!is_int($v) && !is_float($v)) Http::erreur(400, "Valeur invalide pour la question $code.");
        if ($v < 0 || $v > $noteSur) Http::erreur(400, "Valeur hors échelle (0 à $noteSur) pour la question $code.");
    }

    foreach ($questions as $q) {
        $code = (int)$q['code'];
        $remarque = trim((string)($remarques[(string)$code] ?? ''));
        Db::exec(
            'INSERT INTO "T_Quizz_Eval_Rep" ("QER_CodePromo", "QER_CodeStagiaire", "QER_CodeQuest", "QER_Valeur", "QER_ReponseTexte")
             VALUES (:p, :s, :q, :v, :r)',
            [
                ':p' => $codePromo,
                ':s' => $c['sub'],
                ':q' => $code,
                ':v' => (int)round($valeurs[(string)$code]),
                ':r' => $remarque,
            ]
        );
    }

    Http::json(['ok' => true]);
}

// ─── Récapitulatifs formateur (E12, 30/09/2026) : deux exports PDF depuis « Gestion des
// fichiers » — recap anonyme (moyenne par question) et détail par stagiaire (3 moyennes).
// Le calcul des moyennes (hors 0, hors questions "**") est fait côté front (jsPDF) : cette
// route ne fait que renvoyer les données brutes nécessaires.

/** Formateur : toutes les réponses d'évaluation d'une promo, groupées par stagiaire. */
function route_admin_evaluation_promo(string $codePromo): void
{
    Jeton::formateur();

    $promo = Db::ligne(
        'SELECT p."Pro_CodePromo"::text AS code, p."Pro_Libelle" AS libelle, p."Pro_SujetForm" AS sujet,
                p."Pro_Du" AS du, p."Pro_Au" AS au, c."Cli_RaisonSociale" AS client,
                f."Forma_Nom" AS "formateurNom", f."Forma_Prenom" AS "formateurPrenom"
           FROM "T_Promo" p
           LEFT JOIN "T_Client" c ON c."Cli_CodeClient" = p."Pro_CodeClient"
           LEFT JOIN "T_Formateur" f ON f."Forma_CodeFormateur" = p."Pro_CodeFormateur"
          WHERE p."Pro_CodePromo" = :p',
        [':p' => $codePromo]
    );
    if (!$promo) Http::erreur(404, 'Promo introuvable.');

    $codeQ = eval_code_quizz($codePromo);
    $quizz = Db::ligne('SELECT "Q_NoteSur" AS "noteSur" FROM "T_Quizz" WHERE "Q_CodeQ" = :q', [':q' => $codeQ]);
    $questions = eval_questions($codeQ);

    $reponses = Db::lignes(
        'SELECT r."QER_CodeStagiaire" AS "codeStagiaire", r."QER_CodeQuest" AS "codeQuest",
                r."QER_Valeur" AS valeur, r."QER_ReponseTexte" AS remarque,
                s."Stag_Nom" AS nom, s."Stag_Prenom" AS prenom
           FROM "T_Quizz_Eval_Rep" r
           JOIN "T_Stagiaire" s ON s."Stag_CodeStagiaire" = r."QER_CodeStagiaire"
          WHERE r."QER_CodePromo" = :p AND r."QER_CodeQuest" = ANY(:questions::int[])
          ORDER BY s."Stag_Nom", s."Stag_Prenom", r."QER_CodeQuest"',
        [':p' => $codePromo, ':questions' => '{' . implode(',', array_column($questions, 'code')) . '}']
    );

    $parStagiaire = [];
    foreach ($reponses as $r) {
        $cs = $r['codeStagiaire'];
        if (!isset($parStagiaire[$cs])) {
            $parStagiaire[$cs] = [
                'codeStagiaire' => $cs,
                'nom' => $r['nom'],
                'prenom' => $r['prenom'],
                'reponses' => [],
            ];
        }
        $parStagiaire[$cs]['reponses'][] = [
            'codeQuest' => (int)$r['codeQuest'],
            'valeur' => (int)$r['valeur'],
            'remarque' => $r['remarque'],
        ];
    }

    Http::json([
        'promo' => $promo,
        'noteSur' => (int)($quizz['noteSur'] ?? 100),
        'questions' => $questions,
        'stagiaires' => array_values($parStagiaire),
    ]);
}
