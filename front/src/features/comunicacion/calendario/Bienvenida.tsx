import { Settings } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import { CajaLogo, LogoGcal } from '../components/Logos'

/* Sin Google conectado (.gc-onboard): invita a conectar o a configurar, y deja
   ver solo las fechas de las tareas. */
export default function Bienvenida({
  configurado,
  puedeConfigurar,
  conectando,
  onConectar,
  onSoloTareas,
}: {
  configurado: boolean
  puedeConfigurar: boolean
  conectando: boolean
  onConectar: () => void
  onSoloTareas: () => void
}) {
  return (
    <div className="mx-auto my-[6vh] max-w-[540px] rounded-[20px] border border-line bg-card px-9 py-[42px] text-center shadow-[0_24px_60px_-34px_rgba(16,19,24,.35)] max-sm:my-4 max-sm:px-5 max-sm:py-8 dark:shadow-none">
      <div className="mb-[18px] flex justify-center">
        <CajaLogo size={74}>
          <LogoGcal size={40} />
        </CajaLogo>
      </div>
      <h2 className="mb-2.5 text-[21px] font-semibold text-ink-strong">Tu calendario, conectado a Google</h2>
      <p className="mx-auto mb-6 max-w-[420px] text-[14px] leading-[1.65] text-muted">
        Conecta tu cuenta de Google para ver aquí tus reuniones y eventos y crear nuevos desde el ERP. Tú y tu equipo podréis veros las agendas y organizar reuniones juntos.
      </p>
      {configurado ? (
        <Button onClick={onConectar} loading={conectando} loadingText="Abriendo Google…" icon={<LogoGcal size={18} />} className="!px-[22px] !py-3 !text-[14px]">
          Conectar Google Calendar
        </Button>
      ) : puedeConfigurar ? (
        <>
          <Button to="/ajustes/integraciones?i=calendar" icon={<Settings />} className="!px-[22px] !py-3 !text-[14px]">
            Configurar Google Calendar
          </Button>
          <p className="mt-4 text-[12.5px] leading-[1.5] text-muted">Solo tú (Dueño) haces esta configuración, una única vez.</p>
        </>
      ) : (
        <p className="mt-4 text-[12.5px] leading-[1.5] text-muted">
          Pídele al Dueño que configure Google Calendar en Ajustes.
          <br />
          Después podrás conectar tu cuenta desde aquí.
        </p>
      )}
      <div>
        <button type="button" onClick={onSoloTareas} className="mt-6 rounded-lg p-1.5 text-[13px] font-semibold text-[#6b7280] hover:text-ink hover:underline dark:text-muted">
          Ver solo las fechas de mis tareas del ERP →
        </button>
      </div>
    </div>
  )
}
