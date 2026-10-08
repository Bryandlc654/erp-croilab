import { useState, type MouseEvent } from 'react'
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronRight, Copy, Euro, Eye, FileText, FolderOpen, MoreHorizontal, Pencil, Plus, Printer, Trash2, Upload, Download, Layers, Ban, CircleDollarSign, Send } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import EmptyState from '../../../shared/ui/EmptyState'
import Menu, { MenuItem, MenuPanel, MenuSeparator } from '../../../shared/ui/Menu'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import { Lightbox, WindowDropOverlay, type Adjunto } from '../../../shared/ui/rich'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useContextMenu } from '../../../shared/ui/useContextMenu'
import { useToast } from '../../../shared/ui/useToast'
import { fechaCorta, mesLabel } from '../../../shared/lib/formato'
import { mensajeError, urlArchivo, useAccionFactura, useBorrarDocumento, useBorrarFactura, useHubs, useLista, useMeses, useRestaurar, useTipos } from '../api'
import AsignarProyectoModal from '../components/AsignarProyectoModal'
import CargandoFin from '../components/Cargando'
import { CarpetaMes, HubCard, TituloMigas } from '../components/Carpetas'
import DocumentoPanel from '../components/DocumentoPanel'
import EstadoPill from '../components/EstadoPill'
import { COLOR_GASTO, COLOR_INGRESO } from '../lib/estados'
import { eurC } from '../lib/importes'
import { esMes, hoyIso } from '../lib/periodos'
import { usePermisosFin } from '../lib/permisos'
import type { Documento, FacturaFila } from '../schemas'

/* Facturas (facturas.php): navegación por niveles con la URL
     ?            hubs por emisor
     ?em=k        Ingresos / Gastos
     &tipo=t      carpetas por mes
     &mes=AAAA-MM lista del mes (facturas + documentos subidos)
   ?cli=ID (enlace de la ficha de cliente) lleva a «Por cliente». */
export default function FacturasPage() {
  const [sp] = useSearchParams()
  const p = usePermisosFin()
  const cli = sp.get('cli')
  if (cli) return <Navigate to={`/finanzas/clientes?cli=${encodeURIComponent(cli)}${sp.get('mes') ? `&mes=${sp.get('mes')}` : ''}`} replace />
  if (!p.ver) return <Notice tone="error">No tienes acceso a las facturas.</Notice>
  const em = sp.get('em') ?? ''
  const tipo = sp.get('tipo')
  const mes = sp.get('mes')
  if (!em) return <Hubs />
  if (tipo !== 'ingreso' && tipo !== 'gasto') return <Tipos emisor={em} />
  return <Meses key={`${em}-${tipo}`} emisor={em} tipo={tipo} mes={esMes(mes) ? mes : null} />
}

function NuevaFactura({ em }: { em?: string }) {
  const p = usePermisosFin()
  if (!p.emitir) return null
  return (
    <Button icon={<Plus />} to={`/finanzas/facturas/nueva${em ? `?em=${encodeURIComponent(em)}` : ''}`}>
      Nueva factura
    </Button>
  )
}

/* ---------- Nivel 1: hubs por emisor ---------- */

function Hubs() {
  const { data, error, isLoading } = useHubs()
  return (
    <div>
      <PageHeader
        title="Facturas"
        actions={
          <>
            <Button variant="ghost" icon={<FolderOpen />} to="/finanzas/clientes">
              Por cliente
            </Button>
            <NuevaFactura />
          </>
        }
      />
      {error && <Notice tone="error">{mensajeError(error, 'No se han podido cargar las facturas.')}</Notice>}
      {isLoading && <CargandoFin />}
      <div className="grid grid-cols-2 gap-[22px] max-[900px]:grid-cols-1 max-sm:gap-3">
        {data?.items.map((h) => (
          <HubCard
            key={h.clave}
            to={`/finanzas/facturas?em=${encodeURIComponent(h.clave)}`}
            icono={<FileText />}
            color="#1f232a"
            titulo={h.nombre}
            sub={`${h.n} factura${h.n === 1 ? '' : 's'}`}
            stats={[
              { label: 'Cobrado', valor: eurC(h.cobrado) },
              { label: 'Pendiente', valor: eurC(h.pendiente) },
            ]}
          />
        ))}
      </div>
    </div>
  )
}

/* ---------- Nivel 2: ingresos / gastos ---------- */

