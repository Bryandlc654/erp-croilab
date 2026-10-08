import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Info, Pause, Pencil, Play, Plus, Trash2, Zap } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Checkbox from '../../../shared/ui/Checkbox'
import Field from '../../../shared/ui/Field'
import IconButton from '../../../shared/ui/IconButton'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import Select from '../../../shared/ui/Select'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { fechaCorta, mesLabel } from '../../../shared/lib/formato'
import { ApiError } from '../../../shared/api/client'
import { mensajeError, useAccionProgramacion, useClientesFacturacion, useEmisores, useGenerar, useGuardarProgramacion, useProgramaciones } from '../api'
import CargandoFin from '../components/Cargando'
import LineasEditor from '../components/LineasEditor'
import ProyectoCombobox from '../components/ProyectoCombobox'
import { aCampo, lineasACuerpo, nuevaLinea, proyectoACuerpo, type LineaForm, type ProyectoElegido } from '../lib/cuerpos'
import { eurC, leer } from '../lib/importes'
import { esMes, sumarMeses, ymDe } from '../lib/periodos'
import { usePermisosFin } from '../lib/permisos'
import type { ClienteFacturacion, ClienteFiscal, EmisorLista, Programacion } from '../schemas'

/* Programaciones (facturas recurrentes): cada mes, el día indicado, se emite
   sola la factura del cliente (cron) o a mano con «Generar ahora». */
