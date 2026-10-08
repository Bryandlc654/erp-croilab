import { useMemo, useRef, useState } from 'react'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Button from '../../../shared/ui/Button'
import Field from '../../../shared/ui/Field'
import FormGrid from '../../../shared/ui/FormGrid'
import Select from '../../../shared/ui/Select'
import { TextArea, TextInput } from '../../../shared/ui/TextInput'
import { DateInput } from '../../../shared/ui/DatePicker'
import PersonPicker from '../../../shared/ui/PersonPicker'
import { useToast } from '../../../shared/ui/useToast'
import { ESTADOS, ORDEN_ESTADOS, PRIORIDADES } from '../constantes'
import { useDebounced } from '../../../shared/lib/useDebounced'
import { useBuscarClientes, useCliente } from '../../clientes/api'
import { useEquipo } from '../../nav/api'
import { useCrearTarea } from '../api'
import type { Estado } from '../schemas'

export type DatosNuevaTarea = { cli?: number; list?: number; estado?: Estado; mes?: string; asignados?: number[] }

/* «Nueva tarea» (modal de workspace.php y el global erpTarea): título,
   descripción, estado, asignados, fechas, prioridad, etiquetas y mes. Si no
   viene el cliente y la lista (vistas generales), se eligen aquí. */
export default function NuevaTareaModal({ abierto, onCerrar, datos, onCreada }: { abierto: boolean; onCerrar: () => void; datos: DatosNuevaTarea; onCreada?: (id: number) => void }) {
  if (!abierto) return null
  return <Formulario onCerrar={onCerrar} datos={datos} onCreada={onCreada} />
}

