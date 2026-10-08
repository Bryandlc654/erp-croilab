import type { ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ArrowDownRight, ArrowUpRight, Euro, FileText, LayoutGrid } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Notice from '../../../shared/ui/Notice'
import Segmented from '../../../shared/ui/Segmented'
import { ChartCard } from '../../../shared/ui/rich'
import { eurk, mesLabel } from '../../../shared/lib/formato'
import { mensajeError, useResumen } from '../api'
import CargandoFin from '../components/Cargando'
import EstadoPill from '../components/EstadoPill'
import MonthNav from '../components/MonthNav'
import { eurC } from '../lib/importes'
import { esMes, ymDe } from '../lib/periodos'
import { usePermisosFin } from '../lib/permisos'

const MESES_CORTOS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']

/* Resumen mensual (fin-resumen.php), criterio de devengo: lo facturado en el mes. */
export default function ResumenPage() {
  const [sp, setSp] = useSearchParams()
  const p = usePermisosFin()
  const ambito = sp.get('ambito') || 'empresa'
  const mesQ = sp.get('mes')
  const mes = esMes(mesQ) ? mesQ : ymDe()
  const { data, error, isLoading } = useResumen(ambito, mes)
  const ir = (cambios: Record<string, string>) => setSp((x) => ({ ...Object.fromEntries(x), ...cambios }), { replace: true })

  if (!p.ver) return <Notice tone="error">No tienes acceso a Finanzas.</Notice>
  return (
    <div>
      <header className="mb-[22px] flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
        <h1 className="text-[26px] leading-[1.2] font-semibold tracking-[-.5px] text-ink-strong max-sm:text-[23px]">Resumen mensual</h1>
        <div className="flex flex-wrap items-start gap-4 max-lg:w-full">
          <p className="max-w-[560px] text-[12.5px] leading-[1.55] text-muted">
            Basado en lo <b className="text-ink-strong">facturado</b> (devengo): cuenta las facturas emitidas del mes, se hayan cobrado o no (los borradores no cuentan). Lo{' '}
            <b className="text-ink-strong">realmente cobrado</b> (caja) está en{' '}
            <Link to="/finanzas/contabilidad" className="font-semibold text-ink-strong hover:underline">
              Contabilidad
            </Link>{' '}
            — por eso las dos cifras no tienen por qué coincidir.
          </p>
          {data && <Segmented value={ambito} onChange={(v) => ir({ ambito: v })} items={data.ambitos.map((a) => ({ value: a.clave, label: a.nombre }))} aria-label="Ámbito" />}
        </div>
      </header>
      <div className="mb-5">
        <MonthNav mes={mes} onChange={(m) => ir({ mes: m })} />
      </div>
      {error && <Notice tone="error">{mensajeError(error, 'No se ha podido cargar el resumen.')}</Notice>}
      {isLoading && <CargandoFin />}
      {data && (
        <>
          <div className="mb-5 grid grid-cols-3 gap-5 max-[900px]:grid-cols-1 max-sm:gap-3">
            <Tarjeta
              tinte="bg-[#f4faf6] dark:bg-[#121c16]"
              icono={<Euro />}
              titulo="Facturado"
              valor={eurC(data.actual.facturado)}
              sub={`Emitido este mes · ${data.actual.n} factura${data.actual.n === 1 ? '' : 's'}`}
              delta={data.delta.facturado}
            />
            <Tarjeta
              tinte="bg-[#fbf5f3] dark:bg-[#1c1514]"
              icono={<FileText />}
              titulo="Para Hacienda"
              valor={eurC(data.actual.impuestos)}
              sub={`IVA ${eurC(data.actual.iva)} · IRPF ${eurC(data.actual.irpf)}`}
            />
            <Tarjeta tinte="bg-[#f3f6fc] dark:bg-[#12161d]" icono={<LayoutGrid />} titulo="Neto (limpio)" valor={eurC(data.actual.neto)} sub="Tras impuestos y gastos" delta={data.delta.neto} />
          </div>

          <div className="mb-5 grid grid-cols-[1.7fr_1fr] gap-5 max-[1100px]:grid-cols-1">
            <ChartCard
              title="Ingresos por mes"
              type="lineaFinanzas"
              height={270}
              action={<span className="text-[12px] text-muted">Últimos 12 meses · base</span>}
              data={{
                labels: data.tendencia.map((t) => `${MESES_CORTOS[Number(t.mes.slice(5, 7)) - 1]} ${t.mes.slice(2, 4)}`),
                series: [{ label: 'Base', data: data.tendencia.map((t) => t.base / 100) }],
              }}
              formatValue={eurk}
            />
            <section className="min-w-0 rounded-2xl border border-line bg-card px-[22px] py-5 max-sm:px-4">
              <header className="mb-3 flex items-center justify-between">
                <h3 className="text-[15px] font-[650] text-ink-strong">Facturas del mes</h3>
                <span className="text-[12.5px] font-semibold text-muted">{data.facturas.length}</span>
              </header>
              {data.facturas.length === 0 && <p className="py-10 text-center text-[13px] text-muted">Sin facturas en {mesLabel(mes)}.</p>}
              <ul className="max-h-[300px] space-y-0.5 overflow-y-auto">
                {data.facturas.map((f) => (
                  <li key={f.id}>
                    <Link to={`/finanzas/facturas/${f.id}`} className="flex items-center gap-2.5 rounded-lg px-2 py-2 hover:bg-soft">
                      <Avatar nombre={f.cliente_nombre || '?'} size={28} />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-[13.5px] font-semibold text-ink-strong">{f.cliente_nombre || '—'}</span>
                        <span className="text-[12px] text-muted">
                          {f.numero} · {f.fecha?.slice(8, 10)}/{f.fecha?.slice(5, 7)}
                        </span>
                      </span>
                      <span className="flex flex-col items-end gap-0.5">
                        <b className="text-[13px] font-semibold text-ink-strong tabular-nums">{eurC(f.total)}</b>
                        <EstadoPill estado={f.estado} size="sm" />
                      </span>
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          </div>

          <section className="max-w-[560px] rounded-2xl border border-line bg-card px-[26px] py-6 max-sm:px-4">
            <h3 className="mb-3 text-[15px] font-[650] text-ink-strong">De lo facturado a lo que te queda</h3>
            <Cascada a="Base imponible" b={eurC(data.actual.base)} />
            <Cascada a="+ IVA repercutido" b={eurC(data.actual.iva)} />
            <Cascada a="− IRPF retenido" b={`−${eurC(data.actual.irpf)}`} />
            <Cascada a="Facturado" b={eurC(data.actual.facturado)} total />
            <Cascada a="− IVA a Hacienda" b={`−${eurC(data.actual.iva)}`} />
            <Cascada a="− Gastos del mes" b={`−${eurC(data.actual.gastos)}`} />
            <Cascada a="Neto estimado" b={eurC(data.actual.neto)} total />
            <p className="mt-4 text-[12px] leading-[1.5] text-muted">
              Estimación. El IVA se cobra pero se ingresa a Hacienda; el IRPF retenido es un adelanto de tu IRPF. Para cifras oficiales, consulta con tu gestoría.
            </p>
          </section>
        </>
      )}
    </div>
  )
}

function Tarjeta({ tinte, icono, titulo, valor, sub, delta }: { tinte: string; icono: ReactNode; titulo: string; valor: string; sub: string; delta?: number | null }) {
  return (
    <div className={`rounded-2xl border border-line px-6 py-[22px] max-sm:px-4 ${tinte}`}>
      <div className="flex items-center gap-3">
        <span className="flex size-[38px] items-center justify-center rounded-[11px] bg-ink-strong text-page [&>svg]:size-[18px]">{icono}</span>
        <span className="text-[13px] font-medium text-ink">{titulo}</span>
        {delta !== undefined && delta !== null && (
          <span className={`ml-auto inline-flex items-center gap-0.5 rounded-full px-2 py-px text-[11.5px] font-semibold ${delta >= 0 ? 'bg-ok-bg text-[#12854a] dark:text-ok' : 'bg-danger-bg text-[#e5484d] dark:text-danger'}`}>
            {delta >= 0 ? <ArrowUpRight className="size-3" /> : <ArrowDownRight className="size-3" />}
            {delta > 0 ? '+' : ''}
            {delta}%
          </span>
        )}
      </div>
      <b className="mt-5 block text-[29px] leading-none font-[750] tracking-[-1px] text-ink-strong tabular-nums max-sm:text-[25px]">{valor}</b>
      <p className="mt-3 text-[12.5px] text-muted">{sub}</p>
    </div>
  )
}

function Cascada({ a, b, total = false }: { a: string; b: string; total?: boolean }) {
  return (
    <div className={`flex items-center justify-between border-line2 px-0.5 py-[13px] tabular-nums ${total ? 'border-t-[1.5px] border-line text-[17px] font-semibold text-ink-strong' : 'border-b text-[14px] text-ink'}`}>
      <span>{a}</span>
      <span className={total ? '' : 'font-semibold text-ink-strong'}>{b}</span>
    </div>
  )
}