export default function ProgramacionesPage() {
  const p = usePermisosFin()
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const { data, error, isLoading } = useProgramaciones()
  const em = useEmisores()
  const clientes = useClientesFacturacion(p.programar)
  const generar = useGenerar()
  const accion = useAccionProgramacion()
  const [editando, setEditando] = useState<Programacion | 'nueva' | null>(null)
  const [resultado, setResultado] = useState<string | null>(null)

  async function generarAhora() {
    try {
      const r = await generar.mutateAsync()
      if (r.ocupado) setResultado('Ya hay una generación en marcha (el cron o alguien más). Prueba en un momento.')
      else setResultado(`Se generaron ${r.generadas} factura${r.generadas === 1 ? '' : 's'}.${r.errores.length ? ` ${r.errores.length} con error: ${r.errores[0].msg}` : ''}`)
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido generar.'), { tipo: 'error' })
    }
  }

  async function act(s: Programacion, a: 'pausar' | 'activar' | 'borrar') {
    if (a === 'borrar' && !(await confirm({ title: '¿Borrar programación?', message: 'Las facturas que ya generó se quedan.', danger: true }))) return
    try {
      await accion.mutateAsync({ id: s.id, accion: a })
      aviso(a === 'borrar' ? 'Programación borrada.' : a === 'pausar' ? 'Programación pausada.' : 'Programación activada.')
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido hacer.'), { tipo: 'error' })
    }
  }

  if (!p.ver) return <Notice tone="error">No tienes acceso a las facturas.</Notice>
  const nombreEm = (k: string) => em.data?.items.find((e) => e.clave === k)?.nombre ?? k
  return (
    <div>
      <PageHeader
        title="Programaciones"
        actions={
          p.programar && (
            <>
              <Button variant="ghost" icon={<Zap />} onClick={() => void generarAhora()} loading={generar.isPending} loadingText="Generando…">
                Generar ahora
              </Button>
              <Button icon={<Plus />} onClick={() => setEditando('nueva')}>
                Nueva programación
              </Button>
            </>
          )
        }
      />
      <div className="mb-5 flex items-start gap-2.5 rounded-xl border border-[#cfe0fb] bg-[#eef4ff] px-4 py-3 text-[13px] leading-[1.5] text-[#1f4fb6] dark:border-[#24365a] dark:bg-[#141c2b] dark:text-[#a9c1ea]">
        <Info className="mt-0.5 size-4 shrink-0" />
        <span>
          Cada mes, en el día que indiques, el sistema emite automáticamente la factura del cliente (lo hace el cron). También puedes emitir lo pendiente a mano con «Generar ahora». Los meses atrasados se emiten con fecha de hoy y el período del mes que cubren.
          {resultado && <b className="mt-1 block font-semibold">{resultado}</b>}
        </span>
      </div>
      {editando && em.data && clientes.data && (
        <Formulario
          key={editando === 'nueva' ? 'nueva' : editando.id}
          s={editando === 'nueva' ? null : editando}
          emisores={em.data.items.filter((e) => !e.baja)}
          porDefecto={em.data.por_defecto}
          clientes={clientes.data.items}
          onClose={() => setEditando(null)}
        />
      )}
      {error && <Notice tone="error">{mensajeError(error, 'No se han podido cargar.')}</Notice>}
      {isLoading && <CargandoFin />}
      {data && (
        <div className="overflow-hidden rounded-2xl border border-line bg-card">
          <div className="grid grid-cols-[24px_minmax(0,1.4fr)_minmax(0,1fr)_110px_70px_140px_110px] items-center gap-3 border-b border-line bg-head px-[18px] py-3 text-[10.5px] font-bold tracking-[.5px] text-muted uppercase max-[900px]:hidden">
            <span />
            <span>Cliente</span>
            <span>Concepto</span>
            <span>Emisor</span>
            <span>Día</span>
            <span className="text-right">Total / mes</span>
            <span />
          </div>
          {data.items.length === 0 && (
            <p className="px-6 py-14 text-center text-[13.5px] text-muted">
              Sin programaciones.{' '}
              {p.programar && (
                <button type="button" className="font-semibold text-ink-strong hover:underline" onClick={() => setEditando('nueva')}>
                  Crea la primera →
                </button>
              )}
            </p>
          )}
          {data.items.map((s) => (
            <div
              key={s.id}
              className="grid grid-cols-[24px_minmax(0,1.4fr)_minmax(0,1fr)_110px_70px_140px_110px] items-center gap-3 border-b border-line px-[18px] py-3.5 last:border-b-0 max-[900px]:grid-cols-[18px_minmax(0,1fr)_auto] max-[900px]:gap-y-1"
            >
              <span className={`size-2.5 rounded-full ${s.activo ? 'bg-[#12a150]' : 'bg-[#b0b4bb]'}`} title={s.activo ? 'Activa' : 'Pausada'} aria-label={s.activo ? 'Activa' : 'Pausada'} />
              <span className="min-w-0">
                <b className="block truncate text-[14px] font-semibold text-ink-strong">{s.cliente.nombre || '—'}</b>
                <span className="block truncate text-[12px] text-muted">
                  IVA {s.iva_pct}% · IRPF {s.irpf_pct}% · {s.cond_pago} · última: {s.last_ym ? mesLabel(s.last_ym) : '—'}
                  {s.proxima && ` · próxima: ${fechaCorta(s.proxima)}`}
                </span>
                {s.cliente_sin_datos && <span className="text-[11.5px] font-semibold text-[#b7791f] dark:text-warn">El cliente no tiene datos de facturación</span>}
              </span>
              <span className="truncate text-[13px] text-ink max-[900px]:col-start-2">
                {s.lineas[0]?.concepto ?? '—'}
                {s.lineas.length > 1 && <span className="text-muted"> +{s.lineas.length - 1}</span>}
              </span>
              <span className="text-[13px] text-ink max-[900px]:hidden">{nombreEm(s.emisor)}</span>
              <span className="text-[13px] text-muted max-[900px]:hidden">día {s.dia}</span>
              <span className="text-right max-[900px]:col-start-3 max-[900px]:row-start-1">
                <b className="block text-[14px] font-semibold text-ink-strong tabular-nums">{eurC(s.total_mes)}</b>
                <span className="text-[11.5px] text-muted">base {eurC(s.base_mes)}</span>
              </span>
              <span className="flex justify-end gap-0.5 max-[900px]:col-start-3">
                {p.programar && (
                  <>
                    <IconButton label="Editar" icon={<Pencil />} onClick={() => setEditando(s)} />
                    <IconButton label={s.activo ? 'Pausar' : 'Activar'} icon={s.activo ? <Pause /> : <Play />} onClick={() => void act(s, s.activo ? 'pausar' : 'activar')} />
                    <IconButton label="Borrar" tone="danger" icon={<Trash2 />} onClick={() => void act(s, 'borrar')} />
                  </>
                )}
              </span>
            </div>
          ))}
        </div>
      )}
      <p className="mt-3 text-[12.5px] text-muted">
        Las facturas generadas aparecen en{' '}
        <Link to="/finanzas/facturas" className="font-semibold text-ink hover:underline">
          Facturas
        </Link>{' '}
        ya emitidas (con su número) y como «Enviada».
      </p>
    </div>
  )
}

