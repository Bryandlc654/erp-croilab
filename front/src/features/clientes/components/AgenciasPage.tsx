import { useState } from 'react'
import { Plus, Settings, Trash2 } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import Field from '../../../shared/ui/Field'
import FormGrid, { FormZone } from '../../../shared/ui/FormGrid'
import IconButton from '../../../shared/ui/IconButton'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import Select from '../../../shared/ui/Select'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { colorDe } from '../../../shared/lib/avatar'
import { mensajeError, useAgencias, useAsignarAgencia, useBorrarAgencia, useGuardarAgencia, type CuerpoAgencia } from '../api'
import type { Agencia } from '../schemas'
import { enlaceAcceso } from '../lib/etiquetas'
import { usePermisosClientes } from './permisos'

const VACIA: CuerpoAgencia = { nombre: '', color: '', logo_url: '', web: '', email: '', whatsapp: '', meeting_url: '', telefono: '' }

/* Marca blanca (agencias.php): agencias colaboradoras y a qué agencia va cada cliente. */
export default function AgenciasPage() {
  const { data, isLoading, error } = useAgencias()
  const p = usePermisosClientes()
  const { confirm } = useConfirm()
  const borrar = useBorrarAgencia()
  const asignar = useAsignarAgencia()
  const [editando, setEditando] = useState<Agencia | 'nueva' | null>(null)
  const casa = data?.casa ?? 'Croilab'

  async function eliminar(a: Agencia) {
    const ok = await confirm({ title: '¿Eliminar agencia?', message: `Los clientes de «${a.nombre}» volverán a tu marca.`, okLabel: 'Eliminar', danger: true })
    if (ok) borrar.mutate(a.id)
  }

  return (
    <div className="max-w-[1180px]">
      <PageHeader
        title="Marca blanca"
        lead={`Los clientes asignados a una agencia ven su portal e informes con el logo de esa agencia y escriben a su WhatsApp y su enlace de reuniones, no a los tuyos. Lo que no se rellene aquí cae a los datos de ${casa}.`}
        actions={
          p.marca && (
            <Button icon={<Plus />} onClick={() => setEditando('nueva')}>
              Nueva agencia
            </Button>
          )
        }
      />
      {error && <Notice tone="error">{mensajeError(error, 'No se han podido cargar las agencias.')}</Notice>}
      {isLoading && <p className="py-[60px] text-center text-[13.5px] text-muted">Cargando…</p>}
      {data && (
        <>
          {data.agencias.length === 0 ? (
            <div className="rounded-[18px] border border-dashed border-[#d4d8de] px-[26px] py-10 text-center text-[14px] text-muted dark:border-line-strong">
              Aún no hay agencias colaboradoras.{' '}
              {p.marca && (
                <button type="button" onClick={() => setEditando('nueva')} className="font-semibold text-ink-strong hover:underline">
                  Añade la primera →
                </button>
              )}
            </div>
          ) : (
            <div className="grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-4">
              {data.agencias.map((a) => (
                <TarjetaAgencia key={a.id} a={a} puede={p.marca} onEditar={() => setEditando(a)} onBorrar={() => void eliminar(a)} />
              ))}
            </div>
          )}

          <h2 className="mt-8 mb-3 text-[16px] font-semibold text-ink-strong">Asignar clientes</h2>
          <Card padding="none" className="overflow-hidden">
            <div className="grid grid-cols-[minmax(0,1fr)_minmax(200px,280px)] gap-4 border-b border-line bg-head px-5 py-3 text-[11px] font-[650] tracking-[.5px] text-muted uppercase">
              <span>Cliente</span>
              <span>Agencia (white-label)</span>
            </div>
            {data.clientes.length === 0 && <p className="px-5 py-10 text-center text-[14px] text-muted">No hay clientes.</p>}
            {data.clientes.map((c) => (
              <div key={c.id} className="grid grid-cols-[minmax(0,1fr)_minmax(200px,280px)] items-center gap-4 border-b border-line2 px-5 py-3 last:border-b-0 max-sm:grid-cols-1 max-sm:gap-2">
                <span className="flex min-w-0 items-center gap-2.5">
                  <Avatar nombre={c.name} inicialesGuardadas={c.iniciales} forma="cuadrado" size={28} />
                  <span className="truncate text-[13.5px] font-semibold text-ink-strong">{c.name}</span>
                </span>
                <Select
                  aria-label={`Agencia de ${c.name}`}
                  value={c.partner_id ?? 0}
                  disabled={!p.marca || asignar.isPending}
                  onChange={(v) => asignar.mutate({ cliente: c.id, partner: v === 0 ? null : v })}
                  options={[{ value: 0, label: `— Tu marca (${casa}) —` }, ...data.agencias.map((a) => ({ value: a.id, label: a.nombre }))]}
                />
              </div>
            ))}
          </Card>
        </>
      )}
      {editando && <ModalAgencia agencia={editando === 'nueva' ? null : editando} casa={casa} onClose={() => setEditando(null)} />}
    </div>
  )
}

