# CLAUDE.md — Polyglot AI

Guía de trabajo para este repositorio. Léela entera antes de tocar código.

---

## 1. Qué es esto

Plugin de WordPress de traducción multilingüe con paridad funcional con TranslatePress
Business (incluidos sus add-ons), cuyo motor de traducción automática es la **API de
Anthropic (Claude)** en lugar de Google Translate o DeepL.

- **Nombre provisional del plugin:** Polyglot AI
- **Prefijo de funciones/hooks/opciones/tablas:** `pgai_`
- **Prefijo de constantes:** `PGAI_`
- **Text domain:** `polyglot-ai`
- **Namespace PHP:** `PolyglotAI\` (PSR-4 sobre `src/`)
- **Namespace REST:** `pgai/v1`

**Marcas.** Ni "TranslatePress" ni "Claude" aparecen en el nombre del plugin, en el
slug, en el menú de administración ni en la interfaz de usuario. Sí se nombra a
Anthropic/Claude en código, comentarios y documentación técnica cuando se describe el
proveedor de la API que se está llamando (uso nominativo, es la realidad técnica):
p. ej. la clase `PolyglotAI\Engines\Claude\ClaudeEngine`. En la UI el motor se llama
"Motor IA (Anthropic)".

**No copiar código.** Nada de TranslatePress ni de ningún otro plugin. Se reimplementa
el comportamiento observable desde cero.

---

## 2. Requisitos de entorno

| Pieza | Versión |
|---|---|
| WordPress | **6.6+** (ver ADR-01) |
| PHP | 8.1+ (desarrollo sobre 8.4) |
| MySQL / MariaDB | 5.7+ / 10.4+ |
| Node | 20+ |

- Entorno local: `wp-env` (Docker) + WP-CLI.
- Composer con autoload PSR-4. **Cero dependencias pesadas en producción**: lo que se
  instale en `require` debe justificarse; el grueso va en `require-dev`.
- JS del editor visual y del panel: React con `@wordpress/scripts` y `@wordpress/components`.
- Compatible con multisite.

---

## 3. Decisiones de arquitectura (ADR)

Cada decisión lleva su justificación. Si una decisión se cambia, se actualiza aquí en
el mismo commit que la implementa.

### ADR-01 — Parser HTML: arquitectura de *drivers*, primario la HTML API de WordPress

La traducción se hace **sobre el HTML final renderizado** (buffer de salida), igual que
TranslatePress, para funcionar con cualquier tema, constructor o plugin.

El requisito duro es: *no corromper* `<script>`, `<style>`, JSON-LD, plantillas inline;
conservar UTF-8 sin convertir a entidades; conservar el doctype. Eso descarta atacar el
problema con un único parser genérico y re-serializar el documento entero.

Interfaz `PolyglotAI\Html\DocumentDriverInterface`, con selección por capacidad en
tiempo de ejecución:

1. **`WP_HTML_Tag_Processor` (HTML API del core) — PRIMARIO.**
   Es la única opción que satisface el requisito *por construcción* y no por
   configuración cuidadosa del serializador: opera sobre la cadena original con
   un cursor y **no re-serializa el documento**, de modo que todo byte que no
   tocamos sale idéntico — doctype, codificación, `<script>`, `<style>`,
   JSON-LD, `<template>`, elementos personalizados. Cero dependencias,
   mantenida por el core y alineada con la especificación HTML5.
2. **`Dom\HTMLDocument` (PHP 8.4+) — ACELERADO/OPCIONAL.** Parser HTML5
   conforme implementado en C. Pendiente de la fase de rendimiento; solo se
   activaría si el banco de pruebas demuestra que hace falta.
3. **`masterminds/html5` — RESERVA.** Puro PHP, conforme, lento. En
   `require-dev` como oráculo de tests diferenciales, no en producción.
4. **`DOMDocument::loadHTML()` (libxml/HTML4) — PROHIBIDO.** Destroza HTML5
   (elementos vacíos, `<template>`, elementos personalizados), exige *hacks* de
   entidades para no romper UTF-8 y reordena o pierde el doctype. No se usa ni
   como reserva.

**Corrección tras verificar la fuente de WordPress 6.6.2** (esto contradice la
primera versión de este ADR, que daba por hecho `WP_HTML_Processor`):

- **`WP_HTML_Processor` no sirve para una página completa en 6.6.** Solo existe
  `create_fragment()`, que exige contexto `<body>` y devuelve `null` para
  cualquier otro; `create_full_parser()` no llega hasta más adelante. Una página
  con doctype y `<head>` no se puede analizar con él. El primario es por tanto
  el analizador de etiquetas, que es un barrido lineal que nunca abandona, más
  **nuestra propia pila de elementos** (`Html\Elements` + `HtmlApiDriver`).
- **Las posiciones de los tokens no son accesibles por herencia.**
  `$token_starts_at` y `$token_length` son `private`, no `protected`. La vía
  disponible son los marcadores: `set_bookmark()` es público y guarda un
  `WP_HTML_Span` con el inicio y la longitud del token en `$bookmarks`, que sí
  es `protected`. Es la **única** dependencia del plugin sobre una propiedad
  protegida del core, está aislada en `Html\OffsetTagProcessor` y
  `DriverFactory` ejecuta una sonda de capacidad en tiempo de ejecución: si una
  versión futura la cambia, no hay driver y el plugin deja de procesar la salida
  en vez de corromper páginas.
- **Elementos que llegan como un único token** y que por tanto no se apilan:
  `SCRIPT`, `STYLE`, `TITLE`, `TEXTAREA`, `IFRAME`, `NOEMBED`, `NOFRAMES`,
  `XMP`. Verificado en el `switch` de `parse_next_tag()`. Como el tokenizador no
  desciende a su interior, el contenido de los scripts y los estilos queda
  intacto sin hacer nada.
- **`get_modifiable_text()` y `get_attribute()` devuelven el texto
  decodificado.** Al escribir de vuelta hay que recodificar `&` y `<` (y `"` en
  atributos). Se recodifica solo eso: el resto del UTF-8 se conserva tal cual.

