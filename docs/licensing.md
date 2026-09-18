# Licencias y actualizaciones

El plugin no se distribuye por WordPress.org (ADR-14), así que las
actualizaciones las sirve un servidor nuestro. Este documento es el contrato
entre el plugin y ese servidor: lo que hay aquí es lo que el servidor tiene que
implementar, ni más ni menos.

El plugin **no incluye el servidor**. Aquí está solo el cliente.

## Puesta en marcha

| Constante | Dónde | Para qué |
|---|---|---|
| `PGAI_UPDATE_SERVER` | Se fija al empaquetar, en `polyglot-ai.php` o en `wp-config.php` | Dirección del servidor, con `https://`. **Si no está definida no se comprueba nada**: el plugin funciona igual y simplemente no se actualiza solo. Es lo que queremos en desarrollo. |
| `PGAI_LICENSE_KEY` | `wp-config.php` del cliente | Fija la clave y quita el campo del panel. Para instalaciones gestionadas. |

Sin `PGAI_UPDATE_SERVER`, o con una dirección que no empiece por `https://`, el
cliente se queda callado.

## La llamada

Una sola, `POST`, contra:

```
{PGAI_UPDATE_SERVER}/wp-json/pgai/v1/status
```

Cuerpo, JSON:

```json
{
  "license": "PGAI-XXXX-XXXX-XXXX",
  "site":    "https://sitio-del-cliente.example/",
  "slug":    "polyglot-ai",
  "version": "0.1.0"
}
```

**No se llama sin clave.** Un sitio que no tiene licencia configurada no manda
nada: no tiene por qué anunciar su dirección a ninguna parte.

La respuesta se guarda **doce horas**, y **una hora** cuando la llamada falla.
WordPress mira si hay actualizaciones muchas veces por sesión de escritorio y
cada una no puede ser una petición de red.

## La respuesta

```json
{
  "license": {
    "status":  "valid",
    "expires": "2027-01-01"
  },
  "update": {
    "version":      "0.2.0",
    "package":      "https://servidor-de-licencias.example/descargas/polyglot-ai-0.2.0.zip",
    "requires":     "6.6",
    "requires_php": "8.1",
    "tested":       "6.7",
    "last_updated": "2026-10-01",
    "sections":     { "changelog": "<p>…</p>", "description": "<p>…</p>" }
  }
}
```

- `license.status`: `valid`, `invalid` o `expired`. Cualquier otra cosa se toma
  como «todavía no se sabe».
- `update`: **se omite o se pone a `null`** cuando no hay versión nueva o cuando
  la licencia no da derecho a ella. Quién puede descargar lo decide el servidor,
  no el cliente.
- `sections` pasa por `wp_kses_post()` antes de enseñarse.

## Lo que el cliente rechaza

Esta parte importa más que el resto del documento. WordPress **instala sin
rechistar** el zip que diga `package`, de modo que una respuesta manipulada —un
DNS envenenado, un proxy de por medio, el propio servidor comprometido— podría
hacer que el sitio del cliente instalara cualquier cosa bajo el nombre de este
plugin. El actualizador del núcleo no verifica firmas de plugins de terceros, así
que esta comprobación es la única defensa real que hay:

1. `package` tiene que empezar por `https://`.
2. El host de `package` tiene que ser **el mismo** que el de
   `PGAI_UPDATE_SERVER`.
3. `version` tiene que ser mayor que la instalada.

Si algo de esto falla **se descarta la actualización entera**, no se recorta la
parte sospechosa: una respuesta que ya se sabe que no es de quien dice ser no
sirve para nada.

En la práctica esto significa que **las descargas se sirven desde el mismo host
que la API**. Si algún día hay que moverlas a un CDN, hay que cambiar la regla
aquí y decir por qué.

## Lo que hace el servidor

Lo mínimo:

1. Buscar la clave. Si no existe → `status: "invalid"`, sin `update`.
2. Comprobar la caducidad. Si pasó → `status: "expired"`, sin `update`.
3. Anotar el `site` contra la clave, para el límite de instalaciones.
4. Comparar `version` con la última publicada y devolver `update` si hay una más
   nueva.
5. Servir el zip en una URL del mismo host, con el token de descarga que se
   quiera dentro de la query.

## Qué pasa cuando el servidor no está

Nada. No se ofrece actualización, no se enseña ningún error y se vuelve a
intentar más tarde. Cinco segundos de espera como mucho. Un servidor de
licencias caído no puede dejar a nadie sin poder administrar su sitio, y un
plugin ya instalado sigue funcionando igual: la licencia da derecho a
actualizaciones, no permiso para ejecutarse.
