import { Check, Search, UserPlus, X } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Menu, { MenuItem } from '../../../shared/ui/Menu'
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
  /* El valor llega como texto (como en el formulario): '' = quitar. */
  onCampo: (t: Tarea, campo: CampoEnLinea, valor: string) => void
  onAbrir: (t: Tarea) => void
  onBorrar: (t: Tarea) => void
}

export default function TareaFila({ t, equipo, puedeEditar, puedeBorrar, mostrarLista, onCampo, onAbrir, onBorrar }: Props) {
  const prio = PRIORIDADES.find((p) => p.value === t.prioridad) ?? PRIORIDADES[0]
  const asignado = t.asignados[0] ?? null
  const completada = t.estado === 'completada'

  return (
    <div
      role="row"
      onClick={() => onAbrir(t)}
      className={`${COLUMNAS} cursor-pointer border-b border-line px-4 py-3 transition-colors last:border-b-0 hover:bg-[#fafbfc] dark:hover:bg-white/[.03]`}
    >
      {/* Nombre, con el círculo de estado a la izquierda */}
      <div className="flex min-w-0 basis-full items-center gap-3 md:basis-auto">
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
        <span className={`min-w-0 truncate text-[13.5px] font-semibold ${completada ? 'text-muted line-through decoration-[1.5px]' : 'text-ink-strong'}`}>
          {t.titulo}
        </span>
        {completada && (
          <span className="shrink-0 rounded-md bg-[#e4f6ec] px-[7px] py-px text-[11px] font-semibold text-[#12854a] dark:bg-[#12854a]/20">Completada</span>
        )}
        {t.visible_cliente && (
          <span className="shrink-0 rounded-[5px] bg-soft px-1.5 py-0.5 text-[9.5px] font-semibold tracking-[.2px] text-muted uppercase">cliente</span>
        )}
        {mostrarLista && t.list_name && (
          <span className="shrink-0 rounded-md bg-soft px-[7px] py-px text-[11px] font-semibold whitespace-nowrap text-label">{t.list_name}</span>
        )}
      </div>

      {/* Persona asignada */}
      <Menu
        label="Persona asignada"
        disabled={!puedeEditar}
        panelClassName="max-h-72 overflow-y-auto"
        trigger={() => (
          <span className="flex min-w-0 items-center gap-2 rounded-[7px] px-1 py-1 hover:bg-soft">
            {t.asignados.length > 0 ? (
              <>
                <span className="flex -space-x-1.5">
                  {t.asignados.slice(0, 3).map((a) => (
                    <Avatar key={a.id} nombre={a.username} foto={a.foto} size={24} className="ring-2 ring-page" />
                  ))}
                </span>
                <span className="truncate text-[13px] text-ink">
                  {t.asignados.length === 1 ? asignado!.username : `${t.asignados.length} asignados`}
                </span>
              </>
            ) : (
              <span className="flex items-center gap-1.5 text-[12.5px] text-label">
                <UserPlus className="size-3.5" /> Asignar
              </span>
            )}
          </span>
        )}
      >
        {(cerrar) => (
          <>
            {equipo.map((p) => (
              <MenuItem
                key={p.id}
                on={t.responsable_id === p.id}
                onClick={() => {
                  cerrar()
                  if (t.responsable_id !== p.id) onCampo(t, 'responsable_id', String(p.id))
                }}
              >
                <Avatar nombre={p.username} foto={p.foto} size={20} />
                <span className="flex-1 truncate">{p.username}</span>
                {t.responsable_id === p.id && <Check className="size-3.5" />}
              </MenuItem>
            ))}
            {t.responsable_id && (
              <MenuItem
                onClick={() => {
                  cerrar()
                  onCampo(t, 'responsable_id', '')
                }}
              >
                <X className="size-3.5" /> Quitar responsable
              </MenuItem>
            )}
          </>
        )}
      </Menu>

      {/* Fecha límite */}
      <div onClick={(e) => e.stopPropagation()}>
        <input
          type="date"
          lang="es"
          aria-label="Fecha límite"
          disabled={!puedeEditar}
          value={t.due_date ?? ''}
          onChange={(e) => onCampo(t, 'due_date', e.target.value)}
          className="h-[37px] w-full rounded-[9px] border border-line bg-page px-2.5 text-[12.5px] text-ink [color-scheme:light] focus:border-[#c9ccd1] focus:outline-none disabled:opacity-70 dark:[color-scheme:dark]"
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
      <div className="ml-auto flex items-center justify-end gap-1 md:ml-0" onClick={(e) => e.stopPropagation()}>
        <button
          type="button"
          onClick={() => onAbrir(t)}
          className="inline-flex rounded-md p-1.5 text-label transition-colors hover:bg-soft hover:text-ink"
          aria-label={`Abrir ${t.titulo}`}
          title="Abrir"
        >
          <Search className="size-4" strokeWidth={1.8} />
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
