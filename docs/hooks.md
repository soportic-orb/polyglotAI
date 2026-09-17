# Hooks, filtros y funciones públicas

Todo lo documentado aquí es API pública: se mantiene entre versiones menores.
Cada hook nuevo se documenta en el mismo commit que lo introduce.

## Filtros

### `pgai_should_process_output`

Decide si se traduce la salida de la petición en curso. Es el primer sitio donde
mirar cuando un tema o un constructor se rompe al activar el plugin.

```php
add_filter( 'pgai_should_process_output', function ( bool $process ): bool {
    if ( is_singular( 'mi_cpt_privado' ) ) {
        return false;
    }

    return $process;
} );
```

| Parámetro | Tipo | Descripción |
|---|---|---|
| `$process` | `bool` | Si la salida se procesa. |

### `pgai_claude_request_body`

Ajusta el cuerpo de la petición antes de enviarla a la API. Útil para cambiar el
modelo por idioma o para añadir instrucciones propias.

```php
add_filter( 'pgai_claude_request_body', function ( array $body, array $requests, $context ): array {
    if ( 'de_DE' === $context->target_locale ) {
        $body['output_config']['effort'] = 'medium';
    }

    return $body;
}, 10, 3 );
```

| Parámetro | Tipo | Descripción |
|---|---|---|
| `$body` | `array` | Cuerpo de la petición. |
| `$requests` | `TranslationRequest[]` | Cadenas del lote. |
| `$context` | `EngineContext` | Contexto lingüístico. |

### `pgai_engine_batch_size`

Número de cadenas por llamada. Por defecto 40.

### `pgai_exclusion_classes`

Clases CSS que excluyen un elemento y todo su contenido. Por defecto
`['notranslate']`.

### `pgai_allowed_html`

Etiquetas y atributos admitidos en una traducción escrita a mano. La lista por
defecto es corta a propósito: en una traducción solo caben marcas de estilo en
línea, enlaces e imágenes. No se usa `wp_kses_post`, que admite bastante más de
lo que un traductor necesita.

```php
add_filter( 'pgai_allowed_html', function ( array $allowed ): array {
    $allowed['abbr'] = array( 'title' => true );

    return $allowed;
} );
```

### `pgai_skip_gettext_domain`

Excluye de la traducción un dominio de gettext entero.

```php
add_filter( 'pgai_skip_gettext_domain', function ( bool $skip, string $domain ): bool {
    return $skip || 'mi-plugin-interno' === $domain;
}, 10, 2 );
```

### `pgai_record_string`

Decide si una cadena encontrada en el HTML se anota como pendiente. Lo usa la
captura de gettext para que una frase ya traducida por esa vía no aparezca dos
veces en el gestor.

### `pgai_allow_dynamic_translation`

Permite cerrar a los visitantes el endpoint de contenido dinámico. Devolver
`false` lo desactiva.

### `pgai_recipient_language`

Decide el idioma de un correo. Es el punto por el que una integración aporta el
idioma de un pedido, que puede no corresponder a ningún usuario registrado.

```php
add_filter( 'pgai_recipient_language', function ( $language, string $email, $to ) {
    $order = wc_get_order( /* … */ );

    return $order ? pgai_language( $order->get_meta( '_pgai_language' ) ) : $language;
}, 10, 3 );
```

### `pgai_is_bot`

Afina la detección de tráfico automatizado. Devolver `true` impide que esa
petición encole cadenas nuevas para traducir.

```php
add_filter( 'pgai_is_bot', function ( bool $is_bot, string $agent ): bool {
    return $is_bot || str_contains( $agent, 'mi-rastreador-interno' );
}, 10, 2 );
```

### `pgai_purge_page_cache`

Si se vacía la caché de página cuando cambia una traducción o un slug. Activado
por defecto: sin él, la traducción que acaba de guardarse no la ve nadie hasta
que caduca la copia guardada.

```php
add_filter( 'pgai_purge_page_cache', '__return_false' );
```

## Acciones