type FormProg = {
  emisor: string
  serie: string
  client_id: number | null
  cliente: ClienteFiscal
  dia: string
  lineas: LineaForm[]
  iva_pct: string
  irpf_pct: string
  cond_pago: string
  venc_dias: string
  start_ym: string
  activo: boolean
  proyecto: ProyectoElegido
}

function inicialProg(s: Programacion | null, emisores: EmisorLista[], porDefecto: string): FormProg {
  if (s)
    return {
      emisor: s.emisor,
      serie: s.serie,
      client_id: s.client_id,
      cliente: { ...s.cliente },
      dia: String(s.dia),
      lineas: s.lineas.map((l) => nuevaLinea({ concepto: l.concepto, cantidad: aCampo(l.cantidad), precio: aCampo(l.precio) })),
      iva_pct: s.iva_pct,
      irpf_pct: s.irpf_pct,
      cond_pago: s.cond_pago,
      venc_dias: s.venc_dias === null ? '' : String(s.venc_dias),
      start_ym: s.start_ym,
      activo: s.activo,
      proyecto: s.project ? { ...s.project } : null,
    }
  const e = emisores.find((x) => x.clave === porDefecto) ?? emisores[0]
  return {
    emisor: e?.clave ?? '',
    serie: '',
    client_id: null,
    cliente: { nombre: '', nif: '', dir: '', email: '', tel: '' },
    dia: '1',
    lineas: [nuevaLinea()],
    iva_pct: e?.defaults.iva ?? '21',
    irpf_pct: e?.defaults.irpf ?? '0',
    cond_pago: e?.defaults.venc ?? 'Contado',
    venc_dias: '',
    start_ym: ymDe(),
    activo: true,
    proyecto: null,
  }
}

