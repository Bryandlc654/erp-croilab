import { useState } from 'react'
import { Link } from 'react-router-dom'
import { RefreshCw, Settings, Zap } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import Collapse from '../../../shared/ui/Collapse'
import Field from '../../../shared/ui/Field'
import FormGrid from '../../../shared/ui/FormGrid'
import Notice from '../../../shared/ui/Notice'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import AjustesCabecera from '../components/AjustesCabecera'
import { ErrorCarga, Esqueleto, LogoServicio, SetCard } from '../components/piezas'
import { campoDeError, clavesEquipo, mensaje, pedir, useAccion, useMetricas } from '../api'
import { fechaCorta } from '../logica'
import { MetricasRespuesta, SyncRespuesta, type MetricasAjustes } from '../schemas'

/* Ajustes › Portal › Métricas de Google (metricas.php): qué clientes tienen
   web y Analytics, «Actualizar ahora» y los eventos por defecto. La web y la
   propiedad de cada cliente se ponen en su ficha (módulo Clientes). */
export default function PortalMetricasPage() {
  const q = useMetricas()
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const todos = useAccion(() => pedir('/api/v1/ajustes/metricas/sync', { method: 'POST', schema: SyncRespuesta }), [clavesEquipo.metricas])
  const d = q.data

  return (
    <div className="max-w-[1180px]">
      <AjustesCabecera
        icono={<LogoServicio k="google" size={24} />}
        titulo="Métricas de Google"
        sub={
          <>
            El ERP trae de Google las <b className="text-ink">visitas y apariciones</b> (Search Console) y las <b className="text-ink">conversiones</b> (Analytics) de cada cliente y las muestra en su portal. Se actualiza <b className="text-ink">solo, una vez al día</b>.
          </>
        }
        accion={
          d?.conectado && d.puede_editar ? (
            <div className="flex items-center gap-2.5">
              {d.ultima && <span className="text-[12px] text-muted">Última: {fechaCorta(d.ultima)}</span>}
              <Button
                icon={<Zap />}
                loading={todos.isPending}
                loadingText="Trayendo datos…"
                onClick={async () => {
                  if (!(await confirm({ title: 'Actualizar ahora', message: 'El ERP va a leer Google de todos tus clientes. Puede tardar un poco. ¿Seguimos?', okLabel: 'Sí, traer datos' }))) return
                  todos.mutate(undefined, { onSuccess: (r) => aviso(r.msg), onError: (e) => aviso(mensaje(e), { tipo: 'error' }) })
                }}
              >
                Actualizar ahora
              </Button>
            </div>
          ) : undefined
        }
      />
      {q.isPending ? <Esqueleto /> : q.isError ? <ErrorCarga error={q.error} /> : <Contenido d={q.data} />}
    </div>
  )
}

