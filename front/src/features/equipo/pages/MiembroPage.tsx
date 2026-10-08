import { useState, type FormEvent, type ReactNode } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { Crown, KeyRound, Link2, Mail, RefreshCw, Shuffle, UserRound, UserX } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Breadcrumbs from '../../../shared/ui/Breadcrumbs'
import Button from '../../../shared/ui/Button'
import Checkbox from '../../../shared/ui/Checkbox'
import DangerZone from '../../../shared/ui/DangerZone'
import Field from '../../../shared/ui/Field'
import FormGrid from '../../../shared/ui/FormGrid'
import Notice from '../../../shared/ui/Notice'
import Select from '../../../shared/ui/Select'
import Switch from '../../../shared/ui/Switch'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import { useAuth } from '../../auth/useAuth'
import { CopiarCaja, ErrorCarga, Esqueleto, SetCard, SinGuardar, SinPermiso } from '../components/piezas'
import { campoDeError, clavesEquipo, mensaje, pedir, useAccion, useMiembro, useMiembros } from '../api'
import { fechaHora, hayCambios } from '../logica'
import { ActualizarRespuesta, AltaRespuesta, FichaRespuesta, PasswordRespuesta, VacioSchema, type Enlace, type FichaMiembro, type RolAsignable } from '../schemas'

const MIN = 6

/* /ajustes/equipo/nuevo (alta) y /ajustes/equipo/:id (ficha: acceso,
   contraseña, facturación y baja). team-edit.php del antiguo. */
export default function MiembroPage() {
  const { id } = useParams()
  const { can } = useAuth()
  if (!can('equipo.gestionar')) return <SinPermiso que="gestionar el equipo" />
  return id === 'nuevo' || !id ? <Alta /> : <Ficha id={Number(id)} />
}

function opcionesRol(roles: RolAsignable[]) {
  return roles.map((r) => ({ value: r.clave, label: r.nombre, hint: r.descripcion, disabled: !r.asignable }))
}

/* ---------- Alta ---------- */

function Alta() {
  const lista = useMiembros(false)
  const navigate = useNavigate()
  const { aviso } = useToast()
  const [f, setF] = useState({ username: '', email: '', role: 'editor', password: '', enviar_enlace: false, enviar_correo: true })
  const [err, setErr] = useState<{ campo: string | null; msg: string } | null>(null)
  const crear = useAccion((b: typeof f) => pedir('/api/v1/equipo/miembros', { method: 'POST', body: b, schema: AltaRespuesta }))
  const roles = lista.data?.roles ?? []
  const rol = roles.find((r) => r.clave === f.role)

  function onSubmit(e: FormEvent) {
    e.preventDefault()
    setErr(null)
    if (!f.enviar_enlace && f.password.length < MIN) return setErr({ campo: 'password', msg: f.password ? `La contraseña debe tener al menos ${MIN} caracteres.` : 'Pon una contraseña, o marca que se la ponga esa persona.' })
    crear.mutate(
      { ...f, enviar_correo: f.enviar_enlace && f.enviar_correo && f.email !== '' },
      {
        onSuccess: (r) => {
          aviso(`${r.miembro.username} ya está en el equipo.`)
          if (r.enlace) navigate(`/ajustes/equipo/${r.miembro.id}`, { state: { enlace: r.enlace } })
          else navigate('/ajustes/equipo')
        },
        onError: (er) => setErr({ campo: campoDeError(er), msg: mensaje(er) }),
      },
    )
  }
  const e = (c: string) => (err?.campo === c ? err.msg : undefined)

  return (
    <div className="max-w-[980px]">
      <Breadcrumbs items={[{ label: '← Mi equipo', to: '/ajustes/equipo' }, { label: 'Nuevo miembro' }]} />
      <h1 className="mt-2 mb-6 text-[26px] font-semibold tracking-[-.5px] text-ink-strong">Nuevo miembro del equipo</h1>
      <form onSubmit={onSubmit}>
        <SetCard titulo="Datos de acceso" sub="Con esto entra al panel. Podrás cambiarlo cuando quieras.">
          {err && !err.campo && (
            <Notice tone="error" className="mb-4">
              {err.msg}
            </Notice>
          )}
          <FormGrid>
            <Field label="Usuario" required span={6} error={e('username')}>
              <TextInput autoFocus value={f.username} onChange={(x) => setF({ ...f, username: x.target.value })} maxLength={80} autoComplete="off" />
            </Field>
            <Field label="Correo de Google · opcional" span={6} error={e('email')} hint="Con él puede entrar con «Entrar con Google», sin escribir contraseña.">
              <TextInput type="email" value={f.email} onChange={(x) => setF({ ...f, email: x.target.value })} autoComplete="off" />
            </Field>
            <Field label="Rol" span={6} error={e('role')} hint={<>{rol?.descripcion} Lo que hace cada rol se decide en Roles y permisos.</>}>
              <Select value={f.role} onChange={(v) => setF({ ...f, role: v })} options={opcionesRol(roles)} />
            </Field>
            <Field label="Contraseña" span={6} error={e('password')} hint={f.enviar_enlace ? 'Se la pone esa persona con el enlace.' : `Mínimo ${MIN} caracteres.`}>
              <TextInput type="text" value={f.password} disabled={f.enviar_enlace} onChange={(x) => setF({ ...f, password: x.target.value })} autoComplete="new-password" />
            </Field>
            <div className="col-span-12 space-y-2">
              <Switch
                rowVariant="box"
                checked={f.enviar_enlace}
                onChange={(v) => setF({ ...f, enviar_enlace: v })}
                label="Que se la ponga esta persona"
                description="Se crea un enlace de un solo uso (48 h) para pasárselo. La cuenta no queda abierta mientras tanto."
              />
              {f.enviar_enlace && f.email !== '' && <Checkbox checked={f.enviar_correo} onChange={(v) => setF({ ...f, enviar_correo: v })} label={`Mandárselo también por correo a ${f.email}`} />}
            </div>
          </FormGrid>
          <div className="mt-5 flex gap-2.5">
            <Button type="submit" loading={crear.isPending} loadingText="Creando…">
              Crear acceso
            </Button>
            <Button variant="ghost" to="/ajustes/equipo">
              Cancelar
            </Button>
          </div>
        </SetCard>
      </form>
    </div>
  )
}

