import type { ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import Notice from '../../../shared/ui/Notice'
import { ChartCard, ChartGrid } from '../../../shared/ui/rich'
import { eurk } from '../../../shared/lib/formato'
import { mensajeError, useAnalisis } from '../api'
import CargandoFin from '../components/Cargando'
import ContaCabecera, { KpiConta } from '../components/ContaCabecera'
import { eurC } from '../lib/importes'
import { ROJO, VERDE } from '../lib/estados'
import { usePermisosFin } from '../lib/permisos'

const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']
const TRIMESTRES = ['1T · Ene-Mar', '2T · Abr-Jun', '3T · Jul-Sep', '4T · Oct-Dic']

/* Contabilidad › Análisis (contabilidad-analisis.php): gráficas, trimestres,
   categorías y deducible de socios. Margen sobre ingresos BRUTOS (aquí) y
   sobre netos en Movimientos: se rotula para que no se confundan (§17.16). */
export default function AnalisisPage() {
  const p = usePermisosFin()
  const [sp, setSp] = useSearchParams()
  const ambito = sp.get('ambito') || 'empresa'
  const anio = Number(sp.get('anio')) || new Date().getFullYear()
  const { data, error, isLoading } = useAnalisis(ambito, anio)
  const ir = (c: Record<string, string>) => setSp((x) => ({ ...Object.fromEntries(x), ...c }), { replace: true })
  if (!p.conta) return <Notice tone="error">No tienes acceso a la contabilidad.</Notice>
  const hub = ambito === 'empresa'
  const nombre = data?.ambitos.find((a) => a.clave === ambito)?.nombre ?? ambito
  const maxCat = Math.max(1, ...(data?.categorias.map((c) => c.total) ?? [1]))

  return (
    <div>
      <ContaCabecera
        pagina="analisis"
        ambito={ambito}
        anio={anio}
        ambitos={data?.ambitos ?? [{ clave: 'empresa', nombre: 'Hub' }]}
        anios={data?.anios ?? [anio]}
        onAmbito={(a) => ir({ ambito: a })}
        onAnio={(a) => ir({ anio: String(a) })}
      />
      {error && <Notice tone="error">{mensajeError(error, 'No se ha podido cargar el análisis.')}</Notice>}
      {isLoading && <CargandoFin />}
      {data && (
        <>
          <div className="mb-5 grid grid-cols-4 gap-5 max-[900px]:grid-cols-2 max-sm:gap-3 max-[420px]:grid-cols-1">
            <KpiConta label={hub ? 'Ingresos (empresa)' : 'Ingresos'} value={eurC(data.kpis.ing)} tono="ok" sub={`${eurC(data.kpis.ing_legal)} legal · ${eurC(data.kpis.ing_efectivo)} efectivo`} />
            <KpiConta label="Gastos" value={eurC(data.kpis.gas)} tono="mal" sub={hub ? 'sin gastos personales' : `${eurC(data.kpis.ded)} deducibles`} />
            <KpiConta label="Beneficio (bruto)" value={eurC(data.kpis.benef)} tono={data.kpis.benef >= 0 ? 'ok' : 'mal'} sub={`${data.kpis.margen}% margen sobre ingresos brutos`} />
            {hub ? (
              <KpiConta label="Deducible socios" value={eurC(data.deducible_socios.reduce((s, x) => s + x.total, 0))} sub={data.deducible_socios.map((d) => `${d.nombre} ${eurC(d.total)}`).join(' · ') || '—'} />
            ) : (
              <KpiConta label="Gastos deducibles" value={eurC(data.kpis.ded)} sub={`que ${nombre} se desgrava`} />
            )}
          </div>

          <ChartGrid>
            <ChartCard
              title="Ingresos vs Gastos por mes"
              type="bar"
              wide
              height={270}
              formatValue={eurk}
              data={{
                labels: MESES,
                series: [
                  { label: 'Ingresos', data: data.por_mes.map((m) => m.ing / 100), color: VERDE + 'cc' },
                  { label: 'Gastos', data: data.por_mes.map((m) => m.gas / 100), color: ROJO + 'cc' },
                ],
              }}
              empty={data.kpis.ing === 0 && data.kpis.gas === 0}
            />
            {data.categorias.length >= 2 ? (
              <ChartCard
                title="Gastos por categoría"
                type="doughnut"
                formatValue={(n) => eurC(Math.round(n * 100))}
                data={{ labels: data.categorias.map((c) => c.categoria), series: [{ label: 'Gastos', data: data.categorias.map((c) => c.total / 100) }] }}
              />
            ) : (
              <section className="rounded-2xl border border-line bg-card px-[22px] py-5">
                <h3 className="mb-3 text-[15px] font-[650] text-ink-strong">Gastos por categoría</h3>
                <p className="py-10 text-center text-[13px] leading-[1.6] text-muted">
                  {data.categorias.length === 0 ? (
                    'Aún no hay gastos registrados.'
                  ) : (
                    <>
                      Todos los gastos están en una sola categoría (<b className="text-ink">{data.categorias[0].categoria}</b>). Cuando registres gastos en categorías distintas, aquí verás el reparto.
                    </>
                  )}
                </p>
              </section>
            )}
            <Bloque titulo="Resumen por trimestres" doble>
              <table className="w-full text-[13px] tabular-nums">
                <thead className="text-left text-[10.5px] font-bold tracking-[.5px] text-muted uppercase">
                  <tr>
                    <th className="py-2">Trimestre</th>
                    <th className="py-2 text-right">Ingresos</th>
                    <th className="py-2 text-right">Gastos</th>
                    <th className="py-2 text-right">Beneficio</th>
                    <th className="py-2 text-right">Margen</th>
                  </tr>
                </thead>
                <tbody>
                  {data.trimestres.map((t, i) => (
                    <tr key={i} className="border-t border-line2">
                      <td className="py-2.5 text-ink">{TRIMESTRES[i]}</td>
                      <td className="py-2.5 text-right">{eurC(t.ing)}</td>
                      <td className="py-2.5 text-right">{eurC(t.gas)}</td>
                      <td className={`py-2.5 text-right font-semibold ${t.ben >= 0 ? 'text-[#12854a] dark:text-ok' : 'text-[#e5484d] dark:text-danger'}`}>{eurC(t.ben)}</td>
                      <td className="py-2.5 text-right text-muted">{t.margen}%</td>
                    </tr>
                  ))}
                  <tr className="border-t-[1.5px] border-line font-semibold text-ink-strong">
                    <td className="py-2.5">Total {anio}</td>
                    <td className="py-2.5 text-right">{eurC(data.kpis.ing)}</td>
                    <td className="py-2.5 text-right">{eurC(data.kpis.gas)}</td>
                    <td className="py-2.5 text-right">{eurC(data.kpis.benef)}</td>
                    <td className="py-2.5 text-right">{data.kpis.margen}%</td>
                  </tr>
                </tbody>
              </table>
            </Bloque>
            <Bloque titulo="Desglose de gastos por categoría">
              {data.categorias.length === 0 && <p className="py-6 text-center text-[13px] text-muted">Sin gastos este año.</p>}
              <ul className="space-y-3">
                {data.categorias.map((c) => (
                  <li key={c.categoria}>
                    <div className="flex justify-between text-[13px]">
                      <span className="text-ink">{c.categoria}</span>
                      <b className="font-semibold text-ink-strong tabular-nums">{eurC(c.total)}</b>
                    </div>
                    <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-soft">
                      <div className="h-full rounded-full bg-[#e05a4f]" style={{ width: `${Math.round((c.total / maxCat) * 100)}%` }} />
                    </div>
                  </li>
                ))}
              </ul>
            </Bloque>
            <Bloque titulo="Legal vs Efectivo (ingresos)">
              <Linea a="Declarado / legal" b={eurC(data.legal_vs_efectivo.legal)} />
              <Linea a="Efectivo" b={eurC(data.legal_vs_efectivo.efectivo)} />
              <Linea a="Ratio declarado" b={`${data.legal_vs_efectivo.ratio}%`} fuerte />
            </Bloque>
            <Bloque titulo="Deducible de socios (año)">
              {data.deducible_socios.map((d) => (
                <Linea key={d.ambito} a={d.nombre} b={eurC(d.total)} />
              ))}
              <Linea a="Total deducible" b={eurC(data.deducible_socios.reduce((s, x) => s + x.total, 0))} fuerte />
              <p className="mt-3 text-[12px] text-muted">Gastos personales que cada socio se desgrava (fuera de empresa).</p>
            </Bloque>
          </ChartGrid>
        </>
      )}
    </div>
  )
}

function Bloque({ titulo, children, doble = false }: { titulo: string; children: ReactNode; doble?: boolean }) {
  return (
    <section className={`min-w-0 rounded-2xl border border-line bg-card px-[22px] py-5 max-sm:px-4 ${doble ? 'min-[700px]:col-span-2' : ''}`}>
      <h3 className="mb-3 text-[15px] font-[650] text-ink-strong">{titulo}</h3>
      {children}
    </section>
  )
}

function Linea({ a, b, fuerte = false }: { a: string; b: string; fuerte?: boolean }) {
  return (
    <div className={`flex justify-between py-2 text-[13.5px] tabular-nums ${fuerte ? 'border-t-[1.5px] border-line font-semibold text-ink-strong' : 'border-b border-line2 text-ink'}`}>
      <span>{a}</span>
      <span>{b}</span>
    </div>
  )
}
