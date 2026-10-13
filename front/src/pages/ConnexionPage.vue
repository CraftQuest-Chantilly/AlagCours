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
  <div class="grid min-h-screen bg-paper lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
    <!-- Bandeau marque : en haut sur mobile, colonne de gauche sur grand écran -->
    <aside class="flex flex-col justify-between bg-alag-navy-deep px-6 py-6 text-white lg:px-12 lg:py-12">
      <img :src="`${base}logo-full.png`" alt="ALAG-info — Solutions Informatiques & Formation"
           class="h-14 w-auto self-start rounded-md lg:h-24">
      <div class="hidden lg:block">
        <p class="font-display text-3xl font-bold leading-tight">Vos formations,<br>vos ressources,<br>vos exercices.</p>
        <p class="mt-4 max-w-sm text-sm text-slate-300">
          Consultez vos formations, téléchargez les fichiers de cours, répondez aux quizz et
          entraînez-vous en SQL depuis un seul espace.
        </p>
      </div>
      <p class="hidden text-xs text-slate-400 lg:block">ALAG-info</p>
    </aside>

    <!-- Formulaire -->
    <main class="flex items-center justify-center px-6 py-10">
      <div class="w-full max-w-sm">
        <h1 class="font-display text-2xl font-bold text-alag-navy">Connexion</h1>
        <p class="mt-1 text-sm text-slate-600">Stagiaires et formateurs : même identifiant que d'habitude.</p>

        <form class="mt-8 space-y-5" @submit.prevent="seConnecter">
          <div>
            <label for="login" class="mb-1 block text-sm font-medium text-slate-800">Identifiant</label>
            <input id="login" v-model="login" type="text" autocomplete="username" required autofocus class="sc-input w-full">
          </div>
          <div>
            <label for="mdp" class="mb-1 block text-sm font-medium text-slate-800">Mot de passe</label>
            <div class="relative">
              <input id="mdp" v-model="mdp" :type="voirMdp ? 'text' : 'password'" autocomplete="current-password" required class="sc-input w-full pr-24">
              <button type="button" class="absolute inset-y-0 right-1 my-auto h-9 rounded px-3 text-xs font-medium text-alag-navy hover:bg-slate-100"
                      :aria-pressed="voirMdp" @click="voirMdp = !voirMdp">
                {{ voirMdp ? 'Masquer' : 'Afficher' }}
              </button>
            </div>
          </div>

          <p v-if="erreur" class="border-l-4 border-red-600 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ erreur }}</p>

          <button type="submit" :disabled="enCours" class="sc-btn-accent w-full">
            {{ enCours ? 'Connexion…' : 'Se connecter' }}
          </button>
        </form>
      </div>
    </main>
  </div>
</template>
