import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { BarChart3, Check, CircleCheckBig, FileText } from 'lucide-react'
import { usePortal } from '../../contexto'
import Editable from '../Editable'
import { CabeceraVista, Tarjeta, Vacio } from '../ui'

/* Progreso «explicado» por mes (tareas_json). En el antiguo esta vista no tenía
   enlace en el menú; aquí sale como «Progreso» si el tipo de cliente la incluye. */
export default function Progreso() {
  const { datos: d, ruta } = usePortal()
  const [params, setParams] = useSearchParams()
  const meses = d.progreso
  const id = (m: (typeof meses)[number]) => m.clave ?? 'x:' + m.etiqueta
  const sel = meses.find((m) => id(m) === params.get('mes')) ?? meses.find((m) => m.clave === d.cliente.actual) ?? meses[0]

  return (
    <Editable bloque="tareas" label="Editar tareas">
      <div className="p-1">
        <CabeceraVista titulo="Tu progreso" sub="Lo que hemos hecho y lo que estamos haciendo, explicado mes a mes." />
        {!sel ? (
          <Vacio icono={<CircleCheckBig />}>Aún no hay progreso publicado. Tu equipo lo irá contando aquí cada mes.</Vacio>
        ) : (
          <>
            <p className="mb-2 text-[12px] font-bold tracking-wide text-(--p-muted) uppercase">Elige un mes</p>
            <div className="mb-4 flex flex-wrap gap-2">
              {meses.map((m) => (
                <button
                  key={id(m)}
                  type="button"
                  onClick={() => setParams({ mes: id(m) }, { replace: true })}
                  className={`rounded-full px-3.5 py-1.5 text-[13px] font-semibold ${m === sel ? 'bg-(--p-acc) text-(--p-acc-fg)' : 'border border-(--p-line) bg-(--p-card) text-(--p-ink)'}`}
                >
                  {m.etiqueta}
                </button>
              ))}
            </div>
            <div className="mb-4 flex flex-wrap gap-2">
              {d.secciones.metricas && sel.clave && d.metricas.some((m) => m.clave === sel.clave) && (
                <Link to={`${ruta('metricas')}?mes=${sel.clave}`} className="inline-flex items-center gap-2 rounded-full bg-(--p-soft) px-4 py-2 text-[13px] font-medium text-(--p-ink-strong)">
                  <BarChart3 className="size-4" /> Métricas de {sel.etiqueta.toLowerCase()}
                </Link>
              )}
              {d.secciones.informes && d.informes.some((i) => i.clave === sel.clave) && (
                <Link to={ruta('informes')} className="inline-flex items-center gap-2 rounded-full bg-(--p-soft) px-4 py-2 text-[13px] font-medium text-(--p-ink-strong)">
                  <FileText className="size-4" /> Informe de {sel.etiqueta.toLowerCase()}
                </Link>
              )}
            </div>
            {!sel.pendiente.length && !sel.completado.length && <Vacio>Sin tareas registradas en {sel.etiqueta.toLowerCase()}.</Vacio>}
            {(
              [
                ['En curso ahora', sel.pendiente, false],
                ['Completado', sel.completado, true],
              ] as const
            ).map(([titulo, lista, hecho]) =>
              lista.length ? (
                <section key={titulo} className="mb-4">
                  <p className="mb-2 px-1 text-[13px] font-bold text-(--p-ink-strong)">
                    {titulo} ({lista.length})
                  </p>
                  <Tarjeta className="overflow-hidden">
                    {lista.map((t, i) => (
                      <Item key={i} t={t.t} d={t.d} hecho={hecho} />
                    ))}
                  </Tarjeta>
                </section>
              ) : null,
            )}
          </>
        )}
      </div>
    </Editable>
  )
}

function Item({ t, d, hecho }: { t: string; d: string; hecho: boolean }) {
  const [on, setOn] = useState(false)
  return (
    <div className="border-b border-(--p-line) last:border-b-0">
      <button type="button" onClick={() => setOn(!on)} aria-expanded={on} className="flex w-full items-center gap-3 px-4 py-3 text-left" disabled={!d}>
        <span className={`flex size-5 shrink-0 items-center justify-center rounded-md ${hecho ? 'bg-(--p-green) text-white' : 'border-2 border-(--p-line)'}`}>{hecho && <Check className="size-3.5" strokeWidth={3} />}</span>
        <span className="flex-1 text-[14px] text-(--p-ink-strong)">{t}</span>
        <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${hecho ? 'bg-[#12a150]/10 text-(--p-green)' : 'bg-[#3b82f6]/10 text-[#3b82f6]'}`}>{hecho ? 'Hecho' : 'En curso'}</span>
      </button>
      {on && d && <p className="px-4 pb-3 pl-12 text-[13.5px] whitespace-pre-wrap text-(--p-muted)">{d}</p>}
    </div>
  )
}
