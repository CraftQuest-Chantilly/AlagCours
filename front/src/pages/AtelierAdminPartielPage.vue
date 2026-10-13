<script setup>
// Atelier SQL — mini-écran formateur pour créer un partiel. La promo se choisit par recherche
// (libellé / sujet) via GET /admin/promos?q= ; le code promo n'est jamais saisi à la main.
import { onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/services/api'
import { dateFr } from '@/utils'

const codePromo = ref('')
const promoChoisie = ref(null)
const recherchePromo = ref('')
const promosTrouvees = ref([])
const listePromosOuverte = ref(false)
let minuteur
function chercherPromos() {
  clearTimeout(minuteur)
  minuteur = setTimeout(async () => {
    try { promosTrouvees.value = await api('/admin/promos', { params: { q: recherchePromo.value || undefined } }) }
    catch (e) { erreurListe.value = e.message }
  }, 250)
}
function fermerListePromos() { setTimeout(() => { listePromosOuverte.value = false }, 150) }
function ouvrirListePromos() { listePromosOuverte.value = true; chercherPromos() }
function choisirPromo(p) {
  promoChoisie.value = p
  codePromo.value = p.code
  recherchePromo.value = ''
  listePromosOuverte.value = false
  partielsExistants.value = []
  chargerListe()
}
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
  if (!/^\d+$/.test(codePromo.value)) { erreurListe.value = 'Choisissez une promo.'; return }
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

// Banque de consignes (BackOffice) : on pioche des questions déjà créées pour la base choisie.
const banqueOuverte = ref(false)
const banque = ref([])
const banqueChoix = ref([])
const erreurBanque = ref('')
async function basculerBanque() {
  banqueOuverte.value = !banqueOuverte.value
  if (!banqueOuverte.value) return
  erreurBanque.value = ''
  banqueChoix.value = []
  try { banque.value = await api('/admin/atelier/consignes', { params: { base: form.base } }) }
  catch (e) { erreurBanque.value = e.message }
}
watch(() => form.base, () => { banqueOuverte.value = false })
function ajouterDepuisBanque() {
  const dejaLa = new Set(form.questions.map((q) => q.consigne))
  const vides = form.questions.filter((q) => !q.consigne.trim() && !q.requeteRef.trim()).length
  if (vides === form.questions.length) form.questions = [] // on remplace la question vide d'origine
  for (const c of banque.value.filter((c) => banqueChoix.value.includes(c.numConsigne))) {
    if (!dejaLa.has(c.consigne)) form.questions.push({ consigne: c.consigne, requeteRef: c.solution, bareme: 1 })
  }
  banqueOuverte.value = false
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
  if (!/^\d+$/.test(codePromo.value)) { erreurEnvoi.value = 'Choisissez une promo.'; return }
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
    <RouterLink :to="{ name: 'formateur-accueil' }" class="text-sm text-alag-navy hover:underline">‹ Accueil formateur</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Atelier SQL — créer un partiel</h1>
    <section class="sc-card space-y-3 p-4">
      <div class="relative">
        <label for="promo-recherche" class="block text-sm font-medium text-slate-700">Promo</label>
        <div v-if="promoChoisie" class="mt-1 flex items-center justify-between gap-2 rounded-lg border border-slate-300 bg-paper px-3 py-2 text-sm">
          <span><strong>{{ promoChoisie.libelle }}</strong> {{ promoChoisie.sujet }} <span class="text-slate-500">· {{ dateFr(promoChoisie.du) }} → {{ dateFr(promoChoisie.au) }}</span></span>
          <button type="button" class="text-xs font-medium text-alag-navy hover:underline" @click="promoChoisie = null; codePromo = ''; partielsExistants = []; ouvrirListePromos()">Changer</button>
        </div>
        <template v-else>
          <input id="promo-recherche" v-model="recherchePromo" type="search" autocomplete="off" class="sc-input mt-1 w-full"
                 placeholder="Rechercher une promo par nom ou sujet…" @focus="ouvrirListePromos" @input="chercherPromos" @keydown.esc="listePromosOuverte = false" @blur="fermerListePromos" />
          <ul v-if="listePromosOuverte" class="absolute z-10 mt-1 max-h-72 w-full overflow-auto rounded-lg border border-slate-300 bg-white shadow-lg">
            <li v-if="!promosTrouvees.length" class="px-3 py-2 text-sm text-slate-500">Aucune promo trouvée.</li>
            <li v-for="p in promosTrouvees" :key="p.code">
              <button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-paper" @click="choisirPromo(p)">
                <span class="font-medium text-slate-900">{{ p.libelle }}</span> <span class="text-slate-700">{{ p.sujet }}</span>
                <span class="block text-xs text-slate-500">{{ dateFr(p.du) }} → {{ dateFr(p.au) }}</span>
              </button>
            </li>
          </ul>
        </template>
      </div>
      <p v-if="erreurListe" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurListe }}</p>
      <p v-else-if="chargementListe" class="text-sm text-slate-500">Chargement…</p>
      <ul v-else-if="partielsExistants.length" class="divide-y divide-slate-100 text-sm">
        <li v-for="p in partielsExistants" :key="p.numPartiel" class="py-2">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium text-slate-900">{{ p.titre }}</span>
            <span class="text-xs text-slate-500">{{ p.nbQuestions }} question{{ p.nbQuestions > 1 ? 's' : '' }} · {{ heureFr(p.debut) }} → {{ heureFr(p.fin) }}</span>
            <button type="button" class="text-xs text-alag-navy hover:underline" @click="ouvrirResultats(p)">
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
        <select v-model="form.base" class="mt-1 sc-input w-full">
          <option v-for="b in bases" :key="b.code" :value="b.code">{{ b.nom }}</option>
        </select>
      </label>

      <label class="block text-sm font-medium text-slate-700">
        Titre
        <input v-model="form.titre" type="text" class="mt-1 sc-input w-full" placeholder="ex. Partiel SQL — évaluation de fin d'année" />
      </label>

      <div class="grid gap-3 sm:grid-cols-3">
        <label class="block text-sm font-medium text-slate-700">
          Début
          <input v-model="form.debut" type="datetime-local" class="mt-1 sc-input w-full" />
        </label>
        <label class="block text-sm font-medium text-slate-700">
          Fin
          <input v-model="form.fin" type="datetime-local" class="mt-1 sc-input w-full" />
        </label>
        <label class="block text-sm font-medium text-slate-700">
          Tentatives max / question
          <input v-model="form.tentativesMax" type="number" min="1" class="mt-1 sc-input w-full" />
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
            <textarea v-model="q.consigne" rows="2" class="mt-1 sc-input w-full" placeholder="ex. Lister les produits dont le prix public dépasse 10 €"></textarea>
          </label>
          <label class="block text-sm text-slate-700">
            Requête de référence (SELECT)
            <textarea v-model="q.requeteRef" rows="2" spellcheck="false" class="sc-input mt-1 w-full" placeholder='select * from "T_Produit" where "P_PrixPublic" > 10'></textarea>
          </label>
          <label class="block w-32 text-sm text-slate-700">
            Barème
            <input v-model="q.bareme" type="number" min="0.5" step="0.5" class="mt-1 sc-input w-full" />
          </label>
        </div>
        <div class="flex flex-wrap gap-4">
          <button type="button" class="text-sm text-alag-navy hover:underline" @click="ajouterQuestion">+ Ajouter une question</button>
          <button type="button" class="text-sm text-alag-navy hover:underline" @click="basculerBanque">{{ banqueOuverte ? 'Fermer la banque' : '+ Choisir dans la banque de consignes' }}</button>
        </div>
        <div v-if="banqueOuverte" class="rounded-lg border border-slate-300 bg-paper p-3">
          <p v-if="erreurBanque" class="text-sm text-red-700" role="alert">{{ erreurBanque }}</p>
          <p v-else-if="!banque.length" class="text-sm text-slate-600">Aucune consigne dans la banque pour cette base. Elles se créent dans « Consignes ».</p>
          <template v-else>
            <ul class="max-h-64 divide-y divide-slate-200 overflow-auto text-sm">
              <li v-for="c in banque" :key="c.numConsigne">
                <label class="flex cursor-pointer items-start gap-2 py-2">
                  <input v-model="banqueChoix" type="checkbox" :value="c.numConsigne" class="mt-1 h-4 w-4" />
                  <span class="min-w-0"><span class="block text-slate-900">{{ c.consigne }}</span>
                    <span class="block truncate font-mono text-xs text-slate-500">{{ c.solution }}</span></span>
                </label>
              </li>
            </ul>
            <button type="button" class="sc-btn-primary mt-2 px-3 py-1.5 text-sm" :disabled="!banqueChoix.length" @click="ajouterDepuisBanque">
              Ajouter {{ banqueChoix.length || '' }} question{{ banqueChoix.length > 1 ? 's' : '' }}
            </button>
          </template>
        </div>
      </div>

      <p v-if="erreurEnvoi" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurEnvoi }}</p>
      <p v-if="succes" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ succes }}</p>

      <button type="button" class="rounded-lg bg-alag-navy px-4 py-2 text-sm font-medium text-white hover:bg-alag-navy-dark disabled:opacity-50"
              :disabled="envoi" @click="creer">
        {{ envoi ? 'Création…' : 'Créer le partiel' }}
      </button>
    </section>
  </div>
</template>
