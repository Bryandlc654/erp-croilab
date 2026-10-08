import { useState } from 'react'
import { Building2, Check, Clock, Euro, FileText, Home, Mail, Percent, Plus, Trash2, User, UserCheck } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Field from '../../../shared/ui/Field'
import FormGrid, { FormZone } from '../../../shared/ui/FormGrid'
import IconButton from '../../../shared/ui/IconButton'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import Segmented from '../../../shared/ui/Segmented'
import Select from '../../../shared/ui/Select'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import { ApiError } from '../../../shared/api/client'
import { mensajeError, useBorrarEmisor, useEmisoresAjustes, useGuardarEmisores } from '../api'
import CargandoFin from '../components/Cargando'
import { leer } from '../lib/importes'
import { usePermisosFin } from '../lib/permisos'
import type { EmisorAjuste, Fiscal } from '../schemas'

type Fila = { local: string; clave: string; nombre: string; prefijo: string; fiscal: Fiscal; iva: string; irpf: string; uso: EmisorAjuste['uso'] | null }

const FISCAL_VACIO: Fiscal = { name: '', nif: '', dir: '', email: '', phone: '', banco: '', iban: '', venc: '' }

/* Ajustes › Facturación (settings.php › Facturación): quién factura, con qué
   datos fiscales, con qué prefijo de serie y qué valores por defecto. */
export default function AjustesFacturacionPage() {
  const p = usePermisosFin()
  const { data, error, isLoading } = useEmisoresAjustes(p.verEmisores)
  if (!p.verEmisores) return <Notice tone="error">Los datos fiscales propios (NIF, IBAN) solo los ve quien gestiona la facturación.</Notice>
  if (error) return <Notice tone="error">{mensajeError(error, 'No se han podido cargar los ajustes.')}</Notice>
  if (isLoading || !data) return <CargandoFin />
  return <Editor key={data.items.map((i) => i.clave).join('|')} items={data.items} porDefecto={data.por_defecto} anio={data.anio} />
}

function aFila(e: EmisorAjuste): Fila {
  return { local: e.clave, clave: e.clave, nombre: e.nombre, prefijo: e.prefijo, fiscal: { ...e.fiscal }, iva: e.iva, irpf: e.irpf, uso: e.uso }
}

let nuevos = 0

