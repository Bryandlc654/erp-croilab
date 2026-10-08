import { Link, useSearchParams } from 'react-router-dom'
import { ExternalLink, Pencil, Plus } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Notice from '../../../shared/ui/Notice'
import { fechaCorta } from '../../../shared/lib/formato'
import { mensajeError, useMovimientos } from '../api'
import CargandoFin from '../components/Cargando'
import ContaCabecera, { KpiConta } from '../components/ContaCabecera'
import { csv, descargar, xls } from '../lib/exportar'
import { eurC } from '../lib/importes'
import { usePermisosFin } from '../lib/permisos'
import type { Movimiento } from '../schemas'

/* Contabilidad › Movimientos (contabilidad.php): la caja del año por ámbito.
   Solo lectura: cada apunte se edita en su origen (factura, documento…). */
export default function ContabilidadPage() {
  const p = usePermisosFin()
  const [sp, setSp] = useSearchParams()
  const ambito = sp.get('ambito') || 'empresa'
  const anio = Number(sp.get('anio')) || new Date().getFullYear()
  const { data, error, isLoading } = useMovimientos(ambito, anio)
  const ir = (c: Record<string, string>) => setSp((x) => ({ ...Object.fromEntries(x), ...c }), { replace: true })
  if (!p.conta) return <Notice tone="error">No tienes acceso a la contabilidad.</Notice>
  const hub = ambito === 'empresa'
  const nombreAmbito = data?.ambitos.find((a) => a.clave === ambito)?.nombre ?? ambito

  function exportar(formato: 'xls' | 'csv') {
    if (!data) return
    const nombre = `contabilidad_${hub ? 'empresa' : ambito}_${anio}`
    if (formato === 'csv') descargar(`${nombre}.csv`, csv(data.items), 'text/csv;charset=utf-8')
    else descargar(`${nombre}.xls`, xls(data.items, `Contabilidad · ${nombreAmbito} · ${anio}`), 'application/vnd.ms-excel')
  }

  return (
    <div>
      <ContaCabecera
        pagina="movimientos"
        ambito={ambito}
        anio={anio}
        ambitos={data?.ambitos ?? [{ clave: 'empresa', nombre: 'Hub' }]}
        anios={data?.anios ?? [anio]}
        onAmbito={(a) => ir({ ambito: a })}
        onAnio={(a) => ir({ anio: String(a) })}
        onExport={data ? exportar : undefined}
      />
      {error && <Notice tone="error">{mensajeError(error, 'No se ha podido cargar la contabilidad.')}</Notice>}
      {isLoading && <CargandoFin />}
      {data && (
        <>
          <div className="mb-5 grid grid-cols-4 gap-5 max-[900px]:grid-cols-2 max-sm:gap-3 max-[420px]:grid-cols-1">
            <KpiConta label="Ingresos brutos" value={eurC(data.kpis.ing)} sub={`${eurC(data.kpis.ing_legal)} legal · ${eurC(data.kpis.ing_efectivo)} efectivo`} />
            <KpiConta label="Ingresos netos" value={eurC(data.kpis.neto_ing)} sub="Sin IVA ni IRPF" />
            <KpiConta label="Gastos" value={eurC(data.kpis.gas)} sub={hub ? 'de empresa' : `${eurC(data.kpis.ded)} deducibles`} />
            <KpiConta
              label="Beneficio neto"
              value={eurC(data.kpis.neto_benef)}
              tono={data.kpis.neto_benef >= 0 ? 'ok' : 'mal'}
              sub={`bruto ${eurC(data.kpis.bruto)} · ${data.kpis.margen}% margen`}
            />
          </div>
          {!hub && (
            <div className="mb-5 grid grid-cols-3 gap-5 max-[900px]:grid-cols-1 max-sm:gap-3">
              <KpiConta label="De tu bolsillo" value={eurC(data.kpis.gas_personal)} sub="gastos personales" />
              <KpiConta label="Gastos de empresa" value={eurC(data.kpis.gas_empresa)} sub="los paga la empresa" />
              <KpiConta label="Beneficio real tuyo" value={eurC(data.kpis.neto_ing - data.kpis.gas_personal)} sub="solo con tus gastos" tono={data.kpis.neto_ing - data.kpis.gas_personal >= 0 ? 'ok' : 'mal'} />
            </div>
          )}
          <div className="overflow-hidden rounded-2xl border border-line bg-card">
            <div className="overflow-x-auto max-md:hidden">
              <table className="w-full min-w-[920px] text-[13.5px]">
                <thead className="bg-head text-left text-[10.5px] font-bold tracking-[.5px] text-muted uppercase">
                  <tr>
                    <th className="px-4 py-3">Movimiento</th>
                    <th className="px-3 py-3">Tipo</th>
                    <th className="px-3 py-3">Ámbito</th>
                    <th className="px-3 py-3">Proyecto</th>
                    <th className="px-3 py-3">Fecha</th>
                    <th className="px-3 py-3">Marcas</th>
                    <th className="px-3 py-3 text-right">Importe</th>
                    <th className="w-10" />
                  </tr>
                </thead>
                <tbody>
                  {data.items.length === 0 && (
                    <tr>
                      <td colSpan={8} className="px-6 py-14 text-center text-muted">
                        Sin movimientos en {anio} · {hub ? 'Empresa' : nombreAmbito}.
                      </td>
                    </tr>
                  )}
                  {data.items.map((m) => (
                    <tr key={m.id} className="border-t border-line hover:bg-hover-row">
                      <td className="px-4 py-3">
                        <Concepto m={m} />
                      </td>
                      <td className="px-3 py-3">
                        <Tipo tipo={m.tipo} />
                      </td>
                      <td className="px-3 py-3">
                        <span className="rounded-md bg-chip px-2 py-[3px] text-[11px] font-semibold text-[#5c616b] dark:text-ink">{m.ambito_nombre}</span>
                      </td>
                      <td className="px-3 py-3">
                        {m.project ? (
                          <Link to={`/finanzas/proyectos/${m.project.id}`} className="inline-flex items-center gap-1.5 text-[12.5px] hover:underline">
                            <span className="size-2 rounded-full" style={{ backgroundColor: m.project.color }} />
                            {m.project.nombre}
                          </Link>
                        ) : (
                          <span className="text-label">—</span>
                        )}
                      </td>
                      <td className="px-3 py-3 whitespace-nowrap text-muted">{fechaCorta(m.fecha)}</td>
                      <td className="px-3 py-3">
                        <Marcas m={m} />
                      </td>
                      <td className={`px-3 py-3 text-right font-semibold whitespace-nowrap tabular-nums ${m.tipo === 'gasto' ? 'text-[#e5484d] dark:text-danger' : 'text-ink-strong'}`}>
                        {m.tipo === 'gasto' ? '−' : ''}
                        {eurC(m.importe)}
                      </td>
                      <td className="pr-3">
                        <Origen m={m} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <ul className="md:hidden">
              {data.items.length === 0 && <li className="px-5 py-10 text-center text-[13px] text-muted">Sin movimientos en {anio}.</li>}
              {data.items.map((m) => (
                <li key={m.id} className="flex items-start gap-3 border-b border-line px-4 py-3.5 last:border-b-0">
                  <div className="min-w-0 flex-1">
                    <Concepto m={m} />
                    <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-[12px] text-muted">
                      <Tipo tipo={m.tipo} /> · {fechaCorta(m.fecha)} · {m.ambito_nombre}
                      <Marcas m={m} />
                    </div>
                  </div>
                  <span className={`text-right text-[13.5px] font-semibold tabular-nums ${m.tipo === 'gasto' ? 'text-[#e5484d] dark:text-danger' : 'text-ink-strong'}`}>
                    {m.tipo === 'gasto' ? '−' : ''}
                    {eurC(m.importe)}
                  </span>
                  <Origen m={m} />
                </li>
              ))}
            </ul>
            <Link to="/finanzas/facturas" className="flex items-center gap-2 border-t border-line px-[18px] py-[11px] text-[13.5px] text-label transition-colors hover:bg-hover-row hover:text-ink">
              <Plus className="size-4" /> Añadir más…
            </Link>
          </div>
          <p className="mt-3 text-[12.5px] leading-[1.6] text-muted">
            Los movimientos se registran cobrando o subiendo facturas, volcando horas o desde un proyecto. Aquí solo se consultan; usa el lápiz para ir al origen. Marca <b className="text-ink">Personal</b> = gasto del socio (fuera de empresa) ·{' '}
            <b className="text-ink">Legal</b> = con factura · <b className="text-ink">Deduc.</b> = el socio se lo desgrava.
          </p>
        </>
      )}
    </div>
  )
}

function Concepto({ m }: { m: Movimiento }) {
  const sub = m.cliente_nombre ? `Cliente · ${m.cliente_nombre}` : m.proveedor ? `Proveedor · ${m.proveedor}` : m.persona ? `Equipo · ${m.persona}` : m.categoria
  return (
    <div className="flex min-w-0 items-center gap-2.5">
      <Avatar nombre={m.cliente_nombre || m.proveedor || m.concepto || '?'} size={28} forma="cuadrado" />
      <div className="min-w-0">
        <span className="flex items-center gap-1.5">
          <span className="truncate font-medium text-ink-strong">{m.concepto}</span>
          {m.tipo === 'gasto' && (
            <span className={`shrink-0 rounded-md px-1.5 py-px text-[10px] font-bold ${m.personal ? 'bg-[#e8effc] text-[#2f6df6] dark:bg-[#1b2333] dark:text-[#a9c1ea]' : 'bg-chip text-[#5c616b] dark:text-ink'}`}>
              {m.personal ? 'Personal' : 'Empresa'}
            </span>
          )}
        </span>
        <span className="block truncate text-[12px] text-muted">{sub}</span>
      </div>
    </div>
  )
}

function Tipo({ tipo }: { tipo: 'ingreso' | 'gasto' }) {
  return (
    <span className="inline-flex items-center gap-1.5 text-[12.5px] text-ink">
      <span className="size-2 rounded-full" style={{ backgroundColor: tipo === 'ingreso' ? '#30a46c' : '#e5484d' }} />
      {tipo === 'ingreso' ? 'Ingreso' : 'Gasto'}
    </span>
  )
}

function Marcas({ m }: { m: Movimiento }) {
  return (
    <span className="inline-flex flex-wrap gap-1">
      {m.legal && <span className="rounded-md bg-chip px-1.5 py-px text-[10.5px] font-semibold text-[#5c616b] dark:text-ink">Legal</span>}
      {m.deducible && <span className="rounded-md bg-[#e8effc] px-1.5 py-px text-[10.5px] font-semibold text-[#2f6df6] dark:bg-[#1b2333] dark:text-[#a9c1ea]">Deduc.</span>}
    </span>
  )
}

function Origen({ m }: { m: Movimiento }) {
  const cls = 'flex size-[30px] items-center justify-center rounded-md text-label hover:bg-chip hover:text-ink'
  if (m.factura)
    return (
      <Link to={`/finanzas/facturas/${m.factura.id}`} className={cls} aria-label="Ver la factura" title="Ver la factura">
        <Pencil className="size-[15px]" />
      </Link>
    )
  if (m.documento)
    return (
      <Link
        to={`/finanzas/facturas?em=${encodeURIComponent(m.documento.emisor)}&tipo=${m.documento.tipo}&mes=${m.documento.mes}`}
        className={cls}
        aria-label="Ver en Facturas"
        title="Ver en Facturas"
      >
        <ExternalLink className="size-[15px]" />
      </Link>
    )
  if (m.origen === 'horas')
    return (
      <Link to={`/finanzas/horas?mes=${(m.fecha ?? '').slice(0, 7)}`} className={cls} aria-label="Ver las horas" title="Ver las horas">
        <ExternalLink className="size-[15px]" />
      </Link>
    )
  if (m.project)
    return (
      <Link to={`/finanzas/proyectos/${m.project.id}`} className={cls} aria-label="Ver el proyecto" title="Ver el proyecto">
        <ExternalLink className="size-[15px]" />
      </Link>
    )
  return <span className="size-[30px]" />
}
