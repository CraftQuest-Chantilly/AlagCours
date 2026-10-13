<script setup>
import Icone from '@/components/Icone.vue'
// Atelier SQL — banque de consignes (remplace le vrai fonctionnement de PAGE_CodeSQL/PAGE__Accueil
// de l'ancien SqlRA) : liste d'exercices permanents posés par le formateur, résolus au rythme du
// stagiaire, avec stats persos. Distinct du partiel chronométré (AtelierPartielPage.vue).
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/services/api'

const props = defineProps({ code: { type: String, required: true } })

const bases = ref([])
const baseChoisie = ref('')
const consignes = ref([])
const stats = ref(null)
const chargement = ref(true)
const erreur = ref('')

const consigneOuverte = ref(null) // numConsigne
const requete = ref('')
const resultat = ref(null) // { correcte, erreur }
const enCours = ref(false)
const historique = ref([])

async function chargerConsignesEtStats() {
  if (!baseChoisie.value) return
  try {
    const [c, s] = await Promise.all([
      api('/atelier/consignes', { params: { base: baseChoisie.value } }),
      api('/atelier/stats', { params: { base: baseChoisie.value } }),
    ])
    consignes.value = c
    stats.value = s
  } catch (e) {
    erreur.value = e.message
  }
}

onMounted(async () => {
  try {
    bases.value = await api('/atelier/bases')
    if (bases.value.length) baseChoisie.value = bases.value[0].code
    await chargerConsignesEtStats()
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
})

watch(baseChoisie, () => { consigneOuverte.value = null; chargerConsignesEtStats() })

async function ouvrirConsigne(c) {
  if (consigneOuverte.value === c.numConsigne) { consigneOuverte.value = null; return }
  consigneOuverte.value = c.numConsigne
  requete.value = ''
  resultat.value = null
  historique.value = []
  try {
    historique.value = await api(`/atelier/consignes/${c.numConsigne}/tentatives`)
  } catch (e) {
    erreur.value = e.message
  }
}

async function soumettre(numConsigne) {
  if (!requete.value.trim()) return
  enCours.value = true
  resultat.value = null
  try {
    const r = await api(`/atelier/consignes/${numConsigne}/repondre`, { method: 'POST', body: { requete: requete.value } })
    resultat.value = r
    historique.value = await api(`/atelier/consignes/${numConsigne}/tentatives`)
    // Rafraîchit l'état "déjà réussi" de la liste sans tout recharger.
    const c = consignes.value.find((x) => x.numConsigne === numConsigne)
    if (c) { c.tentatives = (c.tentatives || 0) + 1; if (r.correcte) c.dejaReussi = true }
    if (r.correcte) stats.value = await api('/atelier/stats', { params: { base: baseChoisie.value } })
  } catch (e) {
    erreur.value = e.message
  } finally {
    enCours.value = false
  }
}

const progression = computed(() => {
  if (!stats.value || !stats.value.consignesTotal) return null
  return Math.round((stats.value.consignesReussies / stats.value.consignesTotal) * 100)
})
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'atelier', params: { code } }" class="text-sm text-alag-navy hover:underline">‹ Retour à l'atelier SQL</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Consignes</h1>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <label v-if="bases.length > 1" class="block text-sm font-medium text-slate-700">
        Base de données
        <select v-model="baseChoisie" class="mt-1 block w-full sc-input sm:w-64">
          <option v-for="b in bases" :key="b.code" :value="b.code">{{ b.nom }}</option>
        </select>
      </label>

      <section v-if="stats" class="sc-card grid grid-cols-3 gap-3 p-4 text-center">
        <div>
          <div class="text-2xl font-semibold text-alag-navy">{{ stats.essaisTotal }}</div>
          <div class="text-xs text-slate-500">Essais</div>
        </div>
        <div>
          <div class="text-2xl font-semibold text-emerald-700">{{ stats.consignesReussies }}</div>
          <div class="text-xs text-slate-500">Réussies</div>
        </div>
        <div>
          <div class="text-2xl font-semibold text-slate-700">{{ progression ?? 0 }}%</div>
          <div class="text-xs text-slate-500">sur {{ stats.consignesTotal }} consigne{{ stats.consignesTotal > 1 ? 's' : '' }}</div>
        </div>
      </section>

      <p v-if="!consignes.length" class="sc-card p-4 text-sm text-slate-600">Aucune consigne disponible pour cette base pour le moment.</p>

      <div v-else class="space-y-2">
        <div v-for="c in consignes" :key="c.numConsigne" class="sc-card overflow-hidden">
          <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-paper" @click="ouvrirConsigne(c)">
            <Icone :nom="c.dejaReussi ? 'ok' : c.tentatives ? 'partiel' : 'vide'" class="h-5 w-5 shrink-0" :class="c.dejaReussi ? 'text-emerald-700' : c.tentatives ? 'text-accent' : 'text-slate-400'" />
            <span class="flex-1 text-sm text-slate-900">{{ c.consigne }}</span>
            <span v-if="c.tentatives" class="text-xs text-slate-500">{{ c.tentatives }} essai{{ c.tentatives > 1 ? 's' : '' }}</span>
          </button>

          <div v-if="consigneOuverte === c.numConsigne" class="space-y-3 border-t border-slate-100 p-4">
            <textarea v-model="requete" rows="4" spellcheck="false"
                      class="block w-full sc-input font-mono"
                      placeholder='select * from "T_Produit"'></textarea>
            <button type="button" class="rounded-lg bg-alag-navy px-4 py-2 text-sm font-medium text-white hover:bg-alag-navy-dark disabled:opacity-50"
                    :disabled="enCours || !requete.trim()" @click="soumettre(c.numConsigne)">
              {{ enCours ? 'Vérification…' : 'Valider' }}
            </button>

            <p v-if="resultat?.correcte" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Bonne réponse !</p>
            <p v-else-if="resultat && !resultat.correcte && !resultat.erreur" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">Résultat différent de celui attendu. Réessayez.</p>
            <p v-else-if="resultat?.erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ resultat.erreur }}</p>

            <details v-if="historique.length">
              <summary class="cursor-pointer text-xs font-medium text-alag-navy">Historique de vos essais ({{ historique.length }})</summary>
              <ul class="mt-2 space-y-1">
                <li v-for="(h, i) in historique" :key="i" class="rounded border border-slate-200 p-2 font-mono text-xs">
                  <span :class="h.correcte ? 'text-emerald-700' : 'text-red-600'">{{ h.correcte ? '✓' : '✗' }}</span>
                  {{ h.requete }}
                </li>
              </ul>
            </details>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
