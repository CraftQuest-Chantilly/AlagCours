# Détruire / reconstruire les tables : ce qu'il faut refaire

Base : projet Supabase `zykgfhahyuckuhohhmcz`, schéma `public` (état relevé le 26/10/2026).
Le principe : **`DROP TABLE` emporte les index, contraintes, triggers, policies et valeurs par défaut de la table. Il n'emporte ni les fonctions, ni les séquences indépendantes, ni les types, ni le bucket storage.** Avec `CASCADE`, il supprime aussi, sans prévenir, les vues et les FK qui pointent dessus.

## 0. À faire AVANT de détruire (export des définitions)

Rien de ce qui suit n'est récupérable une fois les tables supprimées. Exporte (résultat → fichiers `.sql` à garder) :

```sql
-- index (568 secondaires)
select indexdef||';' from pg_indexes where schemaname='public' and indexname not in
  (select conname from pg_constraint where contype in ('p','u'));
-- contraintes FK / UNIQUE / CHECK
select 'ALTER TABLE '||conrelid::regclass||' ADD CONSTRAINT "'||conname||'" '||pg_get_constraintdef(oid)||';'
  from pg_constraint where connamespace='public'::regnamespace and contype in ('f','u','c');
-- vues
select 'CREATE OR REPLACE VIEW public."'||viewname||'" AS '||definition from pg_views where schemaname='public';
-- fonctions
select pg_get_functiondef(p.oid)||';' from pg_proc p where pronamespace='public'::regnamespace and prokind='f';
-- policies
select format('CREATE POLICY %I ON %I.%I AS %s FOR %s TO %s USING (%s) WITH CHECK (%s);',
  policyname,schemaname,tablename,permissive,cmd,array_to_string(roles,','),coalesce(qual,'true'),coalesce(with_check,'true'))
  from pg_policies where schemaname='public';
-- triggers
select pg_get_triggerdef(oid)||';' from pg_trigger where not tgisinternal and tgrelid::regclass::text !~ '^(auth|storage)';
-- séquences : valeur courante
select schemaname, sequencename, last_value from pg_sequences where schemaname='public';
-- droits
select * from information_schema.role_table_grants where table_schema='public' and grantee in ('anon','authenticated','service_role');
```
Ou plus simple et plus sûr : `pg_dump --schema-only --schema=public` (CLI Supabase : `supabase db dump`).

## 1. Clés primaires, identity, séquences, valeurs par défaut

| Élément | Détail |
|---|---|
| PK | 99 PK pour 100 tables : **une table n'a pas de PK** (à identifier avant : `select relname from pg_class c where relkind='r' and relnamespace='public'::regnamespace and not exists (select 1 from pg_constraint where conrelid=c.oid and contype='p')`) |
| Colonnes `IDENTITY` (14) | `T_Connexion_Tentative.id`, `T_Cours_Points_Vus.CPV_Id`, `T_Fichier_Droits.FicDroi_Id`, `T_SqlAtelier_Consigne.SAC_NumConsigne`, `…_Consigne_Tentative.SACT_Id`, `…_Execution_Log.SAEL_Id`, `…_Message.SAM_Id`, `…_Partiel.SAP_NumPartiel`, `…_Partiel_Question.SAPQ_NumQuestion`, `…_Partiel_Reponse.SAPR_NumReponse`, `cms_blocks.id`, `course_details.id`, `reviews.id`, `trainers.id` |
| Séquences + `DEFAULT nextval` (6) | `seq_t_cours_cours_numcours` → `T_Cours.Cours_NumCours` ; `seq_t_projet_pr_codeappli` → `T_Projet.Pr_CodeAppli` ; `seq_t_promo_pro_codepromo` → `T_Promo.Pro_CodePromo` ; `seq_t_promo_stagiaire_promsta_codepromostagiaire` → `T_Promo_Stagiaire.PromSta_CodePromoStagiaire` ; `seq_t_stagiaire_stag_codestagiaire` → `T_Stagiaire.Stag_CodeStagiaire` ; `seq_t_stagiaire_note_sn_numnote` → `T_Stagiaire_Note.SN_NumNote` |
| Après rechargement des données | **Recaler chaque séquence/identity** : `select setval(pg_get_serial_sequence('"T"','"col"'), max("col")) from "T"` (identity : `ALTER TABLE … ALTER COLUMN … RESTART WITH max+1`). Sinon : doublons de clé au prochain insert. |
| Défauts hors séquences (≈45) | `now()` sur les colonnes date (`SACT_Date`, `SAM_Date`, `CPV_DateCoche`, `CS_DateSignature`, `PA_DateModif`, `created_at`, `updated_at`, `ts`…), booléens (`Forma_EstAdmin=false`, `Forma_AccesGenerationCours=false`, `SAC_Notee=false`, `SAC_Visible=true`, `SAB_Actif=true`, `SACT_Bonus=false`, `SACT_Vu=false`, `is_active`, `is_visible`…), numériques (`SAP_TentativesMax=5`, `SAPQ_Bareme=1`, `SAPQ_Ordre=0`, `PNS_Ordre=0`, `order_index=0`, `version=1`), texte (`SAB_Description=''`, `PA_Commentaire=''`, `reviews.type='avis'::review_type`) |

