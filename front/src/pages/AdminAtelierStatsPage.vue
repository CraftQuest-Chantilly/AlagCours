<script setup>
// Atelier SQL — correction des requêtes + statistiques (équivalent des écrans « tableau de bord » et
// « notation » de l'ancien SqlRA). Formateur : filtres promo / stagiaire / dates, tuiles, tricolore
// de note (rouge 0, orange 0,5, vert 1), Vu, Bonus, promo de référence, points ajoutés.
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/services/api'
import { dateFr } from '@/utils'

const annee = new Date().getFullYear()
const f = reactive({ promo: '', prenom: '', nom: '', groupe: '', du: `${annee}-01-01`, au: '', vu: '', echecs: false, notee: false })
const promos = ref([])
const stats = ref(null)
const reference = ref(null)
const requetes = ref([])
const bonus = ref([])
const chargement = ref(false)
const erreur = ref('')
const refChoisie = ref('')
const messageRef = ref('')

const promoCourante = computed(() => promos.value.find((p) => p.code === f.promo) || null)

function params() {
  const p = { promo: f.promo || undefined, du: f.du || undefined, au: f.au || undefined }
  return p
}
async function actualiser() {
  chargement.value = true
  erreur.value = ''
  try {
    const [s, r] = await Promise.all([
      api('/admin/atelier/stats', { params: params() }),
      api('/admin/atelier/requetes', {
        params: { ...params(), prenom: f.prenom || undefined, nom: f.nom || undefined, groupe: f.groupe || undefined,
                  vu: f.vu || undefined, echecs: f.echecs ? 1 : undefined, notee: f.notee ? 1 : undefined }
      })
    ])
    stats.value = s.stats
    reference.value = s.reference
    refChoisie.value = s.reference?.promo.code || ''
    requetes.value = r
    bonus.value = f.promo ? await api(`/admin/promos/${f.promo}/sql-bonus`) : []
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
}

onMounted(async () => {
  try { promos.value = await api('/admin/atelier/promos-sql') } catch (e) { erreur.value = e.message }
  actualiser()
})

async function modifier(q, champs) {
  const avant = { vu: q.vu, bonus: q.bonus, note: q.note }
  Object.assign(q, champs)
  try {
    await api(`/admin/atelier/requetes/${q.id}`, { method: 'PUT', body: champs })
    const s = await api('/admin/atelier/stats', { params: params() }) // tuiles à jour
    stats.value = s.stats
    if (f.promo) bonus.value = await api(`/admin/promos/${f.promo}/sql-bonus`)
  } catch (e) {
    Object.assign(q, avant)
    erreur.value = e.message
  }
}
const noter = (q, n) => modifier(q, { note: q.note === n ? null : n }) // re-cliquer retire la note

async function enregistrerReference() {
  messageRef.value = ''
  try {
    await api(`/admin/promos/${f.promo}/sql-reference`, { method: 'PUT', body: { promoRef: refChoisie.value || null } })
    messageRef.value = 'Promo de référence enregistrée.'
    actualiser()
  } catch (e) { messageRef.value = e.message }
}
async function enregistrerPoints(b) {
  try {
    await api(`/admin/promos/${f.promo}/sql-points/${b.codeStagiaire}`, { method: 'PUT', body: { points: Number(b.pointsAjoutes) } })
  } catch (e) { erreur.value = e.message }
}

const noteFinale = (b) => Number(b.sommeNotes) + Number(b.pointsAjoutes || 0)
const dt = (iso) => new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-xl font-semibold text-alag-navy">Atelier SQL — correction &amp; stats</h1>

    <form class="sc-card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="actualiser">
      <label class="text-sm font-medium text-slate-700 lg:col-span-2">Promo
        <select v-model="f.promo" class="sc-input mt-1 w-full">
          <option value="">Toutes les promos avec Atelier SQL</option>
          <option v-for="p in promos" :key="p.code" :value="p.code">{{ p.libelle }} {{ p.sujet }} ({{ dateFr(p.du) }})</option>
        </select>
      </label>
      <label class="text-sm font-medium text-slate-700">Du <input v-model="f.du" type="date" class="sc-input mt-1 w-full" /></label>
      <label class="text-sm font-medium text-slate-700">Au <input v-model="f.au" type="date" class="sc-input mt-1 w-full" /></label>
      <label class="text-sm font-medium text-slate-700">Prénom <input v-model="f.prenom" type="text" class="sc-input mt-1 w-full" /></label>
      <label class="text-sm font-medium text-slate-700">Nom <input v-model="f.nom" type="text" class="sc-input mt-1 w-full" /></label>
      <label class="text-sm font-medium text-slate-700">Groupe <input v-model="f.groupe" type="text" class="sc-input mt-1 w-full" /></label>
      <label class="text-sm font-medium text-slate-700">Voir
        <select v-model="f.vu" class="sc-input mt-1 w-full"><option value="">Tout</option><option value="0">Pas vu</option><option value="1">Vu</option></select>
      </label>
      <div class="flex items-center gap-4 text-sm lg:col-span-3">
        <label class="flex items-center gap-2"><input v-model="f.echecs" type="checkbox" class="h-4 w-4" /> Échecs seulement</label>
        <label class="flex items-center gap-2"><input v-model="f.notee" type="checkbox" class="h-4 w-4" /> Consignes notées seulement</label>
      </div>
      <button type="submit" class="sc-btn-primary px-4 py-2 text-sm" :disabled="chargement">Actualiser</button>
    </form>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>

    <!-- Tuiles -->
    <section v-if="stats" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" aria-label="Statistiques">
      <div class="sc-card p-4"><div class="text-xs font-semibold uppercase text-slate-500">Essais total</div><div class="mt-2 text-3xl font-semibold">{{ stats.essaisTotal }}</div><div class="text-xs text-slate-500">{{ stats.nbStagiaires }} stagiaire(s)</div></div>
      <div class="sc-card p-4"><div class="text-xs font-semibold uppercase text-slate-500">Essais non notés</div>
        <div class="mt-2 text-sm"><span class="mr-1 inline-block h-3 w-3 rounded-full bg-red-600"></span>KO <strong class="ml-1 text-2xl">{{ stats.nonNotes.ko }}</strong></div>
        <div class="text-sm"><span class="mr-1 inline-block h-3 w-3 rounded-full bg-emerald-600"></span>OK <strong class="ml-1 text-2xl">{{ stats.nonNotes.ok }}</strong></div></div>
      <div class="sc-card p-4"><div class="text-xs font-semibold uppercase text-slate-500">Essais notés</div>
        <div class="mt-2 text-sm"><span class="mr-1 inline-block h-3 w-3 rounded-full bg-red-600"></span>KO <strong class="ml-1 text-2xl">{{ stats.notes.ko }}</strong></div>
        <div class="text-sm"><span class="mr-1 inline-block h-3 w-3 rounded-full bg-emerald-600"></span>OK <strong class="ml-1 text-2xl">{{ stats.notes.ok }}</strong></div></div>
      <div class="sc-card p-4"><div class="text-xs font-semibold uppercase text-slate-500">Notés validés</div>
        <div class="mt-2 text-sm"><span class="mr-1 inline-block h-3 w-3 rounded-full bg-red-600"></span>Note 0 <strong class="ml-1 text-2xl">{{ stats.valides.rouge }}</strong></div>
        <div class="text-sm"><span class="mr-1 inline-block h-3 w-3 rounded-full bg-emerald-600"></span>Note &gt; 0 <strong class="ml-1 text-2xl">{{ stats.valides.vert }}</strong></div></div>
      <div class="sc-card p-4"><div class="text-xs font-semibold uppercase text-slate-500">Bonus</div><div class="mt-2 text-3xl font-semibold">{{ stats.bonus.tentatives }}</div><div class="text-xs text-slate-500">{{ stats.bonus.stagiaires }} stagiaire(s)</div></div>
    </section>

    <section v-if="stats" class="grid gap-3 lg:grid-cols-2">
      <div class="sc-card overflow-x-auto p-4">
        <h2 class="mb-2 text-sm font-semibold text-alag-navy">{{ promoCourante ? promoCourante.libelle : 'Promos avec Atelier SQL' }} — par groupe</h2>
        <table class="w-full text-sm">
          <thead><tr class="text-left text-xs uppercase text-slate-500"><th class="py-1">Groupe</th><th class="text-right">OK</th><th class="text-right">KO</th><th class="text-right">Bonus</th></tr></thead>
          <tbody><tr v-for="g in stats.parGroupe" :key="g.groupe" class="border-t border-slate-100"><td class="py-1">{{ g.groupe }}</td><td class="text-right">{{ g.ok }}</td><td class="text-right">{{ g.ko }}</td><td class="text-right">{{ g.bonus }}</td></tr>
            <tr v-if="!stats.parGroupe.length"><td colspan="4" class="py-2 text-slate-500">Aucune tentative sur la période.</td></tr></tbody>
        </table>
      </div>
      <div class="sc-card overflow-x-auto p-4">
        <h2 class="mb-2 text-sm font-semibold text-alag-navy">Promo de référence<span v-if="reference"> — {{ reference.promo.libelle }}</span></h2>
        <div v-if="f.promo" class="mb-3 flex flex-wrap items-center gap-2">
          <select v-model="refChoisie" class="sc-input min-w-0 flex-1"><option value="">Aucune</option>
            <option v-for="p in promos.filter((x) => x.code !== f.promo)" :key="p.code" :value="p.code">{{ p.libelle }} {{ p.sujet }} ({{ dateFr(p.du) }})</option></select>
          <button type="button" class="sc-btn-ghost px-3 py-1.5 text-sm" @click="enregistrerReference">Enregistrer</button>
          <span v-if="messageRef" class="text-xs text-slate-600">{{ messageRef }}</span>
        </div>
        <p v-else class="mb-3 text-xs text-slate-500">Choisissez une promo pour définir sa promo de référence.</p>
        <table v-if="reference" class="w-full text-sm">
          <thead><tr class="text-left text-xs uppercase text-slate-500"><th class="py-1">Groupe</th><th class="text-right">OK</th><th class="text-right">KO</th></tr></thead>
          <tbody><tr v-for="g in reference.stats.parGroupe" :key="g.groupe" class="border-t border-slate-100"><td class="py-1">{{ g.groupe }}</td><td class="text-right">{{ g.ok }}</td><td class="text-right">{{ g.ko }}</td></tr></tbody>
        </table>
      </div>
    </section>

    <!-- Bonus -->
    <section v-if="f.promo && bonus.length" class="sc-card overflow-x-auto p-4">
      <h2 class="mb-2 text-sm font-semibold text-alag-navy">Stagiaires avec bonus</h2>
      <table class="w-full text-sm">
        <thead><tr class="text-left text-xs uppercase text-slate-500"><th class="py-1">Stagiaire</th><th class="text-right">Bonus</th><th class="text-right">Somme des notes</th><th class="text-right">Points ajoutés</th><th class="text-right">Total</th></tr></thead>
        <tbody><tr v-for="b in bonus" :key="b.codeStagiaire" class="border-t border-slate-100">
          <td class="py-1">{{ b.nom }} {{ b.prenom }}</td><td class="text-right">{{ b.bonus }}</td><td class="text-right">{{ b.sommeNotes }}</td>
          <td class="text-right"><input v-model="b.pointsAjoutes" type="number" step="0.5" class="sc-input w-20 text-right" :aria-label="'Points ajoutés à ' + b.nom" @change="enregistrerPoints(b)" /></td>
          <td class="text-right font-semibold">{{ noteFinale(b) }}</td></tr></tbody>
      </table>
    </section>

    <!-- Requêtes -->
    <section class="space-y-3">
      <h2 class="text-sm font-semibold text-alag-navy">Requêtes ({{ requetes.length }}<span v-if="requetes.length === 200">, 200 plus récentes</span>)</h2>
      <p v-if="!chargement && !requetes.length" class="sc-card p-4 text-sm text-slate-600">Aucune requête pour ces filtres.</p>
      <article v-for="q in requetes" :key="q.id" class="sc-card p-3">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
          <strong>{{ q.nom }} {{ q.prenom }}</strong>
          <span class="text-slate-500">{{ dt(q.date) }}</span>
          <span class="rounded px-2 py-0.5 text-xs font-semibold" :class="q.correcte ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'">{{ q.correcte ? 'OK' : 'KO' }}</span>
          <span class="text-xs text-slate-500">Consigne {{ q.ordre }}<span v-if="q.niveau"> · niv. {{ q.niveau }}</span><span v-if="q.notee"> · notée</span></span>
          <label class="flex items-center gap-1"><input type="checkbox" class="h-4 w-4" :checked="q.vu" @change="modifier(q, { vu: $event.target.checked })" /> Vu</label>
          <button type="button" class="rounded border px-2 py-0.5 text-xs font-medium" :class="q.bonus ? 'border-accent bg-accent-soft text-accent-hover' : 'border-slate-300 text-slate-600'" :aria-pressed="q.bonus" @click="modifier(q, { bonus: !q.bonus })">Bonus</button>
          <span class="ml-auto flex items-center gap-1" role="group" aria-label="Note">
            <button v-for="n in [0, 0.5, 1]" :key="n" type="button" :aria-pressed="q.note === n" :title="'Note ' + n"
                    class="flex h-8 min-w-[2.5rem] items-center justify-center gap-1 rounded-full border px-2 text-xs font-semibold"
                    :class="q.note === n ? (n === 0 ? 'border-red-600 bg-red-600 text-white' : n === 1 ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-amber-500 bg-amber-500 text-white') : 'border-slate-300 text-slate-600 hover:bg-slate-100'"
                    @click="noter(q, n)">{{ n === 0.5 ? '0,5' : n }}</button>
          </span>
        </div>
        <p class="mt-2 text-xs text-slate-500">{{ q.consigne }}</p>
        <pre class="mt-1 max-h-40 overflow-auto whitespace-pre-wrap rounded bg-paper p-2 font-mono text-xs">{{ q.requete }}</pre>
        <p v-if="q.erreur" class="mt-1 text-xs text-red-700">{{ q.erreur }}</p>
      </article>
    </section>
  </div>
</template>