| Acción | Argumentos | Cuándo se dispara |
|---|---|---|
| `pgai_translation_saved` | `int $source_id, string $language, Status $status` | Tras guardar una traducción. |
| `pgai_translation_failed` | `int $source_id, string $language, string $reason` | Cuando una traducción no supera la validación estructural. |
| `pgai_engine_retry` | `int $attempt, int $delay, EngineException $failure` | Antes de esperar para reintentar una llamada a la API. |
| `pgai_register_engines` | `EngineRegistry $registry` | Para registrar motores de traducción propios. |
| `pgai_document_processing_failed` | `Throwable $error` | La traducción de una página ha fallado y se sirve el original. |
| `pgai_document_safety_check_failed` | `string $driver` | La comprobación de integridad ha rechazado el resultado. |
| `pgai_no_driver_available` | — | No hay driver de análisis viable; el plugin deja de traducir. |
| `pgai_budget_exhausted` | `string $language` | El tope mensual de tokens ha detenido la traducción. |
| `pgai_translated_mail` | `array $mail, string $language` | Tras traducir un correo saliente. |
| `pgai_slugs_changed` | — | Ha cambiado algún slug traducido, así que las URLs ya no son las mismas. |
| `pgai_page_cache_purged` | — | Tras pedir el vaciado de la caché de página. Es donde enganchar la caché de un alojamiento, un CDN o un proxy inverso. |

## Endpoints REST

Espacio de nombres `pgai/v1`. Todos exigen un nonce `wp_rest` y una capacidad
concreta; ninguno usa `__return_true` como comprobación de permiso.

### `GET /wp-json/pgai/v1/strings`

Devuelve cadenas con su original, su traducción y su estado.
Capacidad: `pgai_translate`.

| Parámetro | Tipo | Descripción |
|---|---|---|
| `language` | string | Locale de destino. Obligatorio. |
| `hashes[]` | string[] | Hashes a recuperar. Máximo 500 por petición. |

### `POST /wp-json/pgai/v1/strings`

Guarda traducciones escritas a mano. Capacidad: `pgai_translate`; marcar como
revisada exige además `pgai_review`.

```json
{
  "language": "en_US",
  "translations": [
    { "hash": "…", "translation": "Add to cart", "status": "manual" }
  ]
}
```

La respuesta separa lo guardado de lo rechazado. Una traducción se rechaza si no
conserva la estructura del original: se aplica la misma validación que a lo que
devuelve el motor, porque una persona también puede perder una etiqueta sin
querer.

```json
{
  "saved": { "…": { "translation": "Add to cart", "status": "manual" } },
  "rejected": { "…": "tag_count_mismatch" }
}
```

### `POST /wp-json/pgai/v1/suggest`

Traduce cadenas con el motor automático. Capacidad: **`pgai_run_auto_translate`**,
no `pgai_translate`: esto gasta presupuesto de API.

| Parámetro | Tipo | Descripción |
|---|---|---|
| `language` | string | Locale de destino. Obligatorio. |
| `hashes[]` | string[] | Hashes a traducir. Máximo 60 por petición. |
| `save` | bool | Si además se guardan. Por defecto `false`: la sugerencia solo rellena el campo hasta que el traductor la acepta. |

Con `save` activo, el guardado respeta la precedencia de estados: una sugerencia
automática no pisa una corrección manual ni aunque se pulse «traducir todo».

Devuelve `429` si se ha alcanzado el tope mensual de tokens, `502` si el motor
falla y `503` si el fallo admite reintento.

### `POST /wp-json/pgai/v1/merges` y `DELETE /wp-json/pgai/v1/merges`

Crea y deshace bloques de traducción fusionados. Capacidad: `pgai_translate`.
Una fusión cambia cómo se trocea la página para todos los idiomas, así que no
recibe un idioma.

### `GET /wp-json/pgai/v1/site` y `POST /wp-json/pgai/v1/site`

Traducción de sitio completo. Capacidad: `pgai_run_auto_translate`, **no**
`pgai_translate`: esto gasta dinero, y mucho de golpe.

