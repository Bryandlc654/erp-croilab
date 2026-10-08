import { useState, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Activity, BarChart3, FileText, Globe, ListChecks, MousePointerClick, Pencil, Search } from 'lucide-react'
import Select from '../../../../shared/ui/Select'
import { usePortal } from '../../contexto'
import { conAnterior, delta, estimarIngresos, global, mesAnterior, mesCorto, miles, nombreMes, pct1, textoDelta, type Delta } from '../../logica'
import type { Metrica } from '../../schemas'
import { bandera, CANALES, pais } from '../../textos'
import { Barras, Donut, GraficaArea, Minilinea } from '../graficas'
import { useCuenta } from '../useCuenta'
import { Tarjeta, Vacio } from '../ui'

const COLOR_WA = '#12a150'
const COLOR_FO = '#8b5cf6'
const COLORES_CANAL = ['var(--p-acc)', '#3b82f6', '#12a150', '#8b5cf6', '#e0a000', '#ef4444', '#14b8a6', '#f97316']

export default function Metricas() {
  const { datos: d, ruta } = usePortal()
  const [params, setParams] = useSearchParams()
  const met = d.metricas
  if (!met.length) {
    return <Vacio icono={<BarChart3 />} titulo="Tus métricas">Aún no hay métricas de tu web. En cuanto conectemos Google y tengamos el primer mes, las verás aquí.</Vacio>
  }
  const claves = met.map((m) => m.clave)
  const pedido = params.get('mes') ?? ''
  const sel = pedido === 'global' || claves.includes(pedido) ? pedido : claves.includes(d.cliente.actual) ? d.cliente.actual : claves[claves.length - 1]
  const esGlobal = sel === 'global'
  const m: Metrica = esGlobal ? global(met) : (met.find((x) => x.clave === sel) as Metrica)
  const prev = esGlobal ? null : (conAnterior(met, sel).anterior ?? null)
  const opciones = [
    { value: 'global', label: 'Global (todos)' },
    ...[...met].reverse().map((x) => ({ value: x.clave, label: x.etiqueta + (x.clave === d.cliente.actual ? ' · este mes' : '') })),
  ]
  const mesTxt = esGlobal ? 'todos los meses' : nombreMes(sel)
  const informe = esGlobal ? null : d.informes.find((i) => i.clave === sel)
  const hayBusqueda = met.some((x) => x.vi > 0 || x.ap > 0)
  const srcs = Object.entries(m.src)
  const geos = Object.entries(m.geo)

  return (
    <div className="grid grid-cols-12 gap-3.5">
      <div className="col-span-12 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-[22px] font-extrabold text-(--p-ink-strong)">Tus métricas</h2>
          <p className="text-[13px] text-(--p-muted)">Tus contactos y tu visibilidad en Google, mes a mes.</p>
        </div>
        <label className="flex items-center gap-2">
          <span className="text-[11px] font-bold tracking-wide text-(--p-muted) uppercase">Mes</span>
          <span className="w-[230px] max-sm:w-[190px]">
            <Select value={sel} onChange={(v) => setParams({ mes: v }, { replace: true })} options={opciones} />
          </span>
        </label>
      </div>
      <div className="col-span-12 flex flex-wrap gap-2">
        <Chip to={ruta('tareas') + (esGlobal ? '' : `?mes=${sel}`)} icono={<ListChecks />}>
          Lo que hicimos{esGlobal ? '' : ` en ${mesTxt}`}
        </Chip>
        {d.secciones.informes && (
          <Chip to={ruta('informes')} icono={<FileText />}>
            {informe ? `Informe de ${mesTxt}` : 'Ver informes'}
          </Chip>
        )}
      </div>

      <Kpi titulo="Oportunidades" icono={<Activity />} valor={m.total} d={esGlobal ? null : delta(m.total, prev?.total ?? null)} global={esGlobal ? met.length : 0} />
      <Kpi titulo="Visitas" icono={<Globe />} valor={m.vi} d={esGlobal ? null : delta(m.vi, prev?.vi ?? null)} global={esGlobal ? met.length : 0} />
      <Kpi titulo="Apariciones" icono={<Search />} valor={m.ap} d={esGlobal ? null : delta(m.ap, prev?.ap ?? null)} global={esGlobal ? met.length : 0} />
      <Kpi titulo="CTR" icono={<MousePointerClick />} valor={m.ctr} ctr d={esGlobal ? null : delta(m.ctr, prev ? prev.ctr : null)} pts={prev ? m.ctr - prev.ctr : null} global={esGlobal ? met.length : 0} />

      <Evolucion met={met} sel={sel} />
      <Tarjeta className="col-span-4 p-6 max-lg:col-span-12">
        <h3 className="text-[15px] font-bold text-(--p-ink-strong)">Tus contactos por canal</h3>
        <p className="text-[12.5px] text-(--p-muted)">De dónde llegan las {miles(m.total)} de {mesTxt}</p>
        <div className="mt-5 flex items-center gap-5 max-sm:flex-col">
          <Donut
            partes={[
              { label: 'Llamadas', valor: m.ll, color: 'var(--p-acc)' },
              { label: 'WhatsApp', valor: m.wa, color: COLOR_WA },
              { label: 'Formularios', valor: m.fo, color: COLOR_FO },
            ]}
          />
          <ul className="flex w-full flex-col gap-2">
            {(
              [
                ['Llamadas', m.ll, 'var(--p-acc)'],
                ['WhatsApp', m.wa, COLOR_WA],
                ['Formularios', m.fo, COLOR_FO],
              ] as const
            ).map(([l, v, c]) => (
              <li key={l} className="flex items-center gap-2 rounded-xl bg-(--p-soft) px-3 py-2 text-[13px]">
                <span className="size-2.5 rounded-full" style={{ background: c }} />
                <span className="flex-1 text-(--p-muted)">{l}</span>
                <b className="text-(--p-ink-strong)">{miles(v)}</b>
              </li>
            ))}
          </ul>
        </div>
      </Tarjeta>

      <Tarjeta className="col-span-7 p-6 max-lg:col-span-12">
        <h3 className="text-[15px] font-bold text-(--p-ink-strong)">Visitas a tu web por mes</h3>
        <p className="text-[12.5px] text-(--p-muted)">Gente que ha entrado a tu web desde Google.</p>
        <Barras puntos={met.slice(-12).map((x) => ({ label: mesCorto(x.clave), titulo: x.etiqueta, valor: x.vi, resaltado: x.clave === sel }))} />
      </Tarjeta>
      <Tarjeta className="col-span-5 p-6 max-lg:col-span-12">
        <h3 className="text-[15px] font-bold text-(--p-ink-strong)">Meses con más contactos</h3>
        <p className="text-[12.5px] text-(--p-muted)">Tus mejores meses.</p>
        <TopMeses met={met} />
      </Tarjeta>

      {hayBusqueda && (
        <>
          <Crecimiento titulo="Crecimiento de clics en Google" met={met} campo="vi" color="var(--p-acc)" />
          <Crecimiento titulo="Crecimiento de apariciones" met={met} campo="ap" color={COLOR_FO} />
        </>
      )}

      {srcs.length > 0 && (
        <Tarjeta className="col-span-6 p-6 max-lg:col-span-12">
          <h3 className="text-[15px] font-bold text-(--p-ink-strong)">¿De dónde viene tu tráfico?</h3>
          <p className="text-[13px] text-(--p-muted)">Cómo ha llegado la gente a tu web en {mesTxt}.</p>
          <Reparto filas={srcs.map(([k, v]) => [CANALES[k] ?? k, v])} colores />
        </Tarjeta>
      )}
      {geos.length > 0 && (
        <Tarjeta className={`p-6 max-lg:col-span-12 ${srcs.length ? 'col-span-6' : 'col-span-12'}`}>
          <h3 className="text-[15px] font-bold text-(--p-ink-strong)">¿Desde dónde te visitan?</h3>
          <p className="text-[13px] text-(--p-muted)">Países desde los que ha entrado la gente a tu web en {mesTxt}.</p>
          <Reparto filas={geos.slice(0, 8).map(([k, v]) => [`${bandera(k)} ${pais(k)}`, v])} total={geos.reduce((s, [, v]) => s + v, 0)} />
        </Tarjeta>
      )}

      <Calculadora key={sel} contactos={m.total} mes={mesTxt} />

      {d.looker && (
        <Tarjeta className="col-span-12 overflow-hidden">
          <iframe title="Panel de Looker Studio" src={d.looker} className="h-[600px] w-full border-0" sandbox="allow-scripts allow-same-origin allow-popups" loading="lazy" />
        </Tarjeta>
      )}
    </div>
  )
}

