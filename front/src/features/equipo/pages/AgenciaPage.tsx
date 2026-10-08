import { useRef, useState, type FormEvent } from 'react'
import { Building2, FileText, Globe, House, ImageUp, Mail, Palette, Phone, Trash2 } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Field from '../../../shared/ui/Field'
import FormGrid, { FormZone } from '../../../shared/ui/FormGrid'
import Notice from '../../../shared/ui/Notice'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import AjustesCabecera from '../components/AjustesCabecera'
import { ErrorCarga, Esqueleto, SetCard, SinGuardar } from '../components/piezas'
import { campoDeError, clavesEquipo, mensaje, pedir, useAccion, useAgencia } from '../api'
import { colorValido, hayCambios, inicialMarca } from '../logica'
import { AgenciaRespuesta, type Agencia } from '../schemas'
import { clavesNav } from '../../nav/api'

/* Ajustes › Agencia (settings.php?tab=agency): identidad, cómo te localizan y
   la marca, con la vista previa de la pantalla de acceso y la cabecera del portal. */
export default function AgenciaPage() {
  const q = useAgencia()
  return (
    <div className="max-w-[1180px]">
      <AjustesCabecera titulo="Agencia" sub="Quién eres tú como agencia. Aparece en documentos internos y en la cabecera del portal del cliente." />
      {q.isPending ? <Esqueleto filas={4} /> : q.isError ? <ErrorCarga error={q.error} /> : <Formulario key={JSON.stringify(q.data.agencia)} inicial={q.data.agencia} puede={q.data.puede_editar} />}
    </div>
  )
}

function Formulario({ inicial, puede }: { inicial: Agencia; puede: boolean }) {
  const [f, setF] = useState<Agencia>(inicial)
  const [errCampo, setErrCampo] = useState<{ campo: string | null; msg: string } | null>(null)
  const { aviso } = useToast()
  const archivo = useRef<HTMLInputElement>(null)
  const sucio = hayCambios(f, inicial)
  useUnsavedGuard(sucio)
  const set = (k: keyof Agencia) => (v: string) => setF((x) => ({ ...x, [k]: v }))

  const guardar = useAccion((d: Agencia) => pedir('/api/v1/ajustes/agencia', { method: 'PATCH', body: d, schema: AgenciaRespuesta }), [clavesEquipo.agencia, clavesNav.nav])
  const logo = useAccion((fd: FormData) => pedir('/api/v1/ajustes/agencia/logo', { method: 'POST', form: fd, schema: AgenciaRespuesta }), [clavesEquipo.agencia, clavesNav.nav])
  const quitarLogo = useAccion(() => pedir('/api/v1/ajustes/agencia/logo', { method: 'DELETE', schema: AgenciaRespuesta }), [clavesEquipo.agencia, clavesNav.nav])

  function onSubmit(e: FormEvent) {
    e.preventDefault()
    setErrCampo(null)
    guardar.mutate(f, {
      onSuccess: () => aviso('Datos de la agencia guardados.'),
      onError: (er) => {
        setErrCampo({ campo: campoDeError(er), msg: mensaje(er) })
        aviso(mensaje(er), { tipo: 'error' })
      },
    })
  }

  function subir(file: File | undefined) {
    if (!file) return
    const fd = new FormData()
    fd.append('archivo', file)
    logo.mutate(fd, { onSuccess: () => aviso('Logo actualizado.'), onError: (er) => aviso(mensaje(er), { tipo: 'error' }) })
  }

  const err = (c: string) => (errCampo?.campo === c ? errCampo.msg : undefined)
  const color = colorValido(f.color) ?? '#1f232a'

  return (
    <form onSubmit={onSubmit}>
      <SetCard titulo="Datos de la agencia" sub="Identidad de la agencia dentro del ERP. Aparecen en documentos internos y en la cabecera del portal.">
        {!puede && (
          <Notice tone="info" className="mb-4">
            Solo quien tiene el permiso «Ajustes de la agencia» puede cambiar la identidad de la agencia.
          </Notice>
        )}
        <fieldset disabled={!puede} className="min-w-0">
          <FormGrid>
            <FormZone title="Identidad" />
            <Field label="Nombre de la agencia" icon={<Building2 />} span={6} error={err('nombre')}>
              <TextInput value={f.nombre} onChange={(e) => set('nombre')(e.target.value)} maxLength={120} required />
            </Field>
            <Field label="CIF / NIF" icon={<FileText />} span={3} error={err('cif')}>
              <TextInput value={f.cif} onChange={(e) => set('cif')(e.target.value)} maxLength={30} placeholder="B12345678" />
            </Field>

            <FormZone title="Cómo te localizan" />
            <Field label="Email" icon={<Mail />} span={6} error={err('email')}>
              <TextInput type="email" value={f.email} onChange={(e) => set('email')(e.target.value)} placeholder="hola@tuagencia.com" />
            </Field>
            <Field label="Teléfono" icon={<Phone />} span={3}>
              <TextInput type="tel" value={f.telefono} onChange={(e) => set('telefono')(e.target.value)} placeholder="600 000 000" />
            </Field>
            <Field label="Web" icon={<Globe />} span={3} error={err('web')}>
              <TextInput value={f.web} onChange={(e) => set('web')(e.target.value)} placeholder="https://tuagencia.com" />
            </Field>
            <Field label="Dirección" icon={<House />} span={12}>
              <TextInput value={f.direccion} onChange={(e) => set('direccion')(e.target.value)} placeholder="Calle, número, código postal y ciudad" maxLength={300} />
            </Field>

            <FormZone title="Tu marca" />
            <Field label="Logo (dirección de la imagen)" icon={<ImageUp />} span={8} hint="Pega la dirección de una imagen o sube una (JPG, PNG, GIF o WebP, hasta 2 MB). Si se deja vacío se usa la inicial del nombre." error={err('logo')}>
              <div className="flex flex-wrap gap-2">
                <TextInput className="min-w-[200px] flex-1" value={f.logo} onChange={(e) => set('logo')(e.target.value)} placeholder="https://tuagencia.com/logo.png" />
                <input ref={archivo} type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden onChange={(e) => subir(e.target.files?.[0])} />
                <Button variant="ghost" icon={<ImageUp />} onClick={() => archivo.current?.click()} loading={logo.isPending} loadingText="Subiendo…">
                  Subir
                </Button>
                {inicial.logo && (
                  <Button variant="ghost" icon={<Trash2 />} aria-label="Quitar el logo" onClick={() => quitarLogo.mutate(undefined, { onSuccess: () => aviso('Logo quitado.') })}>
                    Quitar
                  </Button>
                )}
              </div>
            </Field>
            <Field label="Color de marca" icon={<Palette />} span={4} hint="Solo lo usa el portal del cliente. El panel del equipo se queda en blanco y negro." error={err('color')}>
              <div className="flex gap-2">
                <TextInput value={f.color} onChange={(e) => set('color')(e.target.value)} placeholder="#1f232a" maxLength={7} />
                <input
                  type="color"
                  value={color}
                  onChange={(e) => set('color')(e.target.value)}
                  aria-label="Elegir el color"
                  className="h-[43px] w-[46px] shrink-0 cursor-pointer rounded-[10px] border border-line bg-field p-1"
                />
              </div>
            </Field>
          </FormGrid>
        </fieldset>

        <VistaPrevia nombre={f.nombre || 'Croilab'} logo={f.logo} color={color} />

        {puede && (
          <div className="mt-5 flex items-center gap-3">
            <Button type="submit" loading={guardar.isPending} loadingText="Guardando…">
              Guardar datos
            </Button>
            {sucio && <SinGuardar />}
          </div>
        )}
      </SetCard>
    </form>
  )
}

