import { useState } from 'react'
import { Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Download, Layers, List, Pencil, Plus, Snowflake, Trash2, UserPlus, X } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import EmptyState from '../../../shared/ui/EmptyState'
import IconButton from '../../../shared/ui/IconButton'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { useEquipo } from '../../nav/api'
import { exportarLista, mensajeError, useAccionListas, useCatalogos, useLista, useListas } from '../api'
import { descargarTexto, textoCondiciones } from '../logica'
import { usePermisosCrm } from '../permisos'
import AnadirMiembroModal from './AnadirMiembroModal'
import NuevaListaModal from './NuevaListaModal'
import { FaseBadge } from './piezas'

const TH = 'border-b border-line bg-head px-3 py-[11px] text-left text-[11px] font-[650] tracking-[.5px] text-muted uppercase'
const TD = 'border-b border-line2 px-3 py-3 text-[13.5px]'

/* Listas de contactos (listas.php): una lista activa se recalcula sola con sus
   condiciones (más los añadidos a mano); una estática es una foto fija. */
export default function ListasPage() {
  const { id: idRuta } = useParams()
  const id = Number(idRuta ?? 0)
  const [params, setParams] = useSearchParams()
  const { data: listas, isPending, error } = useListas()
  const p = usePermisosCrm()
  const nueva = params.get('nueva') === '1'
  const navigate = useNavigate()

  function cerrarNueva() {
    const n = new URLSearchParams(params)
    n.delete('nueva')
    setParams(n, { replace: true })
  }

  const cabecera = (
    <PageHeader
      title="Listas"
      actions={
        p.crear && (
          <Button icon={<Plus />} onClick={() => setParams({ nueva: '1' }, { replace: true })}>
            Nueva lista
          </Button>
        )
      }
    />
  )
  const modal = <NuevaListaModal open={nueva} onClose={cerrarNueva} onCreada={(l) => navigate(`/crm/listas/${l}`)} />

  if (error) return <Notice tone="error">{mensajeError(error, 'No se han podido cargar las listas.')}</Notice>
  if (isPending) return <div className="py-[60px] text-center text-[13px] text-muted">Cargando…</div>
  /* Sin id: la primera del menú, como el antiguo (la más reciente). */
  if (!id && listas && listas.length > 0) return <Navigate to={`/crm/listas/${listas[0].id}${nueva ? '?nueva=1' : ''}`} replace />

  return (
    <div>
      {cabecera}
      {id ? (
        <DetalleLista id={id} />
      ) : (
        <EmptyState
          icon={<Layers />}
          title="Aún no hay listas"
          text={
            <>
              Crea la primera con «Nueva lista». Una lista <b>activa</b> se recalcula sola con sus condiciones; una <b>estática</b> congela los contactos actuales.
            </>
          }
        />
      )}
      {modal}
    </div>
  )
}

