# 00 · Sistema de diseño y comportamientos transversales del ERP antiguo

> Fuente principal: `copia-erp/admin/erp_nav.php` (CSS global en `erp_css()` L550-1264, armazón `erp_head()` L1983, `erp_foot()` L2007-3141).
> Complementado con los `<style>` en línea de las páginas grandes (secciones 2.B y 3).
> Objetivo: que cada pantalla nueva en `front/src` (React 19 + Tailwind v4) se vea y se comporte igual.
> Todas las cifras son las del CSS original. «L» = número de línea en el fichero citado.

---

## 0. Principios visuales (resumen en 10 líneas)

1. Monocromo neutro: texto gris carbón (`#3c4149`), títulos casi negros (`#22262c`), acento NEGRO (`#1f232a` / `#111318`), no hay color de marca. El color solo aparece en estados (verde OK, rojo peligro, azul «en proceso», ámbar «atemporal») y en avatares.
2. Superficies blancas con borde de 1px `#eeeeef` y radio grande (16px tarjetas, 10px controles, 12-14px popovers). Sin sombra en reposo; la sombra aparece al hover/flotar.
3. Fuente única Inter 400-800, base 14px, `letter-spacing:-.1px`, `line-height:1.5`, antialiased.
4. Micro-interacciones: todo transiciona 0.16s (`cubic-bezier(.2,.7,.3,1)` en transform); botones suben 1px al hover y se hunden al pulsar; tarjetas KPI suben 2px.
5. Seleccionado = fondo NEGRO + texto blanco (segmentados, chips pick); en oscuro se invierte (fondo `#e5e5e5`, texto `#171717`).
6. Etiquetas de sección: MAYÚSCULAS 10.5-12px, `letter-spacing .5-.7px`, peso 600-700, color `--muted/--label`.
7. Popovers/menús: blanco, borde `--line`, radio 12px, sombra `0 18px 46px rgba(0,0,0,.17)`, padding 5px, items 8px 12px radio 8px, animación `pop .15s`.
8. Modales: máscara `rgba(16,19,24,.34-.38)` + `blur(2-4px)`, panel radio 16-18px, entra con `translateY(8-10px) scale(.985)` → normal.
9. Feedback: toast oscuro abajo-derecha con check verde; «Deshacer» dentro del toast 7s; confirmaciones con diálogo propio (nunca `confirm()` nativo).
10. Modo oscuro = capa aditiva `[data-theme=dark]` estilo shadcn neutro (`#0a0a0a` fondo, `#161616` tarjeta, `#1f1f1f` popover, `#0f0f0f` campos).

---

## 1. Tokens

### 1.1 Variables CSS — modo claro (`:root`, erp_nav.php L551)

| Token | Valor | Uso |
|---|---|---|
| `--ink` | `#3c4149` | texto base |
| `--ink-strong` | `#22262c` | títulos, cifras KPI, nombres |
| `--muted` | `#656a72` | texto secundario, cabeceras de tabla |
| `--label` | `#656a72` | iconos en reposo, etiquetas |
| `--line` | `#eeeeef` | bordes de todo |
| `--line2` | `#f6f6f7` | separadores internos (dentro de tarjeta) |
| `--accent` | `#1f232a` | botón primario, checkbox `accent-color`, switch ON |
| `--accent-soft` | `#f2f2f3` | fila seleccionada en paleta/búsqueda, opción activa de select |
| `--bg` | `#ffffff` | fondo de página |
| `--card` | `#fff` | tarjetas |
| `--soft` | `#f7f7f8` | hover de items, fondos de icono, buscador |
| `--ring` | `#c4c4c7` | borde de campo con foco, outline `:focus-visible` |
| `--ring-soft` | `rgba(17,19,24,.07)` | halo |
| `--ok` | `#0f7a3d` | |
| `--danger` | `#c62a33` | |

Valores «sueltos» que se repiten y conviene tokenizar:

| Valor | Dónde |
|---|---|
| `#111318` | activo de `.seg`, `.chip.pick.on`, icono de popup de aviso (`--tab-on` en front) |
| `#0f1012` | raíl negro, logo, botón «Portal» |
| `#dcdcde` | borde en hover de controles blancos (`.btn.ghost`, `.tbtn`, `.chip.*`) |
| `#e2e2e4` | borde hover `.kpi` |
| `#4c515b` / `#5a5f68` / `#5c616b` | texto de items de menú en reposo |
| `#6b7280` | texto inactivo de segmentado, flechas del datepicker |
| `#fafbfc` | hover de filas de tabla / `.inline-add` |
| `#eef0f3` | fondo `.chip` base, hover `.icon-btn` |
| `#d4d8de` | borde discontinuo (`.addbtn`, `.soonbox`) |
| `#12a150` | verde «hecho/online/activo» (check del toast, switch `.ok`, estado completada, punto de presencia) |
| `#3b82f6` | azul de «en proceso» y marcadores de soltar (drag) |
| `#e0a000` | ámbar «atemporal» |
| `#b0b4bb` | gris «en espera» |
| `#ef4444` | badge de campana, asterisco obligatorio |
| `#e5484d` / `#feecec` | contador rojo del menú (`.cnt`) |
| `#c0392b` / `#fde8e8` | texto/fondo de acciones peligrosas en menús |
| `#c0343a` / `#a82c31` | botón «Eliminar» del diálogo / toast de error |
| `#b23b30` / `#ecd4d1` / `#fbf3f2` / `#e2bfbb` | `.btn.danger` (texto/borde/hover fondo/hover borde) |
| `#f59e0b` | punto «cliente no activo» |
| `#f0872a` / `#c0c4cb` | presencia «reposo» / «desconectado» |

### 1.2 Variables CSS — modo oscuro (`[data-theme=dark]`, L1178-1189)

| Token | Valor |
|---|---|
| `--ink` | `#e6e6e6` |
| `--ink-strong` | `#fafafa` |
| `--muted` | `#a1a1a1` |
| `--label` | `#9a9a9a` |
| `--line` | `#282828` |
| `--line2` | `#1c1c1c` |
| `--accent` | `#e5e5e5` |
| `--accent-soft` | `#262626` |
| `--bg` | `#0a0a0a` |
| `--card` | `#161616` (¡distinto del fondo! en claro ambos son blancos) |
| `--soft` | `#242424` |
| `--pop` | `#1f1f1f` (menús, popovers, paleta, datepicker) |
| `--field` | `#0f0f0f` (inputs, selects, chips con borde, `.addbtn`, `.qbtn`) |
| `--line-strong` | `#3a3a3a` (hover de bordes, foco de campos, switch apagado) |
| `--ring` | `#4a4a4a` |
| `--ring-soft` | `rgba(255,255,255,.08)` |
| `--accent-fg` | `#171717` (texto sobre botón primario claro) |
| `--rev` / `--rev-fg` | `#e5e5e5` / `#171717` (activo invertido: `.seg>.on`, `.chip.pick.on`, `.tag.on`, icono de notif) |
| `--nav-ink` | `#b5b5b5` (texto de items de menú) |
| `--badge-bg` / `--badge-fg` | `#3a2327` / `#f0999a` (contadores rojos) |
| `--ok` / `--ok-bg` / `--ok-line` | `#54cd8e` / `#14251c` / `#234436` |
| `--danger` / `--danger-bg` / `--danger-line` | `#f08d82` / `#2e1d1d` / `#472a2a` |
| `--warn` | `#efb445` |
| `color-scheme` | `dark` |

Reglas de la capa oscura (L1190-1263): superficies que eran `#fff` pasan a `--card` (`.side,.rail-fly,.erp-top,.kpi,.panel,.erp-empty,.notif-pop`); popovers a `--pop` con sombra `0 18px 46px rgba(0,0,0,.5)`; campos a `--field`; celdas de edición en línea (`.cell,.ed`) transparentes; hover de `.kpi` `box-shadow:0 8px 26px rgba(0,0,0,.4)`; selección de texto `#3a3d44/#f5f7fa`; scrollbar `rgba(255,255,255,.15/.3)`.

**Estado actual en front** (`front/src/index.css`): solo existen `--c-bg, --c-ink-strong, --c-ink, --c-label, --c-muted, --c-line, --c-line2, --c-soft, --c-head(#fbfbfc/#141414), --c-accent, --c-tab-on`. Faltan: `accent-soft`, `card`, `pop`, `field`, `line-strong`, `ring`, `accent-fg`, `rev/rev-fg`, `nav-ink`, `badge-*`, `ok-*`, `danger-*`, `warn`. Ver §5.3 para el bloque propuesto.

### 1.3 Tipografía

- Familia: `"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif` (Google Fonts, pesos 400;500;600;700;800). Pesos intermedios usados: 640 (`.kpi .big`), 650 (`.rf-h`, `.sh`, títulos de diálogo, `.np-t`), 750 (perfil). Inter variable los soporta.
- `body`: 14px, `line-height:1.5`, `letter-spacing:-.1px`, `-webkit-font-smoothing:antialiased`.
- En móvil ≤640px todos los campos a 16px (evita zoom de iOS).

Escala usada (px → uso):

| px | peso | uso |
|---|---|---|
| 31 | 640, `ls -.7px`, `lh 1` | cifra KPI `.kpi .big` |
| 26 (23 móvil) | 600, `ls -.5px` | `h1` de página |
| 21 | 750 | título popup cumpleaños |
| 18 | 600, `ls -.3px` | título estado vacío |
| 16 | 600-700 | `.panel h3`, cabecera de modal `.erpag-h`, input de la paleta |
| 15.5 | 650, `ls -.2px` | título de barra lateral `.sh`, `.rf-h`, título diálogo |
| 15 | 400, `lh 1.55` | `.lead` (subtítulo bajo h1) |
| 14 | 400-500 | items de menú lateral, inputs |
| 13.5 | 500-600 | botones `.btn`, celdas `td`, segmentado, título resultado búsqueda |
| 13 | 500-600 | menús contextuales, opciones de select, toasts, avisos, `.muted` |
| 12.5 | 600 | `.btn.sm`, `.tbtn`, `.chip.pick/.act`, `label`, migas |
| 12 | 400-650 | `.kpi .sub`, `.sec-t`, `.set-zone`, `.chip.data` |
| 11.5 | 600 | `th`, `.kpi .h`, subtítulos, hints |
| 11 | 600-700 | `.tag`, `.chip`, contadores |
| 10.5 | 700 | cabeceras de sección de menú (`.sec`), `kbd`, `.gs-sec` |
| 10 | 700 | días de la semana del datepicker, contador de listas |
| 9 | 600 | etiqueta bajo icono del raíl |

### 1.4 Radios

| px | Elementos |
|---|---|
| 50% / 99px | avatares de persona, píldoras (`.chip.data/.pick`, contadores, switch) |
| 22 | popup de cumpleaños |
| 20 | raíl flotante |
| 18 | `.soonbox`, icono de estado vacío (60×60), modales `.erpag`, tarjeta de perfil |
| 16 | `.card`, `.panel`, `.kpi`, `.erp-empty`, diálogo de confirmación, paleta Ctrl+K |
| 15 | items del raíl (50×50) |
| 14 | `#dpCal`, `.notif-pop`, bloque «portal» del sidebar, emoji picker |
| 12 | `.ctxmenu`, `.addlm`, `.cs-pop` |
| 11 | items del sidebar, `.seg` exterior, resultado de búsqueda, botones de modal |
| 10 | `.btn`, inputs/selects/textarea, `.tsearch`, `.qbtn`, avisos, `.addbtn`, logo del raíl |
| 9 | `.btn.sm`, `.tbtn`, `.mini-sel`, `.chip.act`, contenedor icono búsqueda (32×32) |
| 8 | items de menú/opción, segmento interior, días del datepicker |
| 7 | `.tag`, `.secadd` |
| 6 | `.chip` base, `kbd`, `.icon-btn` |
| 3 | barra activa del raíl (`0 3px 3px 0`), scrollbar thumb 8px |

Avatar de cliente/empresa (cuadrado): radio = `round(size × 0.29)` (ya en `shared/ui/Avatar.tsx`).

### 1.5 Sombras

| Nombre propuesto | Valor | Uso |
|---|---|---|
| `shadow-btn` | `0 7px 18px rgba(0,0,0,.16)` | hover `.btn` primario |
| `shadow-btn-ghost` | `0 5px 14px rgba(0,0,0,.05)` | hover `.btn.ghost` |
| `shadow-card-hover` | `0 8px 26px rgba(0,0,0,.05)` | hover `.kpi` |
| `shadow-empty` | `0 1px 2px rgba(16,19,24,.03)` | `.erp-empty` |
| `shadow-pop` | `0 18px 46px rgba(0,0,0,.17)` | `.ctxmenu`, `.addlm`, `.cs-pop`, emoji picker |
| `shadow-cal` | `0 20px 50px rgba(0,0,0,.18)` | datepicker |
| `shadow-palette` | `0 24px 60px rgba(16,18,22,.22)` | paleta Ctrl+K |
| `shadow-dialog` | `0 24px 70px -18px rgba(16,19,24,.45), 0 0 0 1px rgba(16,19,24,.05)` | confirm/prompt |
| `shadow-modal` | `0 30px 80px rgba(0,0,0,.35)` | modales `.erpag` |
| `shadow-toast` | `0 12px 34px rgba(0,0,0,.24)` | toast |
| `shadow-notif` | `0 8px 22px -10px rgba(16,19,24,.30), 0 2px 6px -2px rgba(16,19,24,.10)` | popup de aviso en vivo |
| `shadow-fly` | `14px 0 34px -18px rgba(16,19,24,.25)` | desplegable del raíl |
| `shadow-profile` | `0 24px 54px -18px rgba(16,19,24,.42), 0 3px 10px -5px rgba(16,19,24,.18)` | tarjeta de perfil hover |
| `shadow-respick` | `0 16px 40px rgba(16,19,24,.16)` | lista de responsables del modal tarea |
| Halo foco | `0 0 0 3px rgba(17,19,24,.06)` (select abierto), `0 0 0 3px rgba(31,35,42,.06)` (input diálogo), `0 0 0 3px rgba(0,113,227,.12)` + borde `#0071e3` (campos de modales `.erpag` — único sitio azul) |

Front actual: `Menu.tsx` usa `0 12px 32px -8px rgba(0,0,0,.18)` y radio 12px (aceptable, pero difiere de `shadow-pop`).

### 1.6 Espaciado y rejillas

- Contenido de página `.erp-wrap`: `padding:36px 52px 80px`; ≤700px `22px 18px 60px`; ≤640px `20px 14px 64px`. (Front: `px-4 py-5 md:px-6 md:py-6 lg:px-[52px] lg:py-9`; falta el `pb-20` inferior.)
- Tarjeta `.card`: `24px 26px`, `margin-bottom:20px`. `.panel`/`.kpi`: `22px 24px`. Móvil ≤640: `18px 16px`.
- Separación entre tarjetas/columnas: 20px (`.kpis`, `.grid2`, `.panel` margin).
- `.kpis`: `grid-template-columns:repeat(4,1fr); gap:20px; margin-bottom:24px` → ≤900: 2 col; ≤640: 2 col gap 12; ≤420: 1 col.
- `.grid2`: `1.5fr 1fr`, gap 20 → 1 col ≤900.
- Formularios: `.row` 2 col gap 18; `.row3` 3 col gap 10; `.set-grid` 12 col, `gap:18px 22px`, hijos `span 6` por defecto; modificadores `.c2 .c3 .c4 .c5 .c6 .c8 .c12/.full`, `.set-grid.one` todo a ancho completo. Todo a 1 col ≤700px.
- `label`: `display:block; 12.5px; 600; color muted; margin:18px 0 7px`.
- Títulos de bloque: `.sec-t` (12px, upper, `ls .6px`, 650, `margin:32px 0 12px`); `.set-zone` igual + línea `--line2` que llena el resto (`margin:30px 0 14px`).
- Breakpoints: 900 (navegación: raíl/sidebar se ocultan → hamburguesa), 860 (buscador de la barra se reduce a icono), 700 (rejillas a 1 col, popovers ≤ `100vw-20px`), 640 (teléfono: campos 16px, botones ≥42px, chips/tbtn/seg ≥38px, checkbox 20×20), 420 (KPIs 1 col), 400 (barra superior compacta).

### 1.7 Transiciones y animaciones

- Transición global (L560) sobre `a, button, .btn, .card, input, select, textarea, .trow, .ck-row, .nav a, .rail a, .chip, .tl-tab, .kpi, .tbtn, .icon-btn…`: `background-color .16s ease, border-color .16s ease, color .16s ease, box-shadow .18s ease, transform .16s cubic-bezier(.2,.7,.3,1)`.
- Curva de la casa: `cubic-bezier(.2,.7,.3,1)` (≈ ease-out suave). Muelle de la tarjeta de perfil: `cubic-bezier(.34,1.56,.64,1)`. Acordeones: `cubic-bezier(.33,1,.68,1)` 300ms.
- Keyframes:
  - `fadeUp` (from `opacity:0; translateY(9px)`) — entrada de cada página `.erp-wrap` `.34s cubic-bezier(.2,.7,.3,1)`.
  - `fadeIn` (opacidad).
  - `pop` (`scale .97 → 1.03 → 1`) — apertura de menús/popovers (`.15s ease`), hover del logo.
  - `slideIn` (`translateX(-6px)` → 0).
  - `erpIn` (`translateY(7px)`→0, `.22s`) / `erpOut` (→ `translateX(10px)`, `opacity 0`, `.18s`) — filas que aparecen/desaparecen (`.erp-in`, `.erp-out`).
  - `.erp-in-stg>*` escalonado: retrasos 0, .03, .06, .09, .12, .15s (n≥6).
  - `gsIn` (`translateY(-8px) scale(.985)` → normal, `.16s`) — paleta.
  - `bellPulse` (`scale 1 → 1.4 → 1`, `.55s`) — globo de la campana al subir.
  - `theme-reveal` (`clip-path:circle(0%→150%)` desde el botón, `.42s ease-in-out`) — View Transitions al cambiar de tema.
  - `navSlide` (`translateX(-14px)`, opacidad .4 → 1, `.2s`) — cajón móvil.
- `prefers-reduced-motion: reduce` desactiva `erp-in/out/stg` y la animación de tema.
- Botones: hover `translateY(-1px)`; `:active` `translateY(1px) scale(.985)` (`.btn` además `scale(.98)` sin sombra).

### 1.8 Base y accesibilidad

- `*{box-sizing:border-box;margin:0;padding:0}`, `svg{display:block}`.
- `::selection{background:#e4e5e8;color:#0f1216}`.
- Scrollbar: fino, `rgba(0,0,0,.18)` (Firefox); WebKit 10px, thumb `rgba(0,0,0,.15)` radio 8 → hover `.3`.
- Foco: `:focus{outline:none}`; `:focus-visible{outline:2px solid var(--ring);outline-offset:2px}`; los campos no muestran outline, solo `border-color:var(--ring)`.
- `input[type=checkbox|radio]{accent-color:var(--accent)}`; sin flechas en `input[type=number]`.
- Iconos: set propio estilo Feather/Lucide (`ic($n,$s)` L90), `stroke-width:2`, `round`. Tamaños: 20 raíl, 17-18 menú, 15-16 botones/barra, 28 estado vacío. En front se usa `lucide-react` con `strokeWidth={1.8}` (más fino que el original: 2).

