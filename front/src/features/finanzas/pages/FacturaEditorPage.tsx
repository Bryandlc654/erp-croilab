import { useMemo, useState } from 'react'
import { Link, Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Eye, FileCheck2, Save } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Checkbox from '../../../shared/ui/Checkbox'
import { DateInput } from '../../../shared/ui/DatePicker'
import Field from '../../../shared/ui/Field'
import Notice from '../../../shared/ui/Notice'
import Segmented from '../../../shared/ui/Segmented'
import Select from '../../../shared/ui/Select'
import { TextArea, TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import { ApiError } from '../../../shared/api/client'
import { mensajeError, useAccionFactura, useClientesFacturacion, useDesdeNegocio, useEmisores, useFactura, useGuardarFactura, useSiguienteNumero } from '../api'
import CargandoFin from '../components/Cargando'
import FacturaHoja from '../components/FacturaHoja'
import LineasEditor from '../components/LineasEditor'
import ProyectoCombobox from '../components/ProyectoCombobox'
import Seccion from '../components/Seccion'
import { aCampo, lineasACuerpo, lineasNormalizadas, nuevaLinea, proyectoACuerpo, type LineaForm, type ProyectoElegido } from '../lib/cuerpos'
import { leer, totales } from '../lib/importes'
import { periodoDe, periodoRapido, hoyIso, type Periodo } from '../lib/periodos'
import { usePermisosFin } from '../lib/permisos'
import type { ClienteFacturacion, ClienteFiscal, EmisorLista, Factura, Hoja } from '../schemas'

type Form = {
  emisor: string
  serie: string
  client_id: number | null
  cliente: ClienteFiscal
  fecha: string
  fecha_venc: string | null
  periodo_ini: string | null
  periodo_fin: string | null
  cond_pago: string
  iva_pct: string
  irpf_pct: string
  efectivo: boolean
  personal: boolean
  notas: string
  mencion_iva: string
  proyecto: ProyectoElegido
  lineas: LineaForm[]
}

const VACIO: ClienteFiscal = { nombre: '', nif: '', dir: '', email: '', tel: '' }

/* /finanzas/facturas/nueva (?cli=, ?em=, ?negocio=) y /finanzas/facturas/:id/editar.
   Carga lo necesario y monta el editor con su estado inicial (sin efectos). */
export default function FacturaEditorPage() {
  const { id: idTxt } = useParams()
  const id = Number(idTxt ?? 0)
  const [sp] = useSearchParams()
  const p = usePermisosFin()
  const cli = Number(sp.get('cli') ?? 0)
  const negocio = Number(sp.get('negocio') ?? 0)
  const em = sp.get('em') ?? ''
  const factura = useFactura(id)
  const emisores = useEmisores()
  const clientes = useClientesFacturacion()
  const neg = useDesdeNegocio(id ? 0 : negocio)

  if (!p.emitir) return <Notice tone="error">No tienes permiso para crear o editar facturas.</Notice>
  const error = factura.error ?? emisores.error ?? clientes.error ?? neg.error
  if (error) return <Notice tone="error">{mensajeError(error, 'No se ha podido cargar la factura.')}</Notice>
  if ((id && !factura.data) || !emisores.data || !clientes.data || (negocio && !id && !neg.data)) return <CargandoFin />
  if (factura.data && !factura.data.editable) return <Navigate to={`/finanzas/facturas/${id}`} replace />

  return (
    <Editor
      key={id || `nueva-${cli}-${negocio}-${em}`}
      factura={factura.data ?? null}
      emisores={emisores.data.items.filter((e) => !e.baja)}
      porDefecto={em || emisores.data.por_defecto}
      clientes={clientes.data.items}
      cli={cli}
      negocio={neg.data ?? null}
    />
  )
}

function inicial(f: Factura | null, emisores: EmisorLista[], porDefecto: string, clientes: ClienteFacturacion[], cli: number, negocio: ReturnType<typeof useDesdeNegocio>['data'] | null): Form {
  if (f) {
    return {
      emisor: f.emisor,
      serie: f.serie,
      client_id: f.client_id,
      cliente: { ...f.cliente },
      fecha: f.fecha,
      fecha_venc: f.fecha_venc,
      periodo_ini: f.periodo_ini,
      periodo_fin: f.periodo_fin,
      cond_pago: f.cond_pago,
      iva_pct: f.iva_pct,
      irpf_pct: f.irpf_pct,
      efectivo: f.efectivo,
      personal: f.personal,
      notas: f.notas,
      mencion_iva: f.mencion_iva,
      proyecto: f.project ? { id: f.project.id, nombre: f.project.nombre, color: f.project.color } : null,
      lineas: f.lineas.length ? f.lineas.map((l) => nuevaLinea({ concepto: l.concepto, cantidad: aCampo(l.cantidad), precio: aCampo(l.precio) })) : [nuevaLinea()],
    }
  }
  const e = emisores.find((x) => x.clave === porDefecto) ?? emisores[0]
  const base: Form = {
    emisor: e?.clave ?? '',
    serie: e?.serie_recordada ?? '',
    client_id: null,
    cliente: { ...VACIO },
    fecha: hoyIso(),
    fecha_venc: null,
    periodo_ini: null,
    periodo_fin: null,
    cond_pago: e?.defaults.venc ?? 'Contado',
    iva_pct: e?.defaults.iva ?? '21',
    irpf_pct: e?.defaults.irpf ?? '0',
    efectivo: false,
    personal: false,
    notas: '',
    mencion_iva: '',
    proyecto: null,
    lineas: [nuevaLinea()],
  }
  const b = negocio && !negocio.ya ? negocio.borrador : null
  if (b) {
    const en = emisores.find((x) => x.clave === b.emisor)
    return {
      ...base,
      emisor: en?.clave ?? base.emisor,
      serie: b.serie,
      client_id: b.client_id,
      cliente: { ...b.cliente },
      iva_pct: b.iva_pct,
      irpf_pct: b.irpf_pct,
      cond_pago: b.cond_pago,
      lineas: b.lineas.map((l) => nuevaLinea({ concepto: l.concepto, cantidad: aCampo(l.cantidad), precio: aCampo(l.precio) })),
    }
  }
  const c = cli ? clientes.find((x) => x.id === cli) : undefined
  if (c) return { ...base, client_id: c.id, cliente: fiscalesDe(c, base.cliente) }
  return base
}

/* Datos fiscales del cliente elegido: solo los que tiene rellenos (como el antiguo). */
function fiscalesDe(c: ClienteFacturacion, previo: ClienteFiscal): ClienteFiscal {
  return {
    nombre: c.fact_nombre || c.name || previo.nombre,
    nif: c.fact_nif || previo.nif,
    dir: c.fact_dir || previo.dir,
    email: c.fact_email || previo.email,
    tel: c.fact_tel || previo.tel,
  }
}

function Editor({
  factura,
  emisores,
  porDefecto,
  clientes,
  cli,
  negocio,
}: {
  factura: Factura | null
  emisores: EmisorLista[]
  porDefecto: string
  clientes: ClienteFacturacion[]
  cli: number
  negocio: ReturnType<typeof useDesdeNegocio>['data'] | null
}) {
  const navigate = useNavigate()
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const guardar = useGuardarFactura()
  const accion = useAccionFactura()
  const [f, setF] = useState<Form>(() => inicial(factura, emisores, porDefecto, clientes, cli, negocio))
  const [sucio, setSucio] = useState(false)
  const [errores, setErrores] = useState<Record<string, string>>({})
  const [previos, setPrevios] = useState<{ iva: string; irpf: string } | null>(null)
  useUnsavedGuard(sucio)
  const rect = factura?.tipo === 'rectificativa'
  const numeroFijo = factura?.numero ?? null
  const siguiente = useSiguienteNumero({ emisor: f.emisor, serie: f.serie, fecha: f.fecha, tipo: rect ? 'rectificativa' : 'normal' }, !numeroFijo)
  const yaFacturado = negocio?.ya ?? null

  function set<K extends keyof Form>(k: K, v: Form[K]) {
    setF((x) => ({ ...x, [k]: v }))
    setSucio(true)
    if (errores[k as string])
      setErrores((x) => {
        const resto = { ...x }
        delete resto[k as string]
        return resto
      })
  }

  function cambiarEmisor(clave: string) {
    const e = emisores.find((x) => x.clave === clave)
    setF((x) => ({
      ...x,
      emisor: clave,
      /* En una factura nueva se copian los valores por defecto del emisor. */
      ...(factura || !e ? {} : { serie: e.serie_recordada, iva_pct: x.efectivo ? '0' : e.defaults.iva, irpf_pct: x.efectivo ? '0' : e.defaults.irpf, cond_pago: e.defaults.venc }),
    }))
    setSucio(true)
  }

  function elegirCliente(id: number) {
    const c = clientes.find((x) => x.id === id)
    setF((x) => ({ ...x, client_id: c ? c.id : null, cliente: c ? fiscalesDe(c, x.cliente) : x.cliente }))
    setSucio(true)
  }

  function efectivo(v: boolean) {
    if (v) {
      setPrevios({ iva: f.iva_pct, irpf: f.irpf_pct })
      setF((x) => ({ ...x, efectivo: true, iva_pct: '0', irpf_pct: '0' }))
    } else {
      setF((x) => ({ ...x, efectivo: false, iva_pct: previos?.iva ?? '21', irpf_pct: previos?.irpf ?? '0' }))
    }
    setSucio(true)
  }

  function periodo(t: Periodo) {
    const p = periodoRapido(t)
    setF((x) => ({ ...x, periodo_ini: p.ini, periodo_fin: p.fin }))
    setSucio(true)
  }

  const normal = useMemo(() => lineasNormalizadas(f.lineas).filter((l) => l.concepto !== '' || l.precio !== '0.00'), [f.lineas])
  const t = totales(normal, leer(f.iva_pct) ?? '0', leer(f.irpf_pct) ?? '0')
  const emisor = emisores.find((e) => e.clave === f.emisor)
  const hoja: Hoja = {
    id: factura?.id ?? 0,
    numero: numeroFijo ?? siguiente.data ?? null,
    tipo: rect ? 'rectificativa' : 'normal',
    estado: 'borrador',
    borrador: true,
    titulo: rect ? 'FACTURA RECTIFICATIVA' : 'FACTURA',
    fecha: f.fecha,
    fecha_venc: f.fecha_venc,
    periodo_ini: f.periodo_ini,
    periodo_fin: f.periodo_fin,
    cliente: { nombre: f.cliente.nombre, nif: f.cliente.nif, tel: f.cliente.tel, dir: f.cliente.dir, email: f.cliente.email },
    emisor: { name: emisor?.nombre ?? '', nif: '', dir: '', email: '', phone: '', iban: '', banco: '' },
    lineas: normal.map((l) => ({ ...l, importe: totales([l], 0, 0).base })),
    iva_pct: leer(f.iva_pct) ?? '0',
    irpf_pct: leer(f.irpf_pct) ?? '0',
    totales: t,
    pago: { banco: '', titular: emisor?.nombre ?? '', forma: f.efectivo ? 'Efectivo' : 'Transferencia', condiciones: f.cond_pago, fecha_venc: f.fecha_venc, iban: '' },
    notas_legales: [
      ...(rect && factura?.rectifica ? [`Rectifica la factura Nº ${factura.rectifica.numero ?? ''}. Motivo: ${factura.rect_motivo}.`] : []),
      ...(f.efectivo ? ['Operación cobrada en efectivo.'] : []),
      ...(leer(f.iva_pct) === '0.00' && f.mencion_iva.trim() ? [f.mencion_iva.trim()] : []),
    ],
    notas: f.notas,
    rectifica: null,
    hash: null,
    integra: null,
  }

  function cuerpo() {
    return {
      emisor: f.emisor,
      serie: f.serie,
      client_id: f.client_id,
      cliente: f.cliente,
      fecha: f.fecha,
      fecha_venc: f.fecha_venc,
      periodo_ini: f.periodo_ini,
      periodo_fin: f.periodo_fin,
      cond_pago: f.cond_pago,
      iva_pct: leer(f.iva_pct) ?? f.iva_pct,
      irpf_pct: leer(f.irpf_pct) ?? f.irpf_pct,
      efectivo: f.efectivo,
      personal: f.personal,
      notas: f.notas,
      mencion_iva: f.mencion_iva,
      lineas: lineasACuerpo(f.lineas),
      ...proyectoACuerpo(f.proyecto),
      ...(!factura && negocio && !negocio.ya ? { deal_id: negocio.negocio.id } : {}),
    }
  }

  function fallo(e: unknown, porDefecto: string) {
    if (e instanceof ApiError && e.campo) setErrores((x) => ({ ...x, [e.campo!.split('.')[0]]: e.message }))
    aviso(mensajeError(e, porDefecto), { tipo: 'error' })
  }

  async function enviar(emitir: boolean) {
    if (emitir) {
      const ok = await confirm({
        title: rect ? '¿Emitir la rectificativa?' : '¿Emitir la factura?',
        message: `Se le asigna el número ${numeroFijo ?? siguiente.data ?? 'siguiente de la serie'} y desde ese momento ya no se puede modificar ni borrar: solo rectificar o anular.`,
        okLabel: 'Emitir',
      })
      if (!ok) return
    }
    let guardada: Factura
    try {
      guardada = (await guardar.mutateAsync({ id: factura?.id ?? null, datos: cuerpo() })).factura
    } catch (e) {
      fallo(e, 'No se ha podido guardar la factura.')
      return
    }
    setSucio(false)
    if (!emitir) {
      aviso('Borrador guardado.')
      navigate(`/finanzas/facturas/${guardada.id}`)
      return
    }
    try {
      const r = await accion.mutateAsync({ id: guardada.id, accion: 'emitir' })
      aviso(`Factura ${r.factura.numero ?? ''} emitida.`)
      navigate(`/finanzas/facturas/${guardada.id}`)
    } catch (e) {
      fallo(e, 'No se ha podido emitir.')
      if (!factura) navigate(`/finanzas/facturas/${guardada.id}/editar`, { replace: true })
    }
  }

  const ocupado = guardar.isPending || accion.isPending
  const perActual = periodoDe(f.periodo_ini, f.periodo_fin)
  const opcionesClientes = [{ value: 0, label: '— Manual —' }, ...clientes.map((c) => ({ value: c.id, label: c.name, hint: c.completo ? undefined : 'sin datos' }))]

  return (
    <div>
      <Button variant="ghost" size="sm" icon={<ArrowLeft />} onClick={() => navigate(-1)} className="mb-4">
        Volver
      </Button>
      <h1 className="mb-[26px] text-[26px] leading-[1.2] font-semibold tracking-[-.5px] text-ink-strong max-sm:text-[23px]">
        {factura ? (rect ? 'Editar rectificativa' : 'Editar factura') : 'Nueva factura'}
      </h1>
      {yaFacturado && (
        <Notice tone="warn" action={<Link className="font-semibold underline" to={`/finanzas/facturas/${yaFacturado.id}`}>Abrir</Link>}>
          Este negocio ya tenía la factura {yaFacturado.numero ?? 'en borrador'}. No hace falta crear otra.
        </Notice>
      )}
      {negocio && !negocio.ya && <Notice tone="info">Borrador preparado desde el negocio «{negocio.negocio.nombre}». Revísalo antes de guardar.</Notice>}
      {rect && factura?.rectifica && (
        <Notice tone="info">
          Rectifica la factura <b>{factura.rectifica.numero}</b>. Motivo: {factura.rect_motivo}. Ajusta las líneas para dejar solo la diferencia (en negativo si se devuelve dinero).
        </Notice>
      )}

      <div className="grid grid-cols-[minmax(0,1fr)_470px] items-start gap-6 max-[1150px]:grid-cols-1">
        <div className="space-y-5">
          <Seccion titulo="Cliente">
            <div className="grid grid-cols-2 gap-4 max-[700px]:grid-cols-1">
              <Field label="Cliente del portal (autorrellena sus datos)" className="col-span-full" error={errores.client_id}>
                <Select value={f.client_id ?? 0} onChange={(v) => elegirCliente(Number(v))} options={opcionesClientes} searchable searchPlaceholder="Buscar cliente…" />
              </Field>
              <Field label="Nombre / razón social" error={errores.cliente_nombre ?? errores.cliente}>
                <TextInput value={f.cliente.nombre} onChange={(e) => set('cliente', { ...f.cliente, nombre: e.target.value })} />
              </Field>
              <Field label="NIF / CIF">
                <TextInput value={f.cliente.nif} onChange={(e) => set('cliente', { ...f.cliente, nif: e.target.value })} />
              </Field>
              <Field label="Teléfono">
                <TextInput value={f.cliente.tel} type="tel" onChange={(e) => set('cliente', { ...f.cliente, tel: e.target.value })} />
              </Field>
              <Field label="Email">
                <TextInput value={f.cliente.email} type="email" onChange={(e) => set('cliente', { ...f.cliente, email: e.target.value })} />
              </Field>
              <Field label="Dirección" className="col-span-full">
                <TextInput value={f.cliente.dir} onChange={(e) => set('cliente', { ...f.cliente, dir: e.target.value })} />
              </Field>
            </div>
          </Seccion>

          <Seccion titulo="Datos de emisión">
            <div className="grid grid-cols-3 gap-4 max-[700px]:grid-cols-1">
              <Field label="Emitida por" error={errores.emisor}>
                <Select value={f.emisor} onChange={cambiarEmisor} options={emisores.map((e) => ({ value: e.clave, label: e.nombre }))} disabled={!!numeroFijo} />
              </Field>
              <Field label="Serie (prefijo)" hint={rect ? 'Las rectificativas van en su serie «R».' : 'Vacío = la del emisor. Se le añade el año.'} error={errores.serie}>
                <TextInput value={f.serie} placeholder="Ej: F, SE-" maxLength={20} disabled={!!numeroFijo} onChange={(e) => set('serie', e.target.value.toUpperCase())} />
              </Field>
              <Field label="Nº" hint={numeroFijo ? 'Ya tenía número.' : 'Se asigna al emitir, sin huecos.'}>
                <TextInput value={numeroFijo ?? (siguiente.data ? `${siguiente.data} (previsto)` : '')} placeholder="auto" readOnly disabled />
              </Field>
            </div>
          </Seccion>

          <Seccion titulo="Fechas y cobro">
            <div className="grid grid-cols-2 gap-4 max-[700px]:grid-cols-1">
              <Field label="Fecha de expedición" error={errores.fecha}>
                <DateInput value={f.fecha} onChange={(v) => set('fecha', v ?? hoyIso())} />
              </Field>
              <Field label="Fecha vto. (opcional)" error={errores.fecha_venc}>
                <DateInput value={f.fecha_venc} onChange={(v) => set('fecha_venc', v)} />
              </Field>
              <Field label="Período de facturación" className="col-span-full" error={errores.periodo_fin}>
                <Segmented
                  variant="pill"
                  value={perActual ?? 'otro'}
                  onChange={(v) => v !== 'otro' && periodo(v as Periodo)}
                  items={[
                    { value: 'vista', label: 'Mes vista' },
                    { value: 'vencido', label: 'Mes vencido' },
                    { value: 'ninguno', label: 'Sin período' },
                  ]}
                  aria-label="Período"
                />
                <div className="mt-2.5 grid grid-cols-[1fr_auto_1fr] items-center gap-2">
                  <DateInput value={f.periodo_ini} onChange={(v) => set('periodo_ini', v)} placeholder="Desde" aria-label="Desde" />
                  <span className="text-muted">→</span>
                  <DateInput value={f.periodo_fin} onChange={(v) => set('periodo_fin', v)} placeholder="Hasta" aria-label="Hasta" />
                </div>
              </Field>
              <Field label="Vencimiento (texto)" className="col-span-full">
                <TextInput value={f.cond_pago} placeholder="Contado" maxLength={60} onChange={(e) => set('cond_pago', e.target.value)} />
              </Field>
            </div>
          </Seccion>

          <Seccion titulo="Líneas">
            {errores.lineas && <Notice tone="error">{errores.lineas}</Notice>}
            <LineasEditor lineas={f.lineas} onChange={(ls) => set('lineas', ls)} />
          </Seccion>

          <Seccion titulo="Impuestos">
            <div className="grid grid-cols-2 gap-4 max-[700px]:grid-cols-1">
              <Field label="IVA %" error={errores.iva_pct}>
                <TextInput value={f.iva_pct} inputMode="decimal" unit="%" disabled={f.efectivo} onChange={(e) => set('iva_pct', e.target.value)} />
              </Field>
              <Field label="IRPF %" error={errores.irpf_pct}>
                <TextInput value={f.irpf_pct} inputMode="decimal" unit="%" disabled={f.efectivo} onChange={(e) => set('irpf_pct', e.target.value)} />
              </Field>
              {leer(f.iva_pct) === '0.00' && !f.efectivo && (
                <Field label="Mención por IVA 0 % (exención o inversión del sujeto pasivo)" className="col-span-full" hint="Sale impresa en la factura. P. ej. «Operación exenta de IVA según art. 20 de la Ley 37/1992».">
                  <TextInput value={f.mencion_iva} maxLength={250} onChange={(e) => set('mencion_iva', e.target.value)} />
                </Field>
              )}
            </div>
            <div className="mt-4 flex flex-col gap-3 border-t border-line2 pt-4">
              <Checkbox checked={f.efectivo} onChange={efectivo} label="Efectivo (en B) · sin IVA ni IRPF" />
              <Checkbox checked={f.personal} onChange={(v) => set('personal', v)} label="Ingreso personal (mío · fuera de la contabilidad de empresa)" />
            </div>
          </Seccion>

          <Seccion titulo="Notas">
            <TextArea value={f.notas} placeholder="Condiciones, comentarios…" maxLength={2000} onChange={(e) => set('notas', e.target.value)} aria-label="Notas" />
          </Seccion>

          <Seccion titulo="Proyecto" extra="· uso interno, no sale en la factura">
            <ProyectoCombobox value={f.proyecto} clientId={f.client_id} onChange={(v) => set('proyecto', v)} placeholder="Buscar o crear proyecto…" />
          </Seccion>
        </div>

        <aside className="sticky top-3.5 max-[1150px]:static" aria-label="Vista previa">
          <p className="mb-2.5 flex items-center gap-1.5 text-[11.5px] font-bold tracking-[.5px] text-muted uppercase">
            <Eye className="size-3.5" /> Vista previa
          </p>
          <div className="overflow-hidden rounded-2xl shadow-[0_14px_44px_rgba(0,0,0,.08)] motion-safe:animate-fade-up dark:shadow-[0_14px_44px_rgba(0,0,0,.5)]">
            <FacturaHoja h={hoja} compacta />
          </div>
        </aside>
      </div>

      <div className="sticky bottom-0 z-20 -mx-4 mt-6 flex flex-wrap items-center justify-end gap-2.5 border-t border-line bg-page/90 px-4 py-3.5 backdrop-blur-[6px] md:-mx-6 md:px-6 lg:-mx-[52px] lg:px-[52px]">
        <span className="mr-auto text-[12.5px] text-muted max-sm:hidden">
          {sucio ? 'Sin guardar · ' : ''}Un borrador no tiene número: se le da al emitir.
        </span>
        <Button variant="ghost" onClick={() => navigate(factura ? `/finanzas/facturas/${factura.id}` : '/finanzas/facturas')} disabled={ocupado}>
          Cancelar
        </Button>
        <Button variant="ghost" icon={<Save />} onClick={() => void enviar(false)} loading={guardar.isPending && !accion.isPending} loadingText="Guardando…" disabled={ocupado}>
          Guardar borrador
        </Button>
        <Button icon={<FileCheck2 />} onClick={() => void enviar(true)} loading={accion.isPending} loadingText="Emitiendo…" disabled={ocupado}>
          Guardar y emitir
        </Button>
      </div>
    </div>
  )
}
