<script setup>
// Remplace PAGE_Evaluation d'al-cours : évaluation de fin de formation (système "Eval" de
// T_Quizz / T_Quizz_Eval_Rep — distinct du QCM noté T_QuizzPromo, voir QuizzPage.vue).
// Un stagiaire ne répond qu'une fois par promo ; en cas de nouvelle visite, ses réponses
// s'affichent en lecture seule.
//
// Révision du 30/09/2026 (E12, demande Jérôme) : les 5 boutons Insuffisant…Excellent sont
// remplacés par une note libre sur l'échelle réelle (0..noteSur, actuellement 0..100) et une
// zone de commentaire PAR question (au lieu d'une seule remarque générale rattachée à la
// dernière question).
import { computed, onMounted, ref } from 'vue'
import { api } from '@/services/api'

const props = defineProps({ code: { type: String, required: true } })

const chargement = ref(true)
const erreur = ref('')
const envoiEnCours = ref(false)
const noteSur = ref(100)
const questions = ref([])
const dejaRepondu = ref(false)
const reponsesExistantes = ref([])
const valeursSaisies = ref({}) // { [codeQuest]: number|null }
const remarquesSaisies = ref({}) // { [codeQuest]: string }
const envoye = ref(false)

function valeurExistante(codeQuest) {
  const r = reponsesExistantes.value.find((x) => x.codeQuest === codeQuest)
  return r ? r.valeur : null
}

function remarqueExistante(codeQuest) {
  const r = reponsesExistantes.value.find((x) => x.codeQuest === codeQuest)
  return r?.remarque || ''
}

const toutRepondu = computed(() =>
  questions.value.every((q) => {
    const v = valeursSaisies.value[q.code]
    return v !== undefined && v !== null && v !== '' && Number(v) >= 0 && Number(v) <= noteSur.value
  })
)

async function charger() {
  chargement.value = true
  erreur.value = ''
  try {
    const r = await api(`/formations/${props.code}/evaluation`)
    noteSur.value = r.noteSur
    questions.value = r.questions
    dejaRepondu.value = r.dejaRepondu
    reponsesExistantes.value = r.reponses || []
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
}

async function envoyer() {
  if (!toutRepondu.value) return
  envoiEnCours.value = true
  erreur.value = ''
  try {
    const valeurs = {}
    const remarques = {}
    for (const q of questions.value) {
      valeurs[q.code] = Math.round(Number(valeursSaisies.value[q.code]))
      remarques[q.code] = (remarquesSaisies.value[q.code] || '').trim()
    }
    await api(`/formations/${props.code}/evaluation`, { method: 'POST', body: { valeurs, remarques } })
    envoye.value = true
    await charger() // recharge les réponses telles qu'enregistrées (évite un état local désynchronisé)
  } catch (e) {
    erreur.value = e.message
  } finally {
    envoiEnCours.value = false
  }
}

onMounted(charger)
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'formation', params: { code } }" class="text-sm text-alag-navy hover:underline">‹ Retour à la formation</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Évaluation de la formation</h1>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <p v-if="envoye" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700">Merci, votre évaluation a bien été enregistrée.</p>
      <p v-else-if="dejaRepondu" class="rounded-lg bg-paper px-3 py-2 text-sm text-alag-navy">Vous avez déjà évalué cette formation. Voici vos réponses.</p>
      <p v-else class="text-sm text-slate-500">Notez chaque question sur {{ noteSur }} et ajoutez un commentaire si vous le souhaitez.</p>

      <section class="sc-card divide-y divide-slate-100">
        <div v-for="q in questions" :key="q.code" class="space-y-2 p-4">
          <p class="text-sm font-medium text-slate-900">{{ q.libelle }}</p>

          <template v-if="dejaRepondu">
            <p class="text-sm text-slate-600">Note : {{ valeurExistante(q.code) }} / {{ noteSur }}</p>
            <p v-if="remarqueExistante(q.code)" class="text-sm text-slate-500 italic">« {{ remarqueExistante(q.code) }} »</p>
          </template>
          <template v-else>
            <div class="flex items-center gap-2">
              <input
                v-model="valeursSaisies[q.code]"
                type="number" min="0" :max="noteSur" step="1"
                class="w-24 sc-input"
                :placeholder="`0-${noteSur}`"
              />
              <span class="text-sm text-slate-500">/ {{ noteSur }}</span>
            </div>
            <textarea
              v-model="remarquesSaisies[q.code]"
              rows="2"
              class="w-full sc-input"
              placeholder="Commentaire (facultatif)…"
            />
          </template>
        </div>
      </section>

      <button v-if="!dejaRepondu" type="button"
              class="rounded-lg bg-alag-navy px-4 py-2 text-sm font-medium text-white disabled:cursor-default disabled:opacity-50"
              :disabled="!toutRepondu || envoiEnCours"
              @click="envoyer">
        {{ envoiEnCours ? 'Envoi…' : 'Envoyer mon évaluation' }}
      </button>
    </template>
  </div>
</template>