**Rappel ré-import précédent :** un import (WebDev → Postgres) avait déjà perdu identity/défauts sur 5 colonnes (contournées par `MAX+1` dans le code PHP) et les types booléens. Si tu reconstruis par import, le même risque revient : rejoue `db/restaurer_ficdroi_id.sql` et `remise_en_boolean.sql`, puis contrôle avec `outils/verif-schema.sql` (0 ligne = OK).

## 2. Contraintes

- **Clés étrangères (23)** : à recréer **après** le chargement des données (sinon ordre parent → enfant), avec `NOT VALID` puis `VALIDATE CONSTRAINT` si des orphelins existent.
  - Cours/notation : `T_Cours_Notation_Stagiaire→T_Promo_Notation_Stagiaire` ; `T_Promo_Notation_Stagiaire_Reel→T_Promo_Notation_Stagiaire`
  - Docs/exams : `T_Doc_Exo_Point→T_Doc_Exo` ; `T_Exam→T_Promo`
  - Projets : `T_Projet→T_Client` ; `T_Projet→T_TypeProjet` ; `T_Projet_Heures→T_Projet` ; `T_Projet_Heures_Intervenant→T_Projet_Heures`
  - Promos : `T_Promo_Stagiaire→T_Promo` ; `T_QuizzPromo→T_Promo` ; `T_Promo_Option_Reel→T_Promo_Option`
  - Quizz : `T_Stagiaire_QuestionRep→T_QuestionRep` ; `T_Stagiaire_Quizz_Questions→T_Question`
  - Atelier SQL : `Consigne→Base` ; `Consigne_Tentative→Consigne` ; `Partiel→Base` ; `Partiel_Question→Partiel` ; `Partiel_Reponse→Partiel_Question`
  - Site vitrine : `app_profiles→auth.users` ; `cms_blocks→courses` ; `course_details→courses` ; `reviews→courses` ; `reviews→trainers`
  - Noms exacts : voir export §0.
- **UNIQUE (3)** : `uq_Cours_Points_Vus` (T_Cours_Points_Vus), `T_SqlAtelier_Base_SAB_Schema_key`, `reviews_legacy_id_key`. À noter : `ON CONFLICT` du code s'appuie sur les uniques/PK, ils doivent exister sous la même forme.
- **CHECK (12)** : `CNS_Etoiles` 0–4 ; `SACT_Note` ∈ {0, 0.5, 1} ; `SAM_TypeExpediteur` ∈ {stagiaire, formateur} ; `SAP_Fin > SAP_Debut` ; `SAPRf_CodePromoRef <> SAPRf_CodePromo` ; 4 notes `PA_Crit*` 1–5 (T_Stagiaire_Avis) ; `app_profiles.role` ∈ {admin, formateur, stagiaire} ; `reviews.email` (regex) ; `reviews.stars` 1–5.
- **Non couvert par les FK (donc rien à reconstruire, mais à savoir)** : la plupart des liens métier (stagiaire ↔ promo ↔ cours…) ne sont pas des FK en base ; `T_Stagiaire.auth_user_id` n'a pas de FK vers `auth.users`.

## 3. Index

568 index secondaires, tous perdus avec la table. Sans eux : fonctionnement correct mais requêtes lentes (notamment `T_Stagiaire`, `T_Promo_Stagiaire`, `T_Cours`, `T_Cours_Points_Vus`). Recréer depuis l'export §0 (`CREATE INDEX …`), idéalement **après** le chargement des données (plus rapide).

## 4. Vues (7) — emportées par `DROP … CASCADE`, bloquent un `DROP` simple

