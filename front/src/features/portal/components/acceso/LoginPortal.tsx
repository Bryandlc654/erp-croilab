import { useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { ArrowRight, Eye, EyeOff, Lock, User } from 'lucide-react'
import { ApiError } from '../../../../shared/api/client'
import Select from '../../../../shared/ui/Select'
import { entrar, urlGoogle, useClientesEquipo, useSesionPortal } from '../../api'
import { BOTON, CAMPO } from '../../clasePortal'
import { useAuth } from '../../../auth/useAuth'
import MarcoAcceso from './MarcoAcceso'

const MSG_GOOGLE: Record<string, string> = {
  denied: 'Ese correo de Google no está asociado a tu cuenta. Escríbenos y lo activamos.',
  err: 'No se ha podido entrar con Google. Inténtalo de nuevo.',
  cancel: 'Has cancelado el acceso con Google.',
  nocfg: 'El acceso con Google todavía no está disponible.',
  inactivo: 'Tu acceso está desactivado. Ponte en contacto con tu equipo y lo revisamos.',
  equipo: 'Has entrado como equipo en este navegador: abre el portal desde la vista previa.',
}

/* Acceso al portal del cliente (antes login.php). ?m=<agencia> pinta la marca
   de esa agencia colaboradora. Con la sesión del equipo abierta en este
   navegador pasa a «modo equipo»: elegir un cliente y ver su portal. */
export default function LoginPortal() {
  const [params] = useSearchParams()
  const m = Number(params.get('m')) || 0
  const s = useSesionPortal(m)
  const qc = useQueryClient()
  const nav = useNavigate()
  const [usuario, setUsuario] = useState('')
  const [pass, setPass] = useState('')
  const [ver, setVer] = useState(false)
  const [ayuda, setAyuda] = useState(false)
  const [enviando, setEnviando] = useState(false)
  const ge = params.get('ge')
  const [error, setError] = useState(ge ? (MSG_GOOGLE[ge] ?? MSG_GOOGLE.err) : params.get('sesion') ? 'Tu sesión ha terminado. Vuelve a entrar.' : '')

  if (s.data?.cliente) return <Navigate to="/portal" replace />
  const marca = s.data?.marca ?? null

  const enviar = async (e: FormEvent) => {
    e.preventDefault()
    if (!usuario.trim() || !pass) return setError('Escribe tu usuario y tu contraseña.')
    setEnviando(true)
    try {
      await entrar(usuario.trim(), pass)
      qc.removeQueries({ queryKey: ['portal'] })
      nav('/portal', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'No se puede conectar con el servidor')
      setEnviando(false)
    }
  }
  const google = async () => {
    try {
      const r = await urlGoogle(m)
      window.location.assign(r.url)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : MSG_GOOGLE.err)
    }
  }

  return (
    <MarcoAcceso marca={marca}>
      {s.data?.equipo ? (
        <ModoEquipo usuario={s.data.equipo.username} />
      ) : (
        <>
          <h1 className="text-[28px] font-extrabold tracking-tight text-(--p-ink-strong)">Área de cliente</h1>
          <p className="mt-1 text-[14.5px] text-(--p-muted)">Entra para ver el estado de tu proyecto con {marca?.name ?? 'tu agencia'}.</p>
          <form onSubmit={(e) => void enviar(e)} className="mt-6 flex flex-col gap-4" noValidate>
            {error && (
              <p role="alert" className="rounded-xl border border-[#f0caca] bg-[#fbeeee] px-3.5 py-2.5 text-[13.5px] text-[#a32d2d] dark:border-[#472a2a] dark:bg-[#2e1d1d] dark:text-[#f08d82]">
                {error}
              </p>
            )}
            <label className="text-[13px] font-semibold text-(--p-ink-strong)">
              Usuario
              <span className="relative mt-1.5 block">
                <User className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-(--p-muted)" />
                <input autoFocus autoComplete="username" value={usuario} onChange={(e) => setUsuario(e.target.value)} placeholder="Tu usuario o tu correo" className={`${CAMPO} pl-11`} />
              </span>
            </label>
            <label className="text-[13px] font-semibold text-(--p-ink-strong)">
              Contraseña
              <span className="relative mt-1.5 block">
                <Lock className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-(--p-muted)" />
                <input
                  type={ver ? 'text' : 'password'}
                  autoComplete="current-password"
                  value={pass}
                  onChange={(e) => setPass(e.target.value)}
                  placeholder="Tu contraseña"
                  className={`${CAMPO} pr-11 pl-11`}
                />
                <button type="button" onClick={() => setVer(!ver)} className="absolute top-1/2 right-3 -translate-y-1/2 p-1 text-(--p-muted)" aria-label={ver ? 'Ocultar contraseña' : 'Ver contraseña'}>
                  {ver ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </button>
              </span>
            </label>
            <button type="submit" className={BOTON} disabled={enviando}>
              Entrar <ArrowRight className="size-4" />
            </button>
          </form>
          {s.data?.google && (
            <>
              <div className="my-4 flex items-center gap-3 text-[12px] text-(--p-muted)">
                <span className="h-px flex-1 bg-(--p-line)" />o<span className="h-px flex-1 bg-(--p-line)" />
              </div>
              <button type="button" onClick={() => void google()} className="flex h-12 w-full items-center justify-center gap-2.5 rounded-xl border border-(--p-line) bg-(--p-card) text-[14.5px] font-semibold text-(--p-ink-strong)">
                <svg viewBox="0 0 24 24" className="size-[18px]" aria-hidden="true">
                  <path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5a5.6 5.6 0 0 1-2.4 3.6v3h3.9c2.3-2.1 3.5-5.2 3.5-8.8z" />
                  <path fill="#34A853" d="M12 24c3.2 0 6-1.1 8-2.9l-3.9-3c-1.1.7-2.5 1.2-4.1 1.2-3.1 0-5.8-2.1-6.7-5H1.3v3.1A12 12 0 0 0 12 24z" />
                  <path fill="#FBBC05" d="M5.3 14.3a7.2 7.2 0 0 1 0-4.6V6.6H1.3a12 12 0 0 0 0 10.8l4-3.1z" />
                  <path fill="#EA4335" d="M12 4.8c1.8 0 3.3.6 4.6 1.8l3.4-3.4A12 12 0 0 0 1.3 6.6l4 3.1c.9-2.9 3.6-4.9 6.7-4.9z" />
                </svg>
                Entrar con Google
              </button>
            </>
          )}
          <div className="mt-5 text-center text-[13px]">
            <button type="button" onClick={() => setAyuda(!ayuda)} className="text-(--p-muted) hover:text-(--p-ink-strong)" aria-expanded={ayuda}>
              ¿No puedes entrar?
            </button>
            {ayuda && (
              <p className="mt-2 rounded-xl bg-(--p-soft) px-4 py-3 text-(--p-ink)">
                <Link to={`/portal/recuperar${m ? `?m=${m}` : ''}`} className="font-semibold text-(--p-ink-strong) underline">
                  Recupera tu contraseña por correo
                </Link>{' '}
                o ponte en contacto con <b>{marca?.name ?? 'tu agencia'}</b> y te la restablecen.
              </p>
            )}
          </div>
          <p className="mt-6 text-center">
            <Link to="/login" className="text-[13px] font-semibold text-(--p-muted) hover:text-(--p-ink-strong)">
              Acceso del equipo →
            </Link>
          </p>
        </>
      )}
    </MarcoAcceso>
  )
}

/* Sesión del equipo abierta: elegir un cliente (de su alcance) y abrir su portal en vista previa. */
function ModoEquipo({ usuario }: { usuario: string }) {
  const q = useClientesEquipo(true)
  const { logout } = useAuth()
  const qc = useQueryClient()
  const [cli, setCli] = useState<number | null>(null)
  const nav = useNavigate()
  const items = q.data?.items ?? []
  return (
    <>
      <h1 className="text-[28px] font-extrabold tracking-tight text-(--p-ink-strong)">Hola, {usuario} 👋</h1>
      <p className="mt-1 text-[14.5px] text-(--p-muted)">Has entrado como equipo. Elige un cliente para ver su portal — sin contraseña.</p>
      <p className="mt-4 rounded-xl bg-(--p-soft) px-4 py-3 text-[13px] text-(--p-ink)">Entras en modo vista previa (solo lectura): no se envían mensajes ni solicitudes en su nombre.</p>
      {q.isError ? (
        <p className="mt-4 text-[13.5px] text-(--p-red)">{q.error instanceof ApiError ? q.error.message : 'No se han podido cargar los clientes.'}</p>
      ) : !q.isPending && !items.length ? (
        <p className="mt-4 text-[14px] text-(--p-muted)">Todavía no hay clientes dados de alta.</p>
      ) : (
        <div className="mt-4 flex flex-col gap-3">
          <Select
            value={cli}
            onChange={setCli}
            searchable
            placeholder="Elige un cliente"
            options={items.map((c) => ({ value: c.id, label: c.name + (c.activo ? '' : ' (no activo)') }))}
          />
          <button type="button" className={BOTON} disabled={!cli} onClick={() => cli && nav(`/clientes/${cli}/portal`)}>
            Ver su portal <ArrowRight className="size-4" />
          </button>
        </div>
      )}
      <p className="mt-6 flex justify-center gap-4 text-[13px] font-semibold">
        <Link to="/" className="text-(--p-muted) hover:text-(--p-ink-strong)">
          Ir al panel de gestión
        </Link>
        <button
          type="button"
          className="text-(--p-muted) hover:text-(--p-ink-strong)"
          onClick={async () => {
            await logout()
            await qc.invalidateQueries({ queryKey: ['portal', 'sesion'] })
          }}
        >
          Cerrar sesión de equipo
        </button>
      </p>
    </>
  )
}
