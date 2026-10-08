import type { MouseEvent, ReactNode } from 'react'
import { ArrowDown, ArrowUp, MoreHorizontal } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Checkbox from '../../../shared/ui/Checkbox'
import DateInput from '../../../shared/ui/DatePicker'
import Select from '../../../shared/ui/Select'
import { fechaCorta } from '../../../shared/lib/formato'
import type { Persona } from '../../../shared/schemas'
import type { CambioContacto } from '../api'
import type { Filtros } from '../logica'
import type { PermisosCrm } from '../permisos'
import type { Catalogos, Contacto } from '../schemas'
import { CeldaImporte, CeldaTexto } from './celdas'
import { EtiquetaChip, FaseBadge, FasePicker, ServiciosPicker } from './piezas'

type Props = {
  items: Contacto[]
  cat: Catalogos | undefined
  equipo: Persona[]
  p: PermisosCrm
  filtros: Filtros
  onSort: (clave: string) => void
  seleccion: Set<number>
  onSeleccion: (s: Set<number>) => void
  onAbrir: (c: Contacto) => void
  onGuardar: (c: Contacto, cambio: CambioContacto) => void
  onMenu: (e: MouseEvent, c: Contacto) => void
  pie?: ReactNode
}

const TH = 'sticky top-0 z-[2] whitespace-nowrap border-b border-line bg-[#fcfcfd] px-3 py-[14px] text-left text-[11px] font-[650] tracking-[.5px] text-muted uppercase dark:bg-head'
const TD = 'whitespace-nowrap border-b border-line2 px-3 py-2.5 align-middle'
/* Las dos primeras columnas (casilla y nombre) quedan fijas al hacer scroll lateral. */
const FIJA = 'sticky z-[1] bg-card group-hover/fila:bg-soft'

/* Tabla tipo hoja de cálculo de Contactos (table.cm): cabecera fija, orden
   por columna en el servidor, edición en línea y menú por fila. En el móvil
   cada fila es una tarjeta de dos líneas que abre la ficha. */
