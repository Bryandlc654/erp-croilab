import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Check, LogOut, Search, UserMinus, UserPlus } from 'lucide-react'
import { api } from '../../../shared/api/client'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import PresenceDot from '../../../shared/ui/PresenceDot'
import Select from '../../../shared/ui/Select'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { useQueryClient } from '@tanstack/react-query'
import { clavesCom, mensaje } from '../api'
import { SalaOpcionalRespuesta, SalaRespuesta, type Sala, type SalasDatos } from '../schemas'

/* «Nuevo mensaje»: una persona = directo; varias = grupo (con nombre). */
export function NuevoMensajeModal({ open, onClose, datos }: { open: boolean; onClose: () => void; datos: SalasDatos }) {
  return open ? <NuevoMensaje onClose={onClose} datos={datos} /> : null
}

function NuevoMensaje({ onClose, datos }: { onClose: () => void; datos: SalasDatos }) {
  const [q, setQ] = useState('')
  const [elegidos, setElegidos] = useState<number[]>([])
  const [nombre, setNombre] = useState('')
  const [enviando, setEnviando] = useState(false)
  const { aviso } = useToast()
  const navegar = useNavigate()
  const qc = useQueryClient()
  const personas = useMemo(() => datos.personas.filter((p) => p.username.toLowerCase().includes(q.trim().toLowerCase())), [datos.personas, q])
  const grupo = elegidos.length >= 2

  function alternar(id: number) {
    setElegidos((e) => (e.includes(id) ? e.filter((x) => x !== id) : datos.puede_crear_grupo ? [...e, id] : [id]))
  }

  async function crear() {
    if (!elegidos.length) return
    if (grupo && !nombre.trim()) {
      aviso('Ponle un nombre al grupo', { tipo: 'error' })
      return
    }
    setEnviando(true)
    try {
      const r = await api('/api/v1/chat/salas', { method: 'POST', body: grupo ? { tipo: 'grupo', nombre, miembros: elegidos } : { tipo: 'dm', con: elegidos[0] }, schema: SalaRespuesta })
      void qc.invalidateQueries({ queryKey: clavesCom.salas })
      onClose()
      navegar(`/chat/${r.sala.id}`)
    } catch (e) {
      aviso(mensaje(e, 'No se ha podido abrir la conversación.'), { tipo: 'error' })
    } finally {
      setEnviando(false)
    }
  }

  return (
    <Modal open onClose={onClose} size="sm" title="Nuevo mensaje" className="!w-[392px]">
      <ModalBody className="!gap-3">
        <label className="relative block">
          <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" aria-hidden="true" />
          <TextInput autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar persona…" aria-label="Buscar persona" className="!pl-9" />
        </label>
        <p className="text-[12.5px] text-muted">
          Elige <b className="text-ink">una persona</b> para un chat directo{datos.puede_crear_grupo ? <>, o <b className="text-ink">varias</b> para crear un grupo</> : null}.
        </p>
        <div className={`overflow-hidden transition-[max-height,opacity] duration-300 ${grupo ? 'max-h-20 opacity-100' : 'max-h-0 opacity-0'}`}>
          <TextInput value={nombre} onChange={(e) => setNombre(e.target.value)} placeholder="Nombre del grupo…" aria-label="Nombre del grupo" maxLength={120} tabIndex={grupo ? 0 : -1} />
        </div>
        <ul className="-mx-2 max-h-[330px] overflow-y-auto" role="listbox" aria-multiselectable={datos.puede_crear_grupo}>
          {personas.map((p) => {
            const on = elegidos.includes(p.id)
            const pres = datos.presencia[String(p.id)]
            return (
              <li key={p.id}>
                <button type="button" role="option" aria-selected={on} onClick={() => alternar(p.id)} className="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left hover:bg-soft">
                  <span className="relative inline-flex">
                    <Avatar nombre={p.username} foto={p.foto} size={44} />
                    {pres && <PresenceDot state={pres.estado} size={11} className="absolute right-0 bottom-0" />}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[15px] font-[550] text-ink-strong">{p.username}</span>
                    {pres && <span className="block text-[12px] text-muted">{pres.texto}</span>}
                  </span>
                  <span className={`flex size-6 shrink-0 items-center justify-center rounded-full border-2 ${on ? 'border-accent bg-accent text-white dark:text-accent-fg' : 'border-line-strong'}`} aria-hidden="true">
                    {on && <Check className="size-3.5" strokeWidth={3} />}
                  </span>
                </button>
              </li>
            )
          })}
          {personas.length === 0 && <li className="px-2 py-6 text-center text-[13px] text-muted">Nadie con ese nombre.</li>}
        </ul>
      </ModalBody>
      <ModalFooter>
        <Button onClick={() => void crear()} disabled={!elegidos.length} loading={enviando} className="w-full justify-center">
          {!elegidos.length ? 'Elige a alguien' : grupo ? `Crear grupo · ${elegidos.length}` : 'Enviar mensaje'}
        </Button>
      </ModalFooter>
    </Modal>
  )
}

