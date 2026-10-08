import { createElement } from 'react'
import type { BuscarPaleta, GrupoPaleta } from '../../shared/ui/CommandPalette'
import { rutaDesdeLegado } from '../../shared/lib/rutas'
import { buscar } from './api'
import { Icono } from './buscador'
import type { Busqueda } from './schemas'

/* Respuesta de /buscar → grupos de la paleta (ids estables por grupo y fila). */
export function aGruposPaleta(r: Busqueda): GrupoPaleta[] {
  return r.grupos.map((g) => ({
    titulo: g.g,
    resultados: g.r.map((x, i) => ({ id: `${g.g}-${i}-${x.u}`, titulo: x.t, subtitulo: x.s || undefined, href: rutaDesdeLegado(x.u), icono: createElement(Icono, { clave: x.i }) })),
  }))
}

/* El `buscar` real de la paleta Ctrl+K: 5 por grupo, como el antiguo. */
export const buscarPaleta: BuscarPaleta = async (q, signal) => aGruposPaleta(await buscar(q, 5, signal))
