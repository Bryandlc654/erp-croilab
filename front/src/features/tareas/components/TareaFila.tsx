import type { MouseEvent } from 'react'
import { Check, Eye, X } from 'lucide-react'
import AvatarStack from '../../../shared/ui/AvatarStack'
import Menu, { MenuItem } from '../../../shared/ui/Menu'
import PersonPicker from '../../../shared/ui/PersonPicker'
import { RowGrip } from '../../../shared/ui/SortableList'
import { DateInput } from '../../../shared/ui/DatePicker'
import { tonoVencimiento } from '../../../shared/lib/fechas'
import type { useSortable } from '../../../shared/lib/useSortable'
import type { Persona } from '../../../shared/schemas'
import EstadoCirculo from './EstadoCirculo'
import { ESTADOS, ORDEN_ESTADOS, PRIORIDADES } from '../constantes'
import type { CampoEnLinea, Estado, Tarea } from '../schemas'

/* Columnas del tablero antiguo: nombre · persona asignada · fecha límite · prioridad · acciones.
   En móvil no caben: el nombre ocupa su línea y el resto se reparte debajo. */
export const COLUMNAS =
  'flex flex-wrap items-center gap-x-3 gap-y-2 md:grid md:grid-cols-[minmax(0,1fr)_168px_132px_118px_84px] md:gap-2.5'

type Props = {
  t: Tarea
  equipo: Persona[]
  puedeEditar: boolean
  puedeBorrar: boolean
  mostrarLista: boolean
  /* El valor llega como texto (como en el formulario): '' = quitar; asignados = ids separados por comas. */
  onCampo: (t: Tarea, campo: CampoEnLinea, valor: string) => void
  /* Abrir la ficha completa (clic en la fila). */
  onAbrir: (t: Tarea) => void
  onBorrar: (t: Tarea) => void
  /* Cajón de vista rápida (lupa). */
  onVistaRapida?: (t: Tarea) => void
  /* Menú contextual (clic derecho): Abrir, Marcar completada, Borrar. */
  onMenu?: (t: Tarea, x: number, y: number) => void
  /* Asa para reordenar (vista de cliente). */
  asa?: ReturnType<ReturnType<typeof useSortable>['handleProps']>
}