/* Marca: imagen si hay logo, si no la inicial sobre el color. */
function Marca({ nombre, logo, color, size }: { nombre: string; logo: string; color: string; size: number }) {
  const [roto, setRoto] = useState('')
  if (logo && roto !== logo) {
    return <img src={logo} alt="" onError={() => setRoto(logo)} className="shrink-0 rounded-[12px] object-contain" style={{ width: size, height: size }} />
  }
  return (
    <span className="flex shrink-0 items-center justify-center rounded-[12px] font-extrabold text-white" style={{ width: size, height: size, background: color, fontSize: size * 0.45 }}>
      {inicialMarca(nombre)}
    </span>
  )
}

function VistaPrevia({ nombre, logo, color }: { nombre: string; logo: string; color: string }) {
  return (
    <div className="mt-6 grid grid-cols-2 gap-[18px] max-[800px]:grid-cols-1">
      <div>
        <p className="mb-2 text-[11px] font-bold tracking-[.5px] text-muted uppercase">Su pantalla de acceso</p>
        <div className="flex flex-col items-center rounded-2xl border border-line bg-[#f4f4f6] px-5 py-5 dark:bg-soft">
          <Marca nombre={nombre} logo={logo} color={color} size={52} />
          <p className="mt-3 text-[15px] font-bold text-[#1d1d1f] dark:text-ink-strong">{nombre}</p>
          <p className="text-[12px] text-muted">Área de cliente</p>
          <div className="mt-4 h-7 w-full rounded-lg border border-line bg-white dark:bg-field" />
          <div className="mt-2 h-7 w-full rounded-lg border border-line bg-white dark:bg-field" />
          <div className="mt-3 flex h-8 w-full items-center justify-center rounded-lg text-[12.5px] font-semibold text-white" style={{ background: color }}>
            Entrar
          </div>
        </div>
      </div>
      <div>
        <p className="mb-2 text-[11px] font-bold tracking-[.5px] text-muted uppercase">La cabecera de su portal</p>
        <div className="flex items-center gap-3 rounded-2xl border border-line bg-white px-4 py-3.5 dark:bg-field">
          <Marca nombre={nombre} logo={logo} color={color} size={38} />
          <div>
            <p className="text-[14px] font-bold text-[#1d1d1f] dark:text-ink-strong">{nombre}</p>
            <p className="text-[12px] text-muted">Hola, María 👋</p>
          </div>
        </div>
        <p className="mt-3 text-[13px] leading-[1.6] text-muted">El color solo se usa aquí, en el portal del cliente. El panel de tu equipo se queda en blanco y negro.</p>
      </div>
    </div>
  )
}
