import { useState } from 'react'
import { ArrowLeft, CheckCircle2, Download, Upload } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import Checkbox from '../../../shared/ui/Checkbox'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import Select from '../../../shared/ui/Select'
import { useToast } from '../../../shared/ui/useToast'
import { FileDropzone } from '../../../shared/ui/rich'
import { useQueryClient } from '@tanstack/react-query'
import { clavesCrm, importarCsv, mensajeError, plantillaCsv } from '../api'
import { CAMPOS_IMPORTAR, cambiarMapeo, descargarTexto, importeTexto } from '../logica'
import { usePermisosCrm } from '../permisos'
import type { Importacion } from '../schemas'

const COLUMNAS = ['Nombre', 'Empresa', 'Sector', 'Email', 'Telefono', 'WhatsApp', 'Origen', 'Servicios', 'Valor', 'Fase', 'Propietario']
const TH = 'border-b border-line bg-head px-3 py-2.5 text-left text-[11px] font-[650] tracking-[.5px] text-muted uppercase'
const TD = 'border-b border-line2 px-3 py-2 text-[13px]'

/* Importar contactos desde CSV (crm_import.php) en tres pasos: subir, revisar
   (el servidor valida sin escribir: columnas, errores por fila, duplicados) e
   importar con el mapeo elegido. */