**Bloques en línea como unidad de traducción.** Un `<p>Hola <strong>món</strong></p>`
se traduce como una sola cadena. No hace falta DOM: durante el barrido se
registran los desplazamientos de byte de apertura y cierre del bloque, se corta
la subcadena y se sustituye entera. Las sustituciones se acumulan como tuplas
`(inicio, fin, reemplazo)` y se aplican **de derecha a izquierda** sobre la
cadena original, para que los desplazamientos no se invaliden.

Los controles de formulario (`INPUT`, `LABEL`, `SELECT`, `BUTTON`…) cortan la
unidad aunque no sean elementos de bloque: un formulario no es una frase, y sin
esa regla el formulario entero se convertía en una sola cadena y los atributos
de sus controles quedaban absorbidos por ella.

**Red de seguridad, obligatoria.** Tras sustituir, se comprueba que la salida
conserva el prefijo de doctype y el mismo recuento de bloques `<script>` y
`<style>` que la entrada. Si el driver lanza una excepción o la comprobación
falla, **se devuelve el buffer original intacto**. Nunca se sirve una página
corrupta: ante la duda, se sirve sin traducir.

**La reescritura de enlaces es una pasada aparte**, posterior a la de
traducción. Un enlace puede vivir dentro de una unidad de bloque ya traducida, y
hacer ambas cosas en la misma pasada produciría sustituciones solapadas, que el
empalme rechaza.

### ADR-02 — Almacenamiento: tablas propias normalizadas, **no** una tabla por idioma

Se descarta la tabla-por-idioma (el modelo de TranslatePress):

- Idiomas ilimitados ⇒ `CREATE TABLE` en tiempo de ejecución al añadir un idioma.
- En multisite explota: una red de 50 sitios × 8 idiomas = 400 tablas, y cada
  `dbDelta` de actualización tiene que recorrerlas todas.
- No aporta nada: MySQL 5.7 resuelve sin despeinarse decenas de millones de filas con
  un índice compuesto adecuado.

Esquema (todas con prefijo `{$wpdb->prefix}pgai_`):

- **`pgai_sources`** — `id`, `hash` CHAR(32) ascii_bin UNIQUE, `type` (text, block,
  attribute, meta, slug, gettext), `domain` (dominio gettext, NULL si no aplica),
  `context`, `original` LONGTEXT, `first_seen`, `last_seen`.
- **`pgai_translations`** — `id`, `source_id`, `language`, `translation` LONGTEXT,
  `status`, `engine`, `model`, `reviewed_by`, `updated_at`.
  UNIQUE(`source_id`, `language`); KEY(`language`, `status`).
- **`pgai_slugs`** — `id`, `object_type` (post_type, taxonomy, term, base),
  `object_id`, `language`, `original_slug`, `translated_slug`, `status`.
  UNIQUE(`object_type`, `object_id`, `language`); KEY(`language`, `translated_slug`)
  ← este índice es el que sirve el **enrutado inverso**.
