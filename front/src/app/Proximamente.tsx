import { Construction } from 'lucide-react'
import { Link } from 'react-router-dom'

/* Módulos que existían en el ERP PHP y aún no tienen pantalla en React. */
export default function Proximamente({ titulo }: { titulo: string }) {
  return (
    <div className="mx-auto mt-[min(14vh,120px)] max-w-[430px] text-center">
      <div className="mx-auto mb-[18px] flex size-14 items-center justify-center rounded-[17px] bg-soft text-label">
        <Construction className="size-[26px]" strokeWidth={1.6} />
      </div>
      <h1 className="mb-2 text-[19px] font-semibold text-ink-strong">{titulo}</h1>
      <p className="mx-auto max-w-[38ch] text-[13.5px] leading-relaxed text-muted">
        Esta sección todavía no está disponible en el nuevo panel.
      </p>
      <Link
        to="/tareas?view=mine"
        className="mt-6 inline-flex items-center rounded-[9px] border border-line px-[13px] py-[7px] text-[12.5px] font-semibold text-ink hover:bg-soft"
      >
        Ver mis tareas
      </Link>
    </div>
  )
}
