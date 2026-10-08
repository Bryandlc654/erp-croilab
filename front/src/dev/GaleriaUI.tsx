import { useMemo, useState, type ReactNode } from 'react'
import { Bell, Building2, CalendarDays, Copy, Download, FileText, Inbox, LayoutGrid, Mail, Pencil, Plus, Search, Tag, Trash2, UserPlus, Users } from 'lucide-react'
import Button from '../shared/ui/Button'
import IconButton from '../shared/ui/IconButton'
import { TextArea, TextInput } from '../shared/ui/TextInput'
import Field from '../shared/ui/Field'
import FormGrid, { FormZone } from '../shared/ui/FormGrid'
import Select from '../shared/ui/Select'
import { DateInput, DatePicker } from '../shared/ui/DatePicker'
import Switch from '../shared/ui/Switch'
import Checkbox from '../shared/ui/Checkbox'
import Segmented from '../shared/ui/Segmented'
import Chip from '../shared/ui/Chip'
import StatusPill from '../shared/ui/StatusPill'
import PriorityFlag from '../shared/ui/PriorityFlag'
import CountBadge from '../shared/ui/CountBadge'
import AvatarStack from '../shared/ui/AvatarStack'
import Avatar from '../shared/ui/Avatar'
import PresenceDot from '../shared/ui/PresenceDot'
import ProfileHoverCard from '../shared/ui/ProfileHoverCard'
import EstadoCirculo from '../shared/ui/EstadoCirculo'
import Card, { CardHeader, Panel } from '../shared/ui/Card'
import KpiTile, { KpiGrid } from '../shared/ui/KpiTile'
import EmptyState from '../shared/ui/EmptyState'
import Notice from '../shared/ui/Notice'
import Breadcrumbs from '../shared/ui/Breadcrumbs'
import PageHeader from '../shared/ui/PageHeader'
import DataTable, { type Columna } from '../shared/ui/DataTable'
import GroupCard, { GridRow, GridRows } from '../shared/ui/GroupCard'
import InlineAddRow from '../shared/ui/InlineAddRow'
import BulkBar from '../shared/ui/BulkBar'
import FilterBar, { SavedViewsMenu } from '../shared/ui/FilterBar'
import Modal, { ModalBody, ModalFooter } from '../shared/ui/Modal'
import Drawer from '../shared/ui/Drawer'
import CommandPalette, { type BuscarPaleta } from '../shared/ui/CommandPalette'
import SortableList, { RowGrip } from '../shared/ui/SortableList'
import { PhaseBar, ProgressBar, ProgressPill } from '../shared/ui/Progress'
import DateTile from '../shared/ui/DateTile'
import ListRow from '../shared/ui/ListRow'
import { SavedIndicator, StickySaveBar } from '../shared/ui/SaveBar'
import DangerZone from '../shared/ui/DangerZone'
import QuickActionCard from '../shared/ui/QuickActionCard'
import { Accordion } from '../shared/ui/Collapse'
import Menu, { MenuItem, MenuLabel, MenuPanel, MenuSeparator } from '../shared/ui/Menu'
import { useContextMenu } from '../shared/ui/useContextMenu'
import PersonPicker from '../shared/ui/PersonPicker'
import NotificationStack, { type AvisoEnVivo } from '../shared/ui/NotificationStack'
import RailFlyout from '../shared/ui/RailFlyout'
import { useToast } from '../shared/ui/useToast'
import { useConfirm } from '../shared/ui/useConfirm'
import { ESTADOS, ESTADOS_FACTURA, FASES_CRM, ORDEN_ESTADOS } from '../shared/lib/paletas'
import { eur, eur0, eurk, fechaCorta, fechaLarga, horaRelativa, mesLabel } from '../shared/lib/formato'
import { tonoVencimiento } from '../shared/lib/fechas'
import {
  ActivityTimeline,
  AttachmentList,
  ChartCard,
  ChartGrid,
  Checklist,
  CommentComposer,
  CommentThread,
  EmojiButton,
  FileDropzone,
  KanbanBoard,
  KanbanCard,
  QuickReactions,
  ReactionChips,
  RichTextEditor,
  RichTextView,
  WindowDropOverlay,
  type Adjunto,
  type Comentario,
  type KanbanColumnData,
  type Reaccion,
} from '../shared/ui/rich'
import { moverTarjeta } from '../shared/lib/kanban'
import { marcar, type ItemChecklist } from '../shared/lib/checklist'

/* Galería de componentes (solo desarrollo, /dev/ui): cada pieza en claro y en
   oscuro, lado a lado, para revisar el aspecto sin montar pantallas. */

const PERSONAS = [
  { id: 1, username: 'laura', foto: null },
  { id: 2, username: 'bdelacruz654', foto: null },
  { id: 3, username: 'víctor', foto: null },
  { id: 4, username: 'gabi', foto: null },
  { id: 5, username: 'marta', foto: null },
]

type Fila = { id: number; nombre: string; empresa: string; fase: string; valor: number; fecha: string }
const FILAS: Fila[] = [
  { id: 1, nombre: 'Clínica Dental Sur', empresa: 'Salud', fase: 'Propuesta enviada', valor: 2400, fecha: '2026-10-02' },
  { id: 2, nombre: 'Bodegas Ribera', empresa: 'Vino', fase: 'Negociación', valor: 12800, fecha: '2026-09-18' },
  { id: 3, nombre: 'Aeternum', empresa: 'Software', fase: 'Contrato firmado', valor: 980, fecha: '2026-10-07' },
]

const COLUMNAS: Columna<Fila>[] = [
  { key: 'nombre', header: 'Nombre', sortable: true, sortValue: (f) => f.nombre, sticky: true, render: (f) => <b className="font-semibold text-ink-strong">{f.nombre}</b> },
  { key: 'fase', header: 'Fase', sortable: true, sortValue: (f) => f.fase, render: (f) => <StatusPill variant="solid" size="sm" color={FASES_CRM[f.fase]} label={f.fase} /> },
  { key: 'valor', header: 'Valor', align: 'right', sortable: true, sortValue: (f) => f.valor, render: (f) => eur0(f.valor) },
  { key: 'fecha', header: 'Próxima acción', sortable: true, sortValue: (f) => f.fecha, render: (f) => fechaCorta(f.fecha) },
]

