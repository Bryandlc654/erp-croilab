import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronRight, FileText, IdCard, Plus, Users } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import Collapse from '../../../shared/ui/Collapse'
import EmptyState from '../../../shared/ui/EmptyState'
import Field from '../../../shared/ui/Field'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import Segmented from '../../../shared/ui/Segmented'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { fechaCorta, mesLabel } from '../../../shared/lib/formato'
import { mensajeError, useClienteMeses, useClientesFacturacion, useEmisores, useFacturas, useGuardarClienteFacturacion, usePorCliente } from '../api'
import CargandoFin from '../components/Cargando'
import { CarpetaMes, TituloMigas } from '../components/Carpetas'
import EstadoPill from '../components/EstadoPill'
import { eurC } from '../lib/importes'
import { esMes } from '../lib/periodos'
import { usePermisosFin } from '../lib/permisos'
import type { ClienteFacturacion } from '../schemas'

/* Facturas por cliente (facturas.php?clientes=1 / ?cli=) y, en otra pestaña,
   los datos fiscales de los clientes (fin-ajustes.php). */
export default function PorClientePage() {
  const [sp] = useSearchParams()
  const p = usePermisosFin()
  const cli = Number(sp.get('cli') ?? 0)
  const mes = sp.get('mes')
  if (!p.ver) return <Notice tone="error">No tienes acceso a las facturas.</Notice>
  if (cli > 0) return <Cliente key={cli} id={cli} mes={esMes(mes) ? mes : null} />
  return <Hub vista={sp.get('vista') === 'datos' ? 'datos' : 'facturas'} />
}

function Hub({ vista }: { vista: 'facturas' | 'datos' }) {
  const navigate = useNavigate()
  const p = usePermisosFin()
  return (
    <div>
      <PageHeader
        title={vista === 'datos' ? 'Facturación de clientes' : 'Facturas por cliente'}
        lead={
          vista === 'datos' ? (
            <>
              Los datos fiscales de cada cliente. Se rellenan solos al crear una factura o una programación. ¿Buscas los de los autónomos que emiten (IBAN, IVA, IRPF)? Están en{' '}
              <Link to="/ajustes/facturacion" className="font-semibold text-ink-strong hover:underline">
                Ajustes › Facturación
              </Link>
              .
            </>
          ) : undefined
        }
        actions={
          p.emitir && (
            <Button icon={<Plus />} to="/finanzas/facturas/nueva">
              Nueva factura
            </Button>
          )
        }
      />
      <Segmented
        className="mb-5"
        value={vista}
        onChange={(v) => navigate(v === 'datos' ? '/finanzas/clientes?vista=datos' : '/finanzas/clientes', { replace: true })}
        items={[
          { value: 'facturas', label: 'Facturas', icon: <FileText /> },
          { value: 'datos', label: 'Datos fiscales', icon: <IdCard /> },
        ]}
        aria-label="Vista"
      />
      {vista === 'datos' ? <DatosFiscales /> : <Tarjetas />}
    </div>
  )
}

function Tarjetas() {
  const { data, error, isLoading } = usePorCliente()
  if (error) return <Notice tone="error">{mensajeError(error, 'No se ha podido cargar.')}</Notice>
  if (isLoading || !data) return <CargandoFin />
  if (data.items.length === 0) return <EmptyState icon={<Users />} title="Aún no hay facturas" text="Cuando emitas facturas a tus clientes, aparecerán aquí." />
  return (
    <div className="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] gap-[18px] max-sm:grid-cols-1 max-sm:gap-3">
      {data.items.map((c) => (
        <Link
          key={c.client_id}
          to={`/finanzas/clientes?cli=${c.client_id}`}
          className="flex items-center gap-3.5 rounded-2xl border border-line bg-card p-5 transition-[transform,box-shadow] duration-200 ease-erp hover:-translate-y-[3px] hover:shadow-[0_12px_34px_rgba(0,0,0,.08)] dark:hover:shadow-[0_12px_34px_rgba(0,0,0,.5)]"
        >
          <Avatar nombre={c.nombre} size={44} forma="cuadrado" />
          <span className="min-w-0 flex-1">
            <b className="block truncate text-[15px] font-semibold text-ink-strong">{c.nombre}</b>
            <span className="text-[12.5px] text-muted">
              {c.n} factura{c.n === 1 ? '' : 's'}
              {c.borradores > 0 && ` · ${c.borradores} borrador${c.borradores === 1 ? '' : 'es'}`}
            </span>
          </span>
          <span className="text-right">
            <b className="block text-[15px] font-[750] text-ink-strong tabular-nums">{eurC(c.total)}</b>
            <span className="text-[11.5px] text-muted">Neto {eurC(c.neto)}</span>
          </span>
        </Link>
      ))}
    </div>
  )
}

