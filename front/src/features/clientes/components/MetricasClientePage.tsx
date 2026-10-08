import { useState, type ReactNode } from 'react'
import { useParams } from 'react-router-dom'
import { ArrowLeft, Check, HelpCircle, Plus, RefreshCw, Search, UserRound } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Card, { CardHeader } from '../../../shared/ui/Card'
import Chip from '../../../shared/ui/Chip'
import EmptyState from '../../../shared/ui/EmptyState'
import Field from '../../../shared/ui/Field'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { ApiError } from '../../../shared/api/client'
import { fechaCorta, numero } from '../../../shared/lib/formato'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import { mensajeError, pedirEventosGa4, useGoogleCliente, useGuardarGoogle, useSincronizarGoogle } from '../api'
import { OBJETIVOS_GOOGLE } from '../lib/etiquetas'
import { OBJETIVOS, type Google, type Objetivo } from '../schemas'
import { usePermisosClientes } from './permisos'

type Eventos = Record<Objetivo, string[]>

/* Métricas de Google de un cliente (conversiones.php): su web en Search
   Console, su Analytics y qué eventos cuentan como llamada, WhatsApp o
   formulario. La conexión con Google se hace una vez en Integraciones. */
export default function MetricasClientePage() {
  const id = Number(useParams().id)
  const { data, error } = useGoogleCliente(Number.isInteger(id) ? id : 0)
  if (!Number.isInteger(id) || id <= 0 || (error instanceof ApiError && error.status === 404)) {
    return (
      <EmptyState icon={<UserRound />} title="No encuentro ese cliente" text="El enlace no lleva a ningún cliente válido." actions={<Button to="/clientes">Ver mis clientes</Button>} />
    )
  }
  if (error) return <Notice tone="error">{mensajeError(error, 'No se han podido cargar sus métricas.')}</Notice>
  if (!data) return <p className="py-[60px] text-center text-[13.5px] text-muted">Cargando…</p>
  return <Formulario key={JSON.stringify([data.site, data.prop, data.eventos])} g={data} />
}

function Ayuda({ texto }: { texto: string }) {
  return (
    <span title={texto} aria-label={texto} className="inline-flex cursor-help text-label hover:text-ink [&>svg]:size-3.5">
      <HelpCircle />
    </span>
  )
}

