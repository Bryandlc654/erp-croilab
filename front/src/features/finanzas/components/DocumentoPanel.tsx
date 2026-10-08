import { useEffect, useRef, useState } from 'react'
import { FileText, ImageIcon, X } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Checkbox from '../../../shared/ui/Checkbox'
import { DateInput } from '../../../shared/ui/DatePicker'
import Field from '../../../shared/ui/Field'
import { TextInput } from '../../../shared/ui/TextInput'
import { FileDropzone } from '../../../shared/ui/rich'
import { useToast } from '../../../shared/ui/useToast'
import { ApiError } from '../../../shared/api/client'
import { mensajeError, urlArchivo, useGuardarDocumento } from '../api'
import { aCampo, proyectoACuerpo, type ProyectoElegido } from '../lib/cuerpos'
import { decimal, leer } from '../lib/importes'
import { hoyIso } from '../lib/periodos'
import type { Documento } from '../schemas'
import ProyectoCombobox from './ProyectoCombobox'

export const TIPOS_ARCHIVO = 'application/pdf,image/jpeg,image/png,image/webp,.pdf,.jpg,.jpeg,.png,.webp'

/* Panel «Subir factura» (documentos de gasto o ingreso externo): vista previa
   del PDF o la imagen a la izquierda y el formulario a la derecha. Guardar
   crea (o actualiza) también su apunte de caja. */
