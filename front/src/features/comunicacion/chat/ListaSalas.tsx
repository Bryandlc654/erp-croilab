import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Search, SquarePen } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import PresenceDot from '../../../shared/ui/PresenceDot'
import { horaCorta, vistaPrevia } from '../logica'
import type { Sala, SalasDatos } from '../schemas'

/* Avatar de una sala: el de la otra persona (con presencia) en los directos y
   un cuadrado con las iniciales del grupo. */
export function AvatarSala({ sala, datos, size = 44 }: { sala: Sala; datos: SalasDatos; size?: number }) {
  if (sala.tipo === 'grupo') return <Avatar nombre={`g${sala.id}${sala.nombre}`} inicialesGuardadas={iniciales(sala.nombre)} size={size} forma="cuadrado" />
  const otro = sala.miembros.find((m) => m.id === sala.otro_id)
  const pres = sala.otro_id ? datos.presencia[String(sala.otro_id)]?.estado : undefined
  return (
    <span className="relative inline-flex shrink-0">
      <Avatar nombre={otro?.username ?? sala.nombre} foto={otro?.foto} size={size} />
      {pres && <PresenceDot state={pres} size={size >= 40 ? 11 : 9} className="absolute right-0 bottom-0" />}
    </span>
  )
}

function iniciales(n: string) {
  const p = n.trim().split(/\s+/).filter(Boolean)
  return ((p[0]?.[0] ?? 'G') + (p[1]?.[0] ?? p[0]?.[1] ?? '')).toUpperCase()
}

/* Columna de conversaciones (.ch-list): buscador y salas por último mensaje. */
export default function ListaSalas({ datos, activa, onNuevo }: { datos: SalasDatos; activa: number; onNuevo: () => void }) {
  const [q, setQ] = useState('')
  const salas = useMemo(() => {
    const t = q.trim().toLowerCase()
    if (!t) return datos.salas
    return datos.salas.filter((s) => s.nombre.toLowerCase().includes(t) || vistaPrevia(s, datos.yo).toLowerCase().includes(t))
  }, [datos, q])

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex items-center justify-between px-5 pt-5 pb-3">
        <h2 className="text-[17px] font-semibold text-ink-strong">Chat</h2>
        <button type="button" onClick={onNuevo} title="Nuevo mensaje" aria-label="Nuevo mensaje" className="flex size-[30px] items-center justify-center rounded-[9px] bg-accent text-white transition-transform hover:-translate-y-px dark:text-accent-fg">
          <SquarePen className="size-4" />
        </button>
      </div>
      <div className="px-4 pb-2">
        <label className="relative block">
          <Search className="pointer-events-none absolute top-1/2 left-[11px] size-4 -translate-y-1/2 text-label" aria-hidden="true" />
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Buscar"
            aria-label="Buscar conversaciones"
            className="w-full rounded-[10px] bg-[#f0f0f2] py-[9px] pr-[11px] pl-[33px] text-[13.5px] text-ink placeholder:text-label focus:outline-none focus-visible:ring-2 focus-visible:ring-ring max-sm:text-[16px] dark:bg-soft"
          />
        </label>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto px-2.5 pb-3">
        {datos.salas.length === 0 ? (
          <div className="px-4 py-10 text-center">
            <p className="text-[13.5px] text-muted">Aún no tienes conversaciones</p>
            <button type="button" onClick={onNuevo} className="mt-3.5 rounded-[9px] bg-accent px-3.5 py-2 text-[13px] font-semibold text-white dark:text-accent-fg">
              Empezar una
            </button>
          </div>
        ) : salas.length === 0 ? (
          <p className="px-4 py-8 text-center text-[13px] text-muted">Nada con «{q}».</p>
        ) : (
          <ul className="space-y-0.5">
            {salas.map((s) => {
              const sinLeer = s.no_leidos > 0 && s.id !== activa
              return (
                <li key={s.id}>
                  <Link
                    to={`/chat/${s.id}`}
                    aria-current={s.id === activa ? 'page' : undefined}
                    className={`flex items-center gap-3 rounded-xl px-3 py-[11px] transition-colors ${s.id === activa ? 'bg-accent-soft' : 'hover:bg-soft'}`}
                  >
                    <AvatarSala sala={s} datos={datos} />
                    <span className="min-w-0 flex-1">
                      <span className="flex items-baseline justify-between gap-2">
                        <span className={`truncate text-[14.5px] text-ink-strong ${sinLeer ? 'font-[650]' : 'font-semibold'}`}>{s.nombre}</span>
                        {s.ultimo && <span className={`shrink-0 text-[11.5px] ${sinLeer ? 'font-bold text-accent' : 'text-muted'}`}>{horaCorta(s.ultimo.creado)}</span>}
                      </span>
                      <span className="mt-0.5 flex items-center justify-between gap-2">
                        <span className={`truncate text-[13px] ${sinLeer ? 'font-medium text-ink' : 'text-muted'}`}>{vistaPrevia(s, datos.yo)}</span>
                        {sinLeer && <span className="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-[#12a150] px-1.5 text-[11px] font-bold text-white">{s.no_leidos > 99 ? '99+' : s.no_leidos}</span>}
                      </span>
                    </span>
                  </Link>
                </li>
              )
            })}
          </ul>
        )}
      </div>
    </div>
  )
}