function Formulario({ g }: { g: Google }) {
  const p = usePermisosClientes()
  const { aviso } = useToast()
  const guardar = useGuardarGoogle(g.cliente.id)
  const sync = useSincronizarGoogle(g.cliente.id)
  const [site, setSite] = useState(g.site)
  const [prop, setProp] = useState(g.prop)
  const [ev, setEv] = useState<Eventos>(g.eventos)
  const [lista, setLista] = useState<{ name: string; n: number }[] | null>(null)
  const [cargandoLista, setCargandoLista] = useState(false)
  const [errorLista, setErrorLista] = useState('')
  const [manual, setManual] = useState<Record<Objetivo, string>>({ ll: '', wa: '', fo: '' })
  const dirty = site !== g.site || prop !== g.prop || JSON.stringify(ev) !== JSON.stringify(g.eventos)
  useUnsavedGuard(dirty && !guardar.isPending)

  /* Un evento solo cuenta para un tipo: asignarlo lo quita de los otros; volver a pulsarlo lo desasigna. */
  function asignar(nombre: string, k: Objetivo) {
    setEv((x) => {
      const ya = x[k].includes(nombre)
      const sin = Object.fromEntries(OBJETIVOS.map((o) => [o, x[o].filter((e) => e !== nombre)])) as Eventos
      return ya ? sin : { ...sin, [k]: [...sin[k], nombre] }
    })
  }

  async function verEventos() {
    setCargandoLista(true)
    setErrorLista('')
    try {
      setLista(await pedirEventosGa4(g.cliente.id, prop.trim()))
    } catch (e) {
      setLista(null)
      setErrorLista(mensajeError(e, 'No se han podido leer los eventos.'))
    } finally {
      setCargandoLista(false)
    }
  }

  async function enviar() {
    try {
      await guardar.mutateAsync({ site: site.trim(), prop: prop.trim(), eventos: ev })
      aviso('Cambios guardados.')
    } catch (e) {
      aviso(mensajeError(e, 'No se han podido guardar los cambios.'), { tipo: 'error' })
    }
  }

  async function traer() {
    try {
      await sync.mutateAsync()
      aviso('Actualizado.')
    } catch (e) {
      aviso(mensajeError(e, 'No se han podido traer los datos.'), { tipo: 'error' })
    }
  }

  const nombre = g.cliente.name
  return (
    <div className="max-w-[980px]">
      <PageHeader
        crumbs={[{ label: `Volver a la ficha de ${nombre}`, to: `/clientes/${g.cliente.id}`, icon: <ArrowLeft /> }]}
        title={`Métricas de ${nombre}`}
        lead={
          <>
            Todo lo de Google de este cliente en un solo sitio. Rellena lo que tengas y pulsa <b className="text-ink-strong">Guardar cambios</b>. Cada apartado tiene un{' '}
            <b className="text-ink-strong">?</b> con la explicación.
          </>
        }
      />
      {!g.conectado && (
        <Notice tone="warn">
          Todavía no está conectado Google en el ERP. Puedes dejar esto preparado, pero para ver la lista de eventos y traer datos hay que conectarlo una vez en Ajustes ›
          Integraciones.
        </Notice>
      )}
      {!g.cliente.conversiones && <Notice tone="info">Este cliente tiene apagado «Tiene conversiones»: no verá Métricas en su portal si no tiene un tipo que las enseñe.</Notice>}

      <fieldset disabled={!p.editar} className="min-w-0">
        <div className="mb-5 grid grid-cols-2 gap-5 max-[760px]:grid-cols-1">
          <Card padding="md">
            <Field
              label={
                <span className="inline-flex items-center gap-1.5">
                  Su web en Google <Ayuda texto="La propiedad de Search Console de su web: de aquí salen las visitas y las apariciones en Google." />
                </span>
              }
              hint="Ponla igual que aparece en Search Console (a veces es sc-domain:sucliente.com)."
            >
              <TextInput value={site} onChange={(e) => setSite(e.target.value)} placeholder="https://sucliente.com/" maxLength={255} />
            </Field>
          </Card>
          <Card padding="md">
            <Field
              label={
                <span className="inline-flex items-center gap-1.5">
                  Número de Analytics (GA4) <Ayuda texto="El número de la propiedad de GA4 (Administrar › Detalles de la propiedad). De aquí salen las llamadas, WhatsApps y formularios." />
                </span>
              }
              hint="Solo números."
            >
              <TextInput value={prop} onChange={(e) => setProp(e.target.value.replace(/\D/g, ''))} placeholder="ej: 313888031" maxLength={40} inputMode="numeric" />
            </Field>
          </Card>
        </div>

        <Card className="mb-5">
          <CardHeader
            title="¿Qué cuenta como cada contacto?"
            subtitle="Elige qué eventos de Analytics son una llamada, un WhatsApp o un formulario. Si no eliges ninguno, se usan los de por defecto."
          />
          <div className="flex flex-col">
            {OBJETIVOS.map((k) => {
              const o = OBJETIVOS_GOOGLE[k]
              return (
                <div key={k} className="flex flex-wrap items-center gap-3 border-t border-line2 py-3.5 first:border-t-0">
                  <div className="w-[190px] shrink-0 max-sm:w-full">
                    <b className="block text-[13.5px] font-semibold text-ink-strong">
                      {o.emoji} {o.titulo}
                    </b>
                    <span className="text-[12px] text-muted">{o.texto}</span>
                  </div>
                  <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5 max-sm:basis-full">
                    {ev[k].length === 0 && <span className="text-[12.5px] text-muted">Sin asignar (por defecto: {g.por_defecto[k]})</span>}
                    {ev[k].map((e) => (
                      <Chip key={e} variant="data" onRemove={p.editar ? () => asignar(e, k) : undefined} removeLabel="Quitar evento">
                        {e}
                      </Chip>
                    ))}
                  </div>
                  {p.editar && (
                    <form
                      className="flex items-center gap-1.5"
                      onSubmit={(e) => {
                        e.preventDefault()
                        const v = manual[k].trim()
                        if (v && !ev[k].includes(v)) asignar(v, k)
                        setManual((m) => ({ ...m, [k]: '' }))
                      }}
                    >
                      <TextInput
                        size="sm"
                        value={manual[k]}
                        onChange={(e) => setManual((m) => ({ ...m, [k]: e.target.value.replace(/[^A-Za-z0-9_]/g, '') }))}
                        placeholder="evento_a_mano"
                        aria-label={`Añadir evento a ${o.titulo}`}
                        className="w-[150px]"
                        maxLength={40}
                      />
                      <Button type="submit" variant="ghost" size="sm" icon={<Plus />} aria-label={`Añadir a ${o.titulo}`} />
                    </form>
                  )}
                </div>
              )
            })}
          </div>
          <div className="mt-4 border-t border-line2 pt-4">
            <Button variant="ghost" size="sm" icon={<Search />} onClick={() => void verEventos()} loading={cargandoLista} loadingText="Buscando…" disabled={!g.conectado}>
              Ver mis eventos de Analytics
            </Button>
            {errorLista && <Notice tone="error" className="mt-3 !mb-0">{errorLista}</Notice>}
            {lista && lista.length === 0 && <p className="mt-3 text-[13px] text-muted">No se han encontrado eventos en los últimos 90 días. Comprueba el número de Analytics.</p>}
            {lista && lista.length > 0 && (
              <div className="mt-3 flex flex-col">
                {lista.map((e) => (
                  <div key={e.name} className="flex items-center gap-3 border-t border-line2 py-2 first:border-t-0">
                    <span className="min-w-0 flex-1 truncate text-[13px] text-ink">
                      {e.name} <b className="text-ink-strong">{numero(e.n)} veces</b>
                    </span>
                    {OBJETIVOS.map((k) => {
                      const on = ev[k].includes(e.name)
                      return (
                        <button
                          key={k}
                          type="button"
                          onClick={() => asignar(e.name, k)}
                          aria-pressed={on}
                          title={`Contar como ${OBJETIVOS_GOOGLE[k].titulo}`}
                          className={`flex size-8 items-center justify-center rounded-[9px] border text-[15px] transition-colors ${
                            on ? 'border-tab-on bg-tab-on dark:border-rev dark:bg-rev' : 'border-line bg-field hover:bg-soft'
                          }`}
                        >
                          {OBJETIVOS_GOOGLE[k].emoji}
                        </button>
                      )
                    })}
                  </div>
                ))}
              </div>
            )}
          </div>
        </Card>
      </fieldset>

      {p.editar && (
        <div className="mb-8 flex flex-wrap items-center gap-2.5">
          <Button icon={<Check />} onClick={() => void enviar()} loading={guardar.isPending} loadingText="Guardando…" disabled={!dirty}>
            Guardar cambios
          </Button>
          {g.conectado && (
            <Button variant="ghost" icon={<RefreshCw />} onClick={() => void traer()} loading={sync.isPending} loadingText="Trayendo de Google…" disabled={dirty}>
              Traer datos ahora
            </Button>
          )}
        </div>
      )}

      <DatosTraidos g={g} />
    </div>
  )
}

