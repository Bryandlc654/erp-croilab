import { useId, useState } from 'react'
import { miles } from '../logica'

/* Gráficas del portal en SVG (como las del antiguo, que también eran SVG a
   mano): área suavizada, donut, barras y minilíneas. Los colores salen de las
   variables del portal, así cambian solas con el modo oscuro. */

export type Punto = { label: string; valor: number; resaltado?: boolean; titulo?: string }

/* Curva suave que pasa por todos los puntos (Catmull-Rom → Bézier). */
function curva(p: [number, number][]) {
  if (!p.length) return ''
  let d = `M${p[0][0]},${p[0][1]}`
  for (let i = 0; i < p.length - 1; i++) {
    const p0 = p[i - 1] ?? p[i]
    const p1 = p[i]
    const p2 = p[i + 1]
    const p3 = p[i + 2] ?? p2
    const c1 = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6]
    const c2 = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6]
    d += ` C${c1[0]},${c1[1]} ${c2[0]},${c2[1]} ${p2[0]},${p2[1]}`
  }
  return d
}

export function GraficaArea({ puntos, alto = 210, color = 'var(--p-acc)' }: { puntos: Punto[]; alto?: number; color?: string }) {
  const id = useId()
  const [hover, setHover] = useState<number | null>(null)
  const W = 600
  const H = alto
  const pad = { t: 16, b: 28, l: 12, r: 12 }
  const max = Math.max(1, ...puntos.map((p) => p.valor))
  const paso = puntos.length > 1 ? (W - pad.l - pad.r) / (puntos.length - 1) : 0
  const xy: [number, number][] = puntos.map((p, i) => [pad.l + (puntos.length > 1 ? i * paso : (W - pad.l - pad.r) / 2), pad.t + (1 - p.valor / max) * (H - pad.t - pad.b)])
  const linea = curva(xy)
  const area = xy.length ? `${linea} L${xy[xy.length - 1][0]},${H - pad.b} L${xy[0][0]},${H - pad.b} Z` : ''
  return (
    <div className="relative">
      <svg viewBox={`0 0 ${W} ${H}`} className="block h-auto w-full overflow-visible" role="img" aria-label={puntos.map((p) => `${p.label}: ${miles(p.valor)}`).join(', ')}>
        <defs>
          <linearGradient id={`g${id}`} x1="0" x2="0" y1="0" y2="1">
            <stop offset="0" stopColor={color} stopOpacity="0.16" />
            <stop offset="1" stopColor={color} stopOpacity="0" />
          </linearGradient>
        </defs>
        {area && <path d={area} fill={`url(#g${id})`} />}
        {linea && <path d={linea} fill="none" stroke={color} strokeWidth="2.4" strokeLinecap="round" />}
        {hover !== null && xy[hover] && <line x1={xy[hover][0]} x2={xy[hover][0]} y1={pad.t} y2={H - pad.b} stroke="var(--p-line)" strokeDasharray="3 3" />}
        {xy.map(([x, y], i) => (
          <g key={i}>
            <circle cx={x} cy={y} r={puntos[i].resaltado || hover === i ? 6 : 3.5} fill="var(--p-card)" stroke={color} strokeWidth={puntos[i].resaltado ? 3 : 2} />
            <text x={x} y={H - 6} textAnchor="middle" fontSize="12" fill="var(--p-muted)" fontWeight={puntos[i].resaltado ? 700 : 400}>
              {puntos[i].label}
            </text>
            <rect x={x - Math.max(paso, 40) / 2} y={0} width={Math.max(paso, 40)} height={H} fill="transparent" onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} />
          </g>
        ))}
      </svg>
      {hover !== null && xy[hover] && (
        <div
          className="pointer-events-none absolute -translate-x-1/2 -translate-y-full rounded-lg bg-(--p-dark) px-2.5 py-1.5 text-[12px] font-semibold whitespace-nowrap text-white shadow-lg"
          style={{ left: `${(xy[hover][0] / W) * 100}%`, top: `${(xy[hover][1] / H) * 100}%`, marginTop: -10 }}
        >
          {puntos[hover].titulo ?? puntos[hover].label}: {miles(puntos[hover].valor)}
        </div>
      )}
    </div>
  )
}