/* ---------- Ficha ---------- */

function Ficha({ id }: { id: number }) {
  const q = useMiembro(id)
  const lista = useMiembros(false)
  const location = useLocation()
  const enlaceInicial = (location.state as { enlace?: Enlace } | null)?.enlace ?? null
  if (q.isPending) return <Esqueleto filas={4} />
  if (q.isError) return <ErrorCarga error={q.error} />
  const m = q.data.miembro
  return (
    <div className="max-w-[980px]">
      <Breadcrumbs items={[{ label: '← Mi equipo', to: '/ajustes/equipo' }, { label: m.username }]} />
      <header className="mt-3 mb-6 flex flex-wrap items-center gap-4">
        <div className="relative">
          <Avatar nombre={m.username} foto={m.foto} size={58} />
          {m.es_admin_total && (
            <span title="Acceso total" className="absolute -top-2 -right-2 flex size-[22px] items-center justify-center rounded-full border-2 border-card bg-[#e8a33d] text-white">
              <Crown className="size-3" aria-hidden="true" />
            </span>
          )}
        </div>
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-[25px] font-[650] tracking-[-.4px] text-ink-strong">{m.username}</h1>
          <p className="mt-1 flex flex-wrap items-center gap-2 text-[13px] text-muted">
            <span className={`rounded-full px-2.5 py-0.5 text-[12px] font-semibold ${m.es_admin_total ? 'bg-[#fdf3e3] text-[#96631a] dark:bg-[#2a2210] dark:text-warn' : 'bg-accent-soft text-ink'}`}>{m.role_nombre}</span>
            {m.email ?? 'Sin correo de Google'}
            {!m.activo && <span className="rounded-md bg-[#feecec] px-2 py-px text-[11px] font-semibold text-[#c0343a]">Dado de baja</span>}
          </p>
        </div>
        <Button variant="ghost" icon={<UserRound />} to={`/perfil/${m.id}`}>
          Ver su perfil
        </Button>
      </header>
      <DatosAcceso key={`a${m.username}${m.email}${m.role}`} m={m} roles={lista.data?.roles ?? []} />
      {m.activo && <Contrasena m={m} enlaceInicial={enlaceInicial} />}
      <Facturacion key={`f${m.tarifa_hora}${m.iva_pct}${m.irpf_pct}${m.es_autonomo}`} m={m} />
      {!m.yo && m.activo && <Baja m={m} />}
    </div>
  )
}