---

## 2. Componentes

### 2.A Componentes globales (erp_nav.php)

#### Botones

| Clase | Estilo exacto | Estados |
|---|---|---|
| `.btn` (primario) | `inline-flex; gap:7px; bg var(--accent) #1f232a; color #fff; border:none; radius 10px; padding 10px 16px; 13.5px/600` | hover `translateY(-1px)` + `0 7px 18px rgba(0,0,0,.16)`; active `translateY(0) scale(.98)` sin sombra; oscuro: fondo `#e5e5e5` texto `#171717` |
| `.btn.ghost` (secundario) | `bg #fff; color var(--ink); border 1px var(--line)` | hover `bg var(--soft); border #dcdcde; 0 5px 14px rgba(0,0,0,.05)`; oscuro `bg --card`, hover `--soft` + `--line-strong` |
| `.btn.danger` | `bg #fff; color #b23b30; border 1px #ecd4d1` | hover `bg #fbf3f2; border #e2bfbb`; oscuro `bg --card; color --danger; border --danger-line` |
| `.btn.sm` | `padding 7px 12px; 12.5px; radius 9px` | combinable: `btn ghost sm` es el más usado (40 usos) |
| `.tbtn` (barra superior) | `border 1px --line; bg #fff; radius 9px; padding 7px 13px; 12.5px/600; color #4c515b; gap 7px; icono 15px` | hover `bg --soft; border #dcdcde; translateY(-1px)` |
| `.qbtn` (acceso rápido) | `border 1px --line; radius 10px; padding 9px 15px; 13px/600; bg #fff; icono 16px muted` | hover borde y texto `--accent` |
| `.icon-btn` | `border none; bg none; padding 5px; radius 6px; color --label; icono 15px` | hover `bg #eef0f3; color #6b7280` |
| `.addbtn` (añadir, ancho completo) | `flex center; gap 7; bg #fff; border 1px dashed #d4d8de; radius 10; padding 10; color --accent; 13.5px/600; margin-top 4` | hover `border --label; bg --soft` |
| `.inline-add` (fila «+ Añadir…» al pie de lista) | `padding 11px 18px; color --label; 13.5px; sin borde; ancho 100%` | hover `bg #fafbfc; color --ink`; el `+` rota 90° (`.18s`) |
| `.rep-row .del` (quitar fila repetible) | `bg #fff; border 1px #f0caca; color #c0392b; radius 9; height 40; padding 0 12` | hover `bg #fdf5f4` |
| Botones de modal `.erpag-btn` | `radius 11; padding 10px 18px; 14px/600`; `.g` `bg #f2f2f3` → hover `#e9e9eb`; `.p` `bg #18181b` → hover `#000`; `:disabled{opacity:.6}` | texto cambia a «Creando…/Agendando…» mientras guarda |
| Botones de diálogo | `radius 10; padding 9px 15px; 13px/600`; `.no` `bg --soft; color #5a5f68` hover `#eeeef0`; `.yes` `bg --ink-strong` hover `#000`; `.yes.danger` `#c0343a` hover `#a82c31` | móvil: 100% ancho, min-height 46px, apilados (`column-reverse`, primario arriba) |

Móvil ≤640: `.btn{min-height:42px}`.

#### Campos de formulario

- `input[type=text|password|number|url|date|email|tel|search|time|month]`, sin `type`, `textarea`, `select:not(.plain)`: `width:100%; border:1px solid var(--line); radius 10px; padding 10px 13px; 14px; bg #fff; color --ink; outline none`. Foco: `border-color:var(--ring)` (`#c4c4c7`). Sin halo.
- `textarea{min-height:70px; resize:vertical}`.
- `select`: `appearance:none`, chevron SVG `stroke #656a72 2.2` a `right 11px center`, `padding-right:32-34px`; foco `border-color:var(--label)`.
- Edición en línea (`.cell`, `.ed`, `.pf-name`): sin borde ni anillo; al enfocar solo `background:var(--soft)`. En oscuro transparentes, hover `--soft`.
- Campo con unidad `.set-f.u[data-u="%"]`: `padding-right:30px` + `::after` con la unidad `right:11px; bottom:9px; 12.5px muted`.
- `.set-f label`: 12px/600 muted, `flex gap 6`, icono 14px `--label` (o logo de marca 17px); `.hint` 11.5px `--label` `margin-top:7px lh 1.5`.
- `.mini-sel` (select compacto de filtro): `width:auto; border --line; radius 9; padding 7px 11px; 12.5px/500; max-width 185px` hover `border --accent`.
- Prompt del diálogo: `bg --soft; border --line; radius 10; padding 10px 12px; 13.5px`; foco `border #c9ccd1; bg #fff; 0 0 0 3px rgba(31,35,42,.06)`.
- Campos de modal `.erpag`: radio 11px, padding 11px 13px, foco azul `#0071e3` + `0 0 0 3px rgba(0,113,227,.12)`.

#### Select personalizado (`.cs-*`, L1127-1141 + JS L2119-2171)
Todo `<select>` (no `multiple`, no `.cell/.ed/.plain`) se envuelve automáticamente:
- Disparador `.cs-trig`: `inline-flex; gap 8; width 100%; bg #fff; border --line; radius 10; padding 9px 12px; 13.5px; lh 1.2`; flecha 15px `--label` que rota 180° al abrir; abierto: `border --label` + `0 0 0 3px rgba(17,19,24,.06)`; deshabilitado `opacity .55`.
- Panel `.cs-pop` (position **fixed**): `bg #fff; border --line; radius 12; shadow-pop; padding 5; min-width 150 (o el ancho del disparador); max-height 288px; overflow auto; z 700`, entra con `pop .14s`.
- Opción `.cs-opt`: `padding 8px 11px; radius 8; 13px; color #4c515b`; hover `--soft/--ink`; seleccionada `bg --accent-soft; color --accent; 600`. Móvil `12px 13px; 14px`.
- Posición: `left = clamp(8, rect.left, innerWidth - max(width,160) - 8)`; si `rect.bottom + 292 > innerHeight` se abre hacia ARRIBA (`bottom = innerHeight - rect.top + 4`), si no `top = rect.bottom + 4`. Al hacer scroll (captura, rAF) se recoloca; se cierra solo si el disparador sale de pantalla. Cierra con clic fuera. Sincroniza con el `<select>` nativo y dispara `change`.

#### Interruptor `.sw` (L866-879)
`40×23px`, pista `#d9dbe0` radio 99, bolita 17×17 blanca `left/top 3px` sombra `0 1px 3px rgba(0,0,0,.22)`; ON pista `--accent` y bolita `translateX(17px)`; `:active` la bolita se estira a 20px; foco `0 0 0 3px --accent-soft`; disabled `.5`; `.sw-guardando` `.5` sin eventos; variante `.sw.ok` ON verde `#12a150` (solo para «está funcionando»). Transición `.18s cubic-bezier(.2,.7,.3,1)`. Oscuro: pista `--line-strong`, bolita `#e9eaec`.
Fila de interruptor (modales): `label.erpag-sw` `flex gap 13; padding 11px 10px; radius 12` hover `--soft`; logo 26×26; título 13.5/600 `--ink-strong`; descripción 12px muted `lh 1.45`.

#### Segmentado `.seg` (L886-894) — también pestañas de vista
Contenedor `inline-flex; gap 4; bg #fff; border --line; radius 11; padding 4`. Segmento `padding 7px 14px; radius 8; 13.5px/600; color #6b7280; gap 7; icono 15px`; hover (no activo) `bg --soft; color --ink`; activo `bg #111318; color #fff`. Contador `.cnt` `11px/700; bg --soft; color muted; radius 99; padding 1px 7px`; en activo `rgba(255,255,255,.18)/#fff`. Oscuro: activo `--rev/--rev-fg`. Transición `.14s`.
Variante «píldora gris» en modales (`.erpag-seg`): `bg --soft; radius 11; padding 3; gap 4`; botón `flex 1; 12.5px/600; #6b7280; padding 8; radius 8`; activo `bg #fff; color --ink-strong; 0 1px 3px rgba(0,0,0,.08)`.
Pestañas subrayadas `.tl-tabs`: `flex gap 2; border-bottom 1px --line; margin-bottom 14` (el detalle de cada `.tl-tab` vive en task.php, ver 2.B).

#### Chips / tags (L796-814)
| Clase | Estilo |
|---|---|
| `.chips` | `flex gap 6 wrap` |
| `.chip` (etiqueta) | `11px/600; bg #eef0f3; color #5c616b; radius 6; padding 3px 8px; lh 1.4; gap 6` |
| `.chip.data` (dato) | `bg #fff; border --line; radius 99; padding 5px 13px; 12px/600; color --ink; <b> --ink-strong` |
| `.chip.pick` (filtro conmutable) | `bg #fff; border --line; radius 99; padding 6px 14px; 12.5px/600; color muted`; hover `--soft/#dcdcde`; `.on` `#111318` fondo y borde, texto blanco |
| `.chip.act` (acción) | `bg #fff; border --line; radius 9; padding 7px 12px; 12.5px/600; color --ink`; hover `--soft/#dcdcde` |
| `.tag` | `11px/600; padding 3px 10px; radius 7; bg --soft; color muted`; `.on` `#ebebec/#3a3d42`; `.off` `#f1f1f2/#6b7076` |
Oscuro: `.chip` `bg --soft color --ink`; con borde → `--field`; activos → `--rev`.

#### Contadores / badges
- Menú lateral `.cnt`: `margin-left:auto; 11px/700; bg #feecec; color #e5484d; radius 99; padding 0 8px` (variantes inline: neutro `background:none;color:--label;padding:0`, gris `--soft/--label`).
- Listas del sidebar `.cnt`: `10px/700; bg #f0f1f4; color #5c626c; padding 0 7px`.
- Campana del raíl `.rbadge`: `18×18; top/right 5px; bg #ef4444; 8.5px/700; borde 2px #0f1012 (recorte)`; texto `9+` si >9; animación `bellPulse` cuando sube.
- Estado «Completada» (front ya): `bg #e4f6ec; color #12854a; 11px/600; radius 6`.

#### Avatares
- Color determinista: paleta `['#4f46e5','#0369a1','#0f766e','#047857','#b45309','#c2410c','#dc2626','#be185d','#6d28d9','#1d4ed8']`, hash `h=((h<<5)-h+byte)&0x7FFFFFFF` sobre bytes UTF-8 (`avatar_color` L163; ya portado a `shared/lib/avatar.ts`).
- Iniciales: 2 primeras letras en mayúsculas (`mb_substr(...,0,2)`), texto blanco 700.
- Tamaños: 46 (tarjeta perfil, 17px), 32 (pie sidebar/raíl, 12px), 24 (selector responsable, 10px), 20-24 en filas.
- Foto: si el miembro tiene foto (`window.ERP_PHOTOS[uid]`) se pinta como `background-image` cover y se oculta el texto (`color:transparent`). Selector global de avatares: `.mav,.cav,.av,.rav,.mini-av,.tm-av,.et-av,.hd-av,.erpta-av,.wsp-av,.cmck-av,.pav,.m-av` con `data-uid` en él o en un ancestro.
- Presencia: punto 12×12 con borde 2.5px del color de fondo, abajo-derecha; colores online `#12a150`, idle `#f0872a`, offline `#c0c4cb`; texto «hace N min/h/d».
- Pilas (`-space-x`), ver 2.B.

