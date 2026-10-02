import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiError } from '../../shared/api/client'
import AuthLayout from './AuthLayout'
import { pedirEnlace } from './recuperacion'
import { Aviso, BotonPrincipal, Campo, ENLACE } from './ui'

const IconoSobre = (
  <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round">
    <rect x="3" y="5" width="18" height="14" rx="2" />
    <path d="m3 7 9 6 9-6" />
  </svg>
)

/* «He olvidado mi contraseña»: pide el enlace por correo. La respuesta es la
   misma exista o no la cuenta, así que aquí tampoco se distingue. */
export default function Recuperar() {
  const [ident, setIdent] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [enviado, setEnviado] = useState('')

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    setErr('')
    try {
      const r = await pedirEnlace(ident.trim())
      setEnviado(r.msg)
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'No se ha podido enviar la petición.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthLayout
      pestana="Recuperar contraseña"
      titulo="¿Has olvidado tu contraseña?"
      subtitulo="Escribe tu usuario o tu correo y te enviaremos un enlace para elegir una nueva."
    >
      {enviado ? (
        <div className="space-y-5">
          <Aviso tipo="ok">{enviado}</Aviso>
          <p className="text-sm text-gray-500">El enlace vale durante 60 minutos y una sola vez.</p>
          <div className="text-center pt-2">
            <Link to="/login" className={ENLACE}>
              Volver a iniciar sesión
            </Link>
          </div>
        </div>
      ) : (
        <form className="space-y-5" onSubmit={onSubmit}>
          <Campo
            id="rc-ident"
            etiqueta="Usuario o correo"
            icono={IconoSobre}
            type="text"
            placeholder="Tu usuario o tu correo"
            autoComplete="username"
            autoCapitalize="none"
            spellCheck={false}
            autoFocus
            required
            maxLength={190}
            value={ident}
            onChange={(e) => setIdent(e.target.value)}
          />
          {err && <Aviso tipo="error">{err}</Aviso>}
          <BotonPrincipal ocupado={busy} textoOcupado="Enviando…">
            Enviar enlace
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
