import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Copy, Link2, MessageCircle, Pencil, Plus, Trash2, UserCheck, UserRound, UserX } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import IconButton from '../../../shared/ui/IconButton'
import Select from '../../../shared/ui/Select'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { useAuth } from '../../auth/useAuth'
import AjustesCabecera from '../components/AjustesCabecera'
import { CopiarCaja, ErrorCarga, Esqueleto, SetCard, SinPermiso } from '../components/piezas'
import { clavesEquipo, mensaje, pedir, useAccion, useInvitaciones, useMiembros } from '../api'
import { caducaEn, fechaCumple } from '../logica'
import { ActualizarRespuesta, InvitacionCreadaRespuesta, UrlRespuesta, VacioSchema, type Miembro, type RolAsignable } from '../schemas'

/* Ajustes › Mi equipo (team.php): quién entra al panel, con qué rol, y los
   enlaces de registro. */
export default function EquipoPage() {
  const { can } = useAuth()
  const [bajas, setBajas] = useState(false)
  const q = useMiembros(bajas)
  if (!can('equipo.gestionar')) return <SinPermiso que="gestionar el equipo" />
  const d = q.data
  return (
    <div className="max-w-[1180px]">
      <AjustesCabecera
        titulo="Mi equipo"
        sub={d ? `Quién entra al panel y qué puede hacer · ${d.total} persona(s) con acceso · ${d.clientes_alta} cliente(s) de alta.` : 'Quién entra al panel y qué puede hacer.'}
        accion={
          <>
            <Button variant="ghost" to="/ajustes/roles" icon={<UserCheck />}>
              Roles y permisos
            </Button>
            <Button to="/ajustes/equipo/nuevo" icon={<Plus />}>
              Nuevo miembro
            </Button>
          </>
        }
      />
      {q.isPending ? <Esqueleto /> : q.isError ? <ErrorCarga error={q.error} /> : <Lista miembros={d?.items ?? []} roles={d?.roles ?? []} bajas={bajas} />}
      <p className="mt-3 mb-6 flex flex-wrap items-center gap-x-3 text-[12.5px] text-muted">
        <span>
          ¿Quieres cambiar <b className="text-ink">qué puede hacer</b> un rol, o crear uno nuevo? Se hace en{' '}
          <Link to="/ajustes/roles" className="underline-offset-2 hover:underline">
            Roles y permisos
          </Link>
          .
        </span>
        {(d?.bajas ?? 0) > 0 || bajas ? (
          <button type="button" className="font-semibold text-ink underline-offset-2 hover:underline" onClick={() => setBajas((b) => !b)}>
            {bajas ? 'Ver el equipo activo' : `Ver dados de baja (${d?.bajas ?? 0})`}
          </button>
        ) : null}
      </p>
      {!bajas && <RegistroPorEnlace />}
    </div>
  )
}