function Tipos({ emisor }: { emisor: string }) {
  const { data, error, isLoading } = useTipos(emisor)
  const nombre = data?.emisor.nombre ?? emisor
  return (
    <div>
      <PageHeader title={<TituloMigas partes={[{ label: 'Facturas', to: '/finanzas/facturas' }, { label: nombre }]} />} actions={<NuevaFactura em={emisor} />} />
      {error && <Notice tone="error">{mensajeError(error, 'No se ha podido cargar.')}</Notice>}
      {isLoading && <CargandoFin />}
      {data && (
        <div className="grid grid-cols-2 gap-[22px] max-[900px]:grid-cols-1 max-sm:gap-3">
          <HubCard
            to={`/finanzas/facturas?em=${encodeURIComponent(emisor)}&tipo=ingreso`}
            icono={<FileText />}
            color={COLOR_INGRESO}
            titulo="Ingresos"
            sub={`${data.ingreso.n} documento${data.ingreso.n === 1 ? '' : 's'}`}
            stats={[{ label: 'Total', valor: eurC(data.ingreso.total) }]}
          />
          <HubCard
            to={`/finanzas/facturas?em=${encodeURIComponent(emisor)}&tipo=gasto`}
            icono={<Euro />}
            color={COLOR_GASTO}
            titulo="Gastos"
            sub={`${data.gasto.n} documento${data.gasto.n === 1 ? '' : 's'}`}
            stats={[{ label: 'Total', valor: eurC(data.gasto.total) }]}
          />
        </div>
      )}
    </div>
  )
}

/* ---------- Niveles 3 y 4: carpetas por mes y lista del mes ---------- */

type Panel = { doc: Documento | null; archivo: File | null } | null

function Meses({ emisor, tipo, mes }: { emisor: string; tipo: 'ingreso' | 'gasto'; mes: string | null }) {
  const p = usePermisosFin()
  const meses = useMeses(emisor, tipo)
  const [panel, setPanel] = useState<Panel>(null)
  const nombre = meses.data?.emisor.nombre ?? emisor
  const etiqueta = tipo === 'ingreso' ? 'Ingresos' : 'Gastos'
  const base = `/finanzas/facturas?em=${encodeURIComponent(emisor)}&tipo=${tipo}`
  const migas = [
    { label: 'Facturas', to: '/finanzas/facturas' },
    { label: nombre, to: `/finanzas/facturas?em=${encodeURIComponent(emisor)}` },
    { label: etiqueta, to: mes ? base : undefined },
    ...(mes ? [{ label: mesLabel(mes) }] : []),
  ]
  return (
    <div>
      <PageHeader
        title={<TituloMigas partes={migas} />}
        actions={
          <>
            {p.contaEditar && (
              <Button variant={tipo === 'gasto' ? 'primary' : 'ghost'} icon={<Upload />} onClick={() => setPanel({ doc: null, archivo: null })}>
                Subir factura
              </Button>
            )}
            {tipo === 'ingreso' && <NuevaFactura em={emisor} />}
          </>
        }
      />
      {p.contaEditar && (
        <WindowDropOverlay
          enabled={!panel}
          title="Suelta el archivo para subir la factura"
          hint={`PDF o imagen · ${etiqueta} de ${nombre}`}
          onFiles={(fs) => fs[0] && setPanel({ doc: null, archivo: fs[0] })}
        />
      )}
      {panel && (
        <DocumentoPanel
          key={`${panel.doc?.id ?? 'nuevo'}-${panel.archivo?.name ?? ''}`}
          emisor={emisor}
          emisorNombre={nombre}
          tipo={panel.doc?.tipo ?? tipo}
          doc={panel.doc}
          archivoInicial={panel.archivo}
          onClose={() => setPanel(null)}
        />
      )}
      {mes ? (
        <ListaMes emisor={emisor} tipo={tipo} mes={mes} onEditarDoc={(d) => setPanel({ doc: d, archivo: null })} />
      ) : (
        <>
          {meses.error && <Notice tone="error">{mensajeError(meses.error, 'No se ha podido cargar.')}</Notice>}
          {meses.isLoading && <CargandoFin />}
          {meses.data && meses.data.items.length === 0 && (
            <EmptyState variant="card" title={`Aún no hay ${tipo === 'ingreso' ? 'ingresos' : 'gastos'} de ${nombre}.`} text={tipo === 'gasto' ? 'Sube la primera factura de gasto o arrástrala a la ventana.' : undefined} />
          )}
          <div className="grid grid-cols-[repeat(auto-fill,minmax(210px,1fr))] gap-[18px] max-sm:grid-cols-2 max-sm:gap-3">
            {meses.data?.items.map((m) => (
              <CarpetaMes key={m.mes} to={`${base}&mes=${m.mes}`} mes={m.mes} n={m.n} total={m.total} neto={m.neto} />
            ))}
          </div>
        </>
      )}
    </div>
  )
}

