<script setup>
// Atelier SQL — « Mes stats » : les tuiles du stagiaire, comparées à sa promo et à la promo de
// référence choisie par le formateur. Promo et référence = moyennes PAR STAGIAIRE (jamais de noms) ;
// le serveur masque la comparaison sous 3 stagiaires et impose la promo du stagiaire connecté.
import { computed, onMounted, ref } from 'vue'
import { api } from '@/services/api'

const props = defineProps({ code: { type: String, required: true } })
const d = ref(null)
const erreur = ref('')
const chargement = ref(true)

onMounted(async () => {
  try { d.value = await api('/atelier/mes-stats', { params: { promo: props.code } }) }
  catch (e) { erreur.value = e.message }
  finally { chargement.value = false }
})

const moy = (s, n) => (s && s.nbStagiaires ? Math.round((n / s.nbStagiaires) * 10) / 10 : null)
const lignes = computed(() => {
  const m = d.value?.moi, p = d.value?.laPromo, r = d.value?.reference?.stats
  if (!m) return []
  const L = (titre, f) => ({ titre, moi: f(m), promo: p ? moy(p, f(p)) : null, ref: r ? moy(r, f(r)) : null })
  return [
    L('Essais au total', (s) => s.essaisTotal),
    L('Essais non notés — réussis', (s) => s.nonNotes.ok),
    L('Essais non notés — échoués', (s) => s.nonNotes.ko),
    L('Essais notés — réussis', (s) => s.notes.ok),
    L('Essais notés — échoués', (s) => s.notes.ko),
    L('Notés validés (note > 0)', (s) => s.valides.vert),
    L('Notés validés (note 0)', (s) => s.valides.rouge)
  ]
})
</script>

<template>
  <div class="space-y-6">
    <RouterLink :to="{ name: 'atelier', params: { code } }" class="text-sm text-alag-navy hover:underline">‹ Atelier SQL</RouterLink>
    <h1 class="text-xl font-semibold text-alag-navy">Mes stats</h1>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>
    <p v-else-if="!d?.moi" class="sc-card p-4 text-sm text-slate-600">Aucune statistique : l'Atelier SQL n'est pas activé pour votre promo.</p>

    <template v-else>
      <div class="sc-card p-4">
        <div class="text-xs font-semibold uppercase text-slate-500">Mes bonus</div>
        <div class="mt-1 text-2xl font-semibold">{{ d.moi.bonus.tentatives }}</div>
      </div>

      <section class="sc-card overflow-x-auto p-4">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
              <th class="py-1">Statistique</th><th class="text-right">Moi</th>
              <th class="text-right">Ma promo <span class="normal-case">(moy. / stagiaire)</span></th>
              <th v-if="d.reference" class="text-right">{{ d.reference.promo.libelle }} <span class="normal-case">(moy. / stagiaire)</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in lignes" :key="l.titre" class="border-t border-slate-100">
              <td class="py-2">{{ l.titre }}</td>
              <td class="text-right text-base font-semibold">{{ l.moi }}</td>
              <td class="text-right">{{ l.promo ?? '—' }}</td>
              <td v-if="d.reference" class="text-right">{{ l.ref ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="d.comparaisonMasquee" class="mt-3 text-xs text-slate-500">Comparaison indisponible : la promo compte trop peu de stagiaires pour rester anonyme.</p>
      </section>

      <section v-if="d.laPromo" class="sc-card overflow-x-auto p-4">
        <h2 class="mb-2 text-sm font-semibold text-alag-navy">Ma promo par groupe</h2>
        <table class="w-full text-sm">
          <thead><tr class="text-left text-xs uppercase text-slate-500"><th class="py-1">Groupe</th><th class="text-right">Réussis</th><th class="text-right">Échoués</th></tr></thead>
          <tbody><tr v-for="g in d.laPromo.parGroupe" :key="g.groupe" class="border-t border-slate-100"><td class="py-1">{{ g.groupe }}</td><td class="text-right">{{ g.ok }}</td><td class="text-right">{{ g.ko }}</td></tr></tbody>
        </table>
      </section>
    </template>
  </div>
</template>
