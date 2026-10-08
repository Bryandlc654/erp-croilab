/* Traduce las URL guardadas por el ERP PHP (notifications.url, enlaces en
   comentarios…) a las rutas del front (docs/migracion/RUTAS.md). Lo que ya es
   una ruta del front ('/…') se deja tal cual; lo desconocido va a /inicio. */

type Traductor = (q: URLSearchParams) => string | null

const num = (v: string | null) => (v && /^\d+$/.test(v) ? v : null)

const LEGADO: Record<string, Traductor> = {
  'task.php': (q) => (num(q.get('id')) ? `/tareas/${q.get('id')}` : null),
  // ?v= la hoja, ?edit= el editor (la papelera guardaba esta): las dos llevan a la factura.
  'facturas.php': (q) => {
    const id = num(q.get('v')) ? q.get('v') : num(q.get('edit')) ? q.get('edit') : null
    return id ? `/finanzas/facturas/${id}` : '/finanzas/facturas'
  },
  'client.php': (q) => (num(q.get('id')) ? `/clientes/${q.get('id')}` : '/clientes'),
  'support.php': (q) => (num(q.get('t')) ? `/soporte/${q.get('t')}` : '/soporte'),
  'crm.php': (q) => (num(q.get('open')) ? `/crm/contactos/${q.get('open')}` : '/crm'),
  'perfil.php': (q) => (num(q.get('id')) ? `/perfil/${q.get('id')}` : '/perfil'),
  'reuniones.php': () => '/reuniones',
  'chat.php': (q) => (num(q.get('room')) ? `/chat/${q.get('room')}` : num(q.get('dm')) ? `/chat?dm=${q.get('dm')}` : '/chat'),
  'calendar.php': () => '/calendario',
  'ia.php': () => '/asistente',
  'notifications.php': () => '/notificaciones',
  'dashboard.php': () => '/inicio',
  'negocio.php': () => '/crm/negocio',
  'actas.php': (q) => (num(q.get('id')) ? `/actas/${q.get('id')}` : '/actas'),
}

export function rutaDesdeLegado(url: string): string {
  const u = (url ?? '').trim()
  if (u.startsWith('/') && !u.startsWith('//')) {
    // '/admin/task.php?id=3' sigue siendo del ERP antiguo, no del front.
    if (!/\.php(\?|#|$)/i.test(u)) return u
  }
  // Se quita el origen y cualquier carpeta (admin/, ../) y se separa el ancla.
  const sinOrigen = u.replace(/^[a-z]+:\/\/[^/]+/i, '')
  const hash = sinOrigen.indexOf('#')
  const ancla = hash >= 0 ? sinOrigen.slice(hash) : ''
  const resto = hash >= 0 ? sinOrigen.slice(0, hash) : sinOrigen
  const [ruta, query = ''] = resto.split('?')
  const fichero = (ruta.split('/').pop() ?? '').toLowerCase()
  const traducir = LEGADO[fichero]
  const destino = traducir ? traducir(new URLSearchParams(query)) : null
  return destino ? destino + ancla : '/inicio'
}