const buscarDemo: BuscarPaleta = async (q) => {
  await new Promise((r) => setTimeout(r, 120))
  const n = q.toLowerCase()
  const clientes = FILAS.filter((f) => f.nombre.toLowerCase().includes(n)).map((f) => ({ id: `c${f.id}`, titulo: f.nombre, subtitulo: f.empresa, href: '/dev/ui', icono: <Building2 /> }))
  return clientes.length ? [{ titulo: 'Clientes', resultados: clientes }] : []
}

function Seccion({ titulo, children }: { titulo: string; children: ReactNode }) {
  return (
    <section className="mb-8">
      <h2 className="mb-3 border-b border-line pb-1.5 text-[11px] font-bold tracking-[.7px] text-label uppercase">{titulo}</h2>
      <div className="flex flex-col gap-3">{children}</div>
    </section>
  )
}

const Fila_ = ({ children }: { children: ReactNode }) => <div className="flex flex-wrap items-center gap-2.5">{children}</div>

function Muestra() {
  const { aviso } = useToast()
  const { confirm, prompt, alert } = useConfirm()
  const [seg, setSeg] = useState<'todos' | 'mios' | 'equipo'>('todos')
  const [pill, setPill] = useState<'mes' | 'semana'>('mes')
  const [sel, setSel] = useState<string | null>('en proceso')
  const [fase, setFase] = useState<string | null>(null)
  const [fecha, setFecha] = useState<string | null>('2026-10-08')
  const [sw, setSw] = useState(true)
  const [sw2, setSw2] = useState(false)
  const [chk, setChk] = useState(true)
  const [seleccion, setSeleccion] = useState<(string | number)[]>([2])
  const [plegado, setPlegado] = useState(false)
  const [orden, setOrden] = useState(['Diseño', 'Copy', 'Desarrollo', 'Revisión'])
  const [modal, setModal] = useState(false)
  const [drawer, setDrawer] = useState(false)
  const [paleta, setPaleta] = useState(false)
  const [lote, setLote] = useState(0)
  const [busca, setBusca] = useState('')
  const [quick, setQuick] = useState('todos')
  const [resp, setResp] = useState<number | null>(1)
  const [asig, setAsig] = useState<number[]>([1, 3])
  const [avisos, setAvisos] = useState<AvisoEnVivo[]>([])
  const [fly, setFly] = useState(false)
  const [dirty, setDirty] = useState(true)
  const [guardado, setGuardado] = useState<number | null>(null)
  const [cal, setCal] = useState(false)
  const [calAncla, setCalAncla] = useState<HTMLButtonElement | null>(null)
  const cm = useContextMenu()

  return (
    <div>
      <Seccion titulo="Cabecera de página y migas">
        <PageHeader
          crumbs={[{ label: 'Clientes', to: '/dev/ui' }, { label: 'Aeternum' }]}
          title="Clientes en alta"
          lead="12 clientes a la vista."
          actions={
            <Button icon={<Plus />} size="md">
              Nuevo cliente
            </Button>
          }
        />
        <Breadcrumbs items={[{ label: 'Facturas', to: '/dev/ui' }, { label: 'Ingresos', to: '/dev/ui' }, { label: 'Octubre 2026' }]} />
      </Seccion>

      <Seccion titulo="Botones">
        <Fila_>
          <Button icon={<Plus />}>Primario</Button>
          <Button variant="ghost">Secundario</Button>
          <Button variant="danger" icon={<Trash2 />}>
            Eliminar
          </Button>
          <Button variant="subtle">Cancelar</Button>
          <Button variant="link">Enlace</Button>
        </Fila_>
        <Fila_>
          <Button size="sm">Pequeño</Button>
          <Button size="sm" variant="ghost" icon={<Download />}>
            Exportar
          </Button>
          <Button loading loadingText="Guardando…">
            Guardar
          </Button>
          <Button disabled>Desactivado</Button>
          <IconButton label="Editar" icon={<Pencil />} />
          <IconButton label="Borrar" icon={<Trash2 />} tone="danger" />
          <IconButton label="Copiar" icon={<Copy />} size={26} />
        </Fila_>
      </Seccion>

      <Seccion titulo="Formulario: Field, FormGrid, TextInput, Select, DateInput">
        <Card>
          <FormGrid>
            <FormZone title="Identidad" />
            <Field label="Nombre de la agencia" icon={<Building2 />} required>
              <TextInput defaultValue="Croilab" />
            </Field>
            <Field label="CIF / NIF" span={3} hint="Aparece en las facturas.">
              <TextInput placeholder="B12345678" />
            </Field>
            <Field label="IVA" span={3}>
              <TextInput defaultValue="21" unit="%" />
            </Field>
            <FormZone title="Contacto" />
            <Field label="Email" icon={<Mail />} span={4} error="El email no es válido.">
              <TextInput defaultValue="hola@" leftIcon={<Mail />} />
            </Field>
            <Field label="Estado" span={4}>
              <Select value={sel} onChange={setSel} options={ORDEN_ESTADOS.map((e) => ({ value: e, label: ESTADOS[e].label, color: ESTADOS[e].color }))} />
            </Field>
            <Field label="Fecha límite" span={4}>
              <DateInput value={fecha} onChange={setFecha} tone={(iso) => tonoVencimiento(iso)} />
            </Field>
            <Field label="Fase (con buscador)" span={6}>
              <Select value={fase} onChange={setFase} searchable placeholder="Elige una fase" options={Object.entries(FASES_CRM).map(([k, c]) => ({ value: k, label: k, color: c }))} />
            </Field>
            <Field label="Notas" span={6}>
              <TextArea placeholder="Escribe una nota…" />
            </Field>
          </FormGrid>
        </Card>
        <Fila_>
          <Select variant="mini" value={sel} onChange={setSel} options={ORDEN_ESTADOS.map((e) => ({ value: e, label: ESTADOS[e].label }))} aria-label="Filtro" />
          <div className="w-40">
            <Select variant="inline" value={sel} onChange={setSel} options={ORDEN_ESTADOS.map((e) => ({ value: e, label: ESTADOS[e].label }))} aria-label="En línea" />
          </div>
          <div className="w-32">
            <DateInput variant="inline" value={fecha} onChange={setFecha} aria-label="Fecha en línea" />
          </div>
          <button ref={setCalAncla} type="button" onClick={() => setCal(true)} className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-[12.5px] text-muted hover:bg-soft">
            <CalendarDays className="size-3.5" /> {fechaCorta(fecha, 'Sin fecha')}
          </button>
          <DatePicker open={cal} onClose={() => setCal(false)} anchor={{ current: calAncla }} value={fecha} onChange={setFecha} />
        </Fila_>
      </Seccion>

      <Seccion titulo="Interruptores y casillas">
        <Fila_>
          <Switch checked={sw} onChange={setSw} aria-label="Activo" />
          <Switch checked={sw} onChange={setSw} tone="ok" aria-label="Funcionando" />
          <Switch checked={false} onChange={() => {}} saving aria-label="Guardando" />
          <Checkbox checked={chk} onChange={setChk} label="Recordarme" />
          <Checkbox checked={false} indeterminate onChange={() => {}} aria-label="Algunos" />
        </Fila_>
        <Switch checked={sw2} onChange={setSw2} label="Avisos por email" description="Te escribimos cuando te asignan una tarea." rowVariant="box" />
      </Seccion>

      <Seccion titulo="Segmentados">
        <Segmented
          value={seg}
          onChange={setSeg}
          aria-label="Vista"
          items={[
            { value: 'todos', label: 'Todas', icon: <Inbox />, count: 24 },
            { value: 'mios', label: 'Mías', count: 5 },
            { value: 'equipo', label: 'Equipo' },
          ]}
        />
        <Fila_>
          <Segmented variant="pill" value={pill} onChange={setPill} items={[{ value: 'mes', label: 'Mes' }, { value: 'semana', label: 'Semana' }]} aria-label="Periodo" />
        </Fila_>
        <Segmented variant="underline" value={seg} onChange={setSeg} items={[{ value: 'todos', label: 'Resumen' }, { value: 'mios', label: 'Actividad', count: 3 }, { value: 'equipo', label: 'Ajustes' }]} aria-label="Pestañas" />
      </Seccion>

      <Seccion titulo="Chips, píldoras, prioridad, contadores">
        <Fila_>
          <Chip>Etiqueta</Chip>
          <Chip variant="data">
            Total <b>1.234 €</b>
          </Chip>
          <Chip variant="pick" on onClick={() => {}}>
            Todos
          </Chip>
          <Chip variant="pick" onClick={() => {}}>
            Sin contactar
          </Chip>
          <Chip variant="act" icon={<Tag />} onClick={() => {}}>
            Etiquetar
          </Chip>
          <Chip variant="filter" onRemove={() => {}}>
            Fase: Negociación
          </Chip>
        </Fila_>
        <Fila_>
          <StatusPill variant="solid" color="#c2410c" label="Negociación" chevron onClick={() => {}} />
          {Object.entries(ESTADOS_FACTURA).map(([k, e]) => (
            <StatusPill key={k} color={e.color} label={e.label} dot />
          ))}
          <StatusPill variant="neutral" color="#2563eb" label="En proceso" />
        </Fila_>
        <Fila_>
          {[0, 1, 2, 3, 4].map((p) => (
            <PriorityFlag key={p} value={p} />
          ))}
          <PriorityFlag value={4} palette="vivid" />
          <CountBadge n={3} />
          <CountBadge n={120} />
          <CountBadge n={7} tone="neutral" />
          <CountBadge n={2} tone="green" />
          <CountBadge n={4} tone="dark" />
          <CountBadge n={1} tone="amber" />
          <span className="rounded-lg bg-[#0f1012] p-2">
            <CountBadge n={12} max={9} tone="rail" />
          </span>
        </Fila_>
      </Seccion>

      <Seccion titulo="Personas: avatares, presencia, tarjeta de perfil, selector">
        <Fila_>
          <AvatarStack people={PERSONAS} />
          <AvatarStack people={PERSONAS.slice(0, 2)} size={24} />
          <AvatarStack people={[]} emptyLabel="Asignar" size={24} />
          <span className="relative">
            <Avatar nombre="laura" size={32} />
            <PresenceDot state="online" className="absolute -right-0.5 -bottom-0.5" size={11} />
          </span>
          <PresenceDot state="idle" />
          <PresenceDot state="offline" />
          <ProfileHoverCard persona={{ id: 1, username: 'laura', rol: 'Diseño', email: 'laura@croilab.com', presencia: 'online', horaLocal: '10:42 hora local', equipo: 'Croilab' }} onChat={() => {}}>
            <span className="text-[13px] font-semibold text-ink underline decoration-dotted" tabIndex={0}>
              Pasa por encima de «laura»
            </span>
          </ProfileHoverCard>
        </Fila_>
        <Fila_>
          <PersonPicker label="Responsable" people={PERSONAS} value={resp} onChange={setResp} allowNone meId={2} />
          <PersonPicker label="Asignados" multiple people={PERSONAS} value={asig} onChange={setAsig} />
        </Fila_>
      </Seccion>

      <Seccion titulo="Estados de tarea">
        <Fila_>
          {ORDEN_ESTADOS.map((e) => (
            <span key={e} className="inline-flex items-center gap-1.5 text-[13px] text-ink">
              <EstadoCirculo estado={e} /> {ESTADOS[e].label}
            </span>
          ))}
        </Fila_>
      </Seccion>

      <Seccion titulo="Tarjetas, panel y KPIs">
        <KpiGrid cols={3}>
          <KpiTile label="Ha entrado" value={eur0(12480)} sub="Cobrado en cuenta · 8 facturas" icon={<FileText />} href="/dev/ui" delta={{ value: '12%', dir: 'up' }} />
          <KpiTile label="Pendiente" value={eurk(42350)} sub="3 vencidas" delta={{ value: '4%', dir: 'down' }} />
          <KpiTile label="Clientes" value="128" sub="+4 este mes" icon={<Users />} />
        </KpiGrid>
        <div className="grid grid-cols-2 gap-5">
          <Card>
            <CardHeader title="Próximas reuniones" icon={<CalendarDays />} action={<a href="#reu">Ver todo</a>} />
            <ListRow leading={<DateTile date="2026-10-09" />} title="Kick-off Bodegas Ribera" subtitle="10:00 · Google Meet" onClick={() => {}} />
            <ListRow leading={<DateTile date="2026-10-01" tone="past" />} title="Revisión SEO" subtitle="Vencida" right="2 h" />
            <ListRow icon={<FileText />} iconColor="#12a150" title="Factura 2026-041" subtitle="Pagada" right={eur(1210)} actions={<IconButton label="Más" icon={<Pencil />} size={26} />} />
          </Card>
          <Panel title="Avance" icon={<LayoutGrid />}>
            <div className="flex flex-col gap-3">
              <ProgressBar value={62} label="Avance" />
              <PhaseBar total={5} current={3} label="Fases" />
              <span>
                <ProgressPill done={3} total={5} /> <ProgressPill done={5} total={5} />
              </span>
              <p className="text-[12.5px] text-muted">
                {mesLabel('2026-03')} · {fechaLarga('2026-10-08')} · {horaRelativa('2026-10-08 09:10', new Date(2026, 9, 8, 9, 15))}
              </p>
            </div>
          </Panel>
        </div>
        <div className="grid grid-cols-2 gap-5">
          <QuickActionCard icon={<Users />} color="#5e5ce6" title="Clientes" description="Altas, fichas y portal." to="/dev/ui" />
          <QuickActionCard icon={<FileText />} color="#34c759" title="Facturas" description="Emitir y cobrar." to="/dev/ui" />
        </div>
      </Seccion>

      <Seccion titulo="Avisos y estados vacíos">
        <Notice tone="ok">Guardado correctamente.</Notice>
        <Notice tone="error" title="No se ha podido guardar">
          Recarga la página.
        </Notice>
        <Notice tone="warn">Conecta Google Calendar para ver tus reuniones.</Notice>
        <Notice tone="info">Los cambios se aplican al portal del cliente.</Notice>
        <EmptyState icon={<Users />} title="Aún no hay clientes" text="Crea el primero para montar su portal, sus tareas y su facturación." actions={<Button icon={<Plus />}>Nuevo cliente</Button>} />
        <EmptyState variant="dashed" title="Aún no hay agencias colaboradoras" />
        <EmptyState variant="compact" icon={<CalendarDays />} title="Nada para hoy" text="Disfruta del día." />
      </Seccion>

      <Seccion titulo="Filtros, tabla y barra de lote">
        <FilterBar
          search={busca}
          onSearch={setBusca}
          searchPlaceholder="Buscar nombre, empresa, email…"
          filtersCount={2}
          panel={
            <>
              <Field label="Fase">
                <Select value={fase} onChange={setFase} options={Object.keys(FASES_CRM).map((k) => ({ value: k, label: k }))} />
              </Field>
              <Field label="Responsable">
                <Select value={null} onChange={() => {}} options={PERSONAS.map((p) => ({ value: p.id, label: p.username }))} />
              </Field>
            </>
          }
          onClearFilters={() => {}}
          quick={{ items: [{ value: 'todos', label: 'Todos' }, { value: 'sin', label: 'Sin contactar' }, { value: 'venc', label: 'Acción vencida' }], value: quick, onChange: setQuick }}
          active={[{ key: 'f', label: 'Fase: Negociación', onRemove: () => {} }, { key: 'r', label: 'Responsable: laura', onRemove: () => {} }]}
          right={<SavedViewsMenu views={[{ id: '1', label: 'Mis leads calientes' }]} onApply={() => {}} onSave={() => {}} />}
        />
        <DataTable
          aria-label="Contactos"
          columns={COLUMNAS}
          rows={FILAS}
          getRowId={(f) => f.id}
          selectable
          selected={seleccion}
          onSelect={setSeleccion}
          defaultSort={{ key: 'valor', dir: 'desc' }}
          onRowClick={() => {}}
          rowActions={() => <IconButton label="Más acciones" icon={<Pencil />} size={26} />}
          mobileRow={(f) => (
            <span>
              <b className="block text-[15px]">{f.nombre}</b>
              <span className="text-[12.5px] text-muted">
                {f.empresa} · {f.fase}
              </span>
            </span>
          )}
          footer={<InlineAddRow placeholder="Añadir contacto…" onAdd={() => {}} />}
        />
        <Fila_>
          <Button size="sm" variant="ghost" onClick={() => setLote(lote ? 0 : 3)}>
            {lote ? 'Ocultar' : 'Mostrar'} barra de lote
          </Button>
        </Fila_>
        <BulkBar
          count={lote}
          onClear={() => setLote(0)}
          actions={[
            {
              label: 'Asignar',
              icon: <UserPlus />,
              menu: () =>
                PERSONAS.map((p) => (
                  <MenuItem key={p.id} onSelect={() => {}}>
                    <Avatar nombre={p.username} size={20} /> {p.username}
                  </MenuItem>
                )),
            },
            { label: 'Exportar CSV', icon: <Download />, onClick: () => {} },
            { label: 'Eliminar', icon: <Trash2 />, danger: true, separada: true, onClick: () => {} },
          ]}
        />
      </Seccion>

      <Seccion titulo="Grupo plegable, filas en rejilla y fila de añadir">
        <GroupCard title={<span className="inline-flex items-center gap-2.5"><Avatar nombre="Aeternum" forma="cuadrado" size={24} /> Aeternum</span>} count={2} collapsed={plegado} onToggle={() => setPlegado(!plegado)}>
          <GridRows template="minmax(0,1fr) 168px 118px" header={['Nombre', 'Persona asignada', 'Prioridad']}>
            <GridRow template="minmax(0,1fr) 168px 118px" onClick={() => {}}>
              <span className="flex items-center gap-3 text-[13.5px] font-semibold text-ink-strong">
                <EstadoCirculo estado="en proceso" /> Revisar la web
              </span>
              <AvatarStack people={PERSONAS.slice(0, 1)} size={24} />
              <PriorityFlag value={3} />
            </GridRow>
            <GridRow template="minmax(0,1fr) 168px 118px">
              <span className="flex items-center gap-3 text-[13.5px] font-semibold text-ink-strong">
                <EstadoCirculo estado="pendiente" /> Preparar informe
              </span>
              <AvatarStack people={[]} emptyLabel="Asignar" size={24} />
              <PriorityFlag value={0} />
            </GridRow>
          </GridRows>
          <InlineAddRow placeholder="Añadir tarea…" onAdd={() => {}} onEmptySubmit={() => {}} />
        </GroupCard>
      </Seccion>

      <Seccion titulo="Lista reordenable y acordeón">
        <Card padding="none">
          <SortableList
            items={orden}
            getId={(x) => x}
            onReorder={(ids) => setOrden(ids as string[])}
            itemClassName="border-b border-line2 last:border-b-0"
            renderItem={(x, { handleProps }) => (
              <div className="flex items-center gap-2 px-3 py-3 text-[13.5px] text-ink">
                <RowGrip {...handleProps} />
                {x}
              </div>
            )}
          />
        </Card>
        <Card padding="md">
          <Accordion
            defaultOpen={['a']}
            items={[
              { id: 'a', title: 'Datos fiscales', content: <p className="text-[13px] text-muted">CIF, dirección y serie de facturas.</p> },
              { id: 'b', title: 'Portal del cliente', content: <p className="text-[13px] text-muted">Qué secciones ve el cliente.</p> },
            ]}
          />
        </Card>
      </Seccion>

      <Seccion titulo="Menús">
        <Fila_>
          <Menu
            label="Acciones"
            trigger={(abierto) => (
              <span className={`inline-flex items-center gap-2 rounded-[9px] border px-3 py-[7px] text-[12.5px] font-semibold text-ink ${abierto ? 'border-line-strong bg-soft' : 'border-line'}`}>Acciones ▾</span>
            )}
          >
            <MenuLabel>Tarea</MenuLabel>
            <MenuItem icon={<Pencil />} shortcut="E" onSelect={() => {}}>
              Renombrar
            </MenuItem>
            <MenuItem icon={<Copy />} onSelect={() => {}}>
              Duplicar
            </MenuItem>
            <MenuItem color="#2563eb" selected check onSelect={() => {}}>
              En proceso
            </MenuItem>
            <MenuSeparator />
            <MenuItem icon={<Trash2 />} danger onSelect={() => {}}>
              Eliminar
            </MenuItem>
          </Menu>
          <div onContextMenu={cm.onContextMenu} className="rounded-[10px] border border-dashed border-line-strong px-4 py-2.5 text-[12.5px] text-muted">
            Clic derecho aquí
          </div>
          <MenuPanel {...cm.panel} label="Menú contextual">
            <MenuItem onSelect={() => {}}>Abrir</MenuItem>
            <MenuItem danger onSelect={() => {}}>
              Eliminar
            </MenuItem>
          </MenuPanel>
        </Fila_>
      </Seccion>

      <Seccion titulo="Superposiciones">
        <Fila_>
          <Button size="sm" variant="ghost" onClick={() => setModal(true)}>
            Modal
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setDrawer(true)}>
            Panel lateral
          </Button>
          <Button size="sm" variant="ghost" icon={<Search />} onClick={() => setPaleta(true)}>
            Paleta Ctrl+K
          </Button>
          <Button size="sm" variant="ghost" onClick={async () => aviso((await confirm({ message: 'La tarea va a la papelera.', danger: true })) ? 'Borrada' : 'Cancelado')}>
            Confirmar
          </Button>
          <Button size="sm" variant="ghost" onClick={async () => aviso(`Nombre: ${(await prompt({ title: 'Renombrar lista', value: 'Tareas' })) ?? '—'}`, { tipo: 'plain' })}>
            Prompt
          </Button>
          <Button size="sm" variant="ghost" onClick={() => void alert('No tienes permiso para esto.')}>
            Alerta
          </Button>
          <Button size="sm" variant="ghost" onClick={() => aviso('Guardado')}>
            Toast
          </Button>
          <Button size="sm" variant="ghost" onClick={() => aviso('Tarea eliminada', { accion: { label: 'Deshacer', fn: () => {} } })}>
            Toast con deshacer
          </Button>
          <Button size="sm" variant="ghost" onClick={() => aviso('No se ha podido guardar.', { tipo: 'error' })}>
            Toast de error
          </Button>
          <Button
            size="sm"
            variant="ghost"
            icon={<Bell />}
            onClick={() => setAvisos((a) => [...a, { id: Date.now(), titulo: 'laura · Revisar la web', subtitulo: 'Te ha asignado una tarea' }])}
          >
            Aviso en vivo
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setFly(!fly)}>
            Desplegable del raíl
          </Button>
        </Fila_>
        <Modal open={modal} onClose={() => setModal(false)} title="Nueva tarea" subtitle="Se crea en la lista «Tareas»">
          <ModalBody>
            <Field label="Título" required>
              <TextInput placeholder="¿Qué hay que hacer?" />
            </Field>
            <Field label="Responsable">
              <Select value={resp} onChange={setResp} options={PERSONAS.map((p) => ({ value: p.id, label: p.username }))} />
            </Field>
          </ModalBody>
          <ModalFooter>
            <Button variant="subtle" onClick={() => setModal(false)}>
              Cancelar
            </Button>
            <Button onClick={() => setModal(false)}>Crear tarea</Button>
          </ModalFooter>
        </Modal>
        <Drawer open={drawer} onClose={() => setDrawer(false)} title="Revisar la web" subtitle="Aeternum · Tareas">
          <div className="p-6 text-[13.5px] text-ink">Detalle rápido del registro.</div>
        </Drawer>
        <CommandPalette open={paleta} onClose={() => setPaleta(false)} buscar={buscarDemo} onVerTodos={() => {}} />
        <NotificationStack items={avisos} onDismiss={(id) => setAvisos((a) => a.filter((x) => x.id !== id))} />
        <RailFlyout open={fly} title="CRM · Ventas" onClose={() => setFly(false)}>
          <p className="px-5 py-2 text-[13px] text-muted">Menú del módulo.</p>
        </RailFlyout>
      </Seccion>

      <Seccion titulo="Guardar y zona de peligro">
        <Fila_>
          <SavedIndicator dirty />
          <SavedIndicator savedAt={guardado} />
        </Fila_>
        <DangerZone title="Dar de baja al cliente" text="Deja de ver su portal; sus datos se conservan." action={<Button variant="danger">Dar de baja</Button>} />
        <DangerZone variant="simple" title="Eliminar cliente" text="Va a la papelera." action={<Button variant="danger" size="sm">Eliminar</Button>} />
        <StickySaveBar
          dirty={dirty}
          onSave={() => {
            setDirty(false)
            setGuardado(Date.now())
          }}
          onCancel={() => setDirty(false)}
          note="Los cambios se aplican al guardar."
          bleed={false}
          className="!static rounded-xl"
        />
      </Seccion>

      <SeccionRich />
    </div>
  )
}

