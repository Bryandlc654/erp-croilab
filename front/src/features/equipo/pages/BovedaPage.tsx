import { useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { AtSign, Check, Copy, Database, ExternalLink, Eye, EyeOff, Globe, KeyRound, Layers, Lock, Mail, Pencil, Plus, Server, Share2, Trash2, Vault } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Breadcrumbs from '../../../shared/ui/Breadcrumbs'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import Checkbox from '../../../shared/ui/Checkbox'
import EmptyState from '../../../shared/ui/EmptyState'
import Field from '../../../shared/ui/Field'
import { SearchBox } from '../../../shared/ui/FilterBar'
import FormGrid from '../../../shared/ui/FormGrid'
import IconButton from '../../../shared/ui/IconButton'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Select from '../../../shared/ui/Select'
import { TextArea, TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { useAuth } from '../../auth/useAuth'
import { ErrorCarga, Esqueleto, SinPermiso } from '../components/piezas'
import { useReauth } from '../components/useReauth'
import { campoDeError, clavesEquipo, mensaje, pedir, useAccion, useBovedaClientes, useCredenciales } from '../api'
import { quitarTildes } from '../logica'
import { BorrarRespuesta, CredencialRespuesta, SecretoRespuesta, type Categoria, type Credencial } from '../schemas'

const ICONO: Record<Categoria, ReactNode> = {
  web: <Globe />,
  correo: <Mail />,
  hosting: <Server />,
  database: <Database />,
  api: <KeyRound />,
  cms: <Layers />,
  domain: <AtSign />,
  social: <Share2 />,
  other: <Lock />,
}
const NOMBRE: Record<Categoria, string> = {
  web: 'Web',
  correo: 'Correo',
  hosting: 'Hosting',
  database: 'Base de datos',
  api: 'API / Token',
  cms: 'CMS',
  domain: 'Dominio',
  social: 'Redes',
  other: 'Otro',
}

/* Bóveda de credenciales de los clientes (credenciales.php). Vive en
   /ajustes/boveda (menú de ajustes) y en /credenciales (menú de trabajo). */
export default function BovedaPage() {
  const { id } = useParams()
  const { pathname } = useLocation()
  const { can } = useAuth()
  const base = pathname.startsWith('/credenciales') ? '/credenciales' : '/ajustes/boveda'
  if (!can('ver.credenciales')) return <SinPermiso que="la bóveda de credenciales" />
  return id ? <ClienteBoveda id={Number(id)} base={base} /> : <Selector base={base} />
}

function Selector({ base }: { base: string }) {
  const q = useBovedaClientes()
  const [busca, setBusca] = useState('')
  const lista = (q.data?.items ?? []).filter((c) => !busca || quitarTildes(c.nombre).includes(quitarTildes(busca)))
  return (
    <div className="max-w-[1180px]">
      <header className="mb-6">
        <h1 className="flex items-center gap-2.5 text-[26px] font-semibold tracking-[-.5px] text-ink-strong">
          <Lock className="size-6 text-muted" aria-hidden="true" />
          Bóveda de credenciales
        </h1>
        <p className="mt-2 text-[15px] text-muted">Elige un cliente para ver todos sus accesos (web, correo, hosting, API…).</p>
      </header>
      <SearchBox value={busca} onChange={setBusca} delay={0} placeholder="Buscar cliente…" className="mb-5 !max-w-none" />
      {q.isPending ? (
        <Esqueleto />
      ) : q.isError ? (
        <ErrorCarga error={q.error} />
      ) : lista.length === 0 ? (
        <EmptyState icon={<Vault />} title={busca ? 'Ningún cliente coincide' : 'No hay clientes que puedas ver'} />
      ) : (
        <div className="grid grid-cols-[repeat(auto-fill,minmax(230px,1fr))] gap-3.5">
          {lista.map((c) => (
            <Link
              key={c.id}
              to={`${base}/${c.id}`}
              className={`flex items-center gap-3 rounded-2xl border border-line bg-card px-4 py-3.5 transition-[transform,border-color,box-shadow] duration-150 hover:-translate-y-0.5 hover:border-line-strong hover:shadow-card-hover ${c.activo ? '' : 'opacity-60'}`}
            >
              <Avatar nombre={c.nombre} inicialesGuardadas={c.iniciales} size={38} forma="cuadrado" />
              <span className="min-w-0">
                <span className="block truncate text-[14px] font-semibold text-ink-strong">{c.nombre}</span>
                <span className="text-[12px] text-muted">
                  {c.n} credencial{c.n === 1 ? '' : 'es'}
                  {!c.activo && ' · No activo'}
                </span>
              </span>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}

function ClienteBoveda({ id, base }: { id: number; base: string }) {
  const q = useCredenciales(id)
  const [busca, setBusca] = useState('')
  const [cat, setCat] = useState<Categoria | 'todas'>('todas')
  const [editando, setEditando] = useState<Credencial | 'nueva' | null>(null)
  const { conReauth, dialogo } = useReauth()
  const items = useMemo(() => q.data?.items ?? [], [q.data])
  const presentes = (Object.keys(NOMBRE) as Categoria[]).filter((k) => items.some((c) => c.categoria === k))
  const t = quitarTildes(busca)
  const visibles = items.filter((c) => (cat === 'todas' || c.categoria === cat) && (!t || quitarTildes(`${c.titulo} ${c.usuario} ${c.url}`).includes(t)))

  if (q.isPending) return <Esqueleto />
  if (q.isError) return <ErrorCarga error={q.error} />
  const d = q.data
  return (
    <div className="max-w-[1180px]">
      <Breadcrumbs items={[{ label: 'Bóveda', to: base }, { label: d.cliente.nombre }]} />
      <header className="mt-2 mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-[26px] font-semibold tracking-[-.5px] text-ink-strong">{d.cliente.nombre}</h1>
          <p className="mt-1.5 text-[15px] text-muted">
            {items.length} credencial{items.length === 1 ? '' : 'es'} guardada{items.length === 1 ? '' : 's'}.
          </p>
        </div>
        {d.puede_editar && (
          <Button icon={<Plus />} onClick={() => setEditando('nueva')}>
            Registrar credencial
          </Button>
        )}
      </header>

      {items.length > 0 && (
        <div className="mb-5 flex flex-wrap items-center gap-2.5">
          <SearchBox value={busca} onChange={setBusca} delay={0} placeholder="Buscar credenciales…" className="max-sm:basis-full" />
          <div className="flex flex-wrap gap-1.5">
            <Filtro on={cat === 'todas'} onClick={() => setCat('todas')} n={items.length}>
              Todas
            </Filtro>
            {presentes.map((k) => (
              <Filtro key={k} on={cat === k} onClick={() => setCat(k)} n={items.filter((c) => c.categoria === k).length}>
                {NOMBRE[k]}
              </Filtro>
            ))}
          </div>
        </div>
      )}

      {items.length === 0 ? (
        <EmptyState
          icon={<Vault />}
          title="Sin credenciales todavía."
          actions={
            d.puede_editar && (
              <Button icon={<Plus />} onClick={() => setEditando('nueva')}>
                Registrar la primera
              </Button>
            )
          }
        />
      ) : visibles.length === 0 ? (
        <EmptyState variant="compact" title="Nada coincide con la búsqueda." />
      ) : (
        <div className="grid grid-cols-[repeat(auto-fill,minmax(320px,1fr))] gap-4 max-sm:grid-cols-1">
          {visibles.map((c) => (
            <Tarjeta key={c.id} c={c} cli={id} puede={d.puede_editar} onEditar={() => setEditando(c)} conReauth={conReauth} />
          ))}
        </div>
      )}
      {editando && <ModalCredencial cli={id} c={editando === 'nueva' ? null : editando} onClose={() => setEditando(null)} />}
      {dialogo}
    </div>
  )
}

function Filtro({ on, onClick, n, children }: { on: boolean; onClick: () => void; n: number; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={on}
      className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-semibold transition-colors max-sm:min-h-[38px] ${
        on ? 'border-tab-on bg-tab-on text-white dark:border-rev dark:bg-rev dark:text-rev-fg' : 'border-line bg-card text-muted hover:bg-soft hover:text-ink'
      }`}
    >
      {children}
      <span className={`text-[11px] font-bold ${on ? 'opacity-70' : 'text-label'}`}>{n}</span>
    </button>
  )
}

type ConReauth = <T>(fn: () => Promise<T>) => Promise<T | null>

function Tarjeta({ c, cli, puede, onEditar, conReauth }: { c: Credencial; cli: number; puede: boolean; onEditar: () => void; conReauth: ConReauth }) {
  const [secreto, setSecreto] = useState<string | null>(null)
  const [copiado, setCopiado] = useState<'u' | 's' | null>(null)
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const borrar = useAccion(() => pedir(`/api/v1/credenciales/${c.id}`, { method: 'DELETE', schema: BorrarRespuesta }), [clavesEquipo.credenciales(cli), clavesEquipo.bovedaClientes])

  async function revelar(): Promise<string | null> {
    if (secreto !== null) return secreto
    try {
      const r = await conReauth(() => pedir(`/api/v1/credenciales/${c.id}/revelar`, { method: 'POST', schema: SecretoRespuesta }))
      if (!r) return null
      setSecreto(r.secreto)
      /* No se queda a la vista: se vuelve a tapar al minuto. */
      setTimeout(() => setSecreto(null), 60_000)
      return r.secreto
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
      return null
    }
  }

  async function copiar(que: 'u' | 's') {
    const v = que === 'u' ? c.usuario : await revelar()
    if (v === null) return
    try {
      await navigator.clipboard.writeText(v)
      setCopiado(que)
      setTimeout(() => setCopiado(null), 1100)
    } catch {
      aviso('No se ha podido copiar.', { tipo: 'error' })
    }
  }

  return (
    <Card padding="none" className="flex flex-col gap-3 p-[22px] transition-transform duration-150 hover:-translate-y-0.5 max-sm:p-4">
      <div className="flex items-start gap-3">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-soft text-muted [&>svg]:size-[18px]">{ICONO[c.categoria]}</span>
        <div className="min-w-0 flex-1">
          <h3 className="truncate text-[15px] font-semibold text-ink-strong">{c.titulo}</h3>
          <div className="mt-1 flex flex-wrap gap-1.5">
            <span className="rounded-md bg-soft px-1.5 py-px text-[9.5px] font-bold tracking-[.4px] text-muted uppercase">{NOMBRE[c.categoria]}</span>
            {c.visible_cliente && (
              <span title="El cliente ve este acceso en su portal" className="inline-flex items-center gap-1 rounded-md bg-[#e4f6ec] px-1.5 py-px text-[9.5px] font-bold tracking-[.4px] text-[#12854a] uppercase dark:bg-ok-bg dark:text-ok">
                <Eye className="size-2.5" aria-hidden="true" />
                Visible
              </span>
            )}
          </div>
        </div>
        {puede && (
          <div className="flex shrink-0">
            <IconButton label="Editar" icon={<Pencil />} onClick={onEditar} />
            <IconButton
              label="Borrar"
              tone="danger"
              icon={<Trash2 />}
              onClick={async () => {
                if (!(await confirm({ title: '¿Borrar credencial?', message: `«${c.titulo}» se va a la papelera.`, okLabel: 'Borrar', danger: true }))) return
                borrar.mutate(undefined, { onSuccess: () => aviso('Credencial borrada'), onError: (e) => aviso(mensaje(e), { tipo: 'error' }) })
              }}
            />
          </div>
        )}
      </div>
      {c.usuario && (
        <Dato etiqueta="Usuario">
          <code className="min-w-0 flex-1 truncate font-mono text-[13px] text-ink">{c.usuario}</code>
          <MiniBoton label="Copiar usuario" onClick={() => void copiar('u')}>
            {copiado === 'u' ? <Check className="text-[#12a150]" /> : <Copy />}
          </MiniBoton>
        </Dato>
      )}
      {c.tiene_secreto && (
        <Dato etiqueta="Contraseña">
          <code className="min-w-0 flex-1 truncate font-mono text-[13px] text-ink">{secreto ?? '••••••••••••'}</code>
          <MiniBoton label={secreto === null ? 'Ver' : 'Ocultar'} onClick={() => (secreto === null ? void revelar() : setSecreto(null))}>
            {secreto === null ? <Eye /> : <EyeOff />}
          </MiniBoton>
          <MiniBoton label="Copiar contraseña" onClick={() => void copiar('s')}>
            {copiado === 's' ? <Check className="text-[#12a150]" /> : <Copy />}
          </MiniBoton>
        </Dato>
      )}
      {c.nota && <p className="text-[12.5px] leading-[1.5] whitespace-pre-line text-muted">{c.nota}</p>}
      {c.url && (
        <a href={c.url} target="_blank" rel="noopener noreferrer" className="mt-auto inline-flex w-fit items-center gap-1.5 text-[12.5px] font-semibold text-ink hover:underline">
          <ExternalLink className="size-3.5" aria-hidden="true" />
          Acceder al servicio
        </a>
      )}
    </Card>
  )
}

function Dato({ etiqueta, children }: { etiqueta: string; children: ReactNode }) {
  return (
    <div className="rounded-[11px] bg-[#f7f8fa] px-3.5 py-2.5 dark:bg-soft">
      <p className="text-[9.5px] font-bold tracking-[.5px] text-label uppercase">{etiqueta}</p>
      <div className="mt-0.5 flex items-center gap-1">{children}</div>
    </div>
  )
}

function MiniBoton({ label, onClick, children }: { label: string; onClick: () => void; children: ReactNode }) {
  return (
    <button type="button" onClick={onClick} title={label} aria-label={label} className="flex size-7 shrink-0 items-center justify-center rounded-md text-label hover:bg-card hover:text-ink [&>svg]:size-[15px]">
      {children}
    </button>
  )
}

function ModalCredencial({ cli, c, onClose }: { cli: number; c: Credencial | null; onClose: () => void }) {
  const [f, setF] = useState({
    titulo: c?.titulo ?? '',
    categoria: (c?.categoria ?? 'web') as Categoria,
    usuario: c?.usuario ?? '',
    secreto: '',
    url: c?.url ?? '',
    nota: c?.nota ?? '',
    visible_cliente: c?.visible_cliente ?? false,
  })
  const [err, setErr] = useState<{ campo: string | null; msg: string } | null>(null)
  const { aviso } = useToast()
  const guardar = useAccion(
    (b: typeof f) =>
      c
        ? pedir(`/api/v1/credenciales/${c.id}`, { method: 'PATCH', body: b, schema: CredencialRespuesta })
        : pedir(`/api/v1/credenciales/clientes/${cli}`, { method: 'POST', body: b, schema: CredencialRespuesta }),
    [clavesEquipo.credenciales(cli), clavesEquipo.bovedaClientes],
  )
  const e = (k: string) => (err?.campo === k ? err.msg : undefined)

  function onSubmit(ev: FormEvent) {
    ev.preventDefault()
    if (!f.titulo.trim()) return setErr({ campo: 'titulo', msg: 'Ponle un título (ej: WordPress de la web).' })
    guardar.mutate(f, {
      onSuccess: () => {
        aviso(c ? 'Credencial guardada' : 'Credencial registrada')
        onClose()
      },
      onError: (er) => setErr({ campo: campoDeError(er), msg: mensaje(er) }),
    })
  }

  return (
    <Modal open onClose={onClose} size="xl" align="top" title={c ? 'Editar credencial' : 'Registrar credencial'}>
      <form onSubmit={onSubmit}>
        <ModalBody>
          {err && !err.campo && <p className="mb-3 text-[13px] text-[#ef4444]">{err.msg}</p>}
          <FormGrid>
            <Field label="Título" required span={8} error={e('titulo')}>
              <TextInput autoFocus value={f.titulo} onChange={(x) => setF({ ...f, titulo: x.target.value })} placeholder="Ej: WordPress de la web" maxLength={160} />
            </Field>
            <Field label="Categoría" span={4}>
              <Select value={f.categoria} onChange={(v) => setF({ ...f, categoria: v })} options={(Object.keys(NOMBRE) as Categoria[]).map((k) => ({ value: k, label: NOMBRE[k] }))} />
            </Field>
            <Field label="Usuario / email" span={6} error={e('usuario')}>
              <TextInput value={f.usuario} onChange={(x) => setF({ ...f, usuario: x.target.value })} autoComplete="off" />
            </Field>
            <Field label="Contraseña / token" span={6} error={e('secreto')} hint={c?.tiene_secreto ? 'Déjalo vacío para no cambiar la que hay.' : 'Se guarda cifrada.'}>
              <TextInput type="password" value={f.secreto} onChange={(x) => setF({ ...f, secreto: x.target.value })} autoComplete="new-password" placeholder={c?.tiene_secreto ? '(ya guardada)' : ''} />
            </Field>
            <Field label="URL de acceso" span={12} error={e('url')}>
              <TextInput value={f.url} onChange={(x) => setF({ ...f, url: x.target.value })} placeholder="https://…" />
            </Field>
            <Field label="Nota" span={12} error={e('nota')}>
              <TextArea value={f.nota} onChange={(x) => setF({ ...f, nota: x.target.value })} maxLength={500} rows={2} />
            </Field>
            <div className="col-span-12">
              <Checkbox
                checked={f.visible_cliente}
                onChange={(v) => setF({ ...f, visible_cliente: v })}
                label={
                  <span>
                    <b>Visible para el cliente</b> en su portal (Accesos). Deja sin marcar los accesos internos (FTP, base de datos, tokens…).
                  </span>
                }
              />
            </div>
          </FormGrid>
        </ModalBody>
        <ModalFooter>
          <Button variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" loading={guardar.isPending} loadingText="Guardando…">
            Guardar
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
