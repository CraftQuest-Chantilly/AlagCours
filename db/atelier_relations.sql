-- Atelier SQL — clés primaires + relations (FK) d'une base, pour le diagramme de l'entraînement libre.
-- Fonction NOUVELLE et additive : sc_sql_atelier_schema n'est pas modifiée. Rejouable.
create or replace function public.sc_sql_atelier_relations(p_base text)
 returns jsonb
 language plpgsql
 security definer
 set search_path to 'public'
as $function$
declare
    v_schema text;
begin
    select "SAB_Schema" into v_schema from "T_SqlAtelier_Base" where "SAB_Code" = p_base and "SAB_Actif";
    if v_schema is null then
        raise exception 'Base inconnue ou inactive.';
    end if;

    return jsonb_build_object(
      'cles', coalesce((
        select jsonb_agg(jsonb_build_object('table', r.relname, 'colonne', a.attname) order by r.relname, a.attnum)
          from pg_constraint k
          join pg_class r on r.oid = k.conrelid
          join pg_namespace n on n.oid = r.relnamespace
          join pg_attribute a on a.attrelid = k.conrelid and a.attnum = any (k.conkey)
         where k.contype = 'p' and n.nspname = v_schema), '[]'::jsonb),
      'relations', coalesce((
        select jsonb_agg(jsonb_build_object('table', r.relname, 'colonne', a.attname,
                                            'table_ref', rr.relname, 'colonne_ref', ar.attname)
                         order by r.relname, a.attname)
          from pg_constraint k
          join pg_class r on r.oid = k.conrelid
          join pg_class rr on rr.oid = k.confrelid
          join pg_namespace n on n.oid = r.relnamespace
          cross join lateral unnest(k.conkey, k.confkey) as u(att, fatt)
          join pg_attribute a on a.attrelid = k.conrelid and a.attnum = u.att
          join pg_attribute ar on ar.attrelid = k.confrelid and ar.attnum = u.fatt
         where k.contype = 'f' and n.nspname = v_schema), '[]'::jsonb)
    );
end;
$function$;