/* «Info del grupo»: nombre, miembros (quitar / añadir) y salir. */
export function InfoGrupoModal({ open, onClose, sala, datos }: { open: boolean; onClose: () => void; sala: Sala; datos: SalasDatos }) {
  return open ? <InfoGrupo onClose={onClose} sala={sala} datos={datos} /> : null
}

function InfoGrupo({ onClose, sala, datos }: { onClose: () => void; sala: Sala; datos: SalasDatos }) {
  const [nombre, setNombre] = useState(sala.nombre)
  const [nuevo, setNuevo] = useState(0)
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const navegar = useNavigate()
  const qc = useQueryClient()
  const puedeSacar = sala.creado_por === datos.yo || datos.puede_gestionar
  const fuera = datos.personas.filter((p) => !sala.miembros.some((m) => m.id === p.id))

  async function hacer(f: () => Promise<unknown>, ok: string) {
    try {
      await f()
      void qc.invalidateQueries({ queryKey: clavesCom.salas })
      aviso(ok)
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }

  async function salir() {
    const si = await confirm({ title: '¿Salir de este grupo?', message: 'Dejarás de ver sus mensajes. Te pueden volver a añadir.', okLabel: 'Salir', danger: true })
    if (!si) return
    await hacer(() => api(`/api/v1/chat/salas/${sala.id}/miembros/${datos.yo}`, { method: 'DELETE', schema: SalaOpcionalRespuesta }), 'Has salido del grupo')
    onClose()
    navegar('/chat')
  }

  return (
    <Modal open onClose={onClose} size="md" title="Info del grupo">
      <ModalBody>
        <div className="flex gap-2">
          <TextInput value={nombre} onChange={(e) => setNombre(e.target.value)} maxLength={120} aria-label="Nombre del grupo" />
          <Button
            variant="ghost"
            disabled={!nombre.trim() || nombre.trim() === sala.nombre}
            onClick={() => void hacer(() => api(`/api/v1/chat/salas/${sala.id}`, { method: 'PATCH', body: { nombre }, schema: SalaRespuesta }), 'Nombre guardado')}
          >
            Guardar
          </Button>
        </div>
        <div>
          <h3 className="mb-2 text-[11px] font-bold tracking-[.5px] text-muted uppercase">Miembros ({sala.miembros.length})</h3>
          <ul className="space-y-1">
            {sala.miembros.map((m) => (
              <li key={m.id} className="flex items-center gap-2.5 rounded-lg px-1 py-1.5">
                <Avatar nombre={m.username} foto={m.foto} size={30} />
                <span className="flex-1 truncate text-[13.5px] text-ink-strong">
                  {m.username}
                  {m.id === datos.yo && <span className="text-muted"> (tú)</span>}
                  {m.id === sala.creado_por && <span className="ml-1.5 rounded bg-chip px-1.5 py-px text-[10.5px] font-semibold text-label">creó el grupo</span>}
                </span>
                {m.id !== datos.yo && puedeSacar && (
                  <Button
                    variant="ghost"
                    size="sm"
                    icon={<UserMinus />}
                    onClick={() => void hacer(() => api(`/api/v1/chat/salas/${sala.id}/miembros/${m.id}`, { method: 'DELETE', schema: SalaOpcionalRespuesta }), `${m.username} ya no está en el grupo`)}
                  >
                    Quitar
                  </Button>
                )}
              </li>
            ))}
          </ul>
        </div>
        {fuera.length > 0 && (
          <div className="flex gap-2">
            <div className="min-w-0 flex-1">
              <Select value={nuevo || null} onChange={setNuevo} options={fuera.map((p) => ({ value: p.id, label: p.username }))} placeholder="Añadir a alguien…" searchable />
            </div>
            <Button
              variant="ghost"
              icon={<UserPlus />}
              disabled={!nuevo}
              onClick={() => void hacer(() => api(`/api/v1/chat/salas/${sala.id}/miembros`, { method: 'POST', body: { admin_id: nuevo }, schema: SalaRespuesta }), 'Añadido al grupo').then(() => setNuevo(0))}
            >
              Añadir
            </Button>
          </div>
        )}
      </ModalBody>
      <ModalFooter className="!justify-between">
        <Button variant="danger" icon={<LogOut />} onClick={() => void salir()}>
          Salir del grupo
        </Button>
        <Button variant="ghost" onClick={onClose}>
          Cerrar
        </Button>
      </ModalFooter>
    </Modal>
  )
}
