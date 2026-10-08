import { useState, type ReactNode } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, BarChart3, BookOpen, Check, FileText, Home, KeyRound, ListChecks, TrendingUp } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Card, { CardHeader } from '../../../shared/ui/Card'
import Field from '../../../shared/ui/Field'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import Switch from '../../../shared/ui/Switch'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import { mensajeError, useCrearTipo, useGuardarTipo, useTipo } from '../api'
import { SECCIONES_TIPO } from '../lib/etiquetas'
import { SECCIONES, type Seccion, type Tipo } from '../schemas'
import Etiqueta from './Etiqueta'
import { usePermisosClientes } from './permisos'

const ICONOS: Record<Seccion, ReactNode> = {
  metricas: <BarChart3 />,
  progreso: <TrendingUp />,
  informes: <FileText />,
  como: <BookOpen />,
  accesos: <KeyRound />,
  plan: <ListChecks />,
}

/* Alta y edición de un tipo (type-edit.php). */
export default function TipoFormPage() {
  const params = useParams()
  const id = params.id ? Number(params.id) : 0
  const { data: tipo, error } = useTipo(id)
  if (id && error) return <Notice tone="error">{mensajeError(error, 'No se ha podido cargar el tipo.')}</Notice>
  if (id && !tipo) return <p className="py-[60px] text-center text-[13.5px] text-muted">Cargando…</p>
  return <Formulario key={id} tipo={tipo ?? null} />
}

function Formulario({ tipo }: { tipo: Tipo | null }) {
  const navigate = useNavigate()
  const p = usePermisosClientes()
  const { aviso } = useToast()
  const inicial = { nombre: tipo?.nombre ?? '', secciones: tipo?.secciones ?? Object.fromEntries(SECCIONES.map((s) => [s, true])) } as {
    nombre: string
    secciones: Record<Seccion, boolean>
  }
  const [nombre, setNombre] = useState(inicial.nombre)
  const [secciones, setSecciones] = useState(inicial.secciones)
  const [errorNombre, setErrorNombre] = useState('')
  const crear = useCrearTipo()
  const guardar = useGuardarTipo(tipo?.id ?? 0)
  const pendiente = crear.isPending || guardar.isPending
  const dirty = nombre !== inicial.nombre || SECCIONES.some((s) => secciones[s] !== inicial.secciones[s])
  useUnsavedGuard(dirty && !pendiente)

  async function enviar() {
    if (!nombre.trim()) {
      setErrorNombre('Pon un nombre al tipo.')
      return
    }
    try {
      if (tipo) await guardar.mutateAsync({ nombre, secciones })
      else await crear.mutateAsync({ nombre, secciones })
      aviso(tipo ? 'Cambios guardados.' : 'Tipo creado.')
      navigate('/clientes/tipos')
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido guardar el tipo.'), { tipo: 'error' })
    }
  }

  return (
    <div className="max-w-[760px]">
      <PageHeader
        title={tipo ? `Editar «${tipo.nombre}»` : 'Nuevo tipo de cliente'}
        lead={
          tipo ? (
            <>
              Lo que marques aquí cambia el portal de <b className="text-ink-strong">{tipo.uso === 1 ? '1 cliente' : `${tipo.uso} clientes`}</b> al momento.
            </>
          ) : (
            'Un tipo agrupa a los clientes que ven lo mismo en su portal.'
          )
        }
        actions={
          <Button variant="ghost" size="sm" to="/clientes/tipos" icon={<ArrowLeft />}>
            Volver
          </Button>
        }
      />
      {!p.tipos && <Notice tone="warn">Solo lectura: no puedes cambiar los tipos con tu permiso actual.</Notice>}
      <fieldset disabled={!p.tipos} className="min-w-0">
        <Card className="mb-4">
          <Field label="Nombre del tipo" error={errorNombre} hint="Solo lo ves tú, en la ficha de cada cliente." required>
            <TextInput
              value={nombre}
              onChange={(e) => {
                setNombre(e.target.value)
                setErrorNombre('')
              }}
              placeholder="Ej: SEO completo, Solo web, Mantenimiento"
              maxLength={120}
              autoFocus={!tipo}
            />
          </Field>
        </Card>
        <Card className="mb-4">
          <CardHeader title="Qué ve el cliente en su portal" subtitle="Apaga lo que no quieras que vea. Se le oculta la sección entera del menú de su portal." />
          <div className="flex flex-col gap-1">
            <div className="flex items-center gap-[13px] rounded-xl px-2.5 py-[11px]">
              <span className="flex size-[26px] items-center justify-center text-label [&>svg]:size-[18px]">
                <Home />
              </span>
              <span className="min-w-0 flex-1">
                <b className="block text-[13.5px] font-semibold text-ink-strong">Inicio</b>
                <span className="text-[12px] text-muted">El resumen del mes y los avisos. Se ve siempre.</span>
              </span>
              <Etiqueta tono="on">Fija</Etiqueta>
            </div>
            {SECCIONES_TIPO.map((s) => (
              <Switch
                key={s.key}
                checked={secciones[s.key]}
                onChange={(v) => setSecciones((x) => ({ ...x, [s.key]: v }))}
                label={s.titulo}
                description={s.texto}
                logo={<span className={`flex text-label [&>svg]:size-[18px] ${secciones[s.key] ? '' : 'opacity-50'}`}>{ICONOS[s.key]}</span>}
                disabled={!p.tipos}
                className={secciones[s.key] ? '' : 'opacity-60'}
              />
            ))}
          </div>
          <p className="mt-3 text-[12px] text-label">
            Si apagas <b>Métricas</b>, también desaparecen las oportunidades del Inicio: salen de ahí.
          </p>
        </Card>
      </fieldset>
      {p.tipos && (
        <div className="flex gap-2.5">
          <Button icon={<Check />} onClick={() => void enviar()} loading={pendiente} loadingText="Guardando…">
            {tipo ? 'Guardar cambios' : 'Crear tipo'}
          </Button>
          <Button variant="ghost" to="/clientes/tipos">
            Cancelar
          </Button>
        </div>
      )}
    </div>
  )
}