/* ------------------------------------------------ Contenido enriquecido (rich/) */

const IMG_DEMO =
  'data:image/svg+xml;utf8,' +
  encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="270" viewBox="0 0 480 270"><defs><linearGradient id="g" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="#5b8def"/><stop offset="1" stop-color="#a855f7"/></linearGradient></defs><rect width="480" height="270" fill="url(#g)"/><circle cx="360" cy="90" r="40" fill="#fff" opacity=".35"/><path d="M0 270 L150 140 L250 220 L330 160 L480 270Z" fill="#fff" opacity=".45"/></svg>',
  )
const IMG_DEMO2 =
  'data:image/svg+xml;utf8,' +
  encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><rect width="300" height="300" fill="#12a150"/><text x="150" y="170" font-size="64" text-anchor="middle" fill="#fff" font-family="sans-serif">OK</text></svg>')

const TEXTO_DEMO = [
  '# Lanzamiento web',
  '',
  'Objetivo: publicar la **web nueva** antes del *viernes*. Revisa __los textos__ y ~~las fotos~~ el vídeo 🎬.',
  '',
  '- Diseño aprobado por @laura',
  '- Copy en `staging` → https://staging.croilab.com',
  '- [Guía de estilo](https://docs.croilab.com/estilo)',
  '',
  '1. Revisión',
  '2. Publicación',
  '',
  '> El cliente pide ver la versión móvil primero.',
  '',
  '[[chk:1]] Enviar presupuesto',
  '[[chk:0]] Llamar a Bodegas Ribera',
  '',
  '| Tarea | Responsable |',
  '| --- | --- |',
  '| Copy | @marta |',
  '| Fotos | @víctor |',
  '',
  '```',
  'npm run build && npm run deploy',
  '```',
  '---',
  '[[img:portada.png]]',
  '[[file:contrato_2026.pdf|Contrato firmado.pdf]]',
].join('\n')

