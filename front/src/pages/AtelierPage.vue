<script setup>
import Icone from '@/components/Icone.vue'
// Atelier SQL (E8) — écran d'accueil du module, remplace SqlRA. Deux entrées : l'entraînement
// libre (pas de scoping par promo, base commune à tous) et la liste des partiels programmés par
// le formateur pour CETTE promo (le cas échéant — vide tant qu'aucun formateur n'en a créé).
import { onMounted, onUnmounted, ref } from 'vue'
import { api } from '@/services/api'
import { dateFr } from '@/utils'

const props = defineProps({ code: { type: String, required: true } })

const partiels = ref([])
const chargement = ref(true)
const erreur = ref('')

// Présence (E8bis, 26/09/2026) : signale au formateur que le stagiaire est actif sur l'atelier
// SQL, pour le suivi en direct côté BackOffice. Simple sondage périodique, rien d'intrusif.
let minuteurPresence = null
function signalerPresence() {
  api('/atelier/presence', { method: 'POST' }).catch(() => {}) // best-effort, ne bloque jamais l'écran
}

// Messagerie (fil unique de l'atelier SQL, pas un chat temps réel — rafraîchissement manuel/auto).
const messagerieOuverte = ref(false)
const messages = ref([])
const nouveauMessage = ref('')
const envoiMessage = ref(false)
const erreurMessagerie = ref('')
let minuteurMessages = null

async function chargerMessages() {
  try {
    messages.value = await api('/atelier/messages')
  } catch (e) {
    erreurMessagerie.value = e.message
  }
}

async function envoyerMessage() {
  if (!nouveauMessage.value.trim()) return
  envoiMessage.value = true
  try {
    await api('/atelier/messages', { method: 'POST', body: { contenu: nouveauMessage.value } })
    nouveauMessage.value = ''
    await chargerMessages()
  } catch (e) {
    erreurMessagerie.value = e.message
  } finally {
    envoiMessage.value = false
  }
}

function heureFrCourte(iso) {
  return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function heureFr(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function etat(p) {
  if (p.pasEncoreCommence) return { texte: `Ouvre le ${heureFr(p.debut)}`, classe: 'text-slate-500' }
  if (p.dansFenetre) return { texte: `En cours — à rendre avant le ${heureFr(p.fin)}`, classe: 'text-emerald-700 font-medium' }
  return { texte: `Clos depuis le ${heureFr(p.fin)}`, classe: 'text-slate-500' }
}

onMounted(async () => {
  try {
    partiels.value = await api(`/formations/${props.code}/atelier/partiels`)
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }

  signalerPresence()
  minuteurPresence = setInterval(signalerPresence, 30000)
  chargerMessages()
  minuteurMessages = setInterval(chargerMessages, 20000)
})

onUnmounted(() => {
  clearInterval(minuteurPresence)
  clearInterval(minuteurMessages)
})
</script>

<template>
  <div class="space-y-6">
    <RouterLink :to="{ name: 'formation', params: { code } }" class="text-sm text-alag-navy hover:underline">‹ Retour à la formation</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Atelier SQL</h1>

    <RouterLink :to="{ name: 'atelier-entrainement', params: { code } }"
                class="sc-tile">
      <div>
        <div class="font-semibold text-slate-900">Entraînement libre</div>
        <div class="text-xs text-slate-600">Choisissez une base, explorez son schéma et écrivez vos requêtes SELECT, sans limite de temps ni de tentatives.</div>
      </div>
      <span class="sc-tile-icone order-first"><Icone nom="sql" class="h-5 w-5" /></span>
    </RouterLink>

    <RouterLink :to="{ name: 'atelier-consignes', params: { code } }"
                class="sc-tile">
      <div>
        <div class="font-semibold text-slate-900">Consignes (exercices notés)</div>
        <div class="text-xs text-slate-600">La banque d'exercices posée par votre formateur : résolvez-les à votre rythme, vos stats se mettent à jour au fil de l'eau.</div>
      </div>
      <span class="sc-tile-icone order-first"><Icone nom="consignes" class="h-5 w-5" /></span>
    </RouterLink>

    <RouterLink :to="{ name: 'atelier-mes-stats', params: { code } }" class="sc-tile">
      <div>
        <div class="font-semibold text-slate-900">Mes stats</div>
        <div class="text-xs text-slate-600">Vos essais, vos notes et votre bonus, comparés à votre promo.</div>
      </div>
      <span class="sc-tile-icone order-first"><Icone nom="quizz" class="h-5 w-5" /></span>
    </RouterLink>

    <section>
      <h2 class="mb-3 text-base font-semibold text-alag-navy">Évaluations</h2>
      <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
      <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>
      <div v-else class="sc-card overflow-hidden">
        <p v-if="!partiels.length" class="p-4 text-sm text-slate-600">Aucune évaluation programmée pour votre promo pour le moment.</p>
        <ul v-else class="divide-y divide-slate-100 text-sm">
          <li v-for="p in partiels" :key="p.numPartiel">
            <RouterLink :to="{ name: 'atelier-partiel', params: { code, numPartiel: p.numPartiel } }"
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-paper">
              <span class="font-medium text-slate-900">{{ p.titre }}</span>
              <span class="text-xs" :class="etat(p).classe">{{ etat(p).texte }}</span>
            </RouterLink>
          </li>
        </ul>
      </div>
    </section>

    <section>
      <button type="button" class="mb-2 flex w-full items-center justify-between text-base font-semibold text-alag-navy" @click="messagerieOuverte = !messagerieOuverte">
        <span>Messagerie</span>
        <span class="text-sm font-normal text-alag-navy">{{ messagerieOuverte ? 'Fermer' : 'Ouvrir' }}</span>
      </button>
      <div v-if="messagerieOuverte" class="sc-card flex max-h-96 flex-col overflow-hidden">
        <p v-if="erreurMessagerie" class="p-3 text-sm text-red-700">{{ erreurMessagerie }}</p>
        <div class="flex-1 space-y-2 overflow-y-auto p-4">
          <p v-if="!messages.length" class="text-sm text-slate-600">Aucun message pour le moment.</p>
          <div v-for="m in messages" :key="m.id" class="rounded-lg p-2 text-sm" :class="m.type === 'formateur' ? 'bg-paper' : 'bg-slate-100'">
            <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
              <span class="font-medium text-slate-700">{{ m.nom }}</span>
              <span>{{ heureFrCourte(m.date) }}</span>
            </div>
            <div class="text-slate-800">{{ m.contenu }}</div>
          </div>
        </div>
        <form class="flex gap-2 border-t border-slate-100 p-3" @submit.prevent="envoyerMessage">
          <input v-model="nouveauMessage" type="text" maxlength="1000" placeholder="Écrire un message…" class="flex-1 sc-input" />
          <button type="submit" :disabled="envoiMessage || !nouveauMessage.trim()" class="rounded-lg bg-alag-navy px-4 py-2 text-sm font-medium text-white hover:bg-alag-navy-dark disabled:opacity-50">
            Envoyer
          </button>
        </form>
      </div>
    </section>
  </div>
</template>
