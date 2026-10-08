import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, Check, ChevronDown, Copy, Mail, Plus, RefreshCw } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import Collapse from '../../../shared/ui/Collapse'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import { useToast } from '../../../shared/ui/useToast'
import { fechaCorta, horaRelativa } from '../../../shared/lib/formato'
import { mensajeError, useAccionSeguimientos, useSeguimientos } from '../api'
import { usePermisosCrm } from '../permisos'
import type { Bandeja, Seguimiento } from '../schemas'
import ResumenModal from './ResumenModal'
import SeguimientoManualModal from './SeguimientoManualModal'

const NOMBRES_TAREA: Record<string, string> = {
  followups: 'Seguimientos del CRM',
  daily_digest: 'Resumen diario',
  lead_reminder: 'Recordar leads',
}
const ddmm = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`
const CHIP = 'inline-flex items-center gap-1.5 rounded-xl border border-line bg-card px-3.5 py-2 text-[13px] text-muted [&_b]:text-[15px] [&_b]:font-bold [&_b]:text-ink-strong'

/* Reporting (automatizaciones.php): la bandeja de seguimientos de hoy por
   canal, los próximos, el resumen diario y el estado del cron. */
export default function ReportingPage() {
  const { data, error, isPending } = useSeguimientos()
  const acc = useAccionSeguimientos()
  const p = usePermisosCrm()
  const { aviso } = useToast()
  const [manual, setManual] = useState(false)
  const [resumen, setResumen] = useState(false)
  const fallo = (e: unknown) => aviso(mensajeError(e), { tipo: 'error' })

  return (
    <div>
      <PageHeader
        title="Reporting"
        lead="Los seguimientos del CRM se preparan solos desde leads nuevos, propuestas enviadas y negocios a reactivar."
        actions={
          <>
            <Button variant="ghost" icon={<Mail />} onClick={() => setResumen(true)}>
              Ver email diario
            </Button>
            {p.crear && (
              <Button variant="ghost" icon={<Plus />} onClick={() => setManual(true)}>
                Seguimiento manual
              </Button>
            )}
            {p.editar && (
              <>
                <Button
                  variant="ghost"
                  icon={<RefreshCw />}
                  loading={acc.generar.isPending}
                  loadingText="Generando…"
                  onClick={() => acc.generar.mutate(undefined, { onSuccess: (r) => aviso(r.creados ? `${r.creados} seguimiento(s) nuevos` : 'No había nada nuevo que generar'), onError: fallo })}
                >
                  Generar
                </Button>
                <Button
                  icon={<ArrowRight />}
                  loading={acc.resumen.isPending}
                  loadingText="Ejecutando…"
                  onClick={() =>
                    acc.resumen.mutate(undefined, {
                      onSuccess: (r) =>
                        aviso(
                          r.resultado.reason === 'ok'
                            ? `Resumen ejecutado: ${r.resultado.acciones} acción(es)${r.resultado.correos ? ` · ${r.resultado.correos} correo(s)` : ''}`
                            : r.resultado.reason === 'nada_hoy'
                              ? 'Resumen ejecutado: nada pendiente hoy'
                              : 'Resumen ejecutado',
                        ),
                      onError: fallo,
                    })
                  }
                >
                  Ejecutar resumen
                </Button>
              </>
            )}
          </>
        }
      />
      {error && <Notice tone="error">{mensajeError(error, 'No se han podido cargar los seguimientos.')}</Notice>}
      {isPending && <div className="py-[60px] text-center text-[13px] text-muted">Cargando…</div>}
      {data && <Contenido data={data} />}
      <SeguimientoManualModal open={manual} onClose={() => setManual(false)} />
      <ResumenModal open={resumen} onClose={() => setResumen(false)} />
    </div>
  )
}

function Contenido({ data }: { data: Bandeja }) {
  const acc = useAccionSeguimientos()
  const p = usePermisosCrm()
  const { aviso } = useToast()
  const [saliendo, setSaliendo] = useState<Set<number>>(new Set())
  const [detalles, setDetalles] = useState(false)
  const grupos = data.grupos.filter((g) => g.canal !== 'reunion' || g.items.length > 0)
  const canal = new Map(data.grupos.map((g) => [g.canal, g]))

  function hacer(s: Seguimiento, que: 'hecho' | 'posponer' | 'omitir', dias?: number) {
    setSaliendo((x) => new Set(x).add(s.id))
    acc.accion.mutate(
      { id: s.id, que, dias },
      {
        onSuccess: () => aviso(que === 'hecho' ? 'Seguimiento hecho' : que === 'omitir' ? 'Seguimiento omitido' : `Pospuesto ${dias === 7 ? 'una semana' : 'un día'}`),
        onError: (e) => {
          setSaliendo((x) => {
            const n = new Set(x)
            n.delete(s.id)
            return n
          })
          aviso(mensajeError(e), { tipo: 'error' })
        },
      },
    )
  }

  const cron = data.cron
  const estado = { funcionando: ['Funcionando', '#12a150'], parado: ['Parado', '#e5484d'], sin_configurar: ['Sin configurar', '#e5484d'] }[cron.estado]
  const BTN = 'rounded-lg border border-line bg-field px-2.5 py-1 text-[12px] font-semibold text-ink transition-colors hover:bg-soft'

  return (
    <>
      <div className="mb-5 flex flex-wrap gap-2.5">
        <span className={CHIP}>
          <b>{data.pendientes_hoy}</b> pendiente{data.pendientes_hoy === 1 ? '' : 's'} hoy
        </span>
        <span className={CHIP}>
          <b>{data.hechos_hoy}</b> hecho{data.hechos_hoy === 1 ? '' : 's'} hoy
        </span>
        <span className={`${CHIP} font-semibold !text-ink-strong`}>{`${ddmm(data.hoy)}/${data.hoy.slice(0, 4)}`}</span>
        {data.ultimo_resumen && (
          <span className={CHIP}>
            Último resumen: {ddmm(data.ultimo_resumen.fecha.slice(0, 10))} {data.ultimo_resumen.fecha.slice(11, 16)} · {data.ultimo_resumen.n_acciones} acciones
          </span>
        )}
      </div>

      {data.pendientes_hoy === 0 ? (
        <Card className="mb-5 text-center">
          <p className="text-[17px] font-[650] text-[#12a150] dark:text-ok">✓ No hay seguimientos pendientes hoy</p>
          <p className="mt-2 text-[13px] text-muted">Las tareas se generan solas desde leads nuevos, propuestas enviadas y negocios a reactivar.</p>
        </Card>
      ) : (
        <div className="mb-5 grid gap-4" style={{ gridTemplateColumns: `repeat(auto-fit, minmax(240px, 1fr))` }}>
          {grupos.map((g) => (
            <section key={g.canal} className="rounded-2xl border border-line bg-card p-4" aria-label={g.titulo}>
              <h3 className="mb-3 flex items-center gap-2 text-[12px] font-extrabold tracking-[.5px]" style={{ color: g.color }}>
                <span className="size-2 rounded-full" style={{ backgroundColor: g.color }} />
                {g.titulo}
                <span className="font-bold text-label">{g.items.length}</span>
              </h3>
              {g.items.length === 0 && <p className="text-[12.5px] text-muted">Nada por aquí hoy.</p>}
              <ul className="flex flex-col gap-2">
                {g.items.map((s) => (
                  <li key={s.id} className={`rounded-xl border border-line px-3 py-2.5 ${saliendo.has(s.id) ? 'pointer-events-none motion-safe:animate-erp-out' : ''}`}>
                    <Link to={`/crm/contactos/${s.contact_id}`} className="text-[13.5px] font-[650] text-ink-strong hover:underline">
                      {s.contacto.nombre}
                    </Link>
                    <div className="text-[12px] text-muted">
                      {s.contacto.empresa}
                      {s.vencida && <span className="ml-1.5 font-semibold text-[#e5484d]">vencía {ddmm(s.fecha_prevista)}</span>}
                    </div>
                    <p className="mt-1 text-[13px] leading-snug text-ink">{s.descripcion}</p>
                    {p.editar && (
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        <button type="button" className={`${BTN} !border-[#cfe9d6] !text-[#12854a] dark:!border-ok-line dark:!text-ok`} onClick={() => hacer(s, 'hecho')}>
                          <Check className="mr-0.5 inline size-3.5" />
                          Hecho
                        </button>
                        <button type="button" className={BTN} onClick={() => hacer(s, 'posponer', 1)}>
                          +1 día
                        </button>
                        <button type="button" className={BTN} onClick={() => hacer(s, 'posponer', 7)}>
                          +1 sem
                        </button>
                        <button type="button" className={`${BTN} text-muted`} onClick={() => hacer(s, 'omitir')}>
                          Omitir
                        </button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}

      <div className="mb-5 grid grid-cols-2 gap-4 max-[900px]:grid-cols-1">
        <Card>
          <h3 className="mb-3 text-[16px] font-semibold text-ink-strong">Próximos seguimientos</h3>
          {data.proximos.length === 0 && <p className="text-[13px] text-muted">Nada programado más adelante.</p>}
          <ul className="flex flex-col">
            {data.proximos.map((s) => (
              <li key={s.id} className="flex items-center gap-2.5 border-t border-line2 py-2 text-[13px] first:border-t-0">
                <span className="size-2 shrink-0 rounded-full" style={{ backgroundColor: canal.get(s.canal)?.color ?? '#94a3b8' }} />
                <Link to={`/crm/contactos/${s.contact_id}`} className="min-w-0 flex-1 truncate font-semibold text-ink-strong hover:underline">
                  {s.contacto.nombre}
                </Link>
                <span className="truncate text-[12px] text-muted max-sm:hidden">{s.descripcion}</span>
                <span className="shrink-0 text-[12px] font-semibold text-muted tabular-nums">{ddmm(s.fecha_prevista)}</span>
              </li>
            ))}
          </ul>
        </Card>
        <Card>
          <h3 className="mb-3 text-[16px] font-semibold text-ink-strong">Resumen diario por email</h3>
          <p className="text-[13px] leading-relaxed text-ink">
            Cada día laborable (L–V) se prepara un <b>resumen de seguimientos</b> agrupado por canal: LLAMAR HOY, ESCRIBIR WHATSAPP, ENVIAR EMAIL y REUNIONES.
          </p>
          <p className="mt-3 text-[13px] leading-relaxed text-ink">
            Llega como aviso del ERP y por correo a quien tiene acceso total (si tiene email en su cuenta). Lo lanza el cron de abajo, junto con el resto de automatizaciones; <b>«Ejecutar resumen»</b> hace lo mismo a mano, sin esperar a mañana.
          </p>
        </Card>
      </div>

      <Card padding="md">
        <div className="flex flex-wrap items-center gap-3">
          <h3 className="text-[16px] font-semibold text-ink-strong">Ejecución automática (cron)</h3>
          <span className="rounded-full px-2.5 py-0.5 text-[11.5px] font-bold" style={{ backgroundColor: `${estado[1]}18`, color: estado[1] }}>
            {estado[0]}
          </span>
          <span className="text-[13px] text-muted">{cron.ultima ? `Última ejecución ${horaRelativa(cron.ultima)} · ${fechaCorta(cron.ultima)} ${cron.ultima.slice(11, 16)}` : 'Todavía no se ha ejecutado ni una vez.'}</span>
          <Button variant="ghost" size="sm" className="ml-auto" onClick={() => setDetalles((v) => !v)} aria-expanded={detalles}>
            Ver detalles <ChevronDown className={`transition-transform ${detalles ? 'rotate-180' : ''}`} />
          </Button>
        </div>
        <Collapse open={detalles}>
          <div className="mt-4 border-t border-line2 pt-4">
            {cron.tareas.length > 0 && (
              <table className="mb-4 w-full text-[13px]">
                <tbody>
                  {cron.tareas.map((t) => (
                    <tr key={t.tarea} className="border-b border-line2 last:border-b-0">
                      <td className="py-2 pr-3">
                        <span className={`mr-2 inline-block size-2 rounded-full ${t.ok ? 'bg-[#12a150]' : 'bg-[#e5484d]'}`} />
                        {NOMBRES_TAREA[t.tarea] ?? t.tarea}
                      </td>
                      <td className="py-2 pr-3 text-muted">{t.detalle}</td>
                      <td className="py-2 text-right whitespace-nowrap text-muted">{horaRelativa(t.fecha)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            <p className="mb-2 text-[12.5px] text-muted">Línea para el crontab del servidor (cada 15 minutos):</p>
            <div className="flex items-center gap-2">
              <code className="min-w-0 flex-1 overflow-x-auto rounded-lg bg-soft px-3 py-2 font-mono text-[12px] whitespace-nowrap text-ink">{cron.linea}</code>
              <Button
                variant="ghost"
                size="sm"
                icon={<Copy />}
                onClick={() =>
                  void navigator.clipboard
                    ?.writeText(cron.linea)
                    .then(() => aviso('Copiado'))
                    .catch(() => aviso('No se ha podido copiar', { tipo: 'error' }))
                }
              >
                Copiar
              </Button>
            </div>
            <p className="mt-3 text-[12.5px] text-muted">Cada tarea se puede encender o apagar en Ajustes › Reglas automáticas.</p>
          </div>
        </Collapse>
      </Card>
    </>
  )
}
