import { useState, type CSSProperties } from 'react'
import { Link } from 'react-router-dom'
import { Plus, Trash2, Users } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import IconButton from '../../../shared/ui/IconButton'
import Switch from '../../../shared/ui/Switch'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { useAuth } from '../../auth/useAuth'
import AjustesCabecera from '../components/AjustesCabecera'
import { ErrorCarga, Esqueleto, SinPermiso } from '../components/piezas'
import { clavesEquipo, mensaje, pedir, useRoles } from '../api'
import { dependientesQueCaen, requisitosQueFaltan } from '../logica'
import { ClaveRespuesta, PermisosRespuesta, VacioSchema, type RolesDatos, type Rol } from '../schemas'

/* Ajustes › Roles y permisos (permisos.php): una columna por rol y un
   interruptor por permiso. Se guarda solo, al momento, y avisa antes de
   encender o apagar permisos que arrastran a otros. */
export default function RolesPage() {
  const { can } = useAuth()
  const q = useRoles()
  const { prompt } = useConfirm()
  const { aviso } = useToast()
  const qc = useQueryClient()
  if (!can('roles.gestionar')) return <SinPermiso que="gestionar los roles" />

  async function nuevo() {
    const nombre = await prompt({ title: '¿Cómo se llama el rol nuevo?', message: 'Nacerá sin permisos: se los marcas en su columna.', placeholder: 'Ej: Comercial, Contable, Becario', okLabel: 'Crear rol' })
    if (!nombre?.trim()) return
    try {
      await pedir('/api/v1/roles', { method: 'POST', body: { nombre }, schema: ClaveRespuesta })
      aviso(`Rol «${nombre.trim()}» creado`)
      await qc.invalidateQueries({ queryKey: clavesEquipo.roles })
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }

  return (
    <div className="max-w-[1180px]">
      <AjustesCabecera titulo="Roles y permisos" sub="Marca lo que puede hacer cada rol. Se guarda solo, al momento." accion={<Button icon={<Plus />} onClick={() => void nuevo()}>Nuevo rol</Button>} />
      {q.isPending ? <Esqueleto filas={6} /> : q.isError ? <ErrorCarga error={q.error} /> : <Matriz key={JSON.stringify(q.data.roles.map((r) => [r.clave, r.nombre, r.permisos, r.uso]))} datos={q.data} />}
    </div>
  )
}

function Matriz({ datos }: { datos: RolesDatos }) {
  const qc = useQueryClient()
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const { refresh } = useAuth()
  /* Copia local para pintar al momento (optimista); la verdad la manda el servidor. */
  const [permisos, setPermisos] = useState<Record<string, string[]>>(() => Object.fromEntries(datos.roles.map((r) => [r.clave, r.permisos])))
  const [guardando, setGuardando] = useState<string | null>(null)
  const etiqueta = (k: string) => datos.catalogo.flatMap((g) => g.permisos).find((p) => p.clave === k)?.etiqueta ?? k
  const lista = (ks: string[]) => ks.map((k) => `«${etiqueta(k)}»`).join(', ').replace(/, ([^,]*)$/, ' y $1')
  const cols = datos.roles.length
  const plantilla: CSSProperties = { gridTemplateColumns: `minmax(180px, 1fr) repeat(${cols}, 100px)` }

  async function aplicar(rol: Rol, ruta: string, body: Record<string, unknown>, ok: string) {
    setGuardando(rol.clave)
    try {
      const r = await pedir(ruta, { method: 'POST', body: { rol: rol.clave, ...body }, schema: PermisosRespuesta })
      setPermisos((p) => ({ ...p, [rol.clave]: r.permisos }))
      aviso(ok)
      if (r.recargar) void refresh()
    } catch (e) {
      setPermisos(Object.fromEntries(datos.roles.map((x) => [x.clave, x.permisos])))
      aviso(mensaje(e), { tipo: 'error' })
    } finally {
      setGuardando(null)
      void qc.invalidateQueries({ queryKey: clavesEquipo.roles })
    }
  }

  async function alternar(rol: Rol, perm: string, on: boolean) {
    const tiene = permisos[rol.clave] ?? []
    if (perm === 'admin.total' && on) {
      const si = await confirm({
        title: '¿Darle acceso total?',
        message: `Quien tenga el rol «${rol.nombre}» podrá hacerlo todo, ahora y lo que se añada en el futuro: ver y cambiar el dinero, el equipo, los roles y los datos avanzados.`,
        okLabel: 'Dar acceso total',
        danger: true,
      })
      if (!si) return
    } else if (on) {
      const faltan = requisitosQueFaltan(perm, tiene, datos.requisitos)
      if (faltan.length) {
        const si = await confirm({ title: 'Hacen falta otros permisos', message: `«${etiqueta(perm)}» no sirve de nada sin ${lista(faltan)}. Se activarán también.`, okLabel: `Activar los ${faltan.length + 1}` })
        if (!si) return
      }
    } else {
      const caen = dependientesQueCaen(perm, tiene, datos.requisitos)
      if (caen.length) {
        const si = await confirm({ title: 'Se apagarán otros permisos', message: `Sin «${etiqueta(perm)}» no se puede usar ${lista(caen)}. Se apagarán también.`, okLabel: 'Apagar', danger: true })
        if (!si) return
      }
    }
    setPermisos((p) => ({ ...p, [rol.clave]: on ? [...tiene, perm, ...requisitosQueFaltan(perm, tiene, datos.requisitos)] : tiene.filter((x) => x !== perm && !dependientesQueCaen(perm, tiene, datos.requisitos).includes(x)) }))
    await aplicar(rol, '/api/v1/roles/permisos', { perm, on }, on ? 'Permiso activado' : 'Permiso quitado')
  }

  async function renombrar(rol: Rol, nombre: string) {
    if (!nombre.trim() || nombre.trim() === rol.nombre) return false
    try {
      await pedir('/api/v1/roles', { method: 'PATCH', body: { rol: rol.clave, nombre }, schema: VacioSchema.passthrough() })
      aviso('Rol renombrado')
      void qc.invalidateQueries({ queryKey: clavesEquipo.roles })
      return true
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
      return false
    }
  }

  async function borrar(rol: Rol) {
    if (!(await confirm({ title: `¿Borrar el rol «${rol.nombre}»?`, message: 'No se puede deshacer.', okLabel: 'Borrar', danger: true }))) return
    try {
      await pedir(`/api/v1/roles?rol=${encodeURIComponent(rol.clave)}`, { method: 'DELETE', schema: VacioSchema })
      aviso('Rol borrado')
      void qc.invalidateQueries({ queryKey: clavesEquipo.roles })
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }

  return (
    <>
      <div className="overflow-x-auto pb-2">
        <div className="min-w-fit">
          {/* Cabecera: un rol por columna. */}
          <div className="sticky top-0 z-10 grid items-end gap-0 bg-page pb-2" style={plantilla}>
            <span className="px-1 text-[11px] font-bold tracking-[.5px] text-muted uppercase">Permiso</span>
            {datos.roles.map((r) => (
              <div key={r.clave} className="flex flex-col items-center gap-1.5 px-1 text-center">
                <NombreRol rol={r} onGuardar={(n) => renombrar(r, n)} />
                <div className="flex items-center gap-1">
                  <span className="rounded-md bg-soft px-1.5 py-px text-[10.5px] font-semibold text-muted">{r.total ? 'Todo' : `${r.uso} pers.`}</span>
                  {!r.sistema && r.uso === 0 && <IconButton size={26} label={`Borrar el rol ${r.nombre}`} tone="danger" icon={<Trash2 />} onClick={() => void borrar(r)} />}
                </div>
              </div>
            ))}
          </div>

          {datos.catalogo.map((g) => {
            const claves = g.permisos.map((p) => p.clave)
            const deConfig = claves.filter((k) => datos.config.includes(k)).length > claves.length / 2
            return (
              <section key={g.grupo} className="mt-5">
                <div className="mb-2 grid items-center" style={plantilla}>
                  <h2 className="flex items-center gap-2 px-1 text-[11.5px] font-bold tracking-[.5px] text-muted uppercase">
                    {g.grupo}
                    {deConfig && <span className="rounded-md bg-soft px-1.5 py-px text-[10px] font-semibold tracking-normal normal-case">Configuración</span>}
                  </h2>
                  {datos.roles.map((r) => {
                    if (r.total) return <span key={r.clave} />
                    const sinTotal = claves.filter((k) => k !== 'admin.total')
                    const todos = sinTotal.every((k) => (permisos[r.clave] ?? []).includes(k))
                    return (
                      <div key={r.clave} className="flex justify-center">
                        <button
                          type="button"
                          disabled={guardando === r.clave}
                          onClick={() => void aplicar(r, '/api/v1/roles/grupos', { grupo: g.grupo, on: !todos }, todos ? 'Sección quitada' : 'Sección activada')}
                          className="rounded-md border border-line bg-card px-2 py-0.5 text-[10.5px] font-semibold text-muted hover:border-line-strong hover:text-ink disabled:opacity-50"
                        >
                          {todos ? 'Quitar' : 'Todo'}
                        </button>
                      </div>
                    )
                  })}
                </div>
                <Card padding="none" className="overflow-hidden">
                  {g.permisos.map((p) => (
                    <div key={p.clave} className="grid items-center border-b border-line2 last:border-b-0" style={plantilla}>
                      <div className="min-w-0 px-4 py-3">
                        <p className="text-[13px] font-semibold text-ink-strong">{p.etiqueta}</p>
                        <p className="mt-0.5 text-[11.5px] leading-[1.45] text-muted">{p.descripcion}</p>
                      </div>
                      {datos.roles.map((r) => {
                        const on = r.total || (permisos[r.clave] ?? []).includes(p.clave)
                        const fijo = r.total && !(p.clave === 'admin.total' && r.clave !== 'owner')
                        return (
                          <div key={r.clave} className={`flex h-full items-center justify-center py-3 ${r.total ? 'bg-soft/60' : ''}`}>
                            <Switch
                              checked={on}
                              disabled={fijo}
                              saving={guardando === r.clave}
                              aria-label={`${p.etiqueta} · ${r.nombre}`}
                              onChange={(v) => void alternar(r, p.clave, v)}
                            />
                          </div>
                        )
                      })}
                    </div>
                  ))}
                </Card>
              </section>
            )
          })}
        </div>
      </div>
      <p className="mt-5 flex items-center gap-2 text-[12.5px] text-muted">
        <Users className="size-4 text-label" aria-hidden="true" />
        <span>
          Para darle un rol a alguien, ve a{' '}
          <Link to="/ajustes/equipo" className="font-semibold text-ink underline-offset-2 hover:underline">
            Mi equipo
          </Link>{' '}
          · {datos.personas} personas con acceso.
        </span>
      </p>
    </>
  )
}

/* Nombre del rol editable en su sitio: Enter o salir del campo guarda; vacío o igual, vuelve. */
function NombreRol({ rol, onGuardar }: { rol: Rol; onGuardar: (n: string) => Promise<boolean> }) {
  const [v, setV] = useState(rol.nombre)
  const [previo, setPrevio] = useState(rol.nombre)
  if (previo !== rol.nombre) {
    setPrevio(rol.nombre)
    setV(rol.nombre)
  }
  async function fin() {
    if (!(await onGuardar(v))) setV(rol.nombre)
  }
  return (
    <input
      value={v}
      maxLength={60}
      aria-label={`Nombre del rol ${rol.nombre}`}
      onChange={(e) => setV(e.target.value)}
      onBlur={() => void fin()}
      onKeyDown={(e) => {
        if (e.key === 'Enter') e.currentTarget.blur()
        if (e.key === 'Escape') {
          setV(rol.nombre)
          e.currentTarget.blur()
        }
      }}
      className="w-full truncate rounded-md border border-transparent bg-transparent px-1 py-0.5 text-center text-[12.5px] font-semibold text-ink-strong hover:bg-soft focus:border-line focus:bg-card focus:outline-none"
    />
  )
}