function DatosFiscales() {
  const { data, error, isLoading } = useClientesFacturacion()
  const [abierto, setAbierto] = useState<ClienteFacturacion | null>(null)
  if (error) return <Notice tone="error">{mensajeError(error, 'No se ha podido cargar.')}</Notice>
  if (isLoading || !data) return <CargandoFin />
  if (data.items.length === 0)
    return (
      <EmptyState
        icon={<Users />}
        title="Aún no tienes clientes"
        actions={
          <Button to="/clientes/nuevo" icon={<Plus />}>
            Crear el primero
          </Button>
        }
      />
    )
  return (
    <>
      {data.sin_datos > 0 && (
        <p className="mb-3 inline-flex rounded-full bg-[#fffaf0] px-3 py-1 text-[12px] font-semibold text-[#8a5a00] dark:bg-[#2a2210] dark:text-warn">{data.sin_datos} sin datos</p>
      )}
      <div className="grid grid-cols-[repeat(auto-fill,minmax(230px,1fr))] gap-3 max-sm:grid-cols-1">
        {data.items.map((c) => (
          <button
            key={c.id}
            type="button"
            onClick={() => setAbierto(c)}
            className="flex items-center gap-3 rounded-[14px] border border-line bg-card px-4 py-3.5 text-left transition-colors hover:border-line-strong hover:bg-soft"
          >
            <Avatar nombre={c.name} size={32} forma="cuadrado" />
            <span className="min-w-0 flex-1">
              <b className="block truncate text-[13.5px] font-semibold text-ink-strong">{c.name}</b>
              <span className="flex items-center gap-1.5 text-[12px] text-muted">
                <span className={`size-2 rounded-full ${c.completo ? 'bg-[#12a150]' : 'bg-[#e0a000]'}`} />
                {c.completo ? 'Completo' : 'Faltan datos'}
              </span>
            </span>
            <ChevronRight className="size-4 text-label" />
          </button>
        ))}
      </div>
      <Modal open={abierto !== null} onClose={() => setAbierto(null)} title={abierto?.name} size="lg">
        {abierto && <FormFiscal key={abierto.id} c={abierto} onHecho={() => setAbierto(null)} />}
      </Modal>
    </>
  )
}

/* Formulario de datos fiscales de un cliente (modal y desplegable de la ficha). */
function FormFiscal({ c, onHecho, enModal = true }: { c: ClienteFacturacion; onHecho: () => void; enModal?: boolean }) {
  const p = usePermisosFin()
  const { aviso } = useToast()
  const guardar = useGuardarClienteFacturacion()
  const [v, setV] = useState({ fact_nombre: c.fact_nombre, fact_nif: c.fact_nif, fact_tel: c.fact_tel, fact_email: c.fact_email, fact_dir: c.fact_dir })
  const [err, setErr] = useState<string | null>(null)
  const campos = (
    <div className="grid grid-cols-2 gap-4 max-[700px]:grid-cols-1">
      <Field label="Razón social / Nombre fiscal" className="col-span-full">
        <TextInput value={v.fact_nombre} placeholder={c.name} onChange={(e) => setV({ ...v, fact_nombre: e.target.value })} disabled={!p.emitir} />
      </Field>
      <Field label="NIF / CIF">
        <TextInput value={v.fact_nif} placeholder="B12345678" onChange={(e) => setV({ ...v, fact_nif: e.target.value })} disabled={!p.emitir} />
      </Field>
      <Field label="Teléfono">
        <TextInput value={v.fact_tel} placeholder="600 000 000" type="tel" onChange={(e) => setV({ ...v, fact_tel: e.target.value })} disabled={!p.emitir} />
      </Field>
      <Field label="Email" error={err ?? undefined}>
        <TextInput value={v.fact_email} placeholder="correo@cliente.com" type="email" onChange={(e) => setV({ ...v, fact_email: e.target.value })} disabled={!p.emitir} />
      </Field>
      <Field label="Dirección fiscal" className="col-span-full">
        <TextInput value={v.fact_dir} placeholder="C/ …, CP, ciudad" onChange={(e) => setV({ ...v, fact_dir: e.target.value })} disabled={!p.emitir} />
      </Field>
    </div>
  )
  const botones = (
    <>
      <Button variant="ghost" onClick={onHecho}>
        {enModal ? 'Cerrar' : 'Cancelar'}
      </Button>
      <Button
        disabled={!p.emitir}
        loading={guardar.isPending}
        loadingText="Guardando…"
        onClick={async () => {
          try {
            await guardar.mutateAsync({ id: c.id, datos: v })
            aviso('Datos del cliente guardados.')
            onHecho()
          } catch (e) {
            setErr(mensajeError(e, 'No se ha podido guardar.'))
          }
        }}
      >
        {enModal ? 'Guardar' : 'Guardar datos de facturación'}
      </Button>
    </>
  )
  if (!enModal)
    return (
      <div>
        {campos}
        <div className="mt-4 flex justify-end gap-2.5">{botones}</div>
      </div>
    )
  return (
    <>
      <ModalBody>{campos}</ModalBody>
      <ModalFooter>{botones}</ModalFooter>
    </>
  )
}

