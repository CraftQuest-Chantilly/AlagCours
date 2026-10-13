# Plan — Lot 2 bis : Correction & stats Atelier SQL (À VALIDER, 13/10/2026)

Source : captures SqlRA fournies par Jérôme (tableau de bord à tuiles + écran de correction des requêtes). Rien n'est codé.

## Besoin (tel qu'exprimé)
- **Formateur** : voir toutes les requêtes des stagiaires entre deux dates, filtrables (prénom, nom, groupe/TD, sur consigne, 1 user, « ma rép », vu / pas vu, échecs). Pour chaque requête : cocher Vu, mettre Bonus, noter au tricolore (rouge 0, orange 0,5, vert 1).
- **Tuiles** (stats de la capture) pour toutes les promos de l'année ayant l'option Atelier SQL, filtrables par promo et par stagiaire.
- **Stagiaire** : page « Mes stats » = mêmes tuiles pour lui, comparées à sa promo (chiffres agrégés uniquement, jamais de noms ni de requêtes d'autres).
- **Promo de référence** : pour une promo, le formateur choisit une promo précédente de référence ; c'est elle qui alimente « promo préc ».

## Définition des tuiles (à confirmer)
| Tuile | Calcul |
|---|---|
| Nb essais total | tentatives dans la période / le périmètre |
| Essais non notés | tentatives sur consignes non notées ; rouge = KO, vert = OK (verdict automatique) |
| Notés | tentatives sur consignes marquées « notées » ; rouge = KO, vert = OK |
| Notés validés | tentatives notées par le formateur ; rouge = note 0, vert = note > 0 |
| Bonus | tentatives avec Bonus ; liste des stagiaires concernés |
| Nb bonus TD | bonus par groupe (A/B/C/D, 1/2…) = `PromSta_Groupe` |
| Promo actuelle / préc | OK/KO par groupe pour la promo et pour sa promo de référence |
« Sec » : abandonné (décision Jérôme).

## Base (scripts .sql à exécuter par Jérôme, additifs)
1. `T_SqlAtelier_Consigne_Tentative` : + note (0 / 0,5 / 1, NULL = non noté), bonus (bool), noté_par, noté_le.
2. Nouvelle table de points ajoutés à la main sur la note finale (par stagiaire et par promo) + utilisateur/date.
3. Nouvelle table promo → promo de référence.
4. Fonction `sc_sql_stats(...)` (périmètre : promo, stagiaire, dates) renvoyant les tuiles ; mêmes calculs pour stagiaire et formateur.
5. Droits : exécution réservée côté API (jeton), pas d'accès anon aux nouvelles fonctions (cohérent avec Lot 1).

## API (PHP)
- Formateur : `GET /admin/atelier/stats`, `GET /admin/atelier/requetes` (liste filtrée), `PUT /admin/atelier/requetes/{id}` (vu, bonus, note), `GET/PUT /admin/promos/{code}/sql-reference`, liste des bonus + points ajoutés.
- Stagiaire : `GET /atelier/mes-stats` (lui + sa promo agrégée ; promo imposée par le jeton, minimum 3 stagiaires pour afficher la comparaison).

## Écrans (charte déjà en place)
- Formateur « Correction & stats » : tuiles, filtres, liste des requêtes avec tricolore / Vu / Bonus ; encart bonus.
- Stagiaire « Mes stats » : colonnes « Moi » / « Ma promo » / « Promo de référence ».

## Découpage en sous-étapes (une validation chacune)
A. Base + API stats (testées avec données fictives annulées — la base de test n'a aucune tentative).
B. Écran formateur : correction + tuiles.
C. Écran stagiaire « Mes stats ».

## Points à confirmer avant A
1. Note finale : on n'invente aucune formule. Je propose de n'afficher que la somme des notes validées + les points ajoutés à la main. OK ?
2. Le bonus compte-t-il aussi dans la comparaison côté stagiaire ? (proposé : non, seulement son propre bonus visible).
3. Où se choisit la promo de référence : sur l'écran formateur Stats (proposé) ?
