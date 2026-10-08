import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Check, Eye, Plus, Trash2 } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import IconButton from '../../../shared/ui/IconButton'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import { mensajeError, useGuardarServicios, useServicios } from '../api'
import { nuevaKey } from '../lib/formulario'
import type { Servicio } from '../schemas'
import Etiqueta from './Etiqueta'
import { usePermisosClientes } from './permisos'

type Fila = { key: string; nombre: string; desc: string; orig: string; uso: number; video: boolean }

const aFilas = (s: Servicio[]): Fila[] => s.map((x) => ({ key: nuevaKey(), nombre: x.nombre, desc: x.desc, orig: x.nombre, uso: x.uso, video: x.video }))

/* Catálogo de servicios (servicios.php). */
export default function ServiciosPage() {
  const { data, isLoading, error } = useServicios()
  // Fuera del catálogo: al guardar, el catálogo se vuelve a montar con lo que devuelve la API.
  const [resultado, setResultado] = useState<string | null>(null)
  if (error) return <Notice tone="error">{mensajeError(error, 'No se ha podido cargar el catálogo.')}</Notice>
  if (isLoading || !data) return <p className="py-[60px] text-center text-[13.5px] text-muted">Cargando…</p>
  return <Catalogo key={JSON.stringify(data.servicios)} servicios={data.servicios} abiertos={data.abiertos} total={data.total} resultado={resultado} setResultado={setResultado} />
}

function Catalogo({
  servicios,
  abiertos,
  total,
  resultado,
  setResultado,
}: {
  servicios: Servicio[]
  abiertos: number
  total: number
  resultado: string | null
  setResultado: (s: string | null) => void
}) {
  const p = usePermisosClientes()
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const guardar = useGuardarServicios()
  const [filas, setFilas] = useState<Fila[]>(() => aFilas(servicios))
  const [inicial] = useState(() => JSON.stringify(filas.map((f) => [f.nombre, f.desc])))
  const dirty = JSON.stringify(filas.map((f) => [f.nombre, f.desc])) !== inicial
  useUnsavedGuard(dirty && !guardar.isPending)

  const cambiar = (key: string, c: Partial<Fila>) => setFilas((fs) => fs.map((f) => (f.key === key ? { ...f, ...c } : f)))

  async function quitar(f: Fila) {
    if (f.orig && f.uso > 0) {
      const ok = await confirm({
        title: 'Quitar del catálogo',
        message: `«${f.orig}» lo tienen ${f.uso} cliente${f.uso === 1 ? '' : 's'} contratado. Si lo quitas del catálogo, dejará de aparecer en su portal.`,
        okLabel: 'Quitar igualmente',
        danger: true,
      })
      if (!ok) return
    }
    setFilas((fs) => fs.filter((x) => x.key !== f.key))
  }

  async function enviar() {
    try {
      const r = await guardar.mutateAsync(filas.map((f) => ({ nombre: f.nombre, desc: f.desc, orig: f.orig })))
      aviso('Catálogo guardado.')
      setResultado(
        r.renombrados > 0 && r.clientes > 0 ? `Catálogo guardado. Se ha cambiado el nombre en la ficha de ${r.clientes} cliente${r.clientes === 1 ? '' : 's'}.` : 'Catálogo guardado.',
      )
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido guardar el catálogo.'), { tipo: 'error' })
    }
  }

  const n = filas.filter((f) => f.nombre.trim()).length
  return (
    <div className="max-w-[1180px]">
      <PageHeader
        title="Servicios"
        lead="Lo que ofreces y lo que le puedes asignar a cada cliente. Está disponible al crear presupuestos y programaciones, y decide qué secciones ve cada cliente en su portal."
      />
      {resultado && <Notice tone="ok">{resultado}</Notice>}
      {!p.servicios && <Notice tone="warn">Solo lectura: no puedes cambiar el catálogo con tu permiso actual.</Notice>}
      <Card padding="none" className="overflow-hidden">
        <div className="px-[22px] pt-5 pb-1 max-sm:px-4">
          <b className="text-[12px] font-[650] tracking-[.6px] text-ink-strong uppercase">
            {n} servicio{n === 1 ? '' : 's'} en el catálogo
          </b>
          {abiertos > 0 && (
            <p className="mt-1.5 text-[12.5px] text-muted">
              {abiertos} de tus {total} clientes no tienen la lista de servicios personalizada, así que <b className="text-ink-strong">ven todos</b>. Se elige cliente a cliente desde su portal.
            </p>
          )}
        </div>
        <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_150px_34px] gap-4 px-[22px] pt-3 pb-2 text-[11.5px] font-semibold tracking-[.5px] text-muted uppercase max-md:hidden">
          <span>Servicio</span>
          <span>Descripción corta</span>
          <span className="text-right">Estado</span>
          <span />
        </div>
        <fieldset disabled={!p.servicios} className="min-w-0">
          {filas.map((f) => (
            <div
              key={f.key}
              className="group/fila grid grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_150px_34px] items-center gap-4 border-t border-line2 px-[22px] py-3 max-md:grid-cols-1 max-md:gap-2 max-sm:px-4"
            >
              <TextInput value={f.nombre} onChange={(e) => cambiar(f.key, { nombre: e.target.value })} placeholder="Nombre del servicio" maxLength={80} aria-label="Servicio" />
              <TextInput value={f.desc} onChange={(e) => cambiar(f.key, { desc: e.target.value })} placeholder="Para qué es, en una línea" maxLength={200} aria-label="Descripción corta" />
              <div className="flex flex-wrap items-center justify-end gap-1.5 max-md:justify-start">
                {f.orig ? <Etiqueta tono={f.uso > 0 ? 'on' : 'neutro'}>{`${f.uso} cliente${f.uso === 1 ? '' : 's'}`}</Etiqueta> : <Etiqueta tono="verde">Nuevo</Etiqueta>}
                {f.video && (
                  <Etiqueta>
                    <Eye className="size-3" /> Vídeo
                  </Etiqueta>
                )}
              </div>
              {p.servicios ? (
                <IconButton
                  label="Quitar del catálogo"
                  tone="danger"
                  icon={<Trash2 />}
                  onClick={() => void quitar(f)}
                  className="opacity-0 group-focus-within/fila:opacity-100 group-hover/fila:opacity-100 max-[760px]:opacity-100 max-md:justify-self-end"
                />
              ) : (
                <span />
              )}
            </div>
          ))}
          {p.servicios && (
            <button
              type="button"
              onClick={() => setFilas((fs) => [...fs, { key: nuevaKey(), nombre: '', desc: '', orig: '', uso: 0, video: false }])}
              className="group/add flex w-full items-center gap-2 border-t border-line2 px-[22px] py-[11px] text-left text-[13.5px] text-label transition-colors hover:bg-hover-row hover:text-ink"
            >
              <Plus className="size-4 transition-transform duration-[180ms] group-hover/add:rotate-90" />
              Añadir servicio
            </button>
          )}
        </fieldset>
      </Card>
      {p.servicios && (
        <div className="mt-5 flex flex-wrap items-center gap-3">
          <Button icon={<Check />} onClick={() => void enviar()} loading={guardar.isPending} loadingText="Guardando…" disabled={!dirty}>
            Guardar catálogo
          </Button>
          <span className="text-[12.5px] text-muted">
            Al cambiarle el nombre a un servicio, se cambia también en la ficha de los clientes que lo tengan. El vídeo de cada uno se pone en{' '}
            <Link to="/ajustes/portal/videos" className="font-semibold text-ink hover:underline">
              Vídeos
            </Link>
            .
          </span>
        </div>
      )}
    </div>
  )
}
