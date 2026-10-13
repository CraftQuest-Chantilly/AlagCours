# Suivi — alcours (portail stagiaire + formateur)
Ordre validé par Jerome le 13/10/2026 : Lot 0 → Lot 2 → Lot 3 → Lot 1 (sécurité en dernier). Supabase actuel = base de TEST ; la production sera migrée ensuite.
## Lot 0 — Stabiliser — VALIDÉ par Jerome le 13/10/2026 (réserve : tests côté stagiaire non faits : téléchargement, évaluation, réponse libre quizz)
- [x] Cause racine identifiée : colonnes de clé/numérotation perdues au réimport
- [x] Code : MAX+1 sur T_Fichier, TS_Download, T_Quizz_Eval_Rep, T_Stagiaire_QuestionRep, TS_LOG
- [x] Base : FicDroi_Id restauré (script exécuté par Jerome) ; relations atelier créées
- [x] Contrôle base : seules 5 colonnes d'ID sans défaut subsistent, toutes couvertes par MAX+1 (13/10)
- [x] outils/verif-schema.sql (à rejouer après réimport) ; outils/test-ecritures.sql (écritures annulées)
- [x] api/ poussé ; ajout dossier/fichier et droits confirmés par Jerome ; ajout : création de dossier à la racine (13/10)
- [ ] RÉSERVE : téléchargement, évaluation, réponse libre quizz côté stagiaire à tester
- [x] outils/test-ecritures.sql exécuté : « TEST OK (annulé) »
## Lot 2 — Refonte UI (étapes 3, 4, 5) — EN COURS : étape 3 (connexion) VALIDÉE 13/10/2026 ; étape 4 (pages stagiaire) VALIDÉE 13/10/2026 ; étape 5 (pages formateur) VALIDÉE 13/10/2026 → LOT 2 TERMINÉ
## Lot 2 bis — Correction & stats Atelier SQL — VALIDÉ 13/10/2026 (A base+API, B écran formateur, C écran stagiaire ; à tester en réel sur OVH)
## Lot 3 — Finitions — EN COURS (13/10/2026)
- [x] README réécrit selon l'architecture réelle (Supabase via PostgREST, OVH sans pdo_pgsql/curl)
- [ ] Supprimer api/diag.php (dépôt local + OVH : fuite d'infos serveur, versionné)
- [ ] Confirmer étapes 11/12 en production ; sémantique de la 3e moyenne (étape 12)
- [ ] Confirmer la valeur réelle de signatures_racine ; bucket Storage « signatures » inutilisé (suppression sur accord)
- [ ] Tests E2E rejouables (outils/test-e2e.py à mettre à jour)
## Lot 1 — Sécurité (en dernier) — à venir : RPC anon, RLS T_SqlAtelier_*, search_path, hachage mots de passe
## Risques acceptés / en suspens
- MAX+1 : doublon possible en cas d'ajouts simultanés (accepté, 13/10)
- Dev local → API de production (.env.local)
