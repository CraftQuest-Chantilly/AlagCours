<?php
// E6 (partie 2/2) — QCM noté (PAGE_Quizz_Promo / réponses stagiaire côté al-cours), système
// T_QuizzPromo / T_Stagiaire_Quizz_Questions / T_Stagiaire_QuestionRep. Distinct du système
// "Évaluation de la formation" (routes/evaluation.php).
//
// Ce système avait déjà des RPC Supabase prêtes côté base mais jamais appelées par aucune page
// de l'ancien site (« orphelines ») : sc_stag_quizz_liste(), sc_stag_quizz_questions(p_qprom),
// sc_stag_repondre(p_sqq, p_choix, p_rep_libre). Elles ne sont pas appelées directement (l'API
// PHP utilise le rôle service_role, qui n'a pas de session stagiaire pour sc_my_stagiaire()) :
// leur logique SQL est reproduite fidèlement ici, avec le code stagiaire pris dans le jeton JWT.
//
// Modèle : un formateur planifie un quizz pour une promo (T_QuizzPromo) et pré-génère les
// questions assignées à chaque stagiaire (T_Stagiaire_Quizz_Questions) ainsi que, pour les QCM,
// une ligne T_Stagiaire_QuestionRep par choix possible (le stagiaire ne fait que cocher/décocher
// des lignes déjà là, jamais en créer). Cette génération fait partie de la future interface
// formateur (pas encore construite) : tant qu'aucun quizz n'est assigné à une promo, la liste
// reste vide côté stagiaire — comportement normal, pas un bug.
//
// Notation : « note » = nombre de questions correctes (StaQuiQues_RepOK), PAS une note sur
// Q_NoteSur (fidèle à sc_quizz_note() — Q_NoteSur ici n'est affiché qu'à titre de barème/total).
// Les réponses et corrections ne sont jamais révélées au stagiaire tant que QP_ValideNote = false
// (le formateur n'a pas encore validé/clôturé le quizz) — reproduit du comportement des RPC
// (rep_ok / correcte renvoyés seulement si QP_ValideNote).

/** Session de quizz (T_QuizzPromo) vérifiée pour cette promo ET assignée à ce stagiaire, sinon 404. */
function quizz_promo_pour_stagiaire(string $codePromo, int $qprom, string $codeStag): array
{
    $qp = Db::ligne(
        'SELECT qp."QP_CodeQProm" AS "codeQProm", qp."QP_CodeProm"::text AS "codePromo",
                qp."QP_ValideNote" AS "valideNote", qp."QP_DateQProm" AS date,
                qp."QP_HeureDebQProm" AS "heureDebut", qp."QP_HeurefinQProm" AS "heureFin",
                q."Q_NomQ" AS nom, q."Q_NoteSur" AS "noteSur"
           FROM "T_QuizzPromo" qp JOIN "T_Quizz" q ON q."Q_CodeQ" = qp."QP_CodeQuizz"
          WHERE qp."QP_CodeQProm" = :qp',
        [':qp' => $qprom]
    );
    if (!$qp || $qp['codePromo'] !== $codePromo) Http::erreur(404, 'Quizz introuvable.');

    $existe = Db::ligne(
        'SELECT 1 AS ok FROM "T_Stagiaire_Quizz_Questions"
          WHERE "StaQuiQues_CodeQProm" = :qp AND "StaQuiQues_CodeStag" = :s LIMIT 1',
        [':qp' => $qprom, ':s' => $codeStag]
    );
    if (!$existe) Http::erreur(404, 'Quizz introuvable.');

    return $qp;
}