function TarjetaAgencia({ a, puede, onEditar, onBorrar }: { a: Agencia; puede: boolean; onEditar: () => void; onBorrar: () => void }) {
  const { aviso } = useToast()
  const [copiado, setCopiado] = useState(false)
  const enlace = enlaceAcceso(a.id)
  async function copiar() {
    try {
      await navigator.clipboard.writeText(enlace)
      setCopiado(true)
      setTimeout(() => setCopiado(false), 1400)
    } catch {
      aviso('No se ha podido copiar.', { tipo: 'error' })
    }
  }
  return (
    <Card padding="md" className="flex flex-col gap-3">
      <div className="flex items-start gap-3">
        <span
          className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl text-[18px] font-bold text-white"
          style={{ backgroundColor: a.color || colorDe(a.nombre) }}
          aria-hidden="true"
        >
          {/^https?:\/\//i.test(a.logo_url) ? <img src={a.logo_url} alt="" className="size-full bg-white object-contain p-1" /> : a.nombre.slice(0, 1).toUpperCase()}
        </span>
        <div className="min-w-0 flex-1">
          <b className="block truncate text-[15px] font-semibold text-ink-strong">{a.nombre}</b>
          <span className="block truncate text-[12.5px] text-muted">{[a.web, a.email].filter(Boolean).join(' · ') || 'Sin datos de contacto'}</span>
          <span className="text-[12px] font-semibold text-label">{a.uso === 1 ? '1 cliente' : `${a.uso} clientes`}</span>
        </div>
        {puede && (
          <span className="flex gap-0.5">
            <IconButton label="Editar agencia" icon={<Settings />} onClick={onEditar} />
            <IconButton label="Eliminar agencia" tone="danger" icon={<Trash2 />} onClick={onBorrar} />
          </span>
        )}
      </div>
      <div className="flex items-center gap-2 rounded-[10px] bg-soft px-3 py-2">
        <span className="min-w-0 flex-1 truncate font-mono text-[12px] text-ink" title={enlace}>
          {enlace}
        </span>
        <Button variant="ghost" size="sm" onClick={() => void copiar()}>
          {copiado ? 'Copiado' : 'Copiar'}
        </Button>
      </div>
    </Card>
  )
}

function ModalAgencia({ agencia, casa, onClose }: { agencia: Agencia | null; casa: string; onClose: () => void }) {
  const [d, setD] = useState<CuerpoAgencia>(() => (agencia ? { ...agencia } : { ...VACIA }))
  const [error, setError] = useState('')
  const guardar = useGuardarAgencia()
  const { aviso } = useToast()
  const set = (k: keyof CuerpoAgencia, v: string) => setD((x) => ({ ...x, [k]: v }))

  async function enviar() {
    if (!d.nombre.trim()) {
      setError('Pon el nombre de la agencia.')
      return
    }
    try {
      const { nombre, color, logo_url, web, email, whatsapp, meeting_url, telefono } = d
      await guardar.mutateAsync({ id: agencia?.id ?? null, datos: { nombre, color, logo_url, web, email, whatsapp, meeting_url, telefono } })
      aviso(agencia ? 'Agencia guardada.' : 'Agencia creada.')
      onClose()
    } catch (e) {
      setError(mensajeError(e, 'No se ha podido guardar la agencia.'))
    }
  }

  return (
    <Modal open onClose={onClose} size="lg" title={agencia ? 'Editar agencia' : 'Nueva agencia'}>
      <ModalBody>
        {error && <Notice tone="error" className="!mb-0">{error}</Notice>}
        <FormGrid>
          <FormZone title="La agencia" />
          <Field label="Nombre" span={12} required>
            <TextInput value={d.nombre} onChange={(e) => set('nombre', e.target.value)} maxLength={160} autoFocus />
          </Field>
          <Field label="Color de marca" span={4}>
            <div className="flex items-center gap-2">
              <input
                type="color"
                aria-label="Elegir color"
                value={/^#[0-9a-f]{6}$/i.test(d.color) ? d.color : '#7b68ee'}
                onChange={(e) => set('color', e.target.value)}
                className="h-[42px] w-11 shrink-0 cursor-pointer rounded-[10px] border border-line bg-field p-1"
              />
              <TextInput value={d.color} onChange={(e) => set('color', e.target.value)} placeholder="#7b68ee" maxLength={7} />
            </div>
          </Field>
          <Field label="Logo (URL)" span={8}>
            <TextInput type="url" value={d.logo_url} onChange={(e) => set('logo_url', e.target.value)} placeholder="https://…/logo.png" maxLength={400} />
          </Field>
          <FormZone title="Contacto que ve el cliente en su portal" />
          <Field label="Email" span={6}>
            <TextInput type="email" value={d.email} onChange={(e) => set('email', e.target.value)} maxLength={160} />
          </Field>
          <Field label="Teléfono" span={6}>
            <TextInput value={d.telefono} onChange={(e) => set('telefono', e.target.value)} maxLength={40} />
          </Field>
          <Field label="Web" span={6}>
            <TextInput value={d.web} onChange={(e) => set('web', e.target.value)} placeholder="agencia.com" maxLength={200} />
          </Field>
          <Field label="WhatsApp" span={6} hint="Solo números, con prefijo.">
            <TextInput value={d.whatsapp} onChange={(e) => set('whatsapp', e.target.value)} placeholder="34600000000" maxLength={40} inputMode="tel" />
          </Field>
          <Field label="Enlace para pedir reunión" span={12} hint={`Lo que se deje vacío cae a los datos de ${casa} (apartado Agencia).`}>
            <TextInput type="url" value={d.meeting_url} onChange={(e) => set('meeting_url', e.target.value)} placeholder="https://calendly.com/…" maxLength={300} />
          </Field>
        </FormGrid>
      </ModalBody>
      <ModalFooter>
        <Button variant="subtle" onClick={onClose}>
          Cancelar
        </Button>
        <Button onClick={() => void enviar()} loading={guardar.isPending} loadingText="Guardando…">
          {agencia ? 'Guardar' : 'Crear agencia'}
        </Button>
      </ModalFooter>
    </Modal>
  )
}
