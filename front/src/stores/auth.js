// Session de l'utilisateur connecté (stagiaire OU formateur, même écran de connexion) :
// jeton émis par l'API PHP + type de compte + identité. Stockée en sessionStorage :
// fermer l'onglet déconnecte (même choix que l'appli formateur SuiviCours).
import { defineStore } from 'pinia'
import { api } from '@/services/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({ jeton: null, type: null, utilisateur: null }),
  getters: {
    connecte: (s) => !!s.jeton,
    estFormateur: (s) => s.type === 'formateur',
    estStagiaire: (s) => s.type === 'stagiaire',
    nomComplet: (s) => (s.utilisateur ? `${s.utilisateur.prenom ?? ''} ${s.utilisateur.nom ?? ''}`.trim() : ''),
    mdpProvisoire: (s) => s.type === 'stagiaire' && !!s.utilisateur?.mdpProvisoire
  },
  actions: {
    async connecter(login, mdp) {
      const r = await api('/login', { method: 'POST', body: { login, mdp } })
      this.jeton = r.jeton
      this.type = r.type
      this.utilisateur = r.utilisateur
    },
    effacer() {
      this.jeton = null
      this.type = null
      this.utilisateur = null
    }
  },
  persist: { storage: sessionStorage }
})
