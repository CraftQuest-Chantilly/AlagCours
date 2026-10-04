<script setup>
// Atelier SQL — mini-écran formateur pour créer un partiel (en attendant l'interface formateur
// complète, cf. commentaire de route_admin_atelier_partiel_creer). Pas de sélecteur de promo :
// aucune route ne liste encore les promos d'un formateur, donc saisie manuelle du code promo
// (signalé comme provisoire).
import { onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/services/api'

const codePromo = ref('')
const partielsExistants = ref([])
const chargementListe = ref(false)
const erreurListe = ref('')

const bases = ref([])

const form = reactive({
  base: '',
  titre: '',
  debut: '',
  fin: '',
  tentativesMax: 3,
  questions: [{ consigne: '', requeteRef: '', bareme: 1 }]
})

const envoi = ref(false)
const erreurEnvoi = ref('')
const succes = ref('')

// Résultats / non-répondants d'un partiel existant (équivalent "Graph partiel" + "Qui n'a pas rep").
const partielOuvert = ref(null) // numPartiel
const resultats = ref(null)
const nonRepondants = ref(null)
const chargementResultats = ref(false)
const erreurResultats = ref('')

onMounted(async () => {
  try {
    bases.value = await api('/atelier/bases')
    if (bases.value.length) form.base = bases.value[0].code
  } catch (e) {
    erreurListe.value = e.message
  }
})

async function chargerListe() {
  if (!/^\d+$/.test(codePromo.value)) { erreurListe.value = 'Code promo invalide.'; return }
  chargementListe.value = true
  erreurListe.value = ''
  try {
    partielsExistants.value = await api(`/admin/atelier/partiels/${codePromo.value}`)
  } catch (e) {
    erreurListe.value = e.message
  } finally {
    chargementListe.value = false
  }
}

function ajouterQuestion() {
  form.questions.push({ consigne: '', requeteRef: '', bareme: 1 })
}
function retirerQuestion(i) {
  if (form.questions.length > 1) form.questions.splice(i, 1)
}

async function creer() {
  erreurEnvoi.value = ''
  succes.value = ''
  if (!/^\d+$/.test(codePromo.value)) { erreurEnvoi.value = 'Code promo invalide.'; return }
  if (!form.titre.trim() || !form.debut || !form.fin) { erreurEnvoi.value = 'Titre, début et fin sont obligatoires.'; return }
  for (const q of form.questions) {
    if (!q.consigne.trim() || !q.requeteRef.trim()) { erreurEnvoi.value = 'Chaque question doit avoir une consigne et une requête de référence.'; return }
  }
  envoi.value = true
  try {
    const r = await api('/admin/atelier/partiels', {
      method: 'POST',
      body: {
        codePromo: codePromo.value,
        base: form.base,
        titre: form.titre,
        // <input type="datetime-local"> renvoie "YYYY-MM-DDTHH:MM" (heure murale locale du
        // formateur) — interprété côté serveur comme Europe/Paris, cf. atelier.php.
        debut: form.debut,
        fin: form.fin,
        tentativesMax: Number(form.tentativesMax),
        questions: form.questions.map((q) => ({ consigne: q.consigne, requeteRef: q.requeteRef, bareme: Number(q.bareme) }))
      }
    })
    succes.value = `Partiel créé (n° ${r.numPartiel}).`
    form.titre = ''
    form.debut = ''
    form.fin = ''
    form.questions = [{ consigne: '', requeteRef: '', bareme: 1 }]
    chargerListe()
  } catch (e) {
    erreurEnvoi.value = e.message
  } finally {
    envoi.value = false
  }
}

async function ouvrirResultats(p) {
  if (partielOuvert.value === p.numPartiel) { partielOuvert.value = null; return }
  partielOuvert.value = p.numPartiel
  erreurResultats.value = ''
  chargementResultats.value = true
  resultats.value = null
  nonRepondants.value = null
  try {
    ;[resultats.value, nonRepondants.value] = await Promise.all([
      api(`/admin/atelier/partiel/${p.numPartiel}/resultats`),
      api(`/admin/atelier/partiel/${p.numPartiel}/non-repondants`)
    ])
  } catch (e) {
    erreurResultats.value = e.message
  } finally {
    chargementResultats.value = false
  }
}

function heureFr(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="space-y-6">
    <RouterLink :to="{ name: 'formateur-accueil' }" class="text-sm text-alag-blue-dark hover:underline">‹ Accueil formateur</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Atelier SQL — créer un partiel</h1>
    <p class="text-xs text-slate-500">
      Écran provisoire, en attendant l'interface formateur complète : le code promo se saisit à la main.
    </p>

    <section class="sc-card space-y-3 p-4">
      <label class="block text-sm font-medium text-slate-700">
        Code promo
        <div class="mt-1 flex gap-2">
          <input v-model="codePromo" type="text" inputmode="numeric" class="block w-full rounded-lg border-slate-300 text-sm" placeholder="ex. 56857945295553847" />
          <button type="button" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50" @click="chargerListe">Voir les partiels</button>
        </div>
      </label>
      <p v-if="erreurListe" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurListe }}</p>
      <p v-else-if="chargementListe" class="text-sm text-slate-500">Chargement…</p>
      <ul v-else-if="partielsExistants.length" class="divide-y divide-slate-100 text-sm">
        <li v-for="p in partielsExistants" :key="p.numPartiel" class="py-2">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium text-slate-900">{{ p.titre }}</span>
            <span class="text-xs text-slate-500">{{ p.nbQuestions }} question{{ p.nbQuestions > 1 ? 's' : '' }} · {{ heureFr(p.debut) }} → {{ heureFr(p.fin) }}</span>
            <button type="button" class="text-xs text-alag-blue-dark hover:underline" @click="ouvrirResultats(p)">
              {{ partielOuvert === p.numPartiel ? 'Fermer' : 'Résultats' }}
            </button>
          </div>

          <div v-if="partielOuvert === p.numPartiel" class="mt-3 space-y-4 rounded-lg border border-slate-200 bg-slate-50/50 p-3">
            <p v-if="erreurResultats" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurResultats }}</p>
            <p v-else-if="chargementResultats" class="text-sm text-slate-500">Chargement…</p>
            <template v-else-if="resultats">
              <div>
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Taux de réussite par question ({{ resultats.nbInscrits }} inscrit{{ resultats.nbInscrits > 1 ? 's' : '' }})
                </h3>
                <div v-if="!resultats.questions.length" class="text-sm text-slate-600">Aucune question.</div>
                <div v-for="q in resultats.questions" :key="q.numQuestion" class="mb-2">
                  <div class="mb-0.5 flex items-center justify-between text-xs text-slate-600">
                    <span class="truncate pr-2">{{ q.consigne }}</span>
                    <span class="shrink-0 font-mono">{{ q.nbReussi }}/{{ resultats.nbInscrits }}</span>
                  </div>
                  <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                    <div class="h-full rounded-full bg-emerald-500"
                         :style="{ width: resultats.nbInscrits ? `${Math.round((q.nbReussi / resultats.nbInscrits) * 100)}%` : '0%' }"></div>
                  </div>
                </div>
              </div>

              <div>
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  N'ont déposé aucune réponse ({{ nonRepondants?.length ?? 0 }})
                </h3>
                <p v-if="!nonRepondants?.length" class="text-sm text-slate-600">Tout le monde a répondu à au moins une question.</p>
                <ul v-else class="flex flex-wrap gap-1.5">
                  <li v-for="s in nonRepondants" :key="s.code" class="rounded bg-white px-2 py-1 text-xs text-slate-700 ring-1 ring-slate-200">
                    {{ s.nom }} {{ s.prenom }}
                  </li>
                </ul>
              </div>
            </template>
          </div>
        </li>
      </ul>
    </section>

    <section class="sc-card space-y-4 p-4">
      <h2 class="text-base font-semibold text-alag-navy">Nouveau partiel</h2>

      <label class="block text-sm font-medium text-slate-700">
        Base
        <select v-model="form.base" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
          <option v-for="b in bases" :key="b.code" :value="b.code">{{ b.nom }}</option>
        </select>
      </label>

      <label class="block text-sm font-medium text-slate-700">
        Titre
        <input v-model="form.titre" type="text" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" placeholder="ex. Partiel SQL — évaluation de fin d'année" />
      </label>

      <div class="grid gap-3 sm:grid-cols-3">
        <label class="block text-sm font-medium text-slate-700">
          Début
          <input v-model="form.debut" type="datetime-local" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" />
        </label>
        <label class="block text-sm font-medium text-slate-700">
          Fin
          <input v-model="form.fin" type="datetime-local" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" />
        </label>
        <label class="block text-sm font-medium text-slate-700">
          Tentatives max / question
          <input v-model="form.tentativesMax" type="number" min="1" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" />
        </label>
      </div>

      <div class="space-y-3">
        <h3 class="text-sm font-semibold text-slate-700">Questions</h3>
        <div v-for="(q, i) in form.questions" :key="i" class="space-y-2 rounded-lg border border-slate-200 p-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-slate-500">Question {{ i + 1 }}</span>
            <button v-if="form.questions.length > 1" type="button" class="text-xs text-red-600 hover:underline" @click="retirerQuestion(i)">Retirer</button>
          </div>
          <label class="block text-sm text-slate-700">
            Consigne
            <textarea v-model="q.consigne" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" placeholder="ex. Lister les produits dont le prix public dépasse 10 €"></textarea>
          </label>
          <label class="block text-sm text-slate-700">
            Requête de référence (SELECT)
            <textarea v-model="q.requeteRef" rows="2" spellcheck="false" class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-sm" placeholder='select * from "T_Produit" where "P_PrixPublic" > 10'></textarea>
          </label>
          <label class="block w-32 text-sm text-slate-700">
            Barème
            <input v-model="q.bareme" type="number" min="0.5" step="0.5" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" />
          </label>
        </div>
        <button type="button" class="text-sm text-alag-blue-dark hover:underline" @click="ajouterQuestion">+ Ajouter une question</button>
      </div>

      <p v-if="erreurEnvoi" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurEnvoi }}</p>
      <p v-if="succes" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ succes }}</p>

      <button type="button" class="rounded-lg bg-alag-blue-dark px-4 py-2 text-sm font-medium text-white hover:bg-[#0c4a68] disabled:opacity-50"
              :disabled="envoi" @click="creer">
        {{ envoi ? 'Création…' : 'Créer le partiel' }}
      </button>
    </section>
  </div>
</template>
