import type { ElementType, HTMLAttributes, ReactNode } from 'react'
import { useSortable } from '../lib/useSortable'
import type { Eje } from '../lib/ordenar'

/* Asa de 6 puntos (row_grip): visible al pasar por la fila. */
export function RowGrip({ className = '', ...props }: HTMLAttributes<HTMLSpanElement>) {
  return (
    <span
      {...props}
      className={`flex w-3.5 shrink-0 items-center justify-center text-[#c8ccd3] opacity-0 transition-opacity duration-[120ms] group-hover/sort:opacity-100 focus-visible:opacity-100 dark:text-line-strong ${className}`}
    >
      <svg width="10" height="16" viewBox="0 0 10 16" aria-hidden="true" fill="currentColor">
        <circle cx="2.5" cy="3" r="1.4" />
        <circle cx="7.5" cy="3" r="1.4" />
        <circle cx="2.5" cy="8" r="1.4" />
        <circle cx="7.5" cy="8" r="1.4" />
        <circle cx="2.5" cy="13" r="1.4" />
        <circle cx="7.5" cy="13" r="1.4" />
      </svg>
    </span>
  )
}

const MARCA = {
  y: { antes: 'shadow-[inset_0_3px_0_#3b82f6]', despues: 'shadow-[inset_0_-3px_0_#3b82f6]' },
  x: { antes: 'shadow-[inset_3px_0_0_#3b82f6]', despues: 'shadow-[inset_-3px_0_0_#3b82f6]' },
}

/* Lista reordenable. `renderItem` recibe las props del asa: si se usa
   `handle`, solo se arrastra desde el <RowGrip> (o lo que las reciba); si no,
   desde todo el elemento. */
export default function SortableList<T>({
  items,
  getId,
  onReorder,
  axis = 'y',
  handle = true,
  disabled = false,
  as: Tag = 'ul',
  className = '',
  itemClassName = '',
  renderItem,
  'aria-label': ariaLabel,
}: {
  items: T[]
  getId: (item: T) => string | number
  onReorder: (ids: (string | number)[]) => void
  axis?: Eje
  handle?: boolean
  disabled?: boolean
  as?: ElementType
  className?: string
  itemClassName?: string
  renderItem: (item: T, ctx: { handleProps: ReturnType<ReturnType<typeof useSortable>['handleProps']>; dragging: boolean }) => ReactNode
  'aria-label'?: string
}) {
  const ids = items.map(getId)
  const { itemRef, handleProps, marca, arrastrando } = useSortable({ ids, onReorder, axis, disabled })
  const Item = Tag === 'ul' || Tag === 'ol' ? 'li' : 'div'
  return (
    <Tag className={`${axis === 'x' ? 'flex' : ''} ${className}`} aria-label={ariaLabel}>
      {items.map((it) => {
        const id = getId(it)
        const m = marca(id)
        const hp = handleProps(id)
        const dragging = arrastrando === id
        return (
          <Item
            key={id}
            ref={itemRef(id)}
            {...(handle ? {} : hp)}
            className={`group/sort relative ${arrastrando !== null ? 'select-none' : ''} ${dragging ? 'opacity-35' : ''} ${m ? MARCA[axis][m] : ''} ${itemClassName}`}
          >
            {renderItem(it, { handleProps: hp, dragging })}
          </Item>
        )
      })}
    </Tag>
  )
}