function Lista({ miembros, roles, bajas }: { miembros: Miembro[]; roles: RolAsignable[]; bajas: boolean }) {
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const { refresh } = useAuth()
  const cambiarRol = useAccion(({ id, rol }: { id: number; rol: string }) => pedir(`/api/v1/equipo/miembros/${id}/rol`, { method: 'POST', body: { rol }, schema: ActualizarRespuesta }))
  const baja = useAccion((id: number) => pedir(`/api/v1/equipo/miembros/${id}`, { method: 'DELETE', schema: VacioSchema }))
  const reactivar = useAccion((id: number) => pedir(`/api/v1/equipo/miembros/${id}/reactivar`, { method: 'POST', schema: VacioSchema.passthrough() }))

  const opciones = roles.map((r) => ({ value: r.clave, label: r.nombre, hint: r.descripcion, disabled: !r.asignable }))

  async function quitar(m: Miembro) {
    const ok = await confirm({ title: 'Eliminar del equipo', message: `¿Eliminar a ${m.username} del equipo? Perderá el acceso al panel. Sus tareas cerradas y todo lo que haya hecho se quedan donde están.`, okLabel: 'Quitar del equipo', danger: true })
    if (!ok) return
    baja.mutate(m.id, { onSuccess: () => aviso(`${m.username} ya no tiene acceso.`), onError: (e) => aviso(mensaje(e), { tipo: 'error' }) })
  }

  if (!miembros.length) return <Card padding="md" className="text-[13px] text-muted">{bajas ? 'No hay nadie dado de baja.' : 'No hay nadie en el equipo.'}</Card>

  return (
    <Card padding="none" className="overflow-hidden">
      <ul>
        {miembros.map((m) => (
          <li
            key={m.id}
            className="group grid grid-cols-[40px_1fr_220px_132px] items-center gap-4 border-b border-line2 px-5 py-[15px] last:border-b-0 hover:bg-hover-row max-[860px]:grid-cols-[40px_1fr] max-[860px]:gap-y-2.5 max-sm:px-4"
          >
            <Link to={`/perfil/${m.id}`} className="relative block w-fit" aria-label={`Perfil de ${m.username}`}>
              <Avatar nombre={m.username} foto={m.foto} size={38} />
              {m.es_admin_total && (
                <span title="Administrador · acceso total" className="absolute -right-1 -bottom-1 flex size-[17px] items-center justify-center rounded-full border-2 border-card bg-[#e8a33d] text-white">
                  <UserCheck className="size-2.5" aria-hidden="true" />
                </span>
              )}
            </Link>
            <div className="min-w-0">
              <p className="flex flex-wrap items-center gap-2 text-[14.5px] font-semibold text-ink-strong">
                <Link to={`/ajustes/equipo/${m.id}`} className="truncate hover:underline">
                  {m.username}
                </Link>
                {m.yo && <span className="text-[12px] font-medium text-muted">tú</span>}
                {m.es_admin_total && (
                  <span className="inline-flex items-center gap-1 rounded-full border border-[#f3dcbf] bg-[#fff4e5] px-2 py-px text-[10px] font-bold tracking-[.4px] text-[#b7791f] uppercase dark:border-[#4a3a17] dark:bg-[#2a2210]">
                    <UserCheck className="size-2.5" aria-hidden="true" />
                    Admin
                  </span>
                )}
              </p>
              <p className="mt-0.5 truncate text-[12.5px] text-muted">
                {m.email ?? 'Sin correo · no puede entrar con Google'}
                {m.desde && ` · desde ${m.desde}`}
                {m.cumple && ` · 🎂 ${fechaCumple(m.cumple, true)}`}
              </p>
            </div>
            <div className="max-[860px]:col-start-2">
              {bajas ? (
                <span className="text-[13px] text-muted">{m.role_nombre}</span>
              ) : (
                <Select
                  value={m.role}
                  options={opciones.length ? opciones : [{ value: m.role, label: m.role_nombre }]}
                  aria-label={`Rol de ${m.username}`}
                  disabled={cambiarRol.isPending}
                  onChange={(rol) => {
                    if (rol === m.role) return
                    cambiarRol.mutate(
                      { id: m.id, rol },
                      {
                        onSuccess: (r) => {
                          aviso('Rol actualizado')
                          if (r.recargar) void refresh()
                        },
                        onError: (e) => aviso(mensaje(e), { tipo: 'error' }),
                      },
                    )
                  }}
                />
              )}
            </div>
            <div className="flex items-center justify-end gap-0.5 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 max-[860px]:col-start-2 max-[860px]:justify-start max-[860px]:opacity-100">
              {bajas ? (
                <Button size="sm" variant="ghost" onClick={() => reactivar.mutate(m.id, { onSuccess: () => aviso('Acceso devuelto.'), onError: (e) => aviso(mensaje(e), { tipo: 'error' }) })}>
                  Devolver el acceso
                </Button>
              ) : (
                <>
                  <Link to={`/perfil/${m.id}`} title="Ver su perfil" aria-label="Ver su perfil" className="flex size-[30px] items-center justify-center rounded-md text-label hover:bg-soft hover:text-ink">
                    <UserRound className="size-[15px]" />
                  </Link>
                  {!m.yo && (
                    <Link to={`/chat?dm=${m.id}`} title="Escribirle por el chat" aria-label="Escribirle por el chat" className="flex size-[30px] items-center justify-center rounded-md text-label hover:bg-soft hover:text-ink">
                      <MessageCircle className="size-[15px]" />
                    </Link>
                  )}
                  <Link to={`/ajustes/equipo/${m.id}`} title="Editar sus datos y su contraseña" aria-label="Editar sus datos y su contraseña" className="flex size-[30px] items-center justify-center rounded-md text-label hover:bg-soft hover:text-ink">
                    <Pencil className="size-[15px]" />
                  </Link>
                  {!m.yo && <IconButton label="Quitar del equipo" tone="danger" icon={<UserX />} onClick={() => void quitar(m)} />}
                </>
              )}
            </div>
          </li>
        ))}
      </ul>
    </Card>
  )
}

