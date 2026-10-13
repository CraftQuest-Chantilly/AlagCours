<script setup>
// Atelier SQL — entraînement libre. Reprend l'écran SQL de SqlRA (PAGE_CodeSQL) : éditeur + GO,
// choix de table, champs, mots clés, exemples, diagramme des relations. Base commune à toutes les
// promos, lecture seule (SELECT imposé côté serveur par sc_sql_atelier_executer, 200 lignes max).
// Adaptation voulue : les identifiants sont insérés entre guillemets ("T_Produit") car Postgres
// distingue la casse, contrairement à HFSQL.
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { api } from '@/services/api'

// "code" (formation) absent = accès formateur direct (hors contexte d'une formation), cf. E8quater.
const props = defineProps({ code: { type: String, default: '' } })

const MOTS_CLES = ['SELECT', 'FROM', 'WHERE', 'INNER JOIN', 'LEFT JOIN', 'ORDER BY', 'ASC', 'DESC', 'GROUP BY', "LIKE '%xxx%'", 'UCASE(', "SOUND LIKE 'xxx'"]

const bases = ref([])
const baseChoisie = ref('')
const tables = ref([])
const cles = ref([])
const relations = ref([])
const relationsIndispo = ref(false)
const tableChoisie = ref('')
const diagrammeOuvert = ref(false)

const requete = ref('')
const editeur = ref(null)
const lignes = ref(null)
const erreurRequete = ref('')
const enCours = ref(false)
const taillePolice = ref(14)
const tablette = ref(false)

const erreurChargement = ref('')
const chargement = ref(true)

const colonnes = computed(() => (lignes.value && lignes.value.length ? Object.keys(lignes.value[0]) : []))
const champs = computed(() => tables.value.find((t) => t.table_name === tableChoisie.value)?.colonnes ?? [])
const baseCourante = computed(() => bases.value.find((b) => b.code === baseChoisie.value))
const q = (nom) => `"${nom}"`

async function chargerSchema(base) {
  tables.value = []; cles.value = []; relations.value = []; relationsIndispo.value = false; tableChoisie.value = ''
  if (!base) return
  try {
    const r = await api('/atelier/schema', { params: { base } })
    tables.value = r.tables || []
  } catch (e) {
    erreurChargement.value = e.message
  }
  // Le diagramme est facultatif : si la fonction SQL n'est pas encore créée, on masque seulement lui.
  try {
    const r = await api('/atelier/relations', { params: { base } })
    cles.value = r.cles || []
    relations.value = r.relations || []
  } catch {
    relationsIndispo.value = true
  }
}

onMounted(async () => {
  try {
    bases.value = await api('/atelier/bases')
    if (bases.value.length) {
      baseChoisie.value = bases.value[0].code
      await chargerSchema(baseChoisie.value)
    }
  } catch (e) {
    erreurChargement.value = e.message
  } finally {
    chargement.value = false
  }
})

watch(baseChoisie, (b) => { lignes.value = null; erreurRequete.value = ''; chargerSchema(b) })

async function executer() {
  if (!requete.value.trim()) return
  enCours.value = true
  erreurRequete.value = ''
  lignes.value = null
  try {
    const r = await api('/atelier/executer', { method: 'POST', body: { base: baseChoisie.value, requete: requete.value } })
    lignes.value = r.lignes || []
  } catch (e) {
    erreurRequete.value = e.message
  } finally {
    enCours.value = false
  }
}

function raz() { requete.value = ''; lignes.value = null; erreurRequete.value = ''; editeur.value?.focus() }

// Équivalent de Prc_Insere : insère `texte` à la position du curseur, puis replace le curseur après.
async function inserer(texte, avant = '', apres = ' ') {
  const el = editeur.value
  const val = avant + texte + apres
  const debut = el ? el.selectionStart : requete.value.length
  requete.value = requete.value.slice(0, debut) + val + requete.value.slice(debut)
  await nextTick()
  if (el) { el.focus(); el.selectionStart = el.selectionEnd = debut + val.length }
}

// Équivalent de Prc_InsereInner : si la requête se termine par FROM, on ajoute d'abord la table.
function insererJointure(tableDepart, jointure) {
  const fin = requete.value.slice(0, editeur.value?.selectionStart ?? undefined).trim().toUpperCase().endsWith('FROM')
  inserer((fin ? ` ${q(tableDepart)} ` : ' ') + `\n${jointure}\n`, '', '')
}
function jointure(r, depuisEnfant) {
  const [t, c, tr, cr] = [r.table, r.colonne, r.table_ref, r.colonne_ref]
  return depuisEnfant
    ? insererJointure(t, `INNER JOIN ${q(tr)} ON ${q(t)}.${q(c)} = ${q(tr)}.${q(cr)}`)
    : insererJointure(tr, `INNER JOIN ${q(t)} ON ${q(tr)}.${q(cr)} = ${q(t)}.${q(c)}`)
}