export default function TareaFila({ t, equipo, puedeEditar, puedeBorrar, mostrarLista, onCampo, onAbrir, onBorrar, onVistaRapida, onMenu, asa }: Props) {
  const prio = PRIORIDADES.find((p) => p.value === t.prioridad) ?? PRIORIDADES[0]
  const completada = t.estado === 'completada'
  const parar = (e: MouseEvent) => e.stopPropagation()

  return (
    <div
      role="row"
      onClick={() => onAbrir(t)}
      onContextMenu={
        onMenu
          ? (e) => {
              e.preventDefault()
              onMenu(t, e.clientX, e.clientY)
            }
          : undefined
      }
      className={`${COLUMNAS} cursor-pointer border-b border-line px-4 py-3 transition-colors last:border-b-0 hover:bg-[#fafbfc] dark:hover:bg-white/[.03] ${asa ? 'md:pl-1.5' : ''}`}
    >
      {/* Nombre, con el círculo de estado a la izquierda */}
      <div className="flex min-w-0 basis-full items-center gap-3 md:basis-auto">
        {asa && <RowGrip {...asa} onClick={parar} className="-mr-1.5 cursor-grab max-md:hidden" aria-label={`Mover ${t.titulo}`} />}
        <Menu
          label={`Estado: ${ESTADOS[t.estado]?.label ?? t.estado}`}
          disabled={!puedeEditar}
          trigger={() => (
            <span className="flex size-6 items-center justify-center rounded-md hover:bg-soft">
              <EstadoCirculo estado={t.estado} />
            </span>
          )}
        >
          {(cerrar) =>
            ORDEN_ESTADOS.map((e: Estado) => (
              <MenuItem
                key={e}
                on={t.estado === e}
                onClick={() => {
                  cerrar()
                  if (e !== t.estado) onCampo(t, 'estado', e)
                }}
              >
                <EstadoCirculo estado={e} size={14} />
                <span className="flex-1">{ESTADOS[e].label}</span>
                {t.estado === e && <Check className="size-3.5" />}
              </MenuItem>
            ))
          }
        </Menu>
        <span className={`min-w-0 truncate text-[13.5px] font-semibold ${completada ? 'text-muted line-through decoration-[1.5px]' : 'text-ink-strong'}`}>{t.titulo}</span>
        {completada && <span className="shrink-0 rounded-md bg-[#e4f6ec] px-[7px] py-px text-[11px] font-semibold text-[#12854a] dark:bg-[#12854a]/20">Completada</span>}
        {t.visible_cliente && <span className="shrink-0 rounded-[5px] bg-soft px-1.5 py-0.5 text-[9.5px] font-semibold tracking-[.2px] text-muted uppercase">cliente</span>}
        {mostrarLista && t.list_name && <span className="shrink-0 rounded-md bg-soft px-[7px] py-px text-[11px] font-semibold whitespace-nowrap text-label">{t.list_name}</span>}
      </div>

      {/* Persona(s) asignada(s): varios, como el antiguo (set_asignados). */}
      <div onClick={parar} className="min-w-0">
        <PersonPicker
          multiple
          label="Persona asignada"
          people={equipo}
          disabled={!puedeEditar}
          value={t.asignados.map((a) => a.id)}
          onChange={(ids) => onCampo(t, 'asignados', ids.join(','))}
          trigger={(elegidos) =>
            elegidos.length > 0 || t.asignados.length > 0 ? (
              <span className="flex min-w-0 items-center gap-2">
                <AvatarStack people={t.asignados} size={24} overlap={9} />
                <span className="truncate text-[13px] text-ink">{t.asignados.length === 1 ? t.asignados[0].username : `${t.asignados.length} asignados`}</span>
              </span>
            ) : (
              <span className="flex items-center gap-1.5 text-[12.5px] text-label">
                <span className="flex size-6 items-center justify-center rounded-full bg-[#eef0f2] text-[12px] dark:bg-soft">＋</span> Asignar
              </span>
            )
          }
        />
      </div>

      {/* Fecha límite */}
      <div onClick={parar}>
        <DateInput
          variant="inline"
          size="sm"
          aria-label="Fecha límite"
          disabled={!puedeEditar}
          value={t.due_date}
          placeholder="—"
          tone={(iso) => (completada ? null : tonoVencimiento(iso))}
          onChange={(v) => onCampo(t, 'due_date', v ?? '')}
          className="w-full"
        />
      </div>

      {/* Prioridad */}
      <Menu
        label={`Prioridad: ${prio.label}`}
        disabled={!puedeEditar}
        trigger={() => (
          <span className="flex items-center gap-1.5 rounded-[7px] px-1.5 py-1 text-[12.5px] font-semibold text-ink hover:bg-soft">
            {prio.value === 0 ? (
              <span className="text-label">＋</span>
            ) : (
              <>
                <span className="size-[9px] shrink-0 rounded-[2px]" style={{ backgroundColor: prio.color }} />
                {prio.label}
              </>
            )}
          </span>
        )}
      >
        {(cerrar) =>
          PRIORIDADES.map((p) => (
            <MenuItem
              key={p.value}
              on={prio.value === p.value}
              onClick={() => {
                cerrar()
                if (p.value !== prio.value) onCampo(t, 'prioridad', String(p.value))
              }}
            >
              <span className="size-[9px] shrink-0 rounded-[2px]" style={{ backgroundColor: p.color }} />
              <span className="flex-1">{p.label}</span>
              {prio.value === p.value && <Check className="size-3.5" />}
            </MenuItem>
          ))
        }
      </Menu>

      {/* Acciones */}
      <div className="ml-auto flex items-center justify-end gap-1 md:ml-0" onClick={parar}>
        <button
          type="button"
          onClick={() => (onVistaRapida ? onVistaRapida(t) : onAbrir(t))}
          className="inline-flex rounded-md p-1.5 text-label transition-colors hover:bg-soft hover:text-ink"
          aria-label={`Vista rápida de ${t.titulo}`}
          title="Vista rápida"
        >
          <Eye className="size-4" strokeWidth={1.8} />
        </button>
        {puedeBorrar && (
          <button
            type="button"
            onClick={() => onBorrar(t)}
            className="inline-flex rounded-md p-1.5 text-label transition-colors hover:bg-[#fde8e8] hover:text-[#c0392b]"
            aria-label={`Borrar ${t.titulo}`}
            title="Borrar"
          >
            <X className="size-4" strokeWidth={1.8} />
          </button>
        )}
      </div>
    </div>
  )
}