function Contenido({ d }: { d: MetricasAjustes }) {
  const { aviso } = useToast()
  const uno = useAccion((id: number) => pedir(`/api/v1/ajustes/metricas/sync/${id}`, { method: 'POST', schema: SyncRespuesta }), [clavesEquipo.metricas])
  const [enCurso, setEnCurso] = useState<number | null>(null)
  return (
    <>
      {!d.conectado && (
        <Notice tone="warn" className="mb-5" action={<Button size="sm" variant="ghost" to="/ajustes/integraciones?i=metricas">Ir a Integraciones</Button>}>
          {d.revocado ? 'Google ha retirado el permiso: hay que volver a conectarlo en Integraciones.' : 'Todavía no está conectado con Google. Conéctalo en Integraciones (se hace una sola vez) y después vuelve aquí.'}
        </Notice>
      )}
      <SetCard titulo="Tus clientes" sub={`Con la web puesta (${d.con_web} de ${d.clientes.length}) se traen visitas y apariciones; con el número de Analytics, las conversiones.`}>
        {d.clientes.length === 0 ? (
          <p className="text-[13px] text-muted">No hay clientes activos que puedas ver.</p>
        ) : (
          <div className="grid grid-cols-2 gap-3 max-[860px]:grid-cols-1">
            {d.clientes.map((c) => (
              <Card key={c.id} padding="sm" className="flex items-center gap-3">
                <span className={`size-2 shrink-0 rounded-full ${c.web ? 'bg-[#12a150]' : 'bg-[#c0c4cb]'}`} aria-hidden="true" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[13.5px] font-semibold text-ink-strong">{c.nombre}</p>
                  <p className="mt-1 flex flex-wrap items-center gap-1 text-[11px]">
                    {c.web && <Tag>Web</Tag>}
                    {c.analytics && <Tag>Analytics</Tag>}
                    {c.conversiones && <Tag>Conversiones</Tag>}
                    {!c.web && !c.analytics && <span className="text-muted">Sin configurar</span>}
                    {c.sync && <span className="text-muted">· {fechaCorta(c.sync)}</span>}
                  </p>
                </div>
                {d.conectado && d.puede_editar && (c.web || c.analytics) && (
                  <Button
                    size="sm"
                    variant="ghost"
                    icon={<RefreshCw />}
                    aria-label={`Actualizar ${c.nombre}`}
                    loading={enCurso === c.id}
                    loadingText="…"
                    onClick={() => {
                      setEnCurso(c.id)
                      uno.mutate(c.id, { onSuccess: (r) => aviso(r.msg), onError: (e) => aviso(mensaje(e), { tipo: 'error' }), onSettled: () => setEnCurso(null) })
                    }}
                  />
                )}
                <Button size="sm" variant="ghost" icon={<Settings />} to={`/clientes/${c.id}/metricas`}>
                  Configurar
                </Button>
              </Card>
            ))}
          </div>
        )}
      </SetCard>
      <Avanzadas d={d} />
      <p className="text-[12px] text-label">
        Actualización automática (para el técnico): la hace el cron diario del servidor.{' '}
        <Link to="/ajustes/integraciones?i=metricas" className="underline-offset-2 hover:underline">
          Conexión con Google
        </Link>
      </p>
    </>
  )
}

function Tag({ children }: { children: string }) {
  return <span className="rounded-md bg-soft px-1.5 py-px font-semibold text-muted">{children}</span>
}

function Avanzadas({ d }: { d: MetricasAjustes }) {
  const [abierto, setAbierto] = useState(false)
  const [f, setF] = useState(d.eventos)
  const [err, setErr] = useState<{ campo: string | null; msg: string } | null>(null)
  const { aviso } = useToast()
  const guardar = useAccion((b: typeof f) => pedir('/api/v1/ajustes/metricas', { method: 'PATCH', body: b, schema: MetricasRespuesta }), [clavesEquipo.metricas])
  const e = (k: string) => (err?.campo === k ? err.msg : undefined)
  return (
    <SetCard>
      <button type="button" className="flex w-full items-center justify-between text-left text-[13.5px] font-semibold text-ink-strong" aria-expanded={abierto} onClick={() => setAbierto((a) => !a)}>
        Opciones avanzadas (no hace falta tocar)
        <span className="text-[12px] font-medium text-muted">{abierto ? 'Ocultar' : 'Ver'}</span>
      </button>
      <Collapse open={abierto}>
        <form
          className="pt-4"
          onSubmit={(ev) => {
            ev.preventDefault()
            setErr(null)
            guardar.mutate(f, { onSuccess: () => aviso('Opciones avanzadas guardadas.'), onError: (er) => setErr({ campo: campoDeError(er), msg: mensaje(er) }) })
          }}
        >
          <p className="mb-4 text-[12.5px] text-muted">Eventos de Analytics que cuentan como cada contacto cuando el cliente no tiene los suyos. Varios, separados por comas.</p>
          <fieldset disabled={!d.puede_ajustar}>
            <FormGrid>
              <Field label="📞 Llamadas" span={4} error={e('ll')}>
                <TextInput value={f.ll} onChange={(x) => setF({ ...f, ll: x.target.value })} />
              </Field>
              <Field label="💬 WhatsApp" span={4} error={e('wa')}>
                <TextInput value={f.wa} onChange={(x) => setF({ ...f, wa: x.target.value })} />
              </Field>
              <Field label="📝 Formularios" span={4} error={e('fo')}>
                <TextInput value={f.fo} onChange={(x) => setF({ ...f, fo: x.target.value })} />
              </Field>
            </FormGrid>
          </fieldset>
          {d.puede_ajustar && (
            <Button className="mt-4" type="submit" variant="ghost" loading={guardar.isPending} loadingText="Guardando…">
              Guardar opciones
            </Button>
          )}
        </form>
      </Collapse>
    </SetCard>
  )
}
