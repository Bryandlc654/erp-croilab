import { useId, type CSSProperties, type ReactNode } from 'react'
import { ChevronRight } from 'lucide-react'
import Collapse from './Collapse'
import { usePlegados } from '../lib/usePlegados'

type Comunes = {
  title: ReactNode
  count?: number
  /* Algo más en la cabecera, antes del contador (acciones, totales). */
  extra?: ReactNode
  className?: string
  children: ReactNode
}

type Controlado = Comunes & { collapsed: boolean; onToggle: () => void; collapsedKey?: undefined; storageKey?: undefined; defaultCollapsed?: undefined }
type Recordado = Comunes & {
  /* Clave del grupo; su estado se guarda en localStorage[storageKey]. */
  collapsedKey: string
  storageKey?: string
  defaultCollapsed?: boolean
  collapsed?: undefined
  onToggle?: undefined
}

/* Tarjeta de grupo plegable (.grp del tablero de tareas): cabecera gris con
   chevron y contador, y el contenido que se pliega con animación. Controlada
   (collapsed/onToggle) o recordando su estado por sí sola (collapsedKey). */
export default function GroupCard(props: Controlado | Recordado) {
  if (props.collapsed !== undefined) return <Tarjeta {...props} />
  return <TarjetaRecordada {...props} />
}

function TarjetaRecordada({ collapsedKey, storageKey = 'croilab:grupos', defaultCollapsed = false, ...resto }: Recordado) {
  const { estaPlegado, alternar } = usePlegados(storageKey, defaultCollapsed ? [collapsedKey] : [])
  return <Tarjeta {...resto} collapsed={estaPlegado(collapsedKey)} onToggle={() => alternar(collapsedKey)} />
}

function Tarjeta({ title, count, extra, collapsed, onToggle, className = '', children }: Comunes & { collapsed: boolean; onToggle: () => void }) {
  const id = useId()
  return (
    <section className={`mb-5 rounded-[14px] border border-line bg-page ${className}`}>
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={!collapsed}
        aria-controls={`${id}-cuerpo`}
        className={`flex w-full items-center gap-2.5 rounded-t-[14px] bg-head px-[18px] py-[15px] text-left text-[13px] font-semibold text-ink transition-colors select-none hover:bg-[#f4f5f7] dark:hover:bg-white/5 ${
          collapsed ? 'rounded-b-[14px]' : 'border-b border-line2'
        }`}
      >
        <ChevronRight className={`size-4 shrink-0 text-muted transition-transform ${collapsed ? '' : 'rotate-90'}`} aria-hidden="true" />
        {title}
        {extra}
        {count !== undefined && <span className="ml-auto rounded-full bg-soft px-[9px] py-px text-[11.5px] font-semibold text-muted">{count}</span>}
      </button>
      <Collapse open={!collapsed} id={`${id}-cuerpo`}>
        {children}
      </Collapse>
    </section>
  )
}

/* Rejilla de filas con columnas fijas (.ck-row): `template` es el
   grid-template-columns, p. ej. '1fr 168px 132px 118px 84px'. Por debajo de
   768px no hay cabecera y cada fila se apila. */
export function GridRows({ template, header, 'aria-label': ariaLabel, className = '', children }: { template: string; header?: ReactNode[]; 'aria-label'?: string; className?: string; children: ReactNode }) {
  return (
    <div role="table" aria-label={ariaLabel} className={className}>
      {header && (
        <div
          role="row"
          className="grid items-center gap-2.5 border-b border-line bg-head px-4 py-3 text-[10.5px] font-[650] tracking-[.5px] text-muted uppercase max-md:hidden"
          style={{ gridTemplateColumns: template }}
        >
          {header.map((h, i) => (
            <span key={i} role="columnheader">
              {h}
            </span>
          ))}
        </div>
      )}
      {children}
    </div>
  )
}

export function GridRow({ template, onClick, className = '', style, children }: { template: string; onClick?: () => void; className?: string; style?: CSSProperties; children: ReactNode }) {
  return (
    <div
      role="row"
      onClick={onClick}
      onKeyDown={onClick ? (e) => e.key === 'Enter' && e.target === e.currentTarget && onClick() : undefined}
      tabIndex={onClick ? 0 : undefined}
      className={`grid items-center gap-2.5 border-b border-line px-4 py-3 transition-colors last:border-b-0 hover:bg-hover-row max-md:flex max-md:flex-wrap max-md:gap-x-3 max-md:gap-y-2 ${onClick ? 'cursor-pointer' : ''} ${className}`}
      style={{ gridTemplateColumns: template, ...style }}
    >
      {children}
    </div>
  )
}