export default function TablaContactos({ items, cat, equipo, p, filtros, onSort, seleccion, onSeleccion, onAbrir, onGuardar, onMenu, pie }: Props) {
  const ids = items.map((c) => c.id)
  const marcadas = ids.filter((i) => seleccion.has(i)).length
  const todas = marcadas > 0 && marcadas === ids.length
  const ed = p.editar
  const nombres = new Map(equipo.map((x) => [x.id, x.username]))
  const opProp = [{ value: 0, label: 'Sin propietario' }, ...equipo.map((x) => ({ value: x.id, label: x.username }))]
  const opOrigen = (actual: string) => [{ value: '', label: '—' }, ...Array.from(new Set([...(cat?.origenes ?? []), ...(actual ? [actual] : [])])).map((o) => ({ value: o, label: o }))]

  function marcar(id: number, on: boolean) {
    const s = new Set(seleccion)
    if (on) s.add(id)
    else s.delete(id)
    onSeleccion(s)
  }

  const cab = (clave: string, texto: string, extra = '') => {
    const on = filtros.sort === clave
    const Flecha = filtros.dir === 'asc' ? ArrowUp : ArrowDown
    return (
      <th className={`${TH} ${extra}`} aria-sort={on ? (filtros.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
        <button type="button" onClick={() => onSort(clave)} className="inline-flex items-center gap-1 uppercase hover:text-ink">
          {texto}
          {on && <Flecha className="size-3" />}
        </button>
      </th>
    )
  }

  return (
    <>
      {/* Escritorio y tableta */}
      <div className="overflow-auto overscroll-contain rounded-2xl border border-line bg-card max-sm:hidden" style={{ maxHeight: 'calc(100vh - 250px)', minHeight: 320 }}>
        <table className="w-full border-collapse text-[13px]">
          <thead>
            <tr>
              <th className={`${TH} sticky left-0 z-[5] shadow-[1px_0_0_var(--color-line)]`} aria-sort={filtros.sort === 'nombre' ? (filtros.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
                <span className="flex items-center gap-3">
                  {ed && (
                    <Checkbox
                      checked={todas}
                      indeterminate={marcadas > 0 && !todas}
                      onChange={(on) => onSeleccion(on ? new Set([...seleccion, ...ids]) : new Set([...seleccion].filter((i) => !ids.includes(i))))}
                      aria-label="Seleccionar todos"
                    />
                  )}
                  <button type="button" onClick={() => onSort('nombre')} className="inline-flex items-center gap-1 uppercase hover:text-ink">
                    Nombre
                    {filtros.sort === 'nombre' && (filtros.dir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />)}
                  </button>
                </span>
              </th>
              {cab('empresa', 'Empresa')}
              {cab('sector', 'Sector')}
              <th className={TH}>Email</th>
              <th className={TH}>Teléfono</th>
              <th className={TH}>Origen</th>
              <th className={TH}>Servicio</th>
              {cab('valor', 'Valor', 'text-right')}
              {cab('fase', 'Embudo de venta')}
              <th className={TH}>Última actualización</th>
              {cab('ult', 'Últ. contacto')}
              {cab('prox', 'Próxima acción')}
              <th className={TH}>Propietario</th>
              {cab('creado', 'Creado')}
              <th className={`${TH} w-9`} aria-label="Acciones" />
            </tr>
          </thead>
          <tbody>
            {items.map((c) => (
              <tr key={c.id} className="group/fila hover:bg-[#fcfcfd] dark:hover:bg-soft" onContextMenu={(e) => onMenu(e, c)}>
                <td className={`${TD} ${FIJA} left-0 shadow-[1px_0_0_var(--color-line)]`}>
                  <span className="flex items-center gap-3">
                    {ed && <Checkbox checked={seleccion.has(c.id)} onChange={(on) => marcar(c.id, on)} aria-label={`Seleccionar ${c.nombre}`} />}
                    <button type="button" onClick={() => onAbrir(c)} className="flex max-w-[260px] items-center gap-2.5 text-left">
                      <Avatar nombre={c.nombre} size={30} />
                      <span className="min-w-0">
                        <span className="block truncate text-[13.5px] font-semibold text-ink-strong hover:underline">{c.nombre}</span>
                        {c.etiquetas.length > 0 && (
                          <span className="mt-0.5 flex flex-wrap gap-1">
                            {c.etiquetas.map((e) => (
                              <EtiquetaChip key={e.id} e={e} />
                            ))}
                          </span>
                        )}
                      </span>
                    </button>
                  </span>
                </td>
                <td className={TD}>
                  <CeldaTexto value={c.empresa} readOnly={!ed} placeholder="—" aria-label="Empresa" className="!w-[170px]" onSave={(v) => onGuardar(c, { empresa: v })} />
                </td>
                <td className={TD}>
                  <CeldaTexto value={c.sector} readOnly={!ed} placeholder="—" list="crm-sectores" aria-label="Sector" className="!w-[130px]" onSave={(v) => onGuardar(c, { sector: v })} />
                </td>
                <td className={TD}>
                  <CeldaTexto value={c.email} type="email" readOnly={!ed} placeholder="—" aria-label="Email" className="!w-[200px]" onSave={(v) => onGuardar(c, { email: v })} />
                </td>
                <td className={TD}>
                  <CeldaTexto value={c.telefono} type="tel" readOnly={!ed} placeholder="—" aria-label="Teléfono" className="!w-[130px]" onSave={(v) => onGuardar(c, { telefono: v })} />
                </td>
                <td className={`${TD} min-w-[140px]`}>
                  {ed ? (
                    <Select variant="inline" value={c.origen_lead} options={opOrigen(c.origen_lead)} onChange={(v) => onGuardar(c, { origen_lead: v })} aria-label="Origen" className="!text-[13px]" />
                  ) : (
                    <span className="px-2">{c.origen_lead || '—'}</span>
                  )}
                </td>
                <td className={`${TD} min-w-[120px]`}>
                  <ServiciosPicker opciones={cat?.servicios ?? []} value={c.servicios} disabled={!ed} onChange={(v) => onGuardar(c, { servicios: v })} />
                </td>
                <td className={`${TD} text-right`}>
                  <CeldaImporte value={c.valor} readOnly={!ed} placeholder="—" aria-label="Valor" onSave={(v) => onGuardar(c, { valor: v === '' ? null : v })} />
                </td>
                <td className={TD}>
                  <FasePicker fases={cat?.fases} value={c.fase} disabled={!ed} onChange={(f) => onGuardar(c, { fase: f })} />
                </td>
                <td className={`${TD} max-w-[220px] truncate text-muted`} title={c.ultima_actualizacion || undefined}>
                  {c.ultima_actualizacion || '—'}
                </td>
                <td className={`${TD} w-[118px]`}>
                  <DateInput variant="inline" size="sm" value={c.fecha_ultimo_contacto} disabled={!ed} onChange={(v) => onGuardar(c, { fecha_ultimo_contacto: v })} aria-label="Último contacto" />
                </td>
                <td className={`${TD} min-w-[250px]`}>
                  <span className="flex items-center gap-1">
                    {c.accion_vencida && <span className="size-[7px] shrink-0 rounded-full bg-[#e5484d]" title="Acción vencida" />}
                    <CeldaTexto
                      value={c.proxima_accion}
                      readOnly={!ed}
                      placeholder="Acción…"
                      aria-label="Próxima acción"
                      className={c.accion_vencida ? '!text-[#c0343a] font-semibold dark:!text-danger' : ''}
                      onSave={(v) => onGuardar(c, { proxima_accion: v })}
                    />
                    <span className="w-[104px] shrink-0">
                      <DateInput
                        variant="inline"
                        size="sm"
                        value={c.fecha_prox}
                        disabled={!ed}
                        placeholder="dd/mm/aa"
                        tone={() => (c.accion_vencida ? 'late' : null)}
                        onChange={(v) => onGuardar(c, { fecha_prox: v })}
                        aria-label="Fecha de la próxima acción"
                      />
                    </span>
                  </span>
                </td>
                <td className={`${TD} min-w-[140px]`}>
                  {ed ? (
                    <Select
                      variant="inline"
                      value={c.propietario_id ?? 0}
                      options={opProp}
                      onChange={(v) => onGuardar(c, { propietario_id: v || null })}
                      aria-label="Propietario"
                      className="!text-[13px]"
                    />
                  ) : (
                    <span className="px-2">{c.propietario_id ? (nombres.get(c.propietario_id) ?? '—') : '—'}</span>
                  )}
                </td>
                <td className={`${TD} text-muted tabular-nums`}>{fechaCorta(c.fecha_creacion)}</td>
                <td className={`${TD} w-9 !px-1`}>
                  <button
                    type="button"
                    onClick={(e) => onMenu(e, c)}
                    aria-label={`Acciones de ${c.nombre}`}
                    className="flex size-7 items-center justify-center rounded-md text-[#c2c6cd] opacity-0 transition-opacity group-hover/fila:opacity-100 hover:bg-chip hover:text-ink focus-visible:opacity-100"
                  >
                    <MoreHorizontal className="size-4" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {pie}
      </div>
      <datalist id="crm-sectores">
        {(cat?.sectores ?? []).map((s) => (
          <option key={s} value={s} />
        ))}
      </datalist>

      {/* Móvil: filas-tarjeta de dos líneas */}
      <ul className="overflow-hidden rounded-2xl border border-line bg-card sm:hidden">
        {items.map((c) => (
          <li key={c.id} className="flex items-center gap-3 border-b border-line2 px-3.5 py-3 last:border-b-0" onContextMenu={(e) => onMenu(e, c)}>
            <button type="button" onClick={() => onAbrir(c)} className="flex min-w-0 flex-1 items-center gap-3 text-left">
              <Avatar nombre={c.nombre} size={38} />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[15px] font-semibold text-ink-strong">{c.nombre}</span>
                <span className="mt-0.5 flex min-w-0 items-center gap-1.5 text-[12.5px] text-muted">
                  {c.empresa && <span className="truncate">{c.empresa}</span>}
                  <FaseBadge fases={cat?.fases} fase={c.fase} size="sm" />
                </span>
              </span>
            </button>
            {ed && <Checkbox checked={seleccion.has(c.id)} onChange={(on) => marcar(c.id, on)} aria-label={`Seleccionar ${c.nombre}`} />}
          </li>
        ))}
        {pie && <li>{pie}</li>}
      </ul>
    </>
  )
}
