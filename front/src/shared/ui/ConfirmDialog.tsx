import { useCallback, useId, useMemo, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useCapa, useTrampaFoco } from './capas'
import { ConfirmContext, type ConfirmCtx, type OpcionesAlert, type OpcionesConfirm, type OpcionesPrompt } from './useConfirm'

type Peticion = { id: number } & (
  | { tipo: 'confirm'; o: OpcionesConfirm; resolver: (v: boolean) => void }
  | { tipo: 'prompt'; o: OpcionesPrompt; resolver: (v: string | null) => void }
  | { tipo: 'alert'; o: OpcionesAlert; resolver: () => void }
)
type SinId<T> = T extends unknown ? Omit<T, 'id'> : never

/* Proveedor de confirm/prompt/alert. Si se pide otro mientras hay uno abierto
   espera su turno (nunca se pisan). */
export function ConfirmProvider({ children }: { children: ReactNode }) {
  const [actual, setActual] = useState<Peticion | null>(null)
  const cola = useRef<Peticion[]>([])
  const abierta = useRef<Peticion | null>(null)
  const n = useRef(0)

  const pedir = useCallback((sin: SinId<Peticion>) => {
    const p = { ...sin, id: ++n.current } as Peticion
    if (abierta.current) {
      cola.current.push(p)
      return
    }
    abierta.current = p
    setActual(p)
  }, [])

  const terminar = useCallback(() => {
    const sig = cola.current.shift() ?? null
    abierta.current = sig
    setActual(sig)
  }, [])

  const valor = useMemo<ConfirmCtx>(
    () => ({
      confirm: (o) => new Promise<boolean>((resolver) => pedir({ tipo: 'confirm', o: typeof o === 'string' ? { message: o } : o, resolver })),
      prompt: (o) => new Promise<string | null>((resolver) => pedir({ tipo: 'prompt', o, resolver })),
      alert: (o) => new Promise<void>((resolver) => pedir({ tipo: 'alert', o: typeof o === 'string' ? { message: o } : o, resolver })),
    }),
    [pedir],
  )

  return (
    <ConfirmContext.Provider value={valor}>
      {children}
      {/* La key remonta el diálogo para cada petición (foco y texto nuevos). */}
      {actual && <Dialogo key={actual.id} p={actual} onFin={terminar} />}
    </ConfirmContext.Provider>
  )
}

function Dialogo({ p, onFin }: { p: Peticion; onFin: () => void }) {
  const caja = useRef<HTMLDivElement>(null)
  const ok = useRef<HTMLButtonElement>(null)
  const cancelar = useRef<HTMLButtonElement>(null)
  const input = useRef<HTMLInputElement>(null)
  const [texto, setTexto] = useState(p.tipo === 'prompt' ? (p.o.value ?? '') : '')
  const hecho = useRef(false)
  const uid = useId()

  function cerrar(aceptado: boolean) {
    if (hecho.current) return
    hecho.current = true
    if (p.tipo === 'confirm') p.resolver(aceptado)
    else if (p.tipo === 'prompt') p.resolver(aceptado && texto.trim() ? texto.trim() : null)
    else p.resolver()
    onFin()
  }

  useCapa(true, { onEscape: () => cerrar(false), bloquearScroll: true })
  useTrampaFoco(caja, true, p.tipo === 'prompt' ? input : ok)

  const danger = p.tipo === 'confirm' && !!p.o.danger
  const titulo = p.o.title ?? (p.tipo === 'confirm' ? '¿Seguro?' : 'Aviso')
  const okLabel = p.o.okLabel ?? (p.tipo === 'confirm' ? (danger ? 'Eliminar' : 'Aceptar') : p.tipo === 'prompt' ? 'Guardar' : 'Entendido')
  const cancelLabel = (p.tipo !== 'alert' && p.o.cancelLabel) || 'Cancelar'
  const mensaje = p.o.message

  const BOTON = 'rounded-[10px] px-[15px] py-[9px] text-[13px] font-semibold transition-colors max-sm:min-h-[46px] max-sm:w-full'

  return createPortal(
    <div
      className="fixed inset-0 z-[2000] flex items-center justify-center bg-[rgba(16,19,24,.34)] p-5 backdrop-blur-[2px] backdrop-saturate-[1.4] motion-safe:animate-fade-in dark:bg-black/60"
      onMouseDown={(e) => e.target === e.currentTarget && cerrar(false)}
    >
      <div
        ref={caja}
        role="alertdialog"
        aria-modal="true"
        aria-labelledby={`${uid}-t`}
        aria-describedby={mensaje ? `${uid}-m` : undefined}
        onKeyDown={(e) => {
          // Enter acepta salvo con el foco en «Cancelar».
          if (e.key === 'Enter' && e.target !== cancelar.current) {
            e.preventDefault()
            cerrar(true)
          }
        }}
        className="w-full max-w-[380px] rounded-2xl bg-card px-[22px] pt-[22px] pb-4 shadow-dialog motion-safe:animate-dlg-in max-sm:w-[min(420px,94vw)] max-sm:max-w-none dark:border dark:border-line"
      >
        <h4 id={`${uid}-t`} className="mb-1.5 text-[15.5px] font-[650] tracking-[-.2px] text-ink-strong">
          {titulo}
        </h4>
        {mensaje && (
          <p id={`${uid}-m`} className="mb-4 text-[13px] leading-[1.55] whitespace-pre-line text-[#6b7078] dark:text-muted">
            {mensaje}
          </p>
        )}
        {p.tipo === 'prompt' && (
          <input
            ref={input}
            type="text"
            value={texto}
            onChange={(e) => setTexto(e.target.value)}
            placeholder={p.o.placeholder}
            aria-label={titulo}
            className="mb-4 w-full rounded-[10px] border border-line bg-soft px-3 py-2.5 text-[13.5px] text-ink transition-[border-color,background-color,box-shadow] focus:border-[#c9ccd1] focus:bg-card focus:shadow-[0_0_0_3px_rgba(31,35,42,.06)] focus:outline-none max-sm:text-[16px] dark:focus:border-line-strong"
          />
        )}
        {!mensaje && p.tipo !== 'prompt' && <div className="mb-3" />}
        <div className="flex justify-end gap-2 max-sm:flex-col-reverse">
          {p.tipo !== 'alert' && (
            <button ref={cancelar} type="button" onClick={() => cerrar(false)} className={`${BOTON} bg-soft text-[#5a5f68] hover:bg-[#eeeef0] dark:text-ink dark:hover:bg-line-strong`}>
              {cancelLabel}
            </button>
          )}
          <button
            ref={ok}
            type="button"
            onClick={() => cerrar(true)}
            className={`${BOTON} text-white ${danger ? 'bg-[#c0343a] hover:bg-[#a82c31]' : 'bg-ink-strong hover:bg-black dark:text-[#171717] dark:hover:bg-white'}`}
          >
            {okLabel}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  )
}