function Chip({ to, icono, children }: { to: string; icono: ReactNode; children: ReactNode }) {
  return (
    <Link to={to} className="inline-flex items-center gap-2 rounded-full bg-(--p-soft) px-4 py-2 text-[13.5px] font-medium text-(--p-ink-strong) hover:opacity-80 [&_svg]:size-4">
      {icono}
      {children}
    </Link>
  )
}

function claseDelta(d: Delta) {
  return d.tipo === 'baja' ? 'text-(--p-red)' : d.tipo === 'sube' || d.tipo === 'nuevo' ? 'text-(--p-green)' : 'text-(--p-muted)'
}

function Kpi({ titulo, icono, valor, d, ctr = false, pts = null, global: nMeses }: { titulo: string; icono: ReactNode; valor: number; d: Delta | null; ctr?: boolean; pts?: number | null; global: number }) {
  const v = useCuenta(valor)
  let pie: ReactNode
  if (nMeses) pie = <span className="text-(--p-muted)">en {nMeses} meses</span>
  else if (!d) pie = null
  else if (ctr && pts !== null) {
    const r = Math.round(pts * 10) / 10
    pie = <span className={r > 0 ? 'text-(--p-green)' : r < 0 ? 'text-(--p-red)' : 'text-(--p-muted)'}>{r > 0 ? `▲ +${pct1(r)} pts` : r < 0 ? `▼ ${pct1(r)} pts` : 'igual'}</span>
  } else pie = <span className={claseDelta(d)}>{d.tipo === 'partida' ? 'primer dato' : textoDelta(d)}</span>
  return (
    <Tarjeta className="col-span-3 p-5 max-lg:col-span-6">
      <div className="flex items-start justify-between">
        <span className="text-[13px] text-(--p-muted)">{titulo}</span>
        <span className="flex size-8 items-center justify-center rounded-lg bg-(--p-soft) text-(--p-ink-strong) [&_svg]:size-4">{icono}</span>
      </div>
      <p className="mt-3 text-[28px] font-black text-(--p-ink-strong) max-sm:text-[24px]">{ctr ? `${pct1(v)}%` : miles(v)}</p>
      <p className="mt-1 text-[12px] font-bold">{pie}</p>
    </Tarjeta>
  )
}

