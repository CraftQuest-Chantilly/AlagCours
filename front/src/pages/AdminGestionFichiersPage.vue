<script setup>
import Icone from '@/components/Icone.vue'
// Formateur — PAGE_Fichiers_Adm (26/09/2026, revu le même jour en 4 colonnes plein écran sur
// demande de Jérôme). REMPLACE ENTIÈREMENT l'ancien flux AdminPromosPage.vue /
// AdminPromoFichiersPage.vue. 4 colonnes, chacune son rôle :
//   COL 1 : arborescence complète du serveur + recherche de fichier par nom (avec téléchargement
//           pour vérification côté formateur, sans rapport avec les droits stagiaire) + sélection
//           multiple par case à cocher (persiste en changeant de dossier, décoche automatiquement
//           une fois les fichiers ajoutés à un regroupement).
//   COL 2 : bandeau créer-dossier/upload (agit sur le dossier sélectionné en col 1) + client
//           (filtrable) + cours du client (à venir/passés/dates perso).
//   COL 3 : promo ciblée par le cours cliqué en col 2 + regroupement (TD) + options avancées +
//           bouton d'ajout groupé.
//   COL 4 : aperçu en direct de ce que voient les stagiaires (groupé par TD, comme
//           RessourcesPage.vue), avec cases à cocher Visible / Fait directement dedans.
//
// Le fichier physique n'est jamais touché par l'ajout : "ajouter à un regroupement" = poser un
// droit (T_Fichier_Droits), comme dans l'ancien écran.
import { computed, ref, watch } from 'vue'
import { api, apiTelechargerBrut, sauvegarderBlob } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import jsPDF from 'jspdf'

const auth = useAuthStore()
const erreur = ref('')

// ─── COL 1 : arborescence + recherche + sélection multiple (case à cocher) ─────────────────

const pileDossiers = ref([]) // fil d'ariane [{numFic, nom}]
const dossierCourant = computed(() => pileDossiers.value.at(-1)?.numFic ?? null)
const contenuDossier = ref([])
const chargementDossier = ref(false)

// Panier de sélection : persiste en naviguant d'un dossier à l'autre, et à travers la recherche.
const selection = ref(new Map())

async function chargerDossier() {
  chargementDossier.value = true
  erreur.value = ''
  try {
    const chemin = dossierCourant.value ? `/admin/ressources/${dossierCourant.value}` : '/admin/ressources'
    const r = await api(chemin)
    contenuDossier.value = r.fichiers
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargementDossier.value = false
  }
}
chargerDossier()

async function ouvrirDossier(numFic, nom) {
  if (numFic !== null) pileDossiers.value.push({ numFic, nom })
  else pileDossiers.value = []
  await chargerDossier()
}

async function remonterA(index) {
  pileDossiers.value = pileDossiers.value.slice(0, index + 1)
  await chargerDossier()
}

function estCoche(f) {
  return selection.value.has(f.numFic)
}

function basculerSelection(f) {
  const s = new Map(selection.value)
  if (s.has(f.numFic)) s.delete(f.numFic)
  else s.set(f.numFic, { nom: f.nom, type: f.type })
  selection.value = s
}

const nbSelectionnes = computed(() => selection.value.size)

function viderSelection() {
  selection.value = new Map()
}

// Recherche de fichier par nom (dans tout l'arbre, pas juste le dossier courant) — remplace la
// navigation dossier par dossier quand on connaît le nom. Bascule automatiquement l'affichage de
// la colonne : arbre si recherche vide, résultats sinon.
const qRecherche = ref('')
const resultatsRecherche = ref([])
const rechercheEnCours = ref(false)
let debounceRecherche = null
const rechercheActive = computed(() => qRecherche.value.trim().length >= 2)

async function rechercherFichiers() {
  if (!rechercheActive.value) { resultatsRecherche.value = []; return }
  rechercheEnCours.value = true
  try {
    resultatsRecherche.value = await api('/admin/ressources/recherche', { params: { q: qRecherche.value.trim() } })
  } catch (e) {
    erreur.value = e.message
  } finally {
    rechercheEnCours.value = false
  }
}

watch(qRecherche, () => {
  clearTimeout(debounceRecherche)
  debounceRecherche = setTimeout(rechercherFichiers, 300)
})

// Téléchargement par le formateur (vérification du contenu), séparé du téléchargement stagiaire
// journalisé — même fichier utilisable depuis l'arbre ou depuis les résultats de recherche.
const telechargementEnCours = ref(null)

async function telechargerFichier(f) {
  if (f.type === 'L') { window.open(f.url, '_blank', 'noopener'); return }
  if (f.type !== 'F') return
  telechargementEnCours.value = f.numFic
  erreur.value = ''
  try {
    const { blob, nom } = await apiTelechargerBrut(`/admin/ressources/${f.numFic}/telecharger`, f.nom)
    sauvegarderBlob(blob, nom)
  } catch (e) {
    erreur.value = e.message
  } finally {
    telechargementEnCours.value = null
  }
}

// ─── COL 2 (haut) : créer un dossier / déposer un fichier dans le dossier sélectionné ───────

const nouveauDossierNom = ref('')
const creationDossierEnCours = ref(false)
const uploadEnCours = ref(false)
const inputFichier = ref(null)

async function creerDossier() {
  const nom = nouveauDossierNom.value.trim()
  if (!nom) return
  creationDossierEnCours.value = true
  erreur.value = ''
  try {
    // Racine = 0 (le serveur gère cette convention).
    await api(`/admin/ressources/${dossierCourant.value ?? 0}/dossier`, { method: 'POST', body: { nom } })
    nouveauDossierNom.value = ''
    await chargerDossier()
  } catch (e) {
    erreur.value = e.message
  } finally {
    creationDossierEnCours.value = false
  }
}

async function deposerFichier(evenement) {
  const fichier = evenement.target.files?.[0]
  if (!fichier || dossierCourant.value === null) return
  uploadEnCours.value = true
  erreur.value = ''
  try {
    const donnees = new FormData()
    donnees.append('fichier', fichier)
    const base = `${import.meta.env.BASE_URL}api/index.php`
    const rep = await fetch(`${base}?route=${encodeURIComponent(`/admin/ressources/${dossierCourant.value}/fichier`)}`, {
      method: 'POST',
      headers: auth.jeton ? { Authorization: `Bearer ${auth.jeton}` } : {},
      body: donnees,
    })
    const data = await rep.json().catch(() => null)
    if (!rep.ok) throw new Error(data?.erreur || `Erreur ${rep.status}`)
    await chargerDossier()
  } catch (e) {
    erreur.value = e.message
  } finally {
    uploadEnCours.value = false
    if (inputFichier.value) inputFichier.value.value = ''
  }
}

