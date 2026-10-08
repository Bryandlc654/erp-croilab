import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { z } from 'zod'
import { api, ApiError, guardarCsrf } from '../../../shared/api/client'
import AuthLayout from '../../auth/AuthLayout'
import { Aviso, BotonPrincipal, Campo, CampoContrasena, ENLACE, IconoUsuario } from '../../auth/ui'
import { mensaje, pedir } from '../api'
import { inicialMarca } from '../logica'
import { RegistroInfoRespuesta, RegistroRespuesta, type RegistroInfo } from '../schemas'

const MIN = 6

/* /registro?t=<token> (registro.php): alta pública con un enlace de
   invitación de un solo uso. Va FUERA de la sesión (rutasPublicas). */
export default function RegistroPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  // El token se guarda al entrar y se quita de la barra de direcciones.
  const [token] = useState(() => params.get('t') ?? params.get('token') ?? '')
  useEffect(() => {
    if (params.has('t') || params.has('token')) navigate('/registro', { replace: true })
  }, [params, navigate])

  const info = useQuery({
    queryKey: ['equipo-mod', 'registro', token],
    queryFn: ({ signal }) => api(`/api/v1/registro?t=${encodeURIComponent(token)}`, { schema: RegistroInfoRespuesta, signal }),
    enabled: token !== '',
    retry: false,
    staleTime: Infinity,
  })
  const [hecho, setHecho] = useState<string | null>(null)

  const invalido = token === '' || (info.error instanceof ApiError && info.error.status === 404)
  if (invalido) {
    return (
      <AuthLayout pestana="Enlace no válido" titulo="Este enlace ya no vale" subtitulo="Los enlaces para crear una cuenta caducan a las 48 horas y solo se pueden usar una vez.">
        <div className="space-y-5">
          <Aviso tipo="info">Pídele otro a quien lleve el panel.</Aviso>
          <div className="pt-2 text-center">
            <Link to="/login" className={ENLACE}>
              Ir a la pantalla de acceso
            </Link>
          </div>
        </div>
      </AuthLayout>
    )
  }
  if (hecho && info.data) {
    return (
      <AuthLayout pestana="Cuenta creada" titulo="Cuenta creada" subtitulo={<>Ya puedes entrar al panel de {info.data.marca.nombre} con tu usuario <b className="font-semibold text-gray-700">{hecho}</b> y la contraseña que acabas de elegir.</>}>
        <Link to="/login" className="flex w-full items-center justify-center gap-2 rounded-xl bg-[#141518] px-4 py-3.5 font-medium text-white shadow-sm transition-all duration-150 hover:bg-black">
          Ir a la pantalla de acceso →
        </Link>
      </AuthLayout>
    )
  }
  return (
    <AuthLayout
      pestana="Crea tu cuenta"
      titulo="Crea tu cuenta"
      subtitulo={info.data ? <>Te unes al panel de {info.data.marca.nombre} como <b className="font-semibold text-gray-700">{info.data.rol_nombre}</b>. Elige con qué entrar.</> : 'Comprobando el enlace…'}
    >
      {info.isError ? <Aviso tipo="error">{mensaje(info.error, 'No se ha podido comprobar el enlace.')}</Aviso> : <Formulario key={info.data ? 'listo' : 'cargando'} token={token} info={info.data} onHecho={setHecho} />}
    </AuthLayout>
  )
}

function Formulario({ token, info, onHecho }: { token: string; info: RegistroInfo | undefined; onHecho: (u: string) => void }) {
  const [f, setF] = useState({ username: '', email: info?.email ?? '', p1: '', p2: '' })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const coinciden = f.p2 === '' ? null : f.p1 === f.p2

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    if (busy) return
    if (f.username.trim().length < 2) return setErr('Escribe tu nombre de usuario (al menos 2 letras).')
    if (f.p1.length < MIN) return setErr(`La contraseña debe tener al menos ${MIN} caracteres.`)
    if (f.p1 !== f.p2) return setErr('Las dos contraseñas no coinciden.')
    setBusy(true)
    setErr('')
    try {
      const c = await api('/api/v1/auth/csrf', { schema: z.object({ csrf: z.string() }) })
      guardarCsrf(c.csrf)
      const r = await pedir('/api/v1/registro', { method: 'POST', body: { token, username: f.username, email: f.email, password: f.p1 }, schema: RegistroRespuesta })
      onHecho(r.username)
    } catch (er) {
      setErr(mensaje(er, 'No se ha podido crear la cuenta.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="space-y-5" onSubmit={onSubmit} aria-busy={!info}>
      {info && (
        <div className="flex items-center gap-3">
          {info.marca.logo ? (
            <img src={info.marca.logo} alt="" className="size-11 rounded-xl object-contain" />
          ) : (
            <span className="flex size-11 items-center justify-center rounded-xl text-lg font-extrabold text-white" style={{ background: info.marca.color ?? '#141518' }}>
              {inicialMarca(info.marca.nombre)}
            </span>
          )}
          <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-600">Compatible con «Entrar con Google»</span>
        </div>
      )}
      <Campo id="rg-user" etiqueta="Nombre de usuario" icono={<IconoUsuario />} placeholder="Con el que entrarás al panel" autoComplete="username" autoFocus required maxLength={80} value={f.username} onChange={(e) => setF({ ...f, username: e.target.value })} />
      <div>
        <Campo
          id="rg-mail"
          etiqueta="Correo (opcional)"
          icono={
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
          }
          type="email"
          autoComplete="email"
          placeholder="tu@gmail.com"
          value={f.email}
          onChange={(e) => setF({ ...f, email: e.target.value })}
        />
        <p className="mt-1.5 text-xs text-gray-500">Pon tu correo de Google y podrás entrar con un clic con «Entrar con Google».</p>
      </div>
      <CampoContrasena id="rg-p1" etiqueta="Contraseña" placeholder={`Mínimo ${MIN} caracteres`} autoComplete="new-password" required value={f.p1} onChange={(e) => setF({ ...f, p1: e.target.value })} />
      <div>
        <CampoContrasena id="rg-p2" etiqueta="Repite la contraseña" placeholder="Otra vez la misma" autoComplete="new-password" required value={f.p2} onChange={(e) => setF({ ...f, p2: e.target.value })} />
        {coinciden !== null && <p className={`mt-1.5 text-xs font-medium ${coinciden ? 'text-emerald-700' : 'text-red-600'}`}>{coinciden ? 'Las dos coinciden' : 'Las dos contraseñas no coinciden'}</p>}
      </div>
      {err && <Aviso tipo="error">{err}</Aviso>}
      <BotonPrincipal ocupado={busy || !info} textoOcupado={busy ? 'Creando…' : 'Comprobando…'}>
        Crear mi cuenta
      </BotonPrincipal>
      <p className="text-center text-xs text-gray-500">Este enlace caduca a las {info?.horas ?? 48} horas y solo sirve una vez.</p>
    </form>
  )
}
