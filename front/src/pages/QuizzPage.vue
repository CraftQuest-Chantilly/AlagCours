<script setup>
// Remplace la partie stagiaire du QCM noté d'al-cours (système T_QuizzPromo, jamais accessible
// depuis aucune page de l'ancien site). Liste des quizz assignés au stagiaire pour cette
// formation. Tant qu'aucun quizz n'a été planifié/assigné par un formateur, la liste reste vide
// (normal : cette partie formateur n'est pas encore construite).
import { onMounted, ref } from 'vue'
import { api } from '@/services/api'
import { dateFr } from '@/utils'

const props = defineProps({ code: { type: String, required: true } })

const quizz = ref([])
const chargement = ref(true)
const erreur = ref('')

async function charger() {
  chargement.value = true
  erreur.value = ''
  try {
    quizz.value = await api(`/formations/${props.code}/quizz`)
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
}

onMounted(charger)
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'formation', params: { code } }" class="text-sm text-alag-navy hover:underline">‹ Retour à la formation</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Quizz</h1>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <section v-else class="sc-card overflow-hidden">
      <p v-if="!quizz.length" class="p-4 text-sm text-slate-600">Aucun quizz n'est disponible pour votre formation pour le moment.</p>
      <ul v-else class="divide-y divide-slate-100 text-sm">
        <li v-for="q in quizz" :key="q.codeQProm">
          <RouterLink :to="{ name: 'quizz-session', params: { code, qprom: q.codeQProm } }"
                      class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-paper">
            <span>
              <span class="font-medium text-slate-900">{{ q.nom }}</span>
              <span v-if="q.date" class="ml-2 text-xs text-slate-500">{{ dateFr(q.date) }}<template v-if="q.heureDebut"> · {{ q.heureDebut }}–{{ q.heureFin }}</template></span>
            </span>
            <span v-if="q.valideNote" class="text-xs font-medium text-alag-navy">Note : {{ q.note }} / {{ q.nbQuestions }}</span>
            <span v-else class="text-xs text-slate-500">{{ q.nbQuestions }} question{{ q.nbQuestions > 1 ? 's' : '' }} · en attente de correction</span>
          </RouterLink>
        </li>
      </ul>
    </section>
  </div>
</template>
