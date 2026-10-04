<script setup>
// Atelier SQL — BackOffice formateur pour la banque de consignes (équivalent du vrai BackOffice
// de l'ancien SqlRA : table Ordre/Consigne/Visible/Noté/Solution/NivDifficulté/NumCons). Pas de
// scoping par promo : une consigne est liée à une base, commune à toutes les promos qui la
// travaillent (cf. route_admin_atelier_consignes côté API).
import { computed, onMounted, reactive, ref, watch } from 'vue'
import * as XLSX from 'xlsx'
import { api } from '@/services/api'

const bases = ref([])
const baseChoisie = ref('')
const consignes = ref([])
const chargement = ref(false)
const erreur = ref('')

const edition = ref(null) // numConsigne en cours d'édition, ou 'nouveau'
const form = reactive({ ordre: '', consigne: '', solution: '', visible: true, notee: false, nivDifficulte: '' })
const envoi = ref(false)
const erreurForm = ref('')

// Pointage des tentatives (exploite SACT_Vu) — panneau séparé de l'édition, ouvert par consigne.
const tentativesOuvertes = ref(null) // numConsigne
const tentatives = ref([])
const chargementTentatives = ref(false)
const erreurTentatives = ref('')

// Import Excel en masse (analysé côté navigateur avec SheetJS — aucune dépendance PHP ajoutée).
// Colonnes attendues (insensibles à la casse/aux accents) : Ordre, Consigne, Solution, Visible,
// Notée, Difficulté. Seules Consigne et Solution sont obligatoires.
const importOuvert = ref(false)
const importApercu = ref([]) // lignes parsées, avant envoi
const importErreurFichier = ref('')
const importEnCours = ref(false)
const importResultat = ref(null)

function normaliserEntete(s) {
  return String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').trim().toLowerCase()
}

function analyserFichier(ev) {
  const fichier = ev.target.files?.[0]
  if (!fichier) return
  importErreurFichier.value = ''
  importResultat.value = null
  importApercu.value = []
  const lecteur = new FileReader()
  lecteur.onload = (e) => {
    try {
      const classeur = XLSX.read(e.target.result, { type: 'array' })
      const feuille = classeur.Sheets[classeur.SheetNames[0]]
      const lignesBrutes = XLSX.utils.sheet_to_json(feuille, { defval: '' })
      if (!lignesBrutes.length) { importErreurFichier.value = 'Fichier vide ou illisible.'; return }
      importApercu.value = lignesBrutes.map((ligneBrute) => {
        const l = {}
        for (const [cle, val] of Object.entries(ligneBrute)) l[normaliserEntete(cle)] = val
        const truthy = (v) => v === true || v === 1 || /^(1|oui|vrai|true|x)$/i.test(String(v ?? '').trim())
        return {
          ordre: String(l.ordre ?? '').trim(),
          consigne: String(l.consigne ?? '').trim(),
          solution: String(l.solution ?? '').trim(),
          visible: l.visible === '' || l.visible === undefined ? true : truthy(l.visible),
          notee: truthy(l.notee ?? l['notée']),
          nivDifficulte: String(l.difficulte ?? l['difficulté'] ?? l.nivdifficulte ?? '').trim(),
        }
      })
    } catch {
      importErreurFichier.value = "Impossible de lire ce fichier (format Excel attendu : .xlsx, .xls ou .csv)."
    }
  }
  lecteur.readAsArrayBuffer(fichier)
}

async function confirmerImport() {
  if (!importApercu.value.length) return
  importEnCours.value = true
  importResultat.value = null
  try {
    importResultat.value = await api('/admin/atelier/consignes/importer', {
      method: 'POST',
      body: { base: baseChoisie.value, consignes: importApercu.value }
    })
    if (importResultat.value.nbImportees > 0) await charger()
  } catch (e) {
    importErreurFichier.value = e.message
  } finally {
    importEnCours.value = false
  }
}

function fermerImport() {
  importOuvert.value = false
  importApercu.value = []
  importErreurFichier.value = ''
  importResultat.value = null
}

function formVide() {
  form.ordre = ''
  form.consigne = ''
  form.solution = ''
  form.visible = true
  form.notee = false
  form.nivDifficulte = ''
}

