<?php
// E6bis — Avis (tuile "Avis" d'al-cours). Dans l'ancien site, `BTN_Avis` avait un code navigateur
// vide : la tuile s'affichait (marqueur `-Avis-` présent sur 10 promos dans `Pro_Doc1`) mais ne
// faisait jamais rien. Rien à reprendre côté ancien site ni côté base pour cette fonctionnalité.
//
// Décisions validées avec Jérôme le 25/09/2026 :
//   - Contenu : quelques critères notés (1 à 5) + un commentaire libre, plutôt qu'une simple note
//     globale. Les 4 critères (contenu, formateur, organisation, recommandation) sont une
//     proposition de ma part faute d'existant à reprendre — à ajuster si le libellé ne convient pas.
//   - Visibilité : anonyme pour le formateur. L'identité du stagiaire reste en base (nécessaire
//     pour permettre la modification libre d'un avis déjà donné), mais AUCUNE route actuelle ne
//     l'expose au formateur. Quand l'interface formateur sera construite, ne jamais exposer le
//     détail nominatif de T_Stagiaire_Avis, seulement des agrégats (moyennes, nombre de réponses).
//   - Fréquence : modifiable librement, pas de verrou comme pour l'Évaluation de la formation —
//     upsert (INSERT ... ON CONFLICT DO UPDATE) à chaque soumission.

/** Avis déjà donné par le stagiaire connecté pour cette promo, s'il existe. */
function route_avis(string $codePromo): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    unset($i);

    $avis = Db::ligne(
        'SELECT "PA_CritContenu" AS "critContenu", "PA_CritFormateur" AS "critFormateur",
                "PA_CritOrganisation" AS "critOrganisation", "PA_CritRecommandation" AS "critRecommandation",
                "PA_Commentaire" AS commentaire, "PA_DateModif" AS "dateModif"
           FROM "T_Stagiaire_Avis"
          WHERE "PA_CodePromo" = :p AND "PA_CodeStagiaire" = :s',
        [':p' => $codePromo, ':s' => $c['sub']]
    );

    Http::json(['avis' => $avis]);
}

/** Enregistre (ou met à jour) l'avis du stagiaire connecté pour cette promo. */
function route_avis_soumettre(string $codePromo): void
{
    $c = Jeton::stagiaire();
    $i = inscription($c['sub'], $codePromo);
    unset($i);

    $corps = Http::corps();
    $criteres = ['critContenu' => 'PA_CritContenu', 'critFormateur' => 'PA_CritFormateur',
                 'critOrganisation' => 'PA_CritOrganisation', 'critRecommandation' => 'PA_CritRecommandation'];
    $valeurs = [];
    foreach ($criteres as $cle => $colonne) {
        $v = $corps[$cle] ?? null;
        if (!is_int($v) || $v < 1 || $v > 5) Http::erreur(400, "Note invalide pour \"$cle\" (1 à 5 attendu).");
        $valeurs[$colonne] = $v;
    }
    $commentaire = trim((string)($corps['commentaire'] ?? ''));
    if (strlen($commentaire) > 4000) Http::erreur(400, 'Commentaire trop long (4000 caractères max).');

    Db::exec(
        'INSERT INTO "T_Stagiaire_Avis"
            ("PA_CodePromo", "PA_CodeStagiaire", "PA_CritContenu", "PA_CritFormateur",
             "PA_CritOrganisation", "PA_CritRecommandation", "PA_Commentaire", "PA_DateModif")
         VALUES (:p, :s, :c1, :c2, :c3, :c4, :com, now())
         ON CONFLICT ("PA_CodePromo", "PA_CodeStagiaire") DO UPDATE SET
            "PA_CritContenu" = :c1, "PA_CritFormateur" = :c2, "PA_CritOrganisation" = :c3,
            "PA_CritRecommandation" = :c4, "PA_Commentaire" = :com, "PA_DateModif" = now()',
        [
            ':p' => $codePromo, ':s' => $c['sub'],
            ':c1' => $valeurs['PA_CritContenu'], ':c2' => $valeurs['PA_CritFormateur'],
            ':c3' => $valeurs['PA_CritOrganisation'], ':c4' => $valeurs['PA_CritRecommandation'],
            ':com' => $commentaire,
        ]
    );

    Http::json(['ok' => true]);
}
