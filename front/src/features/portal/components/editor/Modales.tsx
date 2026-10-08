import { useId, useState, type ReactNode } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import Button from '../../../../shared/ui/Button'
import Checkbox from '../../../../shared/ui/Checkbox'
import Field from '../../../../shared/ui/Field'
import Modal, { ModalBody, ModalFooter } from '../../../../shared/ui/Modal'
import Notice from '../../../../shared/ui/Notice'
import Select from '../../../../shared/ui/Select'
import Switch from '../../../../shared/ui/Switch'
import { TextArea, TextInput } from '../../../../shared/ui/TextInput'
import type { BloqueEditable } from '../../contexto'
import { mesesCercanos } from '../../logica'
import type { ContenidoEditable, EditorRespuesta } from '../../schemas'

/* Los modales del editor en vivo (uno por bloque). Trabajan sobre una copia:
   «Aplicar» la devuelve y el portal se repinta; nada se guarda hasta «Guardar cambios». */

type Props = {
  bloque: BloqueEditable | null
  contenido: ContenidoEditable
  password: string
  editor: EditorRespuesta
  onAplicar: (c: ContenidoEditable, password?: string) => void
  onClose: () => void
}

const TITULOS: Record<BloqueEditable, string> = {
  identidad: 'Identidad del cliente',
  estado: 'Estado del proyecto',
  plan: 'Plan contratado',
  accesos: 'Accesos del cliente',
  informes: 'Informes mensuales',
  tareas: 'Tareas por mes',
}

export default function ModalesEditor(p: Props) {
  if (!p.bloque) return null
  return <ModalBloque key={p.bloque} {...p} bloque={p.bloque} />
}

function ModalBloque({ bloque, contenido, password, editor, onAplicar, onClose }: Props & { bloque: BloqueEditable }) {
  const [c, setC] = useState<ContenidoEditable>(() => structuredClone(contenido))
  const [pass, setPass] = useState(password)
  const set = <K extends keyof ContenidoEditable>(k: K, v: ContenidoEditable[K]) => setC((x) => ({ ...x, [k]: v }))
  return (
    <Modal open onClose={onClose} title={TITULOS[bloque]} size="xl">
      <ModalBody>
        {bloque === 'identidad' && <Identidad c={c} set={set} pass={pass} setPass={setPass} editor={editor} />}
        {bloque === 'estado' && <EstadoForm c={c} set={set} />}
        {bloque === 'plan' && <PlanForm c={c} set={set} />}
        {bloque === 'accesos' && <AccesosForm c={c} set={set} />}
        {bloque === 'informes' && <InformesForm c={c} set={set} aviso={editor.desde_tareas.informes} />}
        {bloque === 'tareas' && <TareasForm c={c} set={set} aviso={editor.desde_tareas.progreso} />}
      </ModalBody>
      <ModalFooter>
        <Button variant="ghost" onClick={onClose}>
          Cancelar
        </Button>
        <Button
          onClick={() => {
            onAplicar(c, pass)
            onClose()
          }}
        >
          Aplicar
        </Button>
      </ModalFooter>
    </Modal>
  )
}

type Set = <K extends keyof ContenidoEditable>(k: K, v: ContenidoEditable[K]) => void

/* Tarjeta numerada de una lista repetible («Fase 1»…) con su «Quitar». */
function Repetible({ titulo, onQuitar, children }: { titulo: string; onQuitar: () => void; children: ReactNode }) {
  return (
    <div className="rounded-xl border border-line bg-soft/50 p-3.5">
      <div className="mb-2.5 flex items-center justify-between">
        <span className="text-[12.5px] font-bold text-ink-strong">{titulo}</span>
        <button type="button" onClick={onQuitar} className="inline-flex items-center gap-1 text-[12px] text-label hover:text-danger">
          <Trash2 className="size-3.5" /> Quitar
        </button>
      </div>
      <div className="flex flex-col gap-2.5">{children}</div>
    </div>
  )
}

function Anadir({ onClick, children }: { onClick: () => void; children: ReactNode }) {
  return (
    <Button variant="subtle" size="sm" icon={<Plus />} onClick={onClick} className="self-start">
      {children}
    </Button>
  )
}

function quitar<T>(l: T[], i: number) {
  return l.filter((_, n) => n !== i)
}
function cambiar<T>(l: T[], i: number, v: Partial<T>) {
  return l.map((x, n) => (n === i ? { ...x, ...v } : x))
}

