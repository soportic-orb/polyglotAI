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
  instale en `require` debe justificarse; el grueso va en `require-dev`. La única que
  hay es `woocommerce/action-scheduler`, justificada en el ADR-21. La autocarga de
  Composer es opcional: si no está, el plugin registra una PSR-4 propia.
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
2. **`Dom\HTMLDocument` (PHP 8.4+) — DESCARTADO.** Parser HTML5 conforme
   implementado en C. Se dejó apuntado como posible acelerador para las
   páginas grandes, pero al medirlas resultó que el coste que crecía estaba en
   el empalme y no en el análisis (ADR-08), y que re-serializa el documento al
   guardarlo, que es justo lo que este ADR descarta por construcción. No se va
   a usar.
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

- **`pgai_sources`** — `id`, `hash` CHAR(32) UNIQUE, `text_hash` CHAR(32) KEY (hash
  solo del texto normalizado, para la memoria de traducción), `type` (text, block,
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
- Modo edición de constructor. Comprobados en el código del propio plugin:
  `elementor-preview` y `action=elementor` (Elementor), `fl_builder` (Beaver),
  `is-editor-iframe` (Brizy, que edita dentro de un iframe del frente; la lista decía
  `brizy-edit`, que no existe), `siteorigin_panels_live_editor` y
  `customize_changeset_uuid`. Sin comprobar, por ser de pago y no poder instalarlos:
  `et_fb` (Divi), `bricks`, `vc_action` (WPBakery), `tve` (Thrive), `ct_builder`
  (Oxygen). Equivocarse en estos últimos solo significa que el constructor se vería
  traducido en su propia pantalla de edición, no que se rompa el sitio publicado.
- **Vista previa** (`is_preview()`, `is_customize_preview()`). Un borrador es contenido
  sin publicar: procesarlo lo guardaría en `pgai_sources` y la tarea de fondo acabaría
  mandándolo a la API (ADR-13), de modo que un anuncio con fecha o una página de producto
  sin estrenar saldrían del sitio antes de estar publicados. Y como cambia en cada
  revisión, se pagaría además por traducir borradores que luego se tiran. El editor
  visual no se ve afectado: usa sus propios parámetros (`pgai-edit`, `pgai-as`) y no la
  vista previa de WordPress.
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
- **Memoria de traducción:** antes de llamar a la API se busca por `text_hash`, el
  hash solo del texto normalizado, de modo que la misma frase ya traducida en otro
  tipo o contexto se copia en vez de volver a pagarse. **No hay búsqueda por
  similitud**: encontrar «Añadir al carrito ahora» a partir de «Añadir al carrito»
  exigiría un índice de trigramas o FULLTEXT sobre un LONGTEXT, y el ahorro no
  compensa ni el coste de escritura ni el riesgo de reutilizar una traducción que no
  era. Es una limitación conocida, no un olvido.
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
- **La caché de página es de otro y hay que avisarle.** Todo lo anterior es caché de
  objeto, dentro de la petición de WordPress; en producción casi siempre hay delante un
  plugin que guarda el HTML entero. Sin avisarle, la promesa del ADR-13 —«la traducción
  aparece en la visita siguiente»— es falsa: la tarea de fondo traduce y el visitante
  sigue recibiendo la copia que se guardó sin traducir. `Compat\CachePlugins` vacía la
  caché de WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed, Cache Enabler, WP
  Fastest Cache y SiteGround Optimizer (nombres comprobados en el código de cada uno,
  salvo el de WP Rocket, que es de pago), y deja la acción `pgai_page_cache_purged` para
  las demás. **Una vez por petición**, porque una traducción de sitio completo guarda
  miles de cadenas de golpe y vaciar en cada una dejaría el sitio sin caché durante
  horas, y **entera**, porque una misma cadena puede salir en cualquier página y
  averiguar en cuáles costaría más que regenerarlas.

**Rendimiento medido** (no estimado; `tests/unit/PerformanceTest.php`), sobre la
misma máquina antes y después de arreglar el empalme:

| HTML | Traducir entero, antes | Después | ms/KB antes | Después |
|---|---:|---:|---:|---:|
| 65 KB | 20,1 ms | 17,7 ms | 0,309 | 0,273 |
| 128 KB | 40,9 ms | 34,3 ms | 0,319 | 0,268 |
| 254 KB | 90,4 ms | 68,6 ms | 0,356 | 0,270 |

Lo importante de esa tabla no es el 24 % que se ahorra en la página grande, que
depende de la máquina: es que **el coste por KB ha dejado de crecer**. Antes
subía de 0,309 a 0,356 conforme crecía la página, porque el empalme aplicaba
cada sustitución sobre el documento entero y por tanto costaba
O(sustituciones × documento) —200 MB de copias en una página de 128 KB, casi
800 MB en una de 254 KB—. Montando el resultado por trozos, cada byte se copia
una vez y el coste por KB se queda plano en 0,270 a cualquier tamaño.

Del coste que queda, una cuarta parte es el propio `WP_HTML_Tag_Processor` del
core, un 9 % leer la posición de cada token y **algo más de la mitad es lógica
nuestra**: la pila de elementos, las exclusiones y el reparto de atributos. El
empalme ya es marginal: 0,9 ms de los 34 en una página de 128 KB.

Eso sitúa el objetivo de < 50 ms en páginas de **hasta unos 190 KB de HTML**. Se
cumple de sobra en una página de blog o de tienda corriente y también en muchas
de constructor. **Sigue sin cumplirse** en las páginas más grandes de Divi o
Elementor, que pasan de 250 KB: ahí se ronda los 70 ms. Lo que queda por ganar
está en el barrido, no en el empalme, así que es trabajo de perfilar nuestra
propia lógica.

**Lo que no era.** El ADR-01 y una versión anterior de este ADR daban por hecho
que el remedio para las páginas grandes sería activar el driver de
`Dom\HTMLDocument`. No lo era, por dos motivos que solo se ven al medir: el
coste que crecía estaba en el empalme, donde el analizador no interviene, y
`Dom\HTMLDocument` re-serializa el documento al guardarlo, que es justo lo que
el ADR-01 descarta por construcción. Queda descartado también como acelerador.

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
- **Nunca se envían datos personales a la API.** Tres exclusiones, todas por defecto y
  todas en `Compat\PrivacyExclusions`, documentadas para el RGPD:

  1. **Las páginas personales se las pregunta a WooCommerce**, no a la URL. La lista de
     rutas de los ajustes (`/checkout`, `/my-account`…) solo acierta si el sitio usa
     esos slugs, y en un sitio multilingüe precisamente no los usa: la página de pago en
     catalán es `/ca/pagament/` y no se parece a ninguna cadena de la lista, de modo que
     quedaban fuera de la exclusión justo las páginas que más datos personales enseñan.
     `is_cart()`, `is_checkout()`, `is_account_page()` e `is_wc_endpoint_url()` aciertan
     con cualquier slug, en cualquier idioma y aunque se hayan cambiado de sitio. Se
     llaman por nombre de función, así que no hay dependencia de código con WooCommerce;
     la lista de rutas se queda como red para los sitios que no lo llevan.
  2. **Las respuestas a un POST no se traducen.** Una respuesta a un envío de formulario
     está construida con lo que acaba de escribir el visitante —su nombre en un
     «Gracias, …», el resumen de su pedido, su mensaje—, y distinguir dentro del HTML qué
     frase viene del formulario y cuál es del tema no se puede hacer con garantías. Se
     sirve el original, que es el criterio de toda la red de seguridad del ADR-01.
  3. **El contenido de los `<textarea>` no se traduce.** Es lo que ha escrito el
     visitante: un comentario que vuelve tras un error de validación, las notas de un
     pedido, el mensaje de un formulario de contacto. El texto de interfaz de esos
     controles va en `placeholder`, que sí se traduce, así que no se pierde nada. Hay un
     filtro (`pgai_translate_textarea`) para quien tenga textareas con contenido
     estático de verdad.
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

### ADR-16 — Sitemaps: una sola lista de URLs, la sirva quien la sirva

El sitemap del núcleo solo conoce las URLs del idioma por defecto, así que un
buscador no tenía por dónde descubrir `/en/contact-us/` salvo rastreando
enlaces.

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

**Los cuatro plugins de SEO apagan el sitemap del núcleo, y los cuatro con el
mismo filtro.** Esto era lo que la fase 4 no pudo comprobar y aplazó; con Yoast
28.5, Rank Math 1.0.278, SEOPress 10.2 y All in One SEO 5.0.1.1 instalados
resulta que los cuatro hacen `add_filter( 'wp_sitemaps_enabled', '__return_false' )`.
Con ese filtro se van también las rutas `wp-sitemap-*.xml` y con ellas el
proveedor de arriba: las URLs traducidas dejaban de ser descubribles.

La respuesta no es reimplementar el sitemap de cada plugin, sino separar **qué
URLs hay** de **quién las sirve**:

| Pieza | Papel |
|---|---|
| `Seo\TranslatedUrls` | La lista de URLs traducidas, paginada por subtipo. Única. |
| `Seo\TranslatedSitemapProvider` | La sirve dentro del sitemap del núcleo. |
| `Seo\StandaloneSitemap` | La sirve en rutas propias cuando el núcleo no está. |
| `Compat\SeoPlugins` | Mete esos archivos en el índice del plugin de SEO activo. |

Las dos vías de servicio son **excluyentes**: solo se anuncia lo que se sirve, y
si el sitemap del núcleo sigue en pie las rutas propias no responden, porque
esas mismas URLs ya están dentro de él. Publicarlas en dos sitios no aporta
nada y obliga a mantener dos verdades.

**No se detecta qué plugin hay instalado.** Los cuatro filtros se registran
siempre; el que no tenga plugin detrás no se dispara nunca y no cuesta nada.
Reconocer cada plugin por su constante de versión es justo el código que se
queda obsoleto en la siguiente versión mayor, que es lo que ya argumenta el
ADR-15. Lo único que cambia entre los cuatro es el formato de la entrada,
comprobado en el código de cada uno:

| Plugin | Hook | Entrada |
|---|---|---|
| Yoast | `wpseo_sitemap_index_links` | `['loc' => …, 'lastmod' => …]` |
| Rank Math | `rank_math/sitemap/index` | XML en crudo que se concatena |
| SEOPress | `seopress_sitemaps_external_link` | `['sitemap_url' => …, 'sitemap_last_mod' => …]` |
| All in One SEO | `aioseo_sitemap_indexes` | `['loc' => …, 'lastmod' => …, 'count' => …]` |

Que el de Rank Math es el bueno se ve en su propio módulo de Local SEO, que
añade su `local-sitemap.xml` al índice por ese mismo filtro y de esa misma
forma.

**Las rutas propias no usan reglas de reescritura.** Una regla nueva no existe
hasta que alguien vacía las reglas, y aquí el disparador es activar un plugin
ajeno: el sitio serviría 404 hasta que al administrador se le ocurriera volver a
guardar los enlaces permanentes. Se resuelven en `parse_request`, que corre
antes de que WordPress decida el 404 y antes de enviar ninguna cabecera,
mirando la ruta como ya hace el enrutador del ADR-09. El prefijo de idioma ya
viene quitado de ahí, así que `/en/pgai-sitemap.xml` no necesita nada aparte.

**No se anida un índice dentro de otro.** El protocolo de sitemaps.org solo
admite `<sitemap>` apuntando a archivos de URLs, no a otros índices; Google lo
tolera, pero no hace falta apoyarse en eso pudiendo enlazar los archivos uno a
uno, que es lo que ya sabemos enumerar. Nuestro índice `/pgai-sitemap.xml`
existe igualmente y se anuncia en `robots.txt`, que es lo único que hace
descubribles las URLs traducidas si el sitemap del núcleo está apagado y no hay
ningún plugin de SEO detrás.

### ADR-17 — Detección del visitante: implementada, desactivada por defecto

La detección va **solo por `Accept-Language`** (decisión 2 del apartado 7): el
país no es el idioma —en Bélgica se habla neerlandés y francés, y un español en
Berlín sigue queriendo leer en español—, así que la cabecera que el propio
visitante envía es mejor señal que su dirección IP, y además no obliga a
descargar ni a mantener ninguna base de datos.

`Detection\BrowserLanguage` empareja de lo más preciso a lo menos: primero
agota toda la lista del navegador buscando un locale exacto y solo después se
conforma con el idioma base, porque un `es-MX` exacto más abajo es mejor que un
`es` aproximado más arriba. Respeta el factor `q`, y trata una `q` mal formada
como ausente: el navegador ha nombrado ese idioma y eso cuenta.

**La redirección viene desactivada.** Es una decisión de producto con dos
motivos concretos:

- **Rompe las cachés de página que no varían por cookie.** La primera respuesta
  cacheada se queda con la redirección dentro y se la lleva todo el mundo. Por
  eso, además, la redirección no se emite nunca si ya hay cookie: la petición
  cacheable es siempre la misma.
- **Se lleva al visitante a donde no ha pedido ir.** Quien sigue un enlace a la
  versión española desde una red social con el navegador en inglés acaba en la
  inglesa.

Quien la active sabrá lo que hace; quien no, tiene el selector, que es explícito
y no sorprende a nadie.

Salvaguardas cuando está activada: nunca a un robot (mismo `BotDetector` de
ADR-13), nunca en un POST, ni en un 404, ni en un feed, ni en una vista previa,
ni a quien ya ha elegido antes. Navegar a `/en/` cuenta como elección y se
recuerda en una cookie, de modo que a partir de ahí manda lo que el visitante ha
hecho y no lo que dice su navegador. El filtro `pgai_detected_language` permite
cancelarla o cambiarla caso por caso.

### ADR-18 — El selector se pinta siempre en el servidor, desde un único sitio

`Switcher\SwitcherRenderer` es el único lugar donde se decide a qué URL lleva
cada idioma. El shortcode, el bloque de Gutenberg, el elemento de menú y el
selector flotante son envoltorios suyos.

No es simetría por gusto: calcular ese enlace tiene una trampa —hay que volver
al slug original antes de traducir al idioma de destino, o desde `/ca/contacte/`
el enlace al inglés sale como `/en/contacte/`—, y con cuatro implementaciones
habría cuatro sitios donde volver a caer en ella. De hecho el shortcode de la
fase 1 caía.

Por lo mismo, **el bloque no guarda HTML en la entrada**: los enlaces dependen
de la página que se está viendo, de los idiomas activos en ese momento y de los
slugs traducidos de esa página. Un HTML guardado se quedaría obsoleto en cuanto
se añadiera un idioma o se corrigiera un slug, y habría que reeditar cada
entrada.

Y por lo mismo los elementos de menú guardan un centinela (`#pgai-switcher`,
`#pgai-lang-en`) en vez de una URL: no existe una URL que se pueda guardar una
vez, y un menú guardado sigue valiendo si después se añade o se quita un idioma.

### ADR-19 — La traducción de sitio completo vive entre peticiones, no dentro de una

Enviar, preguntar y recoger son tres momentos separados por horas, y cada uno es
una pasada de Action Scheduler. No es una elección estética: una traducción de
sitio completo puede tardar más que cualquier `max_execution_time`, así que nada
de esto puede vivir dentro de una sola petición.

De ahí que el estado (`Jobs\SiteRun`) se guarde entre pasadas: es lo que hace
que el trabajo sea **pausable y reanudable de verdad** y no solo que lo parezca.

- **Pausar no cancela el lote en vuelo.** Lo ya enviado se cobra igual, así que
  tirarlo sería pagar por nada: se deja de preguntar y de enviar más, y reanudar
  vuelve a esperar por el mismo lote sin reenviarlo.
- **Un lote por pasada**, no el sitio entero de golpe. Así el mapa de trozos que
  hay que recordar tiene un tamaño acotado, y si algo va mal se pierde una tanda
  y no el trabajo de un día.
- **El estado va en una opción por idioma, sin autocarga.** Es una fila por
  idioma activo y solo se lee cuando alguien mira el progreso o cuando corre la
  tarea; una tabla nueva habría significado migrar el esquema para guardar como
  mucho ocho filas. Sin autocarga porque el mapa de trozos puede ocupar y no
  tiene por qué estar en memoria en cada visita de cada visitante.

**Los lotes asíncronos son una interfaz aparte** (`AsyncBatchEngineInterface`),
no métodos nuevos en `TranslationEngineInterface`: no todo motor los admite, y
el ciclo es distinto del de una llamada normal. Si el motor configurado no los
admite, la pantalla lo dice en vez de enseñar un botón que no hace nada.

### ADR-20 — Licencias y actualizaciones: servidor propio

Decisión 5 del apartado 7, confirmada: **servidor propio**, ni Freemius ni EDD
ni un vendedor registrado. Con la distribución privada del ADR-14 —pocos
clientes, sin escaparate— una pasarela con comisión por venta no resuelve ningún
problema que tengamos, y las dos alternativas de SaaS obligan a meter su SDK en
el `vendor/` del sitio del cliente, que es exactamente lo que el ADR-05 evita
para no chocar con otros plugins. El cliente son tres clases; el servidor es
trabajo aparte y no vive en este repositorio.

El contrato con el servidor está en `docs/licensing.md`. Lo que se decide aquí:

**Lo que responde el servidor no se cree a ciegas.** WordPress instala sin
rechistar el zip que diga `package`, así que una respuesta manipulada —un DNS
envenenado, un proxy de por medio, el propio servidor comprometido— podría hacer
que el sitio del cliente instalara cualquier cosa bajo el nombre de este plugin.
El actualizador del núcleo no verifica firmas de plugins de terceros, así que la
única defensa real es exigir que la descarga sea `https://` **y esté en el mismo
host que el servidor de licencias**. Si no cuadra se descarta la actualización
entera, no se recorta la parte sospechosa. Consecuencia práctica: las descargas
se sirven desde el mismo host que la API, y moverlas a un CDN obliga a volver
aquí.

**No se llama a casa sin licencia.** Sin clave configurada no se contacta con el
servidor. Un sitio que nunca ha comprado nada no tiene por qué anunciar su
dirección a ninguna parte, y así el plugin se puede usar en desarrollo sin
tráfico saliente de ningún tipo.

**Sin servidor configurado no hay comprobación.** `PGAI_UPDATE_SERVER` se fija al
empaquetar; si no está, el plugin funciona igual y simplemente no se actualiza
solo. Es lo que queremos en una copia del repositorio.

**Un servidor caído no molesta a nadie.** Cinco segundos de espera, y si no
responde no se ofrece actualización ni se enseña ningún error. Se reintenta a la
hora; una respuesta buena se guarda medio día, porque WordPress mira si hay
actualizaciones muchas veces por sesión de escritorio y cada una no puede ser una
petición de red.

**La licencia no es un interruptor de encendido.** Una licencia caducada o
inválida deja al plugin sin actualizaciones, no sin funcionar: el sitio del
cliente sigue traducido y el panel sigue abierto. Vender una suscripción a
actualizaciones es una cosa y tomar el sitio de alguien como rehén es otra.

**La clave de licencia no se cifra**, a diferencia de la de la API (ADR-05). La
de Anthropic gasta dinero en un tercero y el administrador no tiene por qué
volver a verla; la de licencia es suya, la copia de su factura, tiene que poder
leerla para pegarla en otro sitio, y lo peor que puede hacer quien la robe es
recibir actualizaciones de algo que ya ha pagado alguien.

### ADR-21 — Se instala como un zip desde el panel, con todo dentro

El administrador sube un archivo en Plugins → Añadir nuevo → Subir plugin, lo
activa y ya está. **No entra en el servidor ni ejecuta nada.** Eso obliga a tres
cosas que antes no se cumplían.

**Action Scheduler va dentro del paquete.** Es la única dependencia de
producción, y el ADR-05 pide justificar cada una: sin ella, `as_enqueue_async_action()`
no existe, y como todas las llamadas están detrás de un `function_exists()` la
traducción en segundo plano (ADR-13) y la de sitio completo (ADR-19) **se
quedaban calladas sin hacer nada**. No era un fallo visible: el sitio funcionaba,
servía el original y no traducía nunca. Empaquetarla es lo que hace WooCommerce
y para lo que está pensada —negocia su versión con las demás copias que haya en
el sitio—, y no arrastra ninguna dependencia propia. Se carga en el archivo
principal, antes de `plugins_loaded`, porque es cuando entra en esa negociación.

Consecuencia que hay que asumir: Action Scheduler es **GPLv3**, así que el
paquete que se distribuye va bajo GPLv3. Eso **no impide venderlo** —es lo que
hacen Yoast, WooCommerce y todos los plugins comerciales de WordPress—, pero sí
impide llamarlo propietario, que es lo que decía el `readme.txt`. Si algún día
se prefiere no distribuir bajo GPL, la alternativa es no empaquetarla y declarar
`Requires Plugins: action-scheduler` en la cabecera, que hace que WordPress 6.6
ofrezca instalarla desde el panel; son dos instalaciones en vez de una.

**La autocarga de Composer deja de ser obligatoria.** Si `vendor/autoload.php`
no está, se registra una PSR-4 propia de quince líneas. Antes el plugin se
negaba a arrancar y pedía ejecutar `composer install`, que es justo lo que no se
puede pedir aquí. Una copia del repositorio arranca tal cual; lo único que le
falta es Action Scheduler.

**Instalar y actualizar son dos caminos, y los dos tienen que montar lo mismo.**
`register_activation_hook()` **no se dispara al actualizar** desde el panel:
WordPress no desactiva y vuelve a activar. Sin nada más, una versión con un
esquema nuevo se instalaría sobre las tablas viejas y se quedaría así.
`Bootstrap\Installer` es el único sitio que sabe qué significa instalar —tablas,
rol, capacidades— y lo llaman el activador y `Bootstrap\Upgrader`, que compara la
versión guardada en cada carga del escritorio. Si alguna tabla no se puede crear
se avisa con un aviso de error: el plugin activándose, pareciendo que todo va
bien y luego no guardando ninguna traducción es el fallo más desconcertante que
puede tener una instalación, y casi siempre es que el usuario de la base de
datos no puede crear tablas.

**El paquete se construye con una lista de lo que entra, no de lo que se
excluye** (`tools/build-zip.sh`). Se parte de `git archive HEAD`, se compilan los
recursos del editor —que no se versionan—, se instalan solo las dependencias de
producción y se comprueba que estén el cargador, Action Scheduler, `editor.js` y
el `.pot` antes de cerrar el zip. Con una lista de exclusiones, cualquier archivo
de desarrollo nuevo se colaría en la siguiente versión sin que nadie se enterara.

---

## 4. Estructura del repositorio

```
polyglot-ai.php          Cabecera del plugin y arranque
uninstall.php
composer.json  package.json  phpcs.xml.dist  phpstan.neon.dist
phpunit.xml.dist  playwright.config.js  .wp-env.json
src/
  Plugin.php             Contenedor y registro de servicios
  Bootstrap/             Installer, Activator, Deactivator, Upgrader, Requirements
  Database/              Schema, Migrator, repositorios
  Languages/             Registro de idiomas, variantes, RTL
  Routing/               UrlConverter, RequestRouter, SlugResolver, SlugSync,
                         PermalinkTranslator, LinkRewriter, InternalUrl, HeadTags
  Html/                  Drivers, extractor, sustituidor, exclusiones, BailConditions
  Translation/           Dictionary, Normalizer, Hasher, Validator, StatusPrecedence,
                         Memory, MissingQueue, TranslationLookup
  Engines/               Interfaces + Claude/{ClaudeEngine,ClaudeClient,PromptBuilder,
                         ResponseSchema,ResponseParser,RetryPolicy,Batches}
  Seo/                   HeadUrls, StructuredData, Sitemaps, TranslatedUrls,
                         TranslatedSitemapProvider, StandaloneSitemap
  Switcher/              SwitcherRenderer, Shortcode, Block, NavMenu,
                         MenuLocations, FloatingSwitcher
  Detection/             BotDetector, BrowserLanguage, VisitorRedirect
  Content/               Conditional (shortcodes por idioma)
  Admin/                 SettingsPage, StringsPage, GlossaryPage, StatsPage,
                         ImportExport, TranslatorProfile, TranslatorAccess
  Gettext/  Editor/  Rest/
  Compat/                WooCommerce, Forms, Cache, Builders, SeoPlugins
  Jobs/                  PendingTranslator, SlugTranslator, SiteTranslator,
                         SiteRun, Budget, ContextFactory
  Licensing/             License, LicenseServer, UpdateChecker
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
bash tests/e2e/install.sh  # WordPress servido con el plugin activo, sin Docker
npm run test:e2e           # Playwright (enrutado, selector, sitemaps)

# Build
bash tools/build-zip.sh    # zip instalable desde el panel, en dist/
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
| 4. SEO Pack | Completa |
| 5. Selector, navegación y detección | Completa |
| 6. Roles, gestor de cadenas, glosario y estadísticas | Completa |
| 7. Traducción de sitio completo | Completa |
| 8. Compatibilidad, rendimiento, seguridad y documentación | Completa, salvo las pruebas con constructores de pago (decisión nº 6) |

De la fase 8 están hechos:

- **Los sitemaps de los cuatro plugins de SEO** (ADR-16), que era lo que quedaba
  de la fase 4.
- **`Compat\SeoPlugins`, `Compat\PrivacyExclusions`, `Compat\CachePlugins` y
  `Compat\WooCommerce`** (el idioma del pedido, que venía pendiente de la fase 3).
- **El empalme en tiempo lineal** (ADR-08): el coste por KB ha dejado de crecer
  con el tamaño de la página.
- **Tres agujeros de privacidad** que el ADR-12 daba por cerrados y no lo
  estaban: las páginas personales por slug traducido, las respuestas a un POST,
  el contenido de los `<textarea>` y las vistas previas de borradores (ADR-04).
- **Las pruebas de extremo a extremo** con Playwright, sobre un WordPress servido
  de verdad y sin necesitar Docker, y en CI. Encontraron dos fallos que ninguna
  otra suite podía ver: el prefijo de idioma no se quitaba de `PATH_INFO`, de
  modo que ninguna URL traducida funcionaba en los servidores que lo rellenan, y
  el sitemap por idioma no llevaba ninguna página por filtrar por
  `publicly_queryable`.
- **El mecanismo de actualización y licencias** con servidor propio (ADR-20), que
  era la decisión pendiente nº 5.
- **El `readme.txt` y el `README.md`** puestos al día.

Queda de la fase 8, y las dos cosas por motivos que no son de código:

- **Las pruebas con los constructores de pago.** Divi, Elementor Pro, Bricks y
  WPBakery hacen falta instalados para comprobar sus parámetros de modo edición,
  que ahora mismo están puestos por lo que documentan y no por haberlos visto
  (ADR-04). Es la decisión pendiente nº 6 y se resuelve comprando licencias, no
  escribiendo código.
- **Seguir perfilando el barrido.** El objetivo de < 50 ms se cumple hasta unos
  190 KB de HTML, no en las páginas más grandes de constructor. Lo que queda por
  ganar está repartido por nuestra propia lógica —pila de elementos, exclusiones,
  atributos— sin ningún punto caliente que destaque, así que es un trabajo de
  perfilado fino y no un arreglo puntual.

Un punto del encargo que caía en la fase 3 sigue pendiente, y otro ya está
resuelto:

- ~~**Editar desde el panel las cadenas de gettext que nunca llegan a una
  página.**~~ Resuelto en la fase 6: se registran en cuanto se llama a `__()`,
  aunque su texto no acabe en el HTML, y el gestor de cadenas las encuentra
  filtrando por tipo «Cadena del tema o de un plugin».
- ~~**Idioma del pedido.**~~ Resuelto en la fase 8: `Compat\WooCommerce` anota el
  idioma en el propio pedido al comprarlo y lo devuelve por
  `pgai_recipient_language` al enviar sus avisos. Hacía falta porque la mayoría
  de los pedidos los hacen invitados, que no tienen usuario ni preferencia
  guardada, así que sus correos salían todos en el idioma por defecto del sitio.
- **Respuestas de `admin-ajax.php`.** El observador de mutaciones ya traduce el
  resultado visible de esas respuestas. Queda por ver si algún caso de
  WooCommerce necesita algo más.

Y el de la fase 4 ya está resuelto:

- ~~**Los sitemaps de Yoast, Rank Math, SEOPress y All in One SEO.**~~ Resuelto
  en la fase 8 con los cuatro plugins instalados, que es lo que faltaba para
  poder comprobarlo en vez de suponerlo. Resultó que los cuatro apagan el
  sitemap del núcleo con el mismo filtro y que los cuatro admiten entradas
  externas en su índice, así que la integración es una lista de URLs
  compartida y cuatro adaptadores de formato (ADR-16).

## 7. Decisiones confirmadas y pendientes

**Confirmadas** (2026-09-17), ya incorporadas arriba:

1. Mínimo de WordPress **6.6+** → ADR-01.
2. Detección del visitante: **solo por idioma del navegador** (`Accept-Language`) en la
   v1. Nada de MaxMind ni de descarga de bases de datos. `Detection\ProviderInterface`
   se deja preparada para añadir GeoIP más adelante sin tocar el resto.
3. Distribución **comercial/privada** → ADR-14.
4. Traducción en tiempo real **activada, en segundo plano** → ADR-13.
5. Mecanismo de actualización y licencias: **servidor propio** → ADR-20. El contrato
   con ese servidor está en `docs/licensing.md`; el servidor en sí es trabajo aparte y
   no vive en este repositorio.

**Pendiente**:

6. **Licencias para pruebas de compatibilidad** (Fase 8): Divi y Elementor Pro son de
   pago y hacen falta en `wp-env` para las pruebas E2E. Con las versiones gratuitas se
   cubren Elementor, Gutenberg, Astra y GeneratePress; Divi y Beaver/Bricks no.
