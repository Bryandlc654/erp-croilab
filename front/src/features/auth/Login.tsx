import { useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate, Navigate } from 'react-router-dom'
import { useAuth } from './useAuth'
import AuthLayout from './AuthLayout'
import { Aviso, BotonPrincipal, Campo, CampoContrasena, ENLACE, IconoUsuario } from './ui'

export default function Login() {
  const { me, loading, login } = useAuth()
  const [u, setU] = useState('')
  const [p, setP] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const nav = useNavigate()
  const location = useLocation()
  const estado = location.state as { from?: string; aviso?: string } | null
  // Vuelve a la pantalla que se intentaba abrir antes de que pidiera sesión.
  const from = estado?.from || '/tareas'

  // Mientras se comprueba la sesión no se enseña el formulario: si ya hay sesión
  // se redirige sin que aparezca un instante.
  if (loading) return null
  if (me) return <Navigate to={from} replace />

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    setErr('')
    try {
      const r = await login(u.trim(), p)
      if (r.ok) nav(from, { replace: true })
      else setErr(r.msg || 'No se ha podido iniciar sesión.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthLayout pestana="Iniciar sesión" titulo="Bienvenido de nuevo" subtitulo="Entra al panel de gestión con tu cuenta de equipo.">
      <form className="space-y-5" onSubmit={onSubmit}>
        {/* Por ejemplo, al volver de restablecer la contraseña. */}
        {estado?.aviso && <Aviso tipo="ok">{estado.aviso}</Aviso>}

        <Campo
          id="lg-user"
          etiqueta="Usuario"
          icono={<IconoUsuario />}
          type="text"
          placeholder="Tu usuario"
          autoComplete="username"
          autoCapitalize="none"
          spellCheck={false}
          autoFocus
          required
          value={u}
          onChange={(e) => setU(e.target.value)}
        />
        <CampoContrasena
          id="lg-pass"
          etiqueta="Contraseña"
          placeholder="Tu contraseña"
          autoComplete="current-password"
          required
          value={p}
          onChange={(e) => setP(e.target.value)}
        />

        {err && <Aviso tipo="error">{err}</Aviso>}

        <BotonPrincipal ocupado={busy} textoOcupado="Entrando…">
          Entrar
        </BotonPrincipal>

        <div className="text-center pt-2">
          <Link to="/recuperar" className={ENLACE}>
            ¿Has olvidado tu contraseña?
          </Link>
        </div>
        {/* Los clientes entran por su portal, con su propia cuenta. */}
        <div className="text-center">
          <Link to="/portal/login" className={ENLACE}>
            ¿Eres cliente? Entra en tu área →
          </Link>
        </div>
      </form>
    </AuthLayout>
  )
}
