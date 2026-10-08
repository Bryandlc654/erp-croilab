import { useState, type ReactNode } from 'react'
import { Check, Copy, Lock } from 'lucide-react'
import Card from '../../../shared/ui/Card'
import EmptyState from '../../../shared/ui/EmptyState'
import { useToast } from '../../../shared/ui/useToast'

/* Tarjeta de sección de ajustes (.set-card): título 15.5, subtítulo y contenido. */
export function SetCard({ titulo, sub, accion, children, className = '' }: { titulo?: ReactNode; sub?: ReactNode; accion?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <Card padding="md" className={`mb-4 ${className}`}>
      {(titulo || accion) && (
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            {titulo && <h2 className="text-[15.5px] font-semibold text-ink-strong">{titulo}</h2>}
            {sub && <p className="mt-1 max-w-[80ch] text-[12.5px] leading-[1.55] text-muted">{sub}</p>}
          </div>
          {accion && <div className="flex shrink-0 flex-wrap items-center gap-2">{accion}</div>}
        </div>
      )}
      {children}
    </Card>
  )
}

/* Caja con un valor que se copia al pulsar (tokens, enlaces, URI de vuelta). */
export function CopiarCaja({ valor, etiqueta = 'Copiar', mono = true, className = '' }: { valor: string; etiqueta?: string; mono?: boolean; className?: string }) {
  const { aviso } = useToast()
  const [hecho, setHecho] = useState(false)
  async function copiar() {
    try {
      await navigator.clipboard.writeText(valor)
      setHecho(true)
      aviso('Copiado')
      setTimeout(() => setHecho(false), 1100)
    } catch {
      aviso('No se ha podido copiar: selecciónalo y cópialo a mano.', { tipo: 'error' })
    }
  }
  return (
    <div className={`flex min-w-0 items-center gap-2 rounded-[10px] border border-line bg-soft py-1.5 pr-1.5 pl-3 ${className}`}>
      <code className={`min-w-0 flex-1 truncate text-[12.5px] text-ink select-all ${mono ? 'font-mono' : ''}`} title={valor}>
        {valor}
      </code>
      <button
        type="button"
        onClick={copiar}
        className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-line bg-card px-2.5 py-1.5 text-[12px] font-semibold text-ink hover:border-line-strong hover:bg-soft"
      >
        {hecho ? <Check className="size-3.5 text-[#12a150]" aria-hidden="true" /> : <Copy className="size-3.5" aria-hidden="true" />}
        {etiqueta}
      </button>
    </div>
  )
}

export function SinPermiso({ que = 'esta pantalla' }: { que?: string }) {
  return <EmptyState icon={<Lock />} title="No tienes permiso" text={`Tu rol no incluye ${que}. Pídeselo a quien gestiona el equipo.`} />
}

/* Avisos de error al cargar una pantalla. */
export function ErrorCarga({ error }: { error: unknown }) {
  const msg = error instanceof Error ? error.message : 'No se ha podido cargar.'
  return <EmptyState title="No se ha podido cargar" text={msg} />
}

/* Esqueleto mientras carga (bloques grises con pulso, como el resto del front). */
export function Esqueleto({ filas = 3 }: { filas?: number }) {
  return (
    <div className="space-y-3" role="status" aria-label="Cargando">
      {Array.from({ length: filas }, (_, i) => (
        <div key={i} className="h-[72px] animate-pulse rounded-2xl bg-soft" />
      ))}
    </div>
  )
}

/* Pastilla de estado de las integraciones («Activa», «Sin configurar»…). */
export function Insignia({ tono, children }: { tono: 'ok' | 'warn' | 'off'; children: ReactNode }) {
  const c = {
    ok: 'bg-[#e4f6ec] text-[#12854a] dark:bg-ok-bg dark:text-ok',
    warn: 'bg-[#fff4e5] text-[#b7791f] dark:bg-[#2a2210] dark:text-warn',
    off: 'bg-soft text-muted',
  }[tono]
  return <span className={`rounded-md px-2 py-[3px] text-[10px] font-bold tracking-[.4px] uppercase ${c}`}>{children}</span>
}

/* Logos de servicios (svc_logo del antiguo), dibujados aquí para no cargar nada de fuera. */
export function LogoServicio({ k, size = 26 }: { k: 'google' | 'gcal' | 'n8n' | 'claude'; size?: number }) {
  if (k === 'google')
    return (
      <svg width={size} height={size} viewBox="0 0 48 48" aria-hidden="true">
        <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z" />
        <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
        <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z" />
        <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C36.9 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z" />
      </svg>
    )
  if (k === 'gcal')
    return (
      <svg width={size} height={size} viewBox="0 0 48 48" aria-hidden="true">
        <rect x="6" y="6" width="36" height="36" rx="5" fill="#fff" stroke="#e0e0e0" />
        <path fill="#1e88e5" d="M6 14V11a5 5 0 0 1 5-5h26a5 5 0 0 1 5 5v3z" />
        <path fill="#fbc02d" d="M36 42l6-6h-6z" />
        <text x="24" y="36" textAnchor="middle" fontFamily="Arial, sans-serif" fontWeight="700" fontSize="16" fill="#1e88e5">
          31
        </text>
      </svg>
    )
  if (k === 'n8n')
    return (
      <svg width={size} height={size} viewBox="0 0 48 48" aria-hidden="true">
        <g fill="none" stroke="#ea4b71" strokeWidth="3.4" strokeLinecap="round">
          <path d="M11 24h9M28 24c4 0 4-7 8-7M28 24c4 0 4 7 8 7" />
        </g>
        <g fill="#ea4b71">
          <circle cx="8" cy="24" r="4" />
          <circle cx="24" cy="24" r="4" />
          <circle cx="39" cy="17" r="4" />
          <circle cx="39" cy="31" r="4" />
        </g>
      </svg>
    )
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" aria-hidden="true">
      <g stroke="#d97757" strokeWidth="3.6" strokeLinecap="round">
        {Array.from({ length: 12 }, (_, i) => {
          const a = (i * Math.PI) / 6
          return <line key={i} x1={24 + Math.cos(a) * 4} y1={24 + Math.sin(a) * 4} x2={24 + Math.cos(a) * 17} y2={24 + Math.sin(a) * 17} />
        })}
      </g>
    </svg>
  )
}

/* «Sin guardar» junto al botón (.aj-flag). */
export function SinGuardar({ texto = 'Sin guardar' }: { texto?: string }) {
  return (
    <span className="inline-flex animate-fade-in items-center gap-1.5 text-[12px] font-semibold text-[#b7791f] dark:text-warn">
      <span className="size-[7px] rounded-full bg-[#e8a33d]" aria-hidden="true" />
      {texto}
    </span>
  )
}
