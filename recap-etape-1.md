# Recap étape 1 — Analyse des deux sites + plan de fusion (VALIDÉE le 2026-09-25)

Document détaillé : doc projet `claude/plan-fusion-etape-1.md` (inventaire page par page, incohérences).

## Décisions validées
- **Portail** : appli Vue 3 + Tailwind **séparée**, dossier de dev `C:\Users\badet\Documents\Webdevtest\Alag_Cours`, même Supabase `zykgfhahyuckuhohhmcz`.
- **Ordre** : on recrée d'abord **al-cours**, puis SqlRA (bouton « SQL » dans le tableau de bord de la formation pour le stagiaire + bouton « Back-office » réservé au formateur/admin — conception ultérieure).
- **Login** : identique à l'existant, `Stag_Login` + `Stag_Mdp`. La colonne `Stag_Mdp` a été créée mais elle est **vide** (0/12 250).
- **Claroline** : abandonnée (pas de tuile ; `cl_user` / `c_tracking_event` / `Stag_user_id_claro` ne sont pas utilisés).
- **Fichiers** : ils restent sur le serveur OVH `D:\FTP\_Fichiers\_Suivicours\_Fichiers` → il faut un mécanisme d'upload (admin) et de download (stagiaire) — architecture proposée en étape 2.

## Écrans du portail (al-cours)
E1 Connexion · E2 Mes formations + agenda · E3 Tableau de bord de la formation (tuiles selon marqueurs `Pro_Doc1`) · E4 Ressources · E5 Séances & points vus (nouveau) · E6 Quizz (positionnement / fin / évaluation via `T_Quizz_Eval_Rep` + QCM notés via `sc_stag_quizz_*`) · E7 Signature de présence (ensuite).
Abandonnés : `PAGE__Accueil_Mobile`, `PAGE_Inscription` (al-cours), `PAGE_Quizz`, `PAGE_Stagiaire_Choix_Promo`, `Page_Timer`, Kanban, pages `_BAK`/`_old`, messagerie SqlRA, `TS_User`.
Hors périmètre (appli admin) : pages admin des deux sites.

## Incohérences signalées (état au 2026-09-25)
1. `sc_is_staff()` = `SELECT true` → un stagiaire authentifié aurait tous les droits (traité en étape 2).
2. Emails non uniques (4 622 distincts / 12 242) → non utilisables comme identifiant.
3. `Stag_Login` : 1 doublon (`ELOrad4565` ×2).
4. `Stag_Mdp` vide.
5. Aucun fichier physique dans Supabase (métadonnées seules dans `T_Fichier`).
6. Deux systèmes de quizz ; aucune RPC pour `T_Quizz_Eval_Rep`.
7. `PromSta_Actif` vrai pour 10 905 stagiaires.
8. Signature hors créneau : le « code » est l'heure courante HHMM.
9. `cl_user` / `c_tracking_event` sont des exports Claroline (SqlRA), pas des tables vitrine (correction du recap-global §4.2).
