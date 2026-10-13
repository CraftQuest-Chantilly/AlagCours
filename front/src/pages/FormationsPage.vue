<script setup>
// Remplace PAGE__ChoixCours d'al-cours : « Choisir un cours » (tableau des formations + agenda des séances).
import { nextTick, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/services/api'
import { dateFr } from '@/utils'
import AgendaSeances from '@/components/AgendaSeances.vue'

const router = useRouter()
const formations = ref([])
const seances = ref([])
const chargement = ref(true)
const erreur = ref('')
const agenda = ref(null)

onMounted(async () => {
  try {
    ;[formations.value, seances.value] = await Promise.all([api('/formations'), api('/agenda')])
    chargement.value = false
    await nextTick()
    agenda.value?.allerAuPlusPertinent()
  } catch (e) {
    erreur.value = e.message
    chargement.value = false
  }
})

const ouvrir = (code) => router.push({ name: 'formation', params: { code } })
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-xl font-semibold text-alag-navy">Mes formations</h1>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <section class="sc-card overflow-hidden">
        <p v-if="!formations.length" class="p-4 text-sm text-slate-600">Aucune formation active n'est associée à votre compte.</p>

        <!-- Tableau (écran large) -->
        <table v-else class="hidden w-full text-sm md:table">
          <thead class="bg-alag-navy text-left text-white">
            <tr>
              <th class="px-3 py-2 font-medium">Libellé</th>
              <th class="px-3 py-2 font-medium">Sujet</th>
              <th class="px-3 py-2 font-medium">V</th>
              <th class="px-3 py-2 font-medium">Niveau</th>
              <th class="px-3 py-2 text-right font-medium">Jours</th>
              <th class="px-3 py-2 font-medium">Du</th>
              <th class="px-3 py-2 font-medium">Au</th>
              <th class="px-3 py-2"><span class="sr-only">Ouvrir</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in formations" :key="f.code" class="cursor-pointer border-t border-slate-100 hover:bg-paper" @click="ouvrir(f.code)">
              <td class="px-3 py-2 font-medium text-slate-900">{{ f.libelle }}</td>
              <td class="px-3 py-2">{{ f.sujet }}</td>
              <td class="px-3 py-2">{{ f.version }}</td>
              <td class="px-3 py-2">{{ f.niveau }}</td>
              <td class="px-3 py-2 text-right">{{ f.nbJours }}</td>
              <td class="px-3 py-2">{{ dateFr(f.du) }}</td>
              <td class="px-3 py-2">{{ dateFr(f.au) }}</td>
              <td class="px-3 py-2 text-right"><button type="button" class="sc-btn-primary px-3 py-1" @click.stop="ouvrir(f.code)">Ouvrir</button></td>
            </tr>
          </tbody>
        </table>

        <!-- Cartes (mobile) -->
        <ul class="divide-y divide-slate-100 md:hidden">
          <li v-for="f in formations" :key="f.code">
            <button type="button" class="w-full px-4 py-3 text-left hover:bg-paper" @click="ouvrir(f.code)">
              <span class="block font-medium text-slate-900">{{ f.libelle }}</span>
              <span class="block text-sm text-slate-600">{{ f.sujet }} {{ f.version }} · niveau {{ f.niveau || '—' }}</span>
              <span class="block text-sm text-slate-500">Du {{ dateFr(f.du) }} au {{ dateFr(f.au) }} · {{ f.nbJours }} j</span>
            </button>
          </li>
        </ul>
      </section>

      <section class="sc-card p-4">
        <h2 class="mb-3 text-base font-semibold text-alag-navy">Agenda des séances</h2>
        <AgendaSeances ref="agenda" :seances="seances" @choisir="ouvrir" />
      </section>
    </template>
  </div>
</template>
