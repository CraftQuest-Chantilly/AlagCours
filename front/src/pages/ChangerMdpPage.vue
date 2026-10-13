<script setup>
// Changement de mot de passe stagiaire (E8bis, 26/09/2026) — annoncé depuis le début par le
// bandeau "mot de passe provisoire" de AppLayout.vue, jamais construit jusqu'ici. Ne touche pas
// à la convention Stag_Login/Stag_Mdp existante : juste un moyen pour le stagiaire de sortir du
// mot de passe provisoire (= identifiant) par lui-même.
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const mdpActuel = ref('')
const nouveauMdp = ref('')
const confirmation = ref('')
const enCours = ref(false)
const erreur = ref('')
const succes = ref(false)

async function valider() {
  erreur.value = ''
  if (nouveauMdp.value.length < 8) { erreur.value = 'Le nouveau mot de passe doit contenir au moins 8 caractères.'; return }
  if (nouveauMdp.value !== confirmation.value) { erreur.value = 'La confirmation ne correspond pas au nouveau mot de passe.'; return }
  enCours.value = true
  try {
    await api('/me/mot-de-passe', { method: 'PUT', body: { mdpActuel: mdpActuel.value, nouveauMdp: nouveauMdp.value } })
    succes.value = true
    // Rafraîchit l'état local (le bandeau "mot de passe provisoire" doit disparaître aussitôt).
    if (auth.utilisateur) auth.utilisateur.mdpProvisoire = false
    mdpActuel.value = ''
    nouveauMdp.value = ''
    confirmation.value = ''
  } catch (e) {
    erreur.value = e.message
  } finally {
    enCours.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'formations' }" class="text-sm text-alag-navy hover:underline">‹ Mes formations</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Changer mon mot de passe</h1>

    <section class="sc-card max-w-sm space-y-3 p-4">
      <p v-if="succes" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
        Mot de passe changé. Utilisez-le dès votre prochaine connexion.
      </p>

      <form class="space-y-3" @submit.prevent="valider">
        <label class="block text-sm font-medium text-slate-700">
          Mot de passe actuel
          <input v-model="mdpActuel" type="password" autocomplete="current-password" required class="mt-1 block w-full sc-input" />
        </label>
        <label class="block text-sm font-medium text-slate-700">
          Nouveau mot de passe
          <input v-model="nouveauMdp" type="password" autocomplete="new-password" required minlength="8" class="mt-1 block w-full sc-input" />
        </label>
        <label class="block text-sm font-medium text-slate-700">
          Confirmer le nouveau mot de passe
          <input v-model="confirmation" type="password" autocomplete="new-password" required minlength="8" class="mt-1 block w-full sc-input" />
        </label>

        <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>

        <button type="submit" :disabled="enCours" class="rounded-lg bg-alag-navy px-4 py-2 text-sm font-medium text-white hover:bg-alag-navy-dark disabled:opacity-50">
          {{ enCours ? 'Enregistrement…' : 'Changer le mot de passe' }}
        </button>
      </form>
    </section>
  </div>
</template>