- **`pgai_api_log`** — `id`, `created_at`, `engine`, `model`, `language`, `request_id`,
  `batch_id`, `input_tokens`, `output_tokens`, `cache_read_tokens`,
  `cache_creation_tokens`, `strings`, `status`, `error`.

Normalizar `sources` frente a `translations` evita duplicar el texto original una vez
por idioma, abarata la limpieza de huérfanas (`sources` sin `translations` y con
`last_seen` antiguo) y hace que editar el original sea una sola fila.

**Consulta caliente** (una por página e idioma):
`SELECT s.hash, t.translation, t.status FROM sources s JOIN translations t
ON t.source_id = s.id WHERE t.language = %s AND s.hash IN (…)`.

### ADR-03 — Normalización y hash

`hash = md5( normalize(original) . "\x1f" . type . "\x1f" . (context ?? '') . "\x1f" . (domain ?? '') )`

`normalize()`: recorta extremos y colapsa secuencias de espacio en blanco a un solo
espacio, **conservando el HTML interior**. Sin esto, `"  Hola\n"` y `"Hola"` serían dos
cadenas distintas y el sitio se llenaría de duplicados.

`CHAR(32)` con colación `ascii_bin` en vez de `BINARY(16)`: 16 bytes más por fila a
cambio de que la tabla sea legible y depurable con cualquier cliente SQL y de evitar
fricción con `$wpdb->prepare` y charsets binarios. Compensa.

### ADR-04 — Captura de la salida y condiciones de abandono

`ob_start()` en `template_redirect` con prioridad 1 (antes de que se emita `wp_head`).

Se **abandona sin procesar** (sin arrancar siquiera el buffer) cuando:

- `is_admin()`, `wp_doing_ajax()`, `wp_doing_cron()`, `defined('WP_CLI')`,
  `defined('REST_REQUEST')`, `is_feed()` (los feeds tienen su propio camino), login.
- El idioma solicitado es el idioma por defecto y no hay nada que sustituir.
- Modo edición de constructor: `et_fb`, `elementor-preview` / `action=elementor`,
  `fl_builder`, `bricks=run`, `vc_action`, `customize_changeset_uuid`, `tve` (Thrive).
- El `Content-Type` de la respuesta no es `text/html`.

La lista de abandonos vive en `PolyglotAI\Html\BailConditions` y es **filtrable**
(`pgai_should_process_output`). Es el primer sitio donde mirar cuando un constructor se
rompe.

### ADR-05 — Capa de motores y motor Anthropic

`PolyglotAI\Engines\TranslationEngineInterface`:

```php
public function translate( array $strings, string $source, string $target, Context $ctx ): BatchResult;
public function supports_async_batch(): bool;
public function estimate_cost( array $strings, string $target ): CostEstimate;
```

Implementación principal `Claude\ClaudeEngine`. Detalles fijados:

- **Transporte:** `wp_remote_post()` contra `https://api.anthropic.com/v1/messages`.
  Nada de SDK: un plugin de WordPress no puede arrastrar un árbol de dependencias
  Composer al `vendor/` de un sitio ajeno sin provocar colisiones de versiones.
- **Cabeceras:** `x-api-key`, `anthropic-version: 2023-06-01`, `content-type: application/json`.
- **Clave.** Se prefiere la constante `PGAI_API_KEY` en `wp-config.php`. Si se guarda
  en la BD, se cifra con `AUTH_KEY`/`SECURE_AUTH_KEY` vía `sodium_crypto_secretbox` y
  **nunca** se devuelve al navegador: los endpoints REST devuelven solo si está
  configurada y los 4 últimos caracteres.
- **Modelos.** Por defecto `claude-sonnet-5`; opción económica `claude-haiku-4-5`.
  El desplegable del panel se rellena desde `GET /v1/models` y se cachea 24 h, así que
  no hay una lista de modelos incrustada que se quede obsoleta.
  (Nota: el encargo escribía `claude-haiku-4-5-20251001`; el identificador sin sufijo
  de fecha es el canónico y es el que usamos.)
- **Salida estructurada.** Se usa `output_config.format` con `type: "json_schema"`
  (*structured outputs*), **no** *tool use*. El encargo pedía *tool use* con JSON
  Schema para evitar JSON en texto libre; `output_config.format` cumple ese objetivo
  mejor: garantiza a nivel de API que el primer bloque de texto es JSON válido contra
  el esquema, sin el viaje de ida y vuelta de `tool_use` → `tool_result`. Soportado en
  `claude-sonnet-5` y `claude-haiku-4-5`, y compatible con la Batches API.
  El esquema es **fijo entre lotes** — `{"translations":[{"id":…,"text":…}]}`, con
  `additionalProperties: false` — y no un mapa dinámico con los ids como propiedades:
  un esquema estable aprovecha la caché de compilación de esquemas de 24 h en lugar de
  pagar la compilación en cada lote.
  Limitaciones del subconjunto de JSON Schema admitido: sin esquemas recursivos, sin
  `minLength`/`maximum` y `additionalProperties` solo puede valer `false`.
