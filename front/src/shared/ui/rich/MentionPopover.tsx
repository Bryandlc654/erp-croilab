import Avatar from '../Avatar'
import Popover, { type AnclaPopover } from '../Popover'
import type { Placement } from '../posicion'
import type { PersonaMencion } from '../../lib/menciones'

export type MentionPopoverProps<T extends PersonaMencion> = {
  open: boolean
  onClose: () => void
  anchor: AnclaPopover
  /* Personas ya filtradas (filtrarPersonas). Sin ninguna, no se pinta. */
  items: T[]
  /* Índice resaltado (↑/↓ lo mueven desde el campo). */
  active: number
  onActiveChange?: (i: number) => void
  onPick: (p: T) => void
  placement?: Placement
}

/* Lista de personas para @mencionar (#mnPop del antiguo): el foco se queda en
   el campo, que es quien maneja ↑/↓/Intro/Tab/Esc. */
export default function MentionPopover<T extends PersonaMencion>({ open, onClose, anchor, items, active, onActiveChange, onPick, placement = 'bottom-start' }: MentionPopoverProps<T>) {
  return (
    <Popover
      open={open && items.length > 0}
      onClose={onClose}
      anchor={anchor}
      placement={placement}
      minWidth={170}
      maxHeight={220}
      initialFocus="none"
      returnFocus={false}
      closeOnScroll="close"
      role="listbox"
      aria-label="Mencionar a"
      // Que el clic no quite el foco al campo (ni cierre la mención por blur).
      onMouseDown={(e) => e.preventDefault()}
      className="max-w-[calc(100vw-24px)]"
    >
      {items.map((p, i) => {
        const sel = i === active
        return (
          <div
            key={p.id}
            role="option"
            aria-selected={sel}
            onMouseEnter={() => onActiveChange?.(i)}
            onClick={() => onPick(p)}
            className={`flex cursor-pointer items-center gap-2 rounded-lg px-[9px] py-[7px] text-[13px] text-ink ${sel ? 'bg-accent-soft' : 'hover:bg-soft'}`}
          >
            <Avatar
              nombre={p.username}
              foto={p.foto}
              size={22}
              style={sel ? { boxShadow: '0 0 0 2px var(--c-accent-soft), 0 0 0 3.5px var(--c-accent)' } : undefined}
            />
            <span className="truncate">{p.username}</span>
          </div>
        )
      })}
    </Popover>
  )
}
