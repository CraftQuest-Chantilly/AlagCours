<script setup>
// Remplace PAGE_Fichiers_Promo_TD d'al-cours : fichiers/liens pour lesquels un droit existe sur
// la promo + le groupe du stagiaire, triés dans l'ordre posé par le formateur (FicDroi_Ordre).
// Téléchargement journalisé côté API (TS_Download), liens externes ouverts dans un nouvel onglet.
//
// Regroupement (étape 7, 25/09/2026) : T_Fichier_Droits.FicDroi_TD est un champ hérité d'al-cours
// déjà peuplé en base (ex. "01", "02", "Doc") mais jamais exploité côté affichage jusqu'ici — le
// backend renvoie déjà `td` par fichier (voir fic_droits_stagiaire() dans ressources.php), donc le
// regroupement se fait ENTIÈREMENT ici, sans changement d'API. "-" (ou absent) = non groupé, affiché
// à part sans repli. Un même fichier peut avoir plusieurs droits avec des TD différents (vérifié en
// base) : il apparaît alors dans chaque groupe concerné, comme voulu par Jérôme.
//
// « Tout télécharger » un groupe : le serveur OVH n'a pas l'extension PHP zip (vérifié via
// diag.php), donc le zip est assemblé CÔTÉ NAVIGATEUR (JSZip) à partir des téléchargements
// individuels déjà existants, plutôt que de dépendre d'une capacité serveur incertaine.
import { computed, onMounted, ref } from 'vue'
import JSZip from 'jszip'
import { api, apiTelechargerBrut, sauvegarderBlob } from '@/services/api'

const props = defineProps({ code: { type: String, required: true } })

const items = ref([])
const chargement = ref(true)
const erreur = ref('')
const telechargementEnCours = ref(null) // numFic en cours de téléchargement (désactive le bouton)
const groupeEnCours = ref(null) // libellé du groupe en cours de zippage
const erreurGroupe = ref('')

onMounted(charger)