- **Prompt del sistema** (array de bloques `system`, en este orden): reglas del
  traductor → contexto del sitio escrito por el administrador → glosario obligatorio →
  lista de términos que no se traducen → idioma origen/destino con variante regional y
  formalidad (tú/usted, du/Sie). Reglas: conservar todas las etiquetas y atributos HTML
  (salvo el contenido de `alt`, `title`, `placeholder`, `aria-label`), los placeholders
  (`%s`, `%1$s`, `{name}`), shortcodes, URLs, emails y números.
- **Prompt caching.** `cache_control: {"type":"ephemeral"}` en el **último bloque
  estable** del array `system`. El orden de renderizado es `tools` → `system` →
  `messages`, así que las cadenas del lote van en `messages`, después del punto de
  corte. **Mínimo de prefijo cacheable en `claude-sonnet-5`: 1024 tokens** — por debajo
  de eso no cachea y no avisa (`cache_creation_input_tokens: 0`). Si el glosario y el
  contexto no llegan al mínimo, el caching no engancha: hay que medirlo, no suponerlo.
  TTL de 5 min por defecto; `"ttl":"1h"` solo para la traducción de sitio completo, que
  es donde hay ráfagas con huecos.
- **Thinking.** Traducir no requiere razonamiento extendido: por defecto
  `thinking: {"type":"disabled"}` con `output_config.effort: "low"` para los lotes
  masivos. Ajustable en el panel; el reintento de cadenas que fallaron la validación
  estructural sube a `effort: "medium"`.
- **`max_tokens`.** Calculado a partir del tamaño del lote (≈3× los tokens de entrada
  estimados), con suelo 4096 y techo 16000 — sin *streaming*, porque `wp_remote_post`
  es bloqueante y la respuesta debe caber dentro del timeout.
- **Traducción de sitio completo:** Message Batches API (`POST /v1/messages/batches`),
  50 % de coste. Se sondea `processing_status` hasta `"ended"` y se leen los resultados
  desde `results_url`. **Los resultados llegan en cualquier orden: se indexan por
  `custom_id`, nunca por posición.** Orquestado con Action Scheduler; progreso visible,
  pausable y reanudable.
- **Medición de consumo:** `POST /v1/messages/count_tokens` para la estimación previa
  y el tope mensual; el `usage` de cada respuesta para el consumo real (incluidos
  `cache_read_input_tokens` y `cache_creation_input_tokens`, que se registran aparte
  porque tienen precio distinto). Se registra todo en `pgai_api_log`.
- **Reintentos:** *backoff* exponencial con *jitter* ante 429 y 529, respetando la
  cabecera `retry-after`. Máximo 5 intentos, luego se marca `error` y se registra.
- **Memoria de traducción:** antes de llamar a la API se busca por hash exacto y, en
  segundo lugar, por similitud sobre el texto normalizado.
- **Contexto de vecindad:** se envían las cadenas vecinas de la misma página como
  contexto de solo lectura para mejorar la coherencia.

### ADR-06 — Validación estructural posterior

Toda traducción devuelta por un motor pasa por `Translation\Validator` antes de
guardarse. Se compara entre original y traducción:

- la secuencia de nombres de etiqueta HTML y sus atributos,
- el multiconjunto de placeholders (`%s`, `%1$s`, `%d`, `{name}`),
- el multiconjunto de shortcodes,
- URLs y emails.

Si algo no cuadra, la traducción **se descarta**, se guarda con estado `error` y el
original se sirve tal cual. Un fallo de validación nunca degrada la página.

### ADR-07 — Precedencia de estados: lo manual no se pisa jamás

Estados: `pending` < `error` < `automatic` < `reviewed` < `manual`.

La traducción automática solo escribe sobre `pending` y `error`. Escribe sobre
`automatic` **solo** si el usuario ha pedido explícitamente "retraducir". **Nunca**
escribe sobre `reviewed` ni `manual` — ni en la traducción de sitio completo, ni al
cambiar de modelo, ni al reimportar. Esto es un criterio de aceptación global del
encargo, no un detalle: cualquier ruta de escritura pasa por
`Translation\StatusPrecedence::can_overwrite()`.

