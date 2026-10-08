import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowUpRight, Check, Copy, Database, Eye, EyeOff, Globe, KeyRound, Link2, Mail, MessageSquare, Server, Share2 } from 'lucide-react'
import { ApiError } from '../../../../shared/api/client'
import { useToast } from '../../../../shared/ui/useToast'
import { verSecreto } from '../../api'
import { usePortal } from '../../contexto'
import type { Acceso, Credencial } from '../../schemas'
import { CATEGORIA_CRED } from '../../textos'
import Editable from '../Editable'
import { BotonP, CabeceraVista, Tarjeta } from '../ui'

const ICONO_CAT: Record<string, typeof Globe> = { web: Globe, correo: Mail, hosting: Server, database: Database, api: KeyRound, cms: Globe, domain: Globe, social: Share2, other: KeyRound }
const ETIQUETA_TIPO: Record<string, string> = { figma: 'Figma', drive: 'Google Drive', web: 'Sitio web', looker: 'Looker Studio', generic: 'Enlace' }

export default function Accesos() {
  const { datos: d, ruta } = usePortal()
  const nav = useNavigate()
  return (
    <div className="flex flex-col gap-6">
      {d.credenciales.length > 0 && (
        <section>
          <CabeceraVista titulo="Tus contraseñas y accesos" sub="Tus claves de acceso, guardadas y seguras. Pulsa el ojo para verlas o el icono para copiarlas." />
          <div className="grid gap-3.5 [grid-template-columns:repeat(auto-fill,minmax(min(320px,100%),1fr))]">
            {d.credenciales.map((c) => (
              <Boveda key={c.id} c={c} />
            ))}
          </div>
        </section>
      )}
      <Editable bloque="accesos" label="Editar accesos">
        <section className="p-1">
          <CabeceraVista titulo="Enlaces y recursos" sub="Todo lo que compartimos contigo, a un clic." />
          {d.accesos.length ? (
            <div className="grid gap-3.5 md:grid-cols-2">
              {d.accesos.map((a, i) => (
                <Recurso key={i} a={a} />
              ))}
            </div>
          ) : (
            <Tarjeta className="px-6 py-6 text-center text-[14px] text-(--p-muted)">Aún no hay enlaces compartidos.</Tarjeta>
          )}
        </section>
      </Editable>
      <section>
        <CabeceraVista titulo="¿Necesitas algo?" />
        <div className="flex flex-wrap items-center gap-4 rounded-[20px] bg-(--p-dark) px-6 py-6 text-white">
          <div className="min-w-0 flex-1">
            <p className="text-[17px] font-bold">¿Necesitas algo de tu equipo de {d.marca.name}?</p>
            <p className="text-[14px] text-white/75">Ábrenos un ticket y te respondemos por aquí. Verás el estado en Soporte.</p>
          </div>
          <BotonP variante="claro" icono={<MessageSquare />} onClick={() => nav(ruta('soporte') + '?nuevo=1')} className="!border-white/20 !bg-white/10 !text-white">
            Abrir un ticket
          </BotonP>
        </div>
      </section>
      <p className="text-center text-[12px] text-(--p-muted)">
        {d.marca.name} · Área privada de {d.cliente.name} · Actualizado automáticamente desde el seguimiento del proyecto.
      </p>
    </div>
  )
}

function Recurso({ a }: { a: Acceso }) {
  const host = URL.canParse(a.u) ? new URL(a.u).hostname : ''
  const contenido = (
    <>
      <span className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-(--p-soft)">
        {host ? <img src={`https://www.google.com/s2/favicons?sz=64&domain=${encodeURIComponent(host)}`} alt="" className="size-6" loading="lazy" /> : <Link2 className="size-5 text-(--p-muted)" />}
      </span>
      <span className="min-w-0 flex-1">
        <b className="block truncate text-[15px] text-(--p-ink-strong)">{a.b}</b>
        <span className="block truncate text-[13px] text-(--p-muted)">{a.s || ETIQUETA_TIPO[a.tipo] || 'Enlace'}</span>
      </span>
      {a.u && <ArrowUpRight className="size-4 text-(--p-muted)" />}
    </>
  )
  const clase = 'flex items-center gap-3.5 rounded-[20px] border border-(--p-line) bg-(--p-card) p-[18px] shadow-(--p-shadow)'
  return a.u ? (
    <a href={a.u} target="_blank" rel="noopener noreferrer" className={`${clase} transition hover:-translate-y-0.5`}>
      {contenido}
    </a>
  ) : (
    <div className={clase}>{contenido}</div>
  )
}

