import type { DragEvent, MouseEvent, ReactNode } from 'react'
import { Flag } from 'lucide-react'
import AvatarStack from '../../../shared/ui/AvatarStack'
import { colorEstado, TINTE_EVENTO, varColor } from './estilos'
import type { EventoCal, TareaCal } from '../schemas'

/* Piezas comunes a las vistas: chip de tarea (borde del color del estado),
   chip de evento (tinte del color de su agenda) y festivo. */

export function ChipTarea({ t, onAbrir, avatares = true, className = '' }: { t: TareaCal; onAbrir: (id: number) => void; avatares?: boolean; className?: string }) {
  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation()
        onAbrir(t.id)
      }}
      onContextMenu={(e) => e.stopPropagation()}
      title={t.cliente ? `${t.titulo} · ${t.cliente}` : t.titulo}
      className={`flex w-full min-w-0 items-center gap-[5px] rounded-md border-l-[3px] bg-soft px-[7px] py-[3px] text-left text-[11.5px] text-ink transition-colors hover:bg-[#eef0f3] max-sm:px-1 max-sm:py-0.5 max-sm:text-[9.5px] dark:hover:bg-line-strong ${className}`}
      style={{ borderLeftColor: colorEstado(t.estado) }}
    >
      <span className="min-w-0 flex-1 truncate">{t.titulo}</span>
      {avatares && t.asignados.length > 0 && <AvatarStack people={t.asignados} max={2} size={16} className="shrink-0 max-sm:hidden" />}
    </button>
  )
}

type PropsChipEvento = {
  ev: EventoCal
  onAbrir: (ev: EventoCal, el: HTMLElement) => void
  onMenu: (e: MouseEvent, ev: EventoCal) => void
  hora?: boolean
  arrastrable?: boolean
  onDragStart?: (e: DragEvent, ev: EventoCal) => void
  onDragEnd?: () => void
  className?: string
}

export function ChipEvento({ ev, onAbrir, onMenu, hora = true, arrastrable = false, onDragStart, onDragEnd, className = '' }: PropsChipEvento) {
  return (
    <button
      type="button"
      draggable={arrastrable}
      onDragStart={arrastrable ? (e) => onDragStart?.(e, ev) : undefined}
      onDragEnd={arrastrable ? onDragEnd : undefined}
      onClick={(e) => {
        e.stopPropagation()
        onAbrir(ev, e.currentTarget)
      }}
      onContextMenu={(e) => onMenu(e, ev)}
      title={ev.owner ? `${ev.titulo} · ${ev.owner.username}` : ev.titulo}
      style={varColor(ev.color)}
      className={`flex w-full min-w-0 items-center gap-[5px] rounded-md px-[7px] py-[3px] text-left text-[11.5px] transition-[filter] hover:brightness-[.97] max-sm:px-1 max-sm:py-0.5 max-sm:text-[9.5px] ${TINTE_EVENTO} ${arrastrable ? 'cursor-grab active:cursor-grabbing' : ''} ${className}`}
    >
      {hora && !ev.todo_el_dia && ev.hora && <span className="shrink-0 text-[10px] font-bold opacity-85 max-sm:hidden">{ev.hora}</span>}
      <span className="min-w-0 flex-1 truncate font-medium">{ev.titulo}</span>
    </button>
  )
}

export function Festivo({ nombre, className = '', children }: { nombre: string; className?: string; children?: ReactNode }) {
  return (
    <span className={`flex min-w-0 items-center gap-1 truncate font-bold text-[#b08900] dark:text-warn ${className}`} title={`Festivo: ${nombre}`}>
      <Flag className="size-[11px] shrink-0" aria-hidden="true" />
      <span className="truncate">{nombre}</span>
      {children}
    </span>
  )
}
