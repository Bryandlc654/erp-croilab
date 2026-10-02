import { useCallback, useMemo, useRef, useState, type ReactNode } from 'react'
import { ToastContext, type AccionToast, type ToastCtx } from './useToast'

type Toast = { id: number; msg: string; tipo: 'ok' | 'error'; accion?: AccionToast }

/* Avisos abajo a la izquierda, como el «Tarea eliminada · Deshacer» del ERP. */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [lista, setLista] = useState<Toast[]>([])
  const n = useRef(0)

  const quitar = useCallback((id: number) => setLista((l) => l.filter((t) => t.id !== id)), [])
  const aviso = useCallback<ToastCtx['aviso']>(
    (msg, opts) => {
      const id = ++n.current
      setLista((l) => [...l, { id, msg, tipo: opts?.tipo ?? 'ok', accion: opts?.accion }])
      setTimeout(() => quitar(id), opts?.accion ? 7000 : 4000)
    },
    [quitar],
  )
  const valor = useMemo(() => ({ aviso }), [aviso])

  return (
    <ToastContext.Provider value={valor}>
      {children}
      <div className="pointer-events-none fixed bottom-5 left-1/2 z-50 flex -translate-x-1/2 flex-col items-center gap-2" aria-live="polite">
        {lista.map((t) => {
          const accion = t.accion
          return (
            <div
              key={t.id}
              role={t.tipo === 'error' ? 'alert' : 'status'}
              className={`pointer-events-auto flex items-center gap-4 rounded-xl px-4 py-2.5 text-[13px] font-medium shadow-lg ${
                t.tipo === 'error' ? 'bg-[#b91c1c] text-white' : 'bg-[#111318] text-white dark:bg-zinc-100 dark:text-zinc-900'
              }`}
            >
              <span>{t.msg}</span>
              {accion && (
                <button
                  type="button"
                  className="font-semibold underline-offset-2 hover:underline"
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