### ADR-08 — Caché

- Diccionario por (URL, idioma) en caché de objeto (`wp_cache_*`), grupo `pgai_dict`,
  no persistente entre peticiones salvo que haya Redis/Memcached.
- Invalidación al guardar cualquier traducción de esa página, y por versión global
  (`pgai_dict_version`) en operaciones masivas: se incrementa un entero en vez de
  recorrer y borrar claves.
- Bloqueo con `wp_cache_add()` (atómico) para no traducir la misma cadena en paralelo
  desde dos peticiones.
**Rendimiento medido** (no estimado; `tests/unit/PerformanceTest.php`):

| HTML | Extraer | Traducir entero |
|---|---:|---:|
| 21 KB | 8,2 ms | 8,5 ms |
| 128 KB | 47 ms | 49 ms |

Son **0,37 ms por KB**, de los cuales una cuarta parte es el propio
`WP_HTML_Tag_Processor` del core y el resto lógica nuestra. El empalme es
marginal frente al barrido, como debe ser.

Eso sitúa el objetivo de < 50 ms en páginas de **hasta unos 128 KB de HTML**. Se
cumple con holgura en una página de blog o de tienda corriente. **No se cumple**
en páginas grandes de Divi o Elementor, que pasan a menudo de 200 KB: ahí el
sobrecoste ronda los 75 ms. Queda como trabajo de la fase 8, y es el escenario
que justificaría activar el driver de `Dom\HTMLDocument` del ADR-01.

El test de rendimiento **no mide milisegundos absolutos**, que dependen de la
máquina de CI: mide el coste del driver en relación con el del barrido en crudo
del core sobre el mismo documento. Umbral calibrado con mediciones reales sobre
128 KB — 3,6 con coste lineal, 7,0 con una regresión cuadrática introducida a
propósito para comprobar que el test la detecta— y fijado en 5,0.

Una primera versión de este test comparaba tiempos entre dos tamaños de
documento. Se descartó tras comprobar que **no detectaba** la regresión
cuadrática inyectada: a esos tamaños el término cuadrático no llegaba a dominar
sobre el lineal.

### ADR-09 — Enrutado

- Estructura por subdirectorio: `/en/`, con opción de subdirectorio también para el
  idioma por defecto.
- El idioma se resuelve **una sola vez y pronto** (en `plugins_loaded`, a partir de
  `REQUEST_URI`) y se guarda en un singleton; nada de volver a parsear la URL en cada
  llamada.
- Reescritura de enlaces internos en la capa de salida (ADR-01), incluidos `action` de
  formularios, paginación, búsqueda y feeds.
- Enrutado inverso de slugs por el índice `KEY(language, translated_slug)` de
  `pgai_slugs`; redirección 301 del slug sin traducir al traducido.

### ADR-10 — REST y capacidades

Namespace `pgai/v1`. Todos los endpoints con `permission_callback` real (nunca
`__return_true`) y nonce `wp_rest`. Capacidades propias, asignadas por rol:

| Capacidad | Qué permite |
|---|---|
| `pgai_translate` | Editar traducciones en el editor visual |
| `pgai_review` | Marcar cadenas como revisadas |
| `pgai_run_auto_translate` | Lanzar traducción automática (gasta dinero) |
| `pgai_manage_languages` | Añadir/quitar/activar idiomas |
| `pgai_manage_settings` | Ajustes, clave de API, límites |

El rol "Traductor" recibe `pgai_translate` y `read`, y **no** accede al escritorio.

### ADR-11 — Opciones y multisite

- Una sola opción `pgai_settings` (array, autoload `yes`) para lo que se lee en cada
  petición; lo voluminoso (glosario, exclusiones) en opciones separadas con
  autoload `no`.
- Las tablas son **por sitio** (`$wpdb->prefix`), no por red: los contenidos de cada
  sitio son independientes.
- La clave de API puede definirse a nivel de red con `PGAI_API_KEY`.

### ADR-12 — Seguridad y privacidad

- Nonces + comprobación de capacidad en **toda** acción; `sanitize_*` a la entrada y
  `esc_*` a la salida, siempre en el punto de uso.
- Traducciones manuales con HTML: `wp_kses` con lista blanca propia
  (`pgai_allowed_html`), nunca `wp_kses_post` a secas en contexto de traductor.
- Las llamadas a la API provocadas por tráfico de visitantes **nunca bloquean la
  carga** y están acotadas por presupuesto y por filtro de bots (ADR-13).
