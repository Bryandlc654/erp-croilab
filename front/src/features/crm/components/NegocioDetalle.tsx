import { Archive, Check, ExternalLink, FileText, Trash2, UserCheck, X } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import DateInput from '../../../shared/ui/DatePicker'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Select from '../../../shared/ui/Select'
import { useToast } from '../../../shared/ui/useToast'
import { fechaCorta } from '../../../shared/lib/formato'
import { useEquipo } from '../../nav/api'
import { mensajeError, useAccionNegocio, useCatalogos } from '../api'
import { nombreFase } from '../logica'
import { usePermisosCrm } from '../permisos'
import type { Negocio } from '../schemas'
import { CeldaImporte, CeldaTexto } from './celdas'

const ETQ = 'px-2 text-[11.5px] font-semibold text-muted'

/* Detalle de un negocio (modal del embudo): campos que guardan solos y las
   acciones (ganar, perder, archivar, cliente, factura, eliminar). */
export default function NegocioDetalle({
  negocio: n,
  onClose,
  onGanar,
  onPerder,
  onMover,
  onArchivar,
  onBorrar,
  onConvertir,
  onFactura,
}: {
  negocio: Negocio
  onClose: () => void
  onGanar: () => void
  onPerder: () => void
  onMover: (fase: string) => void
  onArchivar: () => void
  onBorrar: () => void
  onConvertir: () => void
  onFactura: () => void
}) {
  const { data: cat } = useCatalogos()
  const { data: equipo = [] } = useEquipo()
  const p = usePermisosCrm()
  const acc = useAccionNegocio()
  const { aviso } = useToast()
  const ed = p.editar
  const g = (d: Omit<Parameters<typeof acc.editar.mutate>[0], 'id'>) =>
    acc.editar.mutate({ id: n.id, ...d }, { onSuccess: () => aviso('Guardado'), onError: (e) => aviso(mensajeError(e), { tipo: 'error' }) })
  const servicios = Array.from(new Set([...(cat?.servicios ?? []), ...(n.servicio ? [n.servicio] : [])]))
  const motivo = cat?.motivos_perdida.find((x) => x.value === n.motivo_perdida)

  return (
    <Modal open onClose={onClose} size="lg" title={n.nombre} subtitle={`${n.contacto.empresa || n.contacto.nombre} · ${nombreFase(cat?.fases, n.fase)}`}>
      <ModalBody className="!gap-2.5">
        <label className="flex flex-col gap-0.5">
          <span className={ETQ}>Nombre del negocio</span>
          <CeldaTexto value={n.nombre} readOnly={!ed} onSave={(v) => v && g({ nombre: v })} className="!max-w-none" />
        </label>
        <div className="grid grid-cols-2 gap-x-3 gap-y-2.5 max-sm:grid-cols-1">
          <label className="flex flex-col gap-0.5">
            <span className={ETQ}>Valor (€)</span>
            <CeldaImporte value={n.valor} readOnly={!ed} placeholder="—" onSave={(v) => g({ valor: v === '' ? null : v })} className="!w-full !text-left" />
          </label>
          <label className="flex flex-col gap-0.5">
            <span className={ETQ}>Cierre previsto</span>
            <DateInput variant="inline" value={n.fecha_cierre_prevista} disabled={!ed} onChange={(v) => g({ fecha_cierre_prevista: v })} aria-label="Cierre previsto" />
          </label>
          <label className="flex flex-col gap-0.5">
            <span className={ETQ}>Servicio</span>
            <Select variant="inline" disabled={!ed} value={n.servicio} onChange={(v) => g({ servicio: v })} options={[{ value: '', label: '—' }, ...servicios.map((s) => ({ value: s, label: s }))]} aria-label="Servicio" />
          </label>
          <label className="flex flex-col gap-0.5">
            <span className={ETQ}>Propietario</span>
            <Select
              variant="inline"
              disabled={!ed}
              value={n.propietario_id ?? 0}
              onChange={(v) => g({ propietario_id: v || null })}
              options={[{ value: 0, label: 'Sin propietario' }, ...equipo.map((x) => ({ value: x.id, label: x.username }))]}
              aria-label="Propietario"
            />
          </label>
          <label className="col-span-full flex flex-col gap-0.5">
            <span className={ETQ}>Fase del embudo</span>
            <Select
              variant="inline"
              disabled={!ed || n.archivado}
              value={n.fase}
              onChange={onMover}
              options={(cat?.fases ?? []).map((f) => ({ value: f.slug, label: f.nombre, color: f.color }))}
              aria-label="Fase del embudo"
            />
          </label>
        </div>
        {n.tipo_fase === 'perdida' && motivo && (
          <p className="rounded-[10px] bg-soft px-3 py-2 text-[12.5px] text-ink">
            Perdido por <b>{motivo.label.toLowerCase()}</b>
            {n.motivo_perdida_txt && `: ${n.motivo_perdida_txt}`}
            {n.fecha_reactivacion ? ` · reactivar el ${fechaCorta(n.fecha_reactivacion)}` : ' · no se reactiva'}
          </p>
        )}
        {(cat?.etiquetas ?? []).length > 0 && (
          <div>
            <span className={ETQ}>Etiquetas</span>
            <div className="mt-1.5 flex flex-wrap gap-1.5 px-2">
              {(cat?.etiquetas ?? []).map((t) => {
                const on = n.etiquetas.some((x) => x.id === t.id)
                return (
                  <button
                    key={t.id}
                    type="button"
                    disabled={!ed}
                    aria-pressed={on}
                    onClick={() => acc.etiqueta.mutate({ id: n.id, tag: t.id, on: !on })}
                    className={`rounded-md px-2.5 py-[3px] text-[11.5px] font-bold transition-colors disabled:cursor-default ${on ? 'text-white' : 'bg-soft text-muted hover:text-ink'}`}
                    style={on ? { backgroundColor: t.color } : undefined}
                  >
                    {t.nombre}
                  </button>
                )
              })}
            </div>
          </div>
        )}
      </ModalBody>
      <ModalFooter className="!justify-start">
        <Button variant="ghost" size="sm" icon={<ExternalLink />} to={`/crm/contactos/${n.contact_id}`}>
          Ver contacto
        </Button>
        {n.client_id && p.clientes ? (
          <Button variant="ghost" size="sm" icon={<UserCheck />} to={`/clientes/${n.client_id}`}>
            Ver cliente
          </Button>
        ) : (
          p.convertir && (
            <Button variant="ghost" size="sm" icon={<UserCheck />} onClick={onConvertir}>
              Crear cliente
            </Button>
          )
        )}
        {(n.invoice_id ? p.finanzas : p.facturar) && (
          <Button variant="ghost" size="sm" icon={<FileText />} onClick={onFactura}>
            {n.invoice_id ? 'Ver factura' : 'Crear factura'}
          </Button>
        )}
        {ed && !n.archivado && n.tipo_fase !== 'ganada' && (
          <Button variant="ghost" size="sm" icon={<Check />} onClick={onGanar}>
            Marcar ganado
          </Button>
        )}
        {ed && !n.archivado && n.tipo_fase !== 'perdida' && (
          <Button variant="ghost" size="sm" icon={<X />} onClick={onPerder}>
            Perdido
          </Button>
        )}
        {ed && (
          <Button variant="ghost" size="sm" icon={<Archive />} onClick={onArchivar}>
            {n.archivado ? 'Desarchivar' : 'Archivar'}
          </Button>
        )}
        {p.borrar && (
          <Button variant="danger" size="sm" icon={<Trash2 />} onClick={onBorrar}>
            Eliminar
          </Button>
        )}
      </ModalFooter>
    </Modal>
  )
}
