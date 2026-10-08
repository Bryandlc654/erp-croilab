import type { ReactNode } from 'react'

const TONOS = {
  // .tag (gris suave), .tag.on (sólido), .tag con borde discontinuo (sección apagada).
  neutro: 'bg-soft text-muted',
  on: 'bg-[#ebebec] text-[#3a3d42] dark:bg-line-strong dark:text-ink',
  off: 'border border-dashed border-[#d4d8de] bg-transparent text-[#9aa0a8] dark:border-line-strong dark:text-muted',
  rojo: 'bg-[#feecec] text-[#c0343a] dark:bg-danger-bg dark:text-danger',
  verde: 'bg-[#e4f6ec] text-[#12854a] dark:bg-ok-bg dark:text-ok',
}

/* Etiqueta pequeña del ERP (.tag): 11px/600, radio 7. */
export default function Etiqueta({ tono = 'neutro', className = '', children }: { tono?: keyof typeof TONOS; className?: string; children: ReactNode }) {
  return (
    <span className={`inline-flex shrink-0 items-center gap-1 rounded-[7px] px-2.5 py-[3px] text-[11px] leading-[1.35] font-semibold whitespace-nowrap ${TONOS[tono]} ${className}`}>
      {children}
    </span>
  )
}

/* Píldora teñida de estado (facturas, tickets): fondo del color al ~9 %. */
export function PildoraEstado({ color, children }: { color: string; children: ReactNode }) {
  return (
    <span className="inline-flex shrink-0 items-center rounded-full px-2.5 py-[3px] text-[11px] font-semibold whitespace-nowrap" style={{ backgroundColor: `${color}18`, color }}>
      {children}
    </span>
  )
}
