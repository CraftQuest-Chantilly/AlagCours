# Récap — Lot 0 « Stabiliser » (validé le 13/10/2026)
- Cause : réimport de la base → colonnes d'identifiant/numérotation perdues, booleans en entiers.
- Code : numéro calculé (MAX+1) pour T_Fichier, TS_Download, T_Quizz_Eval_Rep, T_Stagiaire_QuestionRep, TS_LOG.
- Base : FicDroi_Id restauré (db/restaurer_ficdroi_id.sql), fonction sc_sql_atelier_relations (db/atelier_relations.sql).
- Outils : outils/verif-schema.sql (à rejouer après réimport), outils/test-ecritures.sql (écritures annulées).
- Ajout : création de dossier à la racine ; refus des dossiers en double.
- Réserve : tests côté stagiaire (téléchargement, évaluation, réponse libre quizz) non faits.
- Signatures : fichiers PNG sur le serveur (signatures_racine), pas en base.