function Evolucion({ met, sel }: { met: Metrica[]; sel: string }) {
  const [tab, setTab] = useState<'total' | 'vi' | 'ap'>('total')
  const titulos = { total: 'Oportunidades de contacto', vi: 'Visitas desde Google', ap: 'Apariciones en Google' }
  const ult = met.slice(-12)
  return (
    <Tarjeta className="col-span-8 p-6 max-lg:col-span-12">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-[15px] font-bold text-(--p-ink-strong)">{titulos[tab]}</h3>
          <p className="text-[12.5px] text-(--p-muted)">Llamadas, WhatsApp y formularios · el mes elegido se resalta</p>
        </div>
        <div className="flex rounded-full bg-(--p-soft) p-1" role="tablist">
          {(
            [
              ['total', 'Contactos'],
              ['vi', 'Visitas'],
              ['ap', 'Apariciones'],
            ] as const
          ).map(([k, l]) => (
            <button
              key={k}
              type="button"
              role="tab"
              aria-selected={tab === k}
              onClick={() => setTab(k)}
              className={`rounded-full px-3.5 py-1.5 text-[13px] font-semibold ${tab === k ? 'bg-(--p-acc) text-(--p-acc-fg)' : 'text-(--p-muted)'}`}
            >
              {l}
            </button>
          ))}
        </div>
      </div>
      <div className="mt-4">
        <GraficaArea puntos={ult.map((x) => ({ label: mesCorto(x.clave), titulo: x.etiqueta, valor: x[tab], resaltado: x.clave === sel }))} />
      </div>
    </Tarjeta>
  )
}