const ADJUNTOS_DEMO: Adjunto[] = [
  { id: 1, nombre: 'portada.svg', url: IMG_DEMO, mime: 'image/svg+xml' },
  { id: 2, nombre: 'logo-ok.svg', url: IMG_DEMO2, mime: 'image/svg+xml' },
  { id: 3, nombre: 'Presupuesto 2026.pdf', url: 'https://example.com/presupuesto.pdf', tamano: 182_000 },
  { id: 4, nombre: 'fuentes-web.zip', url: 'https://example.com/fuentes.zip', tamano: 4_800_000 },
]

const hace = (min: number) => new Date(Date.now() - min * 60_000).toISOString()

const COMENTARIOS_DEMO: Comentario[] = [
  {
    id: 1,
    autor: PERSONAS[0],
    cuerpo: 'He subido la **portada** nueva. ¿Qué os parece, @marta?\n\n[[img]]',
    creado: hace(60 * 26),
    adjuntos: [ADJUNTOS_DEMO[0], ADJUNTOS_DEMO[2]],
    reacciones: [
      { emoji: '👍', n: 2, mia: true, quienes: ['laura', 'gabi'] },
      { emoji: '🔥', n: 1, mia: false, quienes: ['marta'] },
    ],
  },
  { id: 2, autor: PERSONAS[4], cuerpo: 'Me encanta. Solo cambiaría el *color* del botón:\n- más contraste\n- texto más corto', creado: hace(90), replyTo: 1 },
  { id: 3, autor: PERSONAS[1], cuerpo: 'Hecho ✅ lo dejo en `staging`.', creado: hace(4), editado: true, reacciones: [{ emoji: '🎉', n: 3, mia: false }] },
]