- **Nunca se envían datos personales a la API.** Exclusión por defecto de las rutas de
  cuenta, carrito, checkout, pedidos y de los datos enviados en formularios. Lista en
  `Compat\PrivacyExclusions`, documentada para el RGPD.
- Desinstalación limpia opcional (`uninstall.php` borra tablas y opciones solo si el
  administrador lo ha marcado).

### ADR-13 — Traducción en tiempo real: activada, en segundo plano

Cuando un visitante pide una página en un idioma activo y hay cadenas sin traducir:

1. Se sirve **el original** para esas cadenas. La carga **no se bloquea nunca** y no se
   hace ninguna llamada a la API dentro de la petición del visitante.
2. Las cadenas nuevas ya quedan escritas en `pgai_sources` por el propio barrido, con
   su `pgai_translations` en estado `pending`. "Encolar" es por tanto una inserción
   barata con `INSERT IGNORE`, sin cola aparte.
3. Se programa (con `as_enqueue_async_action`, desduplicada por idioma) una tarea de
   Action Scheduler que traduce lo pendiente por lotes.
4. La traducción aparece en la visita siguiente.

Salvaguardas, todas obligatorias porque aquí se gasta dinero con tráfico que no
controlamos:

- **Filtro de bots.** `Detection\BotDetector` con lista de user-agents conocidos, más
  peticiones sin `Accept-Language` y peticiones a `robots.txt`/sitemaps. Un bot **nunca**
  encola nada. Sin esto, un rastreo completo del sitio dispara la factura.
- **Tope de presupuesto.** El límite mensual de tokens de los ajustes corta el encolado,
  no solo las llamadas: al alcanzarlo se sigue sirviendo el original y se avisa en el
  panel.
- **Límite por petición.** Un máximo configurable de cadenas nuevas encoladas por
  página, para que una página enorme no genere un lote desproporcionado.
- **Exclusiones de privacidad.** Las rutas de ADR-12 (cuenta, carrito, checkout,
  pedidos, datos de formularios) no encolan nunca.
- Interruptor en el panel para desactivarlo por completo y volver a traducción solo
  manual o por lotes.

### ADR-14 — Distribución comercial

El plugin se distribuye de forma **comercial/privada**, no por WordPress.org.
Consecuencias prácticas:

- Un único plugin con toda la funcionalidad; los "add-ons" de la paridad con
  TranslatePress Business son módulos internos activables, no plugins separados.
- Libertad para empaquetar dependencias en `vendor/`; aun así se mantiene el criterio
  de ADR-05 de no arrastrar árboles de dependencias innecesarios, porque el problema
  real son las colisiones de versiones con otros plugins del sitio, no la política del
  repositorio.
- Hace falta un **mecanismo propio de actualización y licencias** (no hay
  `wp.org` que sirva las actualizaciones). Se decide en la Fase 8; no condiciona nada
  de las fases 1-7.
- Se entrega igualmente el `readme.txt` en formato WordPress.org que pide el encargo:
  sirve como ficha de producto y deja la puerta abierta a publicar una versión gratuita
  reducida.
- Sin las restricciones de wp.org, el consentimiento para llamar a un servicio externo
  no es una obligación del repositorio, pero **se mantiene igualmente** el aviso
  explícito en el asistente de configuración: el administrador debe saber que el
  contenido de su sitio se envía a un tercero (y es lo que exige el RGPD).

### ADR-15 — Integración con los plugins de SEO: sobre la salida, no sobre sus hooks

Yoast, Rank Math, SEOPress y All in One SEO emiten el `<title>`, la
`meta description`, las etiquetas Open Graph y su propia URL canónica. Hay dos
formas de adaptarlos a un sitio multilingüe:

1. Engancharse a los filtros de cada uno (`wpseo_canonical`,
   `rank_math/frontend/canonical`, `seopress_titles_canonical`,
   `aioseo_canonical_url`…).
2. Corregir el HTML final, que es donde ya estamos trabajando (ADR-01).

Se elige la segunda, y no por comodidad:

- **El texto ya está resuelto sin hacer nada.** El barrido de la salida extrae
  `<title>`, `meta[name=description]`, `og:title`, `og:description`,
  `og:site_name`, `og:image:alt` y `twitter:*`. Sea cual sea el plugin que los
  haya escrito, se traducen como cualquier otra cadena del sitio y se revisan
  desde el mismo editor. Un juego de filtros por plugin no añadiría nada a esto
  y habría que mantenerlo cuádruple.
