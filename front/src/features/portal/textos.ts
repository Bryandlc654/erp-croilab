/* Textos fijos del portal (los del ERP antiguo, index.php). */

export type InfoServicio = { corto: string; intro: string; pasos: string[]; faqs: { q: string; a: string }[] }

/* Los seis servicios que el portal explica. Un servicio nuevo del catálogo
   sale con su descripción y sin pasos ni preguntas. */
export const SERVICIOS: Record<string, InfoServicio> = {
  'Diseño web': {
    corto: 'Tu web, rápida y que convierte.',
    intro: 'Creamos tu web desde cero o la renovamos: rápida, clara y pensada para que quien entre acabe contactando.',
    pasos: [
      'Descubrimiento: entendemos tu negocio y tus objetivos',
      'Diseño en Figma: ves la web antes de programar nada',
      'Desarrollo a medida y optimización de velocidad',
      'Revisión contigo y puesta en marcha',
      'Mejoras continuas según cómo se comporta la gente',
    ],
    faqs: [
      { q: '¿Cuánto tarda una web?', a: 'Normalmente entre 3 y 6 semanas según el tamaño. Te damos fechas desde el principio.' },
      { q: '¿Podré editarla yo?', a: 'Sí. Te la dejamos fácil de gestionar y te enseñamos a hacer cambios básicos.' },
      { q: '¿Está preparada para Google?', a: 'Sí, la construimos con buenas bases de SEO y velocidad desde el primer día.' },
    ],
  },
  SEO: {
    corto: 'Aparecer en Google sin pagar por clic.',
    intro: 'Trabajamos para que tu negocio salga en Google cuando alguien busca lo que ofreces, sin pagar por cada clic.',
    pasos: [
      'Auditoría de tu web y de tu competencia',
      'Estudio de palabras clave: qué busca tu cliente',
      'Arquitectura de contenidos y páginas de servicio',
      'Optimización técnica para que Google te entienda',
      'Seguimiento mensual y ajustes',
    ],
    faqs: [
      { q: '¿Cuándo se ven resultados?', a: 'El SEO es a medio plazo: los primeros avances suelen notarse a partir de los 3-4 meses.' },
      { q: '¿Garantizáis salir el primero?', a: 'Nadie puede garantizar el puesto 1, pero sí trabajar con método para subir de forma sostenida.' },
      { q: '¿Qué hacéis cada mes?', a: 'Contenidos, mejoras técnicas y optimización; lo verás todo en tu informe mensual.' },
    ],
  },
  SEM: {
    corto: 'Anuncios en Google desde el primer día.',
    intro: 'Ponemos anuncios en Google para que aparezcas arriba justo cuando te buscan, desde el primer día.',
    pasos: ['Estudio de palabras y competencia', 'Estructura de campañas y grupos de anuncios', 'Redacción de anuncios y extensiones', 'Optimización de pujas y presupuesto', 'Informes y mejora continua'],
    faqs: [
      { q: '¿Cuánto hay que invertir?', a: 'Lo decidimos contigo según tu objetivo; se puede empezar con poco e ir ajustando.' },
      { q: '¿Desde cuándo llegan clientes?', a: 'Al ser anuncios, puedes empezar a recibir contactos casi de inmediato.' },
      { q: '¿Controláis el gasto?', a: 'Sí, gestionamos el presupuesto a diario para sacarle el máximo a cada euro.' },
    ],
  },
  CRO: {
    corto: 'Más clientes con las mismas visitas.',
    intro: 'Mejoramos tu web para que más visitas acaben contactando o comprando, sin necesidad de traer más tráfico.',
    pasos: ['Analizamos cómo se comporta la gente en tu web', 'Detectamos dónde se pierden los clientes', 'Planteamos hipótesis de mejora', 'Tests A/B para validar con datos', 'Implementamos lo que funciona'],
    faqs: [
      { q: '¿Qué es CRO en simple?', a: 'Optimizar para convertir: pequeños cambios que hacen que más gente dé el paso.' },
      { q: '¿Cómo sabéis qué cambiar?', a: 'Miramos datos y hacemos pruebas para decidir con hechos, no a ojo.' },
      { q: '¿Sirve para mi negocio?', a: 'Si recibes visitas pero pocos contactos o ventas, es justo lo que necesitas.' },
    ],
  },
  'Tiendas online': {
    corto: 'Vende más con tu ecommerce.',
    intro: 'Montamos y hacemos crecer tu tienda online para que vendas más y mejor.',
    pasos: [
      'Configuración de la tienda y el catálogo',
      'Fichas de producto optimizadas para vender y posicionar',
      'Pasarela de pago y métodos de envío',
      'SEO de productos y categorías',
      'Campañas para atraer y recuperar carritos',
    ],
    faqs: [
      { q: '¿Con qué plataforma trabajáis?', a: 'La que mejor encaje contigo (WooCommerce, Shopify…); te asesoramos.' },
      { q: '¿Incluye las fichas de producto?', a: 'Sí, las preparamos para vender y para posicionar en Google.' },
      { q: '¿Y los pagos y envíos?', a: 'Lo dejamos todo configurado: pasarelas de pago y métodos de envío.' },
    ],
  },
  Meta: {
    corto: 'Clientes nuevos en Instagram y Facebook.',
    intro: 'Publicidad en Instagram y Facebook para que te conozca gente nueva que encaja con tu cliente ideal.',
    pasos: ['Definición del público objetivo', 'Creatividades que paran el scroll', 'Campañas de captación y retargeting', 'Optimización diaria de resultados', 'Escalado de lo que funciona'],
    faqs: [
      { q: '¿Para qué sirve?', a: 'Para darte a conocer y captar clientes nuevos, y recordar a quien ya te visitó.' },
      { q: '¿Necesito hacer vídeos?', a: 'Ayuda, pero podemos trabajar con lo que tengas; también te guiamos para crearlos.' },
      { q: '¿Cuándo se ven resultados?', a: 'Los primeros datos llegan rápido; afinamos las campañas en las primeras semanas.' },
    ],
  },
}
export const ORDEN_SERVICIOS = ['Diseño web', 'SEO', 'SEM', 'CRO', 'Tiendas online', 'Meta']

