import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, ChartLine, Clock, Euro, FileText, Link2, Link2Off, MoreHorizontal, Plus, Search, TrendingUp } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import { DateInput } from '../../../shared/ui/DatePicker'
import IconButton from '../../../shared/ui/IconButton'
import Menu from '../../../shared/ui/Menu'
import Modal, { ModalBody } from '../../../shared/ui/Modal'
import Notice from '../../../shared/ui/Notice'
import Segmented from '../../../shared/ui/Segmented'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { fechaCorta } from '../../../shared/lib/formato'
import { useDebounced } from '../../../shared/lib/useDebounced'
import { mensajeError, urlArchivo, useEmisores, useFichaProyecto, useVinculables } from '../api'
import CargandoFin from '../components/Cargando'
import EstadoPill from '../components/EstadoPill'
import KpiIcono from '../components/KpiIcono'
import ProyectoMenuItems from '../components/ProyectoMenu'
import { eurC, leer } from '../lib/importes'
import { hoyIso } from '../lib/periodos'
import { usePermisosFin } from '../lib/permisos'
import { useOpsProyecto } from '../lib/useOpsProyecto'

/* Ficha de un proyecto (proyecto.php): balance, movimientos de caja y facturas vinculadas. */
export default function ProyectoPage() {
  const id = Number(useParams().id ?? 0)
  const navigate = useNavigate()
  const p = usePermisosFin()
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const [sp, setSp] = useSearchParams()
  const anio = sp.get('y') || String(new Date().getFullYear())
  const { data, error, isLoading } = useFichaProyecto(id, anio)
  const ops = useOpsProyecto()
  const em = useEmisores()
  const [nuevo, setNuevo] = useState<'ingreso' | 'gasto' | null>(null)
  const [vincular, setVincular] = useState(false)
  if (!p.proyectos) return <Notice tone="error">No tienes acceso a los proyectos.</Notice>
  if (error) return <Notice tone="error">{mensajeError(error, 'No se ha podido cargar el proyecto.')}</Notice>
  if (isLoading || !data) return <CargandoFin />
  const pr = data.proyecto

  async function desvincularMov(acc: number) {
    if (!(await confirm({ title: '¿Desvincular este movimiento del proyecto?', message: 'El apunte de caja se conserva, solo deja de contar aquí.', okLabel: 'Desvincular' }))) return
    ops.acc.desvincularMov.mutate({ id, acc }, { onError: (e) => aviso(mensajeError(e, 'No se ha podido desvincular.'), { tipo: 'error' }) })
  }

  async function desvincularFactura(inv: number) {
    if (!(await confirm({ title: '¿Desvincular la factura?', message: 'La factura no cambia; solo deja de contar en este proyecto.', okLabel: 'Desvincular' }))) return
    ops.acc.factura.mutate({ id, inv, vincular: false }, { onError: (e) => aviso(mensajeError(e, 'No se ha podido desvincular.'), { tipo: 'error' }) })
  }

  return (
    <div>
      <Link to="/finanzas/proyectos" className="mb-4 inline-flex items-center gap-1.5 text-[12.5px] text-muted hover:text-ink">
        <ArrowLeft className="size-3.5" /> Proyectos
      </Link>
      <header className="mb-[22px] flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2.5 text-[26px] leading-[1.2] font-semibold tracking-[-.5px] text-ink-strong max-sm:text-[23px]">
            <span className="size-3 rounded-full" style={{ backgroundColor: pr.color }} />
            {pr.nombre}
            {!pr.activo && <span className="rounded-md bg-chip px-2 py-0.5 text-[11px] font-semibold text-muted">archivado</span>}
          </h1>
          <p className="mt-2 max-w-[75ch] text-[14px] text-muted">
            Lo que ha entrado y salido de la caja con este proyecto{pr.cliente ? ` (cliente: ${pr.cliente})` : ''}. Las facturas sin cobrar se ven aparte como pendiente.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Segmented
            variant="pill"
            value={anio}
            onChange={(v) => setSp({ y: v }, { replace: true })}
            items={[...data.anios.map((a) => ({ value: String(a), label: String(a) })), { value: 'all', label: 'Histórico' }]}
            aria-label="Año"
          />
          {p.escribe && (
            <Menu align="right" label="Opciones del proyecto" trigger={() => <span className="flex size-[34px] items-center justify-center rounded-[9px] border border-line bg-card hover:bg-soft"><MoreHorizontal className="size-4" /></span>}>
              <ProyectoMenuItems
                activo={pr.activo}
                puede
                onRenombrar={() => void ops.renombrar(pr)}
                onColor={(c) => void ops.color(pr, c)}
                onArchivar={() => void ops.archivar(pr)}
                onBorrar={async () => {
                  if (await ops.borrar(pr)) navigate('/finanzas/proyectos')
                }}
              />
            </Menu>
          )}
        </div>
      </header>

      <div className="mb-5 grid grid-cols-4 gap-5 max-[900px]:grid-cols-2 max-sm:gap-3 max-[420px]:grid-cols-1">
        <KpiIcono icono={<TrendingUp className="text-[#12854a]" />} label="Ingresos" valor={eurC(data.kpis.ing)} />
        <KpiIcono icono={<Euro className="text-[#e5484d]" />} label="Costes" valor={eurC(data.kpis.gas)} />
        <KpiIcono icono={<ChartLine />} label="Balance" valor={eurC(data.kpis.ben)} tono={data.kpis.ben >= 0 ? 'ok' : 'mal'} />
        <KpiIcono icono={<Clock />} label="Pendiente" valor={eurC(data.kpis.pendiente)} />
      </div>

      <section className="mb-5 rounded-[20px] border border-line bg-card px-[26px] py-6 max-sm:px-4">
        <header className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-[16px] font-semibold text-ink-strong">Movimientos de caja</h3>
          {p.contaEditar && (
            <div className="flex gap-2">
              <Button variant="ghost" size="sm" icon={<Plus />} onClick={() => setNuevo('ingreso')}>
                Añadir ingreso
              </Button>
              <Button variant="ghost" size="sm" icon={<Plus />} onClick={() => setNuevo('gasto')}>
                Añadir gasto
              </Button>
            </div>
          )}
        </header>
        {nuevo && <NuevoMovimiento key={nuevo} id={id} tipo={nuevo} onClose={() => setNuevo(null)} />}
        {data.movimientos.length === 0 ? (
          <div className="py-10 text-center">
            <b className="block text-[15px] font-semibold text-ink-strong">Sin movimientos</b>
            <p className="mt-1 text-[13px] text-muted">Añade un ingreso o un gasto, o vincula una factura cobrada.</p>
          </div>
        ) : (
          <ul>
            {data.movimientos.map((m) => (
              <li key={m.id} className="flex items-center gap-3 border-b border-line2 py-3 last:border-b-0 max-sm:flex-wrap">
                <span className="w-[72px] shrink-0 text-[12.5px] text-muted">{fechaCorta(m.fecha)}</span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13.5px] text-ink-strong">{m.concepto}</span>
                  {m.factura ? (
                    <Link to={`/finanzas/facturas/${m.factura.id}`} className="text-[11.5px] font-semibold text-[#2f6df6] hover:underline">
                      Factura nº {m.factura.numero ?? 'borrador'}
                    </Link>
                  ) : m.documento?.filename ? (
                    <a href={urlArchivo(m.documento.filename, true)} className="text-[11.5px] font-semibold text-[#2f6df6] hover:underline">
                      Documento
                    </a>
                  ) : (
                    <span className="text-[11.5px] text-muted">Manual</span>
                  )}
                </span>
                <span className="rounded-md bg-chip px-2 py-px text-[11px] font-semibold text-[#5c616b] dark:text-ink">{m.ambito === 'empresa' ? 'Empresa' : (em.data?.items.find((e) => e.clave === m.ambito)?.nombre ?? m.ambito)}</span>
                <b className={`w-[110px] text-right text-[13.5px] tabular-nums ${m.tipo === 'ingreso' ? 'text-[#12854a] dark:text-ok' : 'text-[#e5484d] dark:text-danger'}`}>
                  {m.tipo === 'ingreso' ? '+' : '−'}
                  {eurC(m.importe)}
                </b>
                {p.contaEditar && <IconButton label="Desvincular" icon={<Link2Off />} onClick={() => void desvincularMov(m.id)} />}
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="rounded-[20px] border border-line bg-card px-[26px] py-6 max-sm:px-4">
        <header className="mb-3 flex items-center justify-between gap-2">
          <h3 className="text-[16px] font-semibold text-ink-strong">Facturas vinculadas</h3>
          {p.emitir && (
            <Button variant="ghost" size="sm" icon={<Link2 />} onClick={() => setVincular(true)}>
              Vincular factura
            </Button>
          )}
        </header>
        {data.facturas.length === 0 ? (
          <p className="py-6 text-center text-[13px] text-muted">Sin facturas vinculadas.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px] text-[13.5px]">
              <thead className="text-left text-[11px] font-semibold tracking-[.5px] text-muted uppercase">
                <tr className="border-b border-line">
                  <th className="px-2 py-2">Nº</th>
                  <th className="px-2 py-2">Cliente</th>
                  <th className="px-2 py-2">Fecha</th>
                  <th className="px-2 py-2">Estado</th>
                  <th className="px-2 py-2 text-right">Total</th>
                  <th className="px-2 py-2">Situación</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {data.facturas.map((f) => (
                  <tr key={f.id} className="border-b border-line2">
                    <td className="px-2 py-2.5 font-bold text-ink-strong">
                      <Link to={`/finanzas/facturas/${f.id}`} className="hover:underline">
                        {f.numero ?? 'Borrador'}
                      </Link>
                    </td>
                    <td className="px-2 py-2.5">{f.cliente_nombre}</td>
                    <td className="px-2 py-2.5 text-muted">{fechaCorta(f.fecha)}</td>
                    <td className="px-2 py-2.5">
                      <EstadoPill estado={f.estado} size="sm" />
                    </td>
                    <td className="px-2 py-2.5 text-right tabular-nums">{eurC(f.total)}</td>
                    <td className="px-2 py-2.5 text-[12.5px] text-muted">{f.estado === 'pagada' ? 'En caja' : 'Pendiente'}</td>
                    <td className="text-right">{p.emitir && <IconButton label="Desvincular" icon={<Link2Off />} onClick={() => void desvincularFactura(f.id)} />}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      <Modal open={vincular} onClose={() => setVincular(false)} title="Vincular factura" size="lg">
        {vincular && <Vincular id={id} />}
      </Modal>
    </div>
  )
}

function NuevoMovimiento({ id, tipo, onClose }: { id: number; tipo: 'ingreso' | 'gasto'; onClose: () => void }) {
  const { aviso } = useToast()
  const ops = useOpsProyecto()
  const [concepto, setConcepto] = useState('')
  const [importe, setImporte] = useState('')
  const [fecha, setFecha] = useState<string>(hoyIso)
  async function guardar() {
    try {
      await ops.acc.movimiento.mutateAsync({ id, datos: { tipo, concepto, importe: leer(importe), fecha } })
      aviso(tipo === 'ingreso' ? 'Ingreso añadido.' : 'Gasto añadido.')
      onClose()
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido añadir.'), { tipo: 'error' })
    }
  }
  return (
    <div className="mb-3 grid grid-cols-[minmax(0,1fr)_130px_150px_auto_auto] items-center gap-2 rounded-xl bg-soft p-3 max-[800px]:grid-cols-2">
      <TextInput autoFocus value={concepto} onChange={(e) => setConcepto(e.target.value)} placeholder={tipo === 'ingreso' ? 'Concepto del ingreso…' : 'Concepto del gasto…'} aria-label="Concepto" className="max-[800px]:col-span-2" />
      <TextInput value={importe} onChange={(e) => setImporte(e.target.value)} placeholder="0,00" inputMode="decimal" unit="€" aria-label="Importe" />
      <DateInput value={fecha} onChange={(v) => setFecha(v ?? hoyIso())} aria-label="Fecha" />
      <Button size="sm" onClick={() => void guardar()} loading={ops.acc.movimiento.isPending} loadingText="Guardando…">
        Guardar
      </Button>
      <Button size="sm" variant="ghost" onClick={onClose}>
        Cancelar
      </Button>
    </div>
  )
}

function Vincular({ id }: { id: number }) {
  const { aviso } = useToast()
  const ops = useOpsProyecto()
  const [q, setQ] = useState('')
  const qd = useDebounced(q.trim(), 200)
  const { data, isLoading } = useVinculables(id, qd, true)
  return (
    <ModalBody>
      <TextInput autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar por número o cliente…" leftIcon={<Search />} aria-label="Buscar factura" />
      {isLoading && <CargandoFin />}
      <ul className="max-h-[50vh] overflow-y-auto">
        {data?.items.length === 0 && <li className="py-6 text-center text-[13px] text-muted">No hay facturas que vincular.</li>}
        {data?.items.map((f) => (
          <li key={f.id} className="flex items-center gap-3 border-b border-line2 py-2.5 last:border-b-0">
            <FileText className="size-4 text-label" />
            <b className="w-[110px] truncate text-[13px] text-ink-strong">{f.numero ?? 'Borrador'}</b>
            <span className="min-w-0 flex-1 truncate text-[13px]">{f.cliente_nombre}</span>
            <EstadoPill estado={f.estado} size="sm" />
            <span className="w-[90px] text-right text-[13px] tabular-nums">{eurC(f.total)}</span>
            {f.vinculada ? (
              <span className="w-[100px] text-right text-[12px] text-muted">Ya vinculada</span>
            ) : (
              <Button
                size="sm"
                variant="ghost"
                icon={<Plus />}
                className="w-[100px]"
                onClick={() => ops.acc.factura.mutate({ id, inv: f.id, vincular: true }, { onSuccess: () => aviso('Factura vinculada.'), onError: (e) => aviso(mensajeError(e, 'No se ha podido vincular.'), { tipo: 'error' }) })}
              >
                Vincular
              </Button>
            )}
          </li>
        ))}
      </ul>
    </ModalBody>
  )
}
