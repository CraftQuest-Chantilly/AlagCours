<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const base = import.meta.env.BASE_URL

// Icônes SVG (tracés de type Lucide, trait 1.8) : pas d'emoji, couleur héritée du texte.
const I = {
  accueil: '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
  formations: '<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/>',
  cle: '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M16 7l3 3"/>',
  fichiers: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
  consignes: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
  partiels: '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9zM9 14l2 2 4-4"/>',
  suivi: '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
  entrainement: '<path d="m8 7-5 5 5 5M16 7l5 5-5 5M14 4l-4 16"/>',
  journal: '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>'
}

const menu = computed(() =>
  auth.estFormateur
    ? [
        { label: 'Accueil', to: '/formateur', exact: true, icone: I.accueil },
        { label: 'Fichiers', to: '/formateur/fichiers', icone: I.fichiers },
        { section: 'Atelier SQL' },
        { label: 'Consignes', to: '/formateur/atelier/consignes', icone: I.consignes },
        { label: 'Partiels', to: '/formateur/atelier/partiels', icone: I.partiels },
        { label: 'Suivi', to: '/formateur/atelier/suivi', icone: I.suivi },
        { label: 'Correction & stats', to: '/formateur/atelier/stats', icone: I.suivi },
        { label: 'Entraînement', to: '/formateur/atelier/entrainement', icone: I.entrainement },
        { section: 'Administration' },
        { label: 'Journal', to: '/formateur/journal', icone: I.journal }
      ]
    : [
        { label: 'Mes formations', to: '/', stagiaire: true, icone: I.formations },
        { label: 'Mot de passe', to: '/mot-de-passe', icone: I.cle }
      ]
)

function actif(item) {
  const p = route.path
  if (item.stagiaire) return p === '/' || p.startsWith('/formations')
  return item.exact ? p === item.to : p === item.to || p.startsWith(item.to + '/')
}

const titre = computed(() => (auth.estFormateur ? 'Espace formateur' : 'Espace stagiaire'))
const initiales = computed(() => {
  const u = auth.utilisateur
  return u ? `${(u.prenom ?? '').charAt(0)}${(u.nom ?? '').charAt(0)}`.toUpperCase() || '?' : '?'
})

function lire(cle) { try { return localStorage.getItem(cle) } catch { return null } }
function ecrire(cle, v) { try { localStorage.setItem(cle, v) } catch { /* stockage indisponible */ } }

const replie = ref(lire('alcoursMenuReplie') === '1')
function toggleReplie() { replie.value = !replie.value; ecrire('alcoursMenuReplie', replie.value ? '1' : '0') }

// Mobile : tiroir fermé par défaut, se referme à chaque changement de page.
const tiroir = ref(false)
watch(() => route.fullPath, () => { tiroir.value = false })

function deconnexion() {
  auth.effacer()
  router.push({ name: 'connexion' })
}
</script>

<template>
  <div class="min-h-screen bg-paper">
    <!-- Barre mobile -->
    <header class="sticky top-0 z-30 flex items-center justify-between bg-alag-navy-deep px-4 py-2.5 text-white md:hidden">
      <span class="font-display text-lg font-bold">{{ titre }}</span>
      <button type="button" class="flex h-11 w-11 items-center justify-center rounded-md hover:bg-white/10"
              :aria-expanded="tiroir" aria-label="Ouvrir le menu" @click="tiroir = !tiroir">
        <svg v-if="!tiroir" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        <svg v-else class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
    </header>
    <div v-if="tiroir" class="fixed inset-0 z-30 bg-black/40 md:hidden" @click="tiroir = false" />

    <!-- Sidebar : fixe sur bureau (repliable), tiroir sur mobile -->
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-alag-navy-deep text-slate-200 transition-[width,transform] duration-200"
           :class="[replie ? 'md:w-16' : 'md:w-60', tiroir ? 'translate-x-0' : '-translate-x-full md:translate-x-0']">
      <RouterLink :to="auth.estFormateur ? '/formateur' : '/'" class="flex h-14 items-center gap-2.5 px-4">
        <img :src="`${base}logo-icon.png`" alt="" class="h-8 w-8 shrink-0 rounded bg-white p-0.5">
        <span class="font-display text-base font-bold leading-tight text-white" :class="replie ? 'md:hidden' : ''">{{ titre }}</span>
      </RouterLink>

      <nav class="flex-1 space-y-0.5 overflow-y-auto px-2 py-2" aria-label="Navigation principale">
        <template v-for="(item, i) in menu" :key="item.to || `s${i}`">
          <p v-if="item.section" class="px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-slate-400"
             :class="replie ? 'md:hidden' : ''">{{ item.section }}</p>
          <RouterLink v-else :to="item.to" :title="item.label"
                      class="group relative flex min-h-[44px] items-center gap-3 rounded-md px-3 text-sm font-medium transition-colors hover:bg-white/10 hover:text-white"
                      :class="actif(item) ? 'bg-white/10 text-white' : ''"
                      :aria-current="actif(item) ? 'page' : undefined">
            <span class="absolute inset-y-2 left-0 w-[3px] rounded-r bg-accent transition-opacity" :class="actif(item) ? 'opacity-100' : 'opacity-0'" />
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="item.icone" />
            <span :class="replie ? 'md:hidden' : ''">{{ item.label }}</span>
          </RouterLink>
        </template>
      </nav>

      <div class="space-y-1 border-t border-white/10 p-2">
        <div class="flex items-center gap-2.5 px-3 py-2">
          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-bold text-white">{{ initiales }}</span>
          <span class="truncate text-xs text-slate-300" :class="replie ? 'md:hidden' : ''" data-test="nom-utilisateur">{{ auth.nomComplet }}</span>
        </div>
        <button type="button" class="flex min-h-[44px] w-full items-center gap-3 rounded-md px-3 text-sm hover:bg-red-500/20 hover:text-white" @click="deconnexion">
          <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
          <span :class="replie ? 'md:hidden' : ''">Déconnexion</span>
        </button>
        <button type="button" class="hidden min-h-[44px] w-full items-center gap-3 rounded-md px-3 text-sm hover:bg-white/10 hover:text-white md:flex"
                :aria-label="replie ? 'Déplier le menu' : 'Replier le menu'" @click="toggleReplie">
          <svg class="h-5 w-5 shrink-0 transition-transform" :class="replie ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m11 17-5-5 5-5M18 17l-5-5 5-5"/></svg>
          <span :class="replie ? 'md:hidden' : ''">Replier</span>
        </button>
      </div>
    </aside>

    <main class="px-4 py-6 transition-[padding] duration-200 sm:px-6" :class="replie ? 'md:pl-[88px]' : 'md:pl-[264px]'">
      <div v-if="auth.mdpProvisoire" class="mx-auto mb-5 max-w-6xl border-l-4 border-accent bg-accent-soft px-4 py-2.5 text-sm text-slate-900" role="status">
        Votre mot de passe est provisoire (identique à votre identifiant).
        <RouterLink :to="{ name: 'changer-mdp' }" class="font-semibold text-accent-hover underline hover:no-underline">Le changer maintenant</RouterLink>.
      </div>
      <div :class="route.meta.large ? '' : 'mx-auto max-w-6xl'">
        <RouterView />
      </div>
    </main>
  </div>
</template>
