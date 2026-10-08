import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, ChevronRight, Lock, Play } from 'lucide-react'
import { usePortal } from '../../contexto'
import { servicioContratado, videoDe } from '../../logica'
import { infoServicio, serviciosPortal } from '../../textos'
import { Acordeon, CabeceraVista, Eyebrow, Tarjeta } from '../ui'

/* Vídeo de YouTube: primero la miniatura y, al pulsar, el reproductor (como el antiguo). */
function Video({ id, titulo }: { id: string; titulo: string }) {
  const [on, setOn] = useState(false)
  if (!/^[\w-]{6,20}$/.test(id)) return null
  return (
    <div className="relative aspect-video w-full max-w-[560px] overflow-hidden rounded-[20px] bg-black">
      {on ? (
        <iframe
          title={titulo}
          src={`https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0`}
          className="size-full border-0"
          allow="autoplay; encrypted-media; picture-in-picture"
          allowFullScreen
        />
      ) : (
        <button type="button" onClick={() => setOn(true)} className="group relative size-full" aria-label={`Reproducir: ${titulo}`}>
          <img src={`https://img.youtube.com/vi/${id}/hqdefault.jpg`} alt="" className="size-full object-cover" />
          <span className="absolute inset-0 flex items-center justify-center">
            <span className="flex size-16 items-center justify-center rounded-full bg-white text-black shadow-lg transition group-hover:scale-105">
              <Play className="ml-1 size-6 fill-current" />
            </span>
          </span>
          <span className="absolute bottom-3 left-3 text-[13.5px] font-bold text-white drop-shadow">▶ {titulo}</span>
        </button>
      )}
    </div>
  )
}

export function Metodo() {
  const { datos: d, ruta } = usePortal()
  const lista = serviciosPortal(d.catalogo)
  return (
    <div>
      <CabeceraVista grande titulo="Cómo trabajamos" sub="Nuestro método y cómo enfocamos cada servicio." />
      <Video id={d.videos.general} titulo="Cómo trabajamos · 2 min" />
      <p className="mt-2 px-1.5 text-[13px] text-(--p-muted)">
        Vídeo provisional.{' '}
        <a href={`https://www.youtube.com/watch?v=${d.videos.general}`} target="_blank" rel="noopener noreferrer" className="text-(--p-ink-strong)">
          Ábrelo en YouTube
        </a>
      </p>
      <Eyebrow className="mt-7 mb-3 px-1.5">Nuestros servicios</Eyebrow>
      <div className="grid gap-3.5 md:grid-cols-2">
        {lista.map((s) => {
          const tiene = servicioContratado(d.servicios, s.nombre)
          return (
            <Link
              key={s.nombre}
              to={ruta('metodo', encodeURIComponent(s.nombre))}
              className={`flex items-center gap-3.5 rounded-[20px] border border-(--p-line) bg-(--p-card) p-5 shadow-(--p-shadow) transition hover:-translate-y-0.5 ${tiene ? '' : 'opacity-75'}`}
            >
              <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-(--p-acc-soft) text-[15px] font-black text-(--p-ink-strong) dark:text-(--p-acc)">{s.nombre.slice(0, 2).toUpperCase()}</span>
              <span className="min-w-0 flex-1">
                <b className="flex items-center gap-1.5 text-[15px] text-(--p-ink-strong)">
                  {s.nombre}
                  {!tiene && <Lock className="size-3.5 text-[#e0a000]" aria-label="No incluido en tu plan" />}
                </b>
                <span className="block text-[13px] text-(--p-muted)">{s.desc}</span>
              </span>
              <ChevronRight className="size-4 text-(--p-muted)" />
            </Link>
          )
        })}
      </div>
    </div>
  )
}

export function Servicio() {
  const { datos: d, ruta } = usePortal()
  const { servicio = '' } = useParams()
  const nombre = decodeURIComponent(servicio)
  const desc = d.catalogo.find((c) => c.nombre === nombre)?.desc ?? ''
  const info = infoServicio(nombre, desc)
  const tiene = servicioContratado(d.servicios, nombre)
  return (
    <div className="max-w-[880px]">
      <Link to={ruta('metodo')} className="mb-4 inline-flex items-center gap-1.5 text-[13.5px] font-medium text-(--p-ink-strong)">
        <ArrowLeft className="size-4" /> Volver a servicios
      </Link>
      <div className="mb-5 flex items-center gap-3.5">
        <span className="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-(--p-acc) text-[18px] font-black text-(--p-acc-fg)">{nombre.slice(0, 2).toUpperCase()}</span>
        <div>
          <h2 className="text-[24px] font-extrabold text-(--p-ink-strong)">{nombre}</h2>
          <p className="text-[14.5px] text-(--p-muted)">{info.intro}</p>
        </div>
      </div>
      {!tiene ? (
        <Tarjeta className="p-6 text-center">
          <Lock className="mx-auto mb-2 size-7 text-[#e0a000]" />
          <p className="mx-auto max-w-[520px] text-[14.5px] text-(--p-ink)">
            Este servicio no está en tu plan actual. Si te interesa, organizamos una reunión y te contamos cómo <b>{nombre}</b> puede ayudarte — sin compromiso.
          </p>
          {d.contacto.meeting_url && (
            <a href={d.contacto.meeting_url} target="_blank" rel="noopener noreferrer" className="mt-4 inline-flex min-h-[40px] items-center rounded-xl bg-(--p-acc) px-4 text-[14px] font-semibold text-(--p-acc-fg)">
              Reservar una reunión
            </a>
          )}
        </Tarjeta>
      ) : (
        <>
          <Video id={videoDe(d.videos.servicios, nombre, d.videos.general)} titulo={`${nombre} · presentación`} />
          {info.pasos.length > 0 && (
            <>
              <Eyebrow className="mt-7 mb-3 px-1.5">Cómo lo hacemos</Eyebrow>
              <Tarjeta className="overflow-hidden">
                {info.pasos.map((p, i) => (
                  <div key={i} className="flex items-center gap-3.5 border-b border-(--p-line) px-5 py-3.5 last:border-b-0">
                    <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-(--p-acc) text-[13px] font-bold text-(--p-acc-fg)">{i + 1}</span>
                    <span className="text-[14.5px] text-(--p-ink-strong)">{p}</span>
                  </div>
                ))}
              </Tarjeta>
            </>
          )}
          {info.faqs.length > 0 && (
            <>
              <Eyebrow className="mt-7 mb-3 px-1.5">Preguntas frecuentes</Eyebrow>
              <div className="flex flex-col gap-2.5">
                {info.faqs.map((f) => (
                  <Acordeon key={f.q} titulo={f.q}>
                    {f.a}
                  </Acordeon>
                ))}
              </div>
            </>
          )}
        </>
      )}
    </div>
  )
}
