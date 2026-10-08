import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { ToastContext, type AccionToast, type ToastCtx } from './useToast'

type Toast = { id: number; msg: string; tipo: 'ok' | 'error' | 'plain'; accion?: AccionToast; saliendo?: boolean }

const SALIDA = 260

/* Avisos abajo a la derecha, como el toast del ERP: oscuro con ✓ verde, 2,2 s;
   los que llevan acción («Deshacer») duran 7 s. */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [lista, setLista] = useState<Toast[]>([])
  const n = useRef(0)
  const temporizadores = useRef(new Set<ReturnType<typeof setTimeout>>())

  const despues = useCallback((fn: () => void, ms: number) => {
    const t = setTimeout(() => {
      temporizadores.current.delete(t)
      fn()
    }, ms)
    temporizadores.current.add(t)
  }, [])

  useEffect(() => {
    const ts = temporizadores.current
    return () => ts.forEach(clearTimeout)
  }, [])

  // Primero se marca para que salga con su animación; luego se quita.
  const quitar = useCallback(
    (id: number) => {
      setLista((l) => l.map((t) => (t.id === id ? { ...t, saliendo: true } : t)))
      despues(() => setLista((l) => l.filter((t) => t.id !== id)), SALIDA)
    },
    [despues],
  )

  const aviso = useCallback<ToastCtx['aviso']>(
    (msg, opts) => {
      const id = ++n.current
      setLista((l) => [...l, { id, msg, tipo: opts?.tipo ?? 'ok', accion: opts?.accion }])
      despues(() => quitar(id), opts?.ms ?? (opts?.accion ? 7000 : 2200))
    },
    [quitar, despues],
  )
  const valor = useMemo(() => ({ aviso }), [aviso])

  return (
    <ToastContext.Provider value={valor}>
      {children}
      <div
        className="pointer-events-none fixed right-[18px] bottom-[18px] z-[2100] flex flex-col items-end gap-2 max-sm:right-2.5 max-sm:bottom-3 max-sm:left-2.5 max-sm:items-stretch"
        aria-live="polite"
      >
        {lista.map((t) => {
          const accion = t.accion
          return (
            <div
              key={t.id}
              role={t.tipo === 'error' ? 'alert' : 'status'}
              className={`pointer-events-auto flex max-w-[320px] items-center gap-[9px] rounded-[11px] px-[15px] py-[11px] text-[13px] font-semibold text-white shadow-toast transition-[opacity,transform] duration-[260ms] ease-erp motion-safe:animate-toast-in max-sm:max-w-none ${
                t.tipo === 'error' ? 'bg-[#c0343a]' : 'bg-[#22262c] dark:bg-[#2a2a2a] dark:ring-1 dark:ring-white/10'
              } ${t.saliendo ? 'translate-y-2.5 opacity-0' : ''}`}
            >
              {t.tipo === 'ok' && (
                <span aria-hidden="true" className="flex size-[18px] shrink-0 items-center justify-center rounded-full bg-[#12a150] text-[11px] leading-none">
                  ✓
                </span>
              )}
              {t.tipo === 'error' && (
                <span aria-hidden="true" className="flex size-[18px] shrink-0 items-center justify-center rounded-full bg-white/25 text-[11px] leading-none font-bold">
                  !
                </span>
              )}
              <span className="min-w-0 flex-1">{t.msg}</span>
              {accion && (
                <button
                  type="button"
                  className="shrink-0 rounded-lg bg-white/[.16] px-[11px] py-[5px] text-[12.5px] font-bold transition-colors hover:bg-white/30"
                  onClick={() => {
                    accion.fn()
                    quitar(t.id)
                  }}
                >
                  {accion.label}
                </button>
              )}
            </div>
          )
        })}
      </div>
    </ToastContext.Provider>
  )
}
