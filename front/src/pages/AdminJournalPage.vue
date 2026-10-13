<script setup>
// Journal des connexions (formateur) — équivalent "LogCnx" du BackOffice de l'ancien SqlRA.
// Lecture seule de TS_LOG (déjà alimenté à chaque connexion/échec, cf. auth.php). Pas de notion
// fiable de "connecté maintenant" dans le nouveau portail (voir commentaire de route_admin_journal) :
// c'est un journal d'évènements, pas un moniteur temps réel.
import { onMounted, ref, watch } from 'vue'
import { api } from '@/services/api'

const identifiant = ref('')
const statut = ref('')
const lignes = ref([])
const chargement = ref(true)
const erreur = ref('')
let debounce = null

async function charger() {
  chargement.value = true
  erreur.value = ''
  try {
    lignes.value = await api('/admin/journal', { params: { identifiant: identifiant.value, statut: statut.value, limite: 200 } })
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
}

onMounted(charger)
watch([identifiant, statut], () => {
  clearTimeout(debounce)
  debounce = setTimeout(charger, 300)
})

function heureFr(date, heure) {
  if (!date) return ''
  return `${date.split('-').reverse().join('/')} ${(heure || '').slice(0, 8)}`
}
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'formateur-accueil' }" class="text-sm text-alag-navy hover:underline">‹ Accueil formateur</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Journal des connexions</h1>
    <p class="text-xs text-slate-500">
      200 derniers évènements. Pas de statut « connecté maintenant » fiable dans le nouveau portail (l'ancien ne l'exploitait pas non plus de façon systématique) : c'est un historique, pas un moniteur temps réel.
    </p>

    <div class="grid gap-3 sm:grid-cols-2">
      <label class="block text-sm font-medium text-slate-700">
        Identifiant
        <input v-model="identifiant" type="text" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" placeholder="Filtrer par identifiant…" />
      </label>
      <label class="block text-sm font-medium text-slate-700">
        Statut
        <select v-model="statut" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
          <option value="">Tous</option>
          <option value="Co">Connexions réussies</option>
          <option value="Echec_Co">Échecs</option>
        </select>
      </label>
    </div>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>

    <section class="sc-card overflow-hidden">
      <p v-if="chargement" class="p-4 text-sm text-slate-500">Chargement…</p>
      <p v-else-if="!lignes.length" class="p-4 text-sm text-slate-600">Aucun évènement pour ce filtre.</p>
      <table v-else class="w-full text-sm">
        <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-3 py-2">Date</th>
            <th class="px-3 py-2">Identifiant</th>
            <th class="px-3 py-2">Statut</th>
            <th class="px-3 py-2">IP</th>
            <th class="px-3 py-2">Info</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="l in lignes" :key="l.id">
            <td class="px-3 py-2 font-mono text-xs text-slate-600">{{ heureFr(l.date, l.heure) }}</td>
            <td class="px-3 py-2">{{ l.identifiant }}</td>
            <td class="px-3 py-2">
              <span :class="l.statut === 'Co' ? 'text-emerald-700' : 'text-red-600'">{{ l.statut === 'Co' ? 'Connexion' : 'Échec' }}</span>
            </td>
            <td class="px-3 py-2 font-mono text-xs text-slate-500">{{ l.ip }}</td>
            <td class="px-3 py-2 text-xs text-slate-500">{{ l.info }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
