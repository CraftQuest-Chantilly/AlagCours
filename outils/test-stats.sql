-- Test de sc_sql_stats avec des données fictives, TOUT EST ANNULÉ (le DO se termine par une exception).
-- Prérequis : db/stats_sql.sql exécuté. Résultat attendu : « TEST OK (annulé) ».
do $$
declare
  v_promo bigint := 56857945295554663;   -- promo de test avec option atelier_sql (42 membres)
  v_s1 bigint; v_s2 bigint; v_c_notee bigint; v_c_libre bigint; r jsonb; r1 jsonb;
begin
  select "PromSta_CodeStagiaire" into v_s1 from "T_Promo_Stagiaire" where "PromSta_CodePromo" = v_promo and "PromSta_Actif" order by 1 limit 1;
  select "PromSta_CodeStagiaire" into v_s2 from "T_Promo_Stagiaire" where "PromSta_CodePromo" = v_promo and "PromSta_Actif" order by 1 offset 1 limit 1;
  insert into "T_SqlAtelier_Consigne" ("SAC_CodeBase","SAC_Ordre","SAC_Consigne","SAC_Solution","SAC_Notee","SAC_NivDifficulte")
    select "SAB_Code",'t1','TEST notée','select 1',true,1 from "T_SqlAtelier_Base" limit 1 returning "SAC_NumConsigne" into v_c_notee;
  insert into "T_SqlAtelier_Consigne" ("SAC_CodeBase","SAC_Ordre","SAC_Consigne","SAC_Solution","SAC_Notee","SAC_NivDifficulte")
    select "SAB_Code",'t2','TEST libre','select 1',false,1 from "T_SqlAtelier_Base" limit 1 returning "SAC_NumConsigne" into v_c_libre;
  -- s1 : 2 notées (1 OK note 1 + bonus, 1 KO note 0), 1 libre OK ; s2 : 1 notée OK note 0,5
  insert into "T_SqlAtelier_Consigne_Tentative" ("SACT_NumConsigne","SACT_CodeStagiaire","SACT_Requete","SACT_Correcte","SACT_Note","SACT_Bonus") values
    (v_c_notee, v_s1, 'x', true, 1, true), (v_c_notee, v_s1, 'x', false, 0, false), (v_c_libre, v_s1, 'x', true, null, false),
    (v_c_notee, v_s2, 'x', true, 0.5, false);
  r := sc_sql_stats(v_promo, null, null, null);
  r1 := sc_sql_stats(v_promo, v_s1, null, null);
  if (r->>'essaisTotal')::int <> 4 then raise exception 'FAIL essaisTotal promo=%', r; end if;
  if (r->'notes'->>'ok')::int <> 2 or (r->'notes'->>'ko')::int <> 1 then raise exception 'FAIL notes %', r; end if;
  if (r->'nonNotes'->>'ok')::int <> 1 then raise exception 'FAIL nonNotes %', r; end if;
  if (r->'valides'->>'vert')::int <> 2 or (r->'valides'->>'rouge')::int <> 1 then raise exception 'FAIL valides %', r; end if;
  if (r->'bonus'->>'tentatives')::int <> 1 or (r->'bonus'->>'stagiaires')::int <> 1 then raise exception 'FAIL bonus %', r; end if;
  if (r1->>'essaisTotal')::int <> 3 or (r1->>'nbStagiaires')::int <> 1 then raise exception 'FAIL stagiaire %', r1; end if;
  if jsonb_array_length(r->'parGroupe') < 1 then raise exception 'FAIL parGroupe %', r; end if;
  raise exception 'TEST OK (annulé) : %', r;
end $$;
