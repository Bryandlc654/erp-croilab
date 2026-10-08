import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { ArrowLeft, Check, Lock, ShieldAlert } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import Field from '../../../shared/ui/Field'
import FormGrid, { FormZone } from '../../../shared/ui/FormGrid'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import { TextArea, TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { ApiError } from '../../../shared/api/client'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import { useQueryClient } from '@tanstack/react-query'
import { cargarAvanzado, clavesClientes, guardarAvanzado, mensajeError, useAvanzado, useBloquearAvanzado } from '../api'
import { useReauth } from '../../equipo/components/useReauth'
import { BLOQUES_AVANZADOS, type Avanzado, type BloqueAvanzado } from '../schemas'
import { usePermisosClientes } from './permisos'

const TITULOS: Record<BloqueAvanzado, { titulo: string; hint: string }> = {
  estado_json: { titulo: 'Estado del proyecto', hint: 'Objeto { nombre, etiqueta, siguiente, fases: [{ t, s, estado }] }.' },
  plan_json: { titulo: 'Plan contratado', hint: 'Objeto { resumen, items: [{ n, t }], detalle: [{ h, p }] }.' },
  accesos_json: { titulo: 'Accesos', hint: 'Lista [{ b, s, u, tipo }]. Solo enlaces https:// o http://.' },
  tareas_json: { titulo: 'Progreso por mes', hint: 'Objeto { "Junio": { completado: [{ t, d }], pendiente: [{ t, d }] } }.' },
  met_json: { titulo: 'Métricas por mes', hint: 'Lo escribe la sincronización con Google. { "Mayo": { ll, wa, fo, vi, ap, ctr, src, geo } }.' },
  informes_json: { titulo: 'Informes', hint: 'Lista [{ mes, titulo, texto, url }]. Se regenera si el cliente tiene lista de informes.' },
  servicios_json: { titulo: 'Servicios contratados', hint: 'Lista ["SEO", "Diseño web"]. Vacío = ve todos los servicios.' },
}

/* «Datos avanzados» del cliente (data.php): los bloques en bruto, detrás de
   volver a poner la contraseña (zona «datos», la confirmación común de Equipo). */
export default function AvanzadoPage() {
  const id = Number(useParams().id)
  const p = usePermisosClientes()
  const q = useAvanzado(p.avanzado && Number.isInteger(id) ? id : 0)
  const { conReauth, dialogo } = useReauth()

  if (!p.avanzado) return <Notice tone="warn">No tienes permiso para los datos avanzados.</Notice>
  let cuerpo
  if (q.error instanceof ApiError && q.error.codigo === 'reauth') cuerpo = <Desbloqueo id={id} conReauth={conReauth} />
  else if (q.error) cuerpo = <Notice tone="error">{mensajeError(q.error, 'No se han podido cargar los datos.')}</Notice>
  else if (!q.data) cuerpo = <p className="py-[60px] text-center text-[13.5px] text-muted">Cargando…</p>
  else cuerpo = <Editor key={JSON.stringify(q.data.datos)} d={q.data.datos} conReauth={conReauth} />
  return (
    <>
      {cuerpo}
      {dialogo}
    </>
  )
}

type ConReauth = ReturnType<typeof useReauth>['conReauth']

function Desbloqueo({ id, conReauth }: { id: number; conReauth: ConReauth }) {
  const qc = useQueryClient()
  const [cargando, setCargando] = useState(false)
  const [error, setError] = useState('')

  async function entrar() {
    setCargando(true)
    setError('')
    try {
      // conReauth pide la contraseña si la API contesta 403 «reauth» y repite la petición.
      const r = await conReauth(() => cargarAvanzado(id))
      if (r) qc.setQueryData(clavesClientes.avanzado(id), r)
    } catch (e) {
      setError(mensajeError(e, 'No se han podido cargar los datos.'))
    } finally {
      setCargando(false)
    }
  }

  return (
    <div className="mx-auto mt-[min(10vh,90px)] max-w-[400px]">
      <Card>
        <div className="mx-auto mb-4 flex size-[60px] items-center justify-center rounded-[18px] bg-soft text-label [&>svg]:size-7">
          <Lock />
        </div>
        <h1 className="text-center text-[18px] font-semibold text-ink-strong">Datos avanzados</h1>
        <p className="mx-auto mt-1.5 mb-5 max-w-[320px] text-center text-[13.5px] text-muted">Esta zona escribe en la base de datos tal cual. Vuelve a poner tu contraseña para entrar (30 minutos).</p>
        {error && <Notice tone="error">{error}</Notice>}
        <div className="flex flex-col gap-3">
          <Button icon={<Lock />} onClick={() => void entrar()} loading={cargando} loadingText="Comprobando…">
            Desbloquear
          </Button>
          <Button variant="link" to={`/clientes/${id}`} className="self-center">
            Volver a la ficha
          </Button>
        </div>
      </Card>
    </div>
  )
}

type Valores = Record<BloqueAvanzado | 'looker_url' | 'fact_tel' | 'orden', string>

function Editor({ d, conReauth }: { d: Avanzado; conReauth: ConReauth }) {
  const inicial: Valores = {
    ...(Object.fromEntries(BLOQUES_AVANZADOS.map((k) => [k, d[k]])) as Record<BloqueAvanzado, string>),
    looker_url: d.looker_url,
    fact_tel: d.fact_tel,
    orden: String(d.orden),
  }
  const [v, setV] = useState<Valores>(inicial)
  const [error, setError] = useState('')
  const qc = useQueryClient()
  const [guardando, setGuardando] = useState(false)
  const bloquear = useBloquearAvanzado()
  const { aviso } = useToast()
  const cambiados = (Object.keys(v) as (keyof Valores)[]).filter((k) => v[k] !== inicial[k])
  useUnsavedGuard(cambiados.length > 0 && !guardando)

  async function enviar() {
    setError('')
    const cuerpo: Record<string, string | number> = {}
    for (const k of cambiados) cuerpo[k] = k === 'orden' ? Number(v.orden) || 0 : v[k]
    try {
      setGuardando(true)
      // Si la confirmación caducó mientras editaba, se pide otra vez y se guarda.
      const r = await conReauth(() => guardarAvanzado(d.id, cuerpo))
      if (!r) return
      qc.setQueryData(clavesClientes.avanzado(d.id), r)
      void qc.invalidateQueries({ queryKey: clavesClientes.ficha(d.id) })
      void qc.invalidateQueries({ queryKey: clavesClientes.datos(d.id) })
      aviso('Fila guardada.')
    } catch (e) {
      setError(mensajeError(e, 'No se ha podido guardar.'))
    } finally {
      setGuardando(false)
    }
  }

  return (
    <div className="max-w-[980px]">
      <PageHeader
        crumbs={[{ label: `Volver a la ficha de ${d.name}`, to: `/clientes/${d.id}`, icon: <ArrowLeft /> }]}
        title="Datos avanzados"
        lead={`Los bloques que pinta el portal de ${d.name}, en bruto.`}
        actions={
          <Button variant="ghost" size="sm" icon={<Lock />} onClick={() => bloquear.mutate()}>
            Bloquear otra vez
          </Button>
        }
      />
      <Notice tone="error" icon={<ShieldAlert />}>
        <b>Cuidado:</b> esta vista escribe en la base de datos casi tal cual. Para el día a día usa la ficha del cliente, Tipos de cliente y Servicios. Aquí se comprueba que cada
        bloque sea JSON válido y tenga la forma que espera el portal.
      </Notice>
      {error && <Notice tone="error">{error}</Notice>}
      <Card className="mb-5">
        <FormGrid>
          <FormZone title="Campos sueltos" />
          <Field label="Panel de Looker Studio (URL de inserción)" span={12} hint="Se ve en Métricas, debajo de las gráficas. Vacío = sin panel.">
            <TextInput type="url" value={v.looker_url} onChange={(e) => setV((x) => ({ ...x, looker_url: e.target.value }))} placeholder="https://lookerstudio.google.com/embed/…" />
          </Field>
          <Field label="Teléfono de facturación" span={6} hint="Lo rellena el paso de lead a cliente; no está en la ficha.">
            <TextInput value={v.fact_tel} onChange={(e) => setV((x) => ({ ...x, fact_tel: e.target.value }))} maxLength={40} />
          </Field>
          <Field label="Orden" span={6} hint="Posición en la barra lateral de clientes.">
            <TextInput type="number" value={v.orden} onChange={(e) => setV((x) => ({ ...x, orden: e.target.value }))} />
          </Field>
          <FormZone title="Bloques JSON" />
          {BLOQUES_AVANZADOS.map((k) => (
            <Field key={k} label={`${TITULOS[k].titulo} · ${k}`} span={12} hint={TITULOS[k].hint}>
              <TextArea
                value={v[k]}
                onChange={(e) => setV((x) => ({ ...x, [k]: e.target.value }))}
                spellCheck={false}
                rows={Math.min(14, Math.max(3, v[k].split('\n').length))}
                className="font-mono !text-[12.5px]"
              />
            </Field>
          ))}
        </FormGrid>
      </Card>
      <div className="sticky bottom-0 z-20 -mx-4 flex flex-wrap items-center gap-2.5 border-t border-line bg-page/90 px-4 py-3.5 backdrop-blur-[6px] md:-mx-6 md:px-6 lg:-mx-[52px] lg:px-[52px]">
        <Button icon={<Check />} onClick={() => void enviar()} loading={guardando} loadingText="Guardando…" disabled={cambiados.length === 0}>
          Guardar fila
        </Button>
        {cambiados.length > 0 && <span className="text-[12.5px] text-muted">Cambiado: {cambiados.join(', ')}</span>}
      </div>
    </div>
  )
}
