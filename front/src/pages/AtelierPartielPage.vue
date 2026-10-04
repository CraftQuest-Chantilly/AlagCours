<script setup>
// Atelier SQL — passage d'un partiel : chronométré (fenêtre debut/fin fixée par le formateur) et
// limité en tentatives par question. Chaque question est corrigée immédiatement (comparaison
// tolérante à l'ordre avec la requête de référence, côté serveur) — pas de "soumission finale"
// groupée, chaque tentative est enregistrée au fil de l'eau.
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { api } from '@/services/api'

const props = defineProps({ code: { type: String, required: true }, numPartiel: { type: String, required: true } })

const partiel = ref(null)
const questions = ref([])
const chargement = ref(true)
const erreur = ref('')

// État local par question : requête tapée, résultat de la dernière tentative, envoi en cours.
const etatQuestions = ref({})

const maintenant = ref(Date.now())
let minuteur = null

function etatQ(numQuestion) {
  if (!etatQuestions.value[numQuestion]) etatQuestions.value[numQuestion] = { requete: '', envoi: false, correcte: null, erreur: '' }
  return etatQuestions.value[numQuestion]
}

const tempsRestant = computed(() => {
  if (!partiel.value?.fin) return null
  const ms = new Date(partiel.value.fin).getTime() - maintenant.value
  if (ms <= 0) return '00:00'
  const s = Math.floor(ms / 1000)
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`
})

async function charger() {
  chargement.value = true
  erreur.value = ''
  try {
    const r = await api(`/formations/${props.code}/atelier/partiels/${props.numPartiel}`)
    partiel.value = r.partiel
    questions.value = r.questions
    for (const q of r.questions) {
      etatQuestions.value[q.numQuestion] = { requete: '', envoi: false, correcte: q.dejaReussi, erreur: '' }
    }
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
}

onMounted(() => {
  charger()
  minuteur = setInterval(() => { maintenant.value = Date.now() }, 1000)
})
onUnmounted(() => { if (minuteur) clearInterval(minuteur) })

async function repondre(q) {
  const e = etatQ(q.numQuestion)
  if (!e.requete.trim() || e.envoi) return
  e.envoi = true
  e.erreur = ''
  try {
    const r = await api(`/formations/${props.code}/atelier/partiels/${props.numPartiel}/questions/${q.numQuestion}/repondre`, {
      method: 'POST', body: { requete: e.requete }
    })
    e.correcte = r.correcte
    e.erreur = r.erreur || ''
    q.tentatives++
    if (r.correcte) q.dejaReussi = true
  } catch (err) {
    e.erreur = err.message
  } finally {
    e.envoi = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'atelier', params: { code } }" class="text-sm text-alag-blue-dark hover:underline">‹ Retour à l'atelier SQL</RouterLink>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <template v-else-if="partiel">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-semibold text-alag-navy">{{ partiel.titre }}</h1>
        <span v-if="partiel.dansFenetre && tempsRestant" class="rounded-full bg-amber-100 px-3 py-1 text-sm font-mono font-semibold text-amber-800">
          ⏱ {{ tempsRestant }}
        </span>
      </div>

      <p v-if="!partiel.dansFenetre" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
        Ce partiel n'est pas ouvert actuellement. Vos réponses ne peuvent pas être envoyées.
      </p>

      <section v-for="q in questions" :key="q.numQuestion" class="sc-card space-y-2 p-4">
        <div class="flex items-start justify-between gap-2">
          <p class="text-sm font-medium text-slate-800">{{ q.ordre + 1 }}. {{ q.consigne }}</p>
          <span class="shrink-0 text-xs text-slate-500">{{ q.bareme }} pt{{ q.bareme > 1 ? 's' : '' }}</span>
        </div>

        <textarea v-model="etatQ(q.numQuestion).requete" rows="3" spellcheck="false"
                  class="block w-full rounded-lg border-slate-300 font-mono text-sm"
                  :disabled="!partiel.dansFenetre || q.tentatives >= partiel.tentativesMax"
                  placeholder="select …"></textarea>

        <div class="flex items-center justify-between gap-2">
          <button type="button" class="rounded-lg bg-alag-blue-dark px-3 py-1.5 text-xs font-medium text-white hover:bg-[#0c4a68] disabled:opacity-50"
                  :disabled="!partiel.dansFenetre || etatQ(q.numQuestion).envoi || !etatQ(q.numQuestion).requete.trim() || q.tentatives >= partiel.tentativesMax"
                  @click="repondre(q)">
            {{ etatQ(q.numQuestion).envoi ? 'Envoi…' : 'Valider' }}
          </button>
          <span class="text-xs text-slate-500">Tentative {{ q.tentatives }} / {{ partiel.tentativesMax }}</span>
        </div>

        <p v-if="q.dejaReussi" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">✓ Bonne réponse.</p>
        <template v-else>
          <p v-if="etatQ(q.numQuestion).correcte === false && etatQ(q.numQuestion).erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ etatQ(q.numQuestion).erreur }}
          </p>
          <p v-else-if="etatQ(q.numQuestion).correcte === false" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
            Résultat incorrect.
          </p>
          <p v-if="q.tentatives >= partiel.tentativesMax && !q.dejaReussi" class="text-xs text-slate-500">
            Nombre maximum de tentatives atteint pour cette question.
          </p>
        </template>
      </section>
    </template>
  </div>
</template>