function Meses({ id }: { id: string }) {
  return (
    <datalist id={id}>
      {mesesCercanos(new Date()).map((m) => (
        <option key={m} value={m} />
      ))}
      <option value="General" />
    </datalist>
  )
}

function Identidad({ c, set, pass, setPass, editor }: { c: ContenidoEditable; set: Set; pass: string; setPass: (s: string) => void; editor: EditorRespuesta }) {
  const lista = useId()
  const servicios = c.servicios
  return (
    <>
      <p className="text-[13px] text-label">Cómo se presenta el portal y qué ve el cliente.</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Saludo" hint="«Hola, …». Vacío = el nombre del negocio.">
          <TextInput value={c.saludo} onChange={(e) => set('saludo', e.target.value)} maxLength={160} />
        </Field>
        <Field label="Iniciales">
          <TextInput value={c.iniciales} onChange={(e) => set('iniciales', e.target.value)} maxLength={4} />
        </Field>
        <Field label="Nombre del negocio" required>
          <TextInput value={c.name} onChange={(e) => set('name', e.target.value)} maxLength={160} />
        </Field>
        <Field label="Usuario (para entrar)" required>
          <TextInput value={c.username} onChange={(e) => set('username', e.target.value)} maxLength={80} autoComplete="off" />
        </Field>
        <Field label="Contraseña" hint="En blanco = no cambiar. Si la cambias, el cliente tendrá que volver a entrar.">
          <TextInput type="password" value={pass} onChange={(e) => setPass(e.target.value)} autoComplete="new-password" />
        </Field>
        <Field label="Mes actual" hint="El mes que abre el portal (mejor con año: «Octubre 2026»).">
          <TextInput value={c.actual} onChange={(e) => set('actual', e.target.value)} list={lista} maxLength={40} />
          <Meses id={lista} />
        </Field>
        <Field label="Tipo de cliente" hint="Qué secciones ve.">
          <Select
            value={c.tipo_id ?? 0}
            onChange={(v) => set('tipo_id', v || null)}
            options={[{ value: 0, label: 'Sin tipo (todas las secciones)' }, ...editor.tipos.map((t) => ({ value: t.id, label: t.nombre }))]}
          />
        </Field>
        <Field label="Panel de Looker Studio (URL de inserción, opcional)">
          <TextInput value={c.looker} onChange={(e) => set('looker', e.target.value)} placeholder="https://lookerstudio.google.com/embed/..." />
        </Field>
      </div>
      <Switch checked={c.conversiones} onChange={(v) => set('conversiones', v)} label="Mostrar Métricas y oportunidades (si no usas tipo)" />
      <div>
        <p className="mb-1 text-[13px] font-semibold text-ink-strong">Servicios contratados</p>
        <p className="mb-2 text-[12px] text-label">Desbloquean su vídeo en Método; el resto sale con candado.</p>
        <Switch
          checked={servicios === null}
          onChange={(v) => set('servicios', v ? null : [])}
          label="Todos (sin restringir)"
        />
        {servicios !== null && (
          <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
            {editor.servicios_opciones.map((s) => (
              <Checkbox
                key={s}
                label={s}
                checked={servicios.some((x) => x.toLowerCase() === s.toLowerCase())}
                onChange={(on) => set('servicios', on ? [...servicios, s] : servicios.filter((x) => x.toLowerCase() !== s.toLowerCase()))}
              />
            ))}
          </div>
        )}
      </div>
    </>
  )
}