function TopMeses({ met }: { met: Metrica[] }) {
  const top = [...met].filter((x) => x.total > 0).sort((a, b) => b.total - a.total || b.clave.localeCompare(a.clave)).slice(0, 5)
  if (!top.length) return <p className="mt-4 text-[13.5px] text-(--p-muted)">Aún no hay contactos registrados.</p>
  const max = top[0].total
  return (
    <ul className="mt-4 flex flex-col gap-3.5">
      {top.map((x, i) => (
        <li key={x.clave}>
          <div className="flex justify-between text-[13.5px] font-semibold text-(--p-ink-strong)">
            <span>{x.etiqueta}</span>
            <span>{miles(x.total)}</span>
          </div>
          <div className="mt-1.5 h-2 rounded-full bg-(--p-soft)">
            <div className={`h-2 rounded-full ${i === 0 ? 'bg-(--p-acc)' : 'bg-(--p-muted)'}`} style={{ width: `${(x.total / max) * 100}%` }} />
          </div>
        </li>
      ))}
    </ul>
  )
}

function Crecimiento({ titulo, met, campo, color }: { titulo: string; met: Metrica[]; campo: 'vi' | 'ap'; color: string }) {
  const ult = met[met.length - 1]
  const ant = met.find((x) => x.clave === mesAnterior(ult.clave)) ?? null
  const d = delta(ult[campo], ant ? ant[campo] : null)
  const badge = d.tipo === 'sube' ? `▲ +${d.pct}%` : d.tipo === 'baja' ? `▼ ${d.pct}%` : d.tipo === 'igual' ? '=' : d.tipo === 'nuevo' ? '▲ nuevo' : '—'
  return (
    <Tarjeta className="col-span-6 p-5 max-lg:col-span-12">
      <div className="flex items-start justify-between">
        <span className="text-[13px] text-(--p-muted)">{titulo}</span>
        <span className={`rounded-full px-2.5 py-0.5 text-[12px] font-bold ${d.tipo === 'baja' ? 'bg-[#ef4444]/10 text-(--p-red)' : 'bg-[#12a150]/10 text-(--p-green)'}`}>{badge}</span>
      </div>
      <div className="mt-1 flex items-center gap-4">
        <span className="text-[28px] font-black text-(--p-ink-strong)">{miles(ult[campo])}</span>
        <div className="flex-1">
          <Minilinea valores={met.slice(-12).map((x) => x[campo])} color={color} />
        </div>
      </div>
    </Tarjeta>
  )
}

function Reparto({ filas, colores = false, total }: { filas: [string, number][]; colores?: boolean; total?: number }) {
  const suma = total ?? filas.reduce((s, [, v]) => s + v, 0)
  const max = Math.max(1, ...filas.map(([, v]) => v))
  return (
    <ul className="mt-5 flex flex-col gap-3.5">
      {filas.map(([l, v], i) => (
        <li key={l}>
          <div className="flex justify-between gap-3 text-[13.5px] text-(--p-ink-strong)">
            <span className="truncate">{l}</span>
            <span className="whitespace-nowrap">
              <b>{miles(v)}</b> <span className="text-[11.5px] text-(--p-muted)">{suma ? Math.round((v / suma) * 100) : 0}%</span>
            </span>
          </div>
          {colores && (
            <div className="mt-1.5 h-2 rounded-full bg-(--p-soft)">
              <div className="h-2 rounded-full" style={{ width: `${(v / max) * 100}%`, background: COLORES_CANAL[i % COLORES_CANAL.length] }} />
            </div>
          )}
        </li>
      ))}
    </ul>
  )
}