type Deal = { id: number; titulo: string; empresa: string; valor: number; cierre: string; quien: number }
const COLUMNAS_KANBAN: KanbanColumnData<Deal>[] = [
  {
    id: 'lead',
    title: 'Lead nuevo',
    color: FASES_CRM['Lead nuevo'],
    items: [
      { id: 1, titulo: 'Web corporativa', empresa: 'Clínica Dental Sur · Salud', valor: 2400, cierre: '2026-10-20', quien: 1 },
      { id: 2, titulo: 'SEO local', empresa: 'Taller Paco · Motor', valor: 600, cierre: '2026-10-09', quien: 3 },
    ],
  },
  { id: 'prop', title: 'Propuesta enviada', color: FASES_CRM['Propuesta enviada'], items: [{ id: 3, titulo: 'Tienda online', empresa: 'Bodegas Ribera · Vino', valor: 12800, cierre: '2026-09-30', quien: 2 }] },
  { id: 'neg', title: 'Negociación', color: FASES_CRM['Negociación'], items: [{ id: 4, titulo: 'Campaña Ads', empresa: 'Aeternum · Software', valor: 980, cierre: '2026-11-02', quien: 5 }] },
  { id: 'gan', title: 'Cerrado ganado', color: FASES_CRM['Cerrado ganado'], items: [] },
]