function EstadoForm({ c, set }: { c: ContenidoEditable; set: Set }) {
  const e = c.estado
  const upd = (v: Partial<typeof e>) => set('estado', { ...e, ...v })
  return (
    <>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Etapa actual">
          <TextInput value={e.nombre} onChange={(x) => upd({ nombre: x.target.value })} placeholder="Crecimiento y captación de clientes" />
        </Field>
        <Field label="Etiqueta">
          <TextInput value={e.etiqueta} onChange={(x) => upd({ etiqueta: x.target.value })} placeholder="Etapa 3 de 4" />
        </Field>
      </div>
      <Field label="Lo siguiente">
        <TextArea rows={2} value={e.siguiente} onChange={(x) => upd({ siguiente: x.target.value })} />
      </Field>
      {e.fases.map((f, i) => (
        <Repetible key={i} titulo={`Fase ${i + 1}`} onQuitar={() => upd({ fases: quitar(e.fases, i) })}>
          <div className="grid gap-2.5 sm:grid-cols-2">
            <TextInput value={f.t} onChange={(x) => upd({ fases: cambiar(e.fases, i, { t: x.target.value }) })} placeholder="Nombre de la fase" />
            <TextInput value={f.s} onChange={(x) => upd({ fases: cambiar(e.fases, i, { s: x.target.value }) })} placeholder="Subtítulo (opcional)" />
          </div>
          <Select
            value={f.estado}
            onChange={(v) => upd({ fases: cambiar(e.fases, i, { estado: v }) })}
            options={[
              { value: 'done', label: '✓ Completada' },
              { value: 'now', label: '● En curso ahora' },
              { value: '', label: '○ Pendiente' },
            ]}
          />
        </Repetible>
      ))}
      <Anadir onClick={() => upd({ fases: [...e.fases, { t: '', s: '', estado: '' }] })}>Añadir fase</Anadir>
    </>
  )
}

function PlanForm({ c, set }: { c: ContenidoEditable; set: Set }) {
  const p = c.plan
  const upd = (v: Partial<typeof p>) => set('plan', { ...p, ...v })
  return (
    <>
      <Field label="Resumen">
        <TextArea rows={3} value={p.resumen} onChange={(x) => upd({ resumen: x.target.value })} />
      </Field>
      {p.items.map((it, i) => (
        <Repetible key={i} titulo={`Concepto ${i + 1}`} onQuitar={() => upd({ items: quitar(p.items, i) })}>
          <div className="grid gap-2.5 sm:grid-cols-[120px_1fr]">
            <TextInput value={it.n} onChange={(x) => upd({ items: cambiar(p.items, i, { n: x.target.value }) })} placeholder="Número (grande)" maxLength={20} />
            <TextInput value={it.t} onChange={(x) => upd({ items: cambiar(p.items, i, { t: x.target.value }) })} placeholder="Concepto" />
          </div>
        </Repetible>
      ))}
      <Anadir onClick={() => upd({ items: [...p.items, { n: '', t: '' }] })}>Añadir concepto</Anadir>
      {p.detalle.map((it, i) => (
        <Repetible key={i} titulo={`Apartado ${i + 1}`} onQuitar={() => upd({ detalle: quitar(p.detalle, i) })}>
          <TextInput value={it.h} onChange={(x) => upd({ detalle: cambiar(p.detalle, i, { h: x.target.value }) })} placeholder="Título del apartado" />
          <TextArea rows={2} value={it.p} onChange={(x) => upd({ detalle: cambiar(p.detalle, i, { p: x.target.value }) })} placeholder="Descripción" />
        </Repetible>
      ))}
      <Anadir onClick={() => upd({ detalle: [...p.detalle, { h: '', p: '' }] })}>Añadir apartado</Anadir>
    </>
  )
}

function AccesosForm({ c, set }: { c: ContenidoEditable; set: Set }) {
  const a = c.accesos
  return (
    <>
      {a.map((x, i) => (
        <Repetible key={i} titulo={`Acceso ${i + 1}`} onQuitar={() => set('accesos', quitar(a, i))}>
          <div className="grid gap-2.5 sm:grid-cols-2">
            <TextInput value={x.b} onChange={(e) => set('accesos', cambiar(a, i, { b: e.target.value }))} placeholder="Título" />
            <TextInput value={x.s} onChange={(e) => set('accesos', cambiar(a, i, { s: e.target.value }))} placeholder="Descripción" />
            <TextInput value={x.u} onChange={(e) => set('accesos', cambiar(a, i, { u: e.target.value }))} placeholder="https://…" />
            <Select
              value={x.tipo}
              onChange={(v) => set('accesos', cambiar(a, i, { tipo: v }))}
              options={[
                { value: 'figma', label: 'Figma' },
                { value: 'drive', label: 'Google Drive' },
                { value: 'web', label: 'Sitio web' },
                { value: 'looker', label: 'Looker Studio' },
                { value: 'generic', label: 'Otro' },
              ]}
            />
          </div>
        </Repetible>
      ))}
      <Anadir onClick={() => set('accesos', [...a, { b: '', s: '', u: '', tipo: 'generic' }])}>Añadir acceso</Anadir>
    </>
  )
}