function DetalleLista({ id }: { id: number }) {
  const { data, error, isPending } = useLista(id)
  const { data: cat } = useCatalogos()
  const { data: equipo = [] } = useEquipo()
  const acc = useAccionListas()
  const p = usePermisosCrm()
  const { confirm, prompt } = useConfirm()
  const { aviso } = useToast()
  const navigate = useNavigate()
  const [anadir, setAnadir] = useState(false)
  const fallo = (e: unknown) => aviso(mensajeError(e), { tipo: 'error' })

  if (error) return <Notice tone="error">{mensajeError(error, 'No se ha podido cargar la lista.')}</Notice>
  if (isPending || !data) return <div className="py-[60px] text-center text-[13px] text-muted">Cargando…</div>
  const l = data.lista
  const cond = l.tipo === 'activa' ? textoCondiciones(l.condiciones, cat, equipo) : ''

  async function exportar() {
    try {
      const r = await exportarLista(l.id)
      descargarTexto(r.nombre, r.csv)
    } catch (e) {
      fallo(e)
    }
  }

  return (
    <section className="rounded-2xl border border-line bg-card">
      <header className="flex flex-wrap items-start gap-3 border-b border-line2 px-6 py-5 max-sm:px-4">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            {l.tipo === 'activa' ? <List className="size-[18px] text-label" /> : <Layers className="size-[18px] text-label" />}
            <h2 className="text-[18px] font-semibold tracking-[-.3px] text-ink-strong">{l.nombre}</h2>
            <span className="rounded-md bg-soft px-2 py-0.5 text-[11px] font-bold text-muted">{l.tipo === 'activa' ? 'Activa' : 'Estática'}</span>
          </div>
          <p className="mt-1 text-[13px] text-muted">
            {l.n} contacto{l.n === 1 ? '' : 's'}
            {l.descripcion && ` · ${l.descripcion}`}
          </p>
          {cond && <p className="mt-1.5 text-[12.5px] text-muted">Condiciones: {cond}</p>}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {p.editar && (
            <Button variant="ghost" size="sm" icon={<UserPlus />} onClick={() => setAnadir(true)}>
              Añadir contacto
            </Button>
          )}
          <Button variant="ghost" size="sm" icon={<Download />} onClick={() => void exportar()}>
            Exportar CSV
          </Button>
          {p.editar && l.tipo === 'activa' && (
            <Button
              variant="ghost"
              size="sm"
              icon={<Snowflake />}
              onClick={async () => {
                if (await confirm({ title: '¿Congelar esta lista?', message: 'Se guardarán los contactos actuales y dejará de actualizarse.', okLabel: 'Congelar' }))
                  acc.congelar.mutate(l.id, { onSuccess: () => aviso('Lista congelada'), onError: fallo })
              }}
            >
              Congelar
            </Button>
          )}
          {p.editar && (
            <IconButton
              label="Renombrar la lista"
              icon={<Pencil />}
              onClick={async () => {
                const nombre = await prompt({ title: 'Renombrar lista', value: l.nombre })
                if (nombre && nombre !== l.nombre) acc.renombrar.mutate({ id: l.id, nombre }, { onError: fallo })
              }}
            />
          )}
          {p.borrar && (
            <IconButton
              label="Eliminar la lista"
              tone="danger"
              icon={<Trash2 />}
              onClick={async () => {
                if (await confirm({ title: '¿Eliminar la lista?', message: 'Los contactos no se borran: solo la lista.', danger: true }))
                  acc.borrar.mutate(l.id, {
                    onSuccess: () => {
                      aviso('Lista eliminada')
                      navigate('/crm/listas', { replace: true })
                    },
                    onError: fallo,
                  })
              }}
            />
          )}
        </div>
      </header>
      {data.miembros.length === 0 ? (
        <p className="px-6 py-10 text-center text-[13.5px] text-muted">Ningún contacto coincide.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full border-collapse max-sm:hidden">
            <thead>
              <tr>
                <th className={TH}>Nombre</th>
                <th className={TH}>Empresa</th>
                <th className={TH}>Sector</th>
                <th className={TH}>Email</th>
                <th className={TH}>Teléfono</th>
                <th className={TH}>Embudo</th>
                <th className={`${TH} w-10`} aria-label="Quitar" />
              </tr>
            </thead>
            <tbody>
              {data.miembros.map((c) => (
                <tr key={c.id} className="hover:bg-hover-row">
                  <td className={TD}>
                    <button type="button" onClick={() => navigate(`/crm/contactos/${c.id}`)} className="flex items-center gap-2.5 text-left font-semibold text-ink-strong hover:underline">
                      <Avatar nombre={c.nombre} size={26} />
                      {c.nombre}
                    </button>
                  </td>
                  <td className={TD}>{c.empresa}</td>
                  <td className={TD}>{c.sector}</td>
                  <td className={`${TD} text-muted`}>{c.email}</td>
                  <td className={`${TD} text-muted`}>{c.telefono}</td>
                  <td className={TD}>
                    <FaseBadge fases={cat?.fases} fase={c.fase} size="sm" />
                  </td>
                  <td className={`${TD} !px-1`}>
                    {c.forzado && p.editar && (
                      <IconButton
                        label={`Quitar a ${c.nombre} de la lista`}
                        tone="danger"
                        size={26}
                        icon={<X />}
                        onClick={async () => {
                          if (await confirm({ title: '¿Quitar este contacto de la lista?', okLabel: 'Quitar' })) acc.miembro.mutate({ id: l.id, contacto: c.id, on: false }, { onError: fallo })
                        }}
                      />
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <ul className="sm:hidden">
            {data.miembros.map((c) => (
              <li key={c.id} className="flex items-center gap-3 border-b border-line2 px-4 py-3 last:border-b-0">
                <button type="button" onClick={() => navigate(`/crm/contactos/${c.id}`)} className="flex min-w-0 flex-1 items-center gap-3 text-left">
                  <Avatar nombre={c.nombre} size={34} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[14.5px] font-semibold text-ink-strong">{c.nombre}</span>
                    <span className="block truncate text-[12.5px] text-muted">{c.empresa}</span>
                  </span>
                </button>
                <FaseBadge fases={cat?.fases} fase={c.fase} size="sm" />
              </li>
            ))}
          </ul>
        </div>
      )}
      <AnadirMiembroModal open={anadir} lista={l} onClose={() => setAnadir(false)} />
    </section>
  )
}