#### Tarjeta de perfil al pasar el ratón (`#erpProfCard`, L2590-2640)
Cualquier elemento con `data-uid` → tras **450ms** de hover aparece una tarjeta `264px` (cálculo de posición con 250): `bg #fff; border 1px rgba(0,0,0,.06); radius 18; shadow-profile`; entra `opacity 0 → 1; translateY(10px) scale(.93) → 0/1` (`.18s` opacidad, `.28s cubic-bezier(.34,1.56,.64,1)` transform). Cabecera `15px 16px 14px`: nombre 15.5/750 («Tú» si eres tú), subtítulo 12px/600 `#6b7079` con punto de presencia 7px + estado + «· rol»; avatar 46px con punto. Cuerpo (`border-top --line2; padding 12px 16px; gap 11; 13px`): filas icono 15px `--label` + email / hora local («h:mm am/pm hora local») / «Equipo {marca}». Pie (`bg --soft; padding 11px 12px; gap 8`): botones `.epc-btn` (flex 1, alto 34, radio 10, 12.5/600, borde --line, bg #fff, hover `#f4f5f7/#d9dade`) «Chat» (si no eres tú) y «Ver perfil». Posición: centrada bajo el ancla (`top = rect.bottom + 8`), si no cabe va encima; `left` acotado a `[8, innerWidth - 258]`. Se oculta 180ms después de salir (cancelable si el ratón entra en la tarjeta).

#### Tarjetas y paneles
- `.card`: `bg --card; border --line; radius 16; padding 24px 26px; margin-bottom 20; sin sombra`. Móvil: `18px 16px` y `overflow-x:auto` (tablas dentro).
- `.panel`: igual con `padding 22px 24px`; `h3` 16px/600 `margin-bottom 16`, `flex gap 8`, icono 17px muted.
- `.soonbox` («próximamente»): `border 1px dashed #d4d8de; radius 18; padding 56px 26px; center; muted`.
- Avisos en página: `.ok-note` `bg #eafaf0; border #cfe9d6; color #12854a`; `.err-note` `bg #fbeeee; border #f0caca; color #a32d2d`; ambos `radius 10; padding 10px 14px; 13px; margin-bottom 16`. Oscuro con `--ok-*`/`--danger-*`.

#### KPI (L715-724)
`.kpi`: `bg #fff; border --line; radius 16; padding 22px 24px`; hover `border #e2e2e4; 0 8px 26px rgba(0,0,0,.05); translateY(-2px)`. `.h`: 11.5px upper `ls .4px` muted 600, `flex space-between`, icono 17px `--label`. `.big`: 31px/640 `ls -.7px lh 1` `--ink-strong` `margin-top 12`. `.sub`: 12px muted `margin-top 6`. Puede ser `<a class="kpi">` (enlace al listado que produce la cifra).

#### Tablas (L741-744)
`table{width:100%;border-collapse:collapse}`; `th`: izquierda, 11.5px upper `ls .5px` muted, `padding 11px 12px`, `border-bottom --line`; `td`: `padding 15px 12px; border-bottom --line; 13.5px`; última fila sin borde. Dentro de `.card` en móvil la tabla hace scroll horizontal propio. Filas reordenables: ver arrastre (§4.7). Detalle de filas de tareas (`.ck-row`) en 2.B.

#### Estado vacío `erp_empty($icon,$titulo,$texto,$accion)` (L141, L747-752)
`.erp-empty`: `center; bg #fff; border --line; radius 16; padding 40px 24px; 0 1px 2px rgba(16,19,24,.03)`. Icono `.ei` 60×60 `radius 18; bg --soft; color --label`, svg 28px, `margin 0 auto 16`. Título `b` 18px/600 `ls -.3` `--ink-strong`. Texto `p` 13.5px muted `lh 1.6; max-width 400; margin 7px auto 0`. Acciones `.ea` `margin-top 16; flex center gap 10 wrap`.
Variante simple en listas del menú: `.cli-empty` `padding 8px 12px; 12.5px muted`.

#### Migas `.tk-crumb` (L920-927)
`flex gap 8; 12.5px muted; margin-bottom 16`; enlaces muted → hover `--ink`, con icono (flecha volver) alineado (`inline-flex gap 5`); separador `.sep` `#d4d7dd`; último `span` `--ink` 600.

#### Menús contextuales y mini-menús (`.ctxmenu`, `.addlm`, L993-1006)
`position:fixed; bg #fff; border --line; radius 12; shadow-pop; padding 5; min-width 184; z 200`; `.on` → `display:block` + `pop .15s`. Item: `block; 100%; left; padding 8px 12px; radius 8; color #4c515b; 13px/500`; hover `--soft/--ink`; `.danger` `#c0392b` hover `bg #fde8e8`; separador `.sep` `1px --line; margin 4px 6px`. Cabecera `.alm-h` 10.5px upper 700 muted `padding 7px 10px 5px`. Opción con color `.alm-opt` (`gap 10; padding 9px 11px`) + cuadradito `9×9 radius 3`. Formulario en línea `.alm-form` (`input` radio 8, `padding 8px 10px`, botón `.alm-create` `bg --accent; #fff; radius 8; padding 8px 13px; 12.5/600`, hover `brightness(1.12)`).
Se abren con clic derecho (`oncontextmenu`) en `clientX/clientY`, acotados: `left = min(x, innerWidth-210)`, `top = min(y, innerHeight-200)`. Cierran con clic fuera. Los pasos «renombrar» sustituyen el contenido del menú por un input + «Guardar» (`#111318`), Enter confirma.

#### Desplegable de responsable (modal tarea `.erpta-*`)
Disparador como input (`radius 11; padding 9px 12px; flex space-between`; hover `border #d5d7dc`); lista `absolute; top calc(100% + 4px); radius 12; 0 16px 40px rgba(16,19,24,.16); padding 5; max-height 210`; opción `gap 9; padding 7px 9px; radius 8; 13.5px` hover `--soft`; avatar 24px; «Sin asignar» en muted.

#### Datepicker global (`input.dpick`, CSS L1143-1159, JS L2084-2117)
- Input visible en formato `dd/mm/aa` (placeholder por defecto), valor ISO en `data-iso`; `data-sync="#oculto"` copia el ISO a un input oculto; `data-onchange="fn"` llama `fn(iso, input)`. Acepta escribir `d/m/aa`, `d-m-aaaa`, `d.m.aa` (año <100 → +2000); inválido → restaura el anterior; vacío → borra. Enter = blur/commit.
- Calendario `#dpCal` (fixed, 252px): `bg #fff; border --line; radius 14; 0 20px 50px rgba(0,0,0,.18); padding 12; z 600; pop .15s`. Cabecera: «‹ Mes Año ›» (13px/700 `--ink-strong`), flechas 28×28 radio 8 color `#6b7280` hover `--soft`. Semana empieza en LUNES: «L M X J V S D» 10px/700 `--label`. Días: rejilla 7 col gap 2, botón alto 30 radio 8 12.5px; hover `--soft`; hoy `color --accent; 700`; seleccionado `bg --accent; #fff; 600`. Pie (`border-top --line; margin/padding-top 9`): «Borrar» (hover `#fde8e8/#c0392b`) y «Hoy» (hover `--accent-soft/--accent`), 12px/600 `#6b7280` radio 8 `padding 5px 9px`.
- Posición: `left = min(max(6, rect.left), innerWidth-262)`, `top = min(rect.bottom+6, innerHeight-330)`. Abre en focus/click; cierra al clic fuera (ignorando nodos desconectados por el repintado).
- Móvil: días 36px, flechas 34px.
- `dpSet(input, iso)` rellena sin disparar `onchange` (abrir ≠ editar).

#### Toasts (L2172-2243)
- Contenedor `#erpToasts`: `fixed; right 18; bottom 18; z 1200; flex column gap 8; align flex-end`. Móvil: `left/right 10; bottom 12`, a lo ancho.
- `.erp-toast`: `bg #22262c; #fff; 13px/600; padding 11px 15px; radius 11; shadow-toast; max-width 320; flex gap 9`; entra `opacity 0, translateY(10px)` → `.show` (`.2s`). Prefijo: círculo 18px verde `#12a150` con «✓» 11px. `.err`: `bg #c0343a`, prefijo «!» sobre `rgba(255,255,255,.25)`. `.plain`: sin prefijo.
- `toast(msg, 'err'|'plain')` dura **2.2s** (+.26s salida).
- `toastUndo(msg, tid, {ms, then})`: botón `.undo` (`bg rgba(255,255,255,.16); 12.5px/700; padding 5px 11px; radius 8`, hover `.28`), dura **7s**; al pulsar → texto «…», `POST papelera.php action=restore&json=1&tid=…`; éxito → `o.then(d)` o `location.reload()`; fallo → toast error «No se ha podido deshacer».
- Mensajes por redirección: el pie lee `?msg=` (`cliente-eliminado`, `guardado`, `creado`, `actualizado`, `error-borrado`…) y `$_SESSION['erp_undo']` (aviso con Deshacer tras un borrado con recarga).
- **Diferencia con el front actual** (`shared/ui/Toast.tsx`): está centrado abajo (`left-1/2 bottom-5`), `#111318`, radio 12, sin icono ✓, duración 4s/7s. Debe moverse a abajo-derecha, `#22262c`, radio 11, icono ✓ verde, 2.2s (simple) / 7s (con acción), máx. 320px.

#### Popups de aviso en vivo (`#notifPops`, `.notif-pop`, L1035-1051)
Pila `fixed; top 56; right 6; width 362; max-height 264; overflow-y auto; gap 11; padding 8px 16px 18px; z 600`; con >3 elementos se aplica máscara degradada arriba (`linear-gradient(to bottom, transparent 0, #000 26px)`). Tarjeta: `bg #fff; border --line; radius 14; shadow-notif; padding 13px 14px; gap 11; cursor pointer`; hover `border #dcdcde`. Icono 34×34 radio 10 `bg #111318` blanco (oscuro invertido). Título 13px/650 `--ink-strong` (`actor · título`); subtítulo 12px muted una línea con elipsis (+ «· +N más»). Cerrar «✕» `#c2c6cd` → `--ink`. Entra con `.erp-in`, sale `.erp-out`. Dura **6.5s**; se pausa con hover y al salir quedan 2.5s. Máximo 6 en memoria. Sonido «blip» WebAudio (seno 680→920Hz, 0.3s, ganancia 0.10; chat 620→880Hz, 0.12).

#### Diálogo de confirmación / prompt / alerta (`#erpDlgOv`, CSS L2245-2263, JS L2696-2783)
Máscara `fixed inset 0; z 2000; rgba(16,19,24,.34); backdrop-filter: saturate(140%) blur(2px); padding 20`; fade `.16s`. Caja `.dlg`: `bg #fff; radius 16; max-width 380; shadow-dialog; padding 22px 22px 16px`; entra `translateY(8px) scale(.985)` → normal (`.18s`). Título `h4` 15.5px/650 `ls -.2`; mensaje `p` 13px `#6b7078` `lh 1.55` `pre-line` `margin-bottom 16`. Botones a la derecha gap 8 (ver tabla de botones). Móvil: ancho `min(420px,94vw)`, botones apilados 46px.
API (Promesas):
- `erpConfirm(msg, {titulo='¿Seguro?', ok, cancel, danger})` → `boolean` (ok por defecto «Aceptar», o «Eliminar» si `danger`).
- `erpPrompt(titulo, valor, {placeholder, ok='Guardar', msg})` → `string|null` (vacío = null).
- `erpAlert(msg, {titulo='Aviso', ok='Entendido'})`.
- `erpAsk(msg, {post, data, href, then, danger})` para `onclick` de enlaces; `erpSubmitAsk(form, msg, o)` para `onsubmit` (por defecto `danger:true`); `erpPost(url, datos)` crea un form oculto con `_csrf`.
Teclado: Esc = cancelar; Enter = aceptar (salvo foco en «Cancelar»); foco inicial en «Aceptar» (o en el input y lo selecciona); al cerrar devuelve el foco al elemento previo; clic en la máscara = cancelar.

#### Modales de formulario (`.erpag`: Agendar reunión, Abrir ticket, Nueva tarea — L2314-2670)
Máscara `#erp*Mask`: `fixed inset 0; rgba(16,19,24,.38); blur(4px); z 1200; padding 20`; visible con `.on` (`opacity/visibility .2s`). Panel `.erpag`: `bg #fff; radius 18; width 440; max-width 100%; shadow-modal; overflow hidden`; entra `translateY(10px) scale(.985)` → normal (`.2s cubic-bezier(.2,.8,.2,1)`).
Anatomía: cabecera `.erpag-h` (`flex gap 10; padding 17px 22px; border-bottom --line; 16px/700`) con logo 44×44 (radio 12, borde) + título 16/700 + subtítulo 11.5px muted; segmentado opcional; cuerpo `.erpag-b` (`padding 18px 22px; flex column gap 15`; labels 12px/600 muted `margin-bottom 6`; filas de 2 col `.erpag-row` gap 12); pie `.erpag-f` (`flex end gap 10; padding 15px 22px; border-top --line`) con «Cancelar» (`.g`) y primario (`.p`). Cambio de modo con animación de altura (`height .3s cubic-bezier(.4,0,.2,1)`) y panel `erpagPanelIn .3s`. Validación: toast de error + borde rojo `#ef4444` en el campo que falta + foco. Enter en el título envía. Esc cierra. Clic en la máscara cierra. Al abrir, foco en el primer campo (40ms). Obligatorio marcado con `*` `#ef4444`.

#### Paleta / buscador global Ctrl+K (`#gsOv`, CSS L679-711, JS L2808-2895)
- Disparador en la barra: `.tsearch` `min-width 330; border --line; bg --soft; radius 10; padding 8px 12px; 12.5px muted; gap 9; icono 15`, `kbd` «Ctrl K» (10.5/700, `bg #fff`, borde, radio 6, `2px 6px`); hover `bg #fff; border #dcdcde; color --ink`. ≤860px solo icono.
- Overlay `rgba(16,18,22,.34)` + `blur(2px)`, `z 400`, alineado arriba con `padding-top:11vh` (7vh móvil). Caja `width min(620px,92vw); radius 16; shadow-palette; gsIn .16s`.
- Entrada (`padding 14px 18px; border-bottom`; icono 18 muted; input 16px `--ink-strong` sin borde; badge «Esc»). Resultados `max-height 56vh` (64vh móvil) `padding 6`; secciones `.gs-sec` 10.5/700 upper muted `padding 10px 12px 5px`; fila `.gs-r` `gap 12; padding 11px 12px; radius 11; align flex-start`, icono en caja 32×32 radio 9 `bg --soft`, título 13.5/600 una línea, subtítulo 11.5px muted `lh 1.45` máx. 2 líneas (`line-clamp:2`, `margin-top 4`). Seleccionada `bg --accent-soft` (y su caja de icono blanca). Mensaje vacío `padding 26px 16px; 13px muted`. Pie `padding 9px 16px; 11.5px muted; gap 14` con «↑↓ moverse · Enter abrir · Esc cerrar».
- Lógica: debounce **180ms**; mínimo 2 letras («Escribe al menos dos letras.»); descarta respuestas tardías; `GET buscar.php?json=1&q=` → `{n, grupos:[{g, r:[{t,s,u,i}]}]}`; última fila fija «Ver todos los resultados» → página `buscar.php?q=`. ↑/↓ circulares con `scrollIntoView({block:'nearest'})`; Enter abre el seleccionado (o la página completa). Atajos: **Ctrl/⌘+K** en cualquier sitio; **/** si el foco no está en un campo editable. `gsOpen(texto)` permite abrirla pre-rellenada.
- Front actual: Ctrl+K solo enfoca un `<input>` de la barra; falta la paleta.

#### Raíl, barra lateral y barra superior (ya portados — referencia)
- Raíl `.rail` 66px, `#0f1012`, radio 20, `margin 8px 6px 8px 8px`, sticky `top 8`, `gap 9`, `padding 12px 0`; logo 34×34 radio 10 blanco/800; items 50×50 radio 15, icono 20, etiqueta 9px/600, reposo `#7e838d`, hover `#1e2024` + `translateY(-1px)`, activo `#2a2c31` + barra blanca 3px a `left:-9px` (top/bottom 12). Abajo: engranaje y campana 44×44 radio 13, avatar 32.
- Desplegable del raíl `.rail-fly` (240px, `left:80px`, alto completo, `shadow-fly`): se abre por **intención**: primera vez tras **380ms** quieto; si ya hay uno abierto, cambio instantáneo; cierre con **200ms** de margen; clic en enlace o fuera o Esc lo cierran; clics dentro que no son enlaces (plegar carpeta) NO lo cierran. **(Falta en front.)**
- Sidebar `.side` 240px, título `.sh` 15.5/650 `padding 18px 20px 6px`; secciones `.sec` 10.5 upper `ls .7` 700 `padding 22px 20px 9px` con botón «+» `.secadd` a la derecha; items 14px/500 `#5a5f68` `padding 12px 13px` radio 11 `gap 13`, icono 18 `--label`; hover `--soft` + `translateX(2px)`; activo `--soft` 600 `--ink`. Acordeón de clientes (carpeta con chevron que rota 90°, listas con sangría 24px, items 12.8px, contador). Pie fijo con degradado/blur de 26px por encima, avatar 32 + nombre 13px + rol 11px + engranaje + «Cerrar sesión». Variante colapsada `body.side-collapse` (tira de 16px con asa 3×42 `#d5d5da`, se expande al hover con `16px 0 46px rgba(0,0,0,.13)`).
- Barra superior `.erp-top`: alto 56 (front 53), `padding 0 24`, sticky, `border-bottom --line`; botones `.tbtn`; tema sol/luna.
- Móvil ≤900px: hamburguesa `.nav-burger` → cajón = el raíl convertido en lista con etiquetas (`width min(84vw,300px)`, items `padding 13px 14px` radio 12 `#c9ccd1` 14.5/600, iconos 21px, logout rojo `#e57373` al final), fondo `rgba(16,18,22,.42)`.

#### Popup de cumpleaños (`#bdayOv`, L2907-2963)
Overlay `rgba(16,19,24,.42)` + `blur(5px)` z 1400; tarjeta 400px radio 22 `padding 34px 30px 26px`, `0 34px 90px -20px rgba(16,19,24,.4)`, entra `translateY(14px) scale(.96)` (`.34s cubic-bezier(.2,.9,.3,1.1)`); emoji 56px con `bdayPop`; h2 21/750; p 14px `#5c616b`; botón `.btn` ancho completo; 60 piezas de confeti (`9×14`, radio 2, colores `#ff5e7a #ffcf3f #38c172 #3b82f6 #a970ff #ff9f43`, caída 2.6-4.8s). Una vez al día.

#### Arrastrar para reordenar (CSS L977-990)
- Indicadores de destino: `box-shadow: inset 0 3px 0 #3b82f6` (`.drop-above`), `inset 0 -3px 0` (`.drop-below`), `inset 3px 0 0` (`.drop-left`), `inset -3px 0 0` (`.drop-right`); en el sidebar 2px.
- Elemento arrastrado: `opacity .35` (sidebar `.4`).
- Asa `row_grip()`: 6 puntos (r 1.4) en `10×16`, contenedor 14px ancho, svg 12px, `#c8ccd3`, `opacity 0` → 1 al hover de la fila (`.12s`), `cursor:grab/grabbing`; su clic no abre la fila.
- `cursor:grab` en carpetas/listas (`.folder-drag`, `.list-drag`).

#### Misceláneo
- `.muted` 13px muted `lh 1.55`; `.check` `flex gap 9 margin-top 14`; `.flex` (flex gap 10 center wrap) y `.sp` (flex 1).
- Formato de dinero (`eur`, `eur0`, `eurk`): `1.234,56 €` (miles «.», decimales «,», símbolo detrás con espacio); KPIs sin céntimos; gráficas estrechas `12,4K €`. Meses en español (`mes_nom`, `mes_label` → «Marzo 2026»). Fechas cortas `dd/mm/aa`.
- Círculo de estado de tarea `estado_circle()` (L230): completada círculo `#12a150` lleno + check blanco 2.4; en proceso aro `#3b82f6` 2px + sector relleno (¾); atemporal aro discontinuo `#e0a000` 2.4 `dasharray 3.2 3.2`; en espera aro `#b0b4bb` 2.4. 16px. (Ya en front: `EstadoCirculo.tsx`, con trazos algo más finos.)

### 2.B Componentes definidos en las páginas (candidatos a compartidos)

> Patrón común de todas las páginas: un `<style>` propio + bloque `[data-theme=dark]` que solo reasigna a tokens (`--card` superficies, `--field` campos, `--pop` flotantes, `--soft` hovers/cabeceras, `--rev/--rev-fg` todo lo negro-activo, `--ok-*`/`--danger-*` avisos) + `@media(max-width:640px)` donde las tablas pasan a filas planas de 2 líneas. **No hay skeletons ni spinners en ninguna página**: solo texto «Cargando…» (60px de padding). No hay barras de progreso salvo la píldora «3/5» del checklist.

#### Paletas de datos (¡dos versiones conviven!)

| Concepto | Versión «fuerte» (listas, workspace L14, CRM) | Versión «viva» (detalle de tarea, calendario, soporte) |
|---|---|---|
| Estado tarea: En espera | `#64748b` | `#b0b4bb` |
| En proceso | `#2563eb` | `#3b82f6` |
| Atemporal | `#a16207` | `#e0a000` |
| Completada | `#0f7a3d` | `#12a150` |
| Prioridad 0 Ninguna | `#94a3b8` | `#cfd2d6` |
| 1 Baja | `#64748b` | `#94a3b8` |
| 2 Normal | `#2563eb` | `#3b82f6` |
| 3 Alta | `#b45309` | `#f59e0b` |
| 4 Urgente | `#b91c1c` | `#ef4444` |

Recomendación: el front ya usa la «fuerte» en `features/tareas/constantes.ts` para píldoras/cabeceras; usar la «viva» solo para el círculo de estado (ya así en `EstadoCirculo`) y para chips de calendario. Centralizar ambas en `shared/lib/paletas.ts`.

- Fases del embudo CRM (BD `pipeline_stages`, semilla `lib/crm_lib.php:218`): Lead nuevo `#64748b`, Onboarding `#2563eb`, Onboarding hecho `#1d4ed8`, Propuesta enviada `#a16207`, Negociación `#c2410c`, Contrato firmado `#0f7a3d`, Cerrado ganado `#047857`, Cerrado perdido `#b91c1c`, En pausa `#64748b`; desconocida `#98a2b3`; nueva `#94a3b8`.
- Facturas: borrador `#9aa0a8`, enviada `#3b82f6`, pagada `#12a150`, vencida `#ef4444` (fin-resumen usa `#98a2b3/#2f6df6/#12854a/#e5484d`).
- Tickets (support): Abierto `#3b82f6`, En curso `#7b68ee`, Esperando `#e0a000`, Resuelto `#12a150`, Cerrado `#9aa0a8`.
- Reuniones (perfil CRM): agendada `#6b7280`, realizada `#12a150`, no_show `#c76a12`, cancelada `#c0343a`; agendada ya pasada → borde discontinuo `#e0a000` + fondo `#fffaf0`.
- Tipos de comentario CRM (`.pf-tag` 10px/700 radio 6 `2px 7px`): normal `#eef0f3/#5c616b`, llamada `#e6f6ee/#12854a`, whatsapp `#e3f7ed/--ok`, email `#e8effc/#2f6df6`, reunión `#fdf1e3/#c76a12`. Propuestas: aceptada `#e6f6ee/#12854a`, rechazada `#fdecec/#e5484d`, vista `#e8effc/#2f6df6`.
- Contabilidad: ingreso `#30a46c`, gasto `#e5484d`. Etiquetas CRM: color libre (por defecto `#5b8def`).
- Calendario: Google `#4285F4` (texto `#1a56db`, fondo `#eef4ff`); compañeros `#8e44ad #e67e22 #16a085 #d35400 #2980b9 #c0392b #0f9d58`; festivo `#b08900` sobre `#fbfaf7`.
- Azules de enlace/mención: enlaces del editor `#0071e3`; chat `#2f6fed`; mención en tareas `#5b5fc7` sobre `rgba(91,95,199,.10)`.

#### Píldoras de estado (tres estilos)
1. **Sólida** (fase CRM `.cm-fase`): `inline-flex gap 6; padding 4px 10px; radius 99; 11.5px/700; texto #fff; fondo = color`; punto 7px `currentColor` .9; chevron 10px .75 que rota 180° (`.14s`); hover `0 0 0 3px rgba(0,0,0,.08)`, abierta `.14`. Variantes pequeñas `.pf-badge` (10.5px, `3px 9px`), `.ls-badge` (10px/700, radio 6, `2px 7px`).
2. **Teñida** (facturas, tickets, reuniones): `background: <hex>18` (≈9% alfa; reuniones `1e`), `color:<hex>`; `11px/600-700; padding 3px 10px; radius 99`; punto 7px opcional.
3. **Neutra con punto** (grupo de estado en lista `.gpill`): `11px/700 upper ls .4; bg --soft; radius 7; padding 4px 11px`; punto 8×8 radio 3 del color. Badge de estado del detalle de tarea `.est-badge`: `padding 4px 12px; radius 99; 12px/700 upper ls .2`, fondo = color y texto blanco; «En espera» neutro `#f1f2f4/#6b7079` borde `#e6e7ea`; hover `brightness(.96)`; chevron al final.
- Prioridad: cuadradito 9×9 radio 2 del color + texto 12.5/600 (`.flagp`); vacía «＋» en `--label`. En detalle: punto 11px redondo y texto coloreado; 0 → «Sin prioridad» muted.
- «Completada» `.st-tag.done`: 10.5px/700 upper, `2px 8px`, radio 20, `#e4f6ec/#12854a`.
- «cliente» `.vis-badge`: 9.5px/600 upper, `#f2f3f5`, radio 5, `2px 6px`.
- Aviso de negocio parado `.ng-warn` 10.5/700 radio 6: ≥14 días `#fdf1e3/#9a5410`; ≥30 `#fdeaec/#c62a33`.

#### Pilas de avatares
- Lista de tareas `.asg-stack`: 27px, `border:2px solid #fff` (oscuro `--card`), solape `-11px`, máx. 3 + «+N» (`#c8ccd2`/`#3c4149`, 9.5px). Vacío `.av-none` `#eef0f2` con icono usuario 14px. 1 asignado → nombre; varios → «N asignados».
- Detalle `.mini-av` 24px solape `-9px`; checklist `.pav` 22px solape `-7px`; calendario 16px solape `-6px` (borde 1.5px). Vacío «asignar»: círculo 24px `1.5px dashed #c4c8ce` con «＋» + «Asignar» muted; en checklist dos «fantasmas» a opacidad .32.

#### Lista agrupada de tareas (workspace.php L349-404) — ya portada (TareasPage/TareaFila)
- Tarjeta de grupo `.grp`: borde, radio 14, `mb 20`; cabecera `.grp-h` 13px/600 `padding 15px 18px` `bg #fbfbfc` hover `#f4f5f7`, avatar de cliente cuadrado 24 radio 7, contador píldora `--soft` 11.5/600; plegado recordado en `localStorage['ws_collapsed']` (front: `croilab:ws_collapsed`).
- Grupo por estado `.ck-grp` (vista cliente): cabecera sin caja (`.gpill` + número 12.5/650 muted, chevron que rota −90°); «Completada» empieza plegada; cuerpo con borde radio 12.
- Rejilla `1fr 168px 132px 118px 84px`, gap 10, `padding 12px 16px` (informe `2.4fr 1fr 1fr 70px`). Cabecera de columnas 10.5px upper `ls .5` 650 `bg #fbfbfc`. Fila: hover `#fafbfc`, toda clicable; título 14px/600 (front 13.5).
- Celdas editables `.ws-cell`: radio 8, `min-height 30`, hover `#eef0f3`, `.12s`. Fecha en línea: input sin borde 12.5px `#6b7280`, `padding 5px 6px`, radio 7, hover `#eef0f3`, foco blanco + `inset 0 0 0 2px var(--accent)`. Acción borrar `.ck-x`: hover `#fde8e8/#c0392b`.
- Fila «añadir» `.ck-add`: `+` + input sin borde 13.5px + botón «Añadir» (`--accent`, radio 8, `6px 13px`, hover sube 1px + `0 5px 13px rgba(0,0,0,.15)`); título vacío → abre el modal de creación.
- Móvil: sin cabecera de columnas, fila en 2 líneas (`11px 14px`, título 14.5px), celdas como texto 12px muted, acciones ocultas.
- **No existe tablero kanban de tareas** (el «kanban» del menú es solo un id). El único kanban real es el embudo del CRM (negocio.php).

#### Pestañas de listas `.tl-tab` (subrayadas)
`padding 8px 12px; 13px/600; color muted`; activa `border-bottom 2px var(--accent)`; arrastrables (orden) y con menú contextual. Variante meses `.mes-tab` (`8px 13px`, contador 10px/700). Botón de texto `.txtbtn` 12.5/600 muted → hover `--ink-strong` subrayado offset 3.

#### Menú «crear» rico `.lmenu`
Radio 13, `0 18px 44px rgba(0,0,0,.15)`, padding 6, min-width 278, `pop .16s`; opción `.lmi` padding 10 radio 10 con título 13.5/600 + subtítulo 11.5 muted; cabecera 10.5 upper `ls .5` 650.

#### Página de detalle de tarea (task.php) — ver layout en §3
- Título editable `.tk-title`: input sin borde 27px/600 `ls -.5`, `pb 16` (23px móvil); guarda en `change`.
- Rejilla de propiedades `.tk-fields`: 2 columnas `gap 4px 44px`, bordes arriba/abajo `--line`, `padding 18px 0`, `mb 26`; cada `.tkf` `padding 10px 0`, etiqueta 120px (104 móvil) 12.5/500 muted con icono 15px `--label`. Campos: Estado, Asignados, Fechas (inicio → fin), Tiempo, Prioridad, Etiquetas, Mes. Controles transparentes radio 8 `6px 9px` 13.5px; hover `#f4f5f7`; foco blanco + borde `--label` + `0 0 0 3px rgba(17,19,24,.07)`.
- Fechas `.dwrap`: `padding 5px 9px; radio 9`, hover `#f4f5f7`; separador «→» `#c4c8ce`; **vencimiento**: ≤2 días `#e0a000`/600, pasada `#e5484d`/600 (no aplica si completada; se recalcula al cambiar estado/fecha).
- Tiempo `.tm-pop`: popover 262px, radio 12, `0 12px 30px -10px rgba(16,19,24,.28)`, padding 12; selector de persona `--soft` radio 9; input horas radio 9 foco `--ink-strong`; botón «Guardar» `--ink-strong`; desglose por persona (20px avatar, fila propia en negrita) y total. Enter guarda.
- Indicador «Guardado ✓» `.saved-note`: 12px/600 `--ok`, aparece `.2s` y se oculta a los **1300ms**. Error → toast «No se ha podido guardar. Recarga la página.»; solo lectura → toast propio.
- Checklist: cabecera `.tk-sec` 11px upper `ls .6` 650 muted `margin 30px 0 13px` + píldora progreso `.chk-prog` 11/700 `--soft` radio 99 `2px 9px`. Ítem `gap 12; padding 12px 4px; border-bottom --line2`, hover `#fafbfc`, checkbox 17px. Al marcar: línea de tachado 1.5px `#a9aeb6` que crece `scaleX 0→1 .28s`, texto a `--label`, destello `#eafaf0` .6s, y a los 280ms se reordena al final. Borrar `.cdel` `#c9ccd1` → `#fde8e8/#c0392b`. Añadir: input sin borde, Enter o blur (120ms) crea.
- Adjuntos: miniaturas 92×92 radio 10 cover (abren lightbox); fichero `.att-file` radio 10 `9px 12px` 12.5px nombre ≤180px; quitar `.att-x` círculo 20 en esquina (−7/−7) sombra `0 2px 6px rgba(0,0,0,.1)`.
- **Zona de subida** `.upl`: `2px dashed #d4d8de; radius 14; padding 26px 20px`; columna centrada gap 5; hover `bg --soft`, borde e icono `--accent`; `b` 13.5px, `span` 12px. Arrastre a toda la ventana: overlay `rgba(123,104,238,.12)` z 300 con caja blanca `2px dashed var(--accent)` radio 20 `40px 56px` `0 30px 80px rgba(0,0,0,.22)`, texto 18px. Soltar sobre el panel de actividad adjunta al comentario; fuera, a la tarea. (facturas: zona `.upx-empty` `2px dashed #d6d9df` radio 14 `44px 34px`; overlay `rgba(17,19,24,.55)`.)
- **Lightbox**: `rgba(10,12,16,.62)` + `blur(7px)` z 400; imagen máx. 92vw/92vh radio 10 `0 24px 70px rgba(0,0,0,.55)`, entra `scale(.94)→1` `.24s cubic-bezier(.2,.8,.3,1)`; Esc cierra. (facturas: `rgba(17,19,24,.78)`, caja 900px radio 14, `lbPop .3s`; PDFs con pdf.js 3.11.174 a canvas, máx. 10 págs, fallback `<iframe>`.)
- **Actividad y comentarios** (panel derecho): `h3` 15/600 + contador píldora `#e7e8ea/#5c616b`; línea de sistema 12px muted con punto 6px `#c8ccd2`. Comentario `.cm` `mb 18`: burbuja blanca borde radio 12 `15px 18px` (hover borde `#d9dade`, `bg #fcfcfd`, `0 2px 10px -6px rgba(16,19,24,.18)`), avatar 22px, nombre 12.5/600, hora relativa 11.5 muted («justo ahora», «hace N minutos», «ayer a las…», «27 de jul. a las…»), texto 13.5px `#3a3f47` `lh 1.6`. Barra flotante al hover (arriba-derecha, radio 9, `0 3px 10px rgba(0,0,0,.08)`, aparece `.12s` subiendo 2px) con reaccionar y «más». Pie con 👍, chips de reacción (`.rchip` píldora 12.5px; propia `--accent-soft` borde `#d8d4fb`; rebote `rpop .34s cubic-bezier(.2,1.5,.35,1)`) y «Responder». Cita de respuesta: borde izq. 3px `--accent`, `--soft`, radio `0 8px 8px 0`, autor 12/700, extracto 12.5 con elipsis; clic → desplaza y destella `#fff6db` 1.5s. Enlace profundo `#c<ID>`: resalte `#e9f2ff` + `0 0 0 2px #bcd4ff` 2.5s.
- **Compositor** `.cbox`: radio 12 `10px 12px`; foco `border --accent` + `0 0 0 3px var(--accent-soft)`; editor contenteditable 40-340px; imágenes en línea ≥180×110 radio 11; botón «Comentar» `--accent` radio 8 `7px 14px`. Enter envía, Shift+Enter salto.
- Menú del comentario: Responder, Reaccionar…, checklist; propios: Editar, Eliminar (con `erpConfirm` peligro).

#### Editor de texto enriquecido (lib/rt_editor.php; también descripción de tarea)
- Uso: `rt_editor_assets()` una vez; `<div class="rt-editor" contenteditable data-ph="…" data-emoji-live>` dentro de `.rt-wrap`; `rt_editor_toolbar(id)`; al enviar `rtSerialize(id)`; lectura `<div class="rt-view">` con `rt_blocks()`.
- **Formato guardado: texto tipo markdown, NO HTML**: `# ## ###`, `- `, `1. `, `> `, bloque de código, `---`, tablas `| a | b |`, `[[chk:0/1]]`, `**b** __u__ *i* ~~s~~ \`code\` [t](url)`, `@nombre`, `[[img:FN]]`, `[[file:FN|orig]]`. El front debe leer/escribir ESTE formato (hoy `TareaDetalle` lo trata como HTML y lo pasa a texto plano).
- `.rt-wrap`: solo borde inferior que pasa a `--accent` con foco. Editor min 110-180px, máx. 640px/70vh, `padding 14px 2px`, 14px `lh 1.6`, placeholder `:empty:before{content:attr(data-ph)}`.
- Barra `.cbar.rt-bar` (`padding 4px 0 8px; gap 2`): botones `.tool` padding 6 radio 7 `--label` hover `--soft/--ink`, icono 16; separador 1×18. Orden: Bloques «+», (Adjuntar, solo tarea), Checklist, Emoji | B I U S | Enlace (`erpPrompt`) | Código. Sin estado activo.
- Menú de bloques `.rt-menu`: fixed z 2400, radio 12, `0 18px 46px rgba(16,19,24,.18)`, min 212, máx 70vh, `pop .14s`; icono `.rt-mi` 26×22 `--soft` radio 6 11px/700. Items: Texto, H1, H2, H3 | viñetas, numerada, control | Cita, Código, Tabla, Divisor. Se abre debajo y se voltea si no cabe; `left ∈ [8, innerWidth-232]`.
- Estilos de contenido: h1 1.5em/750, h2 1.28em/700, h3 1.1em/700; cita borde izq. 3px `#d7dae0` color `#5c616b`; `pre` `#f6f7f9` radio 8 `10px 12px` mono .86em; código en línea `#f3f3f5` borde `#e6e7ea` radio 6 color `#c0343a`; enlaces `#0071e3`/500; tablas celdas `5px 9px` borde `--line`, `th` `--soft`/650; checklist leída `.rt-chk` caja 16px, hecha `#e7f7ee/#bfe6cf` + tachado.
- Barra flotante de tabla `.rt-tctl` (z 2500, radio 9, `0 10px 30px rgba(16,19,24,.16)`, 30px sobre la tabla): +col, +fila, −col, −fila (rojos al hover `#feecec`); se oculta 380ms tras salir. Tab/Shift+Tab entre celdas; Tab en la última añade fila.
- Pegado `sanitizePaste`: conserva h1-h3, listas, citas, tablas, código, enlaces http(s), b/i/u/s; elimina estilos, imágenes, scripts. Enter al final de b/i/u/code sale del formato.
- Sin comandos «/», sin autoguardado propio (la descripción de tarea sí: debounce 700ms + blur).

#### Menciones @
- Detección: `/@([\p{L}0-9_.\-]*)$/u` antes del cursor; prefijos primero; máx. 6 (chat). ↑/↓ mueven, Enter/Tab insertan, Esc cierra, blur cierra a 150ms.
- Popup tareas `#mnPop`: fixed en `getClientRects()` del cursor, `left ∈ [8, innerWidth-240]`, se voltea arriba si quedan <220px; min 170, máx. alto 220; fila `7px 9px` radio 8; seleccionada `--accent-soft` con avatar 22px y anillo `0 0 0 2px var(--accent-soft), 0 0 0 3.5px var(--accent)`. Chat `.ch-mentionpop`: absoluto `bottom 64 left 14`, radio 12, min 200, avatar 26.
- Chip insertado: `span.mention` `contenteditable=false`, `#5b5fc7`/600 sobre `rgba(91,95,199,.10)`, radio 6 `1px 5px`, avatar 17px dentro (chat: solo texto 700 `#2f6fed`).

#### Selector de emojis (assets/emoji/component.js + emoji.css)
- Emojis Apple por sprite `sheet.webp` (62×62, 1.8MB): `.ap-e` `1.25em`, `vertical-align:-.25em`, `background-size:6200% 6200%`, posición `(x*100/61)% (y*100/61)%`, `data-e=<carácter>`. Un MutationObserver (90ms) reemplaza emojis en todo el texto salvo inputs/textarea/`.no-emoji`.
- API: `erpEmojiPicker(anchor, onPick?, input?)`, `erpEmojiPickerXY(x,y,cb)`, `erpEmojiClose()`, `erpEmojiInsert(field,char)`. Atajo global **Ctrl/⌘ + .** sobre el campo enfocado.
- Botón auto-anclado `.emx-btn` (26×26, abajo-derecha del campo, radio 8, oculto; visible .55 con foco/hover; 1 + `--soft` al hover) en todo textarea/contenteditable (inputs solo con `.emoji-field`/`data-emoji`).
- Panel `.emx`: fixed z 2600, 340px (~430 alto), radio 14, `0 18px 46px rgba(0,0,0,.17)`, `pop .15s`; buscador `--soft` radio 10 `8px 11px` 14px; pestañas de categoría alto 32 radio 8 opacidad .7, activa `--accent-soft` + subrayado 2px `--ink` (inset 22%, bottom −6); cuerpo alto 300, rejilla 8 col gap 1, celda cuadrada radio 8 sprite 26px; pie con el nombre 12px muted. Categorías: Recientes, Caras y emoción, Personas, Animales y naturaleza, Comida y bebida, Actividades, Viajes y lugares, Objetos, Símbolos, Banderas. Recientes en `localStorage['erpEmojiRecientes']` (32). Elegir NO cierra. Flechas + Enter + Esc. Búsqueda: nombre exacto > prefijo > prefijo de palabra clave > contiene (máx. 250). **Sin modo oscuro** (hardcodea `#fff`): corregir al portar.
- Reacciones rápidas (chat): `['👍','❤️','😂','😮','😢','🙏','🔥','👏']` + «+».

#### Tabla tipo hoja de cálculo (CRM, crm.php L300-683)
- Contenedor `.cm-wrap`: borde, radio 16, `overflow:auto; max-height:calc(100vh - 250px); min-height:320px; overscroll-behavior:contain`.
- `table.cm` 13px `nowrap`; `th` 11px upper `ls .5` muted 650 `padding 14px 12px` `bg #fcfcfd` **sticky top 0**; primeras columnas sticky (checkbox 40px `left:0`, Nombre `left:40px`, z 3/5, borde derecho `box-shadow:1px 0 0 var(--line)`); `td` `padding 10px 12px; border-bottom --line2`; hover `#fcfcfd` (sticky → `--soft`).
- Celda nombre: avatar 30 + nombre 13.5/600 + etiquetas sólidas debajo (10px/700 radio 5 `1px 7px`).
- Celdas editables `td.ed`: input/select transparente radio 7 `6px 8px` 13px min 90 (texto máx. 200 con elipsis y `title`); hover `--soft`; foco `#fff` + `0 0 0 2px rgba(17,19,24,.08)`; numéricos 88px a la derecha. Guarda en `change` (sin debounce): éxito `toast('Guardado')`, error → restaura valor previo + toast error.
- Próxima acción vencida: texto `#c0343a`/600 + punto 7px `#e5484d`.
- Orden por cabecera (enlace GET, flecha « ↑ / ↓» en texto). Checkbox 15px `accent-color:--ink-strong`, «todos» con `indeterminate`.
- Menú de fila «⋯» `#c2c6cd` visible al hover (`.12s`) = mismo menú que clic derecho `.cm-ctx` (radio 11, `0 16px 44px rgba(16,19,24,.18)`, min 196, z 700, cabeceras 10.5 upper, peligro `#e5484d` hover `#fdeaec`).
- **Barra de acciones en lote** `.cm-bulk`: fija abajo-centro `bottom 26`, oculta `translateY(150%) opacity 0` → visible (`transform .22s cubic-bezier(.2,.7,.3,1)`, `opacity .18s`); `bg #fff; borde; radio 14; 0 18px 50px rgba(16,19,24,.18); padding 7px 8px 7px 14px; z 520`; contador píldora 22px `--accent` blanco 11.5/700; separadores 1×20; botones `8px 10px` radio 9 12.5/600 hover `--soft`; peligro `#c0343a` (hover `#fdecec/#a52a30`). Acciones: Asignar, Añadir a lista, Etiquetar (abren popover encima), Exportar CSV, Eliminar (confirm), ✕. Esc sin popups abiertos = deseleccionar. Móvil `left/right/bottom 8`.
- Barra de filtros: buscador `.cm-search` (flex 1, 220-440px, radio 11, `9px 13px`, icono 16, input 14px, envía en change); botón Filtros `.cm-fbtn` (radio 11 `9px 14px` 13/600; activo borde/texto `--ink-strong` + contador píldora oscura 10.5/700); píldoras rápidas (`6px 12px` radio 99 12/600; activa `--ink-strong` blanco: Todos, Sin contactar, Sin actividad +7/+30 días, Acción vencida, Cerrado perdido); panel plegable `.cm-panel` (radio 14 `20px 22px`, rejilla 4 col → 2 ≤900 → 1 ≤640, gap `16px 18px`, etiquetas 10.5 upper, pie «Limpiar filtros»); chips de filtro activo `#eef2fb/#33507f` radio 8 `5px 6px 5px 11px` 12/600 con ✕ (hover `#e5484d`); desplegable de vistas guardadas `.cm-dd` (radio 12 `0 16px 44px rgba(16,19,24,.16)` min 220, «Guardar filtros actuales» con `erpPrompt`, guarda `location.search`).
- Popover de opciones con check `.cm-pop` (fases, servicios…): radio 12, `0 16px 40px rgba(16,19,24,.18)`, padding 6, z 560, min 198, máx 62vh; cabecera 10.5 upper; ítem `8px 10px` radio 8 13px con punto 9px y ✓ en el activo (650); acotado a 8px de los bordes y volteo arriba; cambio optimista con reversión; se cierra con clic fuera, Esc o scroll de ventana.
- Atajos: `/` busca, `n` nuevo contacto, Esc cierra; en la ficha ←/→ registro anterior/siguiente.
- Móvil: cada fila = ítem de 2 líneas (avatar 38 a la izquierda, nombre 15px, «empresa · fase» 12.5 muted, checkbox a la derecha 20px, toda la fila abre la ficha).

#### Ficha de registro en modal grande (perfil de contacto CRM)
Máscara `rgba(16,19,24,.4)` + `blur(5px)` alineada arriba `padding 3vh 0` z 600, fade `.2s`; caja 1020px (`max 95vw`, `max-height 94vh`, scroll) radio 20 `0 40px 90px rgba(0,0,0,.35)`; pantalla completa ≤700px. Cabecera sticky `22px 28px`: avatar 48, nombre editable 21/650 (máx 360), subtítulo 12.5 con badge de fase, navegación ‹ › 34×34 radio 9 + «3 / 40» + cerrar 34 `--soft`. Fila de acciones `16px 28px` (botones radio 10 `9px 14px` 13/600 icono 15: nota, email, llamar, agendar, convertir; «ya es cliente» `#bfe3cc/#f2fbf5/#12703f`). Cuerpo 2 columnas (1 ≤820) con divisoria `--line2`, columnas `24px 28px`, secciones `.pf-sec` 12px upper `ls .5` 650 `margin 24px 0 12px`. Compositor de notas con selector de tipo (`.pf-tp` radio 8 `5px 11px`, activo `--ink-strong`), textarea radio 10 min 60. Línea de tiempo de actividad: filas `gap 10; padding 7px 0; border-top --line2; 12.5px`, punto 6px `#c4c8ce`, fecha 11.5 muted (máx. 60).

#### Kanban del embudo (negocio.php)
- Tablero `.ng-board`: flex gap 14, `overflow-x:auto`, `align-items:flex-start`, `pb 14`. Columna `flex:0 0 264px; bg --soft; borde; radio 14; max-height calc(100vh - 250px)`; móvil 82vw con `scroll-snap`.
- Cabecera `14px 14px 10px`: punto 9px del color de la fase, nombre 13/650, contador píldora blanca 11.5/600; asa para reordenar columnas (dueño).
- Tarjeta `.ng-card`: `#fff; borde; radio 11; padding 13; 0 1px 2px rgba(16,19,24,.03); cursor grab`; título 13.5/650; «empresa · sector» 11.5 muted; fila inferior (gap 6, mt 10): valor 13/750 `--ok`, chip de servicio, etiquetas, avatar 22, fecha de cierre 11px con icono 12; «⋯» al hover.
- Pie de columna: `border-top; 11px 14px; 11.5px` «Total» + importe 650. Vacío «—».
- Arrastre: tarjeta `opacity .4`; columna destino `outline: 2px dashed #2f6df6; outline-offset:-2px; bg #eef4ff`; al soltar se mueve en el DOM, `POST move_deal` y se refrescan solo totales/métricas; soltar en «perdida» abre modal de motivo; se suprime el clic 50ms tras arrastrar.
- Métricas `.ng-metrics`: 5 col (3 ≤1100, 2 ≤600) gap 12; tarjeta radio 14 `16px 18px`; etiqueta 11 upper; valor 19/750 `ls -.4`; sub 11.5.

#### Gráficas
- **Chart.js 4.4.1 (CDN)** en crm_dashboard y fin-resumen; facturas/contabilidad sin gráficas. Defaults: fuente heredada 11.5px, color `#9aa0a8`, rejilla `#f0f0f2`, leyenda `boxWidth 12 padding 12`. Barras `borderRadius 6`, `maxBarThickness 46` (horizontales con `indexAxis:'y'`); donut `cutout 62%` borde blanco 2 leyenda a la derecha; líneas `tension .35` relleno 10% alfa. Paleta `#5b8def #12a150 #f0872a #e0a000 #7c9cf5 #ef4444 #12854a #94a3b8 #a855f7 #06b6d4`. Línea de resumen financiero: `#111318` ancho 2 sin puntos (hover 5), degradado `rgba(17,19,24,.10)→0`, tooltip `#111318` padding 10 radio 8, eje Y rejilla `#f0f1f3` en «K», X sin rejilla. Caja de gráfica 240px (220 móvil) / 270px. Vacío «Sin datos todavía» (padding 60px 0).
- KPI de dashboard CRM `.db-kpi`: radio 14 `18px 20px`, valor 22/750, etiqueta 11 upper. Tarjetas `.db-card` radio 16 `20px 22px`, h3 15/650, rejilla `repeat(auto-fit,minmax(260px,1fr))` gap 16, `.wide` a toda la fila. Filtro de fechas con atajos en píldora (`6px 12px`, activo `--accent`).
- KPI financieros `.rs-c`: radio 16 `22px 24px`, tintes (ingresos `#f4faf6`, impuestos `#fbf5f3`, neto `#f3f6fc`), icono 38 radio 11 `--ink-strong`, importe 29/750 `ls -1`, delta píldora 11.5/600 (sube `#12854a`, baja `#e5484d`). Navegador de mes ‹ Mes › (botones 32 radio 8, etiqueta 13.5/600 min 140, «Hoy» en acento). Pestañas de ámbito `.co-scope` (caja blanca radio 10 padding 3; activo `--ink-strong`). Cascada `.wf` (filas `13px 2px` 14px `tabular-nums`, totales con `border-top 1.5px` y 17px).

#### Facturas (facturas.php)
- Navegación por niveles (no pestañas): hubs de emisor → Ingresos/Gastos → carpetas de mes → tabla. Migas dentro del h1 (`›` en `--label`).
- Hub `.fc-hub`: 2 col gap 22, `min-height 300; radio 22; padding 44px 40px`; hover `translateY(-4px)` + `0 18px 48px rgba(0,0,0,.09)` + borde `#d7d7db`; icono 70 radio 20 `--accent` (ingresos `#12854a`, gastos `#c0392b`); nombre 26/780 `ls -.6`; stats valor 20/750 etiqueta 11 upper.
- Carpetas de mes `.fc-mon`: `auto-fill minmax(210px,1fr)` gap 18; radio 16 `24px 22px 22px`; hover `translateY(-3px)` + `0 12px 34px rgba(0,0,0,.08)`.
- Tabla `.fc-row` (div-grid): `110px 1fr 120px 120px 120px 40px`, gap 10, `13px 18px`, hover `#fafbfc`, fila clicable, cabecera `#fbfbfc` 10.5 upper; nº 13/700; avatar 26; «⋯» 19px.
- Editor `.fe-layout`: `minmax(0,1fr) 470px` gap 24 (1 col <1150); tarjetas plegables `.fe-card` (radio 16 `24px 26px`; h3 13px upper muted; ▾ rota −90°); rejilla 2/3 col gap 16; campos radio 9 `10px 12px` 13px; segmentado de periodo `.pd-seg` (`--soft` radio 12 padding 4; opción `9px 18px` radio 8 12.5/600; activa `--ink-strong` + `0 3px 10px rgba(0,0,0,.16)`); pie **sticky** `bottom 0` `bg --bg` `padding 14px 0`.
- Líneas: `1fr 72px 100px 100px 30px` gap 8 (móvil `1fr 46px 66px 66px 24px`); total de línea `--soft` radio 9; añadir línea `1px dashed #d4d8de` radio 10.
- Vista previa en vivo `.pv` (sticky `top 14`), papel siempre blanco (redefine tokens claros localmente), radio 16 `0 14px 44px rgba(0,0,0,.08)` padding 24, entra `pvIn .45s cubic-bezier(.22,1,.36,1)`.
- Hoja imprimible `.inv-sheet`: máx 680, radio 18; «FACTURA» 36/850 `ls -1.3`; nº en píldora `1.5px solid --ink-strong`; caja emisor/cliente 2 col borde 1.5px radio 16; cabecera de tabla negra con texto blanco 11px upper; total en barra negra radio 12 importe 23/850; `@page{margin:0}`, `print-color-adjust:exact`, `.no-print`.

#### Chat (chat.php)
- Layout a pantalla completa (`height:calc(100vh - 56px)`, sin padding): lista de salas 290px + panel. Cabecera de lista (h2 17/600, botón nuevo 30×30 radio 9 `--accent`), buscador sin borde `#f0f0f2` radio 10 `9px 11px 9px 33px`.
- Sala `.ch-item`: `11px 12px` radio 12 gap 12; hover `--soft`, activa `--accent-soft`; avatar 44 redondo (grupo radio 14); nombre 14.5/600; hora 11.5 muted; vista previa 13px muted; no leída: nombre 650, vista previa `#3c4149`/500, hora en acento/700, contador verde `#12a150` píldora 20px 11/700; punto de presencia 11px con anillo blanco 2.5px.
- Cabecera de conversación alto 60, avatar 38 radio 12, «en línea» `#1a9d5b`/600 o «escribiendo…».
- Feed `padding 26px 30px`; separador de día píldora 11px blanca con borde; divisor «Mensajes nuevos» 10.5/700 upper `#2f6fed` sobre `rgba(47,111,237,.08)`.
- Burbuja: máx. 74%; ajena blanca con borde radio 14 (esquina sup.-izq. 4) `10px 15px`; propia `--accent` texto blanco (esquina sup.-dcha. 4), alineada a la derecha sin avatar; agrupación de mensajes seguidos del mismo autor (margen 2, sin avatar/nombre, esquinas 14); texto 13.5 `lh 1.55`; hora 10px; checks de leído (gris `#cdd4dd`, leído `#53bdeb`); editado 10px cursiva; borrado «🚫 Este mensaje fue eliminado». Cita: borde 3px `#2f6fed` sobre `rgba(47,111,237,.07)`. Adjuntos: imagen máx 260×320 radio 10; fichero `rgba(0,0,0,.05)` radio 9. Reacciones en píldoras 12px (propias `--accent-soft` borde `#c7d2fe`). Acciones al hover fuera de la burbuja (caja radio 9, botones 26×26).
- Compositor: textarea radio 12 `11px 15px` 44-120px auto-crece, foco acento + halo; herramientas 38×38 radio 10 (emoji, adjuntar, micrófono; grabando rojo `#ef4444` con pulso); enviar 42×42 radio 12 `--accent`; barra de respuesta/edición `#f6f7f9` con regla azul; adjuntos pendientes en chips con miniatura 34px; indicador «escribiendo» 3 puntos 6px `#b7bcc4` (`chBlink 1.2s`); botón flotante «bajar» 40px con contador.
- Modal «Nuevo mensaje» 392px: lista de personas (avatar 44, nombre 15/550, círculo de selección 24px), al elegir 2+ aparece «nombre del grupo» (`max-height 0→80 .3s`), botón que cambia «Elige a alguien» → «Enviar mensaje» → «Crear grupo · N».

#### Calendario (calendar.php)
- Cabecera: logo 52 radio 14; ‹ › 34×34 radio 9 con borde; «Hoy» radio 9 `8px 14px`; selector de vista (caja blanca radio 10 padding 3; enlace `6px 13px` radio 7 12.5/600 `#6b7280`; activo `#111318`) Mes/Semana/Día/Agenda; leyenda con cuadraditos 9px radio 3.
- Mes: rejilla radio 16; cabecera de días `#fbfbfc` 11px upper 650; celda min 126 (80 ≤900, 62 móvil) `9px 10px` gap 5; relleno `#fcfcfd`; nº de día 24×24 radio 7 12/600 (hoy `--accent` blanco); chip de tarea 11.5px `3px 7px` radio 6 `--soft` con borde izq. 3px del estado; evento Google `background:{color}1e; border-left-color:{color}; color:{color}`; «+N más» 10.5px (máx. 4 tareas / 2 eventos).
- Semana/Día: 46px por hora; columna de horas 56px; líneas `repeating-linear-gradient(to bottom, var(--line2) 0 1px, transparent 1px 46px)`; columna de hoy `#fafcff`; hueco hover `rgba(66,133,244,.06)` (clic crea); evento absoluto radio 6 `3px 6px` 11px fondo `{color}22` + borde izq. 3px, min 22px; solapes en columnas; línea de ahora 2px `#ef4444` con punto 8px; scroll inicial a las 07:00. Arrastrar/redimensionar eventos propios (asas 7px, snap 15 min, umbral 4px, tooltip `#111318` «HH:MM – HH:MM»).
- Popover de evento 290px radio 14 `15px 16px` `0 20px 50px -16px rgba(16,19,24,.34)`. Modal de evento 470px radio 18 (`0 30px 80px -24px rgba(16,19,24,.5)`), inputs `--soft` radio 10. Autocompletar de correos (máx. 6, ↑↓ Enter Esc, memoria `erpEmailMem`).
- Atajos: `n` nuevo, `m/s/d/a` vista, `t` hoy, ←/→ anterior/siguiente, Esc cierra, Ctrl/⌘+Enter guarda (ignorados mientras se escribe).

#### Reuniones / Actas / Soporte
- Reuniones: ancho máx. 1000; segmentado estilo iOS `.reu-tabs` (`#f1f2f4` radio 12 padding 4; botón `8px 18px` radio 9 13/600 `#6b7079`; activo blanco + `0 1px 3px rgba(16,19,24,.12)`; contador) Próximas/Pasadas/Notas con entrada `reuIn .25s`; separador de mes 12/650 upper + regla. Tarjeta `.reu-card`: flex gap 16 `18px 20px` radio 16 `0 1px 2px rgba(16,19,24,.03), 0 10px 26px -20px rgba(16,19,24,.14)`; bloque fecha 50px (día 21/750, mes 11 upper); título 14.5/650; píldora de cliente `--soft`; chip de documento `#f2f2f3` radio 9.
- Actas: escala de espacios propia `6/10/14/20/28/40/56`; lista máx. 812, documento máx. 728; tarjeta radio 16 hover `#dcdde1` + `0 8px 26px rgba(16,19,24,.07)`; fijada con barra izq. 3px `--accent` (.55) + «Fijada»; título 17/650, extracto 13.5 2 líneas; acciones al hover arriba-derecha; filtros de autor en píldora con avatar 16; documento: título 29/600 `ls -.6`, filas meta (etiqueta 132px), cuerpo 15px `lh 1.75`.
- Soporte: KPIs 4 col radio 14 `20px 22px` (cifra 25/650); tabla div-grid `1fr 150px 120px 120px 96px` `17px 22px`; detalle `1fr 300px` gap 20 (tarjeta radio 16 `28px 30px`; barra lateral con selects que se guardan al cambiar).

#### Superposiciones y modales — inventario de valores (para unificar)
| Sitio | Máscara | Caja |
|---|---|---|
| Confirmar (global) | `rgba(16,19,24,.34)` blur 2 saturate 140% | 380, radio 16 |
| Modales globales `.erpag` | `rgba(16,19,24,.38)` blur 4 | 440, radio 18 |
| Crear tarea (workspace) | `rgba(17,19,24,.34)` blur 4, alineado arriba `padding 56px 18px` | 640, radio 16, `0 30px 90px rgba(0,0,0,.28)`, pie `#fcfcfd` |
| Reuniones | `rgba(17,19,24,.4)` blur 4 | 520, radio 18 |
| Calendario | `rgba(16,19,24,.32)` | 470, radio 18 |
| Chat / soporte | `rgba(20,22,28,.5)` | 440/520, radio 18 |
| CRM pequeños | `rgba(16,19,24,.34)` blur 4 | 400-440, radio 18, `26px 28px` |
| Ficha CRM | `rgba(16,19,24,.4)` blur 5 | 1020, radio 20 |
Propuesta: máscara única `rgba(16,19,24,.36)` + `blur(4px)`; tamaños sm 380 / md 440 / lg 520 / xl 640 / full 1020; radio 18 (16 para confirm); entrada `translateY(8px) scale(.985)` → normal `.2s cubic-bezier(.2,.7,.3,1)`.

### 2.C Componentes del resto de pantallas (dashboard, ajustes, clientes, avisos, perfil, equipo, bóveda, papelera, búsqueda, login, portal)

- **Tarjeta de dashboard `.dsh-card`**: `#fff; border 1px rgba(16,19,24,.06); radio 18; padding 24px 26px; sombra 0 1px 2px rgba(16,19,24,.03), 0 12px 30px -22px rgba(16,19,24,.14)`; cabecera `flex space-between mb 16`, h3 16/650 con icono 16 muted, enlace «Ver todo» 12.5/600 muted. Móvil `15px`, radio 14.
- **Saludo** `.dsh-hi`: h1 28/650 `ls -.5` «Buenos días, {nombre} 👋» + sub 13.5 muted; a la derecha **KPI en píldora** `.dsh-kpi` (`#fff`, borde, radio 13, `12px 18px`, etiqueta 11/600 muted, valor 19/700 `ls -.4`; hover borde `#dcdee2` + `0 10px 24px -18px rgba(0,0,0,.5)`).
- **Tarjeta de acceso rápido** `.acc-card` (rejilla `auto-fit minmax(220px,1fr)` gap 18): borde, radio 16, padding 24, columna gap 14; icono 44×44 radio 13 glifo blanco 21 sobre color sólido (`#64748b #34c759 #5e5ce6 #0a84ff`); título 15/650; descripción 12px muted `lh 1.5`; «Abrir ›» 12.5/600 `#6b7280` abajo; hover sube 2px + `0 14px 30px -20px rgba(0,0,0,.4)`. Variante en ficha de cliente `.cf-q` (6 col → 3 ≤1080 → 2 ≤600; padding 18, radio 14, icono 34 radio 10; colores `#64748b #e0a000 #4285F4 #0ea5e9 #a855f7 #34c759 #ef4444 #5e5ce6`).
- **Fila de lista con «tesela de fecha»** `.dsh-row`: `gap 14; padding 15px 2px; border-top --line2` (no la primera); tesela 44px `--soft` radio 10 (día 15/700, mes 9.5/700 upper `ls .4`); vencida `#fdecec/#c0343a`; ok `#e7f7ee/#0f7a3d`; título 13.5/600 (hover `#0071e3`), sub 12 muted; tesela de icono 30×30 radio 9 fondo `color+1e`; etiqueta «Urgente» 10/700 blanco sobre `#ef4444` píldora.
- **Fila con icono (ficha de cliente)** `.cf-row`: `gap 13; padding 14px 2px; border-top --line2`; icono 30 radio 9 `--soft`; título 13.5/600; sub 12 muted; valor derecho 12.5/600 muted. Encabezado de tarjeta tipo «ceja» 12px/650 upper `ls .5` muted + enlace «Abrir →» `#0071e3`.
- **Pestañas píldora gris** (dashboard `.dsh-tabs`, reuniones, perfil): `bg --soft (#f1f2f4 / #e9e9ec); radio 10-12; padding 3-4`; botón 12.5-14/600 `#6b7280`; activo `#fff` + `0 1px 3px rgba(0,0,0,.08)`; paneles con fundido `translateY(4-6px)` `.22-.3s`.
- **Pestañas subrayadas con dos líneas y contador** (avisos `.nt-tab`): `padding 12px 22px 12px 0; margin-right 26; border-bottom 2px transparent → --ink-strong`; título 14/600, subtítulo 11.5 `--label`; contador píldora blanca 11/700 sobre color (no leídas `#ef4444`, otros `#7b8794`, chat `#12a150`, pospuestas `#e0a000`).
- **Mini calendario** (dashboard): rejilla 7 col gap 8; celda `aspect-ratio:1` radio 9 12.5px (hoy `#3c4149` blanco); puntos de evento 5px; píldora de mes `#eaf3ff/#0071e3`; popover «cristal» `rgba(255,255,255,.72)` + `blur(22px) saturate(1.6)` radio 16 236px `0 20px 50px -16px rgba(16,19,24,.28)`.
- **Estado vacío compacto** (`.dsh-empty`): icono 38 radio 11 `--soft`, título 13.5/700, padding `22px 10px`; dentro de tarjeta llena (min-height 150, icono 60 radio 18, título 17px, texto ≤240px).
- **Tarjeta de sección de ajustes** `.set-card`: `--card`, borde, radio 16, `20px 22px`, `mb 16`; h3 15.5; sub 12.5 muted `mb 16`; inputs hover `#fcfcfd/#dcdde0`, foco `--accent` + `0 0 0 3px var(--accent-soft)`; deshabilitado `--soft`. Ancho máx. de página de ajustes 1180.
- **Buscador de ajustes** `.aj-find` (230px): resultados en popover (radio 12, `0 18px 44px -20px rgba(0,0,0,.4)`, máx 340) y al elegir resalta el campo con `ajPing 1.1s ×2` (anillo `rgba(59,130,246,.45)` hasta 7px).
- **Indicador «Sin guardar»** `.aj-flag`: 12/600 `#b7791f` con punto 7px `#e8a33d`, junto al botón; formularios `.aj-save` se envían por fetch → `toast('Guardado')`; aviso `beforeunload` si hay cambios. Equipo: «Hay cambios sin guardar» que aparece con `.2s`.
- **Barra de guardar fija** `.ed-guardar` (edición de cliente): `position:sticky; bottom:0; z 20; rgba(255,255,255,.92) + blur(6px); border-top --line; margin 0 -44px; padding 14px 44px`; primario + «Cancelar» ghost + nota 12.5 muted a la derecha.
- **Fila con interruptor en caja** `.ed-sw`/`.te-chk`: `gap 14; padding 15px 17px; borde; radio 13; bg --bg`; hover `#dcdde0` + `--soft`; activa borde `#d6d7db` + `--accent-soft`; título 13.5/600, descripción 12.5 muted. Regla automática `.set-rule`: icono 38 radio 11 `--accent-soft`, título 14.5/600, texto 13 muted, `.sw.ok` verde.
- **Mini-tabla de filas repetibles** `.ed-lista`: caja radio 12; cabecera `#fbfbfc` 11px upper 650 `10px 14px 9px`; fila `10px 12px` hover `#fbfbfc`; inputs sin borde (hover `#f1f2f4`; foco blanco + `--accent` + halo `--accent-soft`; placeholder `#c2c6cc`); borrar visible solo al hover; `.inline-add` debajo.
- **Rejilla clave/valor** `.cf-fisc`: 2 col `gap 18px 24px`; etiqueta 10.5/650 upper `ls .4` `mb 5`; valor 14/600; vacío «—» muted 400.
- **Barra de fases** `.cf-pbar`: segmentos 6px radio 99 `#eceef1`, activos `--accent`, gap 5. (Portal: barra 11px, anillo `conic-gradient` 142/106px.)
- **Aviso de contraseña nueva** `.cf-pass`/`.te-clave`: `#eef7f0` borde `#cde8d5` texto `#12603a`, radio 12-16; código 18/700 `ls 1.5`.
- **Zona de peligro**: simple (`.cf-danger`: `border-top`, `mt 26 pt 20`, texto muted + `.btn.danger`) o en caja (`.te-baja`: borde `#f2dede`, fondo `#fffafa`, radio 16, `18px 22px`, título `#8f3034`, texto `#a86a6c`).
- **Insignias de rol**: «Admin» `#fff4e5/#f3dcbf/#b7791f` píldora 10/700 upper; corona 19-22px `#e8a33d` con borde blanco sobre el avatar; rol en píldora `--accent-soft` 12/600 (dueño `#fdf3e3/#96631a`).
- **Tarjetas de opción** `.te-op` (`auto-fit minmax(250px,1fr)` gap 16; radio 16 padding 22; icono 42 radio 13 `--soft` `#6f757e` que se invierte a `--accent`/blanco al hover; sube 2px).
- **Lista de clientes** (index.php): tabla global dentro de `.card`, `td padding 18px 0`; avatar de cliente cuadrado 34 radio 9 (escala 1.06 al hover de fila); nombre 14.5/600 en `--accent`, subrayado al hover; fila inactiva `bg --line2` + avatar .55 + `.tag` «No activo» `#feecec/#c0343a`; acciones ocultas hasta hover/focus-within (siempre visibles ≤760); barra de filtros `.seg` con contadores (En alta / No activos / Todos) + buscador con icono a 12px (`padding-left 36`) + select 186-240px.
- **Fila de aviso** `.nt-row`: rejilla `22px minmax(150px,300px) 1fr auto` gap 16 `15px 22px`, hover `#fafbfc`; leída fondo `--soft`; seleccionada `--accent-soft`; columna 1 círculo de estado/checkbox; título 14/600 (700 no leída); actor con avatar 22 + línea 13 muted; derecha: punto no leído 8px `#ef4444`, píldora «pospuesto» `#fff7e6/#c08a00` 11.5, hora 12 `--label` (min 52), acciones al hover. Agrupado por día (cabecera 12/650 muted `20px 4px 10px`; lista radio 14). Barra de selección con chips. Filas que se van con `.erp-out`.
- **Tarjeta de credencial** `.cred` (`auto-fill minmax(320px,1fr)` gap 16; radio 16; padding 22; hover sube 2px): cabecera con icono 36 radio 10, título 15/600, categoría 9.5/700 upper; campo `.field` `#f7f8fa` radio 11 `11px 14px` etiqueta 9.5 upper + valor mono 13px, contraseña enmascarada `••••••••••••` con ojo y copiar (icono → check 1.1s). Filtros `.vchip` píldora 13/600 (activo `#18181b`) con contador.
- **Fila de papelera** `.pp-r`: `gap 14; padding 15px 20px`; icono 34 radio 10; título 14/600; sub 12.5 «Borrado por X · fecha»; tipo píldora 11/700 `--accent-soft`; «Restaurar» botón borde radio 9 `7px 13px` que se invierte al hover; purgar solo icono (hover `#fdecec/#e5484d`).
- **Resultados de búsqueda (página)**: caja `#fff` radio 14 `14px 18px` input 16px; grupos con h2 12/600 upper; cada resultado tarjeta con borde radio 12 `13px 15px`, icono 32 radio 9, hover `#dcdcde` + `--soft`.
- **Perfil (perfil.php)**: estética iOS (fondo `#f5f5f7`, máx 600, avatar 112 con punto 22, nombre 30/600 `#1d1d1f`, botones píldora `10px 20px` 15/500: `.pri #1d1d1f`, `.sec #e8e8ed`, `.blue #0071e3`; grupos blancos radio 16 con filas 56px y divisor inset; toggle iOS 51×31 verde `#34c759`; inputs radio 13 foco `#0071e3`). **Isla de estilo propio**: no generalizar; reutilizar tokens del ERP al portar.
- **Login**: ya portado en `features/auth` (layout partido 1.05fr/1fr, panel de marca con degradado radial `#26262e→#16161a→#0e0e12`, inputs radio 13 con icono, botón 13 radio).
- **Portal del cliente (`copia-erp/index.php`)**: tema propio (fondo `#f5f5f7`, radio 20/14/10, sombra `0 1px 2px rgba(16,19,24,.04), 0 10px 26px -18px rgba(16,19,24,.12)`, easing `cubic-bezier(.16,1,.3,1)`, acento negro que en oscuro pasa a AZUL `#3b82f6`, `localStorage['portalTheme']`), raíl 62px + sidebar 236px, entrada `blurUp .6s` escalonada, gráficas SVG a mano (área+línea con brillo, sparklines 46px, donut r54 trazo 17, barras CSS 150px), mapa jsvectormap, tooltips que siguen al cursor, modo edición en vivo con borde naranja animado. **Se especifica aparte** (spec del portal); aquí solo se anotan sus tokens.

---

## 3. Patrones de maquetación por tipo de página

Todas viven dentro del armazón: raíl 66px + sidebar 240px (oculto en páginas «solo»: Reuniones, Calendario, Chat, Asistente, Soporte, Actas) + barra superior 56px + `.erp-wrap` (`36px 52px 80px`, entrada `fadeUp .34s`). Ancho de contenido: libre (`max-width:none`) salvo excepciones listadas.

### 3.1 Página de listado (tareas, clientes, CRM, soporte, avisos, papelera)
```
[migas opcionales .tk-crumb]
[h1 26px/600 + .lead 15px muted]            [acciones: .btn primario «+ Nuevo» + .btn.ghost.sm]
[barra de filtros: .seg con contadores | buscador (icono izq., radio 10-11) | .mini-sel/.cs-trig | chips .chip.pick | botón Filtros con contador]
[chips de filtros activos (#eef2fb/#33507f)]
[contenedor: .card con <table> | tarjeta radio 14-16 con div-grid | grupos plegables]
  cabecera de columnas 10.5-11.5px upper ls .5 muted, bg #fbfbfc/#fcfcfd
  filas 12-17px de padding vertical, hover #fafbfc, fila entera clicable, acciones al hover
  fila «+ Añadir…» al pie (.inline-add / .ck-add)
[«Cargar más» o nada; no hay paginación numérica]
[barra de lote flotante abajo-centro cuando hay selección]
```
- Vacío: `erp-empty` dentro del contenedor. Cargando: texto o placeholder (el front usa `animate-pulse` 150px; aceptable).
- Móvil ≤640: cabecera de columnas oculta, fila en 2 líneas, acciones visibles o en menú.

### 3.2 Página de detalle
Tres variantes reales:
1. **Detalle con panel de actividad fijo** (tarea): columna izquierda (migas, título editable 27px, rejilla de propiedades 2×N con etiqueta 120px, descripción con editor, checklist, adjuntos) con `padding-right:580px`; panel derecho **fijo** `top:56px; right:0; bottom:0; width:540px; bg #f6f7f8 (oscuro --soft); border-left; padding 22px 28px` con actividad + compositor abajo. Sidebar colapsado (`side-collapse`). <940px el panel pasa debajo; <640px pestañas «Detalles / Actividad» sticky + swipe (|dx| ≥70 y >1.5·|dy|).
2. **Hub de dos columnas de tarjetas** (ficha de cliente): cabecera (avatar cuadrado 54 radio 16 + h1 25/650 + chips + botones `.cf-btn` a la derecha) → accesos rápidos (6 col) → stats (4 col, cifra 27/700) → `grid 1fr 1fr gap 18` (1 col ≤1024) de tarjetas `.cf-card` (radio 16, `26px 28px`) que igualan altura (última `flex:1 0 auto`) → zona de peligro.
3. **Detalle con barra lateral de metadatos** (ticket): `grid 1fr 300px gap 20`; tarjeta principal radio 16 `28px 30px`; lateral con cajas radio 14 `21px 22px` y h4 11px upper; selects que guardan al cambiar. Móvil: lateral primero.
- Ficha como **modal grande** (contacto CRM, 1020px) con navegación ←/→ entre registros.

### 3.3 Página de ajustes / formulario
- Menú de ajustes = el **sidebar global** con zonas (`aj_menu()`: Organización / Clientes / Portal de clientes / Sistema), no una nav propia dentro de la página.
- Contenido `max-width:1180` (ajustes) / 900 (edición de cliente) / 980 (miembro): cabecera `.aj-h` (título + descripción ≤75ch + buscador de ajustes 230px) → paneles `.set-panel` (uno visible, entra `fadeUp .2s`) → tarjetas `.set-card` con `.set-grid` 12 col (`row-gap 20`), separadores `.set-zone`.
- Alternativa por secciones con `.seg` arriba (edición de cliente) mostrando una tarjeta a la vez.
- Guardado: fetch + toast «Guardado» + marca «Sin guardar» + `beforeunload`; o barra sticky inferior con blur.

### 3.4 Dashboard
- Saludo + KPI píldora → fila de accesos (`auto-fit minmax(220px,1fr)` gap 18) → 3 tarjetas (`repeat(3,1fr)` gap 18 → 1 col ≤1100) → tarjeta ancha con lista a 2 columnas (`gap 0 140px`).
- Dashboards analíticos: barra de filtros de fecha (radio 14) → KPIs (`repeat(4,1fr)` gap 14-20 → 2 → 1) → rejilla de gráficas `repeat(auto-fit,minmax(260px,1fr))` gap 16 con `.wide` → paneles `1.7fr 1fr` (finanzas) o `1.5fr 1fr` (`.grid2`).

### 3.5 Kanban (embudo CRM)
Métricas (5 col) → tablero horizontal con scroll (`gap 14`, columnas 264px `--soft` radio 14, alto máx. `100vh-250px` con scroll interno por columna) → tarjetas blancas radio 11. Móvil: columnas 82vw con scroll-snap.

### 3.6 Aplicaciones a pantalla completa (chat, calendario)
`.erp-wrap` sin padding y `height:calc(100vh - 56px)`; layouts propios (chat 290px + panel; calendario con cabecera de vistas + rejilla). Sin sidebar.

### 3.7 Navegación por carpetas (facturas)
Hubs grandes (2 col) → carpetas (auto-fill 210px) → tabla; migas dentro del h1.

---

## 4. Comportamientos transversales (JS de erp_nav.php y patrones repetidos)

| # | Comportamiento | Detalle exacto | Front hoy |
|---|---|---|---|
| 4.1 | **Tema claro/oscuro** | `data-theme="dark"` en `<html>`; `localStorage['erpTheme']`; script en `<head>` antes de pintar (sin parpadeo). `erpToggleTheme()`: guarda centro del botón en `--tx/--ty` y usa `document.startViewTransition` con `theme-reveal .42s` (círculo que crece); sin animación si `prefers-reduced-motion`. | `useTema` con clase `.dark` y `croilab:tema`; **sin script anti-parpadeo** en `index.html` ni View Transition. |
| 4.2 | **Toast** | `toast(msg,'err'|'plain')` 2.2s; abajo-derecha; ver §2.A. | `ToastProvider` (posición/color/duración distintos). |
| 4.3 | **Deshacer** | Borrado → va a papelera (`pap_borrar`) → `toastUndo(msg,tid)` 7s → `POST papelera.php action=restore&json=1&tid` → callback o recarga. También tras redirección vía sesión. | `aviso(msg,{accion})` 7s; falta endpoint de restaurar genérico. |
| 4.4 | **Confirmación/prompt/alerta** | `erpConfirm/erpPrompt/erpAlert` con promesas; `erpAsk` (enlaces), `erpSubmitAsk` (forms), `erpPost`. Teclado Enter/Esc, foco y retorno de foco. | **Usa `window.confirm`** en TareasPage → sustituir. |
| 4.5 | **Posicionamiento de popovers** | Siempre `position:fixed` con `getBoundingClientRect()`: abajo `+4/+6px`; si no cabe (umbral 292px select / 330 calendario / 220 menciones) se voltea arriba; `left` acotado a `[6-8, innerWidth - ancho - 8]`. Menú contextual en el cursor acotado a `innerWidth-210` / `innerHeight-200`. Select personalizado se **recoloca en scroll** (rAF) y se cierra solo si el ancla sale de pantalla. Cierre: clic fuera (ignorando nodos desconectados por repintado), Esc, o clic en enlace. | `Menu.tsx`: `absolute`, sin volteo ni acotado, se recorta dentro de contenedores con overflow. |
| 4.6 | **Apertura con intención** | Desplegables del raíl: 380ms la primera vez, instantáneo si ya hay uno abierto, 200ms de gracia al salir. Tarjeta de perfil: 450ms de hover, 180ms de gracia. | No existe. |
| 4.7 | **Arrastrar y soltar** | HTML5 nativo, delegado en `document`. Tipos: `.folder-drag` (carpetas de cliente), `.list-drag` (listas), `.row-drag` (cualquier fila; contenedor con `data-reorder`, `data-reorder-cli`, `data-reorder-url`; horizontal si el padre es `.tl-tabs`/`.row-drag-x`). Solo entre hermanos. Elemento arrastrado `opacity .35`; marca de inserción con `inset box-shadow 3px #3b82f6`; decide antes/después por la mitad del rectángulo. Al soltar mueve el nodo y hace `POST order[]=…` silencioso (sin toast). Si dentro hay otro `[draggable]`, manda el interior. Asa `row_grip` visible al hover. Menú lateral: bloques independientes, orden por usuario (`nav_reorder`). Kanban: columna destino con contorno discontinuo. Subidas: overlay de ventana con contador de dragenter. | No existe. |
| 4.8 | **Sondeo de avisos** | `GET notifications.php?poll=1` cada **5s** (primera a 1.2s) y al volver a la pestaña (`visibilitychange`); primer sondeo solo fija la base; si `latest.id` > visto → popup + sonido + latido del badge (`9+` si >9). | No existe. |
| 4.9 | **Sondeo de chat y presencia** | `GET chat.php?ping=1&act=0|1&after=N` cada **5s** (primera a 1.5s); «activo» = pestaña visible y actividad (mouse/teclado/scroll/touch/focus) <60s; online si `last_seen ≤65s`, reposo si inactivo >300s; último visto en `localStorage['chatSeen_<id>']`; no avisa de la sala abierta; notificación del navegador (permiso pedido en el primer clic/tecla) con `tag:'chat-<room>'`. Dentro de chat.php el sondeo es cada **2.5s** y el «escribiendo» se envía como máximo cada 2.5s (TTL 6s). Comentarios de tarea: sondeo 5s. | No existe. |
| 4.10 | **Buscador global** | Ctrl/⌘+K y «/»; ver §2.A. | Solo enfoca el input. |
| 4.11 | **Atajos de teclado** | Globales: Ctrl/⌘+K, «/», Ctrl/⌘+. (emojis), Esc (cierra cualquier modal/popover/raíl). CRM: `/` busca, `n` nuevo, Esc deselecciona, ←/→ en ficha. Calendario: `n m s d a t ←/→`, Ctrl+Enter guarda. Compositores: Enter envía, Shift+Enter salto. Tablas del editor: Tab/Shift+Tab. Todos se ignoran si el foco está en input/textarea/select/contenteditable. | Solo Ctrl+K y Esc del cajón. |
| 4.12 | **Autoguardado** | Patrones: (a) **campo a campo en `change`** con «Guardado ✓» 1.3s o toast «Guardado» (detalle de tarea, celdas CRM); (b) **debounce 700ms + guardar en blur** (descripción); (c) **optimista con reversión** (selects de fase, celdas: guardan `_prev` y restauran + toast error); (d) formularios de ajustes por fetch + marca «Sin guardar» + `beforeunload`; (e) interruptores con `.sw-guardando` (opacidad .5) mientras guardan. Errores: «No se ha podido guardar. Recarga la página.» | Mutaciones optimistas de TanStack Query en filas de tareas (patrón c) — falta indicador «Guardado». |
| 4.13 | **Menciones** | §2.B. Al guardar, el servidor notifica a los mencionados (`notif_comment_scan`). | No existe. |
| 4.14 | **Emojis** | §2.B. Render Apple global por sprite + selector con Ctrl+. | No existe (el front muestra emojis nativos). |
| 4.15 | **Fechas** | Formato visible `dd/mm/aa`; escritura libre `d/m/aa(aa)` con `/ - .`; semana empieza en lunes; «Hoy»/«Borrar». | `<input type="date">` nativo. |
| 4.16 | **Select personalizado** | Todos los `<select>` se mejoran automáticamente (§2.A). | `<select>` nativo + chevron. |
| 4.17 | **Fotos en avatares y tarjeta de perfil** | `data-uid` → foto si existe + tarjeta hover. | `Avatar` admite `foto`; falta la tarjeta. |
| 4.18 | **Seguridad en JS** | `escHtml`, `escJs`, `safeUrl` (bloquea `javascript:/data:/vbscript:`), `safeTel`; CSRF: cabecera `X-CSRF-Token` en todo `fetch` no-GET del mismo origen + campo `_csrf` en todos los `<form method=post>` (MutationObserver). | React escapa por defecto; CSRF en `shared/api/client.ts` (verificar). |
| 4.19 | **Animaciones de entrada/salida** | `.erp-in` al insertar filas/tarjetas, `.erp-out` al quitar (esperar 180-200ms antes de borrar del DOM), `.erp-in-stg` para grupos. | No existe. |
| 4.20 | **Acordeones** | WAAPI sobre `max-height` + opacidad, 300ms `cubic-bezier(.33,1,.68,1)`; al terminar de abrir se quita el recorte (`.done`) para permitir arrastrar. Estado de grupos de tareas en localStorage. | Sidebar sin animar; grupos con `croilab:ws_collapsed`. |
| 4.21 | **Memoria de correos** | `erpEmailMem` en `localStorage['erpEmailMemory']` (máx. 300) para autocompletar invitados. | No existe. |
| 4.22 | **Mensajes tras redirección** | `?msg=` (clave → texto/tipo) leído una vez en el pie. | Usar estado de navegación o toast tras mutación. |
| 4.23 | **Cumpleaños** | Popup con confeti una vez al día (`?bday=test` para probar). | No existe (baja prioridad). |
| 4.24 | **Sonido** | «blip» WebAudio en avisos y chat (§2.A). | No existe. |
| 4.25 | **Formato de dinero/fechas** | `eur/eur0/eurk`, `mes_label`, hora relativa («justo ahora», «hace N minutos», «ayer a las…», «27 de jul. a las…»), «hace N min/h/d» para presencia. | Crear `shared/lib/formato.ts`. |

---

## 5. Mapeo a `front/src` y componentes compartidos propuestos

### 5.1 Lo que ya existe y su correspondencia

| Antiguo | Front actual | Estado |
|---|---|---|
| `.rail`, `.rail a`, `.rlogo` | `app/layout/IconRail.tsx` | Portado (falta engranaje, campana con badge, avatar abajo y desplegables `.rail-fly`). |
| `.side`, `.nav a`, acordeón `.cli-*`, `.foot` | `app/layout/Sidebar.tsx` | Portado (sin animación de acordeón, sin arrastrar, sin menús contextuales). |
| `.erp-top`, `.tbtn`, `.tsearch`, tema | `app/layout/Topbar.tsx` | Portado (alto 53 vs 56; buscador es input, no paleta). |
| `.erp-wrap` | `AppLayout` `<main>` | Portado (falta `pb-20` y `fadeUp`). |
| Tokens `:root`/dark | `src/index.css` | Parcial (§1.2). |
| `avatar_color`, iniciales | `shared/lib/avatar.ts`, `shared/ui/Avatar.tsx` | Completo (persona/cliente). |
| `estado_circle` | `features/tareas/components/EstadoCirculo.tsx` | Completo (moverlo a `shared/ui`: lo usan avisos, perfil, calendario). |
| `.ctxmenu` / `#wsPop` / `.wsp-item` | `shared/ui/Menu.tsx` + `MenuItem` | Parcial (sin posicionamiento fixed/volteo, sin separador, sin cabecera, sin variante peligro, sin apertura por clic derecho). |
| `toast`, `toastUndo` | `shared/ui/Toast.tsx`, `useToast` | Parcial (estilo/posición/duración distintos, sin icono). |
| Estado vacío | inline en `TareasPage` | Falta componente. |
| `.ws-tabs`/`.seg` | clases `TAB/TAB_ON` inline en `TareasPage` | Falta componente. |
| `.ck-row`, `.grp` | `TareaFila`, `Grupo` en `TareasPage` | Portado para tareas (extraer `GrupoPlegable` y `FilaTabla`). |
| Detalle de tarea | `TareaDetalle.tsx` (cajón 520px, solo lectura, texto plano) | Diferente del original (página con panel de actividad fijo). |
| `Cargando…` | `shared/ui/Cargando.tsx` | OK. |
| Login | `features/auth/ui.tsx`, `AuthLayout.tsx` | Portado (usa grises de Tailwind, no tokens; aceptable porque es página aislada). |
| `useDebounced` | `shared/lib/useDebounced.ts` | OK (usar 180ms paleta, 250ms búsquedas, 700ms descripciones). |
| Todo lo demás (`.btn`, inputs, `.cs-*`, `.sw`, `.chip`, `.tag`, `.kpi`, `.panel`, `.card`, `#dpCal`, `#erpDlgOv`, `.erpag`, `#gsOv`, `#notifPops`, `#erpProfCard`, editor rt, emojis, menciones, drag&drop, upload, lightbox, gráficas, tablas sticky, barra de lote, kanban) | — | **Missing** |

### 5.2 Componentes compartidos a construir (`front/src/shared/ui/…`)

Convención: clases Tailwind sobre los tokens de §5.3 (`bg-page`, `bg-card`, `bg-pop`, `bg-field`, `bg-soft`, `bg-head`, `text-ink`, `text-ink-strong`, `text-muted`, `text-label`, `border-line`, `border-line2`, `border-line-strong`, `bg-accent`, `text-accent-fg`, `bg-accent-soft`, `bg-rev text-rev-fg`, `text-ok bg-ok-bg border-ok-line`, `text-danger bg-danger-bg border-danger-line`), sombras `shadow-pop`, `shadow-dialog`, etc. y radios `rounded-[10px]` etc. Todos aceptan `className`.

| # | Componente | Props | Estilo clave (Tailwind) | Sustituye |
|---|---|---|---|---|
| 1 | `Button` | `variant: 'primary'|'ghost'|'danger'|'subtle'|'link'`, `size: 'md'|'sm'`, `icon?`, `loading?`, `loadingText?`, `as?: 'button'|Link`, `...button` | md `h-auto px-4 py-2.5 text-[13.5px] font-semibold rounded-[10px] gap-[7px]`; sm `px-3 py-[7px] text-[12.5px] rounded-[9px]`; primary `bg-accent text-white dark:text-accent-fg hover:-translate-y-px hover:shadow-btn active:scale-[.98]`; ghost `bg-card border border-line text-ink hover:bg-soft hover:border-[#dcdcde] dark:hover:border-line-strong`; danger `bg-card border border-[#ecd4d1] text-[#b23b30] hover:bg-[#fbf3f2] dark:text-danger dark:border-danger-line`; subtle (= `.erpag-btn.g`/diálogo `.no`) `bg-soft text-[#5a5f68] hover:bg-[#eeeef0]`; `max-sm:min-h-[42px]` | `.btn*`, `.tbtn`, `.qbtn`, `.erpag-btn`, `.cm-new`, `.reu-new`… |
| 2 | `IconButton` | `label` (aria), `icon`, `tone?: 'default'|'danger'`, `size?: 26|30|34` | `p-[5px] rounded-md text-label hover:bg-[#eef0f3] hover:text-[#6b7280] dark:hover:bg-soft`; danger hover `bg-[#fde8e8] text-[#c0392b]` | `.icon-btn`, `.ck-x`, `.cdel`, `.att-x` |
| 3 | `TextInput`, `TextArea` | `invalid?`, `leftIcon?`, `unit?` (`%`,`€`), `size?: 'md'|'sm'`, `variant?: 'box'|'inline'` | box `w-full rounded-[10px] border border-line bg-field px-[13px] py-2.5 text-[14px] text-ink focus:border-ring focus:outline-none max-sm:text-[16px]`; inline (celda) `border-transparent bg-transparent rounded-[7px] px-2 py-1.5 hover:bg-soft focus:bg-card focus:shadow-[0_0_0_2px_rgba(17,19,24,.08)]`; invalid `border-[#ef4444]` | inputs globales, `.cell/.ed`, `.ws-date-txt` |
| 4 | `Field` | `label`, `icon?`, `hint?`, `required?`, `span?: 2|3|4|5|6|8|12`, `children` | label `text-[12px] font-semibold text-muted flex gap-1.5 mb-[7px]`; hint `text-[11.5px] text-label mt-[7px]`; `*` `text-[#ef4444]` | `.set-f`, `label` |
| 5 | `FormGrid` + `FormZone` | `cols?: 12`, `one?`; `FormZone title` | `grid grid-cols-12 gap-x-[22px] gap-y-[18px]`, hijos `col-span-6` por defecto, `max-md:grid-cols-1`; zona `text-[12px] uppercase tracking-[.6px] font-[650] text-muted mt-[30px] mb-[14px] flex items-center gap-2.5 after:flex-1 after:h-px after:bg-line2` | `.set-grid`, `.set-zone`, `.sec-t`, `.row`, `.row3` |
| 6 | `Select` (personalizado) | `value`, `onChange`, `options: {value,label,color?,icon?}[]`, `placeholder?`, `size?`, `variant?: 'box'|'mini'|'inline'`, `disabled?`, `searchable?` | disparador = `TextInput` + chevron 15px que rota; abierto `border-label shadow-[0_0_0_3px_rgba(17,19,24,.06)]`; panel en `Popover` (`max-h-[288px]`), opción `px-[11px] py-2 rounded-lg text-[13px] text-[#4c515b] hover:bg-soft`, activa `bg-accent-soft text-accent font-semibold` | `<select>` + `.cs-*`, `.mini-sel`, `Desplegable` de TareasPage |
| 7 | `Popover` (primitiva) | `anchor` (ref o `{x,y}`), `open`, `onClose`, `placement?: 'bottom-start'|'bottom-end'`, `offset=4`, `width?`, `flip=true`, `closeOnScroll?: 'reposition'|'close'` | portal a `body`, `position:fixed`, cálculo de §4.5, `z-[700]`, `rounded-xl border border-line bg-pop p-[5px] shadow-pop animate-[pop_.15s_ease]`; cierre con clic fuera/Esc | base de Menu, Select, DatePicker, pickers, `.cm-pop` |
| 8 | `Menu` (rehacer) + `MenuItem`, `MenuSeparator`, `MenuLabel`, `useContextMenu()` | item: `onSelect`, `icon?`, `danger?`, `selected?`, `shortcut?`, `color?` (punto) | sobre `Popover`; item `px-3 py-2 rounded-lg text-[13px] font-medium text-[#4c515b] dark:text-nav-ink hover:bg-soft hover:text-ink`; danger `text-[#c0392b] hover:bg-[#fde8e8] dark:text-danger dark:hover:bg-danger-bg`; separador `h-px bg-line mx-1.5 my-1`; cabecera `text-[10.5px] uppercase tracking-[.5px] font-bold text-muted px-2.5 pt-[7px] pb-[5px]`; contextual: acotado a `innerWidth-210/innerHeight-200` | `.ctxmenu`, `.addlm`, `.cm-ctx`, `.ng-ctx`, `.cal-ctx`, `Menu.tsx` |
| 9 | `PersonPicker` | `people`, `value: number[]|number|null`, `multiple?`, `onChange`, `allowNone?` | Menu con `Avatar 22`, ✓ en `--accent`, «Sin asignar»/«Quitar» | `#wsPop`, `.erpta-resp*`, `.reu-pop` |
| 10 | `DatePicker` / `DateInput` | `value: string|null` (ISO), `onChange(iso|null)`, `placeholder='dd/mm/aa'`, `variant?: 'box'|'inline'`, `tone?: (iso)=>'late'|'soon'|null` | input que acepta escritura libre (parse de §2.A) + `Popover` 252px (`rounded-[14px] shadow-cal p-3`), cabecera 13px/700, días `h-[30px] rounded-lg text-[12.5px]`, hoy `text-accent font-bold`, seleccionado `bg-accent text-white dark:text-accent-fg`, pie Borrar/Hoy; lunes primero | `.dpick`, `#dpCal`, `#wsCal`, `<input type=date>` |
| 11 | `Switch` | `checked`, `onChange`, `tone?: 'default'|'ok'`, `saving?`, `label?`, `description?`, `logo?` (variante fila) | pista `w-10 h-[23px] rounded-full bg-[#d9dbe0] dark:bg-line-strong`, bolita 17px `translate-x-[17px]`, ON `bg-accent` (ok `bg-[#12a150]`), `active:` bolita 20px, saving `opacity-50 pointer-events-none`; fila `flex gap-[13px] px-2.5 py-[11px] rounded-xl hover:bg-soft` | `.sw`, `.erpag-sw`, `.ed-sw`, `.set-rule` |
| 12 | `Checkbox` | `checked`, `indeterminate?`, `onChange`, `size?: 15|17` | nativo con `accent-color: var(--accent)`; móvil 20px | checkboxes |
| 13 | `Segmented` | `items: {value,label,icon?,count?,href?}[]`, `value`, `onChange`, `variant: 'dark'|'pill'|'underline'` | dark `inline-flex gap-1 rounded-[11px] border border-line bg-card p-1`, item `px-3.5 py-[7px] rounded-lg text-[13.5px] font-semibold text-[#6b7280] hover:bg-soft`, activo `bg-tab-on text-white dark:bg-rev dark:text-rev-fg`, contador `text-[11px] font-bold bg-soft rounded-full px-[7px]`; pill `bg-soft rounded-[11px] p-[3px]`, activo `bg-card text-ink-strong shadow-[0_1px_3px_rgba(0,0,0,.08)]`; underline `border-b border-line`, activo `border-b-2 border-accent` | `.seg`, `.ws-tabs`, `.erpag-seg`, `.dsh-tabs`, `.reu-tabs`, `.tl-tabs`, `.nt-tabs`, `.cl-views` |
| 14 | `Chip` | `variant: 'tag'|'data'|'pick'|'act'`, `on?`, `onClick?`, `onRemove?`, `color?` | tag `text-[11px] font-semibold bg-[#eef0f3] text-[#5c616b] rounded-md px-2 py-[3px] dark:bg-soft dark:text-ink`; data `rounded-full border border-line bg-card px-[13px] py-[5px] text-[12px] font-semibold`; pick `rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold text-muted`, on `bg-tab-on text-white border-tab-on dark:bg-rev dark:text-rev-fg`; act `rounded-[9px] px-3 py-[7px]` ; removible (filtro) `bg-[#eef2fb] text-[#33507f] rounded-lg` | `.chip*`, `.tag`, `.cm-fchip`, `.vchip`, `.cm-quicks` |
| 15 | `StatusPill` | `color`, `label`, `variant: 'solid'|'tint'|'neutral'`, `dot?`, `chevron?`, `size?` | solid `rounded-full px-2.5 py-1 text-[11.5px] font-bold text-white` + `style={{background:color}}`; tint `style={{background: color+'18', color}}`; neutral `.gpill` | `.cm-fase`, `.fc-badge`, `.sp-badge`, `.gpill`, `.est-badge`, `.sbadge` |
| 16 | `PriorityFlag` | `value`, `palette?: 'strong'|'vivid'`, `showLabel?` | cuadradito 9px radio 2 + 12.5/600 | `.flagp`, `.prio-pick` |
| 17 | `CountBadge` | `n`, `tone: 'red'|'neutral'|'green'|'dark'|'amber'`, `max=99` | `ml-auto text-[11px] font-bold rounded-full px-2`, red `bg-[#feecec] text-[#e5484d] dark:bg-badge-bg dark:text-badge-fg` | `.cnt`, `.rbadge`, `.ub`, `.pill` |
| 18 | `AvatarStack` | `people`, `max=3`, `size=27`, `overlap?`, `emptyLabel?` | `flex` con `-ml-[11px]` (27), `-ml-[9px]` (24), `-ml-[7px]` (22); `ring-2 ring-card`; «+N» `bg-[#c8ccd2] text-[#3c4149] text-[9.5px]`; vacío círculo `border-[1.5px] border-dashed border-[#c4c8ce]` + «＋» | `.asg-stack`, `.mini-av`, `.pile`, `.dsh-av2`, `.cav-cal` |
| 19 | `PresenceDot` + `ProfileHoverCard` | `state: 'online'|'idle'|'offline'`; card: `userId`, `children` (ancla) | dot `#12a150/#f0872a/#c0c4cb` con `ring-[2.5px] ring-card`; card §2.A (264px, radio 18, 450ms/180ms) | `#erpProfCard`, `.pdot` |
| 20 | `Card`, `Panel`, `CardHeader` | `padding?: 'md'|'lg'`, `title?`, `icon?`, `action?`, `eyebrow?` | `rounded-2xl border border-line bg-card p-[24px_26px] max-sm:p-[18px_16px]`; header h3 16/600 o «ceja» 12/650 upper | `.card`, `.panel`, `.dsh-card`, `.cf-card`, `.set-card` |
| 21 | `KpiTile` | `label`, `value`, `sub?`, `icon?`, `href?`, `delta?: {value, dir}`, `tint?` | `rounded-2xl border border-line bg-card px-6 py-[22px] hover:-translate-y-0.5 hover:border-[#e2e2e4] hover:shadow-card-hover`; label 11.5 upper `tracking-[.4px]`; valor `text-[31px] font-[640] tracking-[-.7px] leading-none text-ink-strong mt-3` | `.kpi`, `.db-kpi`, `.rs-c`, `.sp-kpi`, `.cf-stat`, `.ng-met`, `.dsh-kpi` |
| 22 | `KpiGrid` | `cols=4` | `grid grid-cols-4 gap-5 mb-6 max-[900px]:grid-cols-2 max-sm:gap-3 max-[420px]:grid-cols-1` | `.kpis` |
| 23 | `EmptyState` | `icon`, `title`, `text?`, `actions?`, `variant: 'card'|'inline'|'dashed'|'compact'` | card `text-center rounded-2xl border border-line bg-card px-6 py-10 shadow-empty`; icono `size-[60px] rounded-[18px] bg-soft text-label` svg 28; título 18/600 `tracking-[-.3px]`; texto 13.5 `max-w-[400px] leading-[1.6]` | `erp_empty`, `.dsh-empty`, `.ac-empty`, `.cred-empty`, vacío de TareasPage |
| 24 | `Notice` | `tone: 'ok'|'error'|'warn'|'info'` | `rounded-[10px] border px-3.5 py-2.5 text-[13px] mb-4`; ok `bg-[#eafaf0] border-[#cfe9d6] text-[#12854a] dark:bg-ok-bg dark:border-ok-line dark:text-ok` | `.ok-note`, `.err-note`, `.cf-pass`, `Aviso` de auth |
| 25 | `Breadcrumbs` | `items: {label, to?, icon?}[]` | `flex gap-2 text-[12.5px] text-muted mb-4`, sep `text-[#d4d7dd]`, último `text-ink font-semibold` | `.tk-crumb` |
| 26 | `PageHeader` | `title`, `lead?`, `avatar?`, `actions?`, `crumbs?` | h1 `text-[26px] font-semibold tracking-[-.5px] text-ink-strong mb-2 max-sm:text-[23px]`; lead 15px muted `mb-[26px]` | `h1 + .lead`, `.ws-head`, `.cf-head` |
| 27 | `DataTable` | `columns: {key, header, width?, align?, sortable?, sticky?, render}[]`, `rows`, `getRowId`, `onRowClick?`, `selectable?`, `selected`, `onSelect`, `sort`, `onSort`, `stickyHeader?`, `maxHeight?`, `rowActions?`, `mobileRow?` (render 2 líneas), `empty?`, `loading?`, `footer?` (fila añadir) | contenedor `rounded-2xl border border-line bg-card overflow-auto overscroll-contain`; th `text-[11px] uppercase tracking-[.5px] font-[650] text-muted px-3 py-3.5 bg-head sticky top-0`; td `px-3 py-2.5 border-b border-line2 text-[13px]`; hover `bg-[#fcfcfd] dark:bg-soft`; acciones `opacity-0 group-hover:opacity-100`; flecha de orden « ↑/↓» | `table`, `table.cm`, `.fc-tbl`, `.sp-tbl`, `.ss`, `table.ls`, `#cliTable` |
| 28 | `GridRows` (div-grid) + `GroupCard` | `template` (p.ej. `'1fr 168px 132px 118px 84px'`), `header`, `rows`; `GroupCard`: `title`, `count`, `collapsedKey`, `defaultCollapsed?` | `GroupCard` = el `Grupo` actual de TareasPage generalizado (radio 14, cabecera `bg-head px-[18px] py-[15px]`, contador píldora, persistencia en localStorage, animación de §4.20) | `.ck-*`, `.grp`, `.nt-list`, `.ck-grp` |
| 29 | `InlineAddRow` | `placeholder`, `onAdd(text)`, `buttonLabel='Añadir'`, `onEmptySubmit?` | `flex items-center gap-2.5 px-[18px] py-[11px] text-label hover:bg-[#fafbfc]`, «+» que rota 90°; botón `bg-accent rounded-lg px-[13px] py-1.5 text-[12.5px]` | `.inline-add`, `.ck-add`, `.chk-add`, `.addbtn` |
| 30 | `BulkBar` | `count`, `actions: {label, icon, onClick, danger?, menu?}[]`, `onClear` | `fixed bottom-[26px] left-1/2 -translate-x-1/2` con transición de §2.B, `rounded-[14px] shadow-[0_18px_50px_rgba(16,19,24,.18)] z-[520]` | `.cm-bulk`, `.nt-bulkbtns` |
| 31 | `FilterBar` (+ `SearchBox`, `FilterPanel`, `ActiveFilterChips`, `SavedViewsMenu`) | `search`, `onSearch`, `filters`, `quick?`, `views?` | buscador `rounded-[11px] border border-line bg-card px-[13px] py-[9px]` icono 16; botón filtros con contador oscuro; panel `grid grid-cols-4 gap-x-[18px] gap-y-4 rounded-[14px] px-[22px] py-5` | `.cm-bar`, `.cli-bar`, `.cm-panel`, `.srch` |
| 32 | `Modal` (+ `ModalHeader`, `ModalBody`, `ModalFooter`) | `open`, `onClose`, `size: 'sm'|'md'|'lg'|'xl'|'full'`, `title`, `subtitle?`, `logo?`, `align?: 'center'|'top'`, `initialFocus?` | máscara `fixed inset-0 bg-[rgba(16,19,24,.36)] backdrop-blur-[4px] z-[1200]`; caja `rounded-[18px] bg-card shadow-modal` + entrada; header `px-[22px] py-[17px] border-b border-line text-[16px] font-bold`; body `px-[22px] py-[18px] flex flex-col gap-[15px]`; footer `flex justify-end gap-2.5 px-[22px] py-[15px] border-t border-line`; Esc, clic fuera, trampa de foco, retorno de foco, `max-sm:` a pantalla completa | `.erpag`, `.tm`, `.reu-modal`, `.gm`, `.ch-modal`, `.cm-modal`, `.ng-modal`, `.cv` |
| 33 | `ConfirmDialog` + `useConfirm()` | `confirm({title='¿Seguro?', message, okLabel, cancelLabel, danger}) → Promise<boolean>`; `prompt({title, value, placeholder, okLabel}) → Promise<string|null>`; `alert(...)` | §2.A (380px, radio 16, botones 13px/600, `.yes.danger #c0343a`) | `erpConfirm/erpPrompt/erpAlert`, `window.confirm` |
| 34 | `Drawer` / `SidePanel` | `open`, `onClose`, `width=520`, `side='right'` | para paneles laterales (detalle rápido); fondo `bg-black/20` | `TareaDetalle` actual |
| 35 | `Toast` (ajustar) | `toast(msg, {tipo:'ok'|'error'|'plain', accion?, ms?})`; `toastUndo(msg, restore)` | `fixed right-[18px] bottom-[18px] z-[1200] flex-col items-end gap-2`; toast `bg-[#22262c] text-white text-[13px] font-semibold px-[15px] py-[11px] rounded-[11px] shadow-toast max-w-[320px]` con círculo 18px `#12a150` «✓»; error `bg-[#c0343a]`; botón deshacer `bg-white/15 hover:bg-white/30 rounded-lg px-[11px] py-[5px] text-[12.5px] font-bold`; 2.2s / 7s; móvil a lo ancho | `Toast.tsx` |
| 36 | `NotificationStack` + `useNotificationsPoll`, `useChatPoll` | — | §2.A `#notifPops`; hooks con `refetchInterval: 5000`, `refetchOnWindowFocus`, base inicial, sonido opcional | `#notifPops`, sondeos |
| 37 | `CommandPalette` (Ctrl+K, «/») | `open`, `onClose`, `initialQuery?` | §2.A (620px, top 11vh, debounce 180, mínimo 2 letras, ↑↓ Enter Esc, «Ver todos los resultados») | `#gsOv`, input del Topbar |
| 38 | `useHotkeys(map, {ignoreInputs:true})` | — | utilidad para atajos de §4.11 | listeners sueltos |
| 39 | `RichTextEditor` + `RichTextView` + `richtext.ts` (parse/serialize del formato markdown-like) | `value` (string formato ERP), `onChange`, `onBlurSave?`, `debounceMs=700`, `placeholder`, `mentions?: Persona[]`, `toolbar?: ('blocks'|'attach'|'checklist'|'emoji'|'bold'…)[]`, `onUploadFiles?` | §2.B (barra `.tool` p-1.5 rounded-[7px] text-label; menú de bloques; estilos de contenido; tabla con barra flotante; sanitizado de pegado) | `rt_editor.php`, descripción y comentarios de tareas, actas |
| 40 | `MentionPopover` (`useMentions`) | `people`, `onPick` | §2.B | `#mnPop`, `.ch-mentionpop` |
| 41 | `EmojiPicker` (+ `useEmojiShortcut` Ctrl+.) | `anchor`, `onPick`, `recentKey='erpEmojiRecientes'` | §2.B (340px, 9 categorías + recientes, buscador, rejilla 8 col). Decidir: sprite Apple (`sheet.webp` 1.8MB) o emoji nativo; **añadir modo oscuro** | `component.js`, `.emx` |
| 42 | `FileDropzone` + `useWindowFileDrop` | `onFiles`, `accept?`, `maxSizeMB=25`, `label`, `hint` | `border-2 border-dashed border-[#d4d8de] rounded-[14px] px-5 py-[26px] hover:bg-soft hover:border-accent`; overlay de ventana con caja `rounded-[20px] border-2 border-dashed border-accent` | `.upl`, `.upx-empty`, `#dropOverlay`, `#upDrop` |
| 43 | `AttachmentList` / `AttachmentChip` / `Lightbox` | `files`, `onRemove?`, `onOpen` | miniatura 92 radio 10; chip radio 10 `px-3 py-[9px] text-[12.5px]`; lightbox `bg-[rgba(10,12,16,.62)] backdrop-blur-[7px]` imagen `rounded-[10px]` | `.att-*`, `#lightbox`, `#fcLbox` |
| 44 | `CommentThread` + `CommentComposer` + `ReactionChips` | `comments`, `onSend`, `onReact`, `onReply`, `onEdit`, `onDelete` | §2.B (burbuja radio 12, compositor `.cbox`) | `.cm*`, `.cbox`, `.pf-cm` |
| 45 | `ActivityTimeline` | `items: {at, text, actor?}[]` | filas `gap-2.5 py-[7px] border-t border-line2 text-[12.5px]`, punto 6px `#c4c8ce` | `.sys`, `.pf-acthist` |
| 46 | `Checklist` | `items`, `onToggle`, `onAdd`, `onDelete`, `onAssign?`, `onReorder?` | §2.B (tachado animado, destello, reordenado a 280ms, píldora progreso) | `.chk-*`, `.cmck` |
| 47 | `SortableList` (`useSortable`) | `items`, `getId`, `onReorder(ids)`, `axis: 'y'|'x'`, `handle?` | indicador `shadow-[inset_0_3px_0_#3b82f6]` / `inset_0_-3px_0` / laterales; arrastrado `opacity-35`; `RowGrip` (6 puntos `#c8ccd3`, visible al hover) | motor de arrastre de erp_nav, `.row-grip` |
| 48 | `KanbanBoard` / `KanbanColumn` / `KanbanCard` | `columns: {id, title, color, total?, items}[]`, `onMove(itemId, toCol, index)`, `onReorderColumns?`, `renderCard`, `footer?` | §2.B (264px, `bg-soft rounded-[14px]`, destino `outline-2 outline-dashed outline-[#2f6df6] bg-[#eef4ff]`) | `negocio.php` |
| 49 | `ChartCard` + `charts.ts` (config Chart.js) | `title`, `wide?`, `height=240`, `type`, `data`, `options?` | Chart.js 4 con defaults de §2.B y paleta `PAL`; vacío «Sin datos todavía» (requiere añadir dependencia `chart.js` + `react-chartjs-2`, o SVG propio) | `.db-card`, `.rs-panel .ch-box` |
| 50 | `ProgressBar` / `PhaseBar` / `ProgressPill` | `value`, `max`, `segments?` | barra 6px `bg-[#eceef1]` relleno `bg-accent rounded-full`; segmentos gap 5; píldora «3/5» `text-[11px] font-bold bg-soft rounded-full px-[9px] py-0.5` | `.cf-pbar`, `.chk-prog`, `.pbar` |
| 51 | `DateTile` | `date`, `tone?: 'past'|'ok'` | 44px `bg-soft rounded-[10px]` día 15/700 mes 9.5/700 upper | `.dsh-date`, `.reu-day` |
| 52 | `ListRow` | `icon?|avatar?|dateTile?`, `title`, `subtitle?`, `right?`, `href?`, `actions?` | `flex gap-3.5 py-[15px] px-0.5 border-t border-line2 first:border-t-0` | `.dsh-row`, `.cf-row`, `.pp-r`, `.lrow`, `.bs-r` |
| 53 | `StickySaveBar` + `useUnsavedGuard` + `SavedIndicator` | `dirty`, `saving`, `onSave`, `onCancel`, `note?`; indicador «Guardado ✓» 1.3s / «Sin guardar» | barra `sticky bottom-0 z-20 bg-page/90 backdrop-blur-[6px] border-t border-line -mx-[52px] px-[52px] py-3.5`; «Guardado ✓» `text-[12px] font-semibold text-ok`; «Sin guardar» `text-[#b7791f]` + punto `#e8a33d` | `.ed-guardar`, `.aj-flag`, `.saved-note`, `.te-pie` |
| 54 | `DangerZone` | `title`, `text`, `action` | caja `rounded-2xl border border-[#f2dede] bg-[#fffafa] px-[22px] py-[18px]` (oscuro `danger-bg/danger-line`) | `.te-baja`, `.cf-danger` |
| 55 | `QuickActionCard` | `icon`, `color`, `title`, `description?`, `to` | §2.C | `.acc-card`, `.cf-q`, `.te-op` |
| 56 | `Accordion` / `Collapse` | `open`, `children` | animación de §4.20 (300ms `cubic-bezier(.33,1,.68,1)`) | `_slide`, `.cli-acc`, `.fe-card h3` |
| 57 | `RailFlyout` (layout) | `items`, `title` | §2.A (240px, `shadow-fly`, intención 380/200ms) | `.rail-fly` |
| 58 | `formato.ts` | `eur(n,dec=2)`, `eur0`, `eurk`, `mesNombre`, `mesLabel('2026-03')`, `fechaCorta(iso)` → `dd/mm/aa`, `horaRelativa(iso)`, `haceTiempo(s)` | — | helpers PHP |
| 59 | `paletas.ts` | `ESTADOS_TAREA` (fuerte/viva), `PRIORIDADES` (fuerte/viva), `FASES_CRM` fallback, `ESTADOS_FACTURA`, `ESTADOS_TICKET`, `ESTADOS_REUNION`, `TIPOS_COMENTARIO`, `PALETA_GRAFICAS`, `COLORES_COMPANEROS`, `PRESENCIA` | — | constantes repartidas |

### 5.3 Bloque de tokens propuesto para `front/src/index.css`

Añadir a `:root` / `.dark` (manteniendo los existentes) y exponer en `@theme inline`:

```css
:root{
  --c-card:#ffffff; --c-pop:#ffffff; --c-field:#ffffff;
  --c-line-strong:#dcdcde; --c-ring:#c4c4c7; --c-ring-soft:rgba(17,19,24,.07);
  --c-accent-soft:#f2f2f3; --c-accent-fg:#ffffff;
  --c-rev:#111318; --c-rev-fg:#ffffff; --c-nav-ink:#4c515b;
  --c-badge-bg:#feecec; --c-badge-fg:#e5484d;
  --c-ok:#0f7a3d; --c-ok-bg:#eafaf0; --c-ok-line:#cfe9d6;
  --c-danger:#c62a33; --c-danger-bg:#fbeeee; --c-danger-line:#f0caca; --c-warn:#b7791f;
}
.dark{
  --c-card:#161616; --c-pop:#1f1f1f; --c-field:#0f0f0f;
  --c-line-strong:#3a3a3a; --c-ring:#4a4a4a; --c-ring-soft:rgba(255,255,255,.08);
  --c-accent-soft:#262626; --c-accent-fg:#171717;
  --c-rev:#e5e5e5; --c-rev-fg:#171717; --c-nav-ink:#b5b5b5;
  --c-badge-bg:#3a2327; --c-badge-fg:#f0999a;
  --c-ok:#54cd8e; --c-ok-bg:#14251c; --c-ok-line:#234436;
  --c-danger:#f08d82; --c-danger-bg:#2e1d1d; --c-danger-line:#472a2a; --c-warn:#efb445;
}
@theme inline{
  --color-card:var(--c-card); --color-pop:var(--c-pop); --color-field:var(--c-field);
  --color-line-strong:var(--c-line-strong); --color-ring:var(--c-ring);
  --color-accent-soft:var(--c-accent-soft); --color-accent-fg:var(--c-accent-fg);
  --color-rev:var(--c-rev); --color-rev-fg:var(--c-rev-fg); --color-nav-ink:var(--c-nav-ink);
  --color-badge-bg:var(--c-badge-bg); --color-badge-fg:var(--c-badge-fg);
  --color-ok:var(--c-ok); --color-ok-bg:var(--c-ok-bg); --color-ok-line:var(--c-ok-line);
  --color-danger:var(--c-danger); --color-danger-bg:var(--c-danger-bg); --color-danger-line:var(--c-danger-line);
  --color-warn:var(--c-warn);
  --shadow-btn:0 7px 18px rgba(0,0,0,.16);
  --shadow-card-hover:0 8px 26px rgba(0,0,0,.05);
  --shadow-empty:0 1px 2px rgba(16,19,24,.03);
  --shadow-pop:0 18px 46px rgba(0,0,0,.17);
  --shadow-cal:0 20px 50px rgba(0,0,0,.18);
  --shadow-palette:0 24px 60px rgba(16,18,22,.22);
  --shadow-dialog:0 24px 70px -18px rgba(16,19,24,.45),0 0 0 1px rgba(16,19,24,.05);
  --shadow-modal:0 30px 80px rgba(0,0,0,.35);
  --shadow-toast:0 12px 34px rgba(0,0,0,.24);
  --shadow-notif:0 8px 22px -10px rgba(16,19,24,.30),0 2px 6px -2px rgba(16,19,24,.10);
  --ease-erp:cubic-bezier(.2,.7,.3,1);
  --animate-pop:pop .15s ease; --animate-fade-up:fadeUp .34s var(--ease-erp);
  --animate-erp-in:erpIn .22s var(--ease-erp) both; --animate-erp-out:erpOut .18s ease forwards;
}
@keyframes pop{0%{transform:scale(.97)}55%{transform:scale(1.03)}100%{transform:scale(1)}}
@keyframes fadeUp{from{opacity:0;transform:translateY(9px)}to{opacity:1;transform:none}}
@keyframes erpIn{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}
@keyframes erpOut{from{opacity:1;transform:none}to{opacity:0;transform:translateX(10px)}}
```
Notas: en oscuro `.dark` ya define `--c-bg:#0a0a0a`; `bg-page` = fondo, `bg-card` = superficie elevada (en claro ambos blancos). Añadir también la base global: `::selection`, scrollbar fina, `:focus-visible{outline:2px solid var(--c-ring);outline-offset:2px}`, `letter-spacing:-.1px`, `line-height:1.5`, `font-size:14px` en `body`, `input[type=checkbox]{accent-color:var(--c-accent)}`, y el script anti-parpadeo del tema en `index.html`. `prefers-reduced-motion` desactiva `erp-in/out` y transición de tema.

### 5.4 Prioridad sugerida de construcción
1. Tokens §5.3 + base global + script de tema.
2. `Button`, `IconButton`, `TextInput/TextArea`, `Field`, `FormGrid`, `Switch`, `Checkbox`, `Card`, `PageHeader`, `Breadcrumbs`, `Notice`, `EmptyState`, `Chip`, `StatusPill`, `CountBadge`, `AvatarStack`, `formato.ts`, `paletas.ts`.
3. `Popover` → `Menu` (rehacer) → `Select`, `PersonPicker`, `DatePicker`; `Modal`, `ConfirmDialog/useConfirm` (quitar `window.confirm`), `Toast` (ajustar), `Segmented`.
4. `DataTable`, `GridRows/GroupCard`, `InlineAddRow`, `FilterBar`, `BulkBar`, `KpiTile/KpiGrid`, `ListRow`, `DateTile`, `StickySaveBar/SavedIndicator/useUnsavedGuard`, `DangerZone`, `QuickActionCard`.
5. `CommandPalette`, `useHotkeys`, `NotificationStack` + sondeos, `ProfileHoverCard`, `RailFlyout`, `SortableList`, `Accordion`.
6. `RichTextEditor` (+ formato), `MentionPopover`, `EmojiPicker`, `FileDropzone`, `AttachmentList/Lightbox`, `CommentThread`, `Checklist`, `ActivityTimeline`, `KanbanBoard`, `ChartCard`.