- **Cuatro plugins son cuatro APIs que cambian entre versiones mayores.** Yoast
  reescribió su capa de frontend entera en la 14. Una pasada sobre el HTML
  funciona con los cuatro, con sus versiones futuras y con el quinto que
  aparezca, sin tener que reconocerlo.
- **Lo que falta es solo la URL**, y es un problema uniforme: la canónica, la
  `og:url` y la paginación salen de `get_permalink()`, así que ya llevan el slug
  traducido y solo les falta el prefijo de idioma.

`Seo\HeadUrls` es esa pasada. Va **después** de la de enlaces y aparte de ella
porque la regla es distinta: aquí manda el `rel`, no la etiqueta.

**`rel="alternate"` no se toca nunca.** Ahí viven nuestros propios `hreflang`,
que apuntan a otros idiomas a propósito, y también los feeds RSS. Convertirlos
al idioma en curso sería estropear justo lo que se acaba de emitir bien. Por la
misma razón `LinkRewriter` sigue sin tocar ningún `<link>`: en esa pasada no se
mira el `rel` y no habría forma de distinguir un canónico de un `hreflang`.

Tampoco se toca `og:image`: es un archivo, no una página, y no tiene versión por
idioma.

### ADR-16 — Sitemaps: el del núcleo ahora; los de los plugins de SEO, en la fase 8

El sitemap del núcleo solo conoce las URLs del idioma por defecto, así que un
buscador no tenía por dónde descubrir `/en/contact-us/` salvo rastreando
enlaces. `Seo\TranslatedSitemapProvider` publica un sitemap por idioma dentro
del de WordPress.

**El idioma va en el subtipo, no en el nombre del proveedor.** La regla de
reescritura del núcleo captura el nombre con `[a-z]+`, de modo que un proveedor
llamado `pgai-en` no encajaría con ninguna ruta y su sitemap devolvería un 404.
El subtipo sí admite cifras y guiones (`[a-z\d_-]+`), que es lo que necesitan
slugs como `pt-br`. Queda `/wp-sitemap-pgai-en-1.xml`.

Entradas y términos van en subtipos distintos (`en` y `en-tax`) porque paginar
una lista que mezcla dos consultas obliga a repartir desplazamientos entre
ellas, y eso se descuadra en cuanto una de las dos cambia de tamaño entre dos
peticiones.

**Sin `xhtml:link` alternates**, porque el renderizador del núcleo no los
admite: `WP_Sitemaps_Renderer` solo escribe `loc`, `lastmod`, `changefreq` y
`priority`, y avisa con `_doing_it_wrong` de cualquier otra clave. Reemplazar el
renderizador entero para añadirlos no compensa: el `hreflang` de cada página ya
da esa señal, y lo que faltaba —que las URLs traducidas fueran descubribles— sí
queda resuelto.

**La integración con los sitemaps de Yoast, Rank Math, SEOPress y All in One
SEO se aplaza a la fase 8**, junto con el resto de pruebas de compatibilidad.
Cada uno sustituye el sitemap del núcleo por el suyo y lo amplía a su manera, y
no tengo forma de comprobar esos hooks hasta tenerlos instalados en `wp-env`.
Escribir cuatro juegos de `add_filter` sin poder ejecutarlos daría código que
parece hecho y puede no hacer nada, que es peor que no tenerlo: esto queda
anotado como pendiente y no como resuelto.

Lo que **sí** funciona ya con los cuatro es lo demás del SEO Pack: el texto que
emiten lo traduce el barrido de la salida y sus URLs las corrige `Seo\HeadUrls`
(ADR-15).

---

## 4. Estructura del repositorio

```
polyglot-ai.php          Cabecera del plugin y arranque
uninstall.php
composer.json  package.json  phpcs.xml.dist  phpstan.neon.dist
phpunit.xml.dist  playwright.config.js  .wp-env.json
src/
  Plugin.php             Contenedor y registro de servicios
  Bootstrap/             Activación, desactivación, comprobación de requisitos
  Database/              Schema, Migrator, repositorios
  Languages/             Registro de idiomas, variantes, RTL
  Routing/               UrlConverter, RequestRouter, SlugResolver, SlugSync,
                         PermalinkTranslator, LinkRewriter, InternalUrl, HeadTags
  Html/                  Drivers, extractor, sustituidor, exclusiones, BailConditions
  Translation/           Dictionary, Normalizer, Hasher, Validator, StatusPrecedence, Memory
  Engines/               Interfaz + Claude/{ClaudeEngine,Client,PromptBuilder,Schema,Batches,UsageMeter}
  Seo/                   HeadUrls, StructuredData, Sitemaps
  Gettext/  Editor/  Rest/  Admin/  Switcher/  Detection/
  Compat/                WooCommerce, Forms, Cache, Builders, SeoPlugins
  Jobs/                  PendingTranslator, SlugTranslator, Budget, ContextFactory
  Support/               Options, Capabilities, Logger, Lock, Cache
assets/src → assets/build
languages/               polyglot-ai.pot
tests/phpunit/  tests/e2e/
docs/
```

