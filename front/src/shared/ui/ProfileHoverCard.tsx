import { useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react'
import { createPortal } from 'react-dom'
import { Link } from 'react-router-dom'
import { Clock, Mail, MessageCircle, Users } from 'lucide-react'
import Avatar from './Avatar'
import PresenceDot from './PresenceDot'
import { PRESENCIA, type Presencia } from '../lib/paletas'
import { useIntencion } from '../lib/useIntencion'

export type PerfilTarjeta = {
  id: number
  username: string
  foto?: string | null
  rol?: string | null
  email?: string | null
  presencia?: Presencia
  /* «hace 5 min» (ver haceTiempo) cuando no está en línea. */
  ultimaVez?: string | null
  /* «10:42 am hora local». */
  horaLocal?: string | null
  equipo?: string | null
}

const ANCHO = 264

/* Tarjeta de perfil al pasar el ratón (#erpProfCard): aparece tras 450 ms
   quieto encima (o al enfocar con teclado) y se va 180 ms después de salir,
   salvo que el ratón entre en la tarjeta. Los datos llegan por props: cuando
   exista la API de presencia, quien la use solo tendrá que pasarlos. */
export default function ProfileHoverCard({
  persona,
  esYo = false,
  onChat,
  perfilHref,
  children,
  className = '',
}: {
  persona: PerfilTarjeta
  esYo?: boolean
  onChat?: () => void
  perfilHref?: string
  children: ReactNode
  className?: string
}) {
  const ancla = useRef<HTMLSpanElement>(null)
  const { abierto, entrar, salir, mantener, cerrar } = useIntencion({ abrirMs: 450, cerrarMs: 180 })

  return (
    <>
      <span
        ref={ancla}
        className={`inline-flex ${className}`}
        onMouseEnter={() => entrar(true)}
        onMouseLeave={salir}
        onFocus={() => entrar(true)}
        onBlur={salir}
        onKeyDown={(e) => e.key === 'Escape' && cerrar()}
      >
        {children}
      </span>
      {abierto && (
        <Tarjeta ancla={ancla} persona={persona} esYo={esYo} onChat={onChat} perfilHref={perfilHref ?? `/perfil/${persona.id}`} onEnter={mantener} onLeave={salir} />
      )}
    </>
  )
}

function Tarjeta({
  ancla,
  persona,
  esYo,
  onChat,
  perfilHref,
  onEnter,
  onLeave,
}: {
  ancla: RefObject<HTMLSpanElement | null>
  persona: PerfilTarjeta
  esYo: boolean
  onChat?: () => void
  perfilHref: string
  onEnter: () => void
  onLeave: () => void
}) {
  const caja = useRef<HTMLDivElement>(null)
  const [pos, setPos] = useState<{ left: number; top?: number; bottom?: number } | null>(null)

  useLayoutEffect(() => {
    const r = ancla.current?.getBoundingClientRect()
    const alto = caja.current?.offsetHeight ?? 220
    if (!r) return
    const left = Math.max(8, Math.min(r.left + r.width / 2 - ANCHO / 2, window.innerWidth - ANCHO - 8))
    // Debajo del ancla; encima si no cabe.
    setPos(r.bottom + 8 + alto > window.innerHeight - 8 && r.top - 8 - alto > 8 ? { left, bottom: window.innerHeight - r.top + 8 } : { left, top: r.bottom + 8 })
  }, [ancla])

  const estado = persona.presencia
  const BOTON =
    'flex h-[34px] flex-1 items-center justify-center gap-1.5 rounded-[10px] border border-line bg-card text-[12.5px] font-semibold text-ink transition-colors hover:border-[#d9dade] hover:bg-[#f4f5f7] dark:hover:border-line-strong dark:hover:bg-soft'

  return createPortal(
    <div
      ref={caja}
      role="dialog"
      aria-label={`Perfil de ${persona.username}`}
      onMouseEnter={onEnter}
      onMouseLeave={onLeave}
      className="fixed z-[1350] w-[264px] overflow-hidden rounded-[18px] border border-black/[.06] bg-card shadow-profile motion-safe:animate-perfil-in dark:border-line"
      style={{ left: pos?.left ?? 0, top: pos?.top, bottom: pos?.bottom, visibility: pos ? undefined : 'hidden', transformOrigin: pos?.bottom !== undefined ? 'bottom center' : 'top center' }}
    >
      <div className="flex items-center gap-3 px-4 pt-[15px] pb-3.5">
        <span className="relative shrink-0">
          <Avatar nombre={persona.username} foto={persona.foto} size={46} />
          {estado && <PresenceDot state={estado} className="absolute -right-0.5 -bottom-0.5" />}
        </span>
        <span className="min-w-0">
          <b className="block truncate text-[15.5px] font-[750] text-ink-strong">{esYo ? 'Tú' : persona.username}</b>
          <span className="mt-0.5 flex items-center gap-1.5 truncate text-[12px] font-semibold text-[#6b7079] dark:text-muted">
            {estado && <span className="size-[7px] shrink-0 rounded-full" style={{ backgroundColor: PRESENCIA[estado].color }} aria-hidden="true" />}
            {estado && (estado === 'online' || !persona.ultimaVez ? PRESENCIA[estado].label : persona.ultimaVez)}
            {persona.rol && <span className="truncate">{estado ? '· ' : ''}{persona.rol}</span>}
          </span>
        </span>
      </div>
      {(persona.email || persona.horaLocal || persona.equipo) && (
        <div className="flex flex-col gap-[11px] border-t border-line2 px-4 py-3 text-[13px] text-ink [&_svg]:size-[15px] [&_svg]:shrink-0 [&_svg]:text-label">
          {persona.email && (
            <span className="flex items-center gap-2.5 truncate">
              <Mail /> <span className="truncate">{persona.email}</span>
            </span>
          )}
          {persona.horaLocal && (
            <span className="flex items-center gap-2.5">
              <Clock /> {persona.horaLocal}
            </span>
          )}
          {persona.equipo && (
            <span className="flex items-center gap-2.5">
              <Users /> Equipo {persona.equipo}
            </span>
          )}
        </div>
      )}
      <div className="flex gap-2 bg-soft px-3 py-[11px]">
        {!esYo && onChat && (
          <button type="button" className={BOTON} onClick={onChat}>
            <MessageCircle className="size-3.5" /> Chat
          </button>
        )}
        <Link to={perfilHref} className={BOTON}>
          Ver perfil
        </Link>
      </div>
    </div>,
    document.body,
  )
}
