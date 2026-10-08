import { useMemo, useState, type FormEvent } from 'react'
import { Search } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Checkbox from '../../../shared/ui/Checkbox'
import Field from '../../../shared/ui/Field'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Segmented from '../../../shared/ui/Segmented'
import Select from '../../../shared/ui/Select'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { ApiError } from '../../../shared/api/client'
import { useEquipo } from '../../nav/api'
import { useAccionListas, useCatalogos, useContactos } from '../api'

type Tipo = 'activa' | 'estatica' | 'manual'
const COND_VACIAS = { sector: '', origen: '', fase: '', servicio: '', prop: '', quick: '', vmin: '', vmax: '', q: '' }
const ETQ = 'mb-1.5 block text-[12px] font-semibold text-muted'

/* «Nueva lista»: activa (condiciones que se recalculan), estática (las mismas
   condiciones, congeladas) o selección manual de contactos. */
export default function NuevaListaModal({ open, onClose, onCreada }: { open: boolean; onClose: () => void; onCreada: (id: number) => void }) {
  const { data: cat } = useCatalogos()
  const { data: equipo = [] } = useEquipo()
  const acc = useAccionListas()
  const { aviso } = useToast()
  const [nombre, setNombre] = useState('')
  const [desc, setDesc] = useState('')
  const [tipo, setTipo] = useState<Tipo>('activa')
  const [cond, setCond] = useState(COND_VACIAS)
  const [sel, setSel] = useState<Set<number>>(new Set())
  const [buscar, setBuscar] = useState('')
  const [error, setError] = useState<{ campo: string | null; msg: string } | null>(null)
  const todos = useContactos({})
  const contactos = useMemo(() => todos.data?.pages.flatMap((p) => p.items) ?? [], [todos.data])
  const visibles = useMemo(() => {
    const q = buscar.trim().toLowerCase()
    return q ? contactos.filter((c) => `${c.nombre} ${c.empresa} ${c.email}`.toLowerCase().includes(q)) : contactos
  }, [contactos, buscar])

  function cerrar() {
    setNombre('')
    setDesc('')
    setTipo('activa')
    setCond(COND_VACIAS)
    setSel(new Set())
    setBuscar('')
    setError(null)
    onClose()
  }

  async function enviar(e: FormEvent) {
    e.preventDefault()
    if (!nombre.trim()) return setError({ campo: 'nombre', msg: 'Ponle un nombre a la lista.' })
    if (tipo === 'manual' && sel.size === 0) return setError({ campo: 'ids', msg: 'Elige al menos un contacto para la lista' })
    const condiciones = Object.fromEntries(Object.entries(cond).filter(([, v]) => v !== ''))
    try {
      const r = await acc.crear.mutateAsync({ nombre: nombre.trim(), descripcion: desc.trim(), tipo, condiciones, ids: tipo === 'manual' ? [...sel] : undefined })
      aviso(`Lista «${r.lista.nombre}» creada`)
      cerrar()
      onCreada(r.lista.id)
    } catch (x) {
      setError({ campo: x instanceof ApiError ? x.campo : null, msg: x instanceof ApiError ? x.message : 'No se ha podido crear.' })
    }
  }

  const c = (k: keyof typeof COND_VACIAS) => (v: string) => setCond((x) => ({ ...x, [k]: v }))
  const cualquiera = { value: '', label: 'Cualquiera' }
  const err = (k: string) => (error?.campo === k ? error.msg : undefined)

  return (
    <Modal open={open} onClose={cerrar} size="lg" align="top" title="Nueva lista" subtitle="Filtra por condiciones o elige los contactos a mano. Una lista activa se recalcula sola; una estática o manual congela los contactos.">
      <form onSubmit={(e) => void enviar(e)} className="contents">
        <ModalBody>
          <Field label="Nombre" required error={err('nombre')}>
            <TextInput value={nombre} onChange={(e) => setNombre(e.target.value)} placeholder="Ej: Restaurantes sin contactar" maxLength={160} autoFocus />
          </Field>
          <Field label="Descripción">
            <TextInput value={desc} onChange={(e) => setDesc(e.target.value)} placeholder="Opcional" maxLength={255} />
          </Field>
          <div>
            <span className={ETQ}>Tipo</span>
            <Segmented
              variant="pill"
              className="w-full"
              value={tipo}
              onChange={setTipo}
              aria-label="Tipo de lista"
              items={[
                { value: 'activa', label: 'Activa (dinámica)' },
                { value: 'estatica', label: 'Estática (congelada)' },
                { value: 'manual', label: 'Selección manual' },
              ]}
            />
          </div>
          {tipo !== 'manual' ? (
            <div className="rounded-xl border border-line px-4 py-3.5">
              <div className="mb-3 text-[11px] font-bold tracking-[.5px] text-muted uppercase">Condiciones</div>
              <div className="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                <div>
                  <span className={ETQ}>Sector</span>
                  <Select value={cond.sector} onChange={c('sector')} options={[cualquiera, ...(cat?.sectores ?? []).map((s) => ({ value: s, label: s }))]} aria-label="Sector" />
                </div>
                <div>
                  <span className={ETQ}>Origen</span>
                  <Select value={cond.origen} onChange={c('origen')} options={[cualquiera, ...(cat?.origenes ?? []).map((s) => ({ value: s, label: s }))]} aria-label="Origen" />
                </div>
                <div>
                  <span className={ETQ}>Embudo</span>
                  <Select value={cond.fase} onChange={c('fase')} options={[cualquiera, ...(cat?.fases ?? []).map((f) => ({ value: f.slug, label: f.nombre, color: f.color }))]} aria-label="Embudo" />
                </div>
                <div>
                  <span className={ETQ}>Servicio</span>
                  <Select value={cond.servicio} onChange={c('servicio')} options={[cualquiera, ...(cat?.servicios ?? []).map((s) => ({ value: s, label: s }))]} aria-label="Servicio" />
                </div>
                <div>
                  <span className={ETQ}>Propietario</span>
                  <Select value={cond.prop} onChange={c('prop')} options={[cualquiera, { value: 'sin', label: 'Sin propietario' }, ...equipo.map((x) => ({ value: String(x.id), label: x.username }))]} aria-label="Propietario" />
                </div>
                <div>
                  <span className={ETQ}>Estado contacto</span>
                  <Select
                    value={cond.quick}
                    onChange={c('quick')}
                    options={[cualquiera, { value: 'sin_contactar', label: 'Sin contactar' }, { value: 'act30', label: 'Sin actividad +30 días' }]}
                    aria-label="Estado del contacto"
                  />
                </div>
                <div>
                  <span className={ETQ}>Valor mínimo (€)</span>
                  <TextInput value={cond.vmin} onChange={(e) => c('vmin')(e.target.value)} placeholder="€ mín" inputMode="decimal" />
                </div>
                <div>
                  <span className={ETQ}>Valor máximo (€)</span>
                  <TextInput value={cond.vmax} onChange={(e) => c('vmax')(e.target.value)} placeholder="€ máx" inputMode="decimal" />
                </div>
                <div className="col-span-full">
                  <span className={ETQ}>Búsqueda de texto</span>
                  <TextInput value={cond.q} onChange={(e) => c('q')(e.target.value)} placeholder="Nombre, empresa o email…" />
                </div>
              </div>
            </div>
          ) : (
            <div className="rounded-xl border border-line">
              <div className="flex flex-wrap items-center gap-2 border-b border-line2 px-3 py-2.5">
                <TextInput size="sm" value={buscar} onChange={(e) => setBuscar(e.target.value)} placeholder="Buscar contacto…" leftIcon={<Search />} className="min-w-0 flex-1" aria-label="Buscar contacto" />
                <button type="button" onClick={() => setSel(new Set([...sel, ...visibles.map((x) => x.id)]))} className="text-[12px] font-semibold text-muted hover:text-ink">
                  Todos
                </button>
                <button type="button" onClick={() => setSel(new Set())} className="text-[12px] font-semibold text-muted hover:text-ink">
                  Ninguno
                </button>
                <span className="text-[12px] font-semibold text-ink">{sel.size} seleccionados</span>
              </div>
              <ul className="max-h-[300px] overflow-y-auto p-1.5">
                {visibles.map((x) => (
                  <li key={x.id}>
                    <label className="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-[13px] hover:bg-soft">
                      <Checkbox checked={sel.has(x.id)} onChange={(on) => setSel((s) => { const n = new Set(s); if (on) n.add(x.id); else n.delete(x.id); return n })} />
                      <span className="min-w-0 flex-1 truncate font-semibold text-ink-strong">{x.nombre}</span>
                      <span className="truncate text-[12px] text-muted">{x.empresa}</span>
                    </label>
                  </li>
                ))}
                {visibles.length === 0 && <li className="px-2 py-3 text-[12.5px] text-muted">Ningún contacto coincide.</li>}
              </ul>
              {err('ids') && <p className="px-3 pb-2.5 text-[12px] font-medium text-[#ef4444]">{err('ids')}</p>}
            </div>
          )}
          {error && !['nombre', 'ids'].includes(error.campo ?? '') && <p className="text-[12.5px] font-medium text-[#ef4444]">{error.msg}</p>}
        </ModalBody>
        <ModalFooter>
          <Button variant="ghost" onClick={cerrar}>
            Cancelar
          </Button>
          <Button type="submit" loading={acc.crear.isPending} loadingText="Creando…">
            Crear lista
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