---

## 5. Comandos

```bash
composer install
npm install

# Entorno
npm run env:start          # wp-env up
npm run env:stop

# Calidad (los tres tienen que estar en verde al cerrar cada fase)
composer phpcs             # WordPress Coding Standards
composer phpcbf            # autocorrección
composer phpstan           # nivel 6
npm run lint:js

# Tests
composer test              # PHPUnit con la suite de WordPress
composer test -- --filter HtmlDriverTest
npm run test:e2e           # Playwright (editor visual, selector de idioma)

# Build
npm run build              # @wordpress/scripts, producción
npm run start              # watch
npm run makepot            # regenera languages/polyglot-ai.pot
```

---

## 6. Cómo trabajamos

- **Fase a fase.** Al cerrar una fase: tests en verde, `phpcs` y `phpstan` limpios,
  resumen de lo hecho y de lo pendiente.
- **Commits pequeños y descriptivos**, en imperativo y en español.
- Rama de desarrollo: `claude/nice-ramanujan-wlznzz`.
- **Todas las cadenas de interfaz traducibles** (`__()`, `_x()`, `wp.i18n`), con text
  domain `polyglot-ai`. Interfaz por defecto en español. Se regenera el `.pot` en la
  fase que añada cadenas.
- Cada hook y filtro público se documenta en `docs/hooks.md` **en el mismo commit** que
  lo introduce.
- Si una decisión de este documento resulta equivocada al implementarla, se cambia aquí
  con su justificación en el mismo commit.

---

## 6 bis. Estado por fases

| Fase | Estado |
|---|---|
| 1. Base | Completa |
| 2. Editor visual y API REST | Completa |
| 3. Gettext, contenido dinámico y correos | Completa |
| 4. SEO Pack | Completa, salvo los sitemaps de los plugins de SEO (ADR-16) |
| 5–8 | Sin empezar |

Dos puntos del encargo que caen en la fase 3 pertenecen en realidad a fases
posteriores y se dejan ahí a propósito:

- **Editar desde el panel las cadenas de gettext que nunca llegan a una
  página.** Las que sí aparecen ya se editan desde el editor visual; el resto
  quedan anotadas y esperan al gestor de cadenas de la fase 6.
- **Idioma del pedido y respuestas de `admin-ajax.php`.** El observador de
  mutaciones ya traduce el resultado visible de esas respuestas, y el filtro
  `pgai_recipient_language` es el punto de entrada para el idioma del pedido.
  La integración concreta con WooCommerce es de la fase 8.

Y uno de la fase 4:

- **Los sitemaps de Yoast, Rank Math, SEOPress y All in One SEO.** Cada uno
  sustituye el del núcleo por el suyo y lo amplía a su manera, y esos hooks no
  se pueden comprobar hasta tener los cuatro plugins instalados en `wp-env`.
  Va con el resto de pruebas de compatibilidad de la fase 8 (ADR-16). El
  sitemap del núcleo sí lleva ya un sitemap por idioma, y el resto del SEO Pack
  —título, descripción, Open Graph, datos estructurados y URLs— funciona con
  los cuatro desde ahora.

## 7. Decisiones confirmadas y pendientes

**Confirmadas** (2026-09-17), ya incorporadas arriba:

1. Mínimo de WordPress **6.6+** → ADR-01.
2. Detección del visitante: **solo por idioma del navegador** (`Accept-Language`) en la
   v1. Nada de MaxMind ni de descarga de bases de datos. `Detection\ProviderInterface`
   se deja preparada para añadir GeoIP más adelante sin tocar el resto.
3. Distribución **comercial/privada** → ADR-14.
4. Traducción en tiempo real **activada, en segundo plano** → ADR-13.

**Pendientes**, no bloquean las fases 1-7:

5. **Mecanismo de actualización y licencias** (Fase 8): servidor propio, EDD Software
   Licensing, Freemius u otro.
6. **Licencias para pruebas de compatibilidad** (Fase 8): Divi y Elementor Pro son de
   pago y hacen falta en `wp-env` para las pruebas E2E. Con las versiones gratuitas se
   cubren Elementor, Gutenberg, Astra y GeneratePress; Divi y Beaver/Bricks no.
