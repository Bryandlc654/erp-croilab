import { ESTADOS_TICKET, PRIORIDADES_VIVAS } from '../../../shared/lib/paletas'
import type { OpcionSelect } from '../../../shared/ui/Select'

/* Estados y prioridades de los tickets (support.php): 1 Baja · 2 Normal · 3 Alta · 4 Urgente. */
export const tonoEstado = (e: string) => ESTADOS_TICKET[e] ?? ESTADOS_TICKET.abierto
export const PRIORIDADES_TICKET = PRIORIDADES_VIVAS.filter((p) => p.value >= 1)
export const tonoPrioridad = (p: number) => PRIORIDADES_TICKET.find((x) => x.value === p) ?? PRIORIDADES_TICKET[1]

export const OPCIONES_ESTADO: OpcionSelect<string>[] = Object.entries(ESTADOS_TICKET).map(([value, t]) => ({ value, label: t.label, color: t.color }))
export const OPCIONES_PRIORIDAD: OpcionSelect<number>[] = PRIORIDADES_TICKET.map((p) => ({ value: p.value, label: p.label, color: p.color }))