export type Parte = { label: string; valor: number; color: string }

export function Donut({ partes, size = 132, centro }: { partes: Parte[]; size?: number; centro?: string }) {
  const [hover, setHover] = useState<number | null>(null)
  const total = partes.reduce((s, p) => s + p.valor, 0)
  const r = 52
  const C = 2 * Math.PI * r
  let acum = 0
  const h = hover !== null ? partes[hover] : null
  return (
    <div className="relative shrink-0" style={{ width: size, height: size }}>
      <svg viewBox="0 0 132 132" className="size-full -rotate-90">
        <circle cx="66" cy="66" r={r} fill="none" stroke="var(--p-soft)" strokeWidth="14" />
        {total > 0 &&
          partes.map((p, i) => {
            const largo = (p.valor / total) * C
            const el = (
              <circle
                key={p.label}
                cx="66"
                cy="66"
                r={r}
                fill="none"
                stroke={p.color}
                strokeWidth={hover === i ? 17 : 14}
                strokeDasharray={`${largo} ${C - largo}`}
                strokeDashoffset={-acum}
                onMouseEnter={() => setHover(i)}
                onMouseLeave={() => setHover(null)}
              />
            )
            acum += largo
            return el
          })}
      </svg>
      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
        <span className="text-[10.5px] text-(--p-muted)">{h ? h.label : 'Total'}</span>
        <span className="text-[22px] leading-none font-extrabold text-(--p-ink-strong)">{h ? miles(h.valor) : (centro ?? miles(total))}</span>
        {h && total > 0 && <span className="text-[10.5px] text-(--p-muted)">{Math.round((h.valor / total) * 100)}%</span>}
      </div>
    </div>
  )
}

export function Barras({ puntos, alto = 170 }: { puntos: Punto[]; alto?: number }) {
  const max = Math.max(1, ...puntos.map((p) => p.valor))
  return (
    <div className="flex items-end justify-around gap-2 overflow-x-auto pt-2" style={{ height: alto + 48 }}>
      {puntos.map((p) => (
        <div key={p.label} className="flex min-w-[34px] flex-1 flex-col items-center gap-1.5">
          <div className="flex w-full max-w-[44px] items-end" style={{ height: alto }}>
            <div
              title={`${p.titulo ?? p.label}: ${miles(p.valor)}`}
              className={`w-full rounded-[12px] transition-[height] duration-700 ${p.resaltado ? 'bg-(--p-acc)' : 'bg-(--p-soft)'}`}
              style={{ height: `${Math.max(4, (p.valor / max) * 100)}%` }}
            />
          </div>
          <span className="text-[12px] font-bold text-(--p-ink-strong)">{miles(p.valor)}</span>
          <span className="text-[11.5px] text-(--p-muted)">{p.label}</span>
        </div>
      ))}
    </div>
  )
}

export function Minilinea({ valores, color = 'var(--p-acc)' }: { valores: number[]; color?: string }) {
  const W = 300
  const H = 48
  const max = Math.max(1, ...valores)
  const min = Math.min(...valores, max)
  const rango = max - min || 1
  const xy: [number, number][] = valores.map((v, i) => [valores.length > 1 ? (i / (valores.length - 1)) * W : W / 2, 6 + (1 - (v - min) / rango) * (H - 12)])
  const ult = xy[xy.length - 1]
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="block h-12 w-full overflow-visible" aria-hidden="true">
      <path d={curva(xy)} fill="none" stroke={color} strokeWidth="2" />
      {ult && <circle cx={ult[0]} cy={ult[1]} r="4.5" fill="var(--p-card)" stroke={color} strokeWidth="2.5" />}
    </svg>
  )
}