const FILA = 'grid grid-cols-[110px_minmax(0,1fr)_120px_120px_100px_40px] items-center gap-2.5 px-[18px] max-[900px]:grid-cols-[minmax(0,1fr)_auto_40px] max-[900px]:gap-x-3'

function ListaMes({ emisor, tipo, mes, onEditarDoc }: { emisor: string; tipo: 'ingreso' | 'gasto'; mes: string; onEditarDoc: (d: Documento) => void }) {
  const navigate = useNavigate()
  const p = usePermisosFin()
  const { aviso } = useToast()
  const { confirm, prompt } = useConfirm()
  const { data, error, isLoading } = useLista(emisor, tipo, mes)
  const accion = useAccionFactura()
  const borrarF = useBorrarFactura()
  const borrarD = useBorrarDocumento()
  const restaurar = useRestaurar()
  const cmF = useContextMenu<FacturaFila>()
  const cmD = useContextMenu<Documento>()
  const [proyecto, setProyecto] = useState<FacturaFila | null>(null)
  const [visor, setVisor] = useState<number | null>(null)

  const imagenes: Adjunto[] = (data?.documentos ?? []).filter((d) => d.archivo && d.tipo_archivo === 'imagen').map((d) => ({ id: d.id, nombre: d.nombre_archivo, url: urlArchivo(d.archivo!) }))

  function previsualizar(d: Documento) {
    if (!d.archivo) return
    if (d.tipo_archivo === 'pdf') window.open(urlArchivo(d.archivo), '_blank', 'noopener')
    else setVisor(imagenes.findIndex((i) => i.id === d.id))
  }

  async function hacer(f: FacturaFila, a: 'estado' | 'duplicar' | 'anular', datos: unknown, ok: string) {
    try {
      const r = await accion.mutateAsync({ id: f.id, accion: a, datos })
      aviso(ok)
      if (a === 'duplicar') navigate(`/finanzas/facturas/${r.factura.id}/editar`)
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido hacer.'), { tipo: 'error' })
    }
  }

  async function borrarFactura(f: FacturaFila) {
    if (!(await confirm({ title: '¿Borrar el borrador?', message: 'Va a la papelera. Un borrador no tiene número: no deja huecos.', danger: true }))) return
    try {
      const { papelera_id } = await borrarF.mutateAsync(f.id)
      aviso('Borrador eliminado', { accion: { label: 'Deshacer', fn: () => restaurar.mutate(papelera_id) } })
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido borrar.'), { tipo: 'error' })
    }
  }

  async function anular(f: FacturaFila) {
    const motivo = await prompt({ title: `Anular ${f.numero ?? ''}`, message: 'Se emite una rectificativa por el total y las dos quedan anuladas.', placeholder: 'Motivo de la anulación', okLabel: 'Anular factura' })
    if (motivo) await hacer(f, 'anular', { motivo }, 'Factura anulada.')
  }

  async function borrarDoc(d: Documento) {
    if (!(await confirm({ title: '¿Borrar este documento?', message: 'También se quita de contabilidad. Va a la papelera.', danger: true }))) return
    try {
      const { papelera_id } = await borrarD.mutateAsync(d.id)
      aviso('Documento eliminado', { accion: { label: 'Deshacer', fn: () => restaurar.mutate(papelera_id) } })
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido borrar.'), { tipo: 'error' })
    }
  }

  if (error) return <Notice tone="error">{mensajeError(error, 'No se ha podido cargar el mes.')}</Notice>
  if (isLoading || !data) return <CargandoFin />
  const vacio = data.facturas.length === 0 && data.documentos.length === 0
  const menuDoc = (d: Documento) => (
    <>
      {p.contaEditar && (
        <MenuItem icon={<Pencil />} onClick={() => onEditarDoc(d)}>
          Editar
        </MenuItem>
      )}
      {d.archivo && (
        <>
          <MenuItem icon={<Eye />} onClick={() => previsualizar(d)}>
            Vista previa
          </MenuItem>
          <MenuItem icon={<Download />} onClick={() => window.open(urlArchivo(d.archivo!, true), '_blank', 'noopener')}>
            Descargar
          </MenuItem>
        </>
      )}
      {p.contaEditar && (
        <>
          <MenuSeparator />
          <MenuItem icon={<Trash2 />} danger onClick={() => void borrarDoc(d)}>
            Borrar documento
          </MenuItem>
        </>
      )}
    </>
  )
  const f = cmF.dato
  return (
    <div className="overflow-hidden rounded-2xl border border-line bg-card">
      <div className={`${FILA} border-b border-line bg-head py-2.5 text-[10.5px] font-bold tracking-[.5px] text-muted uppercase max-[900px]:hidden`}>
        <span>Documento</span>
        <span>Detalle</span>
        <span>Fecha</span>
        <span className="text-right">Importe</span>
        <span />
        <span />
      </div>
      {vacio && <p className="px-6 py-10 text-center text-[13.5px] text-muted">No hay {tipo === 'ingreso' ? 'ingresos' : 'gastos'} en {mesLabel(mes)}.</p>}
      {data.facturas.map((x) => (
        <div
          key={`f${x.id}`}
          role="link"
          tabIndex={0}
          onClick={() => navigate(`/finanzas/facturas/${x.id}`)}
          onKeyDown={(e) => e.key === 'Enter' && navigate(`/finanzas/facturas/${x.id}`)}
          onContextMenu={(e: MouseEvent) => p.emitir && cmF.onContextMenu(e, x)}
          className={`${FILA} cursor-pointer border-b border-line py-[13px] transition-colors last:border-b-0 hover:bg-hover-row`}
        >
          <b className="truncate text-[13px] font-bold text-ink-strong max-[900px]:col-span-full">{x.numero ?? 'Borrador'}</b>
          <span className="flex min-w-0 items-center gap-2">
            <Avatar nombre={x.cliente_nombre || '?'} size={26} />
            <span className="min-w-0 truncate text-[13.5px] text-ink">{x.cliente_nombre || '—'}</span>
            <EstadoPill estado={x.estado} size="sm" />
            {x.tipo === 'rectificativa' && <span className="rounded-md bg-chip px-1.5 py-px text-[10px] font-bold text-[#5c616b] dark:text-ink">RECT.</span>}
          </span>
          <span className="text-[13px] text-muted max-[900px]:hidden">{fechaCorta(x.fecha)}</span>
          <span className={`text-right text-[13.5px] font-semibold tabular-nums ${x.estado === 'borrador' ? 'text-muted' : 'text-ink-strong'}`}>{eurC(x.total)}</span>
          <span className="max-[900px]:hidden" />
          <ChevronRight className="size-4 justify-self-end text-label" />
        </div>
      ))}
      {data.documentos.map((d) => (
        <div
          key={`d${d.id}`}
          role={d.archivo ? 'button' : undefined}
          tabIndex={d.archivo ? 0 : undefined}
          onClick={() => previsualizar(d)}
          onKeyDown={(e) => e.key === 'Enter' && previsualizar(d)}
          onContextMenu={(e: MouseEvent) => cmD.onContextMenu(e, d)}
          className={`${FILA} border-b border-line py-[13px] transition-colors last:border-b-0 hover:bg-hover-row ${d.archivo ? 'cursor-pointer' : ''}`}
        >
          <span className="flex items-center gap-1.5 text-[12.5px] font-semibold text-muted max-[900px]:col-span-full">
            <Upload className="size-3.5" /> Subida
          </span>
          <span className="min-w-0">
            <span className="block truncate text-[13.5px] text-ink">{d.concepto || (d.tipo === 'gasto' ? 'Gasto' : 'Ingreso')}</span>
            <span className="flex flex-wrap items-center gap-1.5 text-[12px] text-muted">
              {d.proveedor && <span className="truncate">{d.proveedor}</span>}
              {d.efectivo && <span className="rounded-md bg-[#e4f6ec] px-1.5 py-px text-[10px] font-bold text-[#12854a] dark:bg-ok-bg dark:text-ok">EFECTIVO</span>}
              {d.personal && <span className="rounded-md bg-[#e8effc] px-1.5 py-px text-[10px] font-bold text-[#2f6df6] dark:bg-[#1b2333] dark:text-[#a9c1ea]">{d.tipo === 'gasto' ? 'DEDUCIBLE' : 'PERSONAL'}</span>}
              {d.project && (
                <span className="inline-flex items-center gap-1">
                  <Layers className="size-3" />
                  {d.project.nombre}
                </span>
              )}
            </span>
          </span>
          <span className="text-[13px] text-muted max-[900px]:hidden">{fechaCorta(d.fecha)}</span>
          <span className="text-right text-[13.5px] font-semibold text-ink-strong tabular-nums">{eurC(d.importe)}</span>
          <span className="text-[12px] text-muted max-[900px]:hidden">{d.tipo_archivo === 'pdf' ? 'PDF' : d.tipo_archivo === 'imagen' ? 'Imagen' : 'sin archivo'}</span>
          <Menu
            align="right"
            label="Acciones del documento"
            className="justify-self-end"
            trigger={() => (
              <span className="flex size-[30px] items-center justify-center rounded-md text-label hover:bg-chip">
                <MoreHorizontal className="size-[19px]" />
              </span>
            )}
          >
            {menuDoc(d)}
          </Menu>
        </div>
      ))}

      <MenuPanel {...cmF.panel} label="Acciones de la factura">
        {f && (
          <>
            <MenuItem icon={<Printer />} onClick={() => navigate(`/finanzas/facturas/${f.id}`)}>
              Ver / Imprimir
            </MenuItem>
            {f.estado === 'borrador' && (
              <MenuItem icon={<Pencil />} onClick={() => navigate(`/finanzas/facturas/${f.id}/editar`)}>
                Editar
              </MenuItem>
            )}
            <MenuItem icon={<Layers />} onClick={() => setProyecto(f)}>
              Asignar proyecto…
            </MenuItem>
            <MenuItem icon={<Copy />} onClick={() => void hacer(f, 'duplicar', {}, 'Copia creada en borrador.')}>
              Duplicar
            </MenuItem>
            {f.estado !== 'borrador' && f.estado !== 'anulada' && (
              <>
                <MenuSeparator />
                {f.estado !== 'pagada' && p.cobrar && (
                  <MenuItem icon={<CircleDollarSign />} onClick={() => void hacer(f, 'estado', { estado: 'pagada', fecha_pago: hoyIso() }, 'Factura marcada como pagada.')}>
                    Marcar pagada
                  </MenuItem>
                )}
                {(f.estado === 'vencida' || (f.estado === 'pagada' && p.cobrar)) && (
                  <MenuItem icon={<Send />} onClick={() => void hacer(f, 'estado', { estado: 'enviada' }, 'Marcada como enviada.')}>
                    Marcar enviada
                  </MenuItem>
                )}
              </>
            )}
            {f.estado === 'borrador' && p.borrar && f.numero === null && (
              <>
                <MenuSeparator />
                <MenuItem icon={<Trash2 />} danger onClick={() => void borrarFactura(f)}>
                  Borrar borrador
                </MenuItem>
              </>
            )}
            {(f.estado === 'enviada' || f.estado === 'vencida') && f.tipo === 'normal' && (
              <>
                <MenuSeparator />
                <MenuItem icon={<Ban />} danger onClick={() => void anular(f)}>
                  Anular factura…
                </MenuItem>
              </>
            )}
          </>
        )}
      </MenuPanel>
      <MenuPanel {...cmD.panel} label="Acciones del documento">
        {cmD.dato && menuDoc(cmD.dato)}
      </MenuPanel>
      <AsignarProyectoModal
        open={proyecto !== null}
        onClose={() => setProyecto(null)}
        clientId={proyecto?.client_id ?? null}
        actual={proyecto?.project ?? null}
        onGuardar={async (cuerpo) => {
          if (!proyecto) return
          try {
            await accion.mutateAsync({ id: proyecto.id, accion: 'proyecto', datos: cuerpo })
            aviso('Proyecto asignado.')
            setProyecto(null)
          } catch (e) {
            aviso(mensajeError(e, 'No se ha podido asignar.'), { tipo: 'error' })
          }
        }}
      />
      <Lightbox items={imagenes} index={visor} onClose={() => setVisor(null)} onIndexChange={setVisor} />
    </div>
  )
}
