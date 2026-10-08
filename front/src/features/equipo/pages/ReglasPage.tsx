import { useState, type ReactNode } from 'react'
import { BellRing, CalendarClock, FileText, Mail, Play, Repeat, Sparkles, Trash2, Users } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import Switch from '../../../shared/ui/Switch'
import { useToast } from '../../../shared/ui/useToast'
import AjustesCabecera from '../components/AjustesCabecera'
import { ErrorCarga, Esqueleto, SetCard } from '../components/piezas'
import { clavesEquipo, mensaje, pedir, useAccion, useReglas } from '../api'
import { MsgSchema, ReglasRespuesta } from '../schemas'

const ICONO: Record<string, ReactNode> = {
  lead_reminder: <Users />,
  invoice_due: <BellRing />,
  monthly_report: <FileText />,
  invoice_recurring: <Repeat />,
  followups: <Mail />,
  daily_digest: <CalendarClock />,
  trash_purge: <Trash2 />,
  uploads_sweep: <Sparkles />,
}

/* Ajustes › Reglas automáticas (settings.php?tab=reglas): lo que el ERP hace
   solo (el cron), cada regla con su interruptor. */
export default function ReglasPage() {
  const q = useReglas()
  const { aviso } = useToast()
  const [guardando, setGuardando] = useState<string | null>(null)
  const regla = useAccion((b: { clave: string; on: boolean }) => pedir('/api/v1/ajustes/automatizaciones', { method: 'PATCH', body: b, schema: ReglasRespuesta }), [clavesEquipo.reglas])
  const ejecutar = useAccion(() => pedir('/api/v1/ajustes/automatizaciones/ejecutar', { method: 'POST', schema: MsgSchema }), [])

  return (
    <div className="max-w-[1180px]">
      <AjustesCabecera titulo="Reglas automáticas" sub="Lo que el ERP hace solo mientras trabajas: avisos, facturas recurrentes y seguimientos." />
      {q.isPending ? (
        <Esqueleto />
      ) : q.isError ? (
        <ErrorCarga error={q.error} />
      ) : (
        <>
          <SetCard
            titulo="Reglas"
            sub="Cada regla se puede apagar. El servidor las ejecuta solo cada día; con «Ejecutar ahora» lanzas ya los avisos de leads, facturas e informe mensual."
            accion={
              q.data.puede_editar && (
                <Button variant="ghost" icon={<Play />} loading={ejecutar.isPending} loadingText="Ejecutando…" onClick={() => ejecutar.mutate(undefined, { onSuccess: (r) => aviso(r.msg), onError: (e) => aviso(mensaje(e), { tipo: 'error' }) })}>
                  Ejecutar ahora
                </Button>
              )
            }
          >
            <div className="grid grid-cols-2 gap-3 max-[860px]:grid-cols-1">
              {q.data.reglas.map((r) => (
                <Card key={r.clave} padding="sm" className="flex items-center gap-3.5">
                  <span className="flex size-[38px] shrink-0 items-center justify-center rounded-[11px] bg-accent-soft text-ink [&>svg]:size-[18px]">{ICONO[r.clave]}</span>
                  <div className="min-w-0 flex-1">
                    <p className="text-[14.5px] font-semibold text-ink-strong">{r.titulo}</p>
                    <p className="mt-0.5 text-[13px] leading-[1.45] text-muted">{r.descripcion}</p>
                  </div>
                  <Switch
                    tone="ok"
                    checked={r.on}
                    disabled={!q.data.puede_editar}
                    saving={guardando === r.clave}
                    aria-label={r.titulo}
                    onChange={(on) => {
                      setGuardando(r.clave)
                      regla.mutate(
                        { clave: r.clave, on },
                        { onSuccess: () => aviso(on ? 'Regla activada' : 'Regla desactivada'), onError: (e) => aviso(mensaje(e), { tipo: 'error' }), onSettled: () => setGuardando(null) },
                      )
                    }}
                  />
                </Card>
              ))}
            </div>
          </SetCard>
          <SetCard titulo="Seguimientos del CRM" sub="Los seguimientos automáticos de cada contacto se configuran en el Reporting del CRM.">
            <Button variant="ghost" to="/crm/reporting">
              Abrir Reporting del CRM
            </Button>
          </SetCard>
        </>
      )}
    </div>
  )
}
