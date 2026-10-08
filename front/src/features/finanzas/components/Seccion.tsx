import { useId, useState, type ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import Collapse from '../../../shared/ui/Collapse'

/* Tarjeta plegable del editor de facturas (.fe-card): título en mayúsculas,
   ▾ que gira al plegar y contenido animado. */
export default function Seccion({ titulo, extra, children, abierta = true }: { titulo: ReactNode; extra?: ReactNode; children: ReactNode; abierta?: boolean }) {
  const [open, setOpen] = useState(abierta)
  const id = useId()
  return (
    <section className="rounded-2xl border border-line bg-card px-[26px] py-6 max-sm:px-4 max-sm:py-[18px]">
      <h3>
        <button
          type="button"
          aria-expanded={open}
          aria-controls={id}
          onClick={() => setOpen((v) => !v)}
          className="flex w-full items-center gap-1.5 text-left text-[13px] font-bold tracking-[.4px] text-muted uppercase hover:text-ink"
        >
          <ChevronDown className={`size-3.5 transition-transform duration-200 ${open ? '' : '-rotate-90'}`} strokeWidth={2.6} />
          {titulo}
          {extra && <span className="text-[12.5px] font-normal tracking-normal normal-case">{extra}</span>}
        </button>
      </h3>
      <Collapse open={open} id={id}>
        <div className="pt-[18px]">{children}</div>
      </Collapse>
    </section>
  )
}
