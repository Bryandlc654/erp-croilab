import { Link } from 'react-router-dom'
import { ChevronRight, ClipboardCheck, ExternalLink, FileText, Receipt } from 'lucide-react'
import { fechaCorta, fechaLarga } from '../../../../shared/lib/formato'
import { usePortal } from '../../contexto'
import { euros } from '../../logica'
import { ESTADO_FACTURA } from '../../textos'
import Editable from '../Editable'
import { Acordeon, CabeceraVista, Eyebrow, Pastilla, Tarjeta, Vacio } from '../ui'

/* Informes, facturas y plan: lo que el cliente consulta y guarda. */

export function Informes() {
  const { datos: d } = usePortal()
  return (
    <Editable bloque="informes" label="Editar informes">
      <div className="p-1">
        <CabeceraVista titulo="Tus informes mensuales" sub="Cada mes recibes aquí tu informe con todo el análisis. Quedan guardados: puedes abrir cualquiera cuando quieras." />
        {!d.informes.length ? (
          <Vacio icono={<FileText />}>Aún no hay informes publicados. En cuanto subamos el informe del mes aparecerá aquí, y podrás abrirlo siempre que quieras.</Vacio>
        ) : (
          <div className="flex flex-col gap-3">
            {d.informes.map((i, n) => (
              <Acordeon key={n} titulo={i.titulo} extra={i.etiqueta} abierto={n === 0 && d.informes.length === 1}>
                <p className="whitespace-pre-wrap">{i.texto || 'Este informe no tiene texto.'}</p>
                {i.url && (
                  <a href={i.url} target="_blank" rel="noopener noreferrer" className="mt-4 inline-flex items-center gap-2 rounded-xl bg-(--p-acc) px-4 py-2.5 text-[13.5px] font-semibold text-(--p-acc-fg)">
                    <ExternalLink className="size-4" /> Abrir / descargar
                  </a>
                )}
              </Acordeon>
            ))}
          </div>
        )}
      </div>
    </Editable>
  )
}

export function Facturas() {
  const { datos: d, ruta } = usePortal()
  return (
    <div>
      <CabeceraVista titulo="Tus facturas" sub="Todas tus facturas. Pulsa una para verla y descargarla en PDF." />
      {!d.facturas.length ? (
        <Vacio icono={<Receipt />}>Todavía no tienes facturas. Cuando emitamos alguna aparecerá aquí para que la veas y la descargues.</Vacio>
      ) : (
        <Tarjeta className="overflow-hidden">
          {d.facturas.map((f) => {
            const e = ESTADO_FACTURA[f.estado] ?? { label: f.estado, color: '#9aa0a8' }
            return (
              <Link key={f.id} to={ruta('facturas', String(f.id))} className="flex items-center gap-3.5 border-b border-(--p-line) px-4 py-3.5 last:border-b-0 hover:bg-(--p-soft)">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-(--p-soft) text-(--p-muted)">
                  <Receipt className="size-5" />
                </span>
                <span className="min-w-0 flex-1">
                  <b className="block text-[14.5px] text-(--p-ink-strong)">Factura {f.numero}</b>
                  <span className="block text-[12.5px] text-(--p-muted)">
                    {fechaLarga(f.fecha)}
                    {f.venc ? ` · vence ${fechaCorta(f.venc)}` : ''}
                  </span>
                </span>
                <Pastilla color={e.color} solida>
                  {e.label}
                </Pastilla>
                <b className="w-[110px] text-right text-[14.5px] whitespace-nowrap text-(--p-ink-strong) max-sm:w-auto">{euros(f.total)}</b>
                <ChevronRight className="size-4 text-(--p-muted) max-sm:hidden" />
              </Link>
            )
          })}
        </Tarjeta>
      )}
    </div>
  )
}

const ICONOS_PLAN = ['📄', '📝', '📊', '💬', '🗓️']

export function Plan() {
  const { datos: d } = usePortal()
  const p = d.plan
  const vacio = !p.resumen && !p.items.length && !p.detalle.length
  return (
    <Editable bloque="plan" label="Editar plan">
      <div className="p-1">
        <CabeceraVista grande titulo="Tu plan mensual" sub="Esto es exactamente lo que tienes contratado cada mes. Sin sorpresas." />
        {vacio ? (
          <Vacio icono={<ClipboardCheck />}>Tu plan se mostrará aquí en cuanto tu equipo lo configure.</Vacio>
        ) : (
          <>
            {p.items.length > 0 && (
              <div className="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3">
                {p.items.map((i, n) => (
                  <Tarjeta key={n} className="flex flex-col items-center px-5 py-6 text-center">
                    <span className="flex size-11 items-center justify-center rounded-xl bg-(--p-soft) text-[20px]" aria-hidden="true">
                      {ICONOS_PLAN[n % ICONOS_PLAN.length]}
                    </span>
                    <span className="mt-3 text-[34px] leading-none font-black text-(--p-ink-strong)">{i.n}</span>
                    <span className="mt-2 text-[13.5px] text-(--p-muted)">{i.t}</span>
                  </Tarjeta>
                ))}
              </div>
            )}
            {p.resumen && (
              <>
                <Eyebrow className="mt-6 mb-2.5 px-1">Qué incluye tu plan</Eyebrow>
                <Tarjeta className="px-6 py-5 text-[14.5px] whitespace-pre-wrap text-(--p-muted)">{p.resumen}</Tarjeta>
              </>
            )}
            {p.detalle.length > 0 && (
              <div className="mt-3.5">
                <Acordeon titulo="Ver el detalle completo de lo que incluye">
                  <div className="flex flex-col gap-3">
                    {p.detalle.map((x, n) => (
                      <div key={n}>
                        <b className="text-(--p-ink-strong)">{x.h}</b>
                        <p className="whitespace-pre-wrap text-(--p-muted)">{x.p}</p>
                      </div>
                    ))}
                  </div>
                </Acordeon>
              </div>
            )}
          </>
        )}
      </div>
    </Editable>
  )
}
