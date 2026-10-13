-- Lot 2 bis, sous-étape A — Atelier SQL : note formateur, bonus, points ajoutés, promo de référence, stats.
-- Additif et rejouable. À exécuter dans le SQL Editor Supabase (base de test, puis prod le moment venu).

-- 1) Note du formateur (0 / 0,5 / 1, NULL = non noté) et bonus sur chaque tentative.
alter table "T_SqlAtelier_Consigne_Tentative"
  add column if not exists "SACT_Note"     numeric(2,1) check ("SACT_Note" in (0, 0.5, 1)),
  add column if not exists "SACT_Bonus"    boolean not null default false,
  add column if not exists "SACT_NoteePar" bigint,
  add column if not exists "SACT_NoteeLe"  timestamptz;

-- 2) Points ajoutés à la main sur la note finale (par promo et stagiaire).
create table if not exists "T_SqlAtelier_Points_Ajoutes" (
  "SAPA_CodePromo"     bigint not null,
  "SAPA_CodeStagiaire" bigint not null,
  "SAPA_Points"        numeric(5,2) not null,
  "SAPA_Par"           bigint,
  "SAPA_Le"            timestamptz not null default now(),
  primary key ("SAPA_CodePromo", "SAPA_CodeStagiaire")
);

-- 3) Promo de référence d'une promo (pour la comparaison « promo préc »).
create table if not exists "T_SqlAtelier_Promo_Ref" (
  "SAPRf_CodePromo"    bigint primary key,
  "SAPRf_CodePromoRef" bigint not null check ("SAPRf_CodePromoRef" <> "SAPRf_CodePromo")
);

-- RLS activée sans policy : seuls l'API (service_role) y accède.
alter table "T_SqlAtelier_Points_Ajoutes" enable row level security;
alter table "T_SqlAtelier_Promo_Ref" enable row level security;

-- 4) Stats. Périmètre = tentatives des stagiaires membres actifs de la promo (ou, sans promo, de
--    toutes les promos ayant l'option atelier_sql), éventuellement un seul stagiaire, entre deux dates.
--    Groupe = PromSta_Groupe (texte libre ; vide ou « - » → « (sans groupe) »).
create or replace function public.sc_sql_stats(
  p_promo bigint default null, p_stagiaire bigint default null,
  p_du timestamptz default null, p_au timestamptz default null)
 returns jsonb
 language sql
 stable
 security definer
 set search_path to 'public'
as $function$
  with m as (
    select ps."PromSta_CodeStagiaire" as stag,
           case when trim(coalesce(ps."PromSta_Groupe", '')) in ('', '-') then '(sans groupe)'
                else trim(ps."PromSta_Groupe") end as groupe
      from "T_Promo_Stagiaire" ps
     where ps."PromSta_Actif"
       and (case when p_promo is null
                 then exists (select 1 from "T_Promo_Option_Reel" o
                               where o."PORe_CodePromo" = ps."PromSta_CodePromo" and o."PORe_CodeOption" = 'atelier_sql')
                 else ps."PromSta_CodePromo" = p_promo end)
       and (p_stagiaire is null or ps."PromSta_CodeStagiaire" = p_stagiaire)
  ), mg as (  -- un stagiaire = un seul groupe, évite de compter ses tentatives deux fois
    select stag, min(groupe) as groupe from m group by stag
  ), t as (
    select t."SACT_Correcte" as ok, t."SACT_Note" as note, t."SACT_Bonus" as bonus,
           t."SACT_CodeStagiaire" as stag, co."SAC_Notee" as notee, mg.groupe
      from "T_SqlAtelier_Consigne_Tentative" t
      join "T_SqlAtelier_Consigne" co on co."SAC_NumConsigne" = t."SACT_NumConsigne"
      join mg on mg.stag = t."SACT_CodeStagiaire"
     where (p_du is null or t."SACT_Date" >= p_du) and (p_au is null or t."SACT_Date" < p_au)
  )
  select jsonb_build_object(
    'essaisTotal', (select count(*) from t),
    'nonNotes', jsonb_build_object('ok', (select count(*) from t where not notee and ok), 'ko', (select count(*) from t where not notee and not ok)),
    'notes',    jsonb_build_object('ok', (select count(*) from t where notee and ok),     'ko', (select count(*) from t where notee and not ok)),
    'valides',  jsonb_build_object('rouge', (select count(*) from t where note = 0), 'vert', (select count(*) from t where note > 0)),
    'bonus',    jsonb_build_object('tentatives', (select count(*) from t where bonus), 'stagiaires', (select count(distinct stag) from t where bonus)),
    'nbStagiaires', (select count(*) from mg),
    'parGroupe', coalesce((select jsonb_agg(jsonb_build_object('groupe', groupe, 'ok', ok, 'ko', ko, 'bonus', bonus) order by groupe)
        from (select groupe, count(*) filter (where ok) ok, count(*) filter (where not ok) ko, count(*) filter (where bonus) bonus
                from t group by groupe) g), '[]'::jsonb)
  );
$function$;

-- Réservée au service_role (API PHP) : pas d'accès anon / authenticated.
revoke all on function public.sc_sql_stats(bigint, bigint, timestamptz, timestamptz) from public, anon, authenticated;
grant execute on function public.sc_sql_stats(bigint, bigint, timestamptz, timestamptz) to service_role;

notify pgrst, 'reload schema';
