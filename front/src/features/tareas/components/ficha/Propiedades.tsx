import { useState, type ReactNode } from 'react'
import { Calendar, CalendarRange, ChevronDown, CircleDot, Clock, Flag, Tag, UserRound, X } from 'lucide-react'
import Menu, { MenuItem } from '../../../../shared/ui/Menu'
import PersonPicker from '../../../../shared/ui/PersonPicker'
import AvatarStack from '../../../../shared/ui/AvatarStack'
import { DateInput } from '../../../../shared/ui/DatePicker'
import { tonoVencimiento } from '../../../../shared/lib/fechas'
import { ESTADOS_VIVOS, ORDEN_ESTADOS, PRIORIDADES_VIVAS } from '../../../../shared/lib/paletas'
import type { Persona } from '../../../../shared/schemas'
import EstadoCirculo from '../EstadoCirculo'
import TiempoPopover from './TiempoPopover'
import type { CambiosTarea, TareaDetalle } from '../../schemas'

const CAMPO = 'flex min-h-[44px] items-center gap-3 py-[5px] max-sm:items-start max-sm:flex-col max-sm:gap-1 max-sm:py-2'
const ETIQUETA = 'flex w-[120px] shrink-0 items-center gap-2 text-[12.5px] font-medium text-muted max-sm:w-auto [&>svg]:size-[15px] [&>svg]:text-label'
const CONTROL = 'rounded-lg px-[9px] py-1.5 text-[13.5px] transition-colors hover:bg-soft'
const INPUT =
  'w-full min-w-0 rounded-lg border border-transparent bg-transparent px-[9px] py-1.5 text-[13.5px] text-ink placeholder:text-label hover:bg-soft focus:border-label focus:bg-card focus:shadow-[0_0_0_3px_rgba(17,19,24,.07)] focus:outline-none disabled:hover:bg-transparent max-sm:text-[16px]'

function Campo({ icono, label, children }: { icono: ReactNode; label: string; children: ReactNode }) {
  return (
    <div className={CAMPO}>
      <span className={ETIQUETA}>
        {icono} {label}
      </span>
      <div className="flex min-w-0 flex-1 items-center">{children}</div>
    </div>
  )
}

/* Texto libre que se guarda al salir del campo o con Intro (Etiquetas, Mes). */
function TextoCampo({ valor, onGuardar, placeholder, disabled, label, max }: { valor: string; onGuardar: (v: string) => void; placeholder: string; disabled: boolean; label: string; max: number }) {
  const [borrador, setBorrador] = useState<string | null>(null)
  const v = borrador ?? valor
  function guardar() {
    if (borrador !== null && borrador.trim() !== valor) onGuardar(borrador.trim())
    setBorrador(null)
  }
  return (
    <input
      value={v}
      disabled={disabled}
      maxLength={max}
      onChange={(e) => setBorrador(e.target.value)}
      onBlur={guardar}
      onKeyDown={(e) => {
        if (e.key === 'Enter') (e.target as HTMLInputElement).blur()
        if (e.key === 'Escape') setBorrador(null)
      }}
      placeholder={disabled ? '—' : placeholder}
      aria-label={label}
      className={INPUT}
    />
  )
}