function Editor({ items, porDefecto, anio }: { items: EmisorAjuste[]; porDefecto: string; anio: number }) {
  const p = usePermisosFin()
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const guardar = useGuardarEmisores()
  const borrar = useBorrarEmisor()
  const [filas, setFilas] = useState<Fila[]>(() => items.map(aFila))
  const [sel, setSel] = useState(items[0]?.clave ?? '')
  const [def, setDef] = useState(porDefecto)
  const [sucio, setSucio] = useState(false)
  const [err, setErr] = useState<{ campo: string; msg: string } | null>(null)
  useUnsavedGuard(sucio)
  const actual = filas.find((f) => f.local === sel) ?? filas[0]
  const ed = p.emisores

  function cambiar(cambios: Partial<Fila>) {
    setFilas((fs) => fs.map((f) => (f.local === actual.local ? { ...f, ...cambios } : f)))
    setSucio(true)
    setErr(null)
  }
  const fiscal = (k: keyof Fiscal, v: string) => cambiar({ fiscal: { ...actual.fiscal, [k]: v } })

  function anadir() {
    nuevos += 1
    const f: Fila = { local: `nuevo-${nuevos}`, clave: '', nombre: '', prefijo: '', fiscal: { ...FISCAL_VACIO }, iva: '21', irpf: '0', uso: null }
    setFilas((fs) => [...fs, f])
    setSel(f.local)
    setSucio(true)
  }

  async function quitar() {
    if (!actual.clave) {
      setFilas((fs) => fs.filter((f) => f.local !== actual.local))
      setSel(filas[0].local)
      return
    }
    if (!(await confirm({ title: `¿Quitar a ${actual.nombre}?`, message: 'Deja de poder facturar. Solo se puede si no tiene histórico.', danger: true, okLabel: 'Quitar' }))) return
    try {
      await borrar.mutateAsync(actual.clave)
      aviso('Emisor quitado.')
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido quitar.'), { tipo: 'error' })
    }
  }

  async function enviar() {
    try {
      await guardar.mutateAsync({
        emisores: filas.map((f) => ({ clave: f.clave, nombre: f.nombre, prefijo: f.prefijo, fiscal: f.fiscal, iva: leer(f.iva) ?? f.iva, irpf: leer(f.irpf) ?? f.irpf })),
        por_defecto: def,
      })
      setSucio(false)
      aviso('Facturación guardada.')
    } catch (e) {
      if (e instanceof ApiError && e.campo) {
        const m = /^emisores\.(\d+)\.?(.*)$/.exec(e.campo)
        if (m) {
          setSel(filas[Number(m[1])]?.local ?? sel)
          setErr({ campo: m[2], msg: e.message })
        }
      }
      aviso(mensajeError(e, 'No se ha podido guardar.'), { tipo: 'error' })
    }
  }

  const error = (c: string) => (err?.campo === c ? err.msg : undefined)
  const pref = (actual.prefijo || (actual.nombre ? actual.nombre[0].toUpperCase() : 'F')).toUpperCase()

  return (
    <div className="max-w-[1180px]">
      <PageHeader title="Facturación" lead="Quién factura, con qué datos y con qué numeración. Cada autónomo lleva su contabilidad aparte." />
      {!ed && <Notice tone="info">Puedes ver estos datos, pero no cambiarlos.</Notice>}
      <Segmented
        className="mb-5"
        value={actual?.local ?? ''}
        onChange={setSel}
        items={filas.map((f) => ({ value: f.local, label: f.nombre || 'Nuevo', icon: <User /> }))}
        aria-label="Emisor"
      >
        {ed && (
          <button type="button" onClick={anadir} className="inline-flex items-center gap-1.5 rounded-lg px-3.5 py-[7px] text-[13.5px] font-semibold text-[#6b7280] hover:bg-soft hover:text-ink">
            <Plus className="size-[15px]" /> Añadir
          </button>
        )}
      </Segmented>
      {actual && (
        <section className="mb-6 rounded-2xl border border-line bg-card px-[26px] py-6 max-sm:px-4">
          <FormGrid>
            <Field label="Nombre en el ERP" icon={<User />} span={6} error={error('nombre')}>
              <TextInput value={actual.nombre} maxLength={60} disabled={!ed} onChange={(e) => cambiar({ nombre: e.target.value })} />
            </Field>
            <Field label="Prefijo" icon={<FileText />} span={2} error={error('prefijo')}>
              <TextInput value={actual.prefijo} maxLength={10} disabled={!ed} placeholder={pref} onChange={(e) => cambiar({ prefijo: e.target.value.toUpperCase() })} />
            </Field>
            <div className="col-span-4 flex items-end gap-2 pb-2.5 max-[700px]:col-span-full">
              <span className="rounded-md bg-chip px-2 py-1 text-[11.5px] font-semibold text-[#5c616b] dark:text-ink">
                {actual.uso?.facturas ?? 0} factura{actual.uso?.facturas === 1 ? '' : 's'}
              </span>
              <span className="text-[12.5px] text-muted">
                Nº {pref}-{anio}-001
              </span>
              {ed && filas.length > 1 && <IconButton className="ml-auto" label="Quitar emisor" tone="danger" icon={<Trash2 />} onClick={() => void quitar()} />}
            </div>
            <FormZone title="Datos fiscales · salen impresos en la factura" />
            <Field label="Nombre y apellidos / razón social" icon={<User />} span={8}>
              <TextInput value={actual.fiscal.name} disabled={!ed} placeholder={actual.nombre} onChange={(e) => fiscal('name', e.target.value)} />
            </Field>
            <Field label="NIF / DNI" icon={<FileText />} span={4} hint="Sin NIF no se puede emitir.">
              <TextInput value={actual.fiscal.nif} disabled={!ed} placeholder="12345678Z" onChange={(e) => fiscal('nif', e.target.value)} />
            </Field>
            <Field label="Dirección fiscal" icon={<Home />} span={12}>
              <TextInput value={actual.fiscal.dir} disabled={!ed} placeholder="Calle, número, código postal y ciudad" onChange={(e) => fiscal('dir', e.target.value)} />
            </Field>
            <Field label="Email" icon={<Mail />} span={8} error={error('email')}>
              <TextInput value={actual.fiscal.email} type="email" disabled={!ed} onChange={(e) => fiscal('email', e.target.value)} />
            </Field>
            <Field label="Teléfono" span={4}>
              <TextInput value={actual.fiscal.phone} type="tel" disabled={!ed} onChange={(e) => fiscal('phone', e.target.value)} />
            </Field>
            <FormZone title="Dónde te pagan" />
            <Field label="IBAN" icon={<Euro />} span={8} error={error('iban')}>
              <TextInput value={actual.fiscal.iban} disabled={!ed} placeholder="ES00 0000 0000 0000 0000 0000" onChange={(e) => fiscal('iban', e.target.value)} />
            </Field>
            <Field label="Banco" icon={<Building2 />} span={4}>
              <TextInput value={actual.fiscal.banco} disabled={!ed} placeholder="BBVA" onChange={(e) => fiscal('banco', e.target.value)} />
            </Field>
            <FormZone title="Se rellena solo al crear una factura" />
            <Field label="IVA" icon={<Percent />} span={3} error={error('iva')}>
              <TextInput value={actual.iva} inputMode="decimal" unit="%" disabled={!ed} onChange={(e) => cambiar({ iva: e.target.value })} />
            </Field>
            <Field label="IRPF" icon={<Percent />} span={3} error={error('irpf')}>
              <TextInput value={actual.irpf} inputMode="decimal" unit="%" disabled={!ed} onChange={(e) => cambiar({ irpf: e.target.value })} />
            </Field>
            <Field label="Vencimiento" icon={<Clock />} span={6}>
              <TextInput value={actual.fiscal.venc} disabled={!ed} placeholder="Contado" onChange={(e) => fiscal('venc', e.target.value)} />
            </Field>
          </FormGrid>
          {ed && (
            <div className="mt-6 flex flex-wrap items-end gap-4 border-t border-line2 pt-5">
              <Button icon={<Check />} onClick={() => void enviar()} loading={guardar.isPending} loadingText="Guardando…" disabled={!sucio}>
                Guardar facturación
              </Button>
              <Field label="Por defecto" icon={<UserCheck />} className="w-[200px]">
                <Select
                  value={def}
                  onChange={(v) => {
                    setDef(v)
                    setSucio(true)
                  }}
                  options={filas.filter((f) => f.clave).map((f) => ({ value: f.clave, label: f.nombre }))}
                />
              </Field>
              <p className="flex-1 pb-2 text-[12.5px] text-muted">Cambiar el nombre no toca ninguna factura ya emitida. El prefijo tampoco: no lo cambies a mitad de año (empezaría otra serie).</p>
            </div>
          )}
        </section>
      )}
      <section className="rounded-2xl border border-line bg-card px-[26px] py-6 max-sm:px-4">
        <h3 className="text-[16px] font-semibold text-ink-strong">Datos fiscales de los clientes</h3>
        <p className="mt-1 text-[13px] text-muted">La razón social, el NIF y la dirección de cada cliente, que se copian solos a sus facturas.</p>
        <Button className="mt-4" variant="ghost" size="sm" icon={<Euro />} to="/finanzas/clientes?vista=datos">
          Abrir facturación de clientes
        </Button>
      </section>
    </div>
  )
}
