import { useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Ban, CircleDollarSign, Copy, Download, FileCheck2, FileText, Folder, MoreHorizontal, Pencil, Printer, RotateCcw, ShieldAlert, Trash2, Undo2 } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Card, { CardHeader } from '../../../shared/ui/Card'
import { DateInput } from '../../../shared/ui/DatePicker'
import Field from '../../../shared/ui/Field'
import Menu, { MenuItem, MenuSeparator } from '../../../shared/ui/Menu'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { fechaCorta } from '../../../shared/lib/formato'
import { mensajeError, pedirPdf, useAccionFactura, useBorrarFactura, useEmisores, useFactura, useHoja, useRestaurar } from '../api'
import CargandoFin from '../components/Cargando'
import EstadoPill from '../components/EstadoPill'
import FacturaHoja from '../components/FacturaHoja'
import AsignarProyectoModal from '../components/AsignarProyectoModal'
import { base64ABytes, descargar } from '../lib/exportar'
import { eurC } from '../lib/importes'
import { hoyIso } from '../lib/periodos'
import { usePermisosFin } from '../lib/permisos'
import type { Factura } from '../schemas'

/* Vista de una factura (facturas.php?v=): la hoja imprimible y, al lado, su
   estado y lo que se puede hacer con ella. Emitida = inmutable: solo cobros,
   proyecto, rectificar o anular. */
export default function FacturaPage() {
  const id = Number(useParams().id ?? 0)
  const { data: f, error, isLoading } = useFactura(id)
  const hoja = useHoja(id)
  const em = useEmisores()
  if (error) return <Notice tone="error">{mensajeError(error, 'No se ha podido cargar la factura.')}</Notice>
  if (isLoading || !f) return <CargandoFin />
  const emisor = em.data?.items.find((e) => e.clave === f.emisor)
  return <Vista f={f} hoja={hoja.data ?? null} emisorNombre={emisor?.nombre ?? f.emisor_nombre} />
}