/* Pastilla del estado: rellena del color y en mayúsculas; «En espera» neutra. */
export function PildoraEstado({ estado, abierto }: { estado: TareaDetalle['estado']; abierto?: boolean }) {
  const e = ESTADOS_VIVOS[estado]
  const neutra = estado === 'pendiente'
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12px] font-bold tracking-[.2px] uppercase transition-[filter] hover:brightness-[.96] ${
        neutra ? 'border border-[#e6e7ea] bg-[#f1f2f4] text-[#6b7079] dark:border-line dark:bg-soft dark:text-muted' : 'text-white'
      }`}
      style={neutra ? undefined : { backgroundColor: e.color }}
    >
      {e.label}
      <ChevronDown className={`size-3.5 transition-transform ${abierto ? 'rotate-180' : ''}`} aria-hidden="true" />
    </span>
  )
}

/* Rejilla de propiedades de la ficha (.tk-fields): dos columnas en escritorio. */
export default function Propiedades({
  t,
  equipo,
  meId,
  onCambio,
}: {
  t: TareaDetalle
  equipo: Persona[]
  meId: number
  onCambio: (c: CambiosTarea) => void
}) {
  const editar = t.permisos.editar
  const prio = PRIORIDADES_VIVAS.find((p) => p.value === t.prioridad) ?? PRIORIDADES_VIVAS[0]
  const completada = t.estado === 'completada'
  const tono = (iso: string) => (completada ? null : tonoVencimiento(iso))

  return (
    <div className="mb-[26px] grid grid-cols-1 gap-x-11 border-y border-line py-[14px] md:grid-cols-2">
      <Campo icono={<CircleDot />} label="Estado">
        <Menu label="Estado" disabled={!editar} trigger={(ab) => <PildoraEstado estado={t.estado} abierto={ab} />}>
          {(cerrar) =>
            ORDEN_ESTADOS.map((e) => (
              <MenuItem
                key={e}
                on={t.estado === e}
                onClick={() => {
                  cerrar()
                  if (e !== t.estado) onCambio({ estado: e })
                }}
              >
                <EstadoCirculo estado={e} size={14} />
                <span className="flex-1">{ESTADOS_VIVOS[e].label}</span>
              </MenuItem>
            ))
          }
        </Menu>
      </Campo>

      <Campo icono={<UserRound />} label="Asignados">
        <PersonPicker
          multiple
          label="Asignados"
          people={equipo}
          meId={meId}
          disabled={!editar}
          value={t.asignados.map((a) => a.id)}
          onChange={(ids) => onCambio({ asignados: ids })}
          trigger={(elegidos) =>
            elegidos.length > 0 ? (
              <span className="flex min-w-0 items-center gap-2 px-1">
                <AvatarStack people={elegidos} size={24} overlap={9} />
                <span className="truncate text-[13.5px] text-ink">{elegidos.length === 1 ? elegidos[0].username : `${elegidos.length} asignados`}</span>
              </span>
            ) : (
              <span className="flex items-center gap-1.5 px-1 text-[13px] text-label">
                <span className="flex size-6 items-center justify-center rounded-full border-[1.5px] border-dashed border-[#c4c8ce] text-[12px]">＋</span>
                {editar ? 'Asignar' : 'Sin asignar'}
              </span>
            )
          }
        />
      </Campo>

      <Campo icono={<CalendarRange />} label="Fechas">
        <div className="flex min-w-0 flex-wrap items-center gap-1">
          <span className="flex items-center gap-1 rounded-[9px] pl-1.5 text-label">
            <Calendar className="size-3.5 shrink-0" aria-hidden="true" />
            <DateInput variant="inline" size="sm" value={t.fecha_inicio} onChange={(v) => onCambio({ fecha_inicio: v })} disabled={!editar} placeholder="Inicio" aria-label="Fecha de inicio" className="w-[86px]" />
          </span>
          <span className="text-[#c4c8ce]" aria-hidden="true">
            →
          </span>
          <span className="flex items-center gap-1 rounded-[9px] pl-1.5 text-label">
            <Flag className="size-3.5 shrink-0" aria-hidden="true" />
            <DateInput variant="inline" size="sm" value={t.due_date} onChange={(v) => onCambio({ due_date: v })} disabled={!editar} placeholder="Límite" tone={tono} aria-label="Fecha límite" className="w-[86px]" />
          </span>
        </div>
      </Campo>

      <Campo icono={<Clock />} label="Tiempo">
        <TiempoPopover t={t} meId={meId} equipo={equipo} puede={t.permisos.horas} />
      </Campo>

      <Campo icono={<Flag />} label="Prioridad">
        <Menu
          label="Prioridad"
          disabled={!editar}
          trigger={() => (
            <span className={`${CONTROL} inline-flex items-center gap-2 font-semibold`} style={prio.value ? { color: prio.color } : undefined}>
              {prio.value === 0 ? (
                <span className="font-normal text-label">Sin prioridad</span>
              ) : (
                <>
                  <span className="size-[11px] rounded-full" style={{ backgroundColor: prio.color }} />
                  {prio.label}
                </>
              )}
            </span>
          )}
        >
          {(cerrar) => (
            <>
              {PRIORIDADES_VIVAS.map((p) => (
                <MenuItem
                  key={p.value}
                  on={prio.value === p.value}
                  onClick={() => {
                    cerrar()
                    if (p.value !== prio.value) onCambio({ prioridad: p.value })
                  }}
                >
                  <span className="size-[11px] shrink-0 rounded-full" style={{ backgroundColor: p.color }} />
                  <span className="flex-1">{p.value === 0 ? 'Sin prioridad' : p.label}</span>
                </MenuItem>
              ))}
            </>
          )}
        </Menu>
      </Campo>

      <Campo icono={<Tag />} label="Etiquetas">
        <TextoCampo valor={t.etiquetas} onGuardar={(v) => onCambio({ etiquetas: v })} placeholder="Sin etiquetas" disabled={!editar} label="Etiquetas" max={255} />
      </Campo>

      <Campo icono={<Calendar />} label="Mes (informe)">
        <TextoCampo valor={t.mes} onGuardar={(v) => onCambio({ mes: v })} placeholder="Ej: Octubre 2026" disabled={!editar} label="Mes del informe" max={40} />
      </Campo>
    </div>
  )
}

/* Lo que ve el cliente en su portal (visible_cliente, título y explicación).
   El antiguo lo guardaba pero no tenía dónde editarlo. */
export function VisibilidadCliente({ t, onCambio }: { t: TareaDetalle; onCambio: (c: CambiosTarea) => void }) {
  const editar = t.permisos.editar
  const enLista = t.list_tipo === 'informe'
  return (
    <section className="mt-[30px]">
      <h3 className="mb-[13px] text-[11px] font-[650] tracking-[.6px] text-muted uppercase">En el portal del cliente</h3>
      <label className={`flex items-start gap-3 rounded-[13px] border px-[17px] py-[13px] transition-colors ${t.visible_cliente ? 'border-[#d6d7db] bg-accent-soft dark:border-line-strong' : 'border-line bg-page hover:bg-soft'}`}>
        <input
          type="checkbox"
          checked={t.visible_cliente}
          disabled={!editar}
          onChange={(e) => onCambio({ visible_cliente: e.target.checked })}
          className="mt-0.5 size-[15px] shrink-0 accent-[var(--c-accent)]"
        />
        <span className="min-w-0">
          <b className="block text-[13.5px] font-semibold text-ink-strong">La ve el cliente en su Progreso</b>
          <span className="block text-[12.5px] text-muted">
            {enLista ? 'Las entradas de la lista de informes ya salen en Portal › Informes.' : 'Con su título y explicación, si los pones; si no, con el título de la tarea.'}
          </span>
        </span>
      </label>
      {t.visible_cliente && (
        <div className="mt-3 grid gap-2">
          <TextoCampo valor={t.titulo_cliente} onGuardar={(v) => onCambio({ titulo_cliente: v })} placeholder="Título para el cliente (opcional)" disabled={!editar} label="Título para el cliente" max={255} />
          <ExplicacionCliente valor={t.explicacion_cliente} disabled={!editar} onGuardar={(v) => onCambio({ explicacion_cliente: v })} />
        </div>
      )}
    </section>
  )
}

function ExplicacionCliente({ valor, disabled, onGuardar }: { valor: string; disabled: boolean; onGuardar: (v: string) => void }) {
  const [borrador, setBorrador] = useState<string | null>(null)
  return (
    <div className="relative">
      <textarea
        value={borrador ?? valor}
        disabled={disabled}
        onChange={(e) => setBorrador(e.target.value)}
        onBlur={() => {
          if (borrador !== null && borrador.trim() !== valor) onGuardar(borrador.trim())
          setBorrador(null)
        }}
        rows={3}
        placeholder="Explicación para el cliente (opcional): qué se ha hecho y por qué."
        aria-label="Explicación para el cliente"
        className={`${INPUT} resize-y leading-[1.55]`}
      />
      {borrador !== null && (
        <button type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => setBorrador(null)} className="absolute top-1.5 right-1.5 rounded p-0.5 text-label hover:text-ink" aria-label="Descartar">
          <X className="size-3.5" />
        </button>
      )}
    </div>
  )
}
