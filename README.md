# alcours — portail stagiaire + espace formateur (alaginfo.net/alcours/)

Remplace les sites WebDev **al-cours** et **SqlRA**. Deux espaces dans une seule appli :
stagiaire (formations, ressources, signature, quizz, évaluation, avis, Atelier SQL, stats) et
formateur (gestion des fichiers et droits, Atelier SQL : consignes, partiels, suivi, correction et stats, journal).

```
front/   Vue 3 + Vite + Tailwind + Pinia (build -> front/dist, publié dans /alcours/)
api/     API PHP (>= 7.4), publiée dans /alcours/api/ ; routage par ?route=/xxx (voir api/index.php)
db/      scripts SQL additifs à exécuter dans Supabase (SQL Editor), rejouables
outils/  verif-schema.sql, test-ecritures.sql, test-stats.sql, test-e2e.py, router.php (serveur PHP de test)
```

## Architecture réelle
- **Base** : Supabase (PostgreSQL). Le navigateur ne parle qu'à l'API PHP, jamais à Supabase.
- **API → base** : l'hébergement OVH mutualisé n'a ni `pdo_pgsql` ni `curl`. `api/lib/Db.php` appelle donc
  l'API REST de Supabase en HTTPS (`file_get_contents`) sur les fonctions `exec_sql` / `exec_write`,
  réservées au rôle `service_role`. Les requêtes SQL des `api/routes/*.php` utilisent des paramètres nommés `:nom`.
- **Serveur** : Windows / IIS, PHP en FastCGI. Pas de module URL Rewrite : le front utilise le fallback
  `httpErrors` (`front/public/web.config`), l'API est appelée en `index.php?route=…`.
- **Session** : jeton signé (HMAC, 8 h) dans `sessionStorage`. Les identifiants (17 chiffres) circulent en chaînes.
- **Fichiers** : ressources sur disque (`fichiers_racine`), signatures de présence en PNG (`signatures_racine`),
  hors racine web.

## Configuration de l'API (`api/config.local.php`, jamais versionné)
Copier `api/config.local.example.php` puis renseigner : `supabase_url`, `supabase_service_role_key`
(clé service_role, jamais la clé publique), `jwt_secret` (64 caractères aléatoires), `fichiers_racine`,
`signatures_racine`. `db_dsn` / `db_user` / `db_password` ne servent qu'à un test local avec `pdo_pgsql`.

## Développement
1. `cd front && npm install && npm run dev` → http://localhost:5175/alcours/
2. `/alcours/api` est relayé vers `VITE_API_PROXY` (défaut http://127.0.0.1:8080). **Attention** : si `front/.env.local`
   contient `VITE_API_PROXY=https://alaginfo.net`, le dev local utilise l'API et la base de PRODUCTION.
3. API locale (facultatif) : `php -S 127.0.0.1:8080 router.php` depuis un dossier contenant `alcours/api` (voir `outils/router.php`).

## Déploiement
1. `cd front && npm run build`, publier le contenu de `front/dist/` dans `/alcours/`.
2. Publier `api/` dans `/alcours/api/` **sans** `config.local.php` écrasé (il reste propre au serveur). Ne jamais publier `api/diag.php`.
3. Scripts de `db/` à exécuter dans Supabase avant de publier le code qui en dépend.

## Après un réimport de la base
Rejouer `outils/verif-schema.sql` (0 ligne = OK). Un réimport a déjà fait perdre des colonnes d'identifiant
(numérotation gérée en code par MAX+1 sur 5 tables) et des booléens. `outils/test-ecritures.sql` et
`outils/test-stats.sql` testent les écritures et les stats sans rien conserver.

## Routes API
Environ 80 routes, regroupées par commentaires dans le tableau `$routes` de `api/index.php`
(authentification, stagiaire, formateur `/admin/…`, Atelier SQL). Le détail par étape est dans les `recap-*.md`.