export default function ImportarPage() {
  const p = usePermisosCrm()
  const qc = useQueryClient()
  const { aviso } = useToast()
  const [archivo, setArchivo] = useState<File | null>(null)
  const [prev, setPrev] = useState<Importacion | null>(null)
  const [mapeo, setMapeo] = useState<string[]>([])
  const [omitir, setOmitir] = useState(true)
  const [hecho, setHecho] = useState<Importacion | null>(null)
  const [cargando, setCargando] = useState(false)
  const [error, setError] = useState('')

  async function validar(f: File, m?: string[]) {
    setCargando(true)
    setError('')
    try {
      const r = await importarCsv(f, { prueba: true, mapeo: m })
      setPrev(r)
      setMapeo(r.mapeo)
    } catch (e) {
      setError(mensajeError(e, 'No se ha podido leer el CSV.'))
      setPrev(null)
    } finally {
      setCargando(false)
    }
  }

  async function importar() {
    if (!archivo) return
    setCargando(true)
    setError('')
    try {
      const r = await importarCsv(archivo, { prueba: false, mapeo, omitirDuplicados: omitir })
      setHecho(r)
      void qc.invalidateQueries({ queryKey: clavesCrm.contactos })
    } catch (e) {
      setError(mensajeError(e, 'No se ha podido importar.'))
    } finally {
      setCargando(false)
    }
  }

  function reiniciar() {
    setArchivo(null)
    setPrev(null)
    setMapeo([])
    setHecho(null)
    setError('')
  }

  async function plantilla() {
    try {
      const r = await plantillaCsv()
      descargarTexto(r.nombre, r.csv)
    } catch (e) {
      aviso(mensajeError(e), { tipo: 'error' })
    }
  }

  const cabecera = <PageHeader title="Importar contactos" lead="Sube un CSV y se crean los contactos en el CRM." crumbs={[{ label: 'Contactos', to: '/crm' }, { label: 'Importar' }]} />
  if (!p.crear) {
    return (
      <div>
        {cabecera}
        <Notice tone="warn">No tienes permiso para crear contactos.</Notice>
      </div>
    )
  }

  if (hecho) {
    return (
      <div>
        {cabecera}
        <Card className="max-w-[640px]">
          <p className="flex items-center gap-2 text-[16px] font-[650] text-[#12854a] dark:text-ok">
            <CheckCircle2 className="size-5" /> Importación completada
          </p>
          <p className="mt-2 text-[13.5px] text-ink">
            {hecho.insertados} contacto(s) creados, {hecho.n_omitidas} fila(s) omitidas (sin nombre){omitir && hecho.n_duplicados > 0 ? ` y ${hecho.n_duplicados} duplicado(s) sin importar` : ''}.
          </p>
          <div className="mt-4 flex flex-wrap gap-2.5">
            <Button to="/crm">→ Ver contactos</Button>
            <Button variant="ghost" onClick={reiniciar}>
              Importar otro archivo
            </Button>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div>
      {cabecera}
      {error && <Notice tone="error">{error}</Notice>}
      {!prev ? (
        <Card className="max-w-[640px]">
          <FileDropzone
            multiple={false}
            accept=".csv,text/csv,text/plain"
            maxSizeMB={2}
            label="Haz clic para elegir tu archivo CSV"
            hint="o arrástralo aquí"
            disabled={cargando}
            onFiles={(fs) => {
              const f = fs[0]
              if (!f) return
              setArchivo(f)
              void validar(f)
            }}
          />
          <div className="mt-4 flex flex-wrap items-center gap-3">
            {cargando && <span className="text-[13px] text-muted">Revisando el archivo…</span>}
            <Button variant="link" icon={<Download />} onClick={() => void plantilla()}>
              Descargar plantilla CSV
            </Button>
          </div>
          <p className="mt-3 text-[12.5px] leading-relaxed text-muted">
            Columnas reconocidas (por su cabecera, en cualquier orden):{' '}
            {COLUMNAS.map((c, i) => (
              <span key={c}>
                <code className="rounded bg-soft px-1.5 py-px text-[11.5px] text-ink">{c}</code>
                {c === 'Nombre' && ' (obligatoria)'}
                {c === 'Servicios' && ' (separados por ;)'}
                {i < COLUMNAS.length - 1 ? ', ' : '.'}
              </span>
            ))}{' '}
            Máximo 2 MB y 5.000 filas. Antes de importar podrás revisar cada columna.
          </p>
        </Card>
      ) : (
        <div className="flex flex-col gap-4">
          <Card padding="md">
            <div className="mb-3 flex flex-wrap items-center gap-2">
              <h3 className="min-w-0 flex-1 truncate text-[15px] font-[650] text-ink-strong">{archivo?.name}</h3>
              <Button variant="ghost" size="sm" icon={<ArrowLeft />} onClick={reiniciar}>
                Elegir otro archivo
              </Button>
            </div>
            <div className="mb-4 flex flex-wrap gap-2 text-[12.5px]">
              <span className="rounded-full bg-soft px-3 py-1 font-semibold text-ink">{prev.total_filas} filas</span>
              <span className="rounded-full bg-[#e6f6ee] px-3 py-1 font-semibold text-[#12854a] dark:bg-ok-bg dark:text-ok">{prev.validas} válidas</span>
              {prev.n_omitidas > 0 && <span className="rounded-full bg-soft px-3 py-1 font-semibold text-muted">{prev.n_omitidas} sin nombre</span>}
              {prev.n_duplicados > 0 && <span className="rounded-full bg-[#fdf1e3] px-3 py-1 font-semibold text-[#9a5410] dark:bg-[#2a2210] dark:text-warn">{prev.n_duplicados} posibles duplicados</span>}
              {prev.n_avisos > 0 && <span className="rounded-full bg-[#fdf1e3] px-3 py-1 font-semibold text-[#9a5410] dark:bg-[#2a2210] dark:text-warn">{prev.n_avisos} avisos</span>}
            </div>
            <h4 className="mb-2 text-[12px] font-[650] tracking-[.5px] text-muted uppercase">Columnas del archivo</h4>
            <div className="grid grid-cols-[repeat(auto-fill,minmax(210px,1fr))] gap-2.5">
              {prev.cabecera.map((h, i) => (
                <div key={i} className="rounded-xl border border-line px-3 py-2.5">
                  <div className="mb-1.5 truncate text-[12.5px] font-semibold text-ink-strong" title={h}>
                    {h || `Columna ${i + 1}`}
                  </div>
                  <Select
                    size="sm"
                    value={mapeo[i] ?? ''}
                    options={CAMPOS_IMPORTAR}
                    aria-label={`Campo de la columna ${h}`}
                    onChange={(v) => {
                      const m = cambiarMapeo(mapeo, i, v)
                      setMapeo(m)
                      if (archivo) void validar(archivo, m)
                    }}
                  />
                </div>
              ))}
            </div>
          </Card>

          {prev.muestra.length > 0 && (
            <Card padding="none" className="overflow-x-auto">
              <table className="w-full border-collapse">
                <thead>
                  <tr>
                    {['Fila', 'Nombre', 'Empresa', 'Email', 'Teléfono', 'Fase', 'Valor', 'Servicios'].map((h) => (
                      <th key={h} className={TH}>
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {prev.muestra.map((r) => (
                    <tr key={r.fila} className={r.duplicado ? 'bg-[#fffaf0] dark:bg-[#2a2210]' : ''}>
                      <td className={`${TD} text-muted tabular-nums`}>{r.fila}</td>
                      <td className={`${TD} font-semibold text-ink-strong`}>{r.nombre}</td>
                      <td className={TD}>{r.empresa}</td>
                      <td className={TD}>{r.email}</td>
                      <td className={TD}>{r.telefono}</td>
                      <td className={TD}>{r.fase}</td>
                      <td className={`${TD} tabular-nums`}>{importeTexto(r.valor)}</td>
                      <td className={TD}>{r.servicios}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          )}

          {(prev.duplicados.length > 0 || prev.avisos.length > 0 || prev.omitidas.length > 0) && (
            <Card padding="md">
              {prev.duplicados.length > 0 && (
                <>
                  <h4 className="mb-1.5 text-[13px] font-[650] text-ink-strong">Posibles duplicados</h4>
                  <ul className="mb-3 text-[12.5px] text-muted">
                    {prev.duplicados.map((d) => (
                      <li key={d.fila}>
                        Fila {d.fila} · {d.nombre}: {d.motivo}
                      </li>
                    ))}
                  </ul>
                </>
              )}
              {prev.avisos.length > 0 && (
                <>
                  <h4 className="mb-1.5 text-[13px] font-[650] text-ink-strong">Avisos</h4>
                  <ul className="mb-3 text-[12.5px] text-muted">
                    {prev.avisos.map((a, i) => (
                      <li key={i}>
                        Fila {a.fila}: {a.msg}
                      </li>
                    ))}
                  </ul>
                </>
              )}
              {prev.omitidas.length > 0 && (
                <p className="text-[12.5px] text-muted">Filas sin nombre que no se importan: {prev.omitidas.map((o) => o.fila).join(', ')}.</p>
              )}
            </Card>
          )}

          <div className="flex flex-wrap items-center gap-4">
            <Button icon={<Upload />} loading={cargando} loadingText="Importando…" disabled={!mapeo.includes('nombre')} onClick={() => void importar()}>
              Importar {omitir ? prev.validas - prev.n_duplicados : prev.validas} contacto(s)
            </Button>
            {prev.n_duplicados > 0 && <Checkbox checked={omitir} onChange={setOmitir} label="No importar los posibles duplicados" />}
            {!mapeo.includes('nombre') && <span className="text-[12.5px] font-medium text-[#ef4444]">Elige qué columna es el «Nombre».</span>}
          </div>
        </div>
      )}
    </div>
  )
}
