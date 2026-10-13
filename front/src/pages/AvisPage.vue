<script setup>
// Nouvelle tuile "Avis" — n'existait pas fonctionnellement dans l'ancien al-cours (BTN_Avis avait
// un code navigateur vide). Contenu, visibilité et fréquence validés avec Jérôme le 25/09/2026 :
// 4 critères notés 1 à 5 + commentaire libre, anonyme pour le formateur, modifiable librement
// (pas de verrou comme pour EvaluationPage.vue).
import { computed, onMounted, ref } from 'vue'
import { api } from '@/services/api'

const props = defineProps({ code: { type: String, required: true } })

const CRITERES = [
  { cle: 'critContenu', libelle: 'Contenu de la formation' },
  { cle: 'critFormateur', libelle: 'Qualité du formateur' },
  { cle: 'critOrganisation', libelle: 'Organisation (accueil, salle, matériel)' },
  { cle: 'critRecommandation', libelle: 'Recommanderiez-vous cette formation ?' }
]

const chargement = ref(true)
const erreur = ref('')
const envoiEnCours = ref(false)
const envoye = ref(false)
const notes = ref({}) // { [cle]: 1..5 }
const commentaire = ref('')
const dejaDonne = ref(false)
const derniereMaj = ref(null)

const toutNote = computed(() => CRITERES.every((c) => notes.value[c.cle] >= 1))

async function charger() {
  chargement.value = true
  erreur.value = ''
  try {
    const r = await api(`/formations/${props.code}/avis`)
    if (r.avis) {
      dejaDonne.value = true
      derniereMaj.value = r.avis.dateModif
      for (const c of CRITERES) notes.value[c.cle] = r.avis[c.cle]
      commentaire.value = r.avis.commentaire || ''
    }
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
}

async function envoyer() {
  if (!toutNote.value) return
  envoiEnCours.value = true
  erreur.value = ''
  try {
    await api(`/formations/${props.code}/avis`, { method: 'POST', body: { ...notes.value, commentaire: commentaire.value.trim() } })
    envoye.value = true
    dejaDonne.value = true
    setTimeout(() => { envoye.value = false }, 3000)
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

    <h1 class="text-xl font-semibold text-alag-navy">Votre avis</h1>
    <p class="text-sm text-slate-500">Votre avis est anonyme pour le formateur. Vous pouvez le modifier à tout moment.</p>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <p v-if="envoye" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700">Merci, votre avis a bien été enregistré.</p>
      <p v-else-if="dejaDonne" class="rounded-lg bg-paper px-3 py-2 text-sm text-alag-navy">Vous avez déjà donné un avis pour cette formation. Vous pouvez le modifier ci-dessous.</p>

      <section class="sc-card divide-y divide-slate-100">
        <div v-for="c in CRITERES" :key="c.cle" class="p-4">
          <p class="mb-2 text-sm font-medium text-slate-900">{{ c.libelle }}</p>
          <div class="flex gap-1">
            <button v-for="n in [1, 2, 3, 4, 5]" :key="n" type="button"
                    class="text-2xl leading-none transition"
                    :class="(notes[c.cle] ?? 0) >= n ? 'text-amber-400' : 'text-slate-200 hover:text-amber-200'"
                    :aria-label="`${n} sur 5`"
                    @click="notes[c.cle] = n">★</button>
          </div>
        </div>

        <div class="p-4">
          <label class="mb-2 block text-sm font-medium text-slate-900" for="commentaire">Commentaire (facultatif)</label>
          <textarea id="commentaire" v-model="commentaire" rows="3"
                    class="w-full sc-input"
                    placeholder="Vos commentaires sur la formation…" />
        </div>
      </section>

      <button type="button"
              class="rounded-lg bg-alag-navy px-4 py-2 text-sm font-medium text-white disabled:cursor-default disabled:opacity-50"
              :disabled="!toutNote || envoiEnCours"
              @click="envoyer">
        {{ envoiEnCours ? 'Envoi…' : (dejaDonne ? 'Mettre à jour mon avis' : 'Envoyer mon avis') }}
      </button>
    </template>
  </div>
</template>
