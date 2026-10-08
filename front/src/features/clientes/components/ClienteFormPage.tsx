import { useMemo, useState, type ReactNode } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeft,
  BarChart3,
  Calendar,
  Check,
  CheckSquare,
  Euro,
  ExternalLink,
  List,
  Lock,
  Mail,
  MessageCircle,
  Plus,
  Store,
  TrendingUp,
  UserRound,
} from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Card, { CardHeader } from '../../../shared/ui/Card'
import EmptyState from '../../../shared/ui/EmptyState'
import Field from '../../../shared/ui/Field'
import FormGrid, { FormZone } from '../../../shared/ui/FormGrid'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import Segmented from '../../../shared/ui/Segmented'
import Select from '../../../shared/ui/Select'
import Switch from '../../../shared/ui/Switch'
import { TextArea, TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { ApiError } from '../../../shared/api/client'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import { mensajeError, useCrearCliente, useCrearTipo, useDatosCliente, useGuardarCliente, useTipos } from '../api'
import { TIPOS_ACCESO } from '../lib/etiquetas'
import {
  cuerpoDesdeForm,
  erroresBasicos,
  filaAcceso,
  filaDetalle,
  filaFase,
  filaItem,
  filaProgreso,
  formDesdeDatos,
  formVacio,
  hayCambios,
  MESES,
  seccionDeCampo,
  seccionDeHash,
  type FilaProgreso,
  type FormCliente,
  type SeccionForm,
} from '../lib/formulario'
import type { Fase } from '../schemas'
import ListaEditable from './ListaEditable'
import { usePermisosClientes } from './permisos'

const SECCIONES: { value: SeccionForm; label: string; icon: ReactNode }[] = [
  { value: 'datos', label: 'Datos', icon: <UserRound /> },
  { value: 'facturacion', label: 'Facturación', icon: <Euro /> },
  { value: 'progreso', label: 'Progreso', icon: <TrendingUp /> },
  { value: 'plan', label: 'Plan', icon: <List /> },
  { value: 'accesos', label: 'Accesos', icon: <ExternalLink /> },
  { value: 'progreso-cliente', label: 'Progreso del cliente', icon: <CheckSquare /> },
]

const ESTADOS_FASE: { value: Fase['estado']; label: string }[] = [
  { value: 'done', label: 'Completada' },
  { value: 'now', label: 'Actual' },
  { value: '', label: 'Pendiente' },
]

/* Alta y edición del cliente (edit.php): seis secciones, una visible a la
   vez, que se guardan juntas desde la barra de abajo. */
export default function ClienteFormPage() {
  const params = useParams()
  const id = params.id ? Number(params.id) : 0
  const esAlta = !params.id
  const datos = useDatosCliente(Number.isInteger(id) ? id : 0)
  const p = usePermisosClientes()

  if (!esAlta && (!Number.isInteger(id) || id <= 0 || (datos.error instanceof ApiError && datos.error.status === 404))) {
    return <EmptyState icon={<UserRound />} title="No encuentro ese cliente" text="El enlace no lleva a ningún cliente válido." actions={<Button to="/clientes">Ver mis clientes</Button>} />
  }
  if (esAlta ? !p.crear : !p.editar) {
    return <Notice tone="warn">No tienes permiso para {esAlta ? 'dar de alta clientes' : 'editar este cliente'}.</Notice>
  }
  if (!esAlta) {
    if (datos.error) return <Notice tone="error">{mensajeError(datos.error, 'No se ha podido cargar el cliente.')}</Notice>
    if (!datos.data) return <p className="py-[60px] text-center text-[13.5px] text-muted">Cargando…</p>
  }
  const inicial = datos.data ? formDesdeDatos(datos.data.cliente) : formVacio()
  // La key reinicia el formulario si se vuelve a cargar otro cliente.
  return <Formulario key={id || 'nuevo'} id={id} inicial={inicial} />
}

function Formulario({ id, inicial }: { id: number; inicial: FormCliente }) {
  const esAlta = id === 0
  const navigate = useNavigate()
  const location = useLocation()
  const p = usePermisosClientes()
  const { aviso } = useToast()
  const [f, setF] = useState<FormCliente>(inicial)
  const [base, setBase] = useState<FormCliente>(inicial)
  const [seccion, setSeccion] = useState<SeccionForm>(() => seccionDeHash(location.hash))
  const [errores, setErrores] = useState<Partial<Record<string, string>>>({})
  const crear = useCrearCliente()
  const guardar = useGuardarCliente(id)
  const guardando = crear.isPending || guardar.isPending
  const dirty = useMemo(() => hayCambios(f, base), [f, base])
  useUnsavedGuard(dirty && !guardando)
  // En edición, lo que ve el cliente en su portal pide su propio permiso.
  const bloqueoPortal = !esAlta && !p.portal

  const set = <K extends keyof FormCliente>(k: K, v: FormCliente[K]) => setF((x) => ({ ...x, [k]: v }))

  async function enviar() {
    const basicos = erroresBasicos(f, esAlta)
    if (Object.keys(basicos).length) {
      setErrores(basicos)
      setSeccion('datos')
      return
    }
    setErrores({})
    const cuerpo = cuerpoDesdeForm(f)
    if (bloqueoPortal) {
      // Sin permiso de portal esos bloques no se mandan: el servidor los rechazaría.
      delete cuerpo.estado
      delete cuerpo.plan
      delete cuerpo.accesos
      delete cuerpo.tareas
    }
    try {
      if (esAlta) {
        const r = await crear.mutateAsync(cuerpo)
        aviso('Creado correctamente.')
        setBase(f)
        navigate(`/clientes/${r.id}`, { replace: true })
      } else {
        const r = await guardar.mutateAsync(cuerpo)
        aviso('Cambios guardados.')
        const nuevo = formDesdeDatos(r.cliente)
        setF(nuevo)
        setBase(nuevo)
        navigate(`/clientes/${id}`)
      }
    } catch (e) {
      if (e instanceof ApiError && e.codigo === 'validacion') {
        const campo = (e as ApiError & { campo?: string }).campo ?? campoDeMensaje(e.message)
        setErrores({ [campo]: e.message })
        setSeccion(seccionDeCampo(campo))
      } else {
        aviso(mensajeError(e, 'No se ha podido guardar.'), { tipo: 'error' })
      }
    }
  }

  const listaErrores = Object.values(errores).filter(Boolean) as string[]

  return (
    <div className="max-w-[900px]">
      <PageHeader
        title={esAlta ? 'Nuevo cliente' : 'Editar cliente'}
        lead="Rellena la ficha; el portal del cliente se arma con esto."
        actions={
          !esAlta && (
            <Button variant="ghost" size="sm" to={`/clientes/${id}`} icon={<ArrowLeft />}>
              Volver a la ficha
            </Button>
          )
        }
        className="!mb-4"
      />

      <div className="mb-[18px] max-sm:-mx-1 max-sm:overflow-x-auto max-sm:px-1">
        <Segmented aria-label="Secciones de la ficha" value={seccion} onChange={setSeccion} items={SECCIONES} className="max-sm:flex-nowrap" />
      </div>

      {listaErrores.length > 0 && (
        <Notice tone="error">
          {listaErrores.map((m) => (
            <div key={m}>• {m}</div>
          ))}
        </Notice>
      )}

      {seccion === 'datos' && <SeccionDatos f={f} set={set} errores={errores} id={id} />}
      {seccion === 'facturacion' && <SeccionFacturacion f={f} set={set} errores={errores} />}
      {seccion !== 'datos' && seccion !== 'facturacion' && bloqueoPortal && (
        <Notice tone="warn">Para cambiar lo que el cliente ve en su portal hace falta el permiso «Publicar en su portal». Puedes verlo, pero no tocarlo.</Notice>
      )}
      <fieldset disabled={bloqueoPortal} className="min-w-0">
        {seccion === 'progreso' && <SeccionProgreso f={f} set={set} disabled={bloqueoPortal} />}
        {seccion === 'plan' && <SeccionPlan f={f} set={set} disabled={bloqueoPortal} />}
        {seccion === 'accesos' && <SeccionAccesos f={f} set={set} disabled={bloqueoPortal} />}
        {seccion === 'progreso-cliente' && <SeccionProgresoCliente f={f} set={set} disabled={bloqueoPortal} />}
      </fieldset>

      <div className="sticky bottom-0 z-20 -mx-4 mt-6 flex flex-wrap items-center gap-2.5 border-t border-line bg-page/90 px-4 py-3.5 backdrop-blur-[6px] md:-mx-6 md:px-6 lg:-mx-[52px] lg:px-[52px]">
        <Button onClick={() => void enviar()} icon={<Check />} loading={guardando} loadingText="Guardando…">
          Guardar cliente
        </Button>
        <Button variant="ghost" onClick={() => navigate(esAlta ? '/clientes' : `/clientes/${id}`)} disabled={guardando}>
          Cancelar
        </Button>
        {dirty && (
          <span className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-[#b7791f] dark:text-warn">
            <span className="size-[7px] rounded-full bg-[#e8a33d]" aria-hidden="true" /> Sin guardar
          </span>
        )}
        <span className="ml-auto text-[12.5px] text-muted max-md:ml-0 max-md:w-full">Se guardan todas las secciones a la vez, no solo la que estés viendo.</span>
      </div>
    </div>
  )
}

/* La API manda el campo (`campo`), pero el cliente HTTP compartido no lo
   guarda en ApiError: mientras tanto se deduce del mensaje para llevar a su sección. */
function campoDeMensaje(msg: string) {
  const reglas: [RegExp, string][] = [
    [/nombre es obligatorio/i, 'name'],
    [/correo de Google/i, 'login_email'],
    [/email de facturación/i, 'fact_email'],
    [/usuario/i, 'username'],
    [/contraseña/i, 'password'],
    [/tipo de cliente/i, 'tipo_id'],
    [/enlace|tipo de acceso/i, 'accesos'],
    [/fase/i, 'estado'],
    [/su mes|progreso/i, 'tareas'],
  ]
  return reglas.find(([r]) => r.test(msg))?.[1] ?? 'name'
}

type PropsSeccion = { f: FormCliente; set: <K extends keyof FormCliente>(k: K, v: FormCliente[K]) => void }

function TarjetaSeccion({ titulo, sub, children }: { titulo: string; sub: string; children: ReactNode }) {
  return (
    <Card className="mb-5">
      <CardHeader title={titulo} subtitle={sub} className="!mb-6" />
      {children}
    </Card>
  )
}

function SeccionDatos({ f, set, errores, id }: PropsSeccion & { errores: Partial<Record<string, string>>; id: number }) {
  const { data: tipos } = useTipos()
  const crearTipo = useCrearTipo()
  const { prompt } = useConfirm()
  const { aviso } = useToast()
  const p = usePermisosClientes()
  const opcionesMes = [{ value: '', label: '—' }, ...MESES.map((m) => ({ value: m, label: m }))]
  if (f.actual && !MESES.includes(f.actual)) opcionesMes.push({ value: f.actual, label: f.actual })

  async function nuevoTipo() {
    const nombre = await prompt({
      title: 'Nuevo tipo de cliente',
      message: 'Se crea al momento con todas las secciones visibles. Podrás afinar qué ve cada tipo en Ajustes › Tipos de cliente.',
      placeholder: 'Ej: SEO completo, Solo web…',
      okLabel: 'Crear tipo',
    })
    if (!nombre) return
    try {
      const r = await crearTipo.mutateAsync({ nombre, rapido: true })
      set('tipo_id', r.tipo.id)
      aviso(r.dup ? 'Ese tipo ya existía: seleccionado.' : `Tipo «${r.tipo.nombre}» creado.`)
    } catch (e) {
      aviso(e instanceof ApiError && e.status !== 0 ? e.message : 'No se pudo crear el tipo.', { tipo: 'error' })
    }
  }

  return (
    <TarjetaSeccion titulo="Datos del cliente" sub="Quién es y cómo entra a su portal.">
      <FormGrid>
        <FormZone title="El negocio" />
        <Field label="Nombre del negocio" icon={<Store />} span={6} error={errores.name} required>
          <TextInput value={f.name} onChange={(e) => set('name', e.target.value)} maxLength={160} autoFocus={id === 0} />
        </Field>
        <Field label="Saludo" icon={<MessageCircle />} span={4} hint="Cómo le saluda el portal.">
          <TextInput value={f.saludo} onChange={(e) => set('saludo', e.target.value)} placeholder="Ej: María" maxLength={160} />
        </Field>
        <Field label="Iniciales" icon={<UserRound />} span={2} hint="Su avatar." error={errores.iniciales}>
          <TextInput value={f.iniciales} onChange={(e) => set('iniciales', e.target.value.toUpperCase())} placeholder="CA" maxLength={4} />
        </Field>

        <FormZone title="Cómo entra a su portal" />
        <Field label="Usuario" icon={<UserRound />} span={4} error={errores.username} required>
          <TextInput value={f.username} onChange={(e) => set('username', e.target.value.replace(/\s/g, ''))} maxLength={80} autoComplete="off" />
        </Field>
        <Field label="Contraseña" icon={<Lock />} span={4} error={errores.password} required={id === 0} hint={id === 0 ? 'Al menos 6 caracteres.' : undefined}>
          <TextInput
            type="password"
            value={f.password}
            onChange={(e) => set('password', e.target.value)}
            placeholder={id === 0 ? '' : 'Déjalo vacío para no cambiarla'}
            autoComplete="new-password"
          />
        </Field>
        <Field label="Mes actual" icon={<Calendar />} span={4} hint="El mes que abre por defecto.">
          <Select value={f.actual} onChange={(v) => set('actual', v)} options={opcionesMes} placeholder="Junio" />
        </Field>
        <Field
          label="Correo de Google para entrar"
          icon={<Mail />}
          span={12}
          error={errores.login_email}
          hint="Si pones aquí su Gmail, podrá entrar al portal pulsando «Entrar con Google», sin contraseña. Déjalo vacío si no lo usa."
        >
          <TextInput type="email" value={f.login_email} onChange={(e) => set('login_email', e.target.value)} placeholder="cliente@gmail.com" maxLength={160} />
        </Field>

        <FormZone title="Qué ve en su portal" />
        <Field label="Tipo de cliente" icon={<List />} span={12} error={errores.tipo_id} hint="El tipo decide qué secciones ve. Puedes crear uno aquí mismo o gestionarlos en Tipos de cliente.">
          <div className="flex flex-wrap gap-2">
            <Select
              className="min-w-[240px] flex-1"
              value={f.tipo_id ?? 0}
              onChange={(v) => set('tipo_id', v === 0 ? null : v)}
              options={[{ value: 0, label: '— Sin tipo —' }, ...(tipos ?? []).map((t) => ({ value: t.id, label: t.nombre }))]}
            />
            {p.tipos && (
              <Button variant="ghost" icon={<Plus />} onClick={() => void nuevoTipo()} loading={crearTipo.isPending} loadingText="Creando…">
                Nuevo tipo
              </Button>
            )}
          </div>
        </Field>
        <div className="col-span-full flex flex-col gap-3">
          <Switch
            rowVariant="box"
            checked={f.conversiones}
            onChange={(v) => set('conversiones', v)}
            label="Tiene conversiones"
            description="Enseña Métricas y oportunidades en su portal. Apágalo en proyectos de solo web."
          />
          {id > 0 && (
            <Button variant="ghost" size="sm" to={`/clientes/${id}/metricas`} icon={<BarChart3 />} className="self-start">
              Configurar sus métricas de Google (web, Analytics y conversiones)
            </Button>
          )}
          <Switch
            rowVariant="box"
            checked={f.activo}
            onChange={(v) => set('activo', v)}
            label="Cliente activo"
            description="Si lo apagas, pasa a «Clientes no activos» y deja de contar como cliente en alta."
          />
        </div>
      </FormGrid>
    </TarjetaSeccion>
  )
}

function SeccionFacturacion({ f, set, errores }: PropsSeccion & { errores: Partial<Record<string, string>> }) {
  return (
    <TarjetaSeccion titulo="Datos de facturación" sub="Se copian solos a cada factura que le hagas. También se rellenan al crear la primera.">
      <FormGrid>
        <Field label="Nombre fiscal" span={6} error={errores.fact_nombre}>
          <TextInput value={f.fact_nombre} onChange={(e) => set('fact_nombre', e.target.value)} placeholder={f.name || 'Nombre fiscal'} maxLength={200} />
        </Field>
        <Field label="NIF / CIF" span={6} error={errores.fact_nif}>
          <TextInput value={f.fact_nif} onChange={(e) => set('fact_nif', e.target.value)} placeholder="B12345678" maxLength={40} />
        </Field>
        <Field label="Dirección" span={12} error={errores.fact_dir}>
          <TextInput value={f.fact_dir} onChange={(e) => set('fact_dir', e.target.value)} placeholder="Calle, número, código postal y ciudad" maxLength={300} />
        </Field>
        <Field label="Email de facturación" span={12} error={errores.fact_email}>
          <TextInput type="email" value={f.fact_email} onChange={(e) => set('fact_email', e.target.value)} placeholder="facturas@cliente.com" maxLength={160} />
        </Field>
      </FormGrid>
    </TarjetaSeccion>
  )
}

function SeccionProgreso({ f, set, disabled }: PropsSeccion & { disabled: boolean }) {
  return (
    <TarjetaSeccion titulo="Progreso del proyecto" sub="La etapa y las fases que ve el cliente en la cabecera de su portal.">
      <FormGrid>
        <Field label="Etapa actual" span={8}>
          <TextInput value={f.est_nombre} onChange={(e) => set('est_nombre', e.target.value)} placeholder="Crecimiento y captación de clientes" maxLength={200} />
        </Field>
        <Field label="Etiqueta" span={4}>
          <TextInput value={f.est_etiqueta} onChange={(e) => set('est_etiqueta', e.target.value)} placeholder="Etapa 3 de 4" maxLength={80} />
        </Field>
        <Field label="Lo siguiente" span={12}>
          <TextArea value={f.est_siguiente} onChange={(e) => set('est_siguiente', e.target.value)} placeholder="Qué viene ahora, explicado para el cliente." maxLength={2000} />
        </Field>
      </FormGrid>
      <ListaEditable
        titulo="Fases"
        filas={f.fases}
        onChange={(v) => set('fases', v)}
        nueva={() => filaFase()}
        textoAnadir="Añadir fase"
        disabled={disabled}
        columnas={[
          { titulo: 'Nombre de la fase', ancho: 'minmax(0,1.3fr)', render: (x, c) => <TextInput variant="inline" value={x.t} onChange={(e) => c({ t: e.target.value })} placeholder="Auditoría" maxLength={120} aria-label="Nombre de la fase" /> },
          { titulo: 'Subtítulo', ancho: 'minmax(0,1fr)', render: (x, c) => <TextInput variant="inline" value={x.s} onChange={(e) => c({ s: e.target.value })} placeholder="y arranque" maxLength={120} aria-label="Subtítulo" /> },
          {
            titulo: 'Estado',
            ancho: '150px',
            render: (x, c) => <Select variant="inline" aria-label="Estado de la fase" value={x.estado} onChange={(v) => c({ estado: v })} options={ESTADOS_FASE} disabled={disabled} />,
          },
        ]}
      />
    </TarjetaSeccion>
  )
}

function SeccionPlan({ f, set, disabled }: PropsSeccion & { disabled: boolean }) {
  return (
    <TarjetaSeccion titulo="Plan contratado" sub="Lo que el cliente ve en la sección «Plan» de su portal.">
      <Field label="Resumen">
        <TextArea value={f.plan_resumen} onChange={(e) => set('plan_resumen', e.target.value)} placeholder="Cada mes trabajamos…" maxLength={4000} />
      </Field>
      <div className="mt-5">
        <ListaEditable
          titulo="Lo que incluye · número + concepto"
          filas={f.items}
          onChange={(v) => set('items', v)}
          nueva={filaItem}
          textoAnadir="Añadir concepto"
          disabled={disabled}
          columnas={[
            { titulo: 'Número', ancho: '90px', render: (x, c) => <TextInput variant="inline" value={x.n} onChange={(e) => c({ n: e.target.value })} placeholder="4" maxLength={20} aria-label="Número" className="font-semibold" /> },
            { titulo: 'Concepto', ancho: 'minmax(0,1fr)', render: (x, c) => <TextInput variant="inline" value={x.t} onChange={(e) => c({ t: e.target.value })} placeholder="Artículos de blog al mes" maxLength={200} aria-label="Concepto" /> },
          ]}
        />
      </div>
      <div className="mt-5">
        <ListaEditable
          titulo="Detalle completo · título + texto"
          filas={f.detalle}
          onChange={(v) => set('detalle', v)}
          nueva={filaDetalle}
          textoAnadir="Añadir apartado"
          disabled={disabled}
          columnas={[
            { titulo: 'Título', ancho: 'minmax(0,1fr)', render: (x, c) => <TextInput variant="inline" value={x.h} onChange={(e) => c({ h: e.target.value })} placeholder="Página de servicio · 1 al mes" maxLength={200} aria-label="Título" /> },
            { titulo: 'Texto', ancho: 'minmax(0,1.6fr)', render: (x, c) => <TextArea variant="inline" value={x.p} onChange={(e) => c({ p: e.target.value })} placeholder="Explica…" maxLength={4000} aria-label="Texto" className="!min-h-[38px]" rows={2} /> },
          ]}
        />
      </div>
    </TarjetaSeccion>
  )
}

function SeccionAccesos({ f, set, disabled }: PropsSeccion & { disabled: boolean }) {
  return (
    <TarjetaSeccion titulo="Accesos" sub="Los enlaces y herramientas que el cliente abre desde su portal (Figma, Drive, su web…).">
      <ListaEditable
        filas={f.accesos}
        onChange={(v) => set('accesos', v)}
        nueva={() => filaAcceso()}
        textoAnadir="Añadir acceso"
        disabled={disabled}
        columnas={[
          { titulo: 'Título', ancho: 'minmax(0,1fr)', render: (x, c) => <TextInput variant="inline" value={x.b} onChange={(e) => c({ b: e.target.value })} placeholder="Diseño en Figma" maxLength={160} aria-label="Título" /> },
          { titulo: 'Descripción', ancho: 'minmax(0,1fr)', render: (x, c) => <TextInput variant="inline" value={x.s} onChange={(e) => c({ s: e.target.value })} placeholder="Mockups de tu web" maxLength={300} aria-label="Descripción" /> },
          { titulo: 'Enlace', ancho: 'minmax(0,1.2fr)', render: (x, c) => <TextInput variant="inline" type="url" value={x.u} onChange={(e) => c({ u: e.target.value })} placeholder="https://…" maxLength={500} aria-label="Enlace" /> },
          { titulo: 'Tipo', ancho: '140px', render: (x, c) => <Select variant="inline" aria-label="Tipo de acceso" value={x.tipo} onChange={(v) => c({ tipo: v })} options={TIPOS_ACCESO} disabled={disabled} /> },
        ]}
      />
    </TarjetaSeccion>
  )
}

const ESTADOS_PROGRESO: { value: FilaProgreso['estado']; label: string }[] = [
  { value: 'completado', label: 'Completado' },
  { value: 'pendiente', label: 'En curso' },
]

function SeccionProgresoCliente({ f, set, disabled }: PropsSeccion & { disabled: boolean }) {
  const ultimoMes = f.progreso.length ? f.progreso[f.progreso.length - 1].mes : f.actual
  return (
    <TarjetaSeccion titulo="Progreso del cliente" sub="Lo que ve en su sección de Progreso: qué se ha hecho y qué está en curso cada mes, explicado para él.">
      <Notice tone="info">
        Si alguna de sus tareas está marcada como visible para el cliente, al crear, cambiar o completar una tarea esto se vuelve a generar desde ellas.
      </Notice>
      <datalist id="meses-progreso">
        {MESES.map((m) => (
          <option key={m} value={m} />
        ))}
      </datalist>
      <ListaEditable
        filas={f.progreso}
        onChange={(v) => set('progreso', v)}
        nueva={() => filaProgreso(ultimoMes)}
        textoAnadir="Añadir tarea"
        disabled={disabled}
        columnas={[
          {
            titulo: 'Mes',
            ancho: '130px',
            render: (x, c) => <TextInput variant="inline" list="meses-progreso" value={x.mes} onChange={(e) => c({ mes: e.target.value })} placeholder="Junio" maxLength={40} aria-label="Mes" className="font-semibold" />,
          },
          {
            titulo: 'Estado',
            ancho: '130px',
            render: (x, c) => <Select variant="inline" aria-label="Estado" value={x.estado} onChange={(v) => c({ estado: v })} options={ESTADOS_PROGRESO} disabled={disabled} />,
          },
          {
            titulo: 'Tarea',
            ancho: 'minmax(0,1fr)',
            render: (x, c) => (
              <div className="flex flex-col gap-1">
                <TextInput variant="inline" value={x.t} onChange={(e) => c({ t: e.target.value })} placeholder="Título de la tarea" maxLength={255} aria-label="Título de la tarea" className="font-semibold" />
                <TextArea variant="inline" value={x.d} onChange={(e) => c({ d: e.target.value })} placeholder="Explicación para el cliente" maxLength={4000} aria-label="Explicación para el cliente" className="!min-h-[38px]" rows={2} />
              </div>
            ),
          },
        ]}
      />
    </TarjetaSeccion>
  )
}
