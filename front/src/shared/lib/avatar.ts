/* Color e iniciales como en el ERP antiguo (avatar_color / ini2 de erp_nav.php):
   el mismo nombre sale con el mismo color en el panel viejo y en este. */
const PALETA = ['#4f46e5', '#0369a1', '#0f766e', '#047857', '#b45309', '#c2410c', '#dc2626', '#be185d', '#6d28d9', '#1d4ed8']
const utf8 = new TextEncoder()

/* Mismo hash que PHP: recorre los bytes UTF-8 (no los caracteres) para que
   «Clínica» dé el mismo color en los dos paneles. */
export function colorDe(nombre: string) {
  let h = 0
  for (const b of utf8.encode(nombre)) h = ((h << 5) - h + b) & 0x7fffffff
  return PALETA[h % PALETA.length]
}

/* Las dos primeras letras, en mayúsculas (o las iniciales guardadas del cliente). */
export function iniciales(nombre: string, guardadas?: string | null) {
  const g = (guardadas ?? '').trim()
  return (g || Array.from(nombre.trim()).slice(0, 2).join('') || '?').toUpperCase()
}