const MESES_DEMO = ['May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct']

function alternarReaccion(rs: Reaccion[], e: string): Reaccion[] {
  const r = rs.find((x) => x.emoji === e)
  if (!r) return [...rs, { emoji: e, n: 1, mia: true }]
  return rs.map((x) => (x.emoji === e ? { ...x, n: x.n + (x.mia ? -1 : 1), mia: !x.mia } : x)).filter((x) => x.n > 0)
}

function SeccionRich() {
  const { aviso } = useToast()
  const [texto, setTexto] = useState(TEXTO_DEMO)
  const [guardadoEn, setGuardadoEn] = useState<string | null>(null)
  const [ficheros, setFicheros] = useState<Record<string, string>>({})
  const [comentarios, setComentarios] = useState(COMENTARIOS_DEMO)
  const [respondiendo, setRespondiendo] = useState<Comentario | null>(null)
  const [chat, setChat] = useState<string[]>([])
  const [reacciones, setReacciones] = useState<Reaccion[]>([
    { emoji: '👍', n: 3, mia: true },
    { emoji: '❤️', n: 1, mia: false },
  ])
  const [checks, setChecks] = useState<ItemChecklist[]>([
    { id: 1, texto: 'Revisar textos de la home', hecho: false, asignados: [1] },
    { id: 2, texto: 'Optimizar imágenes', hecho: false },
    { id: 3, texto: 'Configurar dominio', hecho: true, asignados: [2, 3] },
  ])
  const [cols, setCols] = useState(COLUMNAS_KANBAN)
  const [overlay, setOverlay] = useState(false)
  const [adjuntos, setAdjuntos] = useState(ADJUNTOS_DEMO)
  const resolver = (fn: string) => (fn === 'portada.png' ? IMG_DEMO : (ficheros[fn] ?? null))
  const columnasConTotal = useMemo(() => cols.map((c) => ({ ...c, total: eur0(c.items.reduce((s, d) => s + d.valor, 0)) })), [cols])

  return (
    <>
      <Seccion titulo="Contenido enriquecido: editor y vista (formato del ERP)">
        <div className="grid gap-4 xl:grid-cols-2">
          <div className="min-w-0">
            <RichTextEditor
              value={texto}
              onChange={setTexto}
              onBlurSave={() => setGuardadoEn(new Date().toLocaleTimeString('es-ES'))}
              placeholder="Añade una descripción… texto, imágenes, archivos y listas de control."
              mentions={PERSONAS}
              resolveFileUrl={resolver}
              onUploadFiles={async (fs) => {
                const nuevos = fs.map((f, i) => ({ f, fn: `demo_${Date.now()}_${i}.${(f.name.split('.').pop() ?? 'bin').replace(/[^a-z0-9]/gi, '')}` }))
                setFicheros((m) => ({ ...m, ...Object.fromEntries(nuevos.map((n) => [n.fn, URL.createObjectURL(n.f)])) }))
                return nuevos.map((n) => ({ fn: n.fn, nombre: n.f.name, imagen: n.f.type.startsWith('image/') }))
              }}
            />
            <p className="mt-1.5 text-[12px] text-muted">{guardadoEn ? `Guardado al salir · ${guardadoEn}` : 'Se guarda 700 ms después de escribir y al salir.'}</p>
          </div>
          <div className="min-w-0 rounded-xl border border-line bg-card p-4">
            <div className="mb-2 text-[11px] font-bold tracking-[.6px] text-label uppercase">Vista (RichTextView)</div>
            <RichTextView value={texto} people={PERSONAS} resolveFileUrl={resolver} onChange={setTexto} />
          </div>
        </div>
        <details className="rounded-lg border border-line bg-soft p-3 text-[12px]">
          <summary className="cursor-pointer font-semibold text-ink">Texto guardado (formato del ERP)</summary>
          <pre className="mt-2 overflow-auto font-mono text-[11.5px] whitespace-pre-wrap text-muted">{texto}</pre>
        </details>
      </Seccion>

      <Seccion titulo="Comentarios: hilo, compositor y reacciones">
        <div className="rounded-xl border border-line bg-[#f6f7f8] p-4 dark:bg-soft">
          <h3 className="mb-4 flex items-center gap-2 text-[15px] font-semibold text-ink-strong">
            Actividad <span className="rounded-full bg-[#e7e8ea] px-2 py-px text-[11px] font-[650] text-[#5c616b] dark:bg-line dark:text-muted">{comentarios.length}</span>
          </h3>
          <CommentThread
            comments={comentarios}
            events={[{ id: 'creada', at: hace(60 * 30), texto: 'Tarea creada', actor: 'laura' }]}
            meId={2}
            people={PERSONAS}
            onReact={(c, e) => setComentarios((cs) => cs.map((x) => (x.id === c.id ? { ...x, reacciones: alternarReaccion(x.reacciones ?? [], e) } : x)))}
            onReply={setRespondiendo}
            onEdit={(c, cuerpo) => setComentarios((cs) => cs.map((x) => (x.id === c.id ? { ...x, cuerpo, editado: true } : x)))}
            onDelete={(c) => setComentarios((cs) => cs.filter((x) => x.id !== c.id))}
          />
          <CommentComposer
            people={PERSONAS}
            replyTo={respondiendo}
            onCancelReply={() => setRespondiendo(null)}
            onSend={({ cuerpo, archivos, replyTo }) => {
              setComentarios((cs) => [
                ...cs,
                {
                  id: Date.now(),
                  autor: PERSONAS[1],
                  cuerpo,
                  creado: new Date().toISOString(),
                  replyTo,
                  adjuntos: archivos.map((f, i) => ({ id: `${Date.now()}${i}`, nombre: f.name, url: URL.createObjectURL(f), mime: f.type, tamano: f.size })),
                },
              ])
              aviso('Comentario enviado')
            }}
          />
        </div>
        <div className="rounded-xl border border-line bg-card p-4">
          <div className="mb-3 flex flex-col gap-1.5">
            {chat.length === 0 ? <p className="text-[12.5px] text-muted">Compositor simple (chat): Intro envía, Mayús+Intro salto, @ menciona, Ctrl+. emojis.</p> : null}
            {chat.map((m, i) => (
              <div key={i} className="max-w-[74%] self-end rounded-[14px] rounded-tr-[4px] bg-accent px-[15px] py-2.5 text-[13.5px] whitespace-pre-wrap text-white dark:text-accent-fg">
                {m}
              </div>
            ))}
          </div>
          <CommentComposer variant="plain" placeholder="Escribe un mensaje…" submitLabel="Enviar" people={PERSONAS} onSend={({ cuerpo }) => setChat((c) => [...c, cuerpo])} />
        </div>
        <Fila_>
          <ReactionChips reactions={reacciones} addButton onToggle={(e) => setReacciones((rs) => alternarReaccion(rs, e))} />
          <QuickReactions onPick={(e) => aviso(`Reacción ${e}`)} />
          <span className="inline-flex items-center gap-2 text-[12.5px] text-muted">
            Selector suelto: <EmojiButton onPick={(e) => aviso(`Elegido ${e}`, { tipo: 'plain' })} />
          </span>
        </Fila_>
      </Seccion>

      <Seccion titulo="Adjuntos, subida y visor">
        <AttachmentList items={adjuntos} onRemove={(a) => setAdjuntos((xs) => xs.filter((x) => x.id !== a.id))} />
        <FileDropzone
          onFiles={(fs) => {
            setAdjuntos((xs) => [...xs, ...fs.map((f, i) => ({ id: `${Date.now()}${i}`, nombre: f.name, url: URL.createObjectURL(f), mime: f.type, tamano: f.size }))])
            aviso(`${fs.length} archivo(s) añadidos`)
          }}
          maxSizeMB={10}
        />
        <Fila_>
          <Switch checked={overlay} onChange={setOverlay} label="Capa de «soltar» en toda la ventana" />
        </Fila_>
        <WindowDropOverlay enabled={overlay} onFiles={(fs, destino) => aviso(`${fs.length} archivo(s) soltados en <${destino?.tagName.toLowerCase() ?? '?'}>`)} />
      </Seccion>

      <Seccion titulo="Lista de control e historial">
        <div className="grid gap-6 xl:grid-cols-2">
          <Checklist
            items={checks}
            people={PERSONAS}
            onToggle={(id, hecho) => setChecks((cs) => marcar(cs, id, hecho))}
            onAdd={(t, asignados) => setChecks((cs) => [...cs, { id: Date.now(), texto: t, hecho: false, asignados }])}
            onDelete={(id) => setChecks((cs) => cs.filter((c) => c.id !== id))}
            onAssign={(id, asignados) => setChecks((cs) => cs.map((c) => (c.id === id ? { ...c, asignados } : c)))}
            onReorder={(ids) => setChecks((cs) => ids.map((id) => cs.find((c) => c.id === id)).filter((c): c is ItemChecklist => !!c))}
          />
          <div>
            <div className="mt-[30px] mb-[13px] text-[11px] font-[650] tracking-[.6px] text-muted uppercase">Historial</div>
            <ActivityTimeline
              items={[
                { at: hace(3), actor: 'laura', text: 'movió el negocio a Negociación' },
                { at: hace(80), actor: 'marta', text: 'añadió una nota' },
                { at: hace(60 * 30), text: 'Contacto creado desde el formulario web', color: '#12a150' },
              ]}
            />
          </div>
        </div>
      </Seccion>

      <Seccion titulo="Kanban (embudo)">
        <KanbanBoard
          columns={columnasConTotal}
          getItemId={(d) => d.id}
          cardLabel={(d) => d.titulo}
          onMove={(id, col, i) => setCols((cs) => moverTarjeta(cs, (d: Deal) => d.id, id, col, i))}
          onReorderColumns={(ids) => setCols((cs) => ids.map((id) => cs.find((c) => c.id === id)).filter((c): c is KanbanColumnData<Deal> => !!c))}
          onCardClick={(d) => aviso(`Abrir «${d.titulo}»`, { tipo: 'plain' })}
          renderCard={(d) => (
            <KanbanCard
              title={d.titulo}
              subtitle={d.empresa}
              value={eur0(d.valor)}
              chips={<Chip>Web</Chip>}
              date={fechaCorta(d.cierre)}
              dateTone={tonoVencimiento(d.cierre) === 'late' ? 'late' : null}
              avatar={<Avatar nombre={PERSONAS.find((p) => p.id === d.quien)?.username ?? '?'} size={22} />}
            />
          )}
        />
      </Seccion>

      <Seccion titulo="Gráficas (Chart.js)">
        <ChartGrid>
          <ChartCard
            title="Valor en pipeline por fase"
            type="bar"
            formatValue={eurk}
            data={{
              labels: ['Lead', 'Propuesta', 'Negociación', 'Ganado'],
              series: [{ label: 'Valor', data: [3000, 12800, 980, 5400], color: [FASES_CRM['Lead nuevo'], FASES_CRM['Propuesta enviada'], FASES_CRM['Negociación'], FASES_CRM['Cerrado ganado']] }],
            }}
          />
          <ChartCard
            title="Ganados vs perdidos por mes"
            type="bar"
            data={{
              labels: MESES_DEMO,
              series: [
                { label: 'Ganados', data: [2, 3, 1, 4, 2, 5], color: '#12a150' },
                { label: 'Perdidos', data: [1, 0, 2, 1, 1, 0], color: '#ef4444' },
              ],
            }}
          />
          <ChartCard title="Contactos por origen" type="doughnut" data={{ labels: ['Web', 'Referido', 'Ads', 'Evento'], series: [{ label: 'Contactos', data: [24, 12, 9, 4] }] }} />
          <ChartCard title="Servicios más presupuestados" type="barH" data={{ labels: ['Web', 'SEO', 'Ads', 'Redes'], series: [{ label: 'Presupuestos', data: [18, 11, 7, 4] }] }} />
          <ChartCard title="Contactos nuevos por mes" type="line" data={{ labels: MESES_DEMO, series: [{ label: 'Contactos', data: [4, 9, 6, 12, 10, 15], color: '#f0872a' }] }} />
          <ChartCard title="Motivos de pérdida" type="doughnut" data={{ labels: [], series: [] }} />
          <ChartCard wide height={270} title="Resumen financiero" type="lineaFinanzas" formatValue={eurk} data={{ labels: MESES_DEMO, series: [{ label: 'Ingresos', data: [8200, 9400, 7100, 12500, 11800, 14900] }] }} />
        </ChartGrid>
      </Seccion>
    </>
  )
}

export default function GaleriaUI() {
  return (
    <div className="min-h-screen bg-page">
      <header className="border-b border-line px-6 py-4">
        <h1 className="text-[19px] font-semibold text-ink-strong">Galería de componentes</h1>
        <p className="text-[12.5px] text-muted">Solo en desarrollo · claro a la izquierda, oscuro a la derecha · {eur(1234.56)}</p>
      </header>
      <div className="grid grid-cols-2 max-[1100px]:grid-cols-1">
        <div className="min-w-0 bg-page p-6 text-ink">
          <Muestra />
        </div>
        <div className="dark min-w-0 bg-page p-6 text-ink">
          <Muestra />
        </div>
      </div>
    </div>
  )
}
