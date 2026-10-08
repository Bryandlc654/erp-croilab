import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowRight, Eye, EyeOff } from 'lucide-react'
import { ApiError } from '../../../../shared/api/client'
import { comprobarEnlace, pedirEnlace, restablecer, useSesionPortal } from '../../api'
import { BOTON, CAMPO } from '../../clasePortal'
import MarcoAcceso from './MarcoAcceso'

function Aviso({ tono, children }: { tono: 'ok' | 'error'; children: React.ReactNode }) {
  const c =
    tono === 'ok'
      ? 'border-[#cfe9d6] bg-[#eafaf0] text-[#12854a] dark:border-[#234436] dark:bg-[#14251c] dark:text-[#54cd8e]'
      : 'border-[#f0caca] bg-[#fbeeee] text-[#a32d2d] dark:border-[#472a2a] dark:bg-[#2e1d1d] dark:text-[#f08d82]'
  return (
    <p role={tono === 'error' ? 'alert' : 'status'} className={`rounded-xl border px-3.5 py-2.5 text-[13.5px] ${c}`}>
      {children}
    </p>
  )
}

/* «He olvidado mi contraseña» del cliente: pide el enlace por correo. */
export function RecuperarPortal() {
  const [params] = useSearchParams()
  const m = Number(params.get('m')) || 0
  const s = useSesionPortal(m)
  const [ident, setIdent] = useState('')
  const [msg, setMsg] = useState<{ tono: 'ok' | 'error'; texto: string } | null>(null)
  const [enviando, setEnviando] = useState(false)
  const enviar = async (e: FormEvent) => {
    e.preventDefault()
    if (!ident.trim()) return setMsg({ tono: 'error', texto: 'Escribe tu usuario o tu correo.' })
    setEnviando(true)
    try {
      const r = await pedirEnlace(ident.trim())
      setMsg({ tono: 'ok', texto: r.msg })
    } catch (err) {
      setMsg({ tono: 'error', texto: err instanceof ApiError ? err.message : 'No se puede conectar con el servidor' })
    } finally {
      setEnviando(false)
    }
  }
  return (
    <MarcoAcceso marca={s.data?.marca ?? null}>
      <h1 className="text-[28px] font-extrabold tracking-tight text-(--p-ink-strong)">Recuperar contraseña</h1>
      <p className="mt-1 text-[14.5px] text-(--p-muted)">Te mandamos un enlace al correo de tu cuenta para elegir una contraseña nueva.</p>
      <form onSubmit={(e) => void enviar(e)} className="mt-6 flex flex-col gap-4" noValidate>
        {msg && <Aviso tono={msg.tono}>{msg.texto}</Aviso>}
        <label className="text-[13px] font-semibold text-(--p-ink-strong)">
          Usuario o correo
          <input autoFocus value={ident} onChange={(e) => setIdent(e.target.value)} className={`${CAMPO} mt-1.5`} autoComplete="username" />
        </label>
        <button type="submit" className={BOTON} disabled={enviando}>
          Enviar enlace <ArrowRight className="size-4" />
        </button>
      </form>
      <p className="mt-6 text-center">
        <Link to={`/portal/login${m ? `?m=${m}` : ''}`} className="text-[13px] font-semibold text-(--p-muted) hover:text-(--p-ink-strong)">
          ← Volver a entrar
        </Link>
      </p>
    </MarcoAcceso>
  )
}

/* El enlace del correo: elegir la contraseña nueva (cierra las sesiones abiertas). */
export function RestablecerPortal() {
  const [params] = useSearchParams()
  const m = Number(params.get('m')) || 0
  const token = params.get('token') ?? ''
  const s = useSesionPortal(m)
  const enlace = useQuery({ queryKey: ['portal', 'enlace', token], queryFn: () => comprobarEnlace(token), retry: false })
  const [pass, setPass] = useState('')
  const [pass2, setPass2] = useState('')
  const [ver, setVer] = useState(false)
  const [msg, setMsg] = useState<{ tono: 'ok' | 'error'; texto: string } | null>(null)
  const [hecho, setHecho] = useState(false)
  const enviar = async (e: FormEvent) => {
    e.preventDefault()
    if (pass.length < 6) return setMsg({ tono: 'error', texto: 'La contraseña debe tener al menos 6 caracteres.' })
    if (pass !== pass2) return setMsg({ tono: 'error', texto: 'Las dos contraseñas no coinciden.' })
    try {
      await restablecer(token, pass)
      setHecho(true)
      setMsg({ tono: 'ok', texto: 'Listo: ya tienes tu contraseña nueva. Entra con ella.' })
    } catch (err) {
      setMsg({ tono: 'error', texto: err instanceof ApiError ? err.message : 'No se puede conectar con el servidor' })
    }
  }
  const login = `/portal/login${m ? `?m=${m}` : ''}`
  return (
    <MarcoAcceso marca={s.data?.marca ?? null}>
      <h1 className="text-[28px] font-extrabold tracking-tight text-(--p-ink-strong)">Contraseña nueva</h1>
      {enlace.isError ? (
        <div className="mt-6 flex flex-col gap-4">
          <Aviso tono="error">{enlace.error instanceof ApiError ? enlace.error.message : 'El enlace no es válido o ha caducado. Pide uno nuevo.'}</Aviso>
          <Link to={`/portal/recuperar${m ? `?m=${m}` : ''}`} className={BOTON}>
            Pedir otro enlace
          </Link>
        </div>
      ) : (
        <>
          {enlace.data && <p className="mt-1 text-[14.5px] text-(--p-muted)">Para tu usuario «{enlace.data.usuario}».</p>}
          <form onSubmit={(e) => void enviar(e)} className="mt-6 flex flex-col gap-4" noValidate>
            {msg && <Aviso tono={msg.tono}>{msg.texto}</Aviso>}
            {!hecho && (
              <>
                <label className="text-[13px] font-semibold text-(--p-ink-strong)">
                  Contraseña nueva
                  <span className="relative mt-1.5 block">
                    <input type={ver ? 'text' : 'password'} autoComplete="new-password" value={pass} onChange={(e) => setPass(e.target.value)} className={`${CAMPO} pr-11`} />
                    <button type="button" onClick={() => setVer(!ver)} className="absolute top-1/2 right-3 -translate-y-1/2 p-1 text-(--p-muted)" aria-label={ver ? 'Ocultar' : 'Ver'}>
                      {ver ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                    </button>
                  </span>
                </label>
                <label className="text-[13px] font-semibold text-(--p-ink-strong)">
                  Repítela
                  <input type={ver ? 'text' : 'password'} autoComplete="new-password" value={pass2} onChange={(e) => setPass2(e.target.value)} className={`${CAMPO} mt-1.5`} />
                </label>
                <button type="submit" className={BOTON} disabled={!enlace.data}>
                  Guardar contraseña
                </button>
              </>
            )}
            {hecho && (
              <Link to={login} className={BOTON}>
                Entrar <ArrowRight className="size-4" />
              </Link>
            )}
          </form>
        </>
      )}
    </MarcoAcceso>
  )
}