| Vue | Tables dont elle dépend |
|---|---|
| `v_sc_cours` | T_Cours, T_Formateur, T_Promo |
| `v_sc_promo_liste` | T_Promo |
| `v_sc_promo_stagiaire` | T_Promo_Stagiaire, T_Stagiaire |
| `v_sc_formateur_public` | T_Formateur |
| `v_sc_client_liste` | T_Client |
| `v_sc_projet_a_facturer` | T_Client, T_Projet_Heures_Intervenant, T_Projet_Heures, T_Projet |
| `course_satisfaction` | courses, reviews (+ **une dépendance OID 19328 non résolue** : anomalie à examiner dans l'export) |

Les vues doivent être recréées **après** les tables et **avant** de recharger les droits. `course_satisfaction` est `SECURITY DEFINER` (signalé par les advisors Supabase) : à profiter de la reconstruction pour la passer en `security_invoker = true` (sujet Lot 1).

## 5. Fonctions / RPC (46) — pas supprimées, mais peuvent casser

- Les fonctions **plpgsql** ne déclarent aucune dépendance de table : elles survivent au `DROP`, et ne cassent qu'à l'exécution si une colonne/table manque ou a changé de nom/type.
- Les fonctions **LANGUAGE sql** peuvent, selon la version, être validées à la création : si tu repars d'une base vide, **recrée les tables avant les fonctions**.
- Si tu dropes aussi les fonctions : recréer (export §0) dans l'ordre : `_sc_base64url`, `set_updated_at`, puis le reste.
- À tester absolument après reconstruction (utilisées par le PHP / les stagiaires) :
  - API PHP : `exec_sql`, `exec_write` (service_role uniquement — **ne pas ouvrir à anon**)
  - Auth / droits : `sc_generer_jwt`, `sc_connexion_formateur`, `sc_definir_password_formateur`, `sc_verifier_password_formateur`, `sc_role`, `sc_is_admin`, `sc_is_staff`, `sc_assert_staff`, `sc_my_formateur`, `sc_my_stagiaire`, `sc_my_promos`
  - Stagiaire : `sc_stag_fichiers`, `sc_stag_points_vus`, `sc_stag_quizz_liste`, `sc_stag_quizz_questions`, `sc_stag_repondre`, `sc_stag_log_download`
  - Atelier SQL : `sc_sql_atelier_executer`, `_executer_interne`, `_comparer_interne`, `_corriger`, `_schema`, `_relations`, `sc_sql_stats`
  - Métier : `sc_attribuer_quizz`, `sc_cours_duree`, `sc_heures`, `sc_max_session`, `sc_now_local`, `sc_quizz_note`, `sc_exam_*`, `sc_doc_exo_import_points`, `sc_download_suppr_mois`, `sc_projet_fin_fiche`, `sc_suivi_cours_pas_fini`, `sc_verif_*`, `sc_copy_adr_client_dans_cours`
- **Incohérence relevée** : `sc_tr_cours_modif` est une fonction trigger, mais **aucun trigger ne l'utilise** actuellement. Soit un trigger manquait déjà (perdu lors d'un import précédent), soit la fonction est morte. À trancher avant de reconstruire.
- Pensez à `NOTIFY pgrst, 'reload schema';` à la fin (PostgREST ne voit pas sinon les nouvelles fonctions/colonnes).

## 6. RLS et policies

