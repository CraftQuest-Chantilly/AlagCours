// Dates ISO (AAAA-MM-JJ) renvoyées par l'API -> affichage français, sans décalage de fuseau.
export function dateFr(iso, options = { day: '2-digit', month: '2-digit', year: 'numeric' }) {
  if (!iso) return '—'
  const [a, m, j] = iso.split('-').map(Number)
  return new Date(a, m - 1, j).toLocaleDateString('fr-FR', options)
}
export function isoLocal(d) {
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}