export default function DocumentoPanel({
  emisor,
  emisorNombre,
  tipo,
  doc,
  archivoInicial,
  onClose,
}: {
  emisor: string
  emisorNombre: string
  tipo: 'gasto' | 'ingreso'
  doc: Documento | null
  archivoInicial: File | null
  onClose: () => void
}) {
  const { aviso } = useToast()
  const guardar = useGuardarDocumento()
  const [archivo, setArchivo] = useState<File | null>(archivoInicial)
  const [vista, setVista] = useState<string | null>(() => (archivoInicial ? URL.createObjectURL(archivoInicial) : null))
  const [concepto, setConcepto] = useState(doc?.concepto ?? '')
  const [proveedor, setProveedor] = useState(doc?.proveedor ?? '')
  const [importe, setImporte] = useState(doc ? aCampo(decimal(doc.importe)) : '')
  const [fecha, setFecha] = useState<string>(doc?.fecha ?? hoyIso())
  const [efectivo, setEfectivo] = useState(doc?.efectivo ?? false)
  const [personal, setPersonal] = useState(doc?.personal ?? false)
  const [proyecto, setProyecto] = useState<ProyectoElegido>(doc?.project ? { ...doc.project } : null)
  const [error, setError] = useState<Record<string, string>>({})
  const vistaRef = useRef(vista)

  /* La URL temporal del archivo local se libera al cambiarla o al cerrar. */
  useEffect(() => {
    vistaRef.current = vista
  }, [vista])
  useEffect(() => () => {
    if (vistaRef.current) URL.revokeObjectURL(vistaRef.current)
  }, [])

  function elegir(f: File | null) {
    if (vista) URL.revokeObjectURL(vista)
    setArchivo(f)
    setVista(f ? URL.createObjectURL(f) : null)
  }

  const remoto = !archivo && doc?.archivo ? urlArchivo(doc.archivo) : null
  const src = vista ?? remoto
  const esPdf = archivo ? archivo.type === 'application/pdf' || /\.pdf$/i.test(archivo.name) : doc?.tipo_archivo === 'pdf'
  const nombre = archivo?.name ?? (doc?.archivo ? doc.nombre_archivo : null)

  async function enviar() {
    const imp = leer(importe)
    if (!imp || imp === '0.00') {
      setError({ importe: 'Escribe el importe.' })
      return
    }
    const fd = new FormData()
    fd.set('emisor', emisor)
    fd.set('tipo', tipo)
    fd.set('concepto', concepto)
    fd.set('proveedor', proveedor)
    fd.set('importe', imp)
    fd.set('fecha', fecha)
    fd.set('efectivo', efectivo ? '1' : '0')
    fd.set('personal', personal ? '1' : '0')
    const pc = proyectoACuerpo(proyecto)
    if ('project_nombre' in pc && pc.project_nombre) fd.set('project_nombre', pc.project_nombre)
    else fd.set('project_id', 'project_id' in pc && pc.project_id ? String(pc.project_id) : '')
    if (archivo) fd.set('archivo', archivo)
    try {
      await guardar.mutateAsync({ id: doc?.id ?? null, form: fd })
      aviso(doc ? 'Documento guardado.' : tipo === 'gasto' ? 'Gasto subido y apuntado en Contabilidad.' : 'Ingreso subido y apuntado en Contabilidad.')
      onClose()
    } catch (e) {
      if (e instanceof ApiError && e.campo) setError({ [e.campo]: e.message })
      aviso(mensajeError(e, 'No se ha podido guardar.'), { tipo: 'error' })
    }
  }

  return (
    <section className="mb-6 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-5 rounded-2xl border border-line bg-card p-5 motion-safe:animate-fade-up max-[900px]:grid-cols-1 max-sm:p-4" aria-label={doc ? 'Editar documento' : 'Subir factura'}>
      <div className="flex min-h-[320px] flex-col overflow-hidden rounded-[14px] border border-line">
        <div className="flex items-center gap-2 border-b border-line bg-head px-3.5 py-2.5 text-[12.5px]">
          {esPdf ? <FileText className="size-4 text-label" /> : <ImageIcon className="size-4 text-label" />}
          <span className="min-w-0 flex-1 truncate font-semibold text-ink">{nombre ?? 'Sin archivo seleccionado'}</span>
          {nombre && <span className="rounded-md bg-chip px-2 py-[2px] text-[10.5px] font-bold text-[#5c616b] dark:text-ink">{esPdf ? 'PDF' : 'Imagen'}</span>}
          {archivo && (
            <button type="button" onClick={() => elegir(null)} aria-label="Quitar archivo" className="flex size-6 items-center justify-center rounded-md text-label hover:bg-soft">
              <X className="size-3.5" />
            </button>
          )}
        </div>
        <div className="flex flex-1 items-center justify-center bg-soft p-3">
          {remoto && esPdf ? (
            <div className="flex flex-col items-center gap-3 text-center text-[13px] text-muted">
              <FileText className="size-8 text-label" />
              <span>El PDF guardado se abre en otra pestaña.</span>
              <Button variant="ghost" size="sm" href={remoto} target="_blank" rel="noopener noreferrer">
                Abrir PDF
              </Button>
              <FileDropzone onFiles={(fs) => elegir(fs[0] ?? null)} accept={TIPOS_ARCHIVO} maxSizeMB={15} multiple={false} compact label="Sustituir por otro archivo" />
            </div>
          ) : src ? (
            esPdf ? (
              <iframe title="Vista previa del PDF" src={src} className="h-[420px] w-full rounded-lg border-0 bg-white max-sm:h-[300px]" />
            ) : (
              <img src={src} alt="Vista previa" className="max-h-[420px] max-w-full rounded-lg object-contain" />
            )
          ) : (
            <FileDropzone
              onFiles={(fs) => elegir(fs[0] ?? null)}
              accept={TIPOS_ARCHIVO}
              maxSizeMB={15}
              multiple={false}
              label="Arrastra o haz clic para subir"
              hint="PDF o imagen (JPG, PNG, WebP) · máx. 15 MB"
              className="w-full"
            />
          )}
        </div>
      </div>

      <div className="flex flex-col gap-4">
        <h3 className="text-[16px] font-semibold text-ink-strong">
          {doc ? 'Editar documento' : 'Subir factura'} · {tipo === 'gasto' ? 'Gastos' : 'Ingresos'} de {emisorNombre}
        </h3>
        <Field label="Concepto" error={error.concepto}>
          <TextInput value={concepto} maxLength={250} placeholder={tipo === 'gasto' ? 'Hosting, gestoría…' : 'Servicio SEO…'} onChange={(e) => setConcepto(e.target.value)} />
        </Field>
        <Field label={tipo === 'gasto' ? 'Proveedor' : 'Cliente'} error={error.proveedor}>
          <TextInput value={proveedor} maxLength={200} onChange={(e) => setProveedor(e.target.value)} />
        </Field>
        <div className="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
          <Field label="Importe (€)" error={error.importe} hint="Total tal cual, con impuestos.">
            <TextInput
              value={importe}
              inputMode="decimal"
              placeholder="0,00"
              unit="€"
              onChange={(e) => {
                setImporte(e.target.value)
                setError({})
              }}
            />
          </Field>
          <Field label="Fecha" error={error.fecha}>
            <DateInput value={fecha} onChange={(v) => setFecha(v ?? hoyIso())} />
          </Field>
        </div>
        <div className="flex flex-col gap-2.5">
          <Checkbox checked={efectivo} onChange={setEfectivo} label="Efectivo (pagado en B · sin IVA/IRPF)" />
          <Checkbox checked={personal} onChange={setPersonal} label={tipo === 'gasto' ? 'Deducible — me lo desgravo (gasto del socio)' : 'Personal — no cuenta en contabilidad'} />
        </div>
        <Field label={<>Proyecto <span className="font-normal text-label">· uso interno, para su rentabilidad</span></>}>
          <ProyectoCombobox value={proyecto} onChange={setProyecto} />
        </Field>
        {error.archivo && <p className="text-[12.5px] font-medium text-[#ef4444]">{error.archivo}</p>}
        <div className="mt-auto flex justify-end gap-2.5 pt-2">
          <Button variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button onClick={() => void enviar()} loading={guardar.isPending} loadingText="Guardando…">
            Guardar
          </Button>
        </div>
      </div>
    </section>
  )
}