/** Liste des quizz assignés au stagiaire pour cette promo (reprend sc_stag_quizz_liste, scopé promo). */
function route_quizz_liste(string $codePromo): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    unset($i);

    $r = Db::lignes(
        'SELECT qp."QP_CodeQProm"::text AS "codeQProm", q."Q_NomQ" AS nom, qp."QP_DateQProm" AS date,
                to_char(qp."QP_HeureDebQProm", \'HH24:MI\') AS "heureDebut", to_char(qp."QP_HeurefinQProm", \'HH24:MI\') AS "heureFin",
                qp."QP_ValideNote" AS "valideNote", q."Q_NoteSur" AS "noteSur",
                (SELECT COUNT(*) FROM "T_Stagiaire_Quizz_Questions" x
                   WHERE x."StaQuiQues_CodeQProm" = qp."QP_CodeQProm" AND x."StaQuiQues_CodeStag" = :s) AS "nbQuestions",
                CASE WHEN qp."QP_ValideNote" THEN
                  (SELECT COALESCE(SUM(CASE WHEN y."StaQuiQues_RepOK" THEN 1 ELSE 0 END), 0)
                     FROM "T_Stagiaire_Quizz_Questions" y
                    WHERE y."StaQuiQues_CodeQProm" = qp."QP_CodeQProm" AND y."StaQuiQues_CodeStag" = :s)
                END AS note
           FROM "T_QuizzPromo" qp
           JOIN "T_Quizz" q ON q."Q_CodeQ" = qp."QP_CodeQuizz"
          WHERE qp."QP_CodeProm" = :p
            AND EXISTS (SELECT 1 FROM "T_Stagiaire_Quizz_Questions" z
                         WHERE z."StaQuiQues_CodeQProm" = qp."QP_CodeQProm" AND z."StaQuiQues_CodeStag" = :s)
          ORDER BY qp."QP_DateQProm" DESC NULLS LAST',
        [':p' => $codePromo, ':s' => $c['sub']]
    );
    Http::json($r);
}

/** Questions + réponses (déjà cochées le cas échéant) d'un quizz (reprend sc_stag_quizz_questions). */
function route_quizz_questions(string $codePromo, string $qpromBrut): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    unset($i);
    $qprom = (int)Http::id($qpromBrut);
    $qp = quizz_promo_pour_stagiaire($codePromo, $qprom, $c['sub']);
    $litValide = $qp['valideNote'] ? 'TRUE' : 'FALSE'; // valeur serveur, pas une entrée utilisateur : inlinée sans risque

    $questions = Db::lignes(
        'SELECT sqq."StaQuiQues_CodeSQQ" AS sqq, sqq."StaQuiQues_Ordre" AS ordre, qu."Quest_LibQuest" AS libelle,
                qu."Quest_RepLibre" AS "repLibre",
                CASE WHEN ' . $litValide . ' THEN sqq."StaQuiQues_RepOK" END AS "repOk"
           FROM "T_Stagiaire_Quizz_Questions" sqq
           JOIN "T_Question" qu ON qu."Quest_CodeQuest" = sqq."StaQuiQues_CodeQuest"
          WHERE sqq."StaQuiQues_CodeQProm" = :qp AND sqq."StaQuiQues_CodeStag" = :s
          ORDER BY sqq."StaQuiQues_Ordre"',
        [':qp' => $qprom, ':s' => $c['sub']]
    );

    foreach ($questions as &$q) {
        $q['reponses'] = Db::lignes(
            'SELECT r."SQR_CodeStagQuestR"::text AS sqr, qr."QR_LibQuestR" AS libelle,
                    r."SQR_ChoixStag" AS choisi, r."SQR_RepLibre" AS texte,
                    CASE WHEN ' . $litValide . ' THEN r."SQR_ChoixOK" END AS correcte
               FROM "T_Stagiaire_QuestionRep" r
               LEFT JOIN "T_QuestionRep" qr ON qr."QR_CodeQuestR" = r."SQR_CodeQuestR"
              WHERE r."SQR_CodeSQQ" = :sqq
              ORDER BY r."SQR_Ordre"',
            [':sqq' => $q['sqq']]
        );
    }
    unset($q);

    Http::json(['quizz' => ['nom' => $qp['nom'], 'noteSur' => $qp['noteSur'], 'valideNote' => $qp['valideNote']], 'questions' => $questions]);
}

/** Enregistre la réponse à UNE question (reprend sc_stag_repondre, appelée à chaque interaction
 *  du stagiaire — pas de soumission groupée, comme dans le système d'origine). */
