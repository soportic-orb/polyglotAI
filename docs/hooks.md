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

### `pgai_is_bot`

Afina la detección de tráfico automatizado. Devolver `true` impide que esa
petición encole cadenas nuevas para traducir.

```php
add_filter( 'pgai_is_bot', function ( bool $is_bot, string $agent ): bool {
    return $is_bot || str_contains( $agent, 'mi-rastreador-interno' );
}, 10, 2 );
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
