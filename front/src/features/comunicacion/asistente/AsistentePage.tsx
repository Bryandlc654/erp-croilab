import { useEffect, useRef, useState } from 'react'
import { Check, Inbox, SendHorizontal, Sparkles, TrendingUp } from 'lucide-react'
import { useAuth } from '../../auth/useAuth'
import { useNav } from '../../nav/api'

type Burbuja = { id: number; de: 'yo' | 'ia'; texto: string }

const SUGERENCIAS = [
  { icono: Inbox, titulo: 'Redactar informes', texto: 'Genera el correo de seguimiento mensual a partir de los datos.', prompt: 'Redáctame el informe mensual de SEO para un cliente' },
  { icono: Check, titulo: 'Resumir tareas', texto: 'Un resumen claro de lo pendiente, en curso y completado.', prompt: 'Resume el estado de todas mis tareas de esta semana' },
  { icono: TrendingUp, titulo: 'Ideas y estrategia', texto: 'Propuestas de contenido, keywords y acciones SEO.', prompt: 'Sugiere una estrategia de contenidos para un cliente de dentistas' },
]

const RESPUESTA = 'Buena pregunta. Cuando la IA esté conectada te respondería esto usando tus datos reales: clientes, tareas e informes. De momento es solo una vista previa.'

/* Asistente IA (ia.php): la maqueta del antiguo. No llama a ningún proveedor
   ni manda datos a ningún sitio; conectarlo de verdad es una decisión de
   producto (proveedor, coste, qué datos puede leer). */
export default function AsistentePage() {
  const { me } = useAuth()
  const { data: nav } = useNav()
  const marca = nav?.marca ?? 'Croilab'
  const [texto, setTexto] = useState('')
  const [escribiendo, setEscribiendo] = useState(false)
  const [chat, setChat] = useState<Burbuja[]>([])
  const feed = useRef<HTMLDivElement>(null)
  const temporizador = useRef<number | undefined>(undefined)

  useEffect(() => () => window.clearTimeout(temporizador.current), [])
  useEffect(() => {
    feed.current?.scrollTo({ top: feed.current.scrollHeight, behavior: 'smooth' })
  }, [chat, escribiendo])

  function enviar(t: string) {
    const v = t.trim()
    if (!v || escribiendo) return
    setChat((c) => [...c, { id: Date.now(), de: 'yo', texto: v }])
    setTexto('')
    setEscribiendo(true)
    temporizador.current = window.setTimeout(() => {
      setEscribiendo(false)
      setChat((c) => [...c, { id: Date.now() + 1, de: 'ia', texto: RESPUESTA }])
    }, 900)
  }

  return (
    <div className="mx-auto max-w-[880px]">
      <header className="mb-6 flex items-center gap-4">
        <span className="flex size-[52px] shrink-0 items-center justify-center rounded-[15px] bg-accent text-white shadow-[0_10px_24px_-8px_rgba(16,19,24,.45)] dark:text-accent-fg">
          <Sparkles className="size-6" />
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2.5">
            <h1 className="text-[26px] leading-[1.2] font-semibold tracking-[-.5px] text-ink-strong max-sm:text-[23px]">Asistente IA</h1>
            <span className="rounded-full bg-soft px-2.5 py-[3px] text-[10.5px] font-bold tracking-[.6px] text-muted uppercase">Próximamente</span>
          </div>
          <p className="mt-1 text-[14.5px] text-muted">Tu copiloto para redactar informes, resumir clientes y crear tareas — así se verá.</p>
        </div>
      </header>

      <div className="mb-6 grid grid-cols-3 gap-4 max-md:grid-cols-1">
        {SUGERENCIAS.map((s) => (
          <button
            key={s.titulo}
            type="button"
            onClick={() => enviar(s.prompt)}
            className="rounded-2xl border border-line bg-card px-5 py-[18px] text-left transition-[transform,box-shadow,border-color] duration-150 hover:-translate-y-0.5 hover:border-line-strong hover:shadow-card-hover"
          >
            <span className="mb-3 flex size-[34px] items-center justify-center rounded-[10px] border border-line bg-soft text-ink">
              <s.icono className="size-[17px]" />
            </span>
            <span className="block text-[14.5px] font-semibold text-ink-strong">{s.titulo}</span>
            <span className="mt-1.5 block text-[13px] leading-[1.55] text-muted">{s.texto}</span>
          </button>
        ))}
      </div>

      <section className="overflow-hidden rounded-2xl border border-line bg-card">
        <div ref={feed} className="h-[300px] space-y-3 overflow-y-auto px-5 py-5 max-sm:h-[340px]" aria-live="polite">
          <BurbujaIa texto={`¡Hola ${me?.username ?? ''}! Soy el asistente de ${marca}. Cuando esté activo podré redactar informes, resumir clientes, crear tareas y responder sobre tus datos. Prueba una sugerencia de las de arriba.`} />
          {chat.map((b) =>
            b.de === 'ia' ? (
              <BurbujaIa key={b.id} texto={b.texto} />
            ) : (
              <div key={b.id} className="flex justify-end">
                <p className="max-w-[75%] rounded-[14px] rounded-tr-[4px] bg-accent px-4 py-2.5 text-[13.5px] leading-[1.55] text-white dark:text-accent-fg">{b.texto}</p>
              </div>
            ),
          )}
          {escribiendo && (
            <div className="flex items-center gap-3">
              <IconoIa />
              <span className="flex gap-1 rounded-[14px] bg-soft px-4 py-3" aria-label="Escribiendo">
                {[0, 1, 2].map((i) => (
                  <span key={i} className="size-1.5 animate-bounce rounded-full bg-label" style={{ animationDelay: `${i * 120}ms` }} />
                ))}
              </span>
            </div>
          )}
        </div>
        <form
          className="flex items-center gap-2.5 border-t border-line px-4 py-3.5"
          onSubmit={(e) => {
            e.preventDefault()
            enviar(texto)
          }}
        >
          <input
            value={texto}
            onChange={(e) => setTexto(e.target.value)}
            placeholder="Escribe algo para ver la demo…"
            aria-label="Mensaje para el asistente"
            className="min-w-0 flex-1 rounded-xl border border-line bg-field px-4 py-[11px] text-[13.5px] text-ink placeholder:text-label focus:border-accent focus:shadow-[0_0_0_3px_var(--c-accent-soft)] focus:outline-none max-sm:text-[16px]"
          />
          <button type="submit" aria-label="Enviar" disabled={!texto.trim() || escribiendo} className="flex size-[44px] shrink-0 items-center justify-center rounded-xl bg-accent text-white transition-[transform,opacity] hover:-translate-y-px disabled:opacity-50 dark:text-accent-fg">
            <SendHorizontal className="size-[18px]" />
          </button>
        </form>
      </section>
      <p className="mt-3.5 text-center text-[12px] text-muted">Esta es una vista previa. La IA todavía no está conectada — pronto podrás usarla de verdad.</p>
    </div>
  )
}

function IconoIa() {
  return (
    <span className="flex size-[30px] shrink-0 items-center justify-center rounded-[9px] bg-accent text-white dark:text-accent-fg">
      <Sparkles className="size-3.5" />
    </span>
  )
}

function BurbujaIa({ texto }: { texto: string }) {
  return (
    <div className="flex items-start gap-3">
      <IconoIa />
      <p className="max-w-[75%] rounded-[14px] rounded-tl-[4px] bg-soft px-4 py-2.5 text-[13.5px] leading-[1.55] text-ink">{texto}</p>
    </div>
  )
}