function InformesForm({ c, set, aviso }: { c: ContenidoEditable; set: Set; aviso: boolean }) {
  const l = c.informes
  const lista = useId()
  return (
    <>
      <p className="text-[13px] text-label">Cada informe es el análisis de un mes. Lo último que añadas sale arriba en el portal.</p>
      {aviso && (
        <Notice tone="warn">Este cliente tiene una lista de informes en sus tareas: en cuanto se toque una tarea, los informes se vuelven a generar desde ahí y se pierde lo escrito aquí.</Notice>
      )}
      <Meses id={lista} />
      {l.map((x, i) => (
        <Repetible key={i} titulo={`Informe ${i + 1}`} onQuitar={() => set('informes', quitar(l, i))}>
          <div className="grid gap-2.5 sm:grid-cols-2">
            <TextInput value={x.mes} onChange={(e) => set('informes', cambiar(l, i, { mes: e.target.value }))} placeholder="Mes (Octubre 2026)" list={lista} />
            <TextInput value={x.titulo} onChange={(e) => set('informes', cambiar(l, i, { titulo: e.target.value }))} placeholder="Título" />
          </div>
          <TextInput value={x.url} onChange={(e) => set('informes', cambiar(l, i, { url: e.target.value }))} placeholder="Enlace a PDF (opcional)" />
          <TextArea rows={4} value={x.texto} onChange={(e) => set('informes', cambiar(l, i, { texto: e.target.value }))} placeholder="Análisis del mes" />
        </Repetible>
      ))}
      <Anadir onClick={() => set('informes', [...l, { mes: '', titulo: '', texto: '', url: '' }])}>Añadir informe</Anadir>
    </>
  )
}

function TareasForm({ c, set, aviso }: { c: ContenidoEditable; set: Set; aviso: boolean }) {
  const meses = c.tareas
  const [sel, setSel] = useState(0)
  const lista = useId()
  const m = meses[sel]
  const updMes = (v: Partial<(typeof meses)[number]>) => set('tareas', cambiar(meses, sel, v))
  return (
    <>
      {aviso && <Notice tone="warn">El progreso de este cliente se genera desde sus tareas visibles: al tocar una tarea se vuelve a generar y se pierde lo escrito aquí.</Notice>}
      <div className="flex flex-wrap gap-2">
        {meses.map((x, i) => (
          <button
            key={i}
            type="button"
            onClick={() => setSel(i)}
            className={`rounded-full px-3 py-1.5 text-[12.5px] font-semibold ${i === sel ? 'bg-accent text-accent-fg' : 'border border-line text-ink'}`}
          >
            {x.mes || 'Sin mes'}
          </button>
        ))}
        <Button
          variant="subtle"
          size="sm"
          icon={<Plus />}
          onClick={() => {
            set('tareas', [...meses, { mes: mesesCercanos(new Date(), 0, 0)[0], completado: [], pendiente: [] }])
            setSel(meses.length)
          }}
        >
          Mes
        </Button>
      </div>
      <Meses id={lista} />
      {m && (
        <>
          <div className="flex items-end gap-2">
            <Field label="Mes" className="flex-1">
              <TextInput value={m.mes} onChange={(e) => updMes({ mes: e.target.value })} list={lista} />
            </Field>
            <Button
              variant="ghost"
              icon={<Trash2 />}
              onClick={() => {
                set('tareas', quitar(meses, sel))
                setSel(0)
              }}
            >
              Quitar este mes
            </Button>
          </div>
          {(
            [
              ['completado', '✓ Completado (ya hecho)'],
              ['pendiente', '● En curso ahora'],
            ] as const
          ).map(([g, titulo]) => (
            <div key={g} className="flex flex-col gap-2.5">
              <p className="text-[13px] font-bold text-ink-strong">{titulo}</p>
              {m[g].map((t, i) => (
                <Repetible key={i} titulo={`Tarea ${i + 1}`} onQuitar={() => updMes({ [g]: quitar(m[g], i) })}>
                  <TextInput value={t.t} onChange={(e) => updMes({ [g]: cambiar(m[g], i, { t: e.target.value }) })} placeholder="Título de la tarea" />
                  <TextArea rows={2} value={t.d} onChange={(e) => updMes({ [g]: cambiar(m[g], i, { d: e.target.value }) })} placeholder="Explicación para el cliente" />
                </Repetible>
              ))}
              <Anadir onClick={() => updMes({ [g]: [...m[g], { t: '', d: '' }] })}>Añadir tarea</Anadir>
            </div>
          ))}
        </>
      )}
    </>
  )
}
