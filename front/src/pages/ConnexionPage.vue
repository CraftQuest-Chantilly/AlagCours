<script setup>
// Remplace PAGE__Login d'al-cours (branche stagiaire) : identifiant + mot de passe.
// Le captcha d'origine (après 3 échecs) est remplacé par un blocage temporaire côté API.
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const base = import.meta.env.BASE_URL

const login = ref('')
const mdp = ref('')
const voirMdp = ref(false)
const enCours = ref(false)
const erreur = ref(route.query.expire ? 'Votre session a expiré, reconnectez-vous.' : '')

async function seConnecter() {
  if (!login.value.trim() || !mdp.value) return
  enCours.value = true
  erreur.value = ''
  try {
    await auth.connecter(login.value.trim(), mdp.value)
    router.push({ name: 'formations' })
  } catch (e) {
    erreur.value = e.message
  } finally {
    enCours.value = false
  }
}
</script>

<template>
  <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-alag-navy px-4">
    <img :src="`${base}logo-icon.png`" alt=""
         class="pointer-events-none absolute left-1/2 top-1/2 h-[140vh] w-[140vh] -translate-x-1/2 -translate-y-1/2 opacity-20">

    <div class="relative w-full max-w-sm rounded-2xl border border-white/30 bg-white/20 p-8 shadow-2xl backdrop-blur-xl">
      <div class="mb-6 flex justify-center">
        <img :src="`${base}logo-full.png`" alt="Alag-info" class="h-12">
      </div>
      <h1 class="mb-6 text-center text-lg font-semibold text-white">Espace stagiaire</h1>

      <form class="space-y-4" @submit.prevent="seConnecter">
        <div>
          <label for="login" class="mb-1 block text-sm font-medium text-white">Identifiant</label>
          <input id="login" v-model="login" type="text" autocomplete="username" required
                 class="w-full rounded-lg border border-white/40 bg-white/85 px-3 py-2 text-sm focus:border-white focus:outline-none focus:ring-1 focus:ring-white">
        </div>
        <div>
          <label for="mdp" class="mb-1 block text-sm font-medium text-white">Mot de passe</label>
          <div class="relative">
            <input id="mdp" v-model="mdp" :type="voirMdp ? 'text' : 'password'" autocomplete="current-password" required
                   class="w-full rounded-lg border border-white/40 bg-white/85 px-3 py-2 pr-16 text-sm focus:border-white focus:outline-none focus:ring-1 focus:ring-white">
            <button type="button" class="absolute inset-y-0 right-2 my-auto h-7 rounded px-2 text-xs font-medium text-alag-navy hover:bg-white/60"
                    :aria-pressed="voirMdp" @click="voirMdp = !voirMdp">
              {{ voirMdp ? 'Masquer' : 'Afficher' }}
            </button>
          </div>
        </div>

        <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>

        <button type="submit" :disabled="enCours"
                class="w-full rounded-lg bg-white py-2 text-sm font-semibold text-alag-navy shadow transition-colors hover:bg-alag-blue-light disabled:opacity-60">
          {{ enCours ? 'Connexion…' : 'Se connecter' }}
        </button>
      </form>
    </div>
  </div>
</template>