function route_quizz_repondre(string $codePromo, string $qpromBrut): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    unset($i);
    $qprom = (int)Http::id($qpromBrut);
    $qp = quizz_promo_pour_stagiaire($codePromo, $qprom, $c['sub']);
    if ($qp['valideNote']) Http::erreur(409, 'Ce quizz est clôturé, les réponses ne sont plus modifiables.');

    if ($qp['date'] !== null) {
        $debut = $qp['date'] . ' ' . ($qp['heureDebut'] ?? '00:00:00');
        $fin = $qp['date'] . ' ' . ($qp['heureFin'] ?? '23:59:59');
        $ok = Db::ligne(
            "SELECT ((now() AT TIME ZONE 'Europe/Paris') BETWEEN :d::timestamp AND :f::timestamp) AS ok",
            [':d' => $debut, ':f' => $fin]
        );
        if (!$ok || !$ok['ok']) Http::erreur(403, 'Ce quizz est hors créneau horaire.');
    }

    $corps = Http::corps();
    $sqq = (int)($corps['sqq'] ?? 0);
    if ($sqq <= 0) Http::erreur(400, 'Question invalide.');

    $question = Db::ligne(
        'SELECT sqq."StaQuiQues_CodeSQQ" AS sqq, qu."Quest_RepLibre" AS "repLibre"
           FROM "T_Stagiaire_Quizz_Questions" sqq
           JOIN "T_Question" qu ON qu."Quest_CodeQuest" = sqq."StaQuiQues_CodeQuest"
          WHERE sqq."StaQuiQues_CodeSQQ" = :sqq AND sqq."StaQuiQues_CodeQProm" = :qp AND sqq."StaQuiQues_CodeStag" = :s',
        [':sqq' => $sqq, ':qp' => $qprom, ':s' => $c['sub']]
    );
    if (!$question) Http::erreur(404, 'Question introuvable.');

    if ($question['repLibre']) {
        $texte = mb_substr(trim((string)($corps['repLibre'] ?? '')), 0, 100);
        $maj = Db::exec('UPDATE "T_Stagiaire_QuestionRep" SET "SQR_RepLibre" = :t WHERE "SQR_CodeSQQ" = :sqq', [':t' => $texte, ':sqq' => $sqq]);
        if ($maj === 0) {
            Db::exec('INSERT INTO "T_Stagiaire_QuestionRep" ("SQR_CodeSQQ", "SQR_RepLibre") VALUES (:sqq, :t)', [':sqq' => $sqq, ':t' => $texte]);
        }
        Http::json(['ok' => true]);
        return;
    }

    $choix = $corps['choix'] ?? [];
    if (!is_array($choix)) Http::erreur(400, 'Choix invalide.');
    $choix = array_values(array_filter(array_map('intval', $choix), fn ($v) => $v > 0));
    $listeChoix = '{' . implode(',', $choix) . '}';

    Db::exec(
        'UPDATE "T_Stagiaire_QuestionRep"
            SET "SQR_ChoixStag" = ("SQR_CodeStagQuestR" = ANY(:choix::bigint[])),
                "SQR_Point" = (("SQR_CodeStagQuestR" = ANY(:choix::bigint[])) = "SQR_ChoixOK")
          WHERE "SQR_CodeSQQ" = :sqq',
        [':choix' => $listeChoix, ':sqq' => $sqq]
    );
    $tout = Db::ligne('SELECT bool_and("SQR_Point") AS ok FROM "T_Stagiaire_QuestionRep" WHERE "SQR_CodeSQQ" = :sqq', [':sqq' => $sqq]);
    Db::exec(
        'UPDATE "T_Stagiaire_Quizz_Questions" SET "StaQuiQues_RepOK" = :ok WHERE "StaQuiQues_CodeSQQ" = :sqq',
        [':ok' => ($tout && $tout['ok']) ? 'true' : 'false', ':sqq' => $sqq]
    );

    Http::json(['ok' => true]);
}