// ─── COL 2 (bas) : client → cours (à venir / passés) ────────────────────────────────────────

const qClient = ref('')
const clients = ref([])
const rechercheClientEnCours = ref(false)
let debounceClient = null

async function chercherClients() {
  rechercheClientEnCours.value = true
  try {
    clients.value = await api('/admin/clients', { params: { q: qClient.value || undefined } })
  } catch (e) {
    erreur.value = e.message
  } finally {
    rechercheClientEnCours.value = false
  }
}
chercherClients()

watch(qClient, () => {
  clearTimeout(debounceClient)
  debounceClient = setTimeout(chercherClients, 300)
})

const clientChoisi = ref(null)
const periode = ref('avenir') // 'avenir' | 'passes' | 'personnalise'
const depuisPerso = ref('')
const jusquaPerso = ref('')
const cours = ref([])
const chargementCours = ref(false)

function ajd() {
  return new Date().toISOString().slice(0, 10)
}

async function chargerCours() {
  if (!clientChoisi.value) return
  chargementCours.value = true
  erreur.value = ''
  try {
    let params = {}
    if (periode.value === 'avenir') params = { depuis: ajd() }
    else if (periode.value === 'passes') params = { jusqua: ajd() }
    else params = { depuis: depuisPerso.value || undefined, jusqua: jusquaPerso.value || undefined }
    cours.value = await api(`/admin/clients/${clientChoisi.value.code}/cours`, { params })
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargementCours.value = false
  }
}

function choisirClient(c) {
  clientChoisi.value = c
  coursChoisi.value = null
  chargerCours()
}

watch(periode, chargerCours)
watch([depuisPerso, jusquaPerso], () => { if (periode.value === 'personnalise') chargerCours() })

const coursChoisi = ref(null)
const promoCible = computed(() => (coursChoisi.value
  ? { code: coursChoisi.value.codePromo, libelle: coursChoisi.value.libellePromo }
  : null))

function choisirCours(c) {
  coursChoisi.value = c
  chargerApercu()
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString('fr-FR')
}

// ─── COL 3 : promo ciblée + regroupement (TD) + ajout groupé ────────────────────────────────

const regroupementTD = ref('')
const optionsAvancees = ref({ groupe: '-', visible: true, ordre: '', commentaire: '', debut: '', fin: '' })
const groupesDisponibles = ref([])
const ajoutEnCours = ref(false)
const dernierResultat = ref(null)

async function chargerGroupes() {
  if (!promoCible.value) return
  try {
    groupesDisponibles.value = await api(`/admin/promos/${promoCible.value.code}/groupes`)
  } catch {
    groupesDisponibles.value = []
  }
}

const peutAjouter = computed(() => !!promoCible.value && regroupementTD.value.trim() !== '' && nbSelectionnes.value > 0 && !ajoutEnCours.value)

async function ajouterAuRegroupement() {
  if (!peutAjouter.value) return
  ajoutEnCours.value = true
  erreur.value = ''
  dernierResultat.value = null
  try {
    const numFics = [...selection.value.keys()].map(Number)
    const r = await api(`/admin/promos/${promoCible.value.code}/droits/lot`, {
      method: 'POST',
      body: { numFics, td: regroupementTD.value.trim(), ...optionsAvancees.value },
    })
    dernierResultat.value = r
    viderSelection() // décoche automatiquement les fichiers une fois ajoutés
    await chargerApercu()
  } catch (e) {
    erreur.value = e.message
  } finally {
    ajoutEnCours.value = false
  }
}

watch(promoCible, (p) => { if (p) chargerGroupes() })

// ─── COL 3 (bas) : déblocage temporaire des signatures pour toute la promo (E10) ────────────

const deblocageSignatureEnCours = ref(false)
const deblocageSignatureExpiration = ref(null) // Date | null, tant que le déblocage est actif

async function debloquerSignaturesPromo() {
  if (!promoCible.value) return
  deblocageSignatureEnCours.value = true
  erreur.value = ''
  try {
    const r = await api(`/admin/promos/${promoCible.value.code}/signatures/deblocage`, { method: 'POST' })
    deblocageSignatureExpiration.value = new Date(r.expiration)
  } catch (e) {
    erreur.value = e.message
  } finally {
    deblocageSignatureEnCours.value = false
  }
}

// Remis à zéro dès qu'on change de promo ciblée (l'affichage "jusqu'à HH:MM" ne doit pas persister
// visuellement sur une autre promo que celle réellement débloquée).
watch(promoCible, () => { deblocageSignatureExpiration.value = null })

function heureMinute(d) {
  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

// ─── COL 3 (bas) : feuille de présence — récupération des signatures (E11) ──────────────────
// Remplace PAGE_Feuille_Presence de l'ancien site. Assemblé CÔTÉ NAVIGATEUR (jsPDF) plutôt que
// côté PHP : même choix que le zip groupé (E7) et l'import Excel (E8bis), faute d'extension PDF
// fiable sur l'hébergement OVH mutualisé.

const feuillePresenceEnCours = ref(null) // 'seance' | 'promo' | null

function blobVersDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const lecteur = new FileReader()
    lecteur.onload = () => resolve(lecteur.result)
    lecteur.onerror = reject
    lecteur.readAsDataURL(blob)
  })
}

/** Redimensionne une image (dataURL) à une largeur cible et l'aplatit en JPEG (fond blanc) avant
 *  de l'embarquer dans le PDF — sans ça, jsPDF embarque le PNG source en pixels non recompressés
 *  (un logo RGBA de 1594×656 gonflait chaque PDF à ~4 Mo à lui seul, testé en sandbox). */
function redimensionnerEnJpeg(dataUrl, largeurCible) {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => {
      const echelle = largeurCible / img.width
      const canvas = document.createElement('canvas')
      canvas.width = largeurCible
      canvas.height = Math.round(img.height * echelle)
      const ctx = canvas.getContext('2d')
      ctx.fillStyle = '#fff'
      ctx.fillRect(0, 0, canvas.width, canvas.height)
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
      resolve(canvas.toDataURL('image/jpeg', 0.85))
    }
    img.onerror = reject
    img.src = dataUrl
  })
}