function Vista({ f, hoja, emisorNombre }: { f: Factura; hoja: ReturnType<typeof useHoja>['data'] | null; emisorNombre: string }) {
  const navigate = useNavigate()
  const p = usePermisosFin()
  const { aviso } = useToast()
  const { confirm, prompt } = useConfirm()
  const accion = useAccionFactura()
  const borrar = useBorrarFactura()
  const restaurar = useRestaurar()
  const [cobro, setCobro] = useState<string | null>(null)
  const [proyecto, setProyecto] = useState(false)
  const [pdf, setPdf] = useState(false)
  const borrador = f.estado === 'borrador'
  const emitida = !borrador
  const carpeta = `/finanzas/facturas?em=${encodeURIComponent(f.emisor)}&tipo=ingreso${f.fecha ? `&mes=${f.fecha.slice(0, 7)}` : ''}`
  const titulo = f.numero ? `${f.tipo === 'rectificativa' ? 'Rectificativa' : 'Factura'} ${f.numero}` : f.tipo === 'rectificativa' ? 'Rectificativa (borrador)' : 'Borrador de factura'

  async function hacer(a: Parameters<typeof accion.mutateAsync>[0], ok: string) {
    try {
      const r = await accion.mutateAsync(a)
      aviso(ok)
      return r.factura
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido hacer.'), { tipo: 'error' })
      return null
    }
  }

  async function emitir() {
    const sig = await confirm({
      title: '¿Emitir la factura?',
      message: 'Se le asigna el siguiente número de la serie y desde ese momento ya no se puede modificar ni borrar: solo rectificar o anular.',
      okLabel: 'Emitir',
    })
    if (sig) await hacer({ id: f.id, accion: 'emitir' }, 'Factura emitida.')
  }

  async function estado(e: 'enviada' | 'vencida') {
    await hacer({ id: f.id, accion: 'estado', datos: { estado: e } }, e === 'enviada' ? 'Marcada como enviada.' : 'Marcada como vencida.')
  }

  async function rectificar() {
    const motivo = await prompt({ title: 'Rectificar la factura', message: 'Se crea una factura rectificativa en borrador (serie R) con las líneas en negativo. Ajústalas y emítela.', placeholder: 'Motivo (p. ej. descuento, error en el precio…)', okLabel: 'Crear rectificativa' })
    if (!motivo) return
    const r = await hacer({ id: f.id, accion: 'rectificar', datos: { motivo } }, 'Rectificativa creada en borrador.')
    if (r) navigate(`/finanzas/facturas/${r.id}/editar`)
  }

  async function anular() {
    const motivo = await prompt({
      title: `Anular ${f.numero ?? ''}`,
      message: 'Se emite una rectificativa por el total (serie R) y las dos quedan anuladas. No se puede deshacer.',
      placeholder: 'Motivo de la anulación',
      okLabel: 'Anular factura',
    })
    if (motivo) await hacer({ id: f.id, accion: 'anular', datos: { motivo } }, 'Factura anulada.')
  }

  async function duplicar() {
    const r = await hacer({ id: f.id, accion: 'duplicar' }, 'Copia creada en borrador.')
    if (r) navigate(`/finanzas/facturas/${r.id}/editar`)
  }

  async function eliminar() {
    const ok = await confirm({ title: '¿Borrar el borrador?', message: 'Va a la papelera. Un borrador no tiene número, así que no deja huecos.', danger: true })
    if (!ok) return
    try {
      const { papelera_id } = await borrar.mutateAsync(f.id)
      aviso('Borrador eliminado', { accion: { label: 'Deshacer', fn: () => restaurar.mutate(papelera_id) } })
      navigate(carpeta)
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido borrar.'), { tipo: 'error' })
    }
  }

  async function descargarPdf() {
    setPdf(true)
    try {
      const r = await pedirPdf(f.id)
      descargar(r.nombre, base64ABytes(r.base64), r.mime)
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido generar el PDF.'), { tipo: 'error' })
    } finally {
      setPdf(false)
    }
  }

  return (
    <div>
      <div className="fin-no-print">
        <PageHeader
          crumbs={[
            { label: 'Facturas', to: '/finanzas/facturas' },
            { label: emisorNombre, to: `/finanzas/facturas?em=${encodeURIComponent(f.emisor)}` },
            ...(f.project ? [{ label: f.project.nombre, to: `/finanzas/proyectos/${f.project.id}` }] : []),
            { label: f.numero ?? 'Borrador' },
          ]}
          title={titulo}
          actions={
            <>
              <Button variant="ghost" size="sm" icon={<Folder />} to={carpeta}>
                Facturas
              </Button>
              {borrador && p.emitir && (
                <Button variant="ghost" size="sm" icon={<Pencil />} to={`/finanzas/facturas/${f.id}/editar`}>
                  Editar
                </Button>
              )}
              <Button variant="ghost" size="sm" icon={<Download />} onClick={() => void descargarPdf()} loading={pdf} loadingText="Generando…">
                PDF
              </Button>
              <Button variant="ghost" size="sm" icon={<Printer />} onClick={() => window.print()}>
                Imprimir
              </Button>
              {borrador && p.emitir && (
                <Button size="sm" icon={<FileCheck2 />} onClick={() => void emitir()} loading={accion.isPending} loadingText="Emitiendo…">
                  Emitir
                </Button>
              )}
              {emitida && f.estado !== 'anulada' && p.cobrar && f.estado !== 'pagada' && (
                <Button size="sm" icon={<CircleDollarSign />} onClick={() => setCobro(hoyIso())}>
                  Marcar pagada
                </Button>
              )}
              {p.emitir && (
                <Menu
                  align="right"
                  label="Más acciones"
                  trigger={() => (
                    <span className="inline-flex size-[34px] items-center justify-center rounded-[9px] border border-line bg-card text-ink hover:bg-soft">
                      <MoreHorizontal className="size-4" />
                    </span>
                  )}
                >
                  {emitida && f.estado !== 'anulada' && (
                    <>
                      {f.estado === 'pagada' && p.cobrar && (
                        <MenuItem icon={<Undo2 />} onClick={() => void estado('enviada')}>
                          Marcar no cobrada
                        </MenuItem>
                      )}
                      {f.estado === 'pagada' && p.cobrar && (
                        <MenuItem icon={<CircleDollarSign />} onClick={() => setCobro(f.fecha_pago ?? hoyIso())}>
                          Cambiar fecha de cobro
                        </MenuItem>
                      )}
                      {f.estado === 'vencida' && (
                        <MenuItem color="#3b82f6" onClick={() => void estado('enviada')}>
                          Marcar enviada
                        </MenuItem>
                      )}
                      {f.estado === 'enviada' && (
                        <MenuItem color="#ef4444" onClick={() => void estado('vencida')}>
                          Marcar vencida
                        </MenuItem>
                      )}
                      <MenuSeparator />
                    </>
                  )}
                  <MenuItem icon={<FileText />} onClick={() => setProyecto(true)}>
                    Asignar proyecto…
                  </MenuItem>
                  <MenuItem icon={<Copy />} onClick={() => void duplicar()}>
                    Duplicar
                  </MenuItem>
                  {emitida && f.estado !== 'anulada' && f.tipo === 'normal' && (
                    <>
                      <MenuSeparator />
                      <MenuItem icon={<RotateCcw />} onClick={() => void rectificar()}>
                        Rectificar…
                      </MenuItem>
                      {f.estado !== 'pagada' && (
                        <MenuItem icon={<Ban />} danger onClick={() => void anular()}>
                          Anular…
                        </MenuItem>
                      )}
                    </>
                  )}
                  {borrador && p.borrar && f.numero === null && (
                    <>
                      <MenuSeparator />
                      <MenuItem icon={<Trash2 />} danger onClick={() => void eliminar()}>
                        Borrar borrador
                      </MenuItem>
                    </>
                  )}
                </Menu>
              )}
            </>
          }
        />
      </div>

      <div className="grid grid-cols-[minmax(0,680px)_minmax(0,300px)] items-start gap-6 max-[1100px]:grid-cols-1">
        <div className="overflow-hidden rounded-[18px] border border-line shadow-[0_14px_44px_rgba(0,0,0,.06)] dark:border-transparent">
          {hoja ? <FacturaHoja h={hoja} imprimible /> : <CargandoFin />}
        </div>
        <aside className="fin-no-print space-y-4">
          <Card padding="md">
            <CardHeader title="Estado" eyebrow />
            <div className="flex flex-wrap items-center gap-2">
              <EstadoPill estado={f.estado} />
              {f.tipo === 'rectificativa' && <span className="rounded-md bg-chip px-2 py-[3px] text-[11px] font-semibold text-[#5c616b] dark:text-ink">Rectificativa</span>}
            </div>
            <dl className="mt-4 space-y-2 text-[13px]">
              <Dato a="Total" b={<b className="text-ink-strong tabular-nums">{eurC(f.totales.total)}</b>} />
              <Dato a="Emisor" b={f.emisor_nombre} />
              {f.emitida_at && <Dato a="Emitida" b={fechaCorta(f.emitida_at)} />}
              {f.fecha_pago && <Dato a="Cobrada" b={fechaCorta(f.fecha_pago)} />}
              {f.project && (
                <Dato
                  a="Proyecto"
                  b={
                    <Link to={`/finanzas/proyectos/${f.project.id}`} className="inline-flex items-center gap-1.5 hover:underline">
                      <span className="size-2 rounded-full" style={{ backgroundColor: f.project.color }} />
                      {f.project.nombre}
                    </Link>
                  }
                />
              )}
              {f.personal && <Dato a="Contabilidad" b="Personal (fuera de empresa)" />}
            </dl>
            {borrador && <p className="mt-4 text-[12.5px] leading-[1.5] text-muted">Es un borrador: no tiene número ni valor fiscal hasta que se emite.</p>}
            {f.estado === 'anulada' && <p className="mt-4 text-[12.5px] leading-[1.5] text-muted">Anulada{f.anulada_motivo ? `: ${f.anulada_motivo}` : ''}.</p>}
          </Card>
          {(f.rectifica || f.rectificativas.length > 0) && (
            <Card padding="md">
              <CardHeader title="Rectificaciones" eyebrow />
              {f.rectifica && (
                <p className="text-[13px]">
                  Rectifica a{' '}
                  <Link className="font-semibold text-ink-strong hover:underline" to={`/finanzas/facturas/${f.rectifica.id}`}>
                    {f.rectifica.numero}
                  </Link>
                  {f.rect_motivo && <span className="text-muted"> · {f.rect_motivo}</span>}
                </p>
              )}
              {f.rectificativas.map((r) => (
                <Link key={r.id} to={`/finanzas/facturas/${r.id}`} className="mt-2 flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-[13px] hover:bg-soft">
                  <b className="font-semibold text-ink-strong">{r.numero ?? 'Borrador'}</b>
                  <EstadoPill estado={r.estado} size="sm" />
                  <span className="tabular-nums">{eurC(r.total)}</span>
                </Link>
              ))}
            </Card>
          )}
          {hoja?.integra === false && (
            <Notice tone="error" icon={<ShieldAlert />} title="El contenido no cuadra con la huella">
              Esta factura se ha modificado fuera del ERP después de emitirla.
            </Notice>
          )}
          {emitida && f.apunte_id && p.conta && (
            <p className="text-[12.5px] text-muted">
              Apuntada en caja ·{' '}
              <Link className="font-semibold text-ink hover:underline" to={`/finanzas/contabilidad?ambito=${encodeURIComponent(f.emisor)}&anio=${(f.fecha_pago ?? f.fecha).slice(0, 4)}`}>
                Ver en Contabilidad
              </Link>
            </p>
          )}
        </aside>
      </div>

      <Modal open={cobro !== null} onClose={() => setCobro(null)} title="Marcar como pagada" subtitle="Lo que mueve la contabilidad: el ingreso se apunta en la fecha de cobro." size="sm">
        <ModalBody>
          <Field label="Fecha de cobro">
            <DateInput value={cobro} onChange={(v) => setCobro(v ?? hoyIso())} />
          </Field>
        </ModalBody>
        <ModalFooter>
          <Button variant="ghost" onClick={() => setCobro(null)}>
            Cancelar
          </Button>
          <Button
            loading={accion.isPending}
            loadingText="Guardando…"
            onClick={async () => {
              const r = await hacer({ id: f.id, accion: 'estado', datos: { estado: 'pagada', fecha_pago: cobro } }, 'Factura marcada como pagada.')
              if (r) setCobro(null)
            }}
          >
            Marcar pagada
          </Button>
        </ModalFooter>
      </Modal>
      <AsignarProyectoModal
        open={proyecto}
        onClose={() => setProyecto(false)}
        clientId={f.client_id}
        actual={f.project}
        onGuardar={async (cuerpo) => {
          const r = await hacer({ id: f.id, accion: 'proyecto', datos: cuerpo }, 'Proyecto asignado.')
          if (r) setProyecto(false)
        }}
      />
    </div>
  )
}

function Dato({ a, b }: { a: string; b: ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className="text-muted">{a}</dt>
      <dd className="min-w-0 text-right text-ink">{b}</dd>
    </div>
  )
}
