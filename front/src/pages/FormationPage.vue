<script setup>
// Remplace PAGE__Accueil d'al-cours : en-tête de la formation + modules.
// Un module n'apparaît que s'il est activé pour la promo (E9, 30/09/2026 : table
// T_Promo_Option_Reel, cases à cocher sur la fiche promo — remplace l'ancienne règle des
// marqueurs texte dans Pro_Doc1). Calculé côté API (route_formation()).
// Les modules non encore reconstruits sont affichés « bientôt disponible ».
import { computed, onMounted, ref } from 'vue'
import { api } from '@/services/api'
import { dateFr } from '@/utils'

const props = defineProps({ code: { type: String, required: true } })

const formation = ref(null)
const seances = ref([])
const erreur = ref('')
const chargement = ref(true)

onMounted(async () => {
  try {
    ;[formation.value, seances.value] = await Promise.all([
      api(`/formations/${props.code}`),
      api(`/formations/${props.code}/seances`)
    ])
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
})

// Ordre et libellés repris des boutons de PAGE__Accueil. `route` : nom de route Vue Router une
// fois l'écran reconstruit ; tant qu'absent, la tuile reste affichée mais non cliquable.
const CATALOGUE = [
  { cle: 'testPositionnement', titre: 'Test de positionnement', icone: '?', apresDebut: false },
  { cle: 'signature', titre: 'Signature feuille de présence', icone: '✍', apresDebut: false, route: 'signature' },
  { cle: 'ressources', titre: 'Fichiers, exercices, ressources', icone: '📁', apresDebut: false, route: 'ressources' },
  { cle: 'quizz', titre: 'Quizz', icone: '❓', apresDebut: true, route: 'quizz' },
  { cle: 'evaluation', titre: 'Évaluation de la formation', icone: '📝', apresDebut: true, route: 'evaluation' },
  { cle: 'avis', titre: 'Avis', icone: '★', apresDebut: false, route: 'avis' },
  { cle: 'atelier', titre: 'Atelier SQL', icone: '🗄', apresDebut: false, route: 'atelier' }
]

const modules = computed(() => CATALOGUE.filter((m) => formation.value?.modules?.[m.cle]))
</script>

<template>
  <div class="space-y-6">
    <RouterLink :to="{ name: 'formations' }" class="text-sm text-alag-blue-dark hover:underline">‹ Mes formations</RouterLink>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <template v-else-if="formation">
      <section class="sc-card grid gap-3 p-4 sm:grid-cols-3">
        <div>
          <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cours</div>
          <div class="font-semibold text-slate-900">{{ formation.libelle }} <span class="font-normal text-slate-600">{{ formation.sujet }}</span></div>
        </div>
        <div>
          <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Dates</div>
          <div>Du {{ dateFr(formation.du) }} au {{ dateFr(formation.au) }}</div>
        </div>
        <div>
          <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Groupe</div>
          <div>{{ formation.groupe || '—' }}</div>
        </div>
      </section>

      <section>
        <h2 class="mb-3 text-base font-semibold text-alag-navy">Modules</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <component
            :is="m.route && (!m.apresDebut || formation.commence) ? 'RouterLink' : 'div'"
            v-for="m in modules" :key="m.cle" data-test="module"
            :to="m.route ? { name: m.route, params: { code } } : undefined"
            class="flex items-center justify-between gap-3 rounded-xl bg-[#0f5a7d] px-4 py-4 text-white shadow-sm"
            :class="m.route && (!m.apresDebut || formation.commence) ? 'hover:bg-[#0c4a68]' : 'opacity-80'">
            <div>
              <div class="font-semibold">{{ m.titre }}</div>
              <div class="text-xs text-white/80">
                {{ m.apresDebut && !formation.commence ? 'Disponible quand la formation aura commencé' : (m.route ? '' : 'Bientôt disponible') }}
              </div>
            </div>
            <span class="text-2xl" aria-hidden="true">{{ m.icone }}</span>
          </component>
        </div>
      </section>

      <section class="sc-card overflow-hidden">
        <h2 class="px-4 pt-4 text-base font-semibold text-alag-navy">Séances</h2>
        <p v-if="!seances.length" class="p-4 text-sm text-slate-600">Aucune séance planifiée pour votre groupe.</p>
        <ul v-else class="mt-2 divide-y divide-slate-100 text-sm">
          <!-- « Points vus » (E5) : PAS le compte-rendu libre du formateur (usage interne),
               mais la liste des points cochés pour la séance (ex. « Les RECHERCHEV »).
               Replié par défaut, affiché seulement quand au moins un point est coché. -->
          <li v-for="s in seances" :key="s.code" class="px-4 py-2">
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
              <span class="w-40 font-medium capitalize">{{ dateFr(s.date, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' }) }}</span>
              <span class="w-24">{{ s.debut }}–{{ s.fin }}</span>
              <span v-if="s.numTd" class="w-20 text-slate-600">TD {{ s.numTd }}</span>
              <span class="text-slate-600">{{ s.salle }}</span>
              <span v-if="s.formateurPrenom || s.formateurNom" class="ml-auto text-xs text-slate-500">
                Formateur : {{ [s.formateurPrenom, s.formateurNom].filter(Boolean).join(' ') }}
              </span>
            </div>
            <details v-if="s.pointsVus?.length" class="mt-1">
              <summary class="cursor-pointer text-xs font-medium text-alag-blue-dark">
                Points vus pendant cette séance ({{ s.pointsVus.length }})
              </summary>
              <ul class="mt-1 flex flex-wrap gap-1.5">
                <li v-for="(pt, idx) in s.pointsVus" :key="idx"
                    class="rounded-full bg-alag-blue-light px-2.5 py-1 text-xs text-alag-navy">
                  {{ pt }}
                </li>
              </ul>
            </details>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