function dureeHHMM(debut, fin) {
  const [hd, md] = debut.split(':').map(Number)
  const [hf, mf] = fin.split(':').map(Number)
  let total = (hf * 60 + mf) - (hd * 60 + md)
  if (total < 0) total += 24 * 60
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

function formatDateLongue(d) {
  return new Date(d).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

/** Récupère l'image (dataURL) de chaque signature déjà posée pour la séance — pas d'appel pour
 *  les stagiaires non signés (rien à récupérer). */
async function chargerImagesSignatures(seance) {
  for (const st of seance.stagiaires) {
    if (st.signe) {
      try {
        const { blob } = await apiTelechargerBrut(`/admin/cours/${seance.numCours}/signatures/${st.codeStagiaire}/image`)
        st.imageDataUrl = await blobVersDataUrl(blob)
      } catch {
        st.imageDataUrl = null
      }
    }
  }
}

/** Dessine une page « feuille de présence » pour une séance : en-tête (client, cours, horaires,
 *  salle, formateur, nb heures) + grille 2 colonnes nom/signature, reprenant la mise en page du
 *  document papier d'origine. */
function dessinerPageSeance(doc, promo, seance, logoDataUrl) {
  const marge = 14
  let y = marge

  if (logoDataUrl) doc.addImage(logoDataUrl, 'JPEG', marge, y, 40, 16.5)
  doc.setFontSize(16)
  doc.text('Feuille de présence', 105, y + 8, { align: 'center' })
  y += 22

  doc.setFontSize(10)
  doc.text(`Client : ${promo.client || '—'}`, marge, y)
  doc.text(`Nb Heures total : ${dureeHHMM(seance.heureDebut, seance.heureFin)}`, 140, y)
  y += 6
  doc.text(`Cours : ${promo.libelle}${promo.sujet ? ' — ' + promo.sujet : ''}`, marge, y)
  y += 8

  doc.setFillColor(230, 230, 230)
  doc.rect(marge, y, 182, 7, 'F')
  doc.setFontSize(9)
  doc.text(`Le ${formatDateLongue(seance.date)}   De ${seance.heureDebut} à ${seance.heureFin}`, marge + 2, y + 5)
  doc.text(`Formateur ${seance.formateur || '—'}`, marge + 90, y + 5)
  doc.text(`Salle ${seance.salle || '—'}`, marge + 140, y + 5)
  y += 14

  const colLargeur = 91
  const ligneHauteur = 26
  let col = 0
  let yLigne = y
  for (const st of seance.stagiaires) {
    if (yLigne > 275) { doc.addPage(); yLigne = marge; col = 0 }
    const x = marge + col * colLargeur
    doc.setDrawColor(180)
    doc.rect(x, yLigne, colLargeur - 2, ligneHauteur)
    doc.setFontSize(9)
    doc.setTextColor(0)
    doc.text(`${st.nom} ${st.prenom}`, x + 2, yLigne + 5)
    if (st.signe && st.imageDataUrl) {
      try { doc.addImage(st.imageDataUrl, 'PNG', x + 2, yLigne + 7, colLargeur - 6, ligneHauteur - 9) } catch { /* image illisible, case laissée vide */ }
    } else {
      doc.setFontSize(8)
      doc.setTextColor(150)
      doc.text('Non signé', x + 2, yLigne + ligneHauteur - 4)
    }
    col++
    if (col === 2) { col = 0; yLigne += ligneHauteur }
  }
}

/** perimetre : 'seance' (celle sélectionnée en colonne 2) ou 'promo' (toutes les séances, une
 *  page par séance). */
async function telechargerFeuillePresence(perimetre) {
  if (!promoCible.value) return
  feuillePresenceEnCours.value = perimetre
  erreur.value = ''
  try {
    const params = perimetre === 'seance' && coursChoisi.value ? { numCours: coursChoisi.value.numCours } : {}
    const promo = await api(`/admin/promos/${promoCible.value.code}/signatures`, { params })
    if (!promo.seances.length) { erreur.value = 'Aucune séance trouvée.'; return }

    for (const seance of promo.seances) await chargerImagesSignatures(seance)

    let logoDataUrl = null
    try {
      const repLogo = await fetch(`${import.meta.env.BASE_URL}logo-full.png`)
      const brut = await blobVersDataUrl(await repLogo.blob())
      logoDataUrl = await redimensionnerEnJpeg(brut, 300) // suffisant pour ~40mm de large dans le PDF
    } catch { /* logo optionnel, le PDF reste utilisable sans */ }

    const doc = new jsPDF()
    promo.seances.forEach((seance, i) => {
      if (i > 0) doc.addPage()
      dessinerPageSeance(doc, promo, seance, logoDataUrl)
    })

    const nomBase = perimetre === 'seance'
      ? `feuille-presence_${promo.libelle}_${promo.seances[0].date}`
      : `feuilles-presence_${promo.libelle}`
    doc.save(`${nomBase.replace(/[\\/:*?"<>|]/g, '_')}.pdf`)
  } catch (e) {
    erreur.value = e.message
  } finally {
    feuillePresenceEnCours.value = null
  }
}

// ─── COL 3 (bas) : évaluation de la formation — récapitulatifs PDF (E12, 30/09/2026) ────────
// Remplace PAGE_Eval_Recap / PAGE_Eval_Detail de l'ancien site. Même approche que la feuille
// de présence (E11) : assemblage jsPDF côté navigateur, logo réutilisé via les helpers
// blobVersDataUrl/redimensionnerEnJpeg déjà définis ci-dessus.
//
// Définition des 3 moyennes du récap détaillé, telles que demandées par Jérôme le 30/09/2026 :
//   - moyenneGenerale            : moyenne de TOUTES les réponses du stagiaire (0 compris).
//   - moyenneSansZero            : moyenne des réponses non nulles uniquement.
//   - moyenneSansEtoileSansZero  : moyenne des réponses non nulles, EXCLUANT aussi les
//                                  questions dont le libellé se termine par « ** » (3 des 14
//                                  questions du quiz "Evaluation Alaguillaume", vérifié en base
//                                  le 30/09/2026 : codes 223/224/225).

const evaluationEnCours = ref(null) // 'anonyme' | 'detail' | null

/** Une question est "étoilée" si son libellé se termine par ** (espaces ignorés). */
function estQuestionEtoile(libelle) {
  return /\*\*\s*$/.test((libelle || '').trim())
}

function formatMoyenne(v) {
  return v === null || v === undefined ? '—' : v.toFixed(1).replace('.', ',')
}

/** Calcule les 3 moyennes d'un stagiaire à partir de ses réponses brutes. */
function calculerMoyennes(reponses, questions) {
  const parQuestion = new Map(questions.map((q) => [q.code, q]))
  let sommeGen = 0, nGen = 0
  let sommeSansZero = 0, nSansZero = 0
  let sommeFiltree = 0, nFiltree = 0
  for (const r of reponses) {
    sommeGen += r.valeur
    nGen++
    if (r.valeur !== 0) {
      sommeSansZero += r.valeur
      nSansZero++
      const q = parQuestion.get(r.codeQuest)
      if (!q || !estQuestionEtoile(q.libelle)) {
        sommeFiltree += r.valeur
        nFiltree++
      }
    }
  }
  return {
    moyenneGenerale: nGen ? sommeGen / nGen : null,
    moyenneSansZero: nSansZero ? sommeSansZero / nSansZero : null,
    moyenneSansEtoileSansZero: nFiltree ? sommeFiltree / nFiltree : null,
  }
}

/** Moyenne d'une question donnée, tous stagiaires confondus (recap anonyme). */
function moyenneParQuestion(stagiaires, codeQuest) {
  const valeurs = stagiaires
    .map((s) => s.reponses.find((r) => r.codeQuest === codeQuest))
    .filter((r) => r !== undefined)
    .map((r) => r.valeur)
  return valeurs.length ? valeurs.reduce((a, b) => a + b, 0) / valeurs.length : null
}

/** Barre de progression simple (fond gris clair + remplissage bleu proportionnel). */
function dessinerBarre(doc, x, y, largeur, hauteur, fraction) {
  const f = Math.max(0, Math.min(1, fraction || 0))
  doc.setFillColor(225, 225, 225)
  doc.rect(x, y, largeur, hauteur, 'F')
  doc.setFillColor(30, 64, 175) // bleu alag
  doc.rect(x, y, largeur * f, hauteur, 'F')
}

/** Page unique : récapitulatif anonyme (moyenne par question, sur tous les stagiaires ayant
 *  répondu). Reprend la mise en page de la capture "Eval Formation Recap" fournie par Jérôme. */
function dessinerPageRecapAnonyme(doc, promo, noteSur, questions, stagiaires, logoDataUrl) {
  const marge = 14
  let y = marge

  if (logoDataUrl) doc.addImage(logoDataUrl, 'JPEG', marge, y, 40, 16.5)
  doc.setFontSize(16)
  doc.setTextColor(0)
  doc.text('Récapitulatif évaluation (anonyme)', 105, y + 8, { align: 'center' })
  doc.setFontSize(9)
  doc.text(new Date().toLocaleDateString('fr-FR'), 196, y, { align: 'right' })
  y += 22

  doc.setFontSize(10)
  doc.text(`Client : ${promo.client || '—'}`, marge, y)
  y += 6
  doc.text(`Cours : ${promo.libelle}${promo.sujet ? ' — ' + promo.sujet : ''}`, marge, y)
  y += 10

  const xBarre = 100
  const largeurBarre = 78
  const moyennesQuestions = []
  doc.setFontSize(9)
  for (const q of questions) {
    if (y > 270) { doc.addPage(); y = marge }
    const moyenne = moyenneParQuestion(stagiaires, q.code)
    moyennesQuestions.push(moyenne)
    const libelle = doc.splitTextToSize(q.libelle, 82)
    doc.setTextColor(0)
    doc.text(libelle, marge, y + 3)
    dessinerBarre(doc, xBarre, y, largeurBarre, 4, (moyenne ?? 0) / noteSur)
    doc.text(formatMoyenne(moyenne), 196, y + 3, { align: 'right' })
    y += Math.max(libelle.length * 4, 8) + 3
  }

  y += 3
  doc.setDrawColor(150)
  doc.line(marge, y, 196, y)
  y += 6

  const valeurs = moyennesQuestions.filter((m) => m !== null)
  const moyenneGenerale = valeurs.length ? valeurs.reduce((a, b) => a + b, 0) / valeurs.length : null

  doc.setFontSize(10)
  doc.setFont(undefined, 'bold')
  doc.text(`Nombre de lignes : ${questions.length}`, marge, y)
  doc.text(`Moyenne générale : ${formatMoyenne(moyenneGenerale)}`, 196, y, { align: 'right' })
  doc.setFont(undefined, 'normal')
}

/** Une page par stagiaire : détail de ses réponses + ses 3 moyennes. Reprend la mise en page de
 *  la capture "Évaluation stagiaire de BEARINGPOINT" fournie par Jérôme. */
function dessinerPageDetailEvaluation(doc, promo, noteSur, questions, stagiaire, logoDataUrl) {
  const marge = 14
  let y = marge

  if (logoDataUrl) doc.addImage(logoDataUrl, 'JPEG', 150, y, 40, 16.5)
  doc.setFontSize(13)
  doc.setTextColor(0)
  doc.text(`Formation : ${promo.libelle}`, marge, y + 5)
  doc.setFontSize(10)
  const formateur = `${promo.formateurPrenom || ''} ${promo.formateurNom || ''}`.trim()
  doc.text(`Animée par : ${formateur || '—'}`, marge, y + 12)
  const periode = promo.du
    ? `Du ${formatDateLongue(promo.du)}${promo.au ? ' / Au ' + formatDateLongue(promo.au) : ''}`
    : ''
  if (periode) doc.text(periode, marge, y + 18)
  y += 24

  doc.setFillColor(198, 239, 206)
  doc.rect(marge, y, 182, 8, 'F')
  doc.setFontSize(11)
  doc.setTextColor(0)
  doc.text(`${stagiaire.nom} ${stagiaire.prenom}`, marge + 3, y + 5.5)
  y += 14

  const xBarre = 112
  const largeurBarre = 66
  doc.setFontSize(9)
  for (const q of questions) {
    if (y > 260) { doc.addPage(); y = marge }
    const r = stagiaire.reponses.find((x) => x.codeQuest === q.code)
    const valeur = r ? r.valeur : 0
    const libelle = doc.splitTextToSize(q.libelle, 94)
    doc.setTextColor(0)
    doc.text(libelle, marge, y + 3)
    dessinerBarre(doc, xBarre, y, largeurBarre, 4, valeur / noteSur)
    doc.text(`${valeur} / ${noteSur}`, 196, y + 3, { align: 'right' })
    let hauteur = Math.max(libelle.length * 4, 7)
    if (r && r.remarque) {
      const remarque = doc.splitTextToSize(`« ${r.remarque} »`, 175)
      doc.setFontSize(8)
      doc.setTextColor(100)
      doc.text(remarque, marge, y + hauteur + 1)
      hauteur += remarque.length * 3.5 + 1
      doc.setFontSize(9)
    }
    y += hauteur + 3
  }

  const { moyenneGenerale, moyenneSansZero, moyenneSansEtoileSansZero } = calculerMoyennes(stagiaire.reponses, questions)
  y += 3
  doc.setDrawColor(150)
  doc.line(marge, y, 196, y)
  y += 6
  doc.setFontSize(9)
  doc.setTextColor(0)
  doc.setFont(undefined, 'bold')
  doc.text(`Moyenne générale : ${formatMoyenne(moyenneGenerale)}`, marge, y)
  doc.text(`Moyenne hors 0 : ${formatMoyenne(moyenneSansZero)}`, marge, y + 6)
  doc.text(`Moyenne hors ** et hors 0 : ${formatMoyenne(moyenneSansEtoileSansZero)}`, marge, y + 12)
  doc.setFont(undefined, 'normal')
}

/** type : 'anonyme' (une page, moyenne par question) ou 'detail' (une page par stagiaire). */
async function telechargerEvaluation(type) {
  if (!promoCible.value) return
  evaluationEnCours.value = type
  erreur.value = ''
  try {
    const donnees = await api(`/admin/promos/${promoCible.value.code}/evaluation`)
    if (!donnees.stagiaires.length) { erreur.value = "Aucune évaluation n'a encore été remplie pour cette promo."; return }

    let logoDataUrl = null
    try {
      const repLogo = await fetch(`${import.meta.env.BASE_URL}logo-full.png`)
      const brut = await blobVersDataUrl(await repLogo.blob())
      logoDataUrl = await redimensionnerEnJpeg(brut, 300)
    } catch { /* logo optionnel */ }

    const doc = new jsPDF()
    let nomBase
    if (type === 'anonyme') {
      dessinerPageRecapAnonyme(doc, donnees.promo, donnees.noteSur, donnees.questions, donnees.stagiaires, logoDataUrl)
      nomBase = `evaluation-recap_${donnees.promo.libelle}`
    } else {
      donnees.stagiaires.forEach((s, i) => {
        if (i > 0) doc.addPage()
        dessinerPageDetailEvaluation(doc, donnees.promo, donnees.noteSur, donnees.questions, s, logoDataUrl)
      })
      nomBase = `evaluation-detail_${donnees.promo.libelle}`
    }
    doc.save(`${nomBase.replace(/[\\/:*?"<>|]/g, '_')}.pdf`)
  } catch (e) {
    erreur.value = e.message
  } finally {
    evaluationEnCours.value = null
  }
}

// ─── COL 4 : aperçu en direct (comme RessourcesPage.vue, groupé par TD) + Visible/Fait ──────

const droitsPromo = ref([])
const chargementApercu = ref(false)
const suppressionEnCours = ref(null)
const enregistrementEnCours = ref(null)

async function chargerApercu() {
  if (!promoCible.value) { droitsPromo.value = []; return }
  chargementApercu.value = true
  try {
    droitsPromo.value = await api(`/admin/promos/${promoCible.value.code}/droits`)
  } catch (e) {
    erreur.value = e.message
  } finally {
    chargementApercu.value = false
  }
}

const groupesApercu = computed(() => {
  const parCle = new Map()
  for (const it of droitsPromo.value) {
    const groupe = it.td && it.td !== '-' ? it.td : null
    if (!parCle.has(groupe)) parCle.set(groupe, [])
    parCle.get(groupe).push(it)
  }
  return [...parCle.entries()].map(([libelle, fichiers]) => ({ libelle, fichiers }))
})

/** Bascule Visible ou Fait sur un droit directement depuis l'aperçu : envoie l'état complet du
 *  droit (inchangé sur les autres champs) avec le champ modifié, optimiste puis rollback si ça
 *  échoue côté serveur. "faitLe" est calculé côté serveur, jamais envoyé. */
async function basculerChamp(item, champ) {
  const valeurAvant = item[champ]
  item[champ] = !valeurAvant
  enregistrementEnCours.value = item.id
  erreur.value = ''
  try {
    await api(`/admin/droits/${item.id}`, {
      method: 'PUT',
      body: {
        groupe: item.groupe, td: item.td, visible: item.visible, ordre: item.ordre,
        commentaire: item.commentaire, debut: item.debut, fin: item.fin, fait: item.fait,
      },
    })
    await chargerApercu() // pour récupérer faitLe calculé côté serveur
  } catch (e) {
    item[champ] = valeurAvant
    erreur.value = e.message
  } finally {
    enregistrementEnCours.value = null
  }
}

async function retirerDroit(id) {
  if (!window.confirm('Retirer ce fichier de ce regroupement ? Le fichier reste sur le serveur.')) return
  suppressionEnCours.value = id
  erreur.value = ''
  try {
    await api(`/admin/droits/${id}`, { method: 'DELETE' })
    await chargerApercu()
  } catch (e) {
    erreur.value = e.message
  } finally {
    suppressionEnCours.value = null
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
    <RouterLink :to="{ name: 'formateur-accueil' }" class="text-sm text-alag-navy hover:underline">‹ Retour à l'accueil</RouterLink>

    <h1 class="text-xl font-semibold text-alag-navy">Gestion des fichiers</h1>
    <p v-if="erreur" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ erreur }}</p>

    <!-- Écran pleine largeur (demande de Jérôme) : AppLayout ne borne pas la largeur des routes
         marquées meta.large, la grille occupe donc tout l'espace à droite de la sidebar. -->
    <div>
      <div class="grid grid-cols-1 gap-4 xl:grid-cols-4">
        <!-- ────────────────── COL 1 : arborescence + recherche ────────────────── -->
        <section class="sc-card flex flex-col overflow-hidden xl:max-h-[80vh]">
          <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-alag-navy">
            Serveur — dossiers &amp; fichiers
            <span v-if="nbSelectionnes" class="ml-1 font-normal text-alag-navy">({{ nbSelectionnes }} sélectionné{{ nbSelectionnes > 1 ? 's' : '' }})</span>
          </h2>
          <div class="border-b border-slate-100 p-2">
            <input v-model="qRecherche" type="search" placeholder="Rechercher un fichier par son nom…"
                   class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
          </div>

          <template v-if="rechercheActive">
            <p v-if="rechercheEnCours" class="p-4 text-sm text-slate-500">Recherche…</p>
            <ul v-else class="flex-1 divide-y divide-slate-100 overflow-y-auto text-sm">
              <li v-for="f in resultatsRecherche" :key="f.numFic" class="flex items-center gap-3 px-3 py-2 hover:bg-paper/30">
                <input type="checkbox" class="h-4 w-4 shrink-0 rounded border-slate-300" :checked="estCoche(f)" @change="basculerSelection(f)">
                <Icone :nom="f.type === 'L' ? 'lien' : 'fichier'" class="inline h-4 w-4 text-slate-500" />
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-slate-900">{{ f.nom }}</span>
                  <span class="block truncate text-xs text-slate-400">{{ f.chem }}</span>
                </span>
                <button type="button" class="shrink-0 text-xs font-medium text-alag-navy hover:underline disabled:opacity-50"
                        :disabled="telechargementEnCours === f.numFic" @click="telechargerFichier(f)">
                  {{ telechargementEnCours === f.numFic ? '…' : (f.type === 'L' ? 'Ouvrir' : 'Télécharger') }}
                </button>
              </li>
              <li v-if="!resultatsRecherche.length" class="px-3 py-2 text-slate-500">Aucun fichier trouvé.</li>
            </ul>
          </template>
          <template v-else>
            <nav class="flex flex-wrap items-center gap-1 border-b border-slate-100 px-4 py-2 text-xs text-slate-500">
              <button type="button" class="hover:underline" @click="ouvrirDossier(null)">Racine</button>
              <template v-for="(d, i) in pileDossiers" :key="d.numFic">
                <span>/</span>
                <button type="button" class="hover:underline" @click="remonterA(i)">{{ d.nom }}</button>
              </template>
            </nav>
            <p v-if="chargementDossier" class="p-4 text-sm text-slate-500">Chargement…</p>
            <ul v-else class="flex-1 divide-y divide-slate-100 overflow-y-auto text-sm">
              <li v-for="f in contenuDossier" :key="f.numFic">
                <button v-if="f.type === 'D'" type="button" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-paper/50" @click="ouvrirDossier(f.numFic, f.nom)">
                  <Icone nom="dossier" class="inline h-4 w-4 text-alag-navy" />
                  <span class="flex-1 text-slate-900">{{ f.nom }}</span>
                </button>
                <div v-else class="flex items-center gap-3 px-3 py-2 hover:bg-paper/30">
                  <input type="checkbox" class="h-4 w-4 shrink-0 rounded border-slate-300" :checked="estCoche(f)" @change="basculerSelection(f)">
                  <Icone :nom="f.type === 'L' ? 'lien' : 'fichier'" class="inline h-4 w-4 text-slate-500" />
                  <span class="min-w-0 flex-1 truncate text-slate-900">{{ f.nom }}</span>
                  <span class="shrink-0 text-xs text-slate-500">{{ tailleLisible(f.tailleKo) }}</span>
                  <button type="button" class="shrink-0 text-xs font-medium text-alag-navy hover:underline disabled:opacity-50"
                          :disabled="telechargementEnCours === f.numFic" @click="telechargerFichier(f)">
                    {{ telechargementEnCours === f.numFic ? '…' : (f.type === 'L' ? 'Ouvrir' : 'Télécharger') }}
                  </button>
                </div>
              </li>
              <li v-if="!contenuDossier.length" class="px-3 py-2 text-slate-500">Dossier vide.</li>
            </ul>
          </template>

          <button v-if="nbSelectionnes" type="button" class="border-t border-slate-100 px-4 py-2 text-left text-xs font-medium text-red-700 hover:bg-red-50" @click="viderSelection">
            Vider la sélection
          </button>
        </section>

        <!-- ────────────────── COL 2 : bandeau + client + cours ────────────────── -->
        <section class="space-y-4 xl:max-h-[80vh] xl:overflow-y-auto">
          <div class="sc-card space-y-2 p-4">
            <h2 class="text-sm font-semibold text-alag-navy">{{ dossierCourant === null ? 'Ajouter à la racine' : 'Ajouter au dossier sélectionné à gauche' }}</h2>
            <div class="flex flex-wrap gap-2">
              <input v-model="nouveauDossierNom" type="text" placeholder="Nom du nouveau dossier"
                     class="min-w-0 flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm disabled:bg-slate-50"
                     @keyup.enter="creerDossier">
              <button type="button" class="rounded-lg border border-alag-navy px-3 py-1.5 text-xs font-medium text-alag-navy hover:bg-paper disabled:opacity-50"
                      :disabled="!nouveauDossierNom.trim() || creationDossierEnCours" @click="creerDossier">
                {{ creationDossierEnCours ? 'Création…' : 'Créer le dossier' }}
              </button>
            </div>
            <div>
              <input ref="inputFichier" type="file" :disabled="dossierCourant === null || uploadEnCours" class="text-sm" @change="deposerFichier">
              <span v-if="uploadEnCours" class="ml-2 text-xs text-slate-500">Envoi en cours…</span>
            </div>
            <p v-if="dossierCourant === null" class="text-xs text-amber-700">Dépôt de fichier : ouvrez d'abord un dossier à gauche (la création de dossier à la racine est possible).</p>
          </div>

          <div class="sc-card space-y-2 p-4">
            <h2 class="text-sm font-semibold text-alag-navy">Client</h2>
            <input v-model="qClient" type="search" placeholder="Rechercher un client…"
                   class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            <p v-if="rechercheClientEnCours" class="text-xs text-slate-500">Recherche…</p>
            <ul v-else-if="clients.length" class="max-h-40 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-100 text-sm">
              <li v-for="c in clients" :key="c.code">
                <button type="button" class="flex w-full items-center justify-between px-3 py-1.5 text-left hover:bg-paper/50"
                        :class="clientChoisi?.code === c.code ? 'bg-paper/60 font-medium' : ''"
                        @click="choisirClient(c)">
                  {{ c.nom }}
                </button>
              </li>
            </ul>
            <p v-else class="text-xs text-slate-500">Aucun client trouvé.</p>
          </div>

          <div v-if="clientChoisi" class="sc-card space-y-2 p-4">
            <h2 class="text-sm font-semibold text-alag-navy">Cours de « {{ clientChoisi.nom }} »</h2>
            <div class="flex flex-wrap gap-1 text-xs">
              <button type="button" class="rounded-full px-2.5 py-1" :class="periode === 'avenir' ? 'bg-alag-navy text-white' : 'border border-slate-300 text-slate-600'" @click="periode = 'avenir'">À venir</button>
              <button type="button" class="rounded-full px-2.5 py-1" :class="periode === 'passes' ? 'bg-alag-navy text-white' : 'border border-slate-300 text-slate-600'" @click="periode = 'passes'">Passés</button>
              <button type="button" class="rounded-full px-2.5 py-1" :class="periode === 'personnalise' ? 'bg-alag-navy text-white' : 'border border-slate-300 text-slate-600'" @click="periode = 'personnalise'">Dates personnalisées</button>
            </div>
            <div v-if="periode === 'personnalise'" class="flex flex-wrap gap-2">
              <input v-model="depuisPerso" type="date" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">
              <input v-model="jusquaPerso" type="date" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">
            </div>
            <p v-if="chargementCours" class="text-xs text-slate-500">Chargement…</p>
            <ul v-else-if="cours.length" class="max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-100 text-sm">
              <li v-for="c in cours" :key="c.numCours">
                <button type="button" class="flex w-full flex-col gap-0.5 px-3 py-2 text-left hover:bg-paper/50"
                        :class="coursChoisi?.numCours === c.numCours ? 'bg-paper/60' : ''"
                        @click="choisirCours(c)">
                  <span class="font-medium text-slate-900">{{ formatDate(c.date) }} · {{ c.heureDebut }}–{{ c.heureFin }}</span>
                  <span class="text-xs text-slate-500">{{ c.libellePromo }}<template v-if="c.sujet"> — {{ c.sujet }}</template></span>
                </button>
              </li>
            </ul>
            <p v-else class="text-xs text-slate-500">Aucun cours pour cette période.</p>
          </div>
        </section>

        <!-- ────────────────── COL 3 : promo ciblée + regroupement ────────────────── -->
        <section class="xl:max-h-[80vh] xl:overflow-y-auto">
          <div v-if="promoCible" class="sc-card space-y-3 border-2 border-alag-navy p-4">
            <p class="text-sm">
              Promo ciblée : <strong>{{ promoCible.libelle }}</strong>
            </p>
            <label class="block text-xs font-medium text-slate-600">
              Regroupement (TD)
              <input v-model="regroupementTD" type="text" placeholder="ex. 01, Doc…"
                     class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            </label>

            <details class="text-xs">
              <summary class="cursor-pointer text-alag-navy hover:underline">Options avancées (groupe, visibilité, période, commentaire)</summary>
              <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <label class="font-medium text-slate-600">
                  Groupe
                  <select v-model="optionsAvancees.groupe" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-xs">
                    <option value="-">Tous les groupes</option>
                    <option v-for="g in groupesDisponibles" :key="g" :value="g">{{ g }}</option>
                  </select>
                </label>
                <label class="flex items-center gap-2 pt-5 font-medium text-slate-600">
                  <input v-model="optionsAvancees.visible" type="checkbox" class="h-4 w-4 rounded border-slate-300">
                  Visible par les stagiaires
                </label>
                <label class="font-medium text-slate-600">
                  Ordre d'affichage
                  <input v-model="optionsAvancees.ordre" type="text" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-xs">
                </label>
                <label class="font-medium text-slate-600">
                  Commentaire
                  <input v-model="optionsAvancees.commentaire" type="text" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-xs">
                </label>
                <label class="font-medium text-slate-600">
                  Visible à partir du
                  <input v-model="optionsAvancees.debut" type="date" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-xs">
                </label>
                <label class="font-medium text-slate-600">
                  Jusqu'au
                  <input v-model="optionsAvancees.fin" type="date" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-xs">
                </label>
              </div>
            </details>

            <button type="button" class="w-full rounded-lg bg-alag-navy px-3 py-2 text-sm font-medium text-white hover:bg-alag-navy-dark disabled:opacity-50"
                    :disabled="!peutAjouter" @click="ajouterAuRegroupement">
              {{ ajoutEnCours ? 'Ajout…' : `Ajouter ${nbSelectionnes || ''} fichier${nbSelectionnes > 1 ? 's' : ''} au regroupement` }}
            </button>
            <p v-if="!nbSelectionnes" class="text-xs text-slate-500">Cochez au moins un fichier à gauche.</p>
            <p v-else-if="!regroupementTD.trim()" class="text-xs text-slate-500">Saisissez un regroupement (TD).</p>

            <div v-if="dernierResultat" class="rounded-lg bg-slate-50 p-2 text-xs">
              <p class="font-medium text-slate-700">{{ dernierResultat.nbAjoutes }} / {{ dernierResultat.nbTotal }} fichier(s) ajouté(s).</p>
              <p v-for="(r, i) in dernierResultat.resultats.filter(x => !x.ok)" :key="i" class="text-red-700">{{ r.nom || r.numFic }} : {{ r.erreur }}</p>
            </div>

            <div class="border-t border-slate-100 pt-3">
              <button type="button"
                      class="w-full rounded-lg border border-amber-500 px-3 py-2 text-sm font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-50"
                      :disabled="deblocageSignatureEnCours" @click="debloquerSignaturesPromo">
                {{ deblocageSignatureEnCours ? 'Autorisation…' : 'Autoriser signatures pour cette promo (30 min)' }}
              </button>
              <p v-if="deblocageSignatureExpiration" class="mt-1 text-xs text-amber-700">
                Signatures autorisées pour tous les stagiaires de cette promo, hors créneau, jusqu'à {{ heureMinute(deblocageSignatureExpiration) }}.
              </p>
              <p v-else class="mt-1 text-xs text-slate-500">
                Permet à tous les stagiaires de cette promo de signer une séance même hors de leur créneau, pendant 30 minutes.
              </p>
            </div>

            <div class="border-t border-slate-100 pt-3">
              <h3 class="mb-2 text-xs font-semibold text-slate-600">Feuille de présence (PDF)</h3>
              <div class="flex flex-wrap gap-2">
                <button type="button"
                        class="flex-1 rounded-lg border border-alag-navy px-3 py-2 text-xs font-medium text-alag-navy hover:bg-paper disabled:opacity-50"
                        :disabled="!coursChoisi || !!feuillePresenceEnCours" @click="telechargerFeuillePresence('seance')">
                  {{ feuillePresenceEnCours === 'seance' ? 'Génération…' : 'Cette séance' }}
                </button>
                <button type="button"
                        class="flex-1 rounded-lg border border-alag-navy px-3 py-2 text-xs font-medium text-alag-navy hover:bg-paper disabled:opacity-50"
                        :disabled="!!feuillePresenceEnCours" @click="telechargerFeuillePresence('promo')">
                  {{ feuillePresenceEnCours === 'promo' ? 'Génération…' : 'Toute la promo' }}
                </button>
              </div>
              <p class="mt-1 text-xs text-slate-500">Nom du stagiaire + sa signature (ou « Non signé »), une page par séance.</p>
            </div>

            <div class="border-t border-slate-100 pt-3">
              <h3 class="mb-2 text-xs font-semibold text-slate-600">Évaluation de la formation (PDF)</h3>
              <div class="flex flex-wrap gap-2">
                <button type="button"
                        class="flex-1 rounded-lg border border-alag-navy px-3 py-2 text-xs font-medium text-alag-navy hover:bg-paper disabled:opacity-50"
                        :disabled="!!evaluationEnCours" @click="telechargerEvaluation('anonyme')">
                  {{ evaluationEnCours === 'anonyme' ? 'Génération…' : 'Récap anonyme' }}
                </button>
                <button type="button"
                        class="flex-1 rounded-lg border border-alag-navy px-3 py-2 text-xs font-medium text-alag-navy hover:bg-paper disabled:opacity-50"
                        :disabled="!!evaluationEnCours" @click="telechargerEvaluation('detail')">
                  {{ evaluationEnCours === 'detail' ? 'Génération…' : 'Détail par stagiaire' }}
                </button>
              </div>
              <p class="mt-1 text-xs text-slate-500">Anonyme : moyenne par question. Détail : une page par stagiaire, avec 3 moyennes (générale, hors 0, hors questions ** et hors 0).</p>
            </div>
          </div>
          <div v-else class="sc-card p-4 text-sm text-slate-500">
            Choisissez un client puis un cours (colonne précédente) pour cibler une promo.
          </div>
        </section>

        <!-- ────────────────── COL 4 : aperçu stagiaire en direct ────────────────── -->
        <section class="sc-card flex flex-col overflow-hidden xl:max-h-[80vh]">
          <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-alag-navy">
            Aperçu stagiaire<template v-if="promoCible"> — {{ promoCible.libelle }}</template>
          </h2>
          <div class="flex-1 overflow-y-auto p-3">
            <p v-if="!promoCible" class="text-sm text-slate-500">Choisissez un client puis un cours pour voir la promo ciblée.</p>
            <p v-else-if="chargementApercu" class="text-sm text-slate-500">Chargement…</p>
            <p v-else-if="!groupesApercu.length" class="text-sm text-slate-500">Aucun fichier rattaché à cette promo pour le moment.</p>
            <template v-else>
              <template v-for="groupe in groupesApercu" :key="groupe.libelle ?? '__non_groupe__'">
                <section v-if="!groupe.libelle" class="mb-3 rounded-lg border border-slate-100">
                  <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="it in groupe.fichiers" :key="it.id" class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2">
                      <Icone :nom="it.type === 'L' ? 'lien' : 'fichier'" class="inline h-4 w-4 text-slate-500" />
                      <span class="min-w-0 flex-1 truncate text-slate-900">{{ it.nom }}</span>
                      <label class="flex shrink-0 items-center gap-1 text-xs text-slate-600" title="Visible par les stagiaires">
                        <input type="checkbox" class="h-3.5 w-3.5 rounded border-slate-300" :checked="it.visible" :disabled="enregistrementEnCours === it.id" @change="basculerChamp(it, 'visible')">
                        Visible
                      </label>
                      <label class="flex shrink-0 items-center gap-1 text-xs text-slate-600" :title="it.faitLe ? `Fait le ${formatDate(it.faitLe)}` : ''">
                        <input type="checkbox" class="h-3.5 w-3.5 rounded border-slate-300" :checked="it.fait" :disabled="enregistrementEnCours === it.id" @change="basculerChamp(it, 'fait')">
                        Fait
                      </label>
                      <button type="button" class="shrink-0 text-xs text-red-700 hover:underline disabled:opacity-50" :disabled="suppressionEnCours === it.id" @click="retirerDroit(it.id)">✕</button>
                    </li>
                  </ul>
                </section>
                <details v-else class="mb-3 rounded-lg border border-slate-100" open>
                  <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2 hover:bg-paper/50">
                    <Icone nom="dossier" class="inline h-4 w-4 text-alag-navy" />
                    <span class="flex-1 font-medium text-slate-900">{{ groupe.libelle }}</span>
                    <span class="text-xs text-slate-500">{{ groupe.fichiers.length }}</span>
                  </summary>
                  <ul class="divide-y divide-slate-100 border-t border-slate-100 text-sm">
                    <li v-for="it in groupe.fichiers" :key="it.id" class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2 pl-7">
                      <Icone :nom="it.type === 'L' ? 'lien' : 'fichier'" class="inline h-4 w-4 text-slate-500" />
                      <span class="min-w-0 flex-1 truncate text-slate-900">{{ it.nom }}</span>
                      <label class="flex shrink-0 items-center gap-1 text-xs text-slate-600" title="Visible par les stagiaires">
                        <input type="checkbox" class="h-3.5 w-3.5 rounded border-slate-300" :checked="it.visible" :disabled="enregistrementEnCours === it.id" @change="basculerChamp(it, 'visible')">
                        Visible
                      </label>
                      <label class="flex shrink-0 items-center gap-1 text-xs text-slate-600" :title="it.faitLe ? `Fait le ${formatDate(it.faitLe)}` : ''">
                        <input type="checkbox" class="h-3.5 w-3.5 rounded border-slate-300" :checked="it.fait" :disabled="enregistrementEnCours === it.id" @change="basculerChamp(it, 'fait')">
                        Fait
                      </label>
                      <button type="button" class="shrink-0 text-xs text-red-700 hover:underline disabled:opacity-50" :disabled="suppressionEnCours === it.id" @click="retirerDroit(it.id)">✕</button>
                    </li>
                  </ul>
                </details>
              </template>
            </template>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>
