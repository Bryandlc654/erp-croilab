import { useState, type ReactNode } from 'react'
import { Calculator, List, TrendingUp } from 'lucide-react'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import Segmented from '../../../shared/ui/Segmented'
import { TextInput } from '../../../shared/ui/TextInput'
import LineasEditor from '../components/LineasEditor'
import { lineasNormalizadas, nuevaLinea, type LineaForm } from '../lib/cuerpos'
import { eurC, leer } from '../lib/importes'
import { usePermisosFin } from '../lib/permisos'
import { calcularPrecios } from '../lib/precios'

const SERVICIOS = ['SEO', 'SEM', 'CRO', 'Diseño web', 'Tienda online', 'Meta Ads', 'Mantenimiento']

/* Calculadora de precios (pricing.php): arma un presupuesto, calcula el precio
   y comprueba el margen. Solo en el navegador, no guarda nada. */
export default function PreciosPage() {
  const p = usePermisosFin()
  const [modo, setModo] = useState<'puntual' | 'mensual'>('puntual')
  const [lineas, setLineas] = useState<LineaForm[]>(() => [nuevaLinea()])
  const [v, setV] = useState({ horas: '0', costeHora: '15', fijos: '0', descuento: '0', iva: '21', margen: '60' })
  const set = (k: keyof typeof v, x: string) => setV((o) => ({ ...o, [k]: x }))
  const num = (x: string) => leer(x) ?? '0'
  const r = calcularPrecios({
    lineas: lineasNormalizadas(lineas),
    descuentoPct: num(v.descuento),
    ivaPct: num(v.iva),
    horas: num(v.horas),
    costeHora: num(v.costeHora),
    fijos: num(v.fijos),
    margenObjetivo: num(v.margen),
  })
  if (!p.ver) return <Notice tone="error">No tienes acceso a la calculadora.</Notice>
  const sufijo = modo === 'mensual' ? ' / mes' : ''
  const tono = { info: 'text-muted', error: 'text-[#e5484d] dark:text-danger', warn: 'text-[#b7791f] dark:text-warn', ok: 'text-[#12854a] dark:text-ok' }[r.aviso.tono]
  const barra = Math.max(0, Math.min(100, r.margenPct))

  return (
    <div className="max-w-[1180px]">
      <PageHeader title="Calculadora de precios" lead="Arma un presupuesto, calcula el precio y comprueba tu margen antes de enviarlo." />
      <Segmented
        variant="pill"
        className="mb-5"
        value={modo}
        onChange={setModo}
        items={[
          { value: 'puntual', label: 'Proyecto puntual' },
          { value: 'mensual', label: 'Cuota mensual' },
        ]}
        aria-label="Tipo de presupuesto"
      />
      <div className="grid grid-cols-[minmax(0,1fr)_380px] items-start gap-5 max-[1000px]:grid-cols-1">
        <div className="space-y-5">
          <Bloque icono={<List />} titulo="Servicios">
            <LineasEditor lineas={lineas} onChange={setLineas} placeholder="Servicio…" sugerencias={SERVICIOS} />
          </Bloque>
          <Bloque icono={<Calculator />} titulo="Coste interno">
            <Fila etiqueta="Horas estimadas" unidad="h" valor={v.horas} onChange={(x) => set('horas', x)} />
            <Fila etiqueta="Coste por hora" unidad="€" valor={v.costeHora} onChange={(x) => set('costeHora', x)} />
            <Fila etiqueta="Gastos fijos / herramientas" unidad="€" valor={v.fijos} onChange={(x) => set('fijos', x)} />
            <div className="mt-3 flex items-center justify-between border-t border-line2 pt-4">
              <span className="text-[14px] text-muted">Coste total del trabajo</span>
              <b className="text-[17px] font-[750] text-ink-strong tabular-nums">{eurC(r.coste)}</b>
            </div>
          </Bloque>
        </div>
        <aside className="rounded-[20px] border border-line bg-card px-6 py-6 lg:sticky lg:top-3.5 max-sm:px-4">
          <Fila etiqueta="Descuento" unidad="%" valor={v.descuento} onChange={(x) => set('descuento', x)} />
          <Fila etiqueta="IVA" unidad="%" valor={v.iva} onChange={(x) => set('iva', x)} />
          <div className="mt-2 space-y-2 border-t border-line2 pt-3 text-[13px] tabular-nums">
            <Linea a="Subtotal" b={eurC(r.subtotal)} />
            <Linea a="Descuento" b={`−${eurC(r.descuento)}`} />
            <Linea a="Base imponible" b={eurC(r.base)} fuerte />
            <Linea a="IVA" b={eurC(r.iva)} />
          </div>
          <div className="mt-4 border-t border-line pt-4">
            <span className="text-[11.5px] font-bold tracking-[.5px] text-muted uppercase">Total</span>
            <b className="block text-[31px] leading-tight font-[750] tracking-[-.7px] text-ink-strong tabular-nums">
              {eurC(r.total)}
              {sufijo && <span className="text-[16px] font-semibold text-muted">{sufijo}</span>}
            </b>
          </div>
          <div className="mt-4 rounded-2xl bg-soft p-4">
            <div className="flex items-center justify-between">
              <span className="text-[13px] font-semibold text-ink">Beneficio</span>
              <b className={`tabular-nums ${r.beneficio < 0 ? 'text-[#e5484d] dark:text-danger' : 'text-[#12854a] dark:text-ok'}`}>
                {eurC(r.beneficio)} · {r.margenPct}%
              </b>
            </div>
            <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-line">
              <div className={`h-full rounded-full ${r.beneficio < 0 ? 'bg-[#e5484d]' : r.margenPct < 35 ? 'bg-[#e0a000]' : 'bg-[#12a150]'}`} style={{ width: `${barra}%` }} />
            </div>
            <p className={`mt-2.5 text-[12px] ${tono}`}>{r.aviso.texto}</p>
          </div>
          <div className="mt-3 flex items-center gap-3 rounded-2xl bg-soft p-4">
            <TrendingUp className="size-4 shrink-0 text-label" />
            <span className="text-[12.5px] leading-tight text-ink">Con margen del</span>
            <div className="w-[76px]">
              <TextInput value={v.margen} onChange={(e) => set('margen', e.target.value)} inputMode="decimal" unit="%" aria-label="Margen objetivo" size="sm" />
            </div>
            <span className="ml-auto text-right">
              <b className="block text-[15px] font-[750] text-ink-strong tabular-nums">{eurC(r.recomendado)}</b>
              <span className="text-[11px] text-muted">precio (base) recomendado</span>
            </span>
          </div>
        </aside>
      </div>
    </div>
  )
}

