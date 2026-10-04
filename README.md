# Portail stagiaire al-cours (alaginfo.net/alcours/)

Remplace le site WebDev **al-cours** (puis SqlRA). Aucune dépendance à Supabase : le navigateur
ne parle qu'à l'API PHP, qui ne fait que du SQL PostgreSQL standard (déménagement de la base
vers le VPS = changer le DSN dans `api/config.local.php`).

```
front/   Vue 3 + Vite + Tailwind + Pinia (build -> front/dist, publié dans /alcours/)
api/     API PHP (>= 7.4, extension pdo_pgsql), publiée dans /alcours/api/
```

## Développement
1. API : copier `api/config.local.example.php` en `api/config.local.php` et le remplir
   (DSN PostgreSQL, utilisateur, mot de passe, `jwt_secret` aléatoire de 64 caractères).
   Serveur de test : `php -S 127.0.0.1:8080 router.php` depuis un dossier qui contient
   `alcours/api` (voir `outils/router.php`).
2. Front : `cd front && npm install && npm run dev` → http://localhost:5175/alcours/
   (`/alcours/api` est relayé vers `VITE_API_PROXY`, par défaut http://127.0.0.1:8080).

## Déploiement IIS (OVH)
1. `cd front && npm run build`
2. Copier le contenu de `front/dist/` dans le dossier IIS de `/alcours/`
   (contient `web.config` : réécriture vers `index.html` pour les routes Vue).
3. Copier `api/` dans `/alcours/api/` (son `web.config` réécrit tout vers `index.php`
   et masque `lib/`, `routes/`, `config*.php`), puis y créer `config.local.php`.
4. PHP doit avoir `pdo_pgsql` activé ; le module IIS « URL Rewrite » doit être installé.

## Routes API (étape 2)
| Méthode | Route | Rôle |
|---|---|---|
| POST | /login | `{login, mdp}` → jeton de session (8 h) |
| GET | /me | stagiaire connecté |
| GET | /formations | promos actives du stagiaire |
| GET | /agenda | séances de toutes ses promos (filtrées par groupe) |
| GET | /formations/{code} | en-tête + modules (marqueurs `Pro_Doc1`) |
| GET | /formations/{code}/seances | séances de la promo pour son groupe |

Les identifiants (bigint à 17 chiffres) sont toujours échangés en **chaînes** : JavaScript
ne peut pas les représenter exactement en nombre.
