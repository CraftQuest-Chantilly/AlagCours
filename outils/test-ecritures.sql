-- Teste les écritures de l'API sur la base de test, puis ANNULE tout (rien n'est conservé).
-- Résultat attendu : une erreur « TEST OK (annulé) : ... ». Toute autre erreur = écriture cassée.
DO $$
DECLARE a bigint; b bigint; d bigint; r text := '';
BEGIN
  INSERT INTO "T_Fichier" ("Fic_NumFic","Fic_NomFic","Fic_Chem","Fic_DossierPère","Fic_TypeFouD","Fic_DateCréation","Fic_InvisibleDeBase")
   VALUES ((SELECT COALESCE(MAX("Fic_NumFic"),0)+1 FROM "T_Fichier"), 'QA_dossier','x',NULL,'D',current_date,false) RETURNING "Fic_NumFic" INTO a;
  INSERT INTO "T_Fichier" ("Fic_NumFic","Fic_NomFic","Fic_Chem","Fic_DossierPère","Fic_TypeFouD","Fic_TailleKO","Fic_DateCréation","Fic_InvisibleDeBase")
   VALUES ((SELECT COALESCE(MAX("Fic_NumFic"),0)+1 FROM "T_Fichier"), 'QA.txt','x',a,'F',1,current_date,false) RETURNING "Fic_NumFic" INTO b;
  r := r||'fichiers '||a||'/'||b||'; ';
  INSERT INTO "T_Fichier_Droits" ("FicDroi_NumFic","FicDroi_CodePromo","FicDroi_Groupe","FicDroi_Visible")
   VALUES (b, (SELECT MIN("Pro_CodePromo") FROM "T_Promo"), 'A', true) RETURNING "FicDroi_Id" INTO d;
  UPDATE "T_Fichier_Droits" SET "FicDroi_Fait"=true, "FicDroi_FaitLe"=current_date WHERE "FicDroi_Id"=d;
  DELETE FROM "T_Fichier_Droits" WHERE "FicDroi_Id"=d;
  r := r||'droit '||d||'; ';
  INSERT INTO "TS_Download" ("Dow_IdLOG","Dow_Date","Dow_Heure","Dow_Identifiant","Dow_IP","Dow_Fichier","Dow_CodeUser","Dow_Info","Dow_CodePromo","Dow_Groupe")
   VALUES ((SELECT COALESCE(MAX("Dow_IdLOG"),0)+1 FROM "TS_Download"), current_date, localtime, 'QA','1.1.1.1','QA.txt',1,'QA',1,'A');
  INSERT INTO "T_Quizz_Eval_Rep" ("QER_Quizz_Eval_Rep","QER_CodePromo","QER_CodeStagiaire","QER_CodeQuest","QER_Valeur","QER_ReponseTexte")
   VALUES ((SELECT COALESCE(MAX("QER_Quizz_Eval_Rep"),0)+1 FROM "T_Quizz_Eval_Rep"), 1,1,1,3,'QA');
  INSERT INTO "T_Stagiaire_QuestionRep" ("SQR_CodeStagQuestR","SQR_CodeSQQ","SQR_RepLibre")
   VALUES ((SELECT COALESCE(MAX("SQR_CodeStagQuestR"),0)+1 FROM "T_Stagiaire_QuestionRep"), 1,'QA');
  RAISE EXCEPTION 'TEST OK (annulé) : %', r;
END $$;