// Listes Champs / Mots clés : double-clic ; en mode tablette (pas de double-clic) un simple clic suffit.
function depuisListe(e, fn, simple) {
  const v = e.target.value
  if (!v || (simple && !tablette.value)) return
  fn(v)
  if (simple) e.target.selectedIndex = -1
}

const exemples = [
  'SELECT "P_CodeProduit", "P_PrixPublic" FROM "T_Produit" ORDER BY "P_CodeProduit" DESC;',
  'SELECT "P_CodeProduit", "P_PrixPublic", "TP_LibTP"\nFROM "T_Produit" INNER JOIN "T_TypeProd" ON "T_TypeProd"."TP_CodeTP" = "T_Produit"."P_CodeTP"\nORDER BY "P_CodeProduit" DESC;'
]
function exemple(i) { requete.value = exemples[i]; editeur.value?.focus() }

// --- Diagramme : tables rangées par niveau (parents en haut), seules les colonnes clés sont affichées.
const LARG = 190, ENT = 28, LIG = 17, ECART_X = 40, ECART_Y = 64
const boites = computed(() => {
  const niveau = {}
  const n = (t, vu = []) => {
    if (niveau[t] != null) return niveau[t]
    if (vu.includes(t)) return 0
    const parents = relations.value.filter((r) => r.table === t && r.table_ref !== t).map((r) => r.table_ref)
    return (niveau[t] = parents.length ? 1 + Math.max(...parents.map((p) => n(p, [...vu, t]))) : 0)
  }
  const noms = tables.value.map((t) => t.table_name)
  noms.forEach((t) => n(t))
  const rangs = {}
  noms.forEach((t) => (rangs[niveau[t]] ||= []).push(t))
  const nbCol = Math.max(...Object.values(rangs).map((r) => r.length), 1)
  const largTotale = nbCol * LARG + (nbCol - 1) * ECART_X
  const out = {}
  let y = 12
  Object.keys(rangs).sort((a, b) => a - b).forEach((k) => {
    const rang = rangs[k]
    const dessin = rang.map((t) => {
      const pk = cles.value.filter((c) => c.table === t).map((c) => ({ nom: c.colonne, pk: true }))
      const fk = relations.value.filter((r) => r.table === t && !pk.some((p) => p.nom === r.colonne)).map((r) => ({ nom: r.colonne, fk: true }))
      const l = [...pk.map((p) => ({ ...p, fk: relations.value.some((r) => r.table === t && r.colonne === p.nom) })), ...fk]
      return { nom: t, l, h: ENT + Math.max(l.length, 1) * LIG + 8 }
    })
    const x0 = 12 + (largTotale - (rang.length * LARG + (rang.length - 1) * ECART_X)) / 2
    dessin.forEach((b, i) => { out[b.nom] = { ...b, x: x0 + i * (LARG + ECART_X), y } })
    y += Math.max(...dessin.map((b) => b.h)) + ECART_Y
  })
  return { liste: Object.values(out), par: out, w: largTotale + 24, h: y - ECART_Y + 12 }
})
const traits = computed(() => relations.value.map((r, i) => {
  const a = boites.value.par[r.table], b = boites.value.par[r.table_ref]
  if (!a || !b) return null
  // a (enfant) en bas, b (parent) en haut dans la plupart des cas ; sinon on relie les centres.
  const ax = a.x + LARG / 2, bx = b.x + LARG / 2
  const ay = a.y > b.y ? a.y : a.y + a.h, by = a.y > b.y ? b.y + b.h : b.y
  return { i, r, ax, ay, bx, by, mx: (ax + bx) / 2, my: (ay + by) / 2 }
}).filter(Boolean))
</script>

