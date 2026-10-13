import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  { path: '/connexion', name: 'connexion', component: () => import('@/pages/ConnexionPage.vue') },
  {
    path: '/',
    component: () => import('@/components/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      // Espace stagiaire.
      { path: '', name: 'formations', component: () => import('@/pages/FormationsPage.vue'), meta: { type: 'stagiaire' } },
      { path: 'mot-de-passe', name: 'changer-mdp', component: () => import('@/pages/ChangerMdpPage.vue'), meta: { type: 'stagiaire' } },
      { path: 'formations/:code', name: 'formation', component: () => import('@/pages/FormationPage.vue'), props: true, meta: { type: 'stagiaire' } },
      { path: 'formations/:code/ressources', name: 'ressources', component: () => import('@/pages/RessourcesPage.vue'), props: true, meta: { type: 'stagiaire' } },
      { path: 'formations/:code/evaluation', name: 'evaluation', component: () => import('@/pages/EvaluationPage.vue'), props: true, meta: { type: 'stagiaire' } },
      { path: 'formations/:code/quizz', name: 'quizz', component: () => import('@/pages/QuizzPage.vue'), props: true, meta: { type: 'stagiaire' } },
      { path: 'formations/:code/quizz/:qprom', name: 'quizz-session', component: () => import('@/pages/QuizzSessionPage.vue'), props: true, meta: { type: 'stagiaire' } },
      { path: 'formations/:code/signature', name: 'signature', component: () => import('@/pages/SignaturePage.vue'), props: true, meta: { type: 'stagiaire' } },
      { path: 'formations/:code/avis', name: 'avis', component: () => import('@/pages/AvisPage.vue'), props: true, meta: { type: 'stagiaire' } },
      { path: 'formations/:code/atelier', name: 'atelier', component: () => import('@/pages/AtelierPage.vue'), props: true, meta: { type: 'stagiaire' } },
      { path: 'formations/:code/atelier/entrainement', name: 'atelier-entrainement', component: () => import('@/pages/AtelierEntrainementPage.vue'), props: true, meta: { type: 'stagiaire' } },
      { path: 'formations/:code/atelier/partiels/:numPartiel', name: 'atelier-partiel', component: () => import('@/pages/AtelierPartielPage.vue'), props: true, meta: { type: 'stagiaire' } },
      { path: 'formations/:code/atelier/stats', name: 'atelier-mes-stats', component: () => import('@/pages/AtelierMesStatsPage.vue'), props: true, meta: { type: 'stagiaire' } },
      { path: 'formations/:code/atelier/consignes', name: 'atelier-consignes', component: () => import('@/pages/AtelierConsignesPage.vue'), props: true, meta: { type: 'stagiaire' } },
      // Espace formateur (écran de connexion partagé, cf. route_login de l'API).
      { path: 'formateur', name: 'formateur-accueil', component: () => import('@/pages/FormateurAccueilPage.vue'), meta: { type: 'formateur' } },
      { path: 'formateur/atelier/partiels', name: 'admin-atelier-partiel', component: () => import('@/pages/AtelierAdminPartielPage.vue'), meta: { type: 'formateur' } },
      { path: 'formateur/atelier/consignes', name: 'admin-atelier-consignes', component: () => import('@/pages/AdminAtelierConsignesPage.vue'), meta: { type: 'formateur' } },
      { path: 'formateur/journal', name: 'admin-journal', component: () => import('@/pages/AdminJournalPage.vue'), meta: { type: 'formateur' } },
      { path: 'formateur/atelier/stats', name: 'admin-atelier-stats', component: () => import('@/pages/AdminAtelierStatsPage.vue'), meta: { type: 'formateur' } },
      { path: 'formateur/atelier/suivi', name: 'admin-atelier-suivi', component: () => import('@/pages/AdminAtelierSuiviPage.vue'), meta: { type: 'formateur' } },
      { path: 'formateur/atelier/entrainement', name: 'admin-atelier-entrainement', component: () => import('@/pages/AtelierEntrainementPage.vue'), meta: { type: 'formateur' } },
      // Gestion des fichiers (E8bis, 26/09/2026) — remplace entièrement l'ancien flux
      // admin-promos / admin-promo-fichiers (PAGE_ChoixPromo + PAGE_ModifFicDroit) par un écran
      // unique calqué sur PAGE_Fichiers_Adm du site legacy al-cours (voir AdminGestionFichiersPage.vue).
      { path: 'formateur/fichiers', name: 'admin-fichiers', component: () => import('@/pages/AdminGestionFichiersPage.vue'), meta: { type: 'formateur', large: true } }
    ]
  },
  { path: '/:pathMatch(.*)*', redirect: '/' }
]

const router = createRouter({ history: createWebHistory(import.meta.env.BASE_URL), routes })

// Page d'accueil selon le type de compte connecté.
function accueil(auth) {
  return auth.estFormateur ? { name: 'formateur-accueil' } : { name: 'formations' }
}

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.requiresAuth && !auth.connecte) return { name: 'connexion' }
  if (to.name === 'connexion' && auth.connecte) return accueil(auth)
  // Un stagiaire ne voit pas les écrans formateur et inversement.
  if (to.meta.type && auth.connecte && to.meta.type !== auth.type) return accueil(auth)
})

export default router