/* «¿Cuánto pueden suponer tus contactos?»: cuenta rápida, no se guarda nada. */
function Calculadora({ contactos, mes }: { contactos: number; mes: string }) {
  const [n, setN] = useState(String(contactos))
  const [pct, setPct] = useState('5')
  const [ticket, setTicket] = useState('1500')
  const num = (s: string) => Math.max(0, Number(s.replace(',', '.')) || 0)
  const r = estimarIngresos(num(n), num(pct), num(ticket))
  const campo = 'h-11 w-full rounded-xl border border-(--p-line) bg-(--p-card) px-3.5 text-[15px] font-semibold text-(--p-ink-strong) outline-none focus:border-(--p-muted) max-sm:text-[16px]'
  return (
    <Tarjeta className="col-span-12 p-6">
      <h3 className="text-[15px] font-bold text-(--p-ink-strong)">¿Cuánto pueden suponer tus contactos?</h3>
      <p className="text-[12.5px] text-(--p-muted)">Cambia solo estos dos números y te decimos cuánto podrías ingresar. Es una estimación para que te hagas una idea.</p>
      <label className="mt-4 flex items-center gap-3 rounded-2xl border border-(--p-line) px-4 py-3">
        <span className="flex-1">
          <span className="block text-[14px] font-medium text-(--p-ink-strong)">Contactos del mes</span>
          <span className="block text-[12px] text-(--p-muted)">se pone solo según el mes elegido</span>
        </span>
        <span className="relative w-[120px]">
          <input inputMode="numeric" value={n} onChange={(e) => setN(e.target.value)} className={`${campo} pr-8 text-right text-[20px]`} aria-label="Contactos del mes" />
          <Pencil className="pointer-events-none absolute top-1/2 right-3 size-3.5 -translate-y-1/2 text-(--p-muted)" />
        </span>
      </label>
      <div className="mt-3 grid gap-3 md:grid-cols-2">
        <label className="text-[13px] text-(--p-muted)">
          De cada 100, ¿cuántos acaban comprando?
          <span className="relative mt-1.5 block">
            <input inputMode="decimal" value={pct} onChange={(e) => setPct(e.target.value)} className={campo} />
            <span className="absolute top-1/2 right-3.5 -translate-y-1/2 text-(--p-muted)">%</span>
          </span>
        </label>
        <label className="text-[13px] text-(--p-muted)">
          ¿Cuánto te deja de media cada cliente?
          <span className="relative mt-1.5 block">
            <span className="absolute top-1/2 left-3.5 -translate-y-1/2 text-(--p-muted)">€</span>
            <input inputMode="decimal" value={ticket} onChange={(e) => setTicket(e.target.value)} className={`${campo} pl-8`} />
          </span>
        </label>
      </div>
      <div className="mt-3 grid gap-3 md:grid-cols-2">
        <div className="rounded-2xl border border-(--p-line) p-4">
          <p className="text-[12.5px] text-(--p-muted)">Clientes nuevos estimados</p>
          <p className="text-[26px] font-black text-(--p-ink-strong)">{pct1(r.clientes)}</p>
        </div>
        <div className="rounded-2xl border border-(--p-line) p-4">
          <p className="text-[12.5px] text-(--p-muted)">Podrías ingresar · {mes}</p>
          <p className="text-[26px] font-black text-(--p-ink-strong)">{miles(r.ingresos)} €</p>
        </div>
      </div>
      <p className="mt-3 text-[12px] text-(--p-muted)">
        Si cierras el <b>{num(pct)}%</b> de tus <b>{miles(num(n))}</b> oportunidades, podrías ingresar <b>{miles(r.ingresos)} €</b>.
      </p>
    </Tarjeta>
  )
}