async function charger() {
  if (!baseChoisie.value) return
  chargement.value = true
  erreur.value = ''
  try {
    consignes.value = await api('/admin/atelier/consignes', { params: { base: baseChoisie.value } })
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
}

onMounted(async () => {
  try {
    bases.value = await api('/atelier/bases')
    if (bases.value.length) baseChoisie.value = bases.value[0].code
    await charger()
  } catch (e) {
    erreur.value = e.message
  }
})

watch(baseChoisie, () => { edition.value = null; charger() })

function ouvrirNouveau() {
  tentativesOuvertes.value = null
  edition.value = 'nouveau'
  formVide()
  erreurForm.value = ''
}

function ouvrirEdition(c) {
  tentativesOuvertes.value = null
  edition.value = c.numConsigne
  form.ordre = c.ordre ?? ''
  form.consigne = c.consigne
  form.solution = c.solution
  form.visible = c.visible
  form.notee = c.notee
  form.nivDifficulte = c.nivDifficulte ?? ''
  erreurForm.value = ''
}

function fermerEdition() {
  edition.value = null
}

async function enregistrer() {
  erreurForm.value = ''
  if (!form.consigne.trim() || !form.solution.trim()) { erreurForm.value = 'Consigne et solution requises.'; return }
  if (!/^select\b/i.test(form.solution.trim())) { erreurForm.value = 'La solution doit être une requête SELECT.'; return }
  envoi.value = true
  const corps = {
    ordre: form.ordre,
    consigne: form.consigne,
    solution: form.solution,
    visible: form.visible,
    notee: form.notee,
    nivDifficulte: form.nivDifficulte === '' ? null : Number(form.nivDifficulte),
  }
  try {
    if (edition.value === 'nouveau') {
      await api('/admin/atelier/consignes', { method: 'POST', body: { ...corps, base: baseChoisie.value } })
    } else {
      await api(`/admin/atelier/consignes/${edition.value}`, { method: 'PUT', body: corps })
    }
    edition.value = null
    await charger()
  } catch (e) {
    erreurForm.value = e.message
  } finally {
    envoi.value = false
  }
}

async function supprimer(c) {
  if (!confirm(`Supprimer la consigne « ${c.consigne} » ? Les tentatives des stagiaires seront perdues.`)) return
  try {
    await api(`/admin/atelier/consignes/${c.numConsigne}`, { method: 'DELETE' })
    if (edition.value === c.numConsigne) edition.value = null
    await charger()
  } catch (e) {
    erreur.value = e.message
  }
}

async function ouvrirTentatives(c) {
  if (tentativesOuvertes.value === c.numConsigne) { tentativesOuvertes.value = null; return }
  edition.value = null
  tentativesOuvertes.value = c.numConsigne
  erreurTentatives.value = ''
  chargementTentatives.value = true
  try {
    tentatives.value = await api(`/admin/atelier/consignes/${c.numConsigne}/tentatives`)
  } catch (e) {
    erreurTentatives.value = e.message
  } finally {
    chargementTentatives.value = false
  }
}

async function basculerVu(t) {
  const avant = t.vu
  t.vu = !avant // optimiste, remis en place si l'appel échoue
  try {
    await api(`/admin/atelier/tentatives/${t.id}/vu`, { method: 'PUT', body: { vu: t.vu } })
  } catch (e) {
    t.vu = avant
    erreurTentatives.value = e.message
  }
}

const total = computed(() => consignes.value.length)
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'formateur-accueil' }" class="text-sm text-alag-blue-dark hover:underline">‹ Accueil formateur</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Atelier SQL — BackOffice consignes</h1>
    <p class="text-xs text-slate-500">
      Banque d'exercices permanente, travaillée librement par les stagiaires (tuile « Consignes » de l'atelier). Distincte des partiels chronométrés.
    </p>

    <label v-if="bases.length > 1" class="block text-sm font-medium text-slate-700">
      Base de données
      <select v-model="baseChoisie" class="mt-1 block w-full rounded-lg border-slate-300 text-sm sm:w-64">
        <option v-for="b in bases" :key="b.code" :value="b.code">{{ b.nom }}</option>
      </select>
    </label>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>

    <section class="sc-card overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
        <h2 class="text-sm font-semibold text-alag-navy">{{ total }} consigne{{ total > 1 ? 's' : '' }}</h2>
        <div class="flex gap-2">
          <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium hover:bg-slate-50" @click="importOuvert = !importOuvert">
            {{ importOuvert ? 'Fermer l\'import' : '📥 Importer un fichier Excel' }}
          </button>
          <button type="button" class="rounded-lg bg-alag-blue-dark px-3 py-1.5 text-xs font-medium text-white hover:bg-[#0c4a68]" @click="ouvrirNouveau">
            + Nouvelle consigne
          </button>
        </div>
      </div>

      <div v-if="importOuvert" class="space-y-3 border-b border-slate-100 bg-slate-50/50 p-4">
        <h3 class="text-sm font-semibold text-slate-700">Import en masse (Excel)</h3>
        <p class="text-xs text-slate-500">
          Colonnes attendues (une ligne par consigne) : <code>Ordre</code>, <code>Consigne</code>, <code>Solution</code> (obligatoire, doit commencer par SELECT), <code>Visible</code>, <code>Notée</code>, <code>Difficulté</code>.
        </p>
        <input type="file" accept=".xlsx,.xls,.csv" class="block text-sm" @change="analyserFichier" />
        <p v-if="importErreurFichier" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ importErreurFichier }}</p>

        <div v-if="importApercu.length" class="space-y-2">
          <p class="text-xs font-medium text-slate-600">{{ importApercu.length }} ligne{{ importApercu.length > 1 ? 's' : '' }} détectée{{ importApercu.length > 1 ? 's' : '' }} — aperçu avant import :</p>
          <div class="max-h-64 overflow-auto rounded border border-slate-200 bg-white">
            <table class="w-full text-left text-xs">
              <thead class="sticky top-0 bg-slate-100">
                <tr><th class="px-2 py-1">Ordre</th><th class="px-2 py-1">Consigne</th><th class="px-2 py-1">Solution</th><th class="px-2 py-1">Vis.</th><th class="px-2 py-1">Not.</th></tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="(l, i) in importApercu" :key="i" :class="{ 'bg-red-50': !l.consigne.trim() || !l.solution.trim() }">
                  <td class="px-2 py-1 font-mono">{{ l.ordre }}</td>
                  <td class="px-2 py-1">{{ l.consigne }}</td>
                  <td class="px-2 py-1 font-mono">{{ l.solution }}</td>
                  <td class="px-2 py-1">{{ l.visible ? '✓' : '' }}</td>
                  <td class="px-2 py-1">{{ l.notee ? '✓' : '' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="flex gap-2">
            <button type="button" class="rounded-lg bg-alag-blue-dark px-4 py-2 text-sm font-medium text-white hover:bg-[#0c4a68] disabled:opacity-50" :disabled="importEnCours" @click="confirmerImport">
              {{ importEnCours ? 'Import…' : `Importer ${importApercu.length} ligne${importApercu.length > 1 ? 's' : ''}` }}
            </button>
            <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50" @click="fermerImport">Annuler</button>
          </div>
        </div>

        <p v-if="importResultat" class="rounded-lg px-3 py-2 text-sm" :class="importResultat.nbImportees === importResultat.nbTotal ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'">
          {{ importResultat.nbImportees }}/{{ importResultat.nbTotal }} ligne{{ importResultat.nbTotal > 1 ? 's' : '' }} importée{{ importResultat.nbImportees > 1 ? 's' : '' }}.
          <span v-if="importResultat.nbImportees < importResultat.nbTotal">
            Échecs : {{ importResultat.resultats.filter(r => !r.ok).map(r => `ligne ${r.ligne} (${r.erreur})`).join(', ') }}
          </span>
        </p>
      </div>

      <p v-if="chargement" class="p-4 text-sm text-slate-500">Chargement…</p>

      <div v-else-if="edition === 'nouveau'" class="space-y-3 border-b border-slate-100 p-4">
        <h3 class="text-sm font-semibold text-slate-700">Nouvelle consigne</h3>
        <div class="grid gap-3 sm:grid-cols-3">
          <label class="block text-sm text-slate-700">
            Ordre (tri)
            <input v-model="form.ordre" type="text" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" placeholder="ex. 0C01-01" />
          </label>
          <label class="block text-sm text-slate-700">
            Niveau de difficulté
            <input v-model="form.nivDifficulte" type="number" min="1" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" />
          </label>
          <div class="flex items-end gap-4 pb-1">
            <label class="flex items-center gap-1.5 text-sm text-slate-700"><input v-model="form.visible" type="checkbox" class="rounded border-slate-300" /> Visible</label>
            <label class="flex items-center gap-1.5 text-sm text-slate-700"><input v-model="form.notee" type="checkbox" class="rounded border-slate-300" /> Notée</label>
          </div>
        </div>
        <label class="block text-sm text-slate-700">
          Consigne (énoncé)
          <textarea v-model="form.consigne" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" placeholder="ex. La liste de tous les produits"></textarea>
        </label>
        <label class="block text-sm text-slate-700">
          Solution (SELECT)
          <textarea v-model="form.solution" rows="2" spellcheck="false" class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-sm" placeholder='select * from "T_Produit"'></textarea>
        </label>
        <p v-if="erreurForm" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurForm }}</p>
        <div class="flex gap-2">
          <button type="button" class="rounded-lg bg-alag-blue-dark px-4 py-2 text-sm font-medium text-white hover:bg-[#0c4a68] disabled:opacity-50" :disabled="envoi" @click="enregistrer">
            {{ envoi ? 'Création…' : 'Créer' }}
          </button>
          <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50" @click="fermerEdition">Annuler</button>
        </div>
      </div>

      <p v-if="!chargement && !consignes.length && edition !== 'nouveau'" class="p-4 text-sm text-slate-600">Aucune consigne pour cette base pour le moment.</p>

      <ul v-if="!chargement" class="divide-y divide-slate-100">
        <li v-for="c in consignes" :key="c.numConsigne">
          <div class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
            <span class="w-20 shrink-0 font-mono text-xs text-slate-500">{{ c.ordre || '—' }}</span>
            <span class="flex-1 text-slate-900">{{ c.consigne }}</span>
            <span v-if="!c.visible" class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">masquée</span>
            <span v-if="c.notee" class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">notée</span>
            <span class="text-xs text-slate-500">{{ c.nbReussi }}/{{ c.nbTentatives || 0 }} réussi{{ c.nbReussi > 1 ? 's' : '' }} · {{ c.nbTentatives }} essai{{ c.nbTentatives > 1 ? 's' : '' }}</span>
            <button type="button" class="text-xs text-alag-blue-dark hover:underline" @click="ouvrirTentatives(c)">
              {{ tentativesOuvertes === c.numConsigne ? 'Fermer' : 'Tentatives' }}
            </button>
            <button type="button" class="text-xs text-alag-blue-dark hover:underline" @click="edition === c.numConsigne ? fermerEdition() : ouvrirEdition(c)">
              {{ edition === c.numConsigne ? 'Fermer' : 'Modifier' }}
            </button>
            <button type="button" class="text-xs text-red-600 hover:underline" @click="supprimer(c)">Supprimer</button>
          </div>

          <div v-if="tentativesOuvertes === c.numConsigne" class="border-t border-slate-100 bg-slate-50/50 p-4">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
              Tentatives — pointage manuel (équivalent « Mettre Vu »)
            </h3>
            <p v-if="erreurTentatives" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurTentatives }}</p>
            <p v-else-if="chargementTentatives" class="text-sm text-slate-500">Chargement…</p>
            <p v-else-if="!tentatives.length" class="text-sm text-slate-600">Aucune tentative pour cette consigne.</p>
            <ul v-else class="space-y-1">
              <li v-for="t in tentatives" :key="t.id" class="flex flex-wrap items-center gap-2 rounded border border-slate-200 bg-white p-2 text-xs">
                <span :class="t.correcte ? 'text-emerald-700' : 'text-red-600'">{{ t.correcte ? '✓' : '✗' }}</span>
                <span class="font-medium text-slate-800">{{ t.nom }} {{ t.prenom }}</span>
                <span class="flex-1 truncate font-mono text-slate-500">{{ t.requete }}</span>
                <label class="flex items-center gap-1 text-slate-600">
                  <input type="checkbox" class="rounded border-slate-300" :checked="t.vu" @change="basculerVu(t)" /> Vu
                </label>
              </li>
            </ul>
          </div>

          <div v-if="edition === c.numConsigne" class="space-y-3 border-t border-slate-100 bg-slate-50/50 p-4">
            <div class="grid gap-3 sm:grid-cols-3">
              <label class="block text-sm text-slate-700">
                Ordre (tri)
                <input v-model="form.ordre" type="text" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" />
              </label>
              <label class="block text-sm text-slate-700">
                Niveau de difficulté
                <input v-model="form.nivDifficulte" type="number" min="1" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" />
              </label>
              <div class="flex items-end gap-4 pb-1">
                <label class="flex items-center gap-1.5 text-sm text-slate-700"><input v-model="form.visible" type="checkbox" class="rounded border-slate-300" /> Visible</label>
                <label class="flex items-center gap-1.5 text-sm text-slate-700"><input v-model="form.notee" type="checkbox" class="rounded border-slate-300" /> Notée</label>
              </div>
            </div>
            <label class="block text-sm text-slate-700">
              Consigne (énoncé)
              <textarea v-model="form.consigne" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></textarea>
            </label>
            <label class="block text-sm text-slate-700">
              Solution (SELECT)
              <textarea v-model="form.solution" rows="2" spellcheck="false" class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-sm"></textarea>
            </label>
            <p v-if="erreurForm" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurForm }}</p>
            <div class="flex gap-2">
              <button type="button" class="rounded-lg bg-alag-blue-dark px-4 py-2 text-sm font-medium text-white hover:bg-[#0c4a68] disabled:opacity-50" :disabled="envoi" @click="enregistrer">
                {{ envoi ? 'Enregistrement…' : 'Enregistrer' }}
              </button>
              <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50" @click="fermerEdition">Annuler</button>
            </div>
          </div>
        </li>
      </ul>
    </section>
  </div>
</template>
