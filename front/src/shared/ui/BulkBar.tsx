import { Fragment, type ReactNode } from 'react'
import { X } from 'lucide-react'
import Menu from './Menu'
import { useHotkeys } from '../lib/useHotkeys'

export type AccionLote = {
  label: string
  icon?: ReactNode
  onClick?: () => void
  danger?: boolean
  /* Abre un menú encima de la barra (asignar, etiquetar…). */
  menu?: (cerrar: () => void) => ReactNode
  /* Separador antes de esta acción. */
  separada?: boolean
}

const BOTON = 'inline-flex items-center gap-1.5 rounded-[9px] px-2.5 py-2 text-[12.5px] font-semibold whitespace-nowrap transition-colors [&_svg]:size-[15px]'

/* Barra de acciones en lote (.cm-bulk): flota abajo en el centro mientras hay
   filas marcadas; Esc quita la selección (si no hay otro flotante abierto). */
export default function BulkBar({ count, actions, onClear, label = 'seleccionados' }: { count: number; actions: AccionLote[]; onClear: () => void; label?: string }) {
  const visible = count > 0
  // Sin campos a la vista ni otra capa encima: Esc es «deseleccionar».
  useHotkeys({ escape: () => onClear() }, { enabled: visible })

  return (
    <div
      role="toolbar"
      aria-label="Acciones con la selección"
      aria-hidden={!visible}
      inert={!visible}
      className={`fixed bottom-[26px] left-1/2 z-[520] flex max-w-[calc(100vw-16px)] -translate-x-1/2 items-center gap-1 overflow-x-auto rounded-[14px] border border-line bg-card py-[7px] pr-2 pl-3.5 shadow-bulk transition-[transform,opacity] duration-[220ms] ease-erp max-sm:right-2 max-sm:bottom-2 max-sm:left-2 max-sm:translate-x-0 ${
        visible ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-[150%] opacity-0'
      }`}
    >
      <span className="mr-1.5 flex shrink-0 items-center gap-2 text-[12.5px] font-semibold text-ink">
        <span className="flex h-[22px] min-w-[22px] items-center justify-center rounded-full bg-accent px-1.5 text-[11.5px] font-bold text-white dark:text-accent-fg">{count}</span>
        <span className="max-sm:hidden">{label}</span>
      </span>
      <span className="mx-1 h-5 w-px shrink-0 bg-line" aria-hidden="true" />
      {actions.map((a) => {
        const clase = `${BOTON} ${
          a.danger ? 'text-[#c0343a] hover:bg-[#fdecec] hover:text-[#a52a30] dark:text-danger dark:hover:bg-danger-bg' : 'text-ink hover:bg-soft'
        }`
        return (
          <Fragment key={a.label}>
            {a.separada && <span className="mx-1 h-5 w-px shrink-0 bg-line" aria-hidden="true" />}
            {a.menu ? (
              <Menu
                placement="top-start"
                label={a.label}
                trigger={() => (
                  <span className={clase}>
                    {a.icon}
                    {a.label}
                  </span>
                )}
              >
                {a.menu}
              </Menu>
            ) : (
              <button type="button" onClick={a.onClick} className={clase}>
                {a.icon}
                {a.label}
              </button>
            )}
          </Fragment>
        )
      })}
      <span className="mx-1 h-5 w-px shrink-0 bg-line" aria-hidden="true" />
      <button type="button" onClick={onClear} aria-label="Quitar la selección" title="Quitar la selección (Esc)" className={`${BOTON} text-label hover:bg-soft hover:text-ink`}>
        <X />
      </button>
    </div>
  )
}