- **RLS activée sur 95 tables** : l'activation (`ALTER TABLE … ENABLE ROW LEVEL SECURITY`) est un attribut de la table → **à refaire sur chaque table recréée**, sinon la table devient lisible par `anon` via l'API REST.
- **68 policies** (emportées avec les tables). Répartition (nb de policies) : 1 sur la plupart des tables métier (T_Client, T_Cours, T_Promo, T_Stagiaire, T_Formateur, T_Projet…), 2 sur `T_Cours_Points_Vus`, `T_Cours_Signature`, `T_Cours_Signature_Deblocage`, `T_Promo_Notation_Stagiaire(_Reel)`, `T_Promo_Option(_Reel)`, `T_Promo_Quizz_Eval`, `T_Promo_Signature_Deblocage`, `T_SqlAtelier_Base/Partiel/Partiel_Question/Partiel_Reponse`, `T_Stagiaire_Avis`, `app_profiles`, `reviews`.
- **~49 tables avec RLS mais sans policy** (accès refusé à anon/authenticated, seul `service_role` passe — c'est ainsi que le PHP fonctionne) : toutes les `TT_*`, `TS_LOG/Param/SQL`, `T_Fichier`, `T_Fichier_Droits`, `T_Connexion_Tentative`, `T_SqlAtelier_Points_Ajoutes`, `T_SqlAtelier_Promo_Ref`, `T_Item`, `T_Exo_Item`, `T_Exercice(_Cours)`, `T_Cours_Maitre`, `T_Quizz_Eval_Rep`, `T_VisuModifFic`, `T_CorrespondancePromoSite`, `c_tracking_event`, `cl_user`, `exb_exambarem`, `t_mailenvoi`, `t_projet_*`, `t_rdv`, `trainers`, `ts_logmodif(_ps)`. **Oublier le `ENABLE RLS` sur celles-là les rend publiques** : c'est le point le plus dangereux de la reconstruction.
- Les policies appellent `sc_is_staff()`, `sc_is_admin()`, `sc_my_*` : ces fonctions doivent exister avant de recréer les policies.

## 7. Triggers (2)

- `trg_cms_blocks_updated_at` sur `cms_blocks` et `trg_courses_updated_at` sur `courses`, tous deux via `set_updated_at()`. À recréer (la fonction survit).

## 8. Droits (GRANT / REVOKE)

- Une table créée via le SQL editor / migration reçoit les droits par défaut Supabase (`anon`, `authenticated`, `service_role`). Les **REVOKE personnalisés** éventuels sont perdus → exporter `role_table_grants` (§0) et comparer après.
- Fonctions : par défaut `EXECUTE` à `PUBLIC` (donc `anon`). Les `REVOKE EXECUTE … FROM anon` éventuels sont à rejouer (connu : `sc_connexion_formateur` et RPC atelier sont encore exposées à `anon`, traité au Lot 1).
- Si tu dropes et recrées les fonctions, les `GRANT EXECUTE` sont perdus aussi.

## 9. Ce qui n'est PAS supprimé par un `DROP TABLE` (mais à vérifier)

- Type enum `review_type` (utilisé par `reviews.type`) : à recréer seulement si tu le dropes.
- Séquences `seq_t_*` (indépendantes, valeurs conservées → à recaler après rechargement).
- Bucket Storage `signatures` (0 objet) et ses policies sur `storage.objects`.
- Utilisateurs `auth.users` ; la colonne `T_Stagiaire.auth_user_id` référence leurs id (0 compte lié sur 12 250 à ce jour).
- Extensions, rôles, `exec_sql`/`exec_write`.

## 10. Éléments ajoutés récemment (à ne pas oublier dans la reconstruction)

Colonnes `SACT_Note`, `SACT_Bonus`, `SACT_NoteePar`, `SACT_NoteeLe` (T_SqlAtelier_Consigne_Tentative) ; tables `T_SqlAtelier_Points_Ajoutes`, `T_SqlAtelier_Promo_Ref`, `T_Promo_Option`, `T_Promo_Option_Reel` ; fonctions `sc_sql_atelier_corriger/_schema/_relations`, `sc_sql_stats`. Si tu repars d'un export plus ancien, rejoue : `db/atelier_relations.sql`, `db/stats_sql.sql`.

## 11. Ordre de reconstruction recommandé

1. Types (`review_type`) et séquences
2. Tables (colonnes, types booléens, PK, identity, défauts) — sans FK
3. Chargement des données
4. Recalage des séquences / identity (`setval`)
5. FK, UNIQUE, CHECK (`NOT VALID` puis `VALIDATE` si orphelins)
6. Index
7. Fonctions (si droppées), puis vues
8. Triggers
9. `ENABLE ROW LEVEL SECURITY` sur **toutes** les tables, puis policies
10. GRANT / REVOKE
11. Scripts additifs (`db/restaurer_ficdroi_id.sql`, `db/atelier_relations.sql`, `db/stats_sql.sql`)
12. `NOTIFY pgrst, 'reload schema';`
13. Contrôles : `outils/verif-schema.sql` (0 ligne), `outils/test-ecritures.sql`, `outils/test-stats.sql`, `get_advisors` (sécurité), `outils/test-e2e.py`

## 12. Pièges

- `DROP TABLE … CASCADE` supprime en silence vues, FK entrantes et policies dépendantes : privilégier un `DROP` simple pour que Postgres signale les dépendances.
- Reconstruire depuis un import (type WebDev) **redonne** les symptômes déjà vus : identity/défauts perdus, booléens en entier/texte, `FicDroi_Id`.
- Les noms de tables/colonnes sont sensibles à la casse et accentués (`T_Client_Propriétés`, `T_Propriétés_Client`) : toujours entre guillemets.
- La base actuelle est la base de TEST ; ne rien appliquer en production sans feu vert explicite.
