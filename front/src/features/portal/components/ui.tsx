import { useState, type ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import { colorDe, iniciales } from '../../../shared/lib/avatar'

/* Piezas visuales del portal (tarjetas de 20px, títulos en versalitas grises…). */

export function Tarjeta({ className = '', children, as = 'div' }: { className?: string; children: ReactNode; as?: 'div' | 'section' }) {
  const C = as
  return <C className={`rounded-[20px] border border-(--p-line) bg-(--p-card) shadow-(--p-shadow) ${className}`}>{children}</C>
}

export function Eyebrow({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <h3 className={`text-[12px] font-bold uppercase tracking-[.08em] text-(--p-muted) ${className}`}>{children}</h3>
}

/* Cabecera de una vista: el antiguo usaba dos estilos (título grande o versalitas). */
export function CabeceraVista({ titulo, sub, grande = false, accion }: { titulo: string; sub?: ReactNode; grande?: boolean; accion?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0">
        {grande ? <h2 className="text-[26px] font-extrabold tracking-tight text-(--p-ink-strong) max-sm:text-[22px]">{titulo}</h2> : <Eyebrow>{titulo}</Eyebrow>}
        {sub && <p className={`mt-1 text-(--p-muted) ${grande ? 'text-[15px] text-(--p-ink)' : 'text-[13px]'}`}>{sub}</p>}
      </div>
      {accion}
    </div>
  )
}

export function Pastilla({ color, children, solida = false }: { color: string; children: ReactNode; solida?: boolean }) {
  return (
    <span
      className="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-[3px] text-[11.5px] font-bold"
      style={solida ? { background: color, color: '#fff' } : { background: `${color}1f`, color }}
    >
      {children}
    </span>
  )
}

export function BotonP({
  children,
  onClick,
  variante = 'oscuro',
  icono,
  disabled,
  type = 'button',
  className = '',
  title,
}: {
  children: ReactNode
  onClick?: () => void
  variante?: 'oscuro' | 'claro' | 'suave'
  icono?: ReactNode
  disabled?: boolean
  type?: 'button' | 'submit'
  className?: string
  title?: string
}) {
  const v = {
    oscuro: 'bg-(--p-acc) text-(--p-acc-fg) hover:opacity-90',
    claro: 'border border-(--p-line) bg-(--p-card) text-(--p-ink-strong) hover:bg-(--p-soft)',
    suave: 'bg-(--p-acc-soft) text-(--p-ink-strong) hover:opacity-80',
  }[variante]
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={`inline-flex min-h-[40px] items-center justify-center gap-2 rounded-xl px-4 text-[14px] font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 [&_svg]:size-4 ${v} ${className}`}
    >
      {icono}
      {children}
    </button>
  )
}

export function Vacio({ children, icono, titulo, accion }: { children: ReactNode; icono?: ReactNode; titulo?: string; accion?: ReactNode }) {
  return (
    <Tarjeta className="px-6 py-8 text-center">
      {icono && <div className="mx-auto mb-3 flex size-12 items-center justify-center rounded-2xl bg-(--p-soft) text-(--p-muted) [&_svg]:size-6">{icono}</div>}
      {titulo && <p className="mb-1 font-semibold text-(--p-ink-strong)">{titulo}</p>}
      <p className="mx-auto max-w-[560px] text-[14px] text-(--p-muted)">{children}</p>
      {accion && <div className="mt-4 flex justify-center">{accion}</div>}
    </Tarjeta>
  )
}

/* Avatar de persona o cliente. `foto` llega ya como data: URL desde la API. */
export function PAvatar({ nombre, ini, foto, size = 28, color }: { nombre: string; ini?: string; foto?: string | null; size?: number; color?: string }) {
  return (
    <span
      aria-hidden="true"
      className="inline-flex shrink-0 items-center justify-center rounded-full bg-cover bg-center font-bold text-white ring-2 ring-(--p-card)"
      style={{
        width: size,
        height: size,
        fontSize: Math.max(9, Math.round(size * 0.38)),
        backgroundColor: color || colorDe(nombre),
        backgroundImage: foto && foto.startsWith('data:image/') ? `url("${foto}")` : undefined,
      }}
    >
      {foto ? null : iniciales(nombre, ini)}
    </span>
  )
}

export function Acordeon({ titulo, extra, children, abierto = false }: { titulo: ReactNode; extra?: ReactNode; children: ReactNode; abierto?: boolean }) {
  const [on, setOn] = useState(abierto)
  return (
    <Tarjeta className="overflow-hidden">
      <button type="button" onClick={() => setOn(!on)} aria-expanded={on} className="flex w-full items-center gap-3 px-[18px] py-4 text-left">
        <span className="min-w-0 flex-1 text-[15px] font-medium text-(--p-ink-strong)">
          {titulo}
          {extra && <span className="text-(--p-muted)"> · {extra}</span>}
        </span>
        <ChevronDown className={`size-4 shrink-0 text-(--p-muted) transition ${on ? 'rotate-180' : ''}`} />
      </button>
      {on && <div className="border-t border-(--p-line) px-[18px] py-4 text-[14px] text-(--p-ink)">{children}</div>}
    </Tarjeta>
  )
}

/* Logo de la marca: imagen si hay, si no la inicial sobre su color (o blanco sobre el raíl). */
export function LogoMarca({ nombre, inicial, logo, color, size = 34, claro = false }: { nombre: string; inicial: string; logo: string; color: string; size?: number; claro?: boolean }) {
  const fondo = color || (claro ? '#ffffff' : 'var(--p-acc)')
  return (
    <span
      className="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-[10px] font-extrabold"
      style={{ width: size, height: size, background: logo ? '#fff' : fondo, color: color ? '#fff' : claro ? '#0f1012' : 'var(--p-acc-fg)', fontSize: Math.round(size * 0.5) }}
    >
      {logo ? <img src={logo} alt={nombre} className="size-full object-contain" /> : inicial}
    </span>
  )
}
