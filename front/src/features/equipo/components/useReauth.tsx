import { useCallback, useRef, useState, type FormEvent } from 'react'
import { Lock } from 'lucide-react'
import { useAuth } from '../../auth/useAuth'
import Button from '../../../shared/ui/Button'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Field from '../../../shared/ui/Field'
import { TextInput } from '../../../shared/ui/TextInput'
import { ApiErrorConCampo, mensaje, pedir } from '../api'
import { ReconfirmarRespuesta } from '../schemas'

type Pendiente = { zona: string; resolver: (ok: boolean) => void }

/* «Confirma que eres tú» (lib/reauth.php): si la API contesta 403
   {error:"reauth", zona}, pide la contraseña, la confirma y repite la acción.
   La confirmación vale 30 minutos para esa zona.

     const { conReauth, dialogo } = useReauth()
     const v = await conReauth(() => pedir(...))   // null si se cancela */
export function useReauth() {
  const { me } = useAuth()
  const [pend, setPend] = useState<Pendiente | null>(null)
  const [pass, setPass] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const campo = useRef<HTMLInputElement>(null)

  const pedirContrasena = useCallback((zona: string) => new Promise<boolean>((resolver) => {
    setPass('')
    setErr('')
    setPend({ zona, resolver })
  }), [])

  const conReauth = useCallback(
    async <T,>(fn: () => Promise<T>): Promise<T | null> => {
      try {
        return await fn()
      } catch (e) {
        if (!(e instanceof ApiErrorConCampo) || e.codigo !== 'reauth') throw e
        const ok = await pedirContrasena(e.zona ?? 'boveda')
        if (!ok) return null
        return fn()
      }
    },
    [pedirContrasena],
  )

  function cerrar(ok: boolean) {
    pend?.resolver(ok)
    setPend(null)
  }

  async function enviar(e: FormEvent) {
    e.preventDefault()
    if (!pend || busy) return
    setBusy(true)
    setErr('')
    try {
      await pedir('/api/v1/auth/reconfirmar', { method: 'POST', body: { password: pass, zona: pend.zona }, schema: ReconfirmarRespuesta })
      cerrar(true)
    } catch (er) {
      setErr(mensaje(er, 'No se ha podido comprobar la contraseña.'))
      campo.current?.select()
    } finally {
      setBusy(false)
    }
  }

  const dialogo = (
    <Modal open={pend !== null} onClose={() => cerrar(false)} size="sm" title="Confirma que eres tú" initialFocus={campo}>
      <form onSubmit={enviar}>
        <ModalBody className="space-y-4">
          <p className="text-[13px] leading-[1.55] text-muted">Que la sesión esté abierta no prueba que estés tú delante. Escribe tu contraseña para seguir.</p>
          <div className="flex items-center gap-2 rounded-[10px] bg-soft px-3 py-2 text-[12.5px] font-semibold text-ink">
            <Lock className="size-3.5 text-label" aria-hidden="true" />
            {me?.username}
          </div>
          <Field label="Tu contraseña" error={err || undefined}>
            <TextInput ref={campo} type="password" autoComplete="current-password" value={pass} onChange={(e) => setPass(e.target.value)} required />
          </Field>
          <p className="text-[11.5px] text-label">Se queda desbloqueado 30 minutos.</p>
        </ModalBody>
        <ModalFooter>
          <Button variant="ghost" onClick={() => cerrar(false)}>
            Cancelar
          </Button>
          <Button type="submit" loading={busy} loadingText="Comprobando…" disabled={!pass}>
            Desbloquear
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )

  return { conReauth, dialogo }
}