`GET` devuelve `supported` —si el motor configurado admite lotes asíncronos— y
la pasada en curso, si la hay, con su estado, el progreso y los recuentos.

`POST` acepta `command`:

| `command` | Qué hace |
|---|---|
| `start` | Arranca con lo pendiente de ese idioma. Devuelve 400 si no queda nada. |
| `pause` | Deja de enviar y de preguntar. El lote en vuelo sigue en el proveedor y se cobra igual. |
| `resume` | Vuelve a esperar por el mismo lote, sin reenviarlo. |
| `cancel` | Cancela el lote en vuelo y olvida la pasada. |

### `GET /wp-json/pgai/v1/site/estimate`

Cuántas cadenas quedan pendientes en un idioma y cuántos tokens de entrada
costaría traducirlas. Capacidad: `pgai_run_auto_translate`.

Va en su propio endpoint porque **cuesta una llamada a la API**: mezclarla con
el sondeo del progreso sería pagarla cada quince segundos por cada pestaña
abierta. La cuenta la hace la propia API, no una regla casera de caracteres por
token, y se estima sobre un trozo que se multiplica por los que harían falta.

### `GET /wp-json/pgai/v1/manager`

Busca entre **todas** las cadenas del sitio, no solo las de una página.
Capacidad: `pgai_translate`.

| Parámetro | Por defecto | Qué es |
|---|---|---|
| `language` | — | Locale. Obligatorio. |
| `search` | `''` | Texto a buscar en el original, la traducción y el contexto. |
| `status` | todos | `pending`, `error`, `automatic`, `reviewed` o `manual`. |
| `type` | todos | `text`, `block`, `attribute`, `rcdata`, `meta`, `slug`, `image`, `gettext`. |
| `page` | 1 | Página. |
| `per_page` | 50 | Cadenas por página, máximo 200. |

Filtrar por `pending` incluye las cadenas que aún no tienen fila de traducción.

### `POST /wp-json/pgai/v1/manager/bulk`

Acción sobre varias cadenas a la vez. Capacidad: `pgai_translate`, y además
`pgai_review` para `review`.

| `action` | Qué hace |
|---|---|
| `review` | Pasa a «revisada» lo que sea automático. No toca lo pendiente ni lo manual. |
| `retranslate` | Devuelve a pendiente lo automático y lo que quedó en error, para que el trabajo en segundo plano lo vuelva a traducir. **Nunca** toca lo revisado ni lo manual. |
| `delete` | Borra la traducción en ese idioma. La cadena original se conserva. |

### `GET /wp-json/pgai/v1/slugs`

Slugs traducibles de una página: el de la entrada, los de sus ascendientes y los
de los términos y bases reescritas que aparezcan en su URL. Capacidad:
`pgai_translate`.

| Parámetro | Obligatorio | Qué es |
|---|---|---|
| `language` | sí | Locale, p. ej. `en_US`. |
| `url` | sí | URL de la página. Puede llevar ya los slugs traducidos: es la que el traductor está viendo. |

### `POST /wp-json/pgai/v1/slugs`

Guarda un slug traducido. Capacidad: `pgai_translate`.

| Parámetro | Obligatorio | Qué es |
|---|---|---|
| `language` | sí | Locale. |
| `object_type` | sí | `post`, `term` o `base`. |
| `object_subtype` | sí | Tipo de contenido, taxonomía o base. |
| `object_id` | no | Identificador. `0` en las bases. |
| `translated_slug` | sí | Slug, que se normaliza con `sanitize_title()`. |

Lo guardado queda en estado `manual`, de modo que ninguna traducción automática
volverá a tocarlo. El slug devuelto puede no ser el pedido: si otro objeto ya
usaba ese slug en ese idioma, se desambigua con un sufijo numérico para que el
enrutado inverso siga siendo inequívoco.

### `POST /wp-json/pgai/v1/dynamic`

Devuelve traducciones ya existentes para textos que aparecen en la página
después de cargarla.

Es el **único endpoint abierto a visitantes no identificados**, y lo es porque
tiene que funcionar para cualquiera que navegue el sitio. Es de solo lectura, no
llama a ninguna API y solo devuelve traducciones que ya se muestran
públicamente. Se puede cerrar con el filtro `pgai_allow_dynamic_translation`.

