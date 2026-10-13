-- À rejouer après chaque réimport de la base (SQL Editor Supabase). Aucune écriture.
-- Résultat : 0 ligne = tout est conforme ; sinon chaque ligne est un écart à corriger.
WITH attendu(tbl, col, typ) AS (VALUES
  ('T_Fichier_Droits','FicDroi_Id','bigint'),
  ('T_Fichier_Droits','FicDroi_Visible','boolean'),
  ('T_Fichier_Droits','FicDroi_Fait','boolean'),
  ('T_Fichier','Fic_InvisibleDeBase','boolean'),
  ('TS_LOG','LOG_Out','boolean'),
  ('T_Formateur','Forma_Actif','boolean'),
  ('T_Promo_Stagiaire','PromSta_Actif','boolean'),
  ('T_Fichier','Fic_NumFic','bigint'),
  ('TS_LOG','LOG_IdLOG','bigint'),
  ('TS_Download','Dow_IdLOG','bigint'),
  ('T_Quizz_Eval_Rep','QER_Quizz_Eval_Rep','bigint'),
  ('T_Stagiaire_QuestionRep','SQR_CodeStagQuestR','bigint')
)
SELECT a.tbl, a.col, a.typ AS type_attendu, c.data_type AS type_reel,
       CASE WHEN c.column_name IS NULL THEN 'colonne absente' ELSE 'type différent' END AS probleme
  FROM attendu a
  LEFT JOIN information_schema.columns c
         ON c.table_schema='public' AND c.table_name=a.tbl AND c.column_name=a.col
 WHERE c.column_name IS NULL OR c.data_type <> a.typ;
-- Rappel : Fic_NumFic, LOG_IdLOG, Dow_IdLOG, QER_Quizz_Eval_Rep, SQR_CodeStagQuestR n'ont plus
-- de numérotation automatique après le réimport : l'API calcule MAX+1 elle-même (voulu).
