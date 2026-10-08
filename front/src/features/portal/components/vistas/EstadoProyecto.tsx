import { Link } from 'react-router-dom'
import { ListChecks } from 'lucide-react'
import { usePortal } from '../../contexto'
import { Eyebrow, Tarjeta } from '../ui'

/* «Estado del proyecto»: etapa, barra por fases (hechas rellenas, la actual
   destacada) y «Lo siguiente». */
export default function EstadoProyecto({ conEnlace = false }: { conEnlace?: boolean }) {
  const { datos: d, ruta } = usePortal()
  const e = d.estado
  if (!e.nombre && !e.fases.length && !e.siguiente) {
    return (
      <Tarjeta className="p-[26px] max-sm:p-5">
        <Eyebrow>Estado del proyecto</Eyebrow>
        <p className="mt-2 text-[14px] text-(--p-muted)">Tu equipo todavía no ha publicado el estado del proyecto.</p>
      </Tarjeta>
    )
  }
  return (
    <Tarjeta className="p-[26px] max-sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <Eyebrow>Estado del proyecto</Eyebrow>
          <h2 className="mt-2 text-[20px] font-bold text-(--p-ink-strong)">{e.nombre}</h2>
        </div>
        {e.etiqueta && <span className="rounded-full bg-(--p-dark) px-3 py-1.5 text-[12px] font-bold whitespace-nowrap text-white dark:bg-(--p-soft)">{e.etiqueta}</span>}
      </div>
      {e.fases.length > 0 && (
        <div className="mt-5 grid gap-[7px]" style={{ gridTemplateColumns: `repeat(${e.fases.length}, minmax(0, 1fr))` }}>
          {e.fases.map((f, i) => (
            <div key={i} className="min-w-0">
              <div
                className={`h-[9px] rounded-full ${f.estado === 'done' ? 'bg-(--p-acc)' : f.estado === 'now' ? 'bg-(--p-acc) ring-2 ring-(--p-acc)/30' : 'bg-(--p-soft)'}`}
                title={f.estado === 'done' ? 'Completada' : f.estado === 'now' ? 'En curso ahora' : 'Pendiente'}
              />
              <p className={`mt-2 truncate text-[12px] ${f.estado === 'now' ? 'font-bold text-(--p-ink-strong)' : 'text-(--p-muted)'}`}>
                {f.t}
                {f.s && <span className="font-normal max-md:hidden"> {f.s}</span>}
              </p>
            </div>
          ))}
        </div>
      )}
      {e.siguiente && (
        <p className="mt-5 border-t border-(--p-line) pt-4 text-[14px] text-(--p-muted)">
          <b className="text-(--p-ink-strong)">Lo siguiente:</b> {e.siguiente}
        </p>
      )}
      {conEnlace && (
        <Link to={ruta('tareas')} className="mt-4 inline-flex items-center gap-2 rounded-full bg-(--p-soft) px-4 py-2.5 text-[13.5px] font-medium text-(--p-ink-strong) dark:text-(--p-acc)">
          <ListChecks className="size-4" />
          Ver el trabajo mes a mes
        </Link>
      )}
    </Tarjeta>
  )
}
