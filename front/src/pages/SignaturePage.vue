<script setup>
// Remplace PAGE_Signe/PAGE_Signature d'al-cours : liste des séances de la formation, signature à
// main levée (canvas HTML natif, sans dépendance externe) pour chaque séance non encore signée.
// Hors créneau (au-delà de la marge de tolérance), signature bloquée sauf déblocage formateur
// explicite (voir api/routes/signature.php — remplace l'ancien "code" = heure courante, faille
// de sécurité signalée en étape 1).
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { dateFr } from '@/utils'

const props = defineProps({ code: { type: String, required: true } })
const auth = useAuthStore()

const seances = ref([])
const chargement = ref(true)
const erreur = ref('')
const seanceEnCours = ref(null) // numCours de la séance en train d'être signée
const envoiEnCours = ref(false)

const canvas = ref(null)
let ctx = null
let dessine = false
let aTrace = false

async function charger() {
  chargement.value = true
  erreur.value = ''
  try {
    seances.value = await api(`/formations/${props.code}/signature`)
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargement.value = false
  }
}

function peutSigner(s) {
  return !s.signe && (s.dansFenetre || s.debloque)
}

async function ouvrirSignature(s) {
  if (!peutSigner(s)) return
  seanceEnCours.value = s.numCours
  await nextTick()
  initCanvas()
}

function fermerSignature() {
  seanceEnCours.value = null
}

function initCanvas() {
  const c = canvas.value
  if (!c) return
  const ratio = window.devicePixelRatio || 1
  c.width = c.clientWidth * ratio
  c.height = c.clientHeight * ratio
  ctx = c.getContext('2d')
  ctx.scale(ratio, ratio)
  ctx.lineWidth = 2
  ctx.lineCap = 'round'
  ctx.strokeStyle = '#0f2a3d'
  aTrace = false
}

function position(evt) {
  const rect = canvas.value.getBoundingClientRect()
  const p = evt.touches ? evt.touches[0] : evt
  return { x: p.clientX - rect.left, y: p.clientY - rect.top }
}

function debut(evt) {
  evt.preventDefault()
  dessine = true
  aTrace = true
  const { x, y } = position(evt)
  ctx.beginPath()
  ctx.moveTo(x, y)
}
function trace(evt) {
  if (!dessine) return
  evt.preventDefault()
  const { x, y } = position(evt)
  ctx.lineTo(x, y)
  ctx.stroke()
}
function fin() { dessine = false }

function effacer() {
  const c = canvas.value
  ctx.clearRect(0, 0, c.width, c.height)
  aTrace = false
}

async function valider() {
  if (!aTrace) { erreur.value = 'Veuillez signer avant de valider.'; return }
  envoiEnCours.value = true
  erreur.value = ''
  try {
    const image = canvas.value.toDataURL('image/png')
    await api(`/formations/${props.code}/signature/${seanceEnCours.value}`, { method: 'POST', body: { image } })
    seanceEnCours.value = null
    await charger()
  } catch (e) {
    erreur.value = e.message
  } finally {
    envoiEnCours.value = false
  }
}

onMounted(charger)
onUnmounted(() => { ctx = null })
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'formation', params: { code } }" class="text-sm text-alag-navy hover:underline">‹ Retour à la formation</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Signature de présence</h1>

    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>
    <p v-else-if="chargement" class="text-sm text-slate-500">Chargement…</p>

    <section v-else class="sc-card overflow-hidden">
      <p v-if="!seances.length" class="p-4 text-sm text-slate-600">Aucune séance planifiée pour votre groupe.</p>
      <ul v-else class="divide-y divide-slate-100 text-sm">
        <li v-for="s in seances" :key="s.numCours" class="px-4 py-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span>
              <span class="font-medium capitalize">{{ dateFr(s.date, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' }) }}</span>
              <span class="ml-2 text-slate-600">{{ s.heureDebut }}–{{ s.heureFin }}</span>
              <span v-if="s.salle" class="ml-2 text-xs text-slate-500">{{ s.salle }}</span>
            </span>

            <span v-if="s.signe" class="text-xs font-medium text-green-700">✓ Signée</span>
            <button v-else-if="peutSigner(s)" type="button"
                    class="rounded-lg bg-alag-navy px-3 py-1.5 text-xs font-medium text-white hover:bg-alag-navy"
                    @click="ouvrirSignature(s)">
              {{ s.debloque && !s.dansFenetre ? 'Signer (débloqué par le formateur)' : 'Signer' }}
            </button>
            <span v-else class="text-xs text-slate-500">Hors créneau — demandez à votre formateur</span>
          </div>

          <div v-if="seanceEnCours === s.numCours" class="mt-3 rounded-lg border border-slate-200 p-3">
            <p class="mb-2 text-xs text-slate-500">Signez dans le cadre ci-dessous ({{ auth.nomComplet }}) :</p>
            <!-- :ref en callback (pas ref="canvas" simple) : cet élément est dans un v-for, Vue
                 collecterait sinon tous les canvas en tableau plutôt qu'une seule référence. Un
                 seul canvas existe dans le DOM à la fois (v-if ci-dessus), donc l'affectation
                 directe suffit. -->
            <canvas :ref="(el) => (canvas = el)" class="h-40 w-full touch-none rounded-md border border-slate-300 bg-white"
                    @mousedown="debut" @mousemove="trace" @mouseup="fin" @mouseleave="fin"
                    @touchstart="debut" @touchmove="trace" @touchend="fin" />
            <div class="mt-2 flex flex-wrap gap-2">
              <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-600 hover:border-alag-navy" @click="effacer">Effacer</button>
              <button type="button" class="rounded-lg bg-alag-navy px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50" :disabled="envoiEnCours" @click="valider">
                {{ envoiEnCours ? 'Envoi…' : 'Valider ma signature' }}
              </button>
              <button type="button" class="rounded-lg px-3 py-1.5 text-xs text-slate-500 hover:underline" @click="fermerSignature">Annuler</button>
            </div>
          </div>
        </li>
      </ul>
    </section>
  </div>
</template>
