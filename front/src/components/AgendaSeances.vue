<script setup>
// Agenda des séances (repris de l'agenda AGD_LesTD de PAGE__ChoixCours) :
// vue Semaine (lundi→vendredi) ou Mois, navigation période précédente / suivante.
// Cliquer une séance émet « choisir » avec le code de la promo.
import { computed, ref } from 'vue'
import { isoLocal } from '@/utils'

const props = defineProps({ seances: { type: Array, default: () => [] } })
const emit = defineEmits(['choisir'])

const mode = ref('semaine')
const reference = ref(new Date())
const aujourdhui = isoLocal(new Date())

function lundi(d) {
  const x = new Date(d.getFullYear(), d.getMonth(), d.getDate())
  x.setDate(x.getDate() - ((x.getDay() + 6) % 7))
  return x
}

const jours = computed(() => {
  if (mode.value === 'semaine') {
    const l = lundi(reference.value)
    return Array.from({ length: 5 }, (_, i) => { const d = new Date(l); d.setDate(l.getDate() + i); return d })
  }
  const premier = new Date(reference.value.getFullYear(), reference.value.getMonth(), 1)
  const debut = lundi(premier)
  return Array.from({ length: 42 }, (_, i) => { const d = new Date(debut); d.setDate(debut.getDate() + i); return d })
    .filter((d) => d.getDay() !== 0 && d.getDay() !== 6)
})

const parJour = computed(() => {
  const m = {}
  for (const s of props.seances) (m[s.date] ??= []).push(s)
  return m
})

const titre = computed(() => {
  if (mode.value === 'mois') return reference.value.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
  const j = jours.value
  const f = (d) => d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
  return `${f(j[0])} – ${f(j[4])} ${j[4].getFullYear()}`
})

function deplacer(sens) {
  const d = new Date(reference.value)
  if (mode.value === 'semaine') d.setDate(d.getDate() + 7 * sens)
  else d.setMonth(d.getMonth() + sens, 1)
  reference.value = d
}

// Positionne l'agenda sur la prochaine séance (ou la dernière passée) au premier affichage.
function allerAuPlusPertinent() {
  if (!props.seances.length) return
  const prochaine = props.seances.find((s) => s.date >= aujourdhui) ?? props.seances[props.seances.length - 1]
  const [a, m, j] = prochaine.date.split('-').map(Number)
  reference.value = new Date(a, m - 1, j)
}
defineExpose({ allerAuPlusPertinent })

const dansMois = (d) => mode.value === 'semaine' || d.getMonth() === reference.value.getMonth()
</script>

<template>
  <div>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <div class="flex items-center gap-1">
        <button type="button" class="sc-btn px-3" aria-label="Période précédente" @click="deplacer(-1)">‹</button>
        <button type="button" class="sc-btn px-3" @click="reference = new Date()">Aujourd'hui</button>
        <button type="button" class="sc-btn px-3" aria-label="Période suivante" @click="deplacer(1)">›</button>
        <span class="ml-2 text-sm font-semibold capitalize text-alag-navy">{{ titre }}</span>
      </div>
      <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm">
        <button type="button" class="px-3 py-1.5" :class="mode === 'semaine' ? 'bg-alag-navy text-white' : 'bg-white'" @click="mode = 'semaine'">Semaine</button>
        <button type="button" class="px-3 py-1.5" :class="mode === 'mois' ? 'bg-alag-navy text-white' : 'bg-white'" @click="mode = 'mois'">Mois</button>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-5">
      <div v-for="d in jours" :key="isoLocal(d)"
           class="min-h-[5rem] rounded-lg border p-2"
           :class="[isoLocal(d) === aujourdhui ? 'border-accent bg-accent-soft' : 'border-slate-200 bg-white', dansMois(d) ? '' : 'opacity-40', parJour[isoLocal(d)] ? '' : 'hidden sm:block']">
        <div class="mb-1 text-xs font-semibold text-slate-500">
          {{ d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: mode === 'semaine' ? 'short' : undefined }) }}
        </div>
        <button v-for="s in parJour[isoLocal(d)] ?? []" :key="s.code" type="button"
                class="mb-1 block w-full rounded-md bg-amber-100 px-2 py-1 text-left text-xs text-slate-800 hover:bg-amber-200"
                @click="emit('choisir', s.codePromo)">
          <span class="font-semibold">{{ s.debut }}–{{ s.fin }}</span>
          <span class="block truncate">{{ s.libelle }}</span>
          <span v-if="s.numTd" class="text-slate-500">TD {{ s.numTd }}</span>
        </button>
      </div>
    </div>
    <p v-if="!jours.some((d) => parJour[isoLocal(d)])" class="mt-2 text-sm text-slate-500">Aucune séance sur cette période.</p>
  </div>
</template>