### `pgai_detected_language`

Filtra el idioma al que se redirige a un visitante nuevo cuando la detección por
navegador está activada. Devolver `null` cancela la redirección.

```php
// No redirigir nunca desde la portada.
add_filter( 'pgai_detected_language', function ( $language ) {
	return is_front_page() ? null : $language;
} );
```

| Argumento | Qué es |
|---|---|
| `$language` | `PolyglotAI\Languages\Language` detectado, o `null`. |
| `$header` | Cabecera `Accept-Language` recibida. |

## Shortcodes

### `[pgai_language_switcher]`

Selector de idioma.

| Atributo | Valores | Qué hace |
|---|---|---|
| `display` | `name`, `code`, `both`, `flag`, `flag_name` | Qué se ve en cada enlace. |
| `layout` | `list`, `inline`, `dropdown` | Clase CSS del contenedor. |
| `hide_current` | `yes`, `no` | Oculta el idioma en curso. |
| `class` | | Clases extra, saneadas. |

### `[pgai_if]` y `[pgai_unless]`

Muestran u ocultan contenido según el idioma. Aceptan tanto el slug de la URL
(`en`) como el locale (`en_US`).

```
[pgai_if lang="en,ca"]Solo en inglés y catalán[/pgai_if]
[pgai_unless lang="es"]En todos menos en español[/pgai_unless]
```

Lo que no se muestra **no llega a la salida**: no se registra como cadena, no se
encola y no se paga por traducirlo.

Con `translate="no"` el contenido se envuelve en un contenedor que el barrido
salta. Es para el texto que ya está escrito en el idioma al que se condiciona.

**Para anidar una condición dentro de otra hay que alternar los dos nombres.**
WordPress no sabe anidar dos shortcodes con el mismo nombre: su expresión
regular cierra en el primer `[/...]` que encuentra.

### `[pgai_language]`

Escribe el idioma en curso. `display` acepta `name`, `code`, `locale` y `slug`.

## Funciones públicas

| Función | Devuelve |
|---|---|
| `pgai_current_language()` | Locale de la petición en curso, p. ej. `en_US`. |
| `pgai_is_default_language()` | Si se sirve en el idioma original del sitio. |
| `pgai_languages()` | Idiomas visibles del sitio. |
| `pgai_translate( $text, $locale = null )` | Traducción de un texto suelto; anota la cadena si aún no existe. |
| `pgai_with_language( $locale, $callback )` | Ejecuta un bloque como si la petición fuese de otro idioma. |

`pgai_with_language()` es la vía para generar contenido en un idioma concreto —el
correo de un pedido en el idioma del cliente, por ejemplo—. Dentro del bloque,
las cadenas de gettext y `pgai_current_language()` responden en ese idioma, y al
salir todo vuelve a como estaba, también si el bloque lanza una excepción.

```php
pgai_with_language( 'en_US', function () use ( $order ) {
    return wc_get_template_html( 'emails/customer-completed-order.php', array( 'order' => $order ) );
} );
```

## Mensajes entre el editor y la vista previa

El panel del editor y el iframe se comunican con `postMessage`, siempre
comprobando el origen.

| Origen | Tipo | Datos |
|---|---|---|
| vista previa | `ready` | `strings`, `language`, `url`, `path` |
| vista previa | `select` | `hash` |
| vista previa | `navigate` | `url` |
| editor | `update` | `hash`, `translation`, `status` |
| editor | `highlight` | `hash` |

## Exclusiones en el marcado

Sin escribir una línea de PHP:

| Marca | Efecto |
|---|---|
| `class="notranslate"` | El elemento y su contenido no se traducen. |
| `translate="no"` | Igual, usando el atributo estándar de HTML. |
| `data-no-translation` | Igual. |
| `data-pgai-skip` | Igual, marca propia del plugin. |

También quedan fuera por defecto `<script>`, `<style>`, `<code>`, `<pre>`,
`<template>`, `<svg>`, `<math>`, `<canvas>` y `<object>`.

