# Rapport de reprise — alcours (portail stagiaire + formateur) — 2026-10-13

## 1. Synthèse — verdict : FRAGILE (à consolider avant d'avancer)
Les fonctions existent et ont été testées au fil des étapes, mais la base a été réimportée deux fois et dérive du code (clés / numérotations perdues). Quatre pannes en 3 jours (connexion, ajout de fichier, droits de fichier, 500 en local) ont la même cause. Il n'y a ni environnement de test, ni suite de tests automatiques, ni déploiement outillé.
Trois points clés :
1. Cause racine = réimport de la base : perte des colonnes d'identifiant/numérotation auto. Corrigé dans le code (MAX+1) ou en base, mais rien ne protège d'un prochain réimport.
2. Pas d'environnement de test : le dev local (`.env.local`) parle à l'API de PRODUCTION, donc à la base de production.
3. Sécurité : pas de faille critique constatée côté portail ; points à traiter listés au §6.

## 2. Ce que fait le projet aujourd'hui
- Connexion stagiaire / formateur (JWT maison), journal des connexions, changement de mot de passe.
- Stagiaire : formations, agenda, ressources (fichiers + points vus), quizz, avis, signature de présence, évaluation de formation, atelier SQL (entraînement libre, consignes, partiels).
- Formateur : gestion des fichiers et droits par formation, atelier SQL (consignes, partiels, suivi en direct, entraînement), journal.
- Refonte graphique : étapes 1–2 faites (charte, sidebar) ; connexion et pages stagiaire/formateur restent à refaire.

## 3. Stack et conventions
PHP 7.4+/8 (`api/index.php?route=`, routes en tableau, classes Db/Http/Jeton) sur OVH mutualisé → Supabase PostgREST via RPC `exec_sql` / `exec_write`. Front Vue 3 `<script setup>` + Pinia + Router + Tailwind 3.4 + Vite 5. Déploiement manuel (`dist` + `api` poussés par Jerome).

## 4. Avancement (preuves)
| Fonction | État | Preuve / remarque |
|---|---|---|
| Connexion + journal | fait | corrigé le 11/10 après réimport (booleans, LOG_IdLOG) |
| Ajout fichier / dossier | corrigé, À CONFIRMER en prod | Fic_NumFic + FicDroi_Id restaurés ; retest attendu |
| Droits fichier (modif/suppr/coche) | corrigé, À CONFIRMER | FicDroi_Id présent en base (constaté) |
| Journal téléchargements, évaluation, réponse libre quizz | corrigé dans le code, NON TESTÉ en prod | MAX+1 sur 3 tables |
| Entraînement libre (nouvel écran) | fait, testé en local | diagramme actif (fonction relations existe en base) |
| Refonte UI | partiel (2/5 étapes) | |
| Étapes 11/12 (évaluation), signature | à confirmer en prod | reportés |

## 5. Qualité
- Tests : un script E2E (`outils/test-e2e.py`) ; aucun test unitaire, pas de CI.
- Git : un seul commit initial côté Jerome ; le bac à sable n'est pas un dépôt git → pas d'historique des corrections récentes.
- Dette : numérotation par `MAX+1` (5 tables : doublon possible si deux ajouts simultanés) ; `api/diag.php` à supprimer sur OVH ; README à nettoyer ; bucket Storage `signatures` inutilisé ; 20 tables `TT_*` résiduelles.
- Constaté : les 231 identifiants de colonnes/tables cités dans l'API existent tous dans la base (comparaison des noms ; les TYPES n'ont pas été comparés).

## 6. Sécurité
| Gravité | Constat | État |
|---|---|---|
| Moyenne | `sc_connexion_formateur` exécutable par `anon` via /rest/v1/rpc : contourne le limiteur de tentatives de l'API PHP (force brute possible) | constaté (advisor Supabase) |
| Moyenne | Fonctions atelier SQL (`executer`, `corriger`, `schema`, `relations`) exécutables par `anon` : SELECT sur la base d'exercices sans connexion | constaté ; limité à la base bac à sable |
| Moyenne | 5 tables `T_SqlAtelier_*` sans RLS dans le schéma public (dont consignes avec solutions) | constaté ; accès réel dépend des droits anon, à vérifier |
| Basse | 14 fonctions sans `search_path` fixé ; vue `course_satisfaction` en SECURITY DEFINER | constaté (advisor) |
| À vérifier | Mots de passe : stockage en clair ou haché ? (`Forma_PassWord`, `Stag_Mdp`, `Cli_PasswordPC`) | suspecté |
| À vérifier | Clé service Supabase uniquement dans `.env` OVH, jamais dans le front | non audité |
Non audité : XSS/CSRF du front, limitation de débit PHP, sécurité des uploads (type/extension), RGPD (mentions, durée de conservation du journal).

## 7. Bugs probables et risques d'organisation
- (constaté) Un réimport futur recassera la base : aucun script « post-réimport » unique ni test de non-régression de schéma.
- (constaté) Dev local → production : un test peut écrire en base réelle.
- (suspecté) Upload : le fichier est écrit sur disque AVANT l'insertion en base ; si la base échoue, fichier orphelin.
- (suspecté) `MAX+1` + double clic = doublon de clé.
- Pas de sauvegarde/restauration testée.

## 8. Manques par rapport à une check-list classique
Mot de passe oublié stagiaire, environnement de recette, sauvegardes, supervision des erreurs, tests automatisés, RGPD (journal des connexions / téléchargements), accessibilité vérifiée.

## 9. Questions ouvertes pour Jerome
1. Existe-t-il une copie de la base (projet Supabase ou branche) pour tester sans toucher la production ?
2. Prévoyez-vous un nouveau réimport ?
3. Priorité : stabiliser d'abord, ou poursuivre la refonte (connexion → pages) ?
4. Souhaitez-vous un mot de passe oublié / première connexion stagiaire par e-mail ?

## 10. Recommandation : plan de reprise
- Lot 0 — Stabiliser (avant tout) : confirmer en prod ajout fichier / droits / téléchargement / évaluation / quizz ; rétablir les numérotations auto en base (script unique, rejouable) ; script « vérif schéma » (colonnes citées par l'API vs base) ; environnement de test.
- Lot 1 — Sécurité : révoquer `EXECUTE` anon sur les RPC sensibles, activer RLS sur `T_SqlAtelier_*`, fixer `search_path`, vérifier le hachage des mots de passe.
- Lot 2 — Refonte étape 3 (connexion) puis 4 (pages stagiaire) puis 5 (formateur), un lot = une validation.
- Lot 3 — Finitions : étapes 11/12 confirmées, README, suppression `diag.php`, tests E2E rejouables.
