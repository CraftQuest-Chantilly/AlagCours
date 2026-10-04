<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const base = import.meta.env.BASE_URL

const accueil = computed(() => (auth.estFormateur ? { name: 'formateur-accueil' } : { name: 'formations' }))
const titre = computed(() => (auth.estFormateur ? 'Espace formateur' : 'Espace stagiaire'))

function deconnexion() {
  auth.effacer()
  router.push({ name: 'connexion' })
}
</script>

<template>
  <div class="min-h-screen">
    <header class="bg-alag-navy text-white shadow">
      <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
        <RouterLink :to="accueil" class="flex items-center gap-3">
          <img :src="`${base}logo-icon.png`" alt="" class="h-8 w-8 rounded bg-white p-0.5">
          <span class="text-base font-semibold sm:text-lg">{{ titre }}</span>
        </RouterLink>
        <div class="flex items-center gap-3 text-sm">
          <span class="hidden sm:inline" data-test="nom-utilisateur">{{ auth.nomComplet }}</span>
          <button type="button" class="rounded-lg bg-white/15 px-3 py-1.5 font-medium hover:bg-white/25" @click="deconnexion">
            Déconnexion
          </button>
        </div>
      </div>
    </header>

    <div v-if="auth.mdpProvisoire" class="bg-amber-100 text-amber-900">
      <p class="mx-auto max-w-6xl px-4 py-2 text-sm">
        Votre mot de passe est provisoire (identique à votre identifiant).
        <RouterLink :to="{ name: 'changer-mdp' }" class="font-medium underline hover:no-underline">Le changer maintenant</RouterLink>.
      </p>
    </div>

    <main class="mx-auto max-w-6xl px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>