function RegistroPorEnlace() {
  const q = useInvitaciones()
  const [rol, setRol] = useState('viewer')
  const [email, setEmail] = useState('')
  const [nuevo, setNuevo] = useState<string | null>(null)
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const crear = useAccion((b: { rol: string; email: string }) => pedir('/api/v1/equipo/invitaciones', { method: 'POST', body: b, schema: InvitacionCreadaRespuesta }), [clavesEquipo.invitaciones])
  const anular = useAccion((id: number) => pedir(`/api/v1/equipo/invitaciones/${id}`, { method: 'DELETE', schema: VacioSchema }), [clavesEquipo.invitaciones])

  async function copiar(id: number) {
    try {
      const r = await pedir(`/api/v1/equipo/invitaciones/${id}/enlace`, { schema: UrlRespuesta })
      await navigator.clipboard.writeText(r.url)
      aviso('Enlace copiado')
    } catch (e) {
      aviso(mensaje(e, 'No se ha podido copiar.'), { tipo: 'error' })
    }
  }

  const roles = q.data?.roles ?? []
  return (
    <SetCard
      titulo={<span id="registro">Registro por enlace</span>}
      sub={
        <>
          Genera un enlace temporal para que alguien cree su propia cuenta. <b className="text-ink">Solo funciona con el enlace que generes tú</b>, es de un solo uso y caduca a las 48 h. Si pones su correo, se lo mandamos.
        </>
      }
    >
      <form
        className="flex flex-wrap items-end gap-2.5"
        onSubmit={(e) => {
          e.preventDefault()
          crear.mutate(
            { rol, email },
            {
              onSuccess: (r) => {
                setNuevo(r.invitacion.url)
                setEmail('')
                aviso(r.invitacion.enviado ? 'Enlace creado y enviado por correo' : 'Enlace creado')
              },
              onError: (er) => aviso(mensaje(er), { tipo: 'error' }),
            },
          )
        }}
      >
        <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-muted">
          Entrará como
          <Select value={rol} onChange={setRol} options={roles.map((r) => ({ value: r.clave, label: r.nombre }))} className="w-[200px] max-sm:w-full" aria-label="Rol del enlace" />
        </label>
        <label className="flex min-w-[220px] flex-1 flex-col gap-1.5 text-[12px] font-semibold text-muted max-sm:min-w-full">
          Su correo · opcional
          <TextInput type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="nombre@ejemplo.com" />
        </label>
        <Button type="submit" icon={<Link2 />} loading={crear.isPending} loadingText="Generando…">
          Generar enlace
        </Button>
      </form>

      {nuevo && (
        <div className="mt-4 rounded-xl border border-[#cde8d5] bg-[#eef7f0] p-3.5 dark:border-ok-line dark:bg-ok-bg">
          <p className="mb-2 text-[12.5px] font-semibold text-[#12603a] dark:text-ok">Enlace nuevo: pásaselo por chat o WhatsApp.</p>
          <CopiarCaja valor={nuevo} etiqueta="Copiar enlace" />
        </div>
      )}

      <ul className="mt-4 space-y-2">
        {q.data && q.data.items.length === 0 && <li className="text-[12.5px] text-muted">No hay ningún enlace activo. Genera uno cuando quieras dar de alta a alguien.</li>}
        {q.data?.items.map((i) => (
          <li key={i.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-line px-3.5 py-2.5">
            <span className="flex size-8 items-center justify-center rounded-lg bg-soft text-label">
              <Link2 className="size-4" aria-hidden="true" />
            </span>
            <div className="min-w-[180px] flex-1">
              <p className="text-[13px] font-semibold text-ink-strong">
                Enlace de registro <span className="ml-1 rounded-md bg-accent-soft px-1.5 py-px text-[10.5px] font-bold uppercase">{i.rol_nombre}</span>
              </p>
              <p className="text-[12px] text-muted">
                Un solo uso · {caducaEn(i.caduca)}
                {i.email && ` · para ${i.email}`}
                {i.creado_por && ` · lo creó ${i.creado_por}`}
              </p>
            </div>
            {i.copiable && (
              <Button size="sm" variant="ghost" icon={<Copy />} onClick={() => void copiar(i.id)}>
                Copiar enlace
              </Button>
            )}
            <IconButton
              label="Anular el enlace"
              tone="danger"
              icon={<Trash2 />}
              onClick={async () => {
                if (await confirm({ title: '¿Anular el enlace?', message: 'Quien tenga este enlace ya no podrá registrarse.', okLabel: 'Anular', danger: true })) {
                  anular.mutate(i.id, { onSuccess: () => aviso('Enlace anulado.') })
                }
              }}
            />
          </li>
        ))}
      </ul>
    </SetCard>
  )
}