function DatosAcceso({ m, roles }: { m: FichaMiembro; roles: RolAsignable[] }) {
  const inicial = { username: m.username, email: m.email ?? '', role: m.role }
  const [f, setF] = useState(inicial)
  const [err, setErr] = useState<{ campo: string | null; msg: string } | null>(null)
  const { aviso } = useToast()
  const { refresh } = useAuth()
  const guardar = useAccion((b: typeof f) => pedir(`/api/v1/equipo/miembros/${m.id}`, { method: 'PATCH', body: b, schema: ActualizarRespuesta }))
  const sucio = hayCambios(f, inicial)
  useUnsavedGuard(sucio)
  const rol = roles.find((r) => r.clave === f.role)
  const e = (c: string) => (err?.campo === c ? err.msg : undefined)

  return (
    <form
      onSubmit={(ev) => {
        ev.preventDefault()
        setErr(null)
        guardar.mutate(f, {
          onSuccess: (r) => {
            aviso('Datos guardados.')
            if (r.recargar) void refresh()
          },
          onError: (er) => setErr({ campo: campoDeError(er), msg: mensaje(er) }),
        })
      }}
    >
      <SetCard titulo="Datos de acceso" sub="Con esto entra al panel. Puedes cambiárselo cuando quieras, sin que tenga que hacer nada.">
        {err && !err.campo && (
          <Notice tone="error" className="mb-4">
            {err.msg}
          </Notice>
        )}
        <FormGrid>
          <Field label="Usuario" required span={4} error={e('username')}>
            <TextInput value={f.username} onChange={(x) => setF({ ...f, username: x.target.value })} maxLength={80} />
          </Field>
          <Field label="Correo de Google" span={4} error={e('email')} hint="Con él puede entrar con «Entrar con Google», sin escribir contraseña.">
            <TextInput type="email" value={f.email} onChange={(x) => setF({ ...f, email: x.target.value })} />
          </Field>
          <Field label="Rol" span={4} error={e('role')} hint={<>{rol?.descripcion} Lo que hace cada rol se decide en Roles y permisos.</>}>
            <Select value={f.role} onChange={(v) => setF({ ...f, role: v })} options={opcionesRol(roles.length ? roles : [{ clave: m.role, nombre: m.role_nombre, descripcion: m.role_descripcion, total: m.es_admin_total, asignable: true }])} disabled={!m.activo} />
          </Field>
        </FormGrid>
        <div className="mt-5 flex items-center gap-3">
          <Button type="submit" loading={guardar.isPending} loadingText="Guardando…" disabled={!sucio}>
            Guardar cambios
          </Button>
          {sucio && <SinGuardar texto="Hay cambios sin guardar" />}
        </div>
      </SetCard>
    </form>
  )
}

function Opcion({ icono, titulo, texto, children }: { icono: ReactNode; titulo: string; texto: ReactNode; children: ReactNode }) {
  return (
    <div className="group flex flex-col gap-3 rounded-2xl border border-line p-[22px] transition-[transform,border-color] duration-150 hover:-translate-y-0.5 hover:border-line-strong max-sm:p-4">
      <span className="flex size-[42px] items-center justify-center rounded-[13px] bg-soft text-[#6f757e] transition-colors group-hover:bg-accent group-hover:text-white dark:text-muted dark:group-hover:text-accent-fg [&>svg]:size-5">{icono}</span>
      <div>
        <h3 className="text-[14.5px] font-semibold text-ink-strong">{titulo}</h3>
        <p className="mt-1 text-[12.5px] leading-[1.5] text-muted">{texto}</p>
      </div>
      <div className="mt-auto">{children}</div>
    </div>
  )
}

