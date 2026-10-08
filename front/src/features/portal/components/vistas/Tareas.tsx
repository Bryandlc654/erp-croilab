import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Check, ListChecks } from 'lucide-react'
import Select from '../../../../shared/ui/Select'
import { fechaCorta } from '../../../../shared/lib/formato'
import { usePortal } from '../../contexto'
import type { TareaPortal } from '../../schemas'
import { ESTADO_TAREA, PRIORIDAD } from '../../textos'
import { CabeceraVista, PAvatar, Tarjeta, Vacio } from '../ui'

/* «Tu trabajo»: las tareas reales que ve el cliente, por mes, con quién las lleva. */
export default function Tareas() {
  const { datos: d } = usePortal()
  const [params, setParams] = useSearchParams()
  const grupos: { id: string; etiqueta: string; tareas: TareaPortal[] }[] = []
  for (const t of d.tareas) {
    const id = t.clave ?? 'x:' + t.mes
    let g = grupos.find((x) => x.id === id)
    if (!g) grupos.push((g = { id, etiqueta: t.mes, tareas: [] }))
    g.tareas.push(t)
  }
  const pedido = params.get('mes')
  const sel = pedido === 'todos' ? 'todos' : grupos.find((g) => g.id === pedido)?.id ?? grupos.find((g) => g.id === d.cliente.actual)?.id ?? grupos[0]?.id ?? 'todos'
  const vistos = sel === 'todos' ? grupos : grupos.filter((g) => g.id === sel)

  return (
    <div>
      <CabeceraVista titulo="Tu trabajo" sub="Esto es lo que estamos haciendo por ti, con quién lo lleva. Pulsa una tarea para ver el detalle." />
      {!d.tareas.length ? (
        <Vacio icono={<ListChecks />}>
          Aún no hay tareas para ti. En cuanto empecemos a trabajar en tu proyecto aparecerán aquí, mes a mes y con quién lleva cada cosa.
        </Vacio>
      ) : (
        <>
          <label className="mb-4 flex items-center gap-2">
            <span className="text-[11px] font-bold tracking-wide text-(--p-muted) uppercase">Mes</span>
            <span className="w-[230px]">
              <Select
                value={sel}
                onChange={(v) => setParams({ mes: v }, { replace: true })}
                options={[{ value: 'todos', label: 'Todos los meses' }, ...grupos.map((g) => ({ value: g.id, label: g.etiqueta + (g.id === d.cliente.actual ? ' · este mes' : '') }))]}
              />
            </span>
          </label>
          <div className="flex flex-col gap-5">
            {vistos.map((g) => (
              <section key={g.id}>
                <div className="mb-2 flex items-center gap-2.5 px-1">
                  <span className="inline-flex items-center gap-1.5 text-[12px] font-bold tracking-wide text-(--p-ink-strong) uppercase">
                    <span className="size-1.5 rounded-full bg-(--p-ink-strong)" />
                    {g.etiqueta}
                  </span>
                  {g.id === d.cliente.actual && <span className="rounded-full bg-[#12a150]/10 px-2 py-0.5 text-[10.5px] font-bold text-(--p-green) uppercase">Este mes</span>}
                  <span className="text-[13px] font-semibold text-(--p-muted)">
                    {g.tareas.filter((t) => t.estado === 'completada').length}/{g.tareas.length} hechas
                  </span>
                </div>
                <Tarjeta className="overflow-hidden">
                  <div className="grid grid-cols-[1fr_190px_120px_90px] border-b border-(--p-line) px-4 py-3 text-[11px] font-bold tracking-wide text-(--p-muted) uppercase max-md:hidden">
                    <span>Tarea</span>
                    <span>Quién lo lleva</span>
                    <span>Prioridad</span>
                    <span>Fecha</span>
                  </div>
                  {g.tareas.map((t) => (
                    <Fila key={t.id} t={t} />
                  ))}
                </Tarjeta>
              </section>
            ))}
            {!vistos.length && <Vacio>No hay tareas en ese mes.</Vacio>}
          </div>
        </>
      )}
    </div>
  )
}

function Circulo({ estado }: { estado: string }) {
  const e = ESTADO_TAREA[estado] ?? ESTADO_TAREA.pendiente
  if (estado === 'completada') {
    return (
      <span className="flex size-[18px] shrink-0 items-center justify-center rounded-full text-white" style={{ background: e.color }} title={e.label}>
        <Check className="size-3" strokeWidth={3.5} />
      </span>
    )
  }
  return <span className={`size-[18px] shrink-0 rounded-full border-2 ${estado === 'atemporal' ? 'border-dotted' : ''}`} style={{ borderColor: e.color }} title={e.label} />
}

function Fila({ t }: { t: TareaPortal }) {
  const [abierta, setAbierta] = useState(t.texto !== '')
  const p = PRIORIDAD[t.prioridad]
  const nombres = t.asignados.map((a) => a.nombre)
  return (
    <div className="border-b border-(--p-line) last:border-b-0">
      <button
        type="button"
        onClick={() => setAbierta(!abierta)}
        aria-expanded={abierta}
        className="grid w-full grid-cols-[1fr_190px_120px_90px] items-center gap-2 px-4 py-3.5 text-left hover:bg-(--p-soft) max-md:grid-cols-[1fr_auto]"
      >
        <span className="flex min-w-0 items-center gap-2.5">
          <Circulo estado={t.estado} />
          <span className="truncate text-[14.5px] font-medium text-(--p-ink-strong)">{t.titulo}</span>
          {t.estado === 'completada' && <span className="rounded-full bg-[#12a150]/10 px-2 py-0.5 text-[10.5px] font-bold text-(--p-green) uppercase">Hecho</span>}
        </span>
        <span className="flex items-center gap-2 text-[13.5px] text-(--p-ink)">
          <span className="flex -space-x-1.5">
            {t.asignados.slice(0, 3).map((a, i) => (
              <PAvatar key={i} nombre={a.nombre} foto={a.foto} color={a.color || undefined} size={24} />
            ))}
            {t.asignados.length > 3 && <span className="flex size-6 items-center justify-center rounded-full bg-(--p-soft) text-[10px] font-bold">+{t.asignados.length - 3}</span>}
          </span>
          <span className="truncate max-md:hidden">{nombres.length ? nombres[0] + (nombres.length > 1 ? ` +${nombres.length - 1}` : '') : 'Sin asignar'}</span>
        </span>
        <span className="flex items-center gap-1.5 text-[13px] text-(--p-ink) max-md:hidden">
          {p ? (
            <>
              <span className="size-2 rounded-full" style={{ background: p.color }} />
              {p.label}
            </>
          ) : (
            <span className="text-(--p-muted)">—</span>
          )}
        </span>
        <span className="text-[13px] text-(--p-ink) max-md:hidden">{fechaCorta(t.due, '—')}</span>
      </button>
      {abierta && (
        <div className="px-4 pb-4 pl-[46px] text-[13.5px] whitespace-pre-wrap text-(--p-muted)">
          {t.texto || 'Tu equipo no ha añadido una explicación a esta tarea.'}
          <span className="mt-2 block text-[12px] md:hidden">
            {p ? `Prioridad ${p.label.toLowerCase()} · ` : ''}
            {t.due ? `Fecha ${fechaCorta(t.due)}` : ''}
          </span>
        </div>
      )}
    </div>
  )
}
