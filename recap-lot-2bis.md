# Récap — Lot 2 bis : Correction & stats Atelier SQL (A, B validées 13/10/2026 ; C livrée)

## Fait
- **Base** (`db/stats_sql.sql`, exécuté par Jérôme ; test `outils/test-stats.sql`) : SACT_Note (0/0,5/1), SACT_Bonus, SACT_NoteePar/Le sur les tentatives ; tables T_SqlAtelier_Points_Ajoutes et T_SqlAtelier_Promo_Ref (RLS sans policy) ; fonction `sc_sql_stats(promo, stagiaire, du, au)` réservée service_role.
- **API** (`api/routes/atelier_stats.php`) : stats, promos SQL, liste des requêtes filtrée, modification vu/bonus/note, promo de référence, bonus + points ajoutés, `/atelier/mes-stats` (promo imposée par le serveur, comparaison masquée < 3 stagiaires).
- **Formateur** `AdminAtelierStatsPage.vue` (menu Atelier SQL → Correction & stats) : filtres, tuiles, par groupe, promo de référence, bonus/points, requêtes avec tricolore.
- **Stagiaire** `AtelierMesStatsPage.vue` (tuile « Mes stats » dans l'Atelier) : Moi / Ma promo / Promo de référence (moyennes par stagiaire), bonus personnel seulement.

## Décisions (Jérôme)
Notés validés = note posée (rouge 0, vert > 0) ; bonus = marque + points ajoutés à la main ; « Sec » abandonné ; promo de référence choisie par promo ; note finale = somme des notes + points ajoutés (aucune autre formule) ; bonus non comparé côté stagiaire.

## Réserves
- Une tentative compte pour une promo si le stagiaire en est membre actif (les tentatives n'ont pas de promo propre).
- Groupes = texte libre (78 valeurs en base) ; vide / « - » → « (sans groupe) ».
- Routes PHP et écrans testés avec API simulée seulement (base de test sans tentative) ; à tester en réel après poussée OVH.
- Liste de requêtes limitée à 200.
