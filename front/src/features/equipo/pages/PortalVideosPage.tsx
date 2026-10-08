import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Video } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Field from '../../../shared/ui/Field'
import FormGrid, { FormZone } from '../../../shared/ui/FormGrid'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import AjustesCabecera from '../components/AjustesCabecera'
import { ErrorCarga, Esqueleto, SetCard, SinGuardar } from '../components/piezas'
import { campoDeError, clavesEquipo, mensaje, pedir, useAccion, useVideos } from '../api'
import { hayCambios } from '../logica'
import { VideosRespuesta, type Videos } from '../schemas'

/* Ajustes › Portal › Vídeos (settings.php?tab=videos): el vídeo de
   presentación y uno por servicio del catálogo. */
export default function PortalVideosPage() {
  const q = useVideos()
  return (
    <div className="max-w-[1180px]">
      <AjustesCabecera titulo="Vídeos" sub="Qué vídeo ve el cliente en cada servicio de su portal." />
      {q.isPending ? <Esqueleto /> : q.isError ? <ErrorCarga error={q.error} /> : <Formulario key={JSON.stringify(q.data)} d={q.data} />}
    </div>
  )
}

function Formulario({ d }: { d: Videos }) {
  const inicial = { video_id: d.video_id, servicios: d.servicios }
  const [f, setF] = useState(inicial)
  const [err, setErr] = useState<{ campo: string | null; msg: string } | null>(null)
  const { aviso } = useToast()
  const guardar = useAccion((b: typeof f) => pedir('/api/v1/ajustes/portal/videos', { method: 'PATCH', body: b, schema: VideosRespuesta }), [clavesEquipo.videos])
  const sucio = hayCambios(f, inicial)
  useUnsavedGuard(sucio)
  return (
    <form
      onSubmit={(ev) => {
        ev.preventDefault()
        setErr(null)
        guardar.mutate(f, { onSuccess: () => aviso('Vídeos guardados.'), onError: (er) => setErr({ campo: campoDeError(er), msg: mensaje(er) }) })
      }}
    >
      <SetCard
        titulo="Vídeos de YouTube"
        sub={
          <>
            Pega el enlace del vídeo (o solo su identificador, las 11 letras que van tras <code className="font-mono">v=</code>). El cliente lo ve dentro de su portal, sin salir a YouTube.
          </>
        }
      >
        <fieldset disabled={!d.puede_editar} className="min-w-0">
          <FormGrid>
            <Field label="Vídeo de presentación" icon={<Video />} span={6} error={err?.campo === 'video_id' ? err.msg : undefined} hint="Lo ve en el inicio de su portal.">
              <TextInput value={f.video_id} onChange={(x) => setF({ ...f, video_id: x.target.value })} placeholder="https://youtu.be/…" />
            </Field>
            <FormZone title="Un vídeo por servicio" />
            {f.servicios.map((s, i) => (
              <Field key={s.nombre} label={s.nombre} span={3} error={err?.campo === `servicios.${i}.video` ? err.msg : undefined}>
                <TextInput
                  value={s.video}
                  placeholder="Sin vídeo"
                  onChange={(x) => setF({ ...f, servicios: f.servicios.map((o, j) => (j === i ? { ...o, video: x.target.value } : o)) })}
                />
              </Field>
            ))}
          </FormGrid>
        </fieldset>
        {err && !err.campo && <p className="mt-3 text-[12.5px] text-[#ef4444]">{err.msg}</p>}
        <p className="mt-4 text-[12.5px] text-muted">
          ¿Falta un servicio en esta lista? Créalo primero en{' '}
          <Link to="/clientes/servicios" className="font-semibold text-ink underline-offset-2 hover:underline">
            Servicios
          </Link>{' '}
          y vuelve aquí a ponerle su vídeo.
        </p>
        {d.puede_editar && (
          <div className="mt-5 flex items-center gap-3">
            <Button type="submit" loading={guardar.isPending} loadingText="Guardando…">
              Guardar vídeos
            </Button>
            {sucio && <SinGuardar />}
          </div>
        )}
      </SetCard>
    </form>
  )
}