function Contrasena({ m, enlaceInicial }: { m: FichaMiembro; enlaceInicial: Enlace | null }) {
  const [nueva, setNueva] = useState('')
  const [errNueva, setErrNueva] = useState('')
  const [generada, setGenerada] = useState<string | null>(null)
  const [enlace, setEnlace] = useState<Enlace | null>(enlaceInicial)
  const [porCorreo, setPorCorreo] = useState(false)
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const accion = useAccion((b: Record<string, unknown>) => pedir(`/api/v1/equipo/miembros/${m.id}/password`, { method: 'POST', body: b, schema: PasswordRespuesta }), [clavesEquipo.miembro(m.id)])
  const anular = useAccion(() => pedir(`/api/v1/equipo/miembros/${m.id}/password-enlace`, { method: 'DELETE', schema: VacioSchema }), [clavesEquipo.miembro(m.id)])

  const err = (e: unknown) => aviso(mensaje(e), { tipo: 'error' })

  return (
    <SetCard titulo="Su contraseña" sub="Tres formas de dejarle el acceso listo. Cualquiera de las tres anula un enlace pendiente, si lo hubiera, y cierra las sesiones que tuviera abiertas.">
      {generada && (
        <div className="mb-4 rounded-2xl border border-[#cde8d5] bg-[#eef7f0] p-4 text-[#12603a] dark:border-ok-line dark:bg-ok-bg dark:text-ok">
          <p className="text-[13px] font-semibold">Contraseña nueva de {m.username}</p>
          <code className="mt-2 block text-[18px] font-bold tracking-[1.5px]">{generada}</code>
          <p className="mt-2 text-[12.5px]">
            Cópiala y pásasela. <b>No se vuelve a mostrar</b>: a partir de ahora solo está guardada cifrada.
          </p>
        </div>
      )}
      {(enlace || m.enlace_password) && (
        <div className="mb-4 rounded-2xl border border-line bg-soft p-4">
          <p className="text-[13px] font-semibold text-ink-strong">Enlace activo para que {m.username} elija su contraseña</p>
          <p className="mt-1 text-[12.5px] text-muted">
            {enlace?.enviado ? 'Se lo hemos mandado por correo. ' : 'Pásaselo por chat o WhatsApp. '}
            Caduca el {fechaHora(enlace?.caduca ?? m.enlace_password?.caduca ?? '')} y solo sirve una vez.
          </p>
          {enlace ? (
            <CopiarCaja className="mt-3" valor={enlace.url} etiqueta="Copiar enlace" />
          ) : (
            <p className="mt-2 text-[12px] text-label">El enlace solo se enseña al crearlo. Si no lo tienes, rehazlo.</p>
          )}
          <Button
            className="mt-3"
            size="sm"
            variant="danger"
            onClick={() =>
              anular.mutate(undefined, {
                onSuccess: () => {
                  setEnlace(null)
                  aviso('Enlace anulado.')
                },
                onError: err,
              })
            }
          >
            Anular el enlace
          </Button>
        </div>
      )}
      <div className="grid grid-cols-[repeat(auto-fit,minmax(250px,1fr))] gap-4">
        <Opcion icono={<KeyRound />} titulo="Ponérsela tú" texto="Escríbela aquí y pásasela. Funciona al momento.">
          <form
            className="flex gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              setErrNueva('')
              if (nueva.length < MIN) return setErrNueva(`La contraseña debe tener al menos ${MIN} caracteres.`)
              accion.mutate(
                { modo: 'fijar', password: nueva },
                {
                  onSuccess: () => {
                    setNueva('')
                    setEnlace(null)
                    setGenerada(null)
                    aviso('Contraseña cambiada. Es la que acabas de escribir.')
                  },
                  onError: (e) => setErrNueva(mensaje(e)),
                },
              )
            }}
          >
            <TextInput aria-label="Contraseña nueva" placeholder="Contraseña nueva" value={nueva} onChange={(e) => setNueva(e.target.value)} invalid={!!errNueva} autoComplete="new-password" />
            <Button type="submit" variant="ghost">
              Poner
            </Button>
          </form>
          {errNueva && <p className="mt-1.5 text-[11.5px] font-medium text-[#ef4444]">{errNueva}</p>}
        </Opcion>
        <Opcion icono={<Shuffle />} titulo="Generar una" texto="El ERP inventa una segura y te la enseña una vez para que se la pases.">
          <Button
            variant="ghost"
            onClick={async () => {
              if (!(await confirm({ title: '¿Generar una contraseña?', message: `La que tiene ahora ${m.username} dejará de valer.`, okLabel: 'Generar' }))) return
              accion.mutate(
                { modo: 'generar' },
                {
                  onSuccess: (r) => {
                    setGenerada(r.password ?? null)
                    setEnlace(null)
                  },
                  onError: err,
                },
              )
            }}
          >
            Generar contraseña
          </Button>
        </Opcion>
        <Opcion icono={<Link2 />} titulo={`Que la elija ${m.username}`} texto="Se crea un enlace de un solo uso, que caduca en 48 h, para que la elija él mismo.">
          {m.email && <Checkbox className="mb-2.5" checked={porCorreo} onChange={setPorCorreo} label={`Mandárselo a ${m.email}`} />}
          <Button
            variant="ghost"
            icon={enlace || m.enlace_password ? <RefreshCw /> : porCorreo ? <Mail /> : <Link2 />}
            onClick={async () => {
              if (!(await confirm({ title: '¿Crear el enlace?', message: 'Si había otro enlace, deja de valer.', okLabel: 'Crear enlace' }))) return
              accion.mutate(
                { modo: 'enlace', enviar_correo: porCorreo },
                {
                  onSuccess: (r) => {
                    setEnlace(r.enlace ?? null)
                    setGenerada(null)
                    aviso(r.enlace?.enviado ? 'Enlace creado y enviado por correo.' : 'Enlace creado.')
                  },
                  onError: err,
                },
              )
            }}
          >
            {enlace || m.enlace_password ? 'Rehacer enlace' : 'Crear enlace'}
          </Button>
        </Opcion>
      </div>
      <p className="mt-4 text-[12px] leading-[1.5] text-label">
        ¿Y ver la contraseña que tiene ahora? No se puede: se guarda cifrada de forma que nadie, ni el ERP, puede leerla. Si no la recuerda, ponle una nueva.
      </p>
    </SetCard>
  )
}

