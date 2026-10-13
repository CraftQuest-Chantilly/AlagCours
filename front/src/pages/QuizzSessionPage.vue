<script setup>
// Détail d'un quizz noté : une question à la fois n'est pas imposé côté base (chaque réponse est
// enregistrée indépendamment, cf. api/routes/quizz.php), donc toutes les questions sont affichées
// sur une seule page, chacune sauvegardée dès l'interaction (pas de bouton « valider » global).
// Correction (bonne/mauvaise réponse) : jamais affichée tant que le formateur n'a pas validé les
// notes (valideNote) — reproduit fidèlement le comportement des RPC Supabase d'origine.
import { onMounted, ref } from 'vue'
import { api } from '@/services/api'

const props = defineProps({ code: { type: String, required: true }, qprom: { type: String, required: true } })

const chargement = ref(true)
const erreur = ref('')
const quizz = ref(null)
const questions = ref([])
const enregistrement = ref({}) // { [sqq]: 'ok' | 'erreur' | undefined }
const texteLibre = ref({}) // { [sqq]: string } — brouillon local avant envoi (sur blur)

async function charger() {
  chargement.value = true
  erreur.value = ''
  try {
    const r = await api(`/formations/${props.code}/quizz/${props.qprom}`)
    quizz.value = r.quizz
    questions.value = r.questions
    for (const q of questions.value) {
      const rl = q.reponses.find((x) => x.texte !== null && x.texte !== undefined)
      if (rl) texteLibre.value[q.sqq] = rl.texte
    }
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
}

async function enregistrer(sqq, corps) {
  enregistrement.value[sqq] = undefined
  try {
    await api(`/formations/${props.code}/quizz/${props.qprom}/repondre`, { method: 'POST', body: { sqq, ...corps } })
    enregistrement.value[sqq] = 'ok'
  } catch (e) {
    enregistrement.value[sqq] = 'erreur'
    erreur.value = e.message
  }
}

function basculerChoix(q, rep) {
  if (quizz.value.valideNote) return
  rep.choisi = !rep.choisi
  const choix = q.reponses.filter((r) => r.choisi).map((r) => Number(r.sqr))
  enregistrer(q.sqq, { choix })
}

function envoyerTexteLibre(q) {
  if (quizz.value.valideNote) return
  enregistrer(q.sqq, { repLibre: texteLibre.value[q.sqq] || '' })
}

onMounted(charger)
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'quizz', params: { code } }" class="text-sm text-alag-navy hover:underline">‹ Retour aux quizz</RouterLink>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <template v-else-if="quizz">
      <h1 class="text-xl font-semibold text-alag-navy">{{ quizz.nom }}</h1>
      <p v-if="quizz.valideNote" class="rounded-lg bg-paper px-3 py-2 text-sm text-alag-navy">
        Ce quizz est corrigé. Barème : {{ quizz.noteSur }}.
      </p>
      <p v-else class="text-sm text-slate-500">Vos réponses sont enregistrées automatiquement. La correction s'affichera une fois le quizz validé par votre formateur.</p>

      <section class="space-y-3">
        <div v-for="q in questions" :key="q.sqq" class="sc-card p-4">
          <div class="mb-2 flex items-start justify-between gap-2">
            <p class="text-sm font-medium text-slate-900">{{ q.libelle }}</p>
            <!-- Les questions à réponse libre ne sont jamais corrigées automatiquement (pas de bonne
                 réponse en base) : correction manuelle par le formateur, pas encore construite. -->
            <span v-if="quizz.valideNote && !q.repLibre" class="shrink-0 text-xs font-medium" :class="q.repOk ? 'text-green-700' : 'text-red-700'">
              {{ q.repOk ? '✓ Correct' : '✗ Incorrect' }}
            </span>
            <span v-else-if="enregistrement[q.sqq] === 'ok'" class="shrink-0 text-xs text-green-700">Enregistré ✓</span>
            <span v-else-if="enregistrement[q.sqq] === 'erreur'" class="shrink-0 text-xs text-red-700">Échec de l'enregistrement</span>
          </div>

          <textarea v-if="q.repLibre" v-model="texteLibre[q.sqq]" rows="2" maxlength="100" :disabled="quizz.valideNote"
                    class="w-full sc-input disabled:bg-slate-50"
                    placeholder="Votre réponse…" @blur="envoyerTexteLibre(q)" />

          <div v-else class="flex flex-wrap gap-2">
            <button v-for="rep in q.reponses" :key="rep.sqr" type="button" :disabled="quizz.valideNote"
                    class="rounded-full border px-3 py-1.5 text-sm transition disabled:cursor-default"
                    :class="[
                      rep.choisi ? 'border-alag-navy bg-alag-navy text-white' : 'border-slate-200 text-slate-600 hover:border-alag-navy',
                      quizz.valideNote && rep.correcte ? '!border-green-600 !bg-green-600 !text-white' : '',
                      quizz.valideNote && rep.choisi && !rep.correcte ? '!border-red-600 !bg-red-600 !text-white' : ''
                    ]"
                    @click="basculerChoix(q, rep)">
              {{ rep.libelle }}
            </button>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