function Celda({ children, fuerte = false }: { children: ReactNode; fuerte?: boolean }) {
  return <td className={`border-b border-line2 px-3 py-2.5 text-right tabular-nums ${fuerte ? 'font-semibold text-ink-strong' : 'text-ink'}`}>{children}</td>
}

/* Lo que ya hay guardado de Google, mes a mes (met_json). */
function DatosTraidos({ g }: { g: Google }) {
  return (
    <Card padding="none" className="overflow-hidden">
      <div className="px-6 pt-5 pb-3 max-sm:px-4">
        <CardHeader title="Lo que ve en su portal" subtitle={g.sync_at ? `Última actualización: ${fechaCorta(g.sync_at)}` : 'Aún no se ha traído nada de Google.'} className="!mb-0" />
      </div>
      {g.meses.length === 0 ? (
        <p className="px-6 pb-6 text-[13px] text-muted max-sm:px-4">Sin datos todavía.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[620px] border-collapse text-[13px]">
            <thead>
              <tr className="text-[11px] font-[650] tracking-[.5px] text-muted uppercase">
                {['Mes', 'Oportunidades', 'Llamadas', 'WhatsApp', 'Formularios', 'Visitas', 'Apariciones', 'CTR'].map((h, i) => (
                  <th key={h} className={`border-y border-line bg-head px-3 py-2.5 ${i === 0 ? 'pl-6 text-left' : 'text-right'}`}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {g.meses.map((m) => (
                <tr key={m.mes}>
                  <td className="border-b border-line2 py-2.5 pr-3 pl-6 font-semibold text-ink-strong">{m.mes}</td>
                  <Celda fuerte>{numero(m.total)}</Celda>
                  <Celda>{numero(m.ll)}</Celda>
                  <Celda>{numero(m.wa)}</Celda>
                  <Celda>{numero(m.fo)}</Celda>
                  <Celda>{numero(m.vi)}</Celda>
                  <Celda>{numero(m.ap)}</Celda>
                  <Celda>{numero(m.ctr, 2)}%</Celda>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}