/* Ficha de un servicio: la fija si es de los seis (también «Meta Ads» o «Tienda online»). */
export function infoServicio(nombre: string, desc = ''): InfoServicio {
  const n = nombre.trim().toLowerCase()
  const clave = n === 'meta ads' ? 'Meta' : n === 'tienda online' ? 'Tiendas online' : ORDEN_SERVICIOS.find((k) => k.toLowerCase() === n)
  if (clave) return SERVICIOS[clave]
  return { corto: desc || 'Servicio de tu agencia.', intro: desc || `Te contamos cómo trabajamos ${nombre}.`, pasos: [], faqs: [] }
}

/* La lista de «Nuestros servicios»: los seis de siempre y lo que el catálogo añada. */
export function serviciosPortal(catalogo: { nombre: string; desc: string }[]): { nombre: string; desc: string }[] {
  const out = ORDEN_SERVICIOS.map((n) => ({ nombre: n, desc: SERVICIOS[n].corto }))
  const ya = new Set(['diseño web', 'seo', 'sem', 'cro', 'tiendas online', 'tienda online', 'meta', 'meta ads'])
  for (const s of catalogo) if (!ya.has(s.nombre.trim().toLowerCase())) out.push({ nombre: s.nombre, desc: s.desc || 'Servicio de tu agencia.' })
  return out
}

/* Canales de GA4 en cristiano. */
export const CANALES: Record<string, string> = {
  'Organic Search': 'Búsqueda en Google',
  Direct: 'Directo',
  'Paid Search': 'Anuncios (Google Ads)',
  'Organic Social': 'Redes sociales',
  'Paid Social': 'Anuncios en redes',
  Referral: 'Otras webs',
  Email: 'Email',
  Display: 'Display',
  'Organic Video': 'Vídeo',
  'Paid Video': 'Anuncios de vídeo',
  Unassigned: 'Sin clasificar',
  'Organic Shopping': 'Shopping',
  'Paid Shopping': 'Shopping de pago',
  'Cross-network': 'Varias redes',
}

let nombresPais: Intl.DisplayNames | null = null
export function pais(iso: string) {
  try {
    nombresPais ??= new Intl.DisplayNames(['es'], { type: 'region' })
    return nombresPais.of(iso.toUpperCase()) ?? iso
  } catch {
    return iso
  }
}
export const bandera = (iso: string) =>
  /^[A-Za-z]{2}$/.test(iso)
    ? String.fromCodePoint(...iso.toUpperCase().split('').map((c) => 0x1f1e6 + c.charCodeAt(0) - 65))
    : '🏳️'

export const ESTADO_TAREA: Record<string, { label: string; color: string }> = {
  pendiente: { label: 'En espera', color: '#b0b4bb' },
  'en proceso': { label: 'En proceso', color: '#3b82f6' },
  atemporal: { label: 'Atemporal', color: '#e0a000' },
  completada: { label: 'Completada', color: '#12a150' },
}
export const PRIORIDAD: Record<number, { label: string; color: string }> = {
  1: { label: 'Baja', color: '#94a3b8' },
  2: { label: 'Normal', color: '#3b82f6' },
  3: { label: 'Alta', color: '#f59e0b' },
  4: { label: 'Urgente', color: '#ef4444' },
}
export const ESTADO_FACTURA: Record<string, { label: string; color: string }> = {
  enviada: { label: 'Enviada', color: '#3b82f6' },
  pagada: { label: 'Pagada', color: '#12a150' },
  vencida: { label: 'Vencida', color: '#ef4444' },
  anulada: { label: 'Anulada', color: '#9aa0a8' },
}
export const ESTADO_TICKET: Record<string, { label: string; color: string }> = {
  abierto: { label: 'Abierto', color: '#3b82f6' },
  en_curso: { label: 'En curso', color: '#7b68ee' },
  esperando: { label: 'Esperando', color: '#e0a000' },
  resuelto: { label: 'Resuelto', color: '#12a150' },
  cerrado: { label: 'Cerrado', color: '#9aa0a8' },
}
export const ESTADO_REUNION: Record<string, { label: string; color: string }> = {
  agendada: { label: 'Agendada', color: '#3b82f6' },
  realizada: { label: 'Realizada', color: '#12a150' },
  no_show: { label: 'No realizada', color: '#e0a000' },
  cancelada: { label: 'Cancelada', color: '#9aa0a8' },
}
export const ESTADO_SOLICITUD: Record<string, { label: string; color: string }> = {
  pendiente: { label: 'Pendiente de confirmar', color: '#e0a000' },
  aprobada: { label: 'Confirmada', color: '#12a150' },
  rechazada: { label: 'No disponible', color: '#9aa0a8' },
}
export const CATEGORIA_CRED: Record<string, string> = {
  web: 'Web',
  correo: 'Correo',
  hosting: 'Hosting',
  database: 'Base de datos',
  api: 'API / Token',
  cms: 'CMS',
  domain: 'Dominio',
  social: 'Redes',
  other: 'Acceso',
}
export const FRANJAS = ['Sin preferencia', 'Por la mañana', 'Al mediodía', 'Por la tarde']