async function charger() {
  chargement.value = true
  erreur.value = ''
  try {
    const r = await api(`/formations/${props.code}/ressources`)
    items.value = r.fichiers
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
}

// Groupes dans l'ordre de première apparition (suit donc FicDroi_Ordre côté serveur). Le groupe
// "non groupé" (td absent ou "-") est distingué par cle=null et rendu sans repliage.
const groupes = computed(() => {
  const parCle = new Map()
  for (const it of items.value) {
    const groupe = it.td && it.td !== '-' ? it.td : null
    if (!parCle.has(groupe)) parCle.set(groupe, [])
    parCle.get(groupe).push(it)
  }
  return [...parCle.entries()].map(([libelle, fichiers]) => ({ libelle, fichiers }))
})

async function ouvrirFichier(item) {
  if (item.type === 'L') {
    window.open(item.url, '_blank', 'noopener')
    return
  }
  if (item.type === 'D') return // anomalie (droit posé sur un dossier) : pas d'action possible
  telechargementEnCours.value = item.numFic
  try {
    const { blob, nom } = await apiTelechargerBrut(`/formations/${props.code}/ressources/${item.numFic}/telecharger`, item.nom)
    sauvegarderBlob(blob, nom)
  } catch (e) {
    erreur.value = e.message
  } finally {
    telechargementEnCours.value = null
  }
}

async function telechargerGroupe(groupe) {
  const fichiers = groupe.fichiers.filter((f) => f.type === 'F')
  if (!fichiers.length || groupeEnCours.value) return
  groupeEnCours.value = groupe.libelle
  erreurGroupe.value = ''
  try {
    const zip = new JSZip()
    const noms = new Set() // évite d'écraser deux fichiers homonymes dans le zip
    for (const f of fichiers) {
      const { blob, nom } = await apiTelechargerBrut(`/formations/${props.code}/ressources/${f.numFic}/telecharger`, f.nom)
      let nomZip = nom
      let i = 2
      while (noms.has(nomZip)) { nomZip = `${nom.replace(/(\.[^.]*)?$/, '')}_${i}${nom.match(/\.[^.]*$/)?.[0] || ''}`; i++ }
      noms.add(nomZip)
      zip.file(nomZip, blob)
    }
    const archive = await zip.generateAsync({ type: 'blob' })
    sauvegarderBlob(archive, `${groupe.libelle}.zip`)
  } catch (e) {
    erreurGroupe.value = e.message
  } finally {
    groupeEnCours.value = null
  }
}

function tailleLisible(ko) {
  if (ko === null || ko === undefined) return ''
  if (ko < 1024) return `${ko} Ko`
  return `${(ko / 1024).toFixed(1)} Mo`
}
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'formation', params: { code } }" class="text-sm text-alag-blue-dark hover:underline">‹ Retour à la formation</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Fichiers, exercices, ressources</h1>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="erreurGroupe" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurGroupe }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <p v-if="!items.length" class="sc-card p-4 text-sm text-slate-600">Aucun fichier n'est accessible pour votre formation pour le moment.</p>

      <template v-for="groupe in groupes" :key="groupe.libelle ?? '__non_groupe__'">
        <!-- Non groupé : liste directe, sans repli. -->
        <section v-if="!groupe.libelle" class="sc-card overflow-hidden">
          <ul class="divide-y divide-slate-100 text-sm">
            <li v-for="it in groupe.fichiers" :key="it.numFic">
              <button type="button"
                      class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-alag-blue-light/50 disabled:cursor-default disabled:opacity-60 disabled:hover:bg-transparent"
                      :disabled="telechargementEnCours === it.numFic || it.type === 'D'"
                      @click="ouvrirFichier(it)">
                <span aria-hidden="true">{{ it.type === 'L' ? '🔗' : it.type === 'D' ? '📁' : '📄' }}</span>
                <span class="flex-1">
                  <span class="text-slate-900">{{ it.nom }}</span>
                  <span v-if="it.commentaire" class="block text-xs text-slate-500">{{ it.commentaire }}</span>
                </span>
                <span v-if="it.type === 'F'" class="text-xs text-slate-500">{{ tailleLisible(it.tailleKo) }}</span>
                <span v-if="telechargementEnCours === it.numFic" class="text-xs text-slate-500">Téléchargement…</span>
                <span v-else-if="it.type !== 'D'" class="text-xs font-medium text-alag-blue-dark">{{ it.type === 'L' ? 'Ouvrir' : 'Télécharger' }}</span>
              </button>
            </li>
          </ul>
        </section>

        <!-- Groupe nommé : dossier virtuel dépliable + téléchargement groupé. -->
        <details v-else class="sc-card overflow-hidden">
          <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 hover:bg-alag-blue-light/50">
            <span class="flex items-center gap-2">
              <span aria-hidden="true">📁</span>
              <span class="font-medium text-slate-900">{{ groupe.libelle }}</span>
              <span class="text-xs text-slate-500">{{ groupe.fichiers.length }} élément{{ groupe.fichiers.length > 1 ? 's' : '' }}</span>
            </span>
            <button type="button" class="shrink-0 rounded-lg border border-alag-blue-dark px-3 py-1 text-xs font-medium text-alag-blue-dark hover:bg-alag-blue-light disabled:opacity-50"
                    :disabled="groupeEnCours === groupe.libelle"
                    @click.stop.prevent="telechargerGroupe(groupe)">
              {{ groupeEnCours === groupe.libelle ? 'Préparation…' : 'Tout télécharger' }}
            </button>
          </summary>
          <ul class="divide-y divide-slate-100 border-t border-slate-100 text-sm">
            <li v-for="it in groupe.fichiers" :key="it.numFic">
              <button type="button"
                      class="flex w-full items-center gap-3 px-4 py-3 pl-9 text-left hover:bg-alag-blue-light/50 disabled:cursor-default disabled:opacity-60 disabled:hover:bg-transparent"
                      :disabled="telechargementEnCours === it.numFic || it.type === 'D'"
                      @click="ouvrirFichier(it)">
                <span aria-hidden="true">{{ it.type === 'L' ? '🔗' : it.type === 'D' ? '📁' : '📄' }}</span>
                <span class="flex-1">
                  <span class="text-slate-900">{{ it.nom }}</span>
                  <span v-if="it.commentaire" class="block text-xs text-slate-500">{{ it.commentaire }}</span>
                </span>
                <span v-if="it.type === 'F'" class="text-xs text-slate-500">{{ tailleLisible(it.tailleKo) }}</span>
                <span v-if="telechargementEnCours === it.numFic" class="text-xs text-slate-500">Téléchargement…</span>
                <span v-else-if="it.type !== 'D'" class="text-xs font-medium text-alag-blue-dark">{{ it.type === 'L' ? 'Ouvrir' : 'Télécharger' }}</span>
              </button>
            </li>
          </ul>
        </details>
      </template>
    </template>
  </div>
</template>