function Boveda({ c }: { c: Credencial }) {
  const { datos: d } = usePortal()
  const { aviso } = useToast()
  const [secreto, setSecreto] = useState<string | null>(null)
  const [ver, setVer] = useState(false)
  const [copiado, setCopiado] = useState<'u' | 's' | null>(null)
  const Icono = ICONO_CAT[c.categoria] ?? KeyRound
  const pedir = async () => {
    if (secreto !== null) return secreto
    if (!d.puede_enviar) {
      aviso('En la vista previa no se revelan contraseñas: ábrelas desde la bóveda.', { tipo: 'plain' })
      return null
    }
    try {
      const s = await verSecreto(c.id)
      setSecreto(s)
      return s
    } catch (e) {
      aviso(e instanceof ApiError ? e.message : 'No se ha podido leer la contraseña.', { tipo: 'error' })
      return null
    }
  }
  const copiar = async (que: 'u' | 's') => {
    const v = que === 'u' ? c.usuario : await pedir()
    if (v === null) return
    try {
      await navigator.clipboard.writeText(v)
      setCopiado(que)
      setTimeout(() => setCopiado(null), 1400)
    } catch {
      aviso('No se ha podido copiar.', { tipo: 'error' })
    }
  }
  const boton = 'rounded-md p-1 text-(--p-muted) hover:text-(--p-ink-strong) [&_svg]:size-4'
  return (
    <Tarjeta className="p-[18px]">
      <div className="flex items-center gap-3">
        <span className="flex size-9 items-center justify-center rounded-xl bg-(--p-soft) text-(--p-ink-strong)">
          <Icono className="size-4" />
        </span>
        <div>
          <b className="block text-[15px] text-(--p-ink-strong)">{c.titulo}</b>
          <span className="text-[11px] font-bold text-(--p-muted) uppercase">{CATEGORIA_CRED[c.categoria] ?? 'Acceso'}</span>
        </div>
      </div>
      {c.usuario && (
        <div className="mt-3 rounded-xl bg-(--p-soft) px-3 py-2.5">
          <p className="text-[10.5px] font-bold text-(--p-muted) uppercase">Usuario</p>
          <div className="flex items-center gap-2">
            <code className="flex-1 truncate text-[13px] text-(--p-ink-strong)">{c.usuario}</code>
            <button type="button" className={boton} onClick={() => void copiar('u')} aria-label="Copiar usuario">
              {copiado === 'u' ? <Check /> : <Copy />}
            </button>
          </div>
        </div>
      )}
      {c.tiene_secreto && (
        <div className="mt-2 rounded-xl bg-(--p-soft) px-3 py-2.5">
          <p className="text-[10.5px] font-bold text-(--p-muted) uppercase">Contraseña</p>
          <div className="flex items-center gap-2">
            <code className="flex-1 truncate text-[13px] text-(--p-ink-strong)">{ver && secreto !== null ? secreto : '••••••••••••'}</code>
            <button
              type="button"
              className={boton}
              onClick={async () => {
                if (ver) return setVer(false)
                if ((await pedir()) !== null) setVer(true)
              }}
              aria-label={ver ? 'Ocultar contraseña' : 'Ver contraseña'}
            >
              {ver ? <EyeOff /> : <Eye />}
            </button>
            <button type="button" className={boton} onClick={() => void copiar('s')} aria-label="Copiar contraseña">
              {copiado === 's' ? <Check /> : <Copy />}
            </button>
          </div>
        </div>
      )}
      {c.nota && <p className="mt-2 rounded-xl bg-(--p-soft) px-3 py-2 text-[12.5px] text-(--p-muted) italic">{c.nota}</p>}
      {/^https?:\/\//i.test(c.url) && (
        <a href={c.url} target="_blank" rel="noopener noreferrer" className="mt-3 inline-flex items-center gap-1.5 text-[13.5px] font-medium text-(--p-ink-strong)">
          <ArrowUpRight className="size-4" /> Acceder al servicio
        </a>
      )}
    </Tarjeta>
  )
}