function Formulario({ s, emisores, porDefecto, clientes, onClose }: { s: Programacion | null; emisores: EmisorLista[]; porDefecto: string; clientes: ClienteFacturacion[]; onClose: () => void }) {
  const { aviso } = useToast()
  const guardar = useGuardarProgramacion()
  const [f, setF] = useState<FormProg>(() => inicialProg(s, emisores, porDefecto))
  const [err, setErr] = useState<Record<string, string>>({})
  const set = <K extends keyof FormProg>(k: K, v: FormProg[K]) => setF((x) => ({ ...x, [k]: v }))
  const cliente = clientes.find((c) => c.id === f.client_id)
  const meses = Array.from({ length: 18 }, (_, i) => sumarMeses(ymDe(), i - 6))
  if (!meses.includes(f.start_ym) && esMes(f.start_ym)) meses.unshift(f.start_ym)

  function elegirCliente(id: number) {
    const c = clientes.find((x) => x.id === id)
    setF((x) => ({
      ...x,
      client_id: c ? c.id : null,
      cliente: c ? { nombre: c.fact_nombre || c.name || x.cliente.nombre, nif: c.fact_nif || x.cliente.nif, dir: c.fact_dir || x.cliente.dir, email: c.fact_email || x.cliente.email, tel: c.fact_tel || x.cliente.tel } : x.cliente,
    }))
  }

  async function enviar() {
    const datos = {
      emisor: f.emisor,
      serie: f.serie,
      client_id: f.client_id,
      cliente: f.cliente,
      dia: Number(f.dia),
      lineas: lineasACuerpo(f.lineas),
      iva_pct: leer(f.iva_pct) ?? f.iva_pct,
      irpf_pct: leer(f.irpf_pct) ?? f.irpf_pct,
      cond_pago: f.cond_pago,
      venc_dias: f.venc_dias === '' ? null : Number(f.venc_dias),
      start_ym: f.start_ym,
      activo: f.activo,
      ...proyectoACuerpo(f.proyecto),
    }
    try {
      await guardar.mutateAsync({ id: s?.id ?? null, datos })
      aviso('Programación guardada.')
      onClose()
    } catch (e) {
      if (e instanceof ApiError && e.campo) setErr({ [e.campo.split('.')[0]]: e.message })
      aviso(mensajeError(e, 'No se ha podido guardar.'), { tipo: 'error' })
    }
  }

  return (
    <section className="mb-6 rounded-2xl border border-line bg-card px-[26px] py-6 motion-safe:animate-fade-up max-sm:px-4">
      <h3 className="mb-4 text-[16px] font-semibold text-ink-strong">{s ? 'Editar programación' : 'Nueva programación'}</h3>
      <div className="grid grid-cols-3 gap-4 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1">
        <Field label="Emisor" error={err.emisor}>
          <Select value={f.emisor} onChange={(v) => set('emisor', v)} options={emisores.map((e) => ({ value: e.clave, label: e.nombre }))} />
        </Field>
        <Field label="Serie (prefijo)" error={err.serie} hint="Vacío = la del emisor.">
          <TextInput value={f.serie} placeholder="Ej: F, SE-" maxLength={20} onChange={(e) => set('serie', e.target.value.toUpperCase())} />
        </Field>
        <Field label="Cliente" error={err.client_id}>
          <Select
            value={f.client_id ?? 0}
            onChange={(v) => elegirCliente(Number(v))}
            searchable
            options={[{ value: 0, label: '— Manual —' }, ...clientes.map((c) => ({ value: c.id, label: c.completo ? c.name : `${c.name} — sin datos` }))]}
          />
        </Field>
        <Field label="Día de emisión (1-28)" error={err.dia}>
          <Select value={f.dia} onChange={(v) => set('dia', v)} options={Array.from({ length: 28 }, (_, i) => ({ value: String(i + 1), label: `Día ${i + 1}` }))} />
        </Field>
        <Field label="Nombre / razón social" error={err.cliente}>
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
        <Field label="Dirección">
          <TextInput value={f.cliente.dir} onChange={(e) => set('cliente', { ...f.cliente, dir: e.target.value })} />
        </Field>
      </div>
      {cliente && !cliente.completo && (
        <Notice tone="warn" className="mt-4">
          Este cliente aún no tiene datos de facturación. Rellénalos arriba (se guardarán en su ficha al guardar la programación) o edítalos en{' '}
          <Link to="/finanzas/clientes?vista=datos" className="font-semibold underline">
            Facturación de clientes
          </Link>
          .
        </Notice>
      )}
      <div className="mt-5">
        {err.lineas && <Notice tone="error">{err.lineas}</Notice>}
        <LineasEditor lineas={f.lineas} onChange={(ls) => set('lineas', ls)} />
      </div>
      <div className="mt-5 grid grid-cols-3 gap-4 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1">
        <Field label="IVA %" error={err.iva_pct}>
          <TextInput value={f.iva_pct} inputMode="decimal" unit="%" onChange={(e) => set('iva_pct', e.target.value)} />
        </Field>
        <Field label="IRPF %" error={err.irpf_pct}>
          <TextInput value={f.irpf_pct} inputMode="decimal" unit="%" onChange={(e) => set('irpf_pct', e.target.value)} />
        </Field>
        <Field label="Vencimiento (texto)">
          <TextInput value={f.cond_pago} placeholder="Contado" maxLength={60} onChange={(e) => set('cond_pago', e.target.value)} />
        </Field>
        <Field label="Días hasta el vencimiento" hint="Vacío = sin fecha de vencimiento (no pasará a vencida)." error={err.venc_dias}>
          <TextInput value={f.venc_dias} inputMode="numeric" placeholder="—" unit="días" onChange={(e) => set('venc_dias', e.target.value.replace(/\D/g, ''))} />
        </Field>
        <Field label="Empezar en (mes)" error={err.start_ym}>
          <Select value={f.start_ym} onChange={(v) => set('start_ym', v)} options={meses.map((m) => ({ value: m, label: mesLabel(m) }))} />
        </Field>
        <Field label="Proyecto">
          <ProyectoCombobox value={f.proyecto} onChange={(v) => set('proyecto', v)} clientId={f.client_id} />
        </Field>
      </div>
      <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
        <Checkbox checked={f.activo} onChange={(v) => set('activo', v)} label="Activa" />
        <div className="flex gap-2.5">
          <Button variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button onClick={() => void enviar()} loading={guardar.isPending} loadingText="Guardando…">
            Guardar programación
          </Button>
        </div>
      </div>
    </section>
  )
}