## Caché de página

Al guardar una traducción o cambiar un slug se le pide al plugin de caché que
vacíe lo que tiene guardado, **una sola vez por petición** —una traducción de
sitio completo guarda miles de cadenas de golpe— y **entero**, porque una misma
cadena puede salir en cualquier página.

Se reconocen WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, Cache
Enabler, WP Fastest Cache y SiteGround Optimizer. Para cualquier otra caché
—la de un alojamiento, un CDN, un proxy inverso— está la acción
`pgai_page_cache_purged`.

## Privacidad

Estas páginas no se traducen nunca y su contenido no llega a la API (ADR-12):

| Qué | Por qué |
|---|---|
| Carrito, pago, cuenta y endpoints de WooCommerce | Se le pregunta a WooCommerce, no a la URL: así acierta con el slug traducido de cada idioma. |
| Cualquier respuesta a una petición POST | Está construida con lo que acaba de enviar el visitante. |
| El contenido de los `<textarea>` | Suele ser lo que ha escrito el visitante. Su texto de interfaz va en `placeholder`, que sí se traduce. |
| Las rutas de `excluded_paths` en los ajustes | Red para los sitios sin WooCommerce. |

### `pgai_translate_textarea`

Activa la traducción del contenido de los `<textarea>`. Desactivado por
defecto. Solo tiene sentido en sitios cuyos textareas llevan texto estático de
la interfaz y nunca datos escritos por el visitante.

```php
add_filter( 'pgai_translate_textarea', '__return_true' );
```

## Sitemaps

Las URLs traducidas se publican siempre, esté quien esté al mando del sitemap.

| Situación | Dónde salen |
|---|---|
| Sitemap del núcleo en pie | Dentro de él: `/wp-sitemap-pgai-<idioma>-<n>.xml`. |
| Un plugin de SEO lo ha apagado | En rutas propias, enlazadas desde el índice de ese plugin. |

Rutas propias:

| Ruta | Contenido |
|---|---|
| `/pgai-sitemap.xml` | Índice de los sitemaps por idioma. También se anuncia en `robots.txt`. |
| `/pgai-sitemap-<idioma>-<n>.xml` | URLs de las entradas de ese idioma. |
| `/pgai-sitemap-<idioma>-tax-<n>.xml` | URLs de los términos de ese idioma. |

Solo responden cuando el sitemap del núcleo está apagado; si sigue en pie, esas
mismas URLs ya están dentro de él y estas rutas no existen.

Filtros ajenos a los que nos enganchamos para entrar en el índice del plugin de
SEO activo (no hace falta configurar nada: los cuatro se registran siempre y
solo se dispara el del plugin que haya):

| Plugin | Filtro |
|---|---|
| Yoast SEO | `wpseo_sitemap_index_links` |
| Rank Math | `rank_math/sitemap/index` |
| SEOPress | `seopress_sitemaps_external_link` |
| All in One SEO | `aioseo_sitemap_indexes` |

## Capacidades

| Capacidad | Permite |
|---|---|
| `pgai_translate` | Editar traducciones en el editor visual. |
| `pgai_review` | Marcar cadenas como revisadas. |
| `pgai_run_auto_translate` | Lanzar traducción automática (gasta presupuesto de API). |
| `pgai_manage_languages` | Añadir, quitar y activar idiomas. |
| `pgai_manage_settings` | Ajustes generales, clave de API y límites. |

El rol `pgai_translator` («Traductor») recibe `read`, `pgai_translate` y
`pgai_review`.

## Shortcodes

### `[pgai_language_switcher]`

| Atributo | Valores | Por defecto |
|---|---|---|
| `display` | `name`, `code`, `both` | `name` |
| `hide_current` | `yes`, `no` | `no` |

## Constantes

| Constante | Descripción |
|---|---|
| `PGAI_API_KEY` | Clave de la API. Definirla en `wp-config.php` es la vía recomendada: así la clave no se guarda en la base de datos ni viaja en las copias de seguridad. |
