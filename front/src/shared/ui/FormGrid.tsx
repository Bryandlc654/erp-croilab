import { useMemo, type ReactNode } from 'react'
import { RejillaContext } from './campoContexto'

/* Rejilla de 12 columnas de los formularios de ajustes (.set-grid). Con `one`
   cada campo ocupa toda la fila (.set-grid.one). */
export default function FormGrid({ one = false, className = '', children }: { one?: boolean; className?: string; children: ReactNode }) {
  const ctx = useMemo(() => ({ una: one }), [one])
  return (
    <RejillaContext.Provider value={ctx}>
      <div className={`grid grid-cols-12 gap-x-[22px] gap-y-[18px] max-[700px]:grid-cols-1 ${className}`}>{children}</div>
    </RejillaContext.Provider>
  )
}

/* Título de zona con la línea que llena el resto (.set-zone). Dentro de una
   FormGrid ocupa la fila entera. */
export function FormZone({ title, className = '' }: { title: ReactNode; className?: string }) {
  return (
    <div
      role="heading"
      aria-level={3}
      className={`col-span-full mt-3 mb-0 flex items-center gap-2.5 text-[12px] font-[650] tracking-[.6px] text-muted uppercase first:mt-0 after:h-px after:flex-1 after:bg-line2 after:content-[''] ${className}`}
    >
      {title}
    </div>
  )
}
