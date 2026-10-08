import { useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Cake, Camera, ChevronLeft, Globe, Mail, MapPin, MessageCircle, Pencil, Phone, Settings, UserRound } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import EstadoCirculo from '../../../shared/ui/EstadoCirculo'
import Field from '../../../shared/ui/Field'
import FormGrid from '../../../shared/ui/FormGrid'
import Notice from '../../../shared/ui/Notice'
import PresenceDot from '../../../shared/ui/PresenceDot'
import Segmented from '../../../shared/ui/Segmented'
import Select from '../../../shared/ui/Select'
import Switch from '../../../shared/ui/Switch'
import { TextArea, TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { guardarCsrf } from '../../../shared/api/client'
import { tonoVencimiento } from '../../../shared/lib/fechas'
import type { EstadoTarea } from '../../../shared/lib/paletas'
import { useAuth } from '../../auth/useAuth'
import { ErrorCarga, Esqueleto } from '../components/piezas'
import { campoDeError, clavesEquipo, mensaje, pedir, useAccion, useAvisos, useCuenta, usePerfil } from '../api'
import { fechaCorta, fechaCumple } from '../logica'
import { AvisosRespuesta, CambioPasswordRespuesta, CuentaRespuesta, FotoRespuesta, PerfilRespuesta, type Perfil } from '../schemas'

type Pestana = 'perfil' | 'cuenta' | 'notif'

/* /perfil (yo) y /perfil/:id (perfil.php): ficha social de cualquiera del
   equipo y, para uno mismo, el centro de cuenta (?modo=cuenta&tab=…). */
export default function PerfilPage() {
  const { id } = useParams()
  const { me } = useAuth()
  const [params, setParams] = useSearchParams()
  const pid = id ? Number(id) : (me?.id ?? 0)
  const q = usePerfil(pid)
  const editar = params.get('edit') === '1'
  const modoCuenta = params.get('modo') === 'cuenta'
  const tab = (['perfil', 'cuenta', 'notif'].includes(params.get('tab') ?? '') ? params.get('tab') : modoCuenta ? 'cuenta' : 'perfil') as Pestana

  function ir(p: Record<string, string | null>) {
    const n = new URLSearchParams(params)
    for (const [k, v] of Object.entries(p)) {
      if (v === null) n.delete(k)
      else n.set(k, v)
    }
    setParams(n, { replace: true })
  }

  if (q.isPending) return <Esqueleto filas={4} />
  if (q.isError) return <ErrorCarga error={q.error} />
  const p = q.data.perfil
  const cuenta = modoCuenta && p.yo

  return (
    <div className="mx-auto max-w-[600px] pb-10">
      <Volver yo={p.yo} />
      <Hero p={p} cuenta={cuenta} editar={editar} onEditar={() => ir({ edit: '1', modo: null, tab: null })} onCuenta={() => ir({ modo: cuenta ? null : 'cuenta', tab: null, edit: null })} />
      {cuenta && (
        <div className="mb-6 flex justify-center">
          <Segmented
            variant="pill"
            value={tab}
            onChange={(t) => ir({ tab: t })}
            items={[
              { value: 'perfil', label: 'Perfil' },
              { value: 'cuenta', label: 'Cuenta' },
              { value: 'notif', label: 'Avisos' },
            ]}
            aria-label="Secciones de tu cuenta"
            className="w-[340px] max-sm:w-full"
          />
        </div>
      )}
      {editar && p.puede_editar ? (
        <Editar p={p} departamentos={q.data.departamentos} onFin={() => ir({ edit: null })} />
      ) : cuenta && tab === 'cuenta' ? (
        <CuentaPanel />
      ) : cuenta && tab === 'notif' ? (
        <AvisosPanel />
      ) : (
        <Vista p={p} />
      )}
    </div>
  )
}

/* A uno mismo: a Mi equipo (si lo gestiona) o al inicio; a otro: atrás. */
function Volver({ yo }: { yo: boolean }) {
  const { can } = useAuth()
  const navigate = useNavigate()
  const c = 'mb-4 inline-flex items-center gap-1 text-[13.5px] font-medium text-muted hover:text-ink'
  if (!yo)
    return (
      <button type="button" className={c} onClick={() => (window.history.length > 1 ? navigate(-1) : navigate('/ajustes/equipo'))}>
        <ChevronLeft className="size-4" aria-hidden="true" />
        Volver
      </button>
    )
  const gestiona = can('equipo.gestionar')
  return (
    <Link to={gestiona ? '/ajustes/equipo' : '/inicio'} className={c}>
      <ChevronLeft className="size-4" aria-hidden="true" />
      {gestiona ? 'Mi equipo' : 'Inicio'}
    </Link>
  )
}

function Hero({ p, cuenta, editar, onEditar, onCuenta }: { p: Perfil; cuenta: boolean; editar: boolean; onEditar: () => void; onCuenta: () => void }) {
  return (
    <section className="mb-7 flex flex-col items-center text-center">
      <div className="relative">
        <Avatar nombre={p.username} foto={p.foto} size={112} />
        <PresenceDot state={p.presencia.estado} size={22} ring="var(--c-bg)" className="absolute right-1.5 bottom-1.5" />
      </div>
      <h1 className="mt-4 text-[30px] leading-[1.15] font-semibold tracking-[-.6px] text-ink-strong">
        {p.username}
        {p.yo && <span className="text-[16px] font-medium text-muted"> · Tú</span>}
      </h1>
      <p className="mt-1.5 flex flex-wrap items-center justify-center gap-1.5 text-[14px] text-muted">
        <PresenceDot state={p.presencia.estado} size={8} />
        {p.presencia.texto}
        <span aria-hidden="true">·</span>
        {p.role_nombre}
        {!p.activo && <span className="ml-1 rounded-md bg-[#feecec] px-1.5 text-[11.5px] font-semibold text-[#c0343a]">Dado de baja</span>}
      </p>
      {(p.cargo || p.departamento) && <p className="mt-1 text-[14px] text-ink">{[p.cargo, p.departamento].filter(Boolean).join(' · ')}</p>}
      {!editar && (
        <div className="mt-5 flex flex-wrap justify-center gap-2.5">
          {!p.yo && (
            <Pildora to={`/chat?dm=${p.id}`} icono={<MessageCircle />}>
              Mensaje
            </Pildora>
          )}
          {!p.yo && p.email && (
            <Pildora href={`mailto:${p.email}`} icono={<Mail />}>
              Email
            </Pildora>
          )}
          {p.yo && (
            <Pildora onClick={onCuenta} icono={cuenta ? <UserRound /> : <Settings />}>
              {cuenta ? 'Ver perfil' : 'Ajustes'}
            </Pildora>
          )}
          {p.puede_editar && (
            <Pildora onClick={onEditar} icono={<Pencil />}>
              {p.yo ? 'Editar' : 'Editar · admin'}
            </Pildora>
          )}
        </div>
      )}
    </section>
  )
}

function Pildora({ to, href, onClick, icono, children }: { to?: string; href?: string; onClick?: () => void; icono: ReactNode; children: ReactNode }) {
  const c = 'inline-flex items-center gap-2 rounded-full bg-[#e8e8ed] px-5 py-2.5 text-[15px] font-medium text-[#1d1d1f] transition-colors hover:bg-[#dcdce1] dark:bg-soft dark:text-ink dark:hover:bg-line-strong [&>svg]:size-[17px]'
  if (to)
    return (
      <Link to={to} className={c}>
        {icono}
        {children}
      </Link>
    )
  if (href)
    return (
      <a href={href} className={c}>
        {icono}
        {children}
      </a>
    )
  return (
    <button type="button" onClick={onClick} className={c}>
      {icono}
      {children}
    </button>
  )
}

function Grupo({ titulo, children, extra }: { titulo: string; children: ReactNode; extra?: ReactNode }) {
  return (
    <section className="mb-6">
      <div className="mb-2 flex items-center justify-between px-1">
        <h2 className="text-[12.5px] font-semibold tracking-[.4px] text-muted uppercase">{titulo}</h2>
        {extra}
      </div>
      <div className="overflow-hidden rounded-2xl border border-line bg-card">{children}</div>
    </section>
  )
}

function Fila({ icono, etiqueta, children }: { icono: ReactNode; etiqueta: string; children: ReactNode }) {
  return (
    <div className="flex min-h-[56px] items-center gap-3.5 px-5 py-2.5 [&+&]:border-t [&+&]:border-line2">
      <span className="flex size-[30px] shrink-0 items-center justify-center rounded-lg bg-[#8e8e93] text-white dark:bg-line-strong [&>svg]:size-4">{icono}</span>
      <div className="min-w-0">
        <p className="text-[12px] text-muted">{etiqueta}</p>
        <div className="truncate text-[15px] text-ink-strong">{children}</div>
      </div>
    </div>
  )
}

function Vista({ p }: { p: Perfil }) {
  const web = p.web.replace(/^https?:\/\//, '')
  return (
    <>
      <Grupo titulo="Contacto">
        <Fila icono={<Mail />} etiqueta="Email">
          {p.email ? <a href={`mailto:${p.email}`} className="hover:underline">{p.email}</a> : '—'}
        </Fila>
        {p.telefono && (
          <Fila icono={<Phone />} etiqueta="Teléfono">
            <a href={`tel:${p.telefono.replace(/\s+/g, '')}`} className="hover:underline">
              {p.telefono}
            </a>
          </Fila>
        )}
        {p.ubicacion && (
          <Fila icono={<MapPin />} etiqueta="Ubicación">
            {p.ubicacion}
          </Fila>
        )}
        {p.web && (
          <Fila icono={<Globe />} etiqueta="Web">
            <a href={p.web} target="_blank" rel="noopener noreferrer" className="hover:underline">
              {web}
            </a>
          </Fila>
        )}
        {p.cumple && (
          <Fila icono={<Cake />} etiqueta="Cumpleaños">
            {fechaCumple(p.cumple)}
          </Fila>
        )}
      </Grupo>
      {p.skills.length > 0 && (
        <Grupo titulo="Habilidades">
          <div className="flex flex-wrap gap-2 px-5 py-4">
            {p.skills.map((s) => (
              <span key={s} className="rounded-full bg-soft px-3 py-1 text-[13px] font-medium text-ink">
                {s}
              </span>
            ))}
          </div>
        </Grupo>
      )}
      <Grupo titulo={p.yo ? 'Sobre mí' : 'Sobre esta persona'}>
        <p className="px-5 py-4 text-[15px] leading-[1.55] whitespace-pre-line text-ink">{p.bio || (p.yo ? 'Aún no has escrito nada. Pulsa «Editar».' : 'Todavía no ha rellenado su perfil.')}</p>
      </Grupo>
      <Grupo
        titulo={`${p.yo ? 'Tareas que tienes' : 'Tareas asignadas'}${p.tareas.total ? ` · ${p.tareas.total}` : ''}`}
        extra={
          p.tareas.total > 0 && (
            <Link to={p.yo ? '/tareas?view=mine' : `/tareas?view=emp&emp=${p.id}`} className="text-[13px] font-medium text-muted hover:text-ink">
              Ver todas
            </Link>
          )
        }
      >
        {p.tareas.items.length === 0 ? (
          <p className="px-5 py-4 text-[15px] text-ink">{p.yo ? 'No tienes tareas pendientes. Todo al día.' : 'No tiene tareas pendientes.'}</p>
        ) : (
          p.tareas.items.map((t) => (
            <Link key={t.id} to={`/tareas/${t.id}`} className="flex items-center gap-3 px-5 py-3 hover:bg-hover-row [&+&]:border-t [&+&]:border-line2">
              <EstadoCirculo estado={t.estado as EstadoTarea} />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[14px] font-medium text-ink-strong">{t.titulo}</span>
                <span className="block truncate text-[12px] text-muted">{[t.cliente, t.lista].filter(Boolean).join(' · ')}</span>
              </span>
              {t.due_date && <span className={`text-[12px] ${tonoVencimiento(t.due_date) === 'late' ? 'font-semibold text-[#c0343a]' : 'text-muted'}`}>{fechaCorta(t.due_date).replace(/\/(\d{2})(\d{2})$/, '/$2')}</span>}
            </Link>
          ))
        )}
      </Grupo>
    </>
  )
}

function Editar({ p, departamentos, onFin }: { p: Perfil; departamentos: string[]; onFin: () => void }) {
  const [f, setF] = useState({ cargo: p.cargo, departamento: p.departamento, telefono: p.telefono, ubicacion: p.ubicacion, web: p.web, cumple: p.cumple ?? '', skills: p.skills.join(', '), bio: p.bio })
  const [err, setErr] = useState<{ campo: string | null; msg: string } | null>(null)
  const archivo = useRef<HTMLInputElement>(null)
  const { aviso } = useToast()
  const { refresh } = useAuth()
  const inv = [clavesEquipo.perfil(p.id), clavesEquipo.todo]
  const guardar = useAccion((b: typeof f) => pedir(`/api/v1/perfiles/${p.id}`, { method: 'PATCH', body: { ...b, cumple: b.cumple || null }, schema: PerfilRespuesta }), inv)
  const foto = useAccion((fd: FormData) => pedir(`/api/v1/perfiles/${p.id}/foto`, { method: 'POST', form: fd, schema: FotoRespuesta }), inv)
  const quitar = useAccion(() => pedir(`/api/v1/perfiles/${p.id}/foto`, { method: 'DELETE', schema: FotoRespuesta }), inv)
  const deps = [{ value: '', label: 'Sin departamento' }, ...departamentos.map((d) => ({ value: d, label: d }))]
  if (f.departamento && !departamentos.includes(f.departamento)) deps.push({ value: f.departamento, label: f.departamento })
  const e = (k: string) => (err?.campo === k ? err.msg : undefined)

  function onSubmit(ev: FormEvent) {
    ev.preventDefault()
    setErr(null)
    guardar.mutate(f, {
      onSuccess: () => {
        aviso('Perfil guardado.')
        onFin()
      },
      onError: (er) => setErr({ campo: campoDeError(er), msg: mensaje(er) }),
    })
  }

  return (
    <form onSubmit={onSubmit} className="rounded-2xl border border-line bg-card p-6 max-sm:p-4">
      <div className="mb-5 flex flex-wrap items-center gap-3">
        <Avatar nombre={p.username} foto={p.foto} size={56} />
        <input
          ref={archivo}
          type="file"
          accept="image/png,image/jpeg,image/gif,image/webp"
          hidden
          onChange={(ev) => {
            const file = ev.target.files?.[0]
            if (!file) return
            const fd = new FormData()
            fd.append('archivo', file)
            foto.mutate(fd, {
              onSuccess: () => {
                aviso('Foto actualizada.')
                if (p.yo) void refresh()
              },
              onError: (er) => aviso(mensaje(er), { tipo: 'error' }),
            })
            ev.target.value = ''
          }}
        />
        <Button size="sm" variant="ghost" icon={<Camera />} onClick={() => archivo.current?.click()} loading={foto.isPending} loadingText="Subiendo…">
          Cambiar foto
        </Button>
        {p.foto && (
          <Button size="sm" variant="ghost" onClick={() => quitar.mutate(undefined, { onSuccess: () => aviso('Foto quitada.') })}>
            Quitar
          </Button>
        )}
        <span className="text-[11.5px] text-label">JPG, PNG, GIF o WebP · hasta 8 MB.</span>
      </div>
      {err && !err.campo && (
        <Notice tone="error" className="mb-4">
          {err.msg}
        </Notice>
      )}
      <FormGrid>
        <Field label="Cargo / puesto" span={6} error={e('cargo')}>
          <TextInput value={f.cargo} onChange={(x) => setF({ ...f, cargo: x.target.value })} placeholder="Ej: Especialista SEO" maxLength={120} />
        </Field>
        <Field label="Departamento" span={6}>
          <Select value={f.departamento} onChange={(v) => setF({ ...f, departamento: v })} options={deps} />
        </Field>
        <Field label="Teléfono" span={6} error={e('telefono')}>
          <TextInput type="tel" value={f.telefono} onChange={(x) => setF({ ...f, telefono: x.target.value })} maxLength={60} />
        </Field>
        <Field label="Ubicación" span={6} error={e('ubicacion')}>
          <TextInput value={f.ubicacion} onChange={(x) => setF({ ...f, ubicacion: x.target.value })} maxLength={120} />
        </Field>
        <Field label="Web / enlace" span={6} error={e('web')}>
          <TextInput value={f.web} onChange={(x) => setF({ ...f, web: x.target.value })} placeholder="https://…" maxLength={160} />
        </Field>
        <Field label="Cumpleaños" span={6} error={e('cumple')}>
          <TextInput type="date" value={f.cumple} onChange={(x) => setF({ ...f, cumple: x.target.value })} />
        </Field>
        <Field label="Habilidades" span={12} hint="Sepáralas por comas. Se muestran como etiquetas." error={e('skills')}>
          <TextInput value={f.skills} onChange={(x) => setF({ ...f, skills: x.target.value })} placeholder="SEO, Google Ads, WordPress" maxLength={300} />
        </Field>
        <Field label="Sobre mí" span={12} error={e('bio')}>
          <TextArea value={f.bio} onChange={(x) => setF({ ...f, bio: x.target.value })} rows={4} maxLength={2000} />
        </Field>
      </FormGrid>
      <div className="mt-5 flex justify-end gap-2.5">
        <Button variant="ghost" onClick={onFin}>
          Cancelar
        </Button>
        <Button type="submit" loading={guardar.isPending} loadingText="Guardando…">
          Guardar cambios
        </Button>
      </div>
    </form>
  )
}

function CuentaPanel() {
  const q = useCuenta()
  const { aviso } = useToast()
  const navigate = useNavigate()
  const { refresh } = useAuth()
  const [datos, setDatos] = useState<{ username: string; email: string } | null>(null)
  const [pw, setPw] = useState({ actual: '', nueva: '', repite: '' })
  const [err, setErr] = useState<{ campo: string | null; msg: string } | null>(null)
  const guardar = useAccion((b: { username: string; email: string }) => pedir('/api/v1/me/cuenta', { method: 'PATCH', body: b, schema: CuentaRespuesta }), [clavesEquipo.cuenta, clavesEquipo.todo])
  const cambiar = useAccion((b: { actual: string; nueva: string }) => pedir('/api/v1/me/password', { method: 'POST', body: b, schema: CambioPasswordRespuesta }), [clavesEquipo.cuenta])
  if (q.isPending) return <Esqueleto filas={2} />
  if (q.isError) return <ErrorCarga error={q.error} />
  const f = datos ?? { username: q.data.cuenta.username, email: q.data.cuenta.email ?? '' }
  const e = (k: string) => (err?.campo === k ? err.msg : undefined)

  return (
    <>
      <form
        className="mb-6 rounded-2xl border border-line bg-card p-6 max-sm:p-4"
        onSubmit={(ev) => {
          ev.preventDefault()
          setErr(null)
          guardar.mutate(f, {
            onSuccess: () => {
              aviso('Cuenta actualizada.')
              setDatos(null)
              void refresh()
            },
            onError: (er) => setErr({ campo: campoDeError(er), msg: mensaje(er) }),
          })
        }}
      >
        <h2 className="mb-4 text-[15.5px] font-semibold text-ink-strong">Cuenta</h2>
        <FormGrid>
          <Field label="Nombre de usuario" span={6} error={e('username')}>
            <TextInput value={f.username} onChange={(x) => setDatos({ ...f, username: x.target.value })} maxLength={80} autoComplete="username" />
          </Field>
          <Field label="Email" span={6} error={e('email')} hint="Con tu correo de Google puedes entrar con «Entrar con Google».">
            <TextInput type="email" value={f.email} onChange={(x) => setDatos({ ...f, email: x.target.value })} autoComplete="email" />
          </Field>
        </FormGrid>
        <Button className="mt-4" type="submit" loading={guardar.isPending} loadingText="Guardando…" disabled={!datos}>
          Guardar
        </Button>
      </form>
      <form
        className="rounded-2xl border border-line bg-card p-6 max-sm:p-4"
        onSubmit={(ev) => {
          ev.preventDefault()
          setErr(null)
          if (pw.nueva.length < 6) return setErr({ campo: 'nueva', msg: 'La nueva contraseña debe tener al menos 6 caracteres.' })
          if (pw.nueva !== pw.repite) return setErr({ campo: 'repite', msg: 'Las dos contraseñas no coinciden.' })
          cambiar.mutate(
            { actual: pw.actual, nueva: pw.nueva },
            {
              onSuccess: (r) => {
                if (r.csrf) guardarCsrf(r.csrf)
                setPw({ actual: '', nueva: '', repite: '' })
                aviso('Contraseña actualizada. Se han cerrado tus otras sesiones.')
                navigate('/perfil?modo=cuenta&tab=cuenta', { replace: true })
              },
              onError: (er) => setErr({ campo: campoDeError(er), msg: mensaje(er) }),
            },
          )
        }}
      >
        <h2 className="text-[15.5px] font-semibold text-ink-strong">Contraseña</h2>
        <p className="mt-1 mb-4 text-[12.5px] text-muted">{q.data.cuenta.password_changed_at ? `Cambiada por última vez el ${fechaCorta(q.data.cuenta.password_changed_at.slice(0, 10))}.` : 'Al cambiarla se cierran las sesiones abiertas en otros dispositivos.'}</p>
        <FormGrid one>
          <Field label="Contraseña actual" error={e('actual')}>
            <TextInput type="password" value={pw.actual} onChange={(x) => setPw({ ...pw, actual: x.target.value })} autoComplete="current-password" />
          </Field>
          <Field label="Nueva contraseña" error={e('nueva')}>
            <TextInput type="password" value={pw.nueva} onChange={(x) => setPw({ ...pw, nueva: x.target.value })} autoComplete="new-password" />
          </Field>
          <Field label="Repite la nueva" error={e('repite')}>
            <TextInput type="password" value={pw.repite} onChange={(x) => setPw({ ...pw, repite: x.target.value })} autoComplete="new-password" />
          </Field>
        </FormGrid>
        <Button className="mt-4" type="submit" loading={cambiar.isPending} loadingText="Cambiando…" disabled={!pw.actual || !pw.nueva}>
          Cambiar contraseña
        </Button>
      </form>
    </>
  )
}

function AvisosPanel() {
  const q = useAvisos()
  const { aviso } = useToast()
  const guardar = useAccion((silenciar: string[]) => pedir('/api/v1/me/avisos', { method: 'PATCH', body: { silenciar }, schema: AvisosRespuesta }), [clavesEquipo.avisos])
  if (q.isPending) return <Esqueleto filas={2} />
  if (q.isError) return <ErrorCarga error={q.error} />
  const sil: string[] = q.data.silenciar
  const alternar = (k: 'chat' | 'avisos', on: boolean) => {
    const n = on ? [...sil.filter((x) => x !== k), k] : sil.filter((x) => x !== k)
    guardar.mutate(n, { onSuccess: () => aviso('Preferencias guardadas.'), onError: (e) => aviso(mensaje(e), { tipo: 'error' }) })
  }
  return (
    <Grupo titulo="Silenciar">
      <div className="px-2 py-1.5">
        <Switch checked={sil.includes('chat')} onChange={(v) => alternar('chat', v)} saving={guardar.isPending} label="Chat de equipo" description="Sonido y notificación del navegador de los mensajes." />
        <Switch checked={sil.includes('avisos')} onChange={(v) => alternar('avisos', v)} saving={guardar.isPending} label="Avisos y resúmenes" description="Facturas, informes, resumen diario." />
      </div>
      <p className="border-t border-line2 px-5 py-3 text-[12.5px] text-muted">Las asignaciones y menciones de tareas llegan siempre.</p>
    </Grupo>
  )
}
