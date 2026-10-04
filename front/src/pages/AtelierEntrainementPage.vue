<script setup>
// Atelier SQL — entraînement libre. Base commune à toutes les promos (pas de scoping), lecture
// seule (SELECT uniquement, imposé côté serveur par sc_sql_atelier_executer). Le stagiaire choisit
// une base, consulte son schéma, écrit une requête et voit le résultat (200 lignes max, imposé
// côté serveur).
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/services/api'

// "code" (formation) absent = accès formateur direct (hors contexte d'une formation), cf. E8quater.
const props = defineProps({ code: { type: String, default: '' } })

const bases = ref([])
const baseChoisie = ref('')
const tables = ref([])
const schemaOuvert = ref(false)

const requete = ref('')
const lignes = ref(null)
const erreurRequete = ref('')
const enCours = ref(false)

const erreurChargement = ref('')
const chargement = ref(true)

const colonnes = computed(() => (lignes.value && lignes.value.length ? Object.keys(lignes.value[0]) : []))

async function chargerSchema(base) {
  if (!base) { tables.value = []; return }
  try {
    const r = await api('/atelier/schema', { params: { base } })
    tables.value = r.tables || []
  } catch (e) {
    erreurChargement.value = e.message
  }
}

onMounted(async () => {
  try {
    bases.value = await api('/atelier/bases')
    if (bases.value.length) {
      baseChoisie.value = bases.value[0].code
      await chargerSchema(baseChoisie.value)
    }
  } catch (e) {
    erreurChargement.value = e.message
  } finally {
    chargement.value = false
  }
})

watch(baseChoisie, (b) => { lignes.value = null; erreurRequete.value = ''; chargerSchema(b) })

async function executer() {
  if (!requete.value.trim()) return
  enCours.value = true
  erreurRequete.value = ''
  lignes.value = null
  try {
    const r = await api('/atelier/executer', { method: 'POST', body: { base: baseChoisie.value, requete: requete.value } })
    lignes.value = r.lignes || []
  } catch (e) {
    erreurRequete.value = e.message
  } finally {
    enCours.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <RouterLink v-if="code" :to="{ name: 'atelier', params: { code } }" class="text-sm text-alag-blue-dark hover:underline">‹ Retour à l'atelier SQL</RouterLink>
    <RouterLink v-else :to="{ name: 'formateur-accueil' }" class="text-sm text-alag-blue-dark hover:underline">‹ Accueil formateur</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Entraînement libre{{ code ? '' : ' — vue formateur' }}</h1>

    <p v-if="erreurChargement" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurChargement }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <section class="sc-card space-y-3 p-4">
        <label class="block text-sm font-medium text-slate-700">
          Base de données
          <select v-model="baseChoisie" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            <option v-for="b in bases" :key="b.code" :value="b.code">{{ b.nom }}</option>
          </select>
        </label>
        <p v-if="bases.find((b) => b.code === baseChoisie)?.description" class="text-xs text-slate-500">
          {{ bases.find((b) => b.code === baseChoisie)?.description }}
        </p>

        <details :open="schemaOuvert" @toggle="schemaOuvert = $event.target.open">
          <summary class="cursor-pointer text-xs font-medium text-alag-blue-dark">Voir le schéma ({{ tables.length }} table{{ tables.length > 1 ? 's' : '' }})</summary>
          <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="t in tables" :key="t.table_name" class="rounded-lg border border-slate-200 p-2">
              <div class="mb-1 font-mono text-xs font-semibold text-alag-navy">{{ t.table_name }}</div>
              <ul class="space-y-0.5">
                <li v-for="c in t.colonnes" :key="c.colonne" class="font-mono text-xs text-slate-600">
                  {{ c.colonne }} <span class="text-slate-400">{{ c.type }}</span>
                </li>
              </ul>
            </div>
          </div>
        </details>
      </section>

      <section class="sc-card space-y-3 p-4">
        <label class="block text-sm font-medium text-slate-700">
          Votre requête (SELECT uniquement)
          <textarea v-model="requete" rows="5" spellcheck="false"
                    class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-sm"
                    placeholder='select * from "T_Produit"'></textarea>
        </label>
        <button type="button" class="rounded-lg bg-alag-blue-dark px-4 py-2 text-sm font-medium text-white hover:bg-[#0c4a68] disabled:opacity-50"
                :disabled="enCours || !requete.trim()" @click="executer">
          {{ enCours ? 'Exécution…' : 'Exécuter' }}
        </button>

        <p v-if="erreurRequete" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurRequete }}</p>

        <div v-if="lignes" class="overflow-x-auto">
          <p v-if="!lignes.length" class="text-sm text-slate-600">Aucune ligne renvoyée.</p>
          <table v-else class="min-w-full divide-y divide-slate-200 text-sm">
            <thead>
              <tr>
                <th v-for="c in colonnes" :key="c" class="px-3 py-1.5 text-left font-mono text-xs font-semibold text-slate-500">{{ c }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(l, i) in lignes" :key="i">
                <td v-for="c in colonnes" :key="c" class="px-3 py-1.5 font-mono text-xs text-slate-700">{{ l[c] }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="lignes.length === 200" class="mt-2 text-xs text-slate-500">Affichage limité aux 200 premières lignes.</p>
        </div>
      </section>
    </template>
  </div>
</template>
