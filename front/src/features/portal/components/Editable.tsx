import type { ReactNode } from 'react'
import { Pencil } from 'lucide-react'
import { usePortal, type BloqueEditable } from '../contexto'

/* En el editor en vivo, un bloque con borde naranja y su botón «✏️ Editar…».
   Fuera del editor no añade nada. */
export default function Editable({ bloque, label, children, className = '' }: { bloque: BloqueEditable; label: string; children: ReactNode; className?: string }) {
  const { editar } = usePortal()
  if (!editar) return <>{children}</>
  return (
    <div className={`portal-editable ${className}`}>
      <div className="rounded-[20px] bg-(--p-bg)">{children}</div>
      <button
        type="button"
        onClick={() => editar(bloque)}
        className="absolute -top-3 right-4 z-10 inline-flex items-center gap-1.5 rounded-full bg-[#ff9500] px-3 py-1.5 text-[12.5px] font-bold text-white shadow-md hover:bg-[#f08a00]"
      >
        <Pencil className="size-3.5" />
        {label}
      </button>
    </div>
  )
}