<template>
  <div class="space-y-4">
    <RouterLink v-if="code" :to="{ name: 'atelier', params: { code } }" class="text-sm text-alag-navy hover:underline">‹ Retour à l'atelier SQL</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Entraînement libre{{ code ? '' : ' — vue formateur' }}</h1>

    <p v-if="erreurChargement" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurChargement }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <!-- Barre du haut : base, choix de table, exemples -->
      <section class="sc-card flex flex-wrap items-end gap-x-6 gap-y-3 p-4">
        <label class="text-sm font-medium text-slate-700">
          Base de données
          <select v-model="baseChoisie" class="sc-input mt-1 block min-w-[12rem]">
            <option v-for="b in bases" :key="b.code" :value="b.code">{{ b.nom }}</option>
          </select>
        </label>
        <label class="text-sm font-medium text-slate-700">
          Choix table
          <span class="mt-1 flex gap-1.5">
            <select v-model="tableChoisie" class="sc-input block min-w-[12rem]">
              <option value="">Choisir une table…</option>
              <option v-for="t in tables" :key="t.table_name" :value="t.table_name">{{ t.table_name }}</option>
            </select>
            <button type="button" class="sc-btn-ghost" :disabled="!tableChoisie" @click="inserer(q(tableChoisie), ' ', ' ')">Insérer</button>
          </span>
        </label>
        <div class="text-sm font-medium text-slate-700">
          Exemple
          <div class="mt-1 flex gap-1.5">
            <button type="button" class="sc-btn-ghost min-w-[44px]" @click="exemple(0)">1</button>
            <button type="button" class="sc-btn-ghost min-w-[44px]" @click="exemple(1)">2</button>
          </div>
        </div>
        <p v-if="baseCourante?.description" class="basis-full text-xs text-slate-500">{{ baseCourante.description }}</p>
      </section>

      <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_16rem]">
        <!-- Éditeur + résultat -->
        <section class="sc-card space-y-3 p-4">
          <label class="block text-sm font-medium text-slate-700" for="sql-editeur">Votre requête (SELECT uniquement)</label>
          <textarea id="sql-editeur" ref="editeur" v-model="requete" rows="9" spellcheck="false"
                    class="sc-input block w-full font-mono" :style="{ fontSize: taillePolice + 'px' }"
                    placeholder='SELECT * FROM "T_Produit"' @keydown.ctrl.enter.prevent="executer"></textarea>

          <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <button type="button" class="sc-btn-accent min-w-[72px]" :disabled="enCours || !requete.trim()" title="Exécuter (Ctrl+Entrée)" @click="executer">
              {{ enCours ? '…' : 'GO' }}
            </button>
            <span class="text-sm text-slate-600">Lignes : <strong class="tabular-nums text-slate-900">{{ lignes ? lignes.length : '' }}</strong></span>
            <div class="flex items-center gap-1.5 text-sm text-slate-600">
              Police
              <button type="button" class="sc-btn-ghost h-9 min-w-[36px] px-2" aria-label="Réduire la police" @click="taillePolice = Math.max(10, taillePolice - 1)">−</button>
              <button type="button" class="sc-btn-ghost h-9 min-w-[36px] px-2" aria-label="Agrandir la police" @click="taillePolice = Math.min(24, taillePolice + 1)">+</button>
            </div>
            <button type="button" class="sc-btn-ghost" @click="raz">RAZ</button>
            <label class="flex min-h-[44px] items-center gap-2 text-sm text-slate-600">
              <input v-model="tablette" type="checkbox" class="h-4 w-4 rounded border-slate-300"> Mode tablette
            </label>
          </div>

          <p v-if="erreurRequete" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreurRequete }}</p>

          <div v-if="lignes" class="overflow-x-auto">
            <p v-if="!lignes.length" class="text-sm text-slate-600">Aucune ligne renvoyée.</p>
            <table v-else class="min-w-full text-sm">
              <thead class="sticky top-0 bg-alag-navy text-left text-white">
                <tr><th v-for="c in colonnes" :key="c" class="px-3 py-1.5 font-mono text-xs font-medium">{{ c }}</th></tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="(l, i) in lignes" :key="i" class="hover:bg-paper">
                  <td v-for="c in colonnes" :key="c" class="px-3 py-1.5 font-mono text-xs tabular-nums text-slate-700">{{ l[c] }}</td>
                </tr>
              </tbody>
            </table>
            <p v-if="lignes.length === 200" class="mt-2 text-xs text-slate-500">Affichage limité aux 200 premières lignes.</p>
          </div>
        </section>

        <!-- Colonne de droite : champs + mots clés -->
        <aside class="space-y-4">
          <section class="sc-card p-3">
            <label class="mb-1 block text-sm font-medium text-slate-700" for="liste-champs">
              Champs <span v-if="tableChoisie" class="font-mono text-xs text-slate-500">· {{ tableChoisie }}</span>
            </label>
            <select id="liste-champs" size="8" class="sc-input block w-full font-mono text-xs"
                    @dblclick="depuisListe($event, (v) => inserer(q(v), '', ' '), false)"
                    @click="depuisListe($event, (v) => inserer(q(v), '', ' '), true)">
              <option v-for="c in champs" :key="c.colonne" :value="c.colonne" :title="c.type">{{ c.colonne }}</option>
            </select>
            <p class="mt-1 text-xs text-slate-500">
              {{ tableChoisie ? (tablette ? 'Clic' : 'Double-clic') + ' pour insérer.' : 'Choisissez une table pour voir ses champs.' }}
            </p>
          </section>
          <section class="sc-card p-3">
            <label class="mb-1 block text-sm font-medium text-slate-700" for="liste-mots">Mots clés</label>
            <select id="liste-mots" size="8" class="sc-input block w-full font-mono text-xs"
                    @dblclick="depuisListe($event, (v) => inserer(v, ' ', ' '), false)"
                    @click="depuisListe($event, (v) => inserer(v, ' ', ' '), true)">
              <option v-for="m in MOTS_CLES" :key="m" :value="m">{{ m }}</option>
            </select>
            <p class="mt-1 text-xs text-slate-500">{{ tablette ? 'Clic' : 'Double-clic' }} pour insérer.</p>
          </section>
        </aside>
      </div>

      <!-- Diagramme des relations -->
      <section class="sc-card p-4">
        <button type="button" class="sc-btn-ghost" :aria-expanded="diagrammeOuvert" @click="diagrammeOuvert = !diagrammeOuvert">
          {{ diagrammeOuvert ? 'Masquer' : 'Afficher' }} le diagramme des relations
        </button>
        <template v-if="diagrammeOuvert">
          <p v-if="relationsIndispo" class="mt-3 text-sm text-slate-600">Diagramme indisponible pour le moment.</p>
          <template v-else>
            <p class="mt-3 text-xs text-slate-500">Cliquez sur un trait, côté de la table de départ, pour insérer la jointure (INNER JOIN) dans la requête.</p>
            <div class="mt-2 overflow-x-auto">
              <svg :viewBox="`0 0 ${boites.w} ${boites.h}`" :width="boites.w" :height="boites.h" class="mx-auto max-w-none" role="img" aria-label="Diagramme des relations entre les tables">
                <g v-for="t in traits" :key="t.i">
                  <line :x1="t.ax" :y1="t.ay" :x2="t.bx" :y2="t.by" stroke="#94a3b8" stroke-width="1.5" />
                  <!-- moitié côté enfant : jointure enfant → parent ; moitié côté parent : parent → enfant -->
                  <line :x1="t.ax" :y1="t.ay" :x2="t.mx" :y2="t.my" stroke="transparent" stroke-width="16" class="cursor-pointer hover:stroke-orange-200"
                        @click="jointure(t.r, true)"><title>{{ t.r.table }} → INNER JOIN {{ t.r.table_ref }}</title></line>
                  <line :x1="t.mx" :y1="t.my" :x2="t.bx" :y2="t.by" stroke="transparent" stroke-width="16" class="cursor-pointer hover:stroke-orange-200"
                        @click="jointure(t.r, false)"><title>{{ t.r.table_ref }} → INNER JOIN {{ t.r.table }}</title></line>
                </g>
                <g v-for="b in boites.liste" :key="b.nom">
                  <rect :x="b.x" :y="b.y" :width="LARG" :height="b.h" rx="4" fill="#fff" stroke="#1F4C73" />
                  <rect :x="b.x" :y="b.y" :width="LARG" :height="ENT" rx="4" fill="#1F4C73" />
                  <text :x="b.x + 8" :y="b.y + 18" fill="#fff" font-size="12" font-weight="600" font-family="ui-monospace, monospace">{{ b.nom }}</text>
                  <text v-for="(c, i) in b.l" :key="c.nom" :x="b.x + 8" :y="b.y + ENT + 14 + i * LIG" font-size="11" font-family="ui-monospace, monospace"
                        :font-weight="c.pk ? 700 : 400" :fill="c.fk ? '#C2410C' : '#334155'">{{ c.pk ? 'PK ' : '' }}{{ c.nom }}{{ c.fk ? ' →' : '' }}</text>
                </g>
              </svg>
            </div>
          </template>
        </template>
      </section>
    </template>
  </div>
</template>