function Bloque({ icono, titulo, children }: { icono: ReactNode; titulo: string; children: ReactNode }) {
  return (
    <section className="rounded-[20px] border border-line bg-card px-[26px] py-6 max-sm:px-4">
      <h3 className="mb-4 flex items-center gap-2 text-[11.5px] font-bold tracking-[.5px] text-muted uppercase [&>svg]:size-3.5">
        {icono}
        {titulo}
      </h3>
      {children}
    </section>
  )
}

function Fila({ etiqueta, unidad, valor, onChange }: { etiqueta: string; unidad: string; valor: string; onChange: (v: string) => void }) {
  return (
    <label className="flex items-center justify-between gap-3 py-1.5">
      <span className="text-[14px] text-ink">{etiqueta}</span>
      <span className="w-[130px]">
        <TextInput value={valor} onChange={(e) => onChange(e.target.value)} inputMode="decimal" unit={unidad} className="text-right" />
      </span>
    </label>
  )
}

function Linea({ a, b, fuerte = false }: { a: string; b: string; fuerte?: boolean }) {
  return (
    <div className={`flex justify-between ${fuerte ? 'text-[14px] font-semibold text-ink-strong' : 'text-muted'}`}>
      <span>{a}</span>
      <span>{b}</span>
    </div>
  )
}
