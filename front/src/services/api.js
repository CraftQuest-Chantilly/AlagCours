// Appels à l'API PHP du portail (/alcours/api/index.php?route=…). Le jeton de session est
// envoyé en en-tête. Toute réponse 401 (session absente/expirée) renvoie à l'écran de connexion.
// Routage par paramètre ?route= et non par chemin façon /api/formations : le serveur OVH n'a
// pas le module IIS URL Rewrite, index.php doit rester une ressource physique adressable.
import { useAuthStore } from '@/stores/auth'
import router from '@/router'

const BASE = `${import.meta.env.BASE_URL}api/index.php`

export async function api(chemin, { method = 'GET', body, params } = {}) {
  const auth = useAuthStore()
  const headers = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (auth.jeton) headers.Authorization = `Bearer ${auth.jeton}`

  // `params` : query string additionnelle EN DEHORS de route= (ex. /atelier/schema?base=ecole) —
  // nécessaire pour l'atelier SQL, dont le code de base n'est pas un identifiant numérique comme
  // les autres routes et ne peut donc pas être un segment de chemin (cf. Http::id() côté API,
  // appliqué à tout segment capturé). Concaténer "base" dans `chemin` lui-même ne marcherait pas :
  // encodeURIComponent(chemin) encoderait le "?" et le "=" dans la valeur de route=, si bien que
  // PHP recevrait "/atelier/schema?base=ecole" comme UNE SEULE valeur de route, pas deux paramètres.
  let url = `${BASE}?route=${encodeURIComponent(chemin)}`
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null) url += `&${encodeURIComponent(k)}=${encodeURIComponent(v)}`
    }
  }

  let rep
  try {
    rep = await fetch(url, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined })
  } catch {
    throw new Error('Serveur injoignable. Vérifiez votre connexion internet.')
  }
  const data = await rep.json().catch(() => null)
  if (rep.status === 401 && chemin !== '/login') {
    auth.effacer()
    router.push({ name: 'connexion', query: { expire: '1' } })
  }
  if (!rep.ok) throw new Error(data?.erreur || `Erreur ${rep.status}`)
  return data
}

// Récupère un fichier binaire (ex. /formations/:code/ressources/:id/telecharger) en blob, SANS
// déclencher l'enregistrement — brique commune à apiTelecharger() (un seul fichier, sauvegardé
// tout de suite) et au zip groupé de RessourcesPage.vue (plusieurs fichiers assemblés avant
// sauvegarde). Un simple lien <a href> ne peut pas porter l'en-tête Authorization, d'où le fetch.
export async function apiTelechargerBrut(chemin, nomParDefaut = 'fichier') {
  const auth = useAuthStore()
  const headers = {}
  if (auth.jeton) headers.Authorization = `Bearer ${auth.jeton}`

  let rep
  try {
    rep = await fetch(`${BASE}?route=${encodeURIComponent(chemin)}`, { headers })
  } catch {
    throw new Error('Serveur injoignable. Vérifiez votre connexion internet.')
  }
  if (rep.status === 401) {
    auth.effacer()
    router.push({ name: 'connexion', query: { expire: '1' } })
    throw new Error('Session expirée.')
  }
  if (!rep.ok) {
    const data = await rep.json().catch(() => null)
    throw new Error(data?.erreur || `Erreur ${rep.status}`)
  }

  const dispo = rep.headers.get('Content-Disposition') || ''
  const m = /filename="?([^"]+)"?/.exec(dispo)
  const nom = m ? decodeURIComponent(m[1]) : nomParDefaut
  const blob = await rep.blob()
  return { blob, nom }
}

// Déclenche l'enregistrement d'un blob déjà récupéré (ou le fait récupérer puis l'enregistre),
// via un <a download> éphémère — seul moyen de nommer le fichier correctement côté navigateur.
function sauvegarderBlob(blob, nom) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = nom
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10000)
}

export async function apiTelecharger(chemin, nomParDefaut = 'fichier') {
  const { blob, nom } = await apiTelechargerBrut(chemin, nomParDefaut)
  sauvegarderBlob(blob, nom)
}

export { sauvegarderBlob }
