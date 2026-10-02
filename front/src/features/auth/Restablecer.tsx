import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ApiError } from '../../shared/api/client'
import AuthLayout from './AuthLayout'
import { comprobarEnlace, MINIMO_CONTRASENA, restablecer } from './recuperacion'
import { Aviso, BotonPrincipal, CampoContrasena, ENLACE } from './ui'

/* Llega desde el enlace del correo (/restablecer?token=…). Comprueba que el
   enlace sigue valiendo antes de enseñar el formulario. */
export default function Restablecer() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  // El token se guarda al entrar y se quita de la barra de direcciones: así no
  // queda en el historial del navegador ni se comparte al copiar la URL.
  const [token] = useState(() => params.get('token') ?? '')
  useEffect(() => {
    if (params.has('token')) navigate('/restablecer', { replace: true })
  }, [params, navigate])

  const enlace = useQuery({
    queryKey: ['enlace-restablecer', token],
    queryFn: ({ signal }) => comprobarEnlace(token, signal),
    enabled: token !== '',
    retry: false,
    staleTime: Infinity,
  })

  const [p1, setP1] = useState('')
  const [p2, setP2] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [caducado, setCaducado] = useState(false)

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    if (busy) return
    if (p1.length < MINIMO_CONTRASENA) return setErr(`La contraseña debe tener al menos ${MINIMO_CONTRASENA} caracteres.`)
    if (p1 !== p2) return setErr('Las dos contraseñas no coinciden.')
    setBusy(true)
    setErr('')
    try {
      await restablecer(token, p1)
      navigate('/login', { replace: true, state: { aviso: 'Contraseña cambiada. Ya puedes entrar con la nueva.' } })
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) setCaducado(true)
      else setErr(e instanceof ApiError ? e.message : 'No se ha podido cambiar la contraseña.')
    } finally {
      setBusy(false)
    }
  }

  const invalido = token === '' || caducado || (enlace.error instanceof ApiError && enlace.error.status === 404)

  if (invalido) {
    return (
      <AuthLayout pestana="Enlace no válido" titulo="Enlace no válido" subtitulo="El enlace ha caducado, ya se ha usado o no está completo.">
        <div className="space-y-5">
          <Aviso tipo="info">Los enlaces valen durante 60 minutos y una sola vez. Pide uno nuevo y usa el último que te llegue.</Aviso>
          <Link
            to="/recuperar"
            className="w-full bg-[#141518] hover:bg-black text-white font-medium py-3.5 px-4 rounded-xl transition-all duration-150 flex items-center justify-center gap-2 shadow-sm"
          >
            Pedir un enlace nuevo
          </Link>
          <div className="text-center pt-2">
            <Link to="/login" className={ENLACE}>
              Volver a iniciar sesión
            </Link>
          </div>
        </div>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout
      pestana="Nueva contraseña"
      titulo="Elige una contraseña nueva"
      subtitulo={enlace.data ? <>Para la cuenta <b className="font-semibold text-gray-700">{enlace.data.username}</b>. Al guardarla se cerrarán las sesiones abiertas.</> : 'Comprobando el enlace…'}
    >
      {enlace.isError ? (
        <Aviso tipo="error">{enlace.error instanceof ApiError ? enlace.error.message : 'No se ha podido comprobar el enlace.'}</Aviso>
      ) : (
        <form className="space-y-5" onSubmit={onSubmit} aria-busy={enlace.isPending}>
          {/* Para que el gestor de contraseñas asocie la nueva con la cuenta. */}
          <input type="text" name="username" autoComplete="username" value={enlace.data?.username ?? ''} readOnly hidden />
          <CampoContrasena
            id="rs-pass"
            etiqueta="Contraseña nueva"
            placeholder={`Mínimo ${MINIMO_CONTRASENA} caracteres`}
            autoComplete="new-password"
            autoFocus
            required
            disabled={!enlace.data}
            value={p1}
            onChange={(e) => setP1(e.target.value)}
          />
          <CampoContrasena
            id="rs-pass2"
            etiqueta="Repite la contraseña"
            placeholder="Otra vez la misma"
            autoComplete="new-password"
            required
            disabled={!enlace.data}
            value={p2}
            onChange={(e) => setP2(e.target.value)}
          />
          {err && <Aviso tipo="error">{err}</Aviso>}
          <BotonPrincipal ocupado={busy || !enlace.data} textoOcupado={busy ? 'Guardando…' : 'Comprobando…'}>
            Guardar contraseña
          </BotonPrincipal>
          <div className="text-center pt-2">
            <Link to="/login" className={ENLACE}>
              Volver a iniciar sesión
            </Link>
          </div>
        </form>
      )}
    </AuthLayout>
  )
}
