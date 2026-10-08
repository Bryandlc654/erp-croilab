import { useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Clock } from 'lucide-react'
import Avatar from '../../../../shared/ui/Avatar'
import Popover from '../../../../shared/ui/Popover'
import type { Persona } from '../../../../shared/schemas'
import { useToast } from '../../../../shared/ui/useToast'
import { useTiempo } from '../../api'
import { horasTexto, horasValidas } from '../../ficha'
import type { TareaDetalle } from '../../schemas'

/* Campo «Tiempo» de la ficha (.tm-pop): horas de cada persona en esta tarea.
   Cada una tiene su línea «Horas de la tarea» y se factura a su tarifa en
   Finanzas › Horas; aquí no se tocan las demás líneas. */
export default function TiempoPopover({ t, meId, equipo, puede }: { t: TareaDetalle; meId: number; equipo: Persona[]; puede: boolean }) {
  const boton = useRef<HTMLButtonElement>(null)
  const [abierto, setAbierto] = useState(false)
  const [quien, setQuien] = useState(meId)
  const [horas, setHoras] = useState('')
  const guardar = useTiempo(t.id)
  const { aviso } = useToast()
  const total = t.tiempo.total_min

  const minutosDe = (id: number) => t.tiempo.reparto.find((r) => r.persona.id === id)?.minutos ?? 0
  /* Tú, los asignados y quien ya tenga horas apuntadas. */
  const opciones = useMemo(() => {
    const ids = [meId, ...t.asignados.map((a) => a.id), ...t.tiempo.reparto.map((r) => r.persona.id)]
    return [...new Set(ids)].map((id) => equipo.find((p) => p.id === id) ?? t.tiempo.reparto.find((r) => r.persona.id === id)?.persona).filter((p): p is Persona => !!p)
  }, [meId, t.asignados, t.tiempo.reparto, equipo])

  function abrir() {
    setQuien(meId)
    const m = minutosDe(meId)
    setHoras(m ? String(Math.round((m / 60) * 100) / 100).replace('.', ',') : '')
    setAbierto(true)
  }

  function elegir(id: number) {
    setQuien(id)
    const m = minutosDe(id)
    setHoras(m ? String(Math.round((m / 60) * 100) / 100).replace('.', ',') : '')
  }

  function enviar() {
    const v = horasValidas(horas)
    if (v === null) {
      aviso('Escribe las horas con números (por ejemplo 1,5).', { tipo: 'error' })
      return
    }
    guardar.mutate({ horas: v, admin_id: quien }, { onSuccess: () => setAbierto(false) })
  }

  if (!puede) {
    return <span className={`px-[9px] py-1.5 text-[13.5px] ${total ? 'text-ink' : 'text-label'}`}>{total ? horasTexto(total) : 'Sin tiempo registrado'}</span>
  }

  return (
    <>
      <button
        ref={boton}
        type="button"
        onClick={abrir}
        className={`inline-flex items-center gap-1.5 rounded-lg px-[9px] py-1.5 text-[13.5px] transition-colors hover:bg-soft ${total ? 'font-medium text-ink' : 'text-label'}`}
      >
        {total ? horasTexto(total) : 'Añadir tiempo'}
      </button>
      <Popover open={abierto} onClose={() => setAbierto(false)} anchor={boton} width={262} initialFocus="none" className="p-3">
        <div className="mb-2 text-[12px] font-[650] text-ink-strong">Horas en esta tarea</div>
        <label className="mb-1 block text-[11px] font-semibold text-muted" htmlFor="tm-quien">
          De quién
        </label>
        <select
          id="tm-quien"
          value={quien}
          onChange={(e) => elegir(Number(e.target.value))}
          className="mb-2 h-9 w-full rounded-[9px] border border-line bg-soft px-2.5 text-[13px] text-ink focus:outline-none max-sm:text-[16px]"
        >
          {opciones.map((p) => (
            <option key={p.id} value={p.id}>
              {p.id === meId ? `Tú (${p.username})` : p.username}
              {minutosDe(p.id) ? ` · ${horasTexto(minutosDe(p.id))}` : ''}
            </option>
          ))}
        </select>
        <div className="flex items-center gap-2">
          <input
            autoFocus
            inputMode="decimal"
            value={horas}
            onChange={(e) => setHoras(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && enviar()}
            placeholder="0"
            aria-label="Horas"
            className="h-9 w-20 rounded-[9px] border border-line bg-field px-2.5 text-right text-[13.5px] text-ink focus:border-ink-strong focus:outline-none max-sm:text-[16px]"
          />
          <span className="text-[13px] text-muted">h</span>
          <button
            type="button"
            onClick={enviar}
            disabled={guardar.isPending}
            className="ml-auto rounded-lg bg-ink-strong px-3 py-[7px] text-[12.5px] font-semibold text-page transition-opacity disabled:opacity-60"
          >
            {guardar.isPending ? 'Guardando…' : 'Guardar'}
          </button>
        </div>
        {t.tiempo.reparto.length > 0 && (
          <ul className="mt-3 flex flex-col gap-1.5 border-t border-line2 pt-2.5">
            {t.tiempo.reparto.map((r) => (
              <li key={r.persona.id} className={`flex items-center gap-2 text-[12.5px] ${r.persona.id === meId ? 'font-semibold text-ink-strong' : 'text-ink'}`}>
                <Avatar nombre={r.persona.username} foto={r.persona.foto} size={20} />
                <span className="min-w-0 flex-1 truncate">{r.persona.id === meId ? 'Tú' : r.persona.username}</span>
                <span>{horasTexto(r.minutos)}</span>
              </li>
            ))}
            <li className="mt-1 flex items-center justify-between border-t border-line2 pt-2 text-[12.5px] font-semibold text-ink-strong">
              <span>Total de la tarea</span>
              <span>{horasTexto(total)}</span>
            </li>
          </ul>
        )}
        <p className="mt-2.5 flex gap-1.5 text-[11.5px] leading-[1.45] text-muted">
          <Clock className="mt-px size-3.5 shrink-0" aria-hidden="true" />
          <span>
            Elige arriba <b className="font-semibold text-ink">de quién</b> son las horas. Cada persona tiene las suyas y se facturan a su tarifa en{' '}
            <Link to={`/finanzas/horas?u=${quien}`} className="font-semibold text-ink underline-offset-2 hover:underline">
              Finanzas › Horas
            </Link>
            .
          </span>
        </p>
      </Popover>
    </>
  )
}
