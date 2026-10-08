import { useState } from 'react'
import { CalendarClock, Mail, MessageCircle } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Field from '../../../shared/ui/Field'
import FormGrid from '../../../shared/ui/FormGrid'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import AjustesCabecera from '../components/AjustesCabecera'
import { ErrorCarga, Esqueleto, SetCard, SinGuardar } from '../components/piezas'
import { campoDeError, clavesEquipo, mensaje, pedir, useAccion, useContacto } from '../api'
import { hayCambios } from '../logica'
import { ContactoRespuesta, type Contacto } from '../schemas'

/* Ajustes › Portal › Contacto (settings.php?tab=contacto). */
export default function PortalContactoPage() {
  const q = useContacto()
  return (
    <div className="max-w-[1180px]">
      <AjustesCabecera titulo="Contacto" sub="Por dónde te escriben los clientes desde su portal." />
      {q.isPending ? <Esqueleto /> : q.isError ? <ErrorCarga error={q.error} /> : <Formulario key={JSON.stringify(q.data.contacto)} inicial={q.data.contacto} puede={q.data.puede_editar} />}
    </div>
  )
}

function Formulario({ inicial, puede }: { inicial: Contacto; puede: boolean }) {
  const [f, setF] = useState(inicial)
  const [err, setErr] = useState<{ campo: string | null; msg: string } | null>(null)
  const { aviso } = useToast()
  const guardar = useAccion((b: Contacto) => pedir('/api/v1/ajustes/portal/contacto', { method: 'PATCH', body: b, schema: ContactoRespuesta }), [clavesEquipo.contacto])
  const sucio = hayCambios(f, inicial)
  useUnsavedGuard(sucio)
  const e = (k: string) => (err?.campo === k ? err.msg : undefined)
  return (
    <form
      onSubmit={(ev) => {
        ev.preventDefault()
        setErr(null)
        guardar.mutate(f, { onSuccess: () => aviso('Contacto guardado.'), onError: (er) => setErr({ campo: campoDeError(er), msg: mensaje(er) }) })
      }}
    >
      <SetCard titulo="Contacto del portal" sub="Lo que ve el cliente para escribirte o pedir una reunión. Los clientes de marca blanca usan el contacto de su agencia si lo tiene configurado.">
        <fieldset disabled={!puede} className="min-w-0">
          <FormGrid>
            <Field label="Email de contacto" icon={<Mail />} span={6} error={e('email')}>
              <TextInput type="email" value={f.email} onChange={(x) => setF({ ...f, email: x.target.value })} placeholder="hola@tuagencia.com" />
            </Field>
            <Field label="WhatsApp" icon={<MessageCircle />} span={6} error={e('whatsapp')} hint="Sin el «+», con el prefijo del país. Ej: 34600000000.">
              <TextInput inputMode="tel" value={f.whatsapp} onChange={(x) => setF({ ...f, whatsapp: x.target.value })} placeholder="34600000000" />
            </Field>
            <Field
              label="Enlace de reservas (Google Calendar · Horarios de citas)"
              icon={<CalendarClock />}
              span={12}
              error={e('meeting_url')}
              hint="En Google Calendar → Crear → Horario de citas. Copia el enlace de la página de reservas y pégalo aquí: el cliente elegirá día y hora desde su portal."
            >
              <TextInput value={f.meeting_url} onChange={(x) => setF({ ...f, meeting_url: x.target.value })} placeholder="https://calendar.app.google/…" />
            </Field>
          </FormGrid>
        </fieldset>
        {puede && (
          <div className="mt-5 flex items-center gap-3">
            <Button type="submit" loading={guardar.isPending} loadingText="Guardando…">
              Guardar contacto
            </Button>
            {sucio && <SinGuardar />}
          </div>
        )}
      </SetCard>
    </form>
  )
}