function Facturacion({ m }: { m: FichaMiembro }) {
  const inicial = { es_autonomo: m.es_autonomo, tarifa_hora: m.tarifa_hora.replace('.', ','), iva_pct: m.iva_pct.replace('.', ','), irpf_pct: m.irpf_pct.replace('.', ',') }
  const [f, setF] = useState(inicial)
  const [err, setErr] = useState<{ campo: string | null; msg: string } | null>(null)
  const { aviso } = useToast()
  const guardar = useAccion((b: typeof f) => pedir(`/api/v1/equipo/miembros/${m.id}/facturacion`, { method: 'PATCH', body: b, schema: FichaRespuesta }))
  const sucio = hayCambios(f, inicial)
  const e = (c: string) => (err?.campo === c ? err.msg : undefined)
  return (
    <form
      onSubmit={(ev) => {
        ev.preventDefault()
        setErr(null)
        guardar.mutate(f, { onSuccess: () => aviso('Datos de facturación guardados.'), onError: (er) => setErr({ campo: campoDeError(er), msg: mensaje(er) }) })
      }}
    >
      <SetCard titulo="Si factura sus horas">
        <Switch rowVariant="box" checked={f.es_autonomo} onChange={(v) => setF({ ...f, es_autonomo: v })} label="Es autónomo y factura sus horas" description="Las horas que se apunte pasan a la contabilidad con esta tarifa." />
        <div className={`mt-4 transition-opacity ${f.es_autonomo ? '' : 'opacity-50'}`}>
          <FormGrid>
            <Field label="Tarifa por hora" span={4} error={e('tarifa_hora')}>
              <TextInput inputMode="decimal" unit="€" value={f.tarifa_hora} onChange={(x) => setF({ ...f, tarifa_hora: x.target.value })} />
            </Field>
            <Field label="IVA" span={4} error={e('iva_pct')}>
              <TextInput inputMode="decimal" unit="%" value={f.iva_pct} onChange={(x) => setF({ ...f, iva_pct: x.target.value })} />
            </Field>
            <Field label="IRPF · retención" span={4} error={e('irpf_pct')}>
              <TextInput inputMode="decimal" unit="%" value={f.irpf_pct} onChange={(x) => setF({ ...f, irpf_pct: x.target.value })} />
            </Field>
          </FormGrid>
        </div>
        <div className="mt-5 flex items-center gap-3">
          <Button type="submit" loading={guardar.isPending} loadingText="Guardando…" disabled={!sucio}>
            Guardar
          </Button>
          {sucio && <SinGuardar texto="Hay cambios sin guardar" />}
        </div>
      </SetCard>
    </form>
  )
}

function Baja({ m }: { m: FichaMiembro }) {
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const navigate = useNavigate()
  const baja = useAccion(() => pedir(`/api/v1/equipo/miembros/${m.id}`, { method: 'DELETE', schema: VacioSchema }))
  return (
    <DangerZone
      title={`Quitarle el acceso a ${m.username}`}
      text="Deja de poder entrar al panel y se cierran sus sesiones. Sus tareas cerradas y todo lo que haya hecho se quedan donde están; las abiertas se quedan sin responsable."
      action={
        <Button
          variant="danger"
          icon={<UserX />}
          onClick={async () => {
            if (!(await confirm({ title: 'Eliminar del equipo', message: `¿Eliminar a ${m.username} del equipo? Perderá el acceso al panel.`, okLabel: 'Quitar del equipo', danger: true }))) return
            baja.mutate(undefined, {
              onSuccess: () => {
                aviso(`${m.username} ya no tiene acceso.`)
                navigate('/ajustes/equipo')
              },
              onError: (e) => aviso(mensaje(e), { tipo: 'error' }),
            })
          }}
        >
          Dar de baja
        </Button>
      }
    />
  )
}
