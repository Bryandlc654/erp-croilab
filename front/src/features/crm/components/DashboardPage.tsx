import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import Button from '../../../shared/ui/Button'
import DateInput from '../../../shared/ui/DatePicker'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import { ChartCard, ChartGrid, type DatosGrafica, type TipoGrafica } from '../../../shared/ui/rich'
import { eurk } from '../../../shared/lib/formato'
import { mensajeError, useDashboard } from '../api'
import { euros, mesCorto, rangoRapido } from '../logica'
import type { Dashboard } from '../schemas'

const KPI = 'rounded-[14px] border border-line bg-card px-5 py-[18px]'
const PILDORA = 'rounded-full border px-3 py-1.5 text-[12.5px] font-semibold transition-colors'

type Grafica = { clave: string; titulo: string; tipo: TipoGrafica; ancha?: boolean; formato?: (n: number) => string; meses?: boolean }

const GRAFICAS: Grafica[] = [
  { clave: 'embudo', titulo: 'Embudo de venta', tipo: 'barH', ancha: true },
  { clave: 'valor_fase', titulo: 'Valor en pipeline por fase', tipo: 'bar', formato: eurk },
  { clave: 'ganados_perdidos', titulo: 'Ganados vs perdidos por mes', tipo: 'bar', meses: true },
  { clave: 'conversion_mes', titulo: 'Tasa de conversión mensual', tipo: 'line', formato: (n) => `${n}%`, meses: true },
  { clave: 'valor_ganado_mes', titulo: 'Valor ganado por mes', tipo: 'bar', formato: eurk, meses: true },
  { clave: 'contactos_mes', titulo: 'Contactos nuevos por mes', tipo: 'line', meses: true },
  { clave: 'origen', titulo: 'Contactos por origen', tipo: 'doughnut' },
  { clave: 'sector', titulo: 'Contactos por sector', tipo: 'barH' },
  { clave: 'motivos', titulo: 'Motivos de pérdida', tipo: 'doughnut' },
  { clave: 'propietarios', titulo: 'Negocios abiertos por propietario', tipo: 'barH' },
  { clave: 'servicios', titulo: 'Servicios más presupuestados', tipo: 'barH' },
  { clave: 'fase_contactos', titulo: 'Contactos por fase del embudo', tipo: 'doughnut' },
  { clave: 'dias_fase', titulo: 'Días medios en cada fase', tipo: 'bar', formato: (n) => `${n} d` },
]

/* La serie de la API a lo que pinta ChartCard (meses «may», «jun»…). */
function datos(d: Dashboard, g: Grafica): DatosGrafica {
  const s = d.series[g.clave] ?? { labels: [], series: [] }
  const vacia = s.series.every((x) => x.data.every((v) => v === 0))
  return {
    labels: g.meses ? s.labels.map((m) => mesCorto(m)) : s.labels,
    // Sin un solo dato no hay gráfica: «Sin datos todavía».
    series: vacia && !g.meses ? [] : s.series.map((x) => ({ label: x.label, data: x.data, color: x.color ?? undefined })),
  }
}

/* Dashboard del CRM (crm_dashboard.php): rango de fechas con atajos, 7 KPIs
   y 13 gráficas. */
export default function DashboardPage() {
  const [params, setParams] = useSearchParams()
  const desde = params.get('desde') ?? ''
  const hasta = params.get('hasta') ?? ''
  const { data, error, isPending } = useDashboard(desde, hasta)
  const [d1, setD1] = useState<string | null>(desde || null)
  const [d2, setD2] = useState<string | null>(hasta || null)

  function aplicar(a: string | null, b: string | null) {
    setD1(a)
    setD2(b)
    const n = new URLSearchParams()
    if (a) n.set('desde', a)
    if (b) n.set('hasta', b)
    setParams(n, { replace: true })
  }

  const rapidos = (['mes', 'tres', 'anio'] as const).map((k) => ({ k, r: rangoRapido(k), label: { mes: 'Este mes', tres: 'Últimos 3 meses', anio: 'Este año' }[k] }))
  const activo = (r: { desde: string; hasta: string } | null) => (r ? r.desde === desde && r.hasta === hasta : !desde && !hasta)
  const k = data?.kpis
  const periodo = k?.hay_rango ? 'en el periodo' : 'histórico'

  return (
    <div>
      <PageHeader title="Dashboard" />
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-[14px] border border-line bg-card px-[18px] py-3.5">
        <form
          className="flex flex-wrap items-center gap-2.5"
          onSubmit={(e) => {
            e.preventDefault()
            aplicar(d1, d2)
          }}
        >
          <span className="text-[12.5px] font-semibold text-muted">Desde</span>
          <span className="w-[120px]">
            <DateInput size="sm" value={d1} onChange={setD1} aria-label="Desde" />
          </span>
          <span className="text-[12.5px] font-semibold text-muted">Hasta</span>
          <span className="w-[120px]">
            <DateInput size="sm" value={d2} onChange={setD2} aria-label="Hasta" />
          </span>
          <Button type="submit" size="sm">
            Aplicar
          </Button>
        </form>
        <div className="flex flex-wrap gap-1.5">
          {rapidos.map(({ k: clave, r, label }) => (
            <button
              key={clave}
              type="button"
              onClick={() => aplicar(r.desde, r.hasta)}
              className={`${PILDORA} ${activo(r) ? 'border-accent bg-accent text-white dark:text-accent-fg' : 'border-line bg-field text-ink hover:bg-soft'}`}
            >
              {label}
            </button>
          ))}
          <button type="button" onClick={() => aplicar(null, null)} className={`${PILDORA} ${activo(null) ? 'border-accent bg-accent text-white dark:text-accent-fg' : 'border-line bg-field text-ink hover:bg-soft'}`}>
            Todo
          </button>
        </div>
      </div>

      {error && <Notice tone="error">{mensajeError(error, 'No se ha podido cargar el dashboard.')}</Notice>}
      {isPending && <div className="py-[60px] text-center text-[13px] text-muted">Cargando…</div>}
      {k && data && (
        <>
          <div className="mb-5 grid grid-cols-4 gap-3.5 max-[900px]:grid-cols-2 max-[420px]:grid-cols-1">
            {[
              ['Contactos', String(k.contactos), k.hay_rango ? 'en el periodo' : 'total en CRM'],
              ['Negocios abiertos', String(k.abiertos), `${euros(k.valor_pipeline)} en pipeline`],
              ['Ganado', euros(k.ganado), `${k.n_ganados} ganados ${periodo}`],
              ['Tasa conversión', `${k.conversion}%`, `${k.n_ganados} ganados · ${k.n_perdidos} perdidos`],
              ['Ticket medio', euros(k.ticket_medio), 'negocios ganados'],
              ['Negocios perdidos', String(k.n_perdidos), periodo],
              ['Cierres previstos', String(k.cierres_previstos), 'próximos 30 días'],
            ].map(([t, v, s]) => (
              <div key={t} className={KPI}>
                <div className="text-[11px] font-semibold tracking-[.4px] text-muted uppercase">{t}</div>
                <div className="mt-2 text-[22px] leading-tight font-[750] tracking-[-.4px] text-ink-strong tabular-nums">{v}</div>
                <div className="mt-1 text-[12px] text-muted">{s}</div>
              </div>
            ))}
          </div>
          <ChartGrid>
            {GRAFICAS.map((g) => (
              <ChartCard key={g.clave} title={g.titulo} type={g.tipo} data={datos(data, g)} wide={g.ancha} formatValue={g.formato} height={g.ancha ? 270 : 240} />
            ))}
          </ChartGrid>
        </>
      )}
    </div>
  )
}