function Formulario({ onCerrar, datos, onCreada }: { onCerrar: () => void; datos: DatosNuevaTarea; onCreada?: (id: number) => void }) {
  const { aviso } = useToast()
  const crear = useCrearTarea()
  const { data: equipoData } = useEquipo()
  const equipo = useMemo(() => equipoData ?? [], [equipoData])
  const titulo = useRef<HTMLInputElement>(null)
  const [f, setF] = useState({
    titulo: '',
    descripcion: '',
    estado: datos.estado ?? ('pendiente' as Estado),
    asignados: datos.asignados ?? ([] as number[]),
    fecha_inicio: null as string | null,
    due_date: null as string | null,
    prioridad: 0,
    etiquetas: '',
    mes: datos.mes ?? '',
  })
  const [cli, setCli] = useState(datos.cli ?? 0)
  const [list, setList] = useState(datos.list ?? 0)
  const [buscar, setBuscar] = useState('')
  const busqueda = useBuscarClientes(useDebounced(buscar, 250))
  const { data: cliente } = useCliente(cli)
  const [error, setError] = useState<string | null>(null)
  const fijo = !!(datos.cli && datos.list)
  const listas = (cliente?.cliente.listas ?? []).filter((l) => l.tipo !== 'informe' || datos.mes !== undefined)

  async function enviar() {
    const t = f.titulo.trim()
    if (!t) {
      setError('titulo')
      aviso('Escribe un título', { tipo: 'error' })
      titulo.current?.focus()
      return
    }
    const lista = list || listas[0]?.id || 0
    if (!cli || !lista) {
      setError('list')
      aviso('¿A qué lista? Elígela para guardar', { tipo: 'error' })
      return
    }
    const r = await crear.mutateAsync({
      client_id: cli,
      list_id: lista,
      titulo: t,
      estado: f.estado,
      asignados: f.asignados,
      fecha_inicio: f.fecha_inicio,
      due_date: f.due_date,
      prioridad: f.prioridad,
      etiquetas: f.etiquetas,
      mes: f.mes,
      ...(f.descripcion.trim() ? { descripcion: f.descripcion } : {}),
    })
    aviso('Tarea creada')
    onCreada?.(r.tarea.id)
    onCerrar()
  }

  const opcionesCli = [
    ...(cliente && !(busqueda.data?.items ?? []).some((c) => c.id === cliente.cliente.id) ? [{ value: cliente.cliente.id, label: cliente.cliente.name }] : []),
    ...(busqueda.data?.items ?? []).map((c) => ({ value: c.id, label: c.name })),
  ]

  return (
    <Modal open onClose={onCerrar} size="xl" align="top" title="Nueva tarea" subtitle={fijo ? `${cliente?.cliente.name ?? ''} · ${listas.find((l) => l.id === list)?.nombre ?? ''}` : 'Elige el cliente y la lista'} initialFocus={titulo}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          void enviar().catch(() => undefined)
        }}
      >
        <ModalBody>
          <FormGrid>
            {!fijo && (
              <>
                <Field label="Cliente" required span={6} error={error === 'list' && !cli ? 'Elige un cliente' : undefined}>
                  <Select
                    value={cli || null}
                    onChange={(v) => {
                      setCli(v)
                      setList(0)
                    }}
                    options={opcionesCli}
                    searchable
                    onSearchChange={setBuscar}
                    placeholder="Elige un cliente…"
                    emptyText="Sin coincidencias"
                  />
                </Field>
                <Field label="Lista" required span={6}>
                  <Select
                    value={list || listas[0]?.id || null}
                    onChange={setList}
                    options={listas.map((l) => ({ value: l.id, label: l.nombre }))}
                    placeholder={cli ? (listas.length ? 'Elige la lista' : '(sin listas — créalas en las tareas del cliente)') : 'Primero el cliente'}
                    disabled={!cli || listas.length === 0}
                  />
                </Field>
              </>
            )}
            <Field label="Título" required span={12} error={error === 'titulo' ? 'El título es obligatorio' : undefined}>
              <TextInput
                ref={titulo}
                value={f.titulo}
                maxLength={255}
                invalid={error === 'titulo'}
                onChange={(e) => {
                  setF({ ...f, titulo: e.target.value })
                  setError(null)
                }}
                placeholder="¿Qué hay que hacer? (Intro para crear)"
              />
            </Field>
            <Field label="Descripción" span={12}>
              <TextArea value={f.descripcion} rows={3} onChange={(e) => setF({ ...f, descripcion: e.target.value })} placeholder="Opcional" />
            </Field>
            <Field label="Estado" span={6}>
              <Select value={f.estado} onChange={(v) => setF({ ...f, estado: v })} options={ORDEN_ESTADOS.map((e) => ({ value: e, label: ESTADOS[e].label, color: ESTADOS[e].color }))} />
            </Field>
            <Field label="Asignados" span={6}>
              <PersonPicker multiple label="Asignados" people={equipo} value={f.asignados} onChange={(ids) => setF({ ...f, asignados: ids })} className="min-h-[43px] w-full rounded-[10px] border border-line bg-field px-3" />
            </Field>
            <Field label="Inicio" span={6}>
              <DateInput value={f.fecha_inicio} onChange={(v) => setF({ ...f, fecha_inicio: v })} />
            </Field>
            <Field label="Fecha límite" span={6}>
              <DateInput value={f.due_date} onChange={(v) => setF({ ...f, due_date: v })} />
            </Field>
            <Field label="Prioridad" span={4}>
              <Select value={f.prioridad} onChange={(v) => setF({ ...f, prioridad: v })} options={PRIORIDADES.map((p) => ({ value: p.value, label: p.value === 0 ? 'Sin prioridad' : p.label, color: p.color }))} />
            </Field>
            <Field label="Etiquetas" span={4}>
              <TextInput value={f.etiquetas} maxLength={255} onChange={(e) => setF({ ...f, etiquetas: e.target.value })} placeholder="SEO, web…" />
            </Field>
            <Field label="Mes (informe)" span={4}>
              <TextInput value={f.mes} maxLength={40} onChange={(e) => setF({ ...f, mes: e.target.value })} placeholder="Ej: Junio" />
            </Field>
          </FormGrid>
        </ModalBody>
        <ModalFooter>
          <Button type="button" variant="ghost" onClick={onCerrar}>
            Cancelar
          </Button>
          <Button type="submit" loading={crear.isPending} loadingText="Creando…">
            Crear tarea
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