function Cliente({ id, mes }: { id: number; mes: string | null }) {
  const p = usePermisosFin()
  const navigate = useNavigate()
  const { data, error, isLoading } = useClienteMeses(id)
  const facturas = useFacturas({ client_id: id, mes: mes ?? undefined }, !!mes)
  const em = useEmisores()
  const [fiscal, setFiscal] = useState(false)
  if (error) return <Notice tone="error">{mensajeError(error, 'No se ha podido cargar el cliente.')}</Notice>
  if (isLoading || !data) return <CargandoFin />
  const nombreEm = (k: string) => em.data?.items.find((e) => e.clave === k)?.nombre ?? k
  const base = `/finanzas/clientes?cli=${id}`
  return (
    <div>
      <PageHeader
        title={<TituloMigas partes={[{ label: 'Por cliente', to: '/finanzas/clientes' }, { label: data.cliente.name, to: mes ? base : undefined }, ...(mes ? [{ label: mesLabel(mes) }] : [])]} />}
        actions={
          <>
            <Button variant="ghost" icon={<IdCard />} onClick={() => setFiscal((v) => !v)} aria-expanded={fiscal}>
              Datos de facturación
            </Button>
            {p.emitir && (
              <Button icon={<Plus />} to={`/finanzas/facturas/nueva?cli=${id}`}>
                Nueva factura
              </Button>
            )}
          </>
        }
      />
      <Collapse open={fiscal}>
        <div className="mb-6 rounded-2xl border border-line bg-card px-[26px] py-6 max-sm:px-4">
          <FormFiscal c={data.cliente} onHecho={() => setFiscal(false)} enModal={false} />
        </div>
      </Collapse>
      {!mes &&
        (data.meses.length === 0 ? (
          <EmptyState
            icon={<FileText />}
            title="Este cliente no tiene facturas"
            text="Emite la primera cuando quieras."
            actions={
              p.emitir && (
                <Button icon={<Plus />} to={`/finanzas/facturas/nueva?cli=${id}`}>
                  Emitir factura
                </Button>
              )
            }
          />
        ) : (
          <div className="grid grid-cols-[repeat(auto-fill,minmax(210px,1fr))] gap-[18px] max-sm:grid-cols-2 max-sm:gap-3">
            {data.meses.map((m) => (
              <CarpetaMes key={m.mes} to={`${base}&mes=${m.mes}`} mes={m.mes} n={m.n} total={m.total} neto={m.neto} />
            ))}
          </div>
        ))}
      {mes && (
        <div className="overflow-hidden rounded-2xl border border-line bg-card">
          {facturas.isLoading && <CargandoFin />}
          {facturas.data && facturas.data.items.length === 0 && <p className="px-6 py-10 text-center text-[13.5px] text-muted">No hay facturas en {mesLabel(mes)}.</p>}
          {facturas.data && facturas.data.items.length > 0 && (
            <table className="w-full text-[13.5px]">
              <thead className="bg-head text-left text-[10.5px] font-bold tracking-[.5px] text-muted uppercase">
                <tr>
                  <th className="px-[18px] py-2.5">Nº</th>
                  <th className="px-3 py-2.5 max-sm:hidden">Emisor</th>
                  <th className="px-3 py-2.5 max-sm:hidden">Fecha</th>
                  <th className="px-3 py-2.5 text-right">Total</th>
                  <th className="px-3 py-2.5">Estado</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {facturas.data.items.map((f) => (
                  <tr key={f.id} onClick={() => navigate(`/finanzas/facturas/${f.id}`)} className="cursor-pointer border-t border-line hover:bg-hover-row">
                    <td className="px-[18px] py-3 font-bold text-ink-strong">
                      <Link to={`/finanzas/facturas/${f.id}`} onClick={(e) => e.stopPropagation()}>
                        {f.numero ?? 'Borrador'}
                      </Link>
                    </td>
                    <td className="px-3 py-3 max-sm:hidden">{nombreEm(f.emisor)}</td>
                    <td className="px-3 py-3 text-muted max-sm:hidden">{fechaCorta(f.fecha)}</td>
                    <td className="px-3 py-3 text-right font-semibold text-ink-strong tabular-nums">{eurC(f.total)}</td>
                    <td className="px-3 py-3">
                      <EstadoPill estado={f.estado} size="sm" />
                    </td>
                    <td className="pr-3">
                      <ChevronRight className="size-4 text-label" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </div>
  )
}
