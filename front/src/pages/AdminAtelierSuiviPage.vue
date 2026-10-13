<script setup>
// Atelier SQL — suivi en direct (formateur), E8bis 26/09/2026. Équivalent réduit du moniteur de
// connectés + chat + détection d'abus de l'ancien BackOffice SqlRA. Rafraîchi par sondage
// (polling) manuel ou automatique — AUCUNE dépendance à Supabase Realtime (décision de Jérôme) :
// tout passe par les mêmes routes REST que le reste de l'API.
import { onMounted, onUnmounted, ref } from 'vue'
import { api } from '@/services/api'

const presents = ref([])
const messages = ref([])
const abus = ref(null)
const chargement = ref(true)
const erreur = ref('')
const autoRefresh = ref(true)
const nouveauMessage = ref('')
const envoiMessage = ref(false)

let minuteur = null

async function tout_charger() {
  erreur.value = ''
  try {
    const [p, m, a] = await Promise.all([
      api('/admin/atelier/presence'),
      api('/admin/atelier/messages'),
      api('/admin/atelier/abus'),
    ])
    presents.value = p
    messages.value = m
    abus.value = a
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
}

async function envoyer() {
  if (!nouveauMessage.value.trim()) return
  envoiMessage.value = true
  try {
    await api('/admin/atelier/messages', { method: 'POST', body: { contenu: nouveauMessage.value } })
    nouveauMessage.value = ''
    messages.value = await api('/admin/atelier/messages')
  } catch (e) {
    erreur.value = e.message
  } finally {
    envoiMessage.value = false
  }
}

function heureFr(iso) {
  return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

onMounted(() => {
  tout_charger()
  minuteur = setInterval(() => { if (autoRefresh.value) tout_charger() }, 20000)
})
onUnmounted(() => clearInterval(minuteur))
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'formateur-accueil' }" class="text-sm text-alag-navy hover:underline">‹ Accueil formateur</RouterLink>

    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-semibold text-alag-navy">Atelier SQL — suivi en direct</h1>
      <div class="flex items-center gap-3 text-sm">
        <label class="flex items-center gap-1.5 text-slate-600">
          <input v-model="autoRefresh" type="checkbox" class="rounded border-slate-300" /> Rafraîchissement auto (20 s)
        </label>
        <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium hover:bg-slate-50" @click="tout_charger">
          ↻ Rafraîchir
        </button>
      </div>
    </div>
    <p class="text-xs text-slate-500">
      Pas de temps réel au sens strict : ces données sont rafraîchies par sondage, pas poussées instantanément. La présence reflète les stagiaires actifs sur l'atelier SQL dans les 3 dernières minutes.
    </p>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <section class="sc-card p-4">
        <h2 class="mb-2 text-sm font-semibold text-alag-navy">Stagiaires actifs ({{ presents.length }})</h2>
        <p v-if="!presents.length" class="text-sm text-slate-600">Personne actuellement sur l'atelier SQL.</p>
        <ul v-else class="flex flex-wrap gap-1.5">
          <li v-for="p in presents" :key="p.code" class="rounded bg-emerald-50 px-2 py-1 text-xs text-emerald-800 ring-1 ring-emerald-200">
            {{ p.nom }} {{ p.prenom }} · {{ heureFr(p.derniereActivite) }}
          </li>
        </ul>
      </section>

      <section class="sc-card p-4" :class="abus?.stagiaires?.length ? 'ring-1 ring-amber-300' : ''">
        <h2 class="mb-2 text-sm font-semibold text-alag-navy">
          Abus de lancement de requêtes
          <span class="font-normal text-slate-500">(≥ {{ abus?.seuil }} en {{ abus?.fenetreSecondes }} s)</span>
        </h2>
        <p v-if="!abus?.stagiaires?.length" class="text-sm text-slate-600">Rien à signaler.</p>
        <ul v-else class="space-y-1">
          <li v-for="s in abus.stagiaires" :key="s.code" class="rounded bg-amber-50 px-2 py-1 text-sm text-amber-800 ring-1 ring-amber-200">
            {{ s.nom }} {{ s.prenom }} — {{ s.nbRequetes }} requêtes
          </li>
        </ul>
      </section>

      <section class="sc-card flex max-h-[28rem] flex-col overflow-hidden">
        <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-alag-navy">Messagerie</h2>
        <div class="flex-1 space-y-2 overflow-y-auto p-4">
          <p v-if="!messages.length" class="text-sm text-slate-600">Aucun message.</p>
          <div v-for="m in messages" :key="m.id" class="rounded-lg p-2 text-sm" :class="m.type === 'formateur' ? 'bg-paper/60' : 'bg-slate-100'">
            <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
              <span class="font-medium text-slate-700">{{ m.nom }}</span>
              <span>{{ heureFr(m.date) }}</span>
            </div>
            <div class="text-slate-800">{{ m.contenu }}</div>
          </div>
        </div>
        <form class="flex gap-2 border-t border-slate-100 p-3" @submit.prevent="envoyer">
          <input v-model="nouveauMessage" type="text" maxlength="1000" placeholder="Écrire un message aux stagiaires…"
                 class="flex-1 rounded-lg border-slate-300 text-sm" />
          <button type="submit" :disabled="envoiMessage || !nouveauMessage.trim()"
                  class="rounded-lg bg-alag-navy px-4 py-2 text-sm font-medium text-white hover:bg-alag-navy-dark disabled:opacity-50">
            Envoyer
          </button>
        </form>
      </section>
    </template>
  </div>
</template>
