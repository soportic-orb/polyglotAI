=== Polyglot AI ===
Contributors: soportic
Tags: multilingual, translate, translation, multilanguage, seo
Requires at least: 6.6
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.1.0
License: Proprietary

Traduce tu sitio entero con inteligencia artificial, con URLs por idioma y corrección manual que la traducción automática no pisa nunca.

== Description ==

Polyglot AI traduce el HTML ya renderizado de cada página, así que funciona con
cualquier tema, constructor o plugin sin integraciones específicas.

Lo que no es texto no se toca: el documento no se vuelve a serializar, solo se
sustituyen los intervalos que cambian. El doctype, el JavaScript, el CSS, los
datos estructurados y la codificación salen idénticos. Si algo no cuadra al
terminar, se sirve la página original sin traducir en lugar de arriesgarse a
servirla rota.

= Idiomas y URLs =

* Idiomas ilimitados, con variantes regionales y soporte RTL.
* Una URL por idioma en subdirectorio (`/en/`), con los enlaces internos
  reescritos y los slugs traducidos.
* Redirección 301 del slug sin traducir al traducido.
* Detección del idioma del navegador, desactivada por defecto y con selector
  siempre visible.

= Traducción =

* Motor de inteligencia artificial (Anthropic) con salida estructurada y
  validación posterior: si la traducción pierde una etiqueta, un shortcode, un
  marcador como `%s` o una URL, se descarta y se sirve el original.
* Traducción del sitio completo por lotes asíncronos, con estimación de coste
  previa, progreso visible y posibilidad de pausar y reanudar.
* Traducción en segundo plano de lo que aparece nuevo, sin bloquear nunca la
  carga de una página.
* Memoria de traducción: una frase ya traducida no se vuelve a pagar.
* Glosario de traducciones obligatorias y lista de términos que no se traducen.
* Tope mensual de tokens y filtro de robots para que un rastreo no dispare la
  factura.

= Corrección y equipo =

* Editor visual: se hace clic en cualquier texto de la página y se corrige.
* Gestor de cadenas con búsqueda, filtros por estado y edición en masa.
* Importación y exportación en CSV.
* Rol de traductor con capacidades propias, asignable por idioma y sin acceso al
  escritorio.
* **Las correcciones manuales no se sobrescriben nunca**, ni al retraducir, ni
  al cambiar de modelo, ni al reimportar.

= SEO =

* `hreflang`, `x-default` y atributo `lang` correctos.
* Título, meta descripción, Open Graph, Twitter Cards y datos estructurados
  traducidos, y URLs canónicas con su prefijo de idioma.
* Funciona con Yoast SEO, Rank Math, SEOPress y All in One SEO sin configurar
  nada: el texto que emiten se traduce como cualquier otro del sitio.
* Un sitemap por idioma, dentro del sitemap de WordPress o dentro del índice del
  plugin de SEO que esté activo.

= Selector de idioma =

Shortcode, bloque de Gutenberg, elemento de menú y selector flotante. Todos
salen del mismo sitio, así que todos enlazan a la misma URL correcta de cada
página.

== Privacidad ==

El plugin envía a la API de Anthropic el texto de las páginas que hay que
traducir. Conviene decírselo a los visitantes en la política de privacidad del
sitio.

Lo que **nunca** se envía:

* Las páginas de carrito, pago, cuenta y pedidos. Se le preguntan a WooCommerce,
  así que la exclusión acierta con el slug traducido de cada idioma y no depende
  de que las páginas se llamen de una forma concreta.
* Las respuestas a cualquier formulario enviado por POST, que están construidas
  con lo que acaba de escribir el visitante.
* El contenido de los `<textarea>`, que suele ser lo que ha escrito el
  visitante.
* Las vistas previas de borradores: el contenido sin publicar no sale del sitio.
* Cualquier ruta que se añada a la lista de exclusiones de los ajustes.

Tampoco se llama a la API durante la visita de nadie: la traducción se genera en
segundo plano o desde el panel, y mientras tanto se sirve el texto original.

== Installation ==

1. Copia el plugin en `wp-content/plugins/polyglot-ai`.
2. Ejecuta `composer install --no-dev` en la carpeta del plugin.
3. Actívalo.
4. Añade `define( 'PGAI_API_KEY', 'sk-ant-...' );` a `wp-config.php`.
5. Configura los idiomas en Polyglot AI → Ajustes.

La clave se puede guardar también desde los ajustes; en ese caso se cifra con
las claves de seguridad del sitio y nunca se devuelve al navegador.

== Frequently Asked Questions ==

= ¿Se pierden mis correcciones manuales al retraducir? =

No. Una traducción marcada como manual o revisada no la sobrescribe ningún
proceso automático, en ninguna de las rutas de escritura del plugin.

= ¿Qué pasa si desactivo el plugin? =

El sitio vuelve a su estado original. No se borra nada. Al desinstalarlo solo se
borran las tablas y los ajustes si lo has marcado antes en los ajustes.

= ¿Funciona con plugins de caché? =

Sí. Cada idioma tiene su propia URL y se cachea por separado, y al guardar una
traducción o cambiar un slug se le pide al plugin de caché que vacíe lo que
tiene guardado, para que el cambio se vea enseguida. Se reconocen WP Rocket, W3
Total Cache, WP Super Cache, LiteSpeed Cache, Cache Enabler, WP Fastest Cache y
SiteGround Optimizer; para cualquier otra hay una acción donde engancharse.

= ¿Cuánto ralentiza la página? =

El coste crece de forma lineal con el tamaño de la página, no más deprisa. En la
máquina de desarrollo son unos 0,27 ms por KB de HTML: unos 34 ms en una página
de 128 KB y unos 69 ms en una de 254 KB. En tu servidor la cifra será otra, pero
la proporción se mantiene. Detrás de una caché de página ese coste se paga una
vez por copia guardada, no en cada visita.

= ¿Se puede probar antes de gastar? =

Sí. La traducción del sitio completo estima el coste en tokens antes de enviar
nada, y los ajustes tienen un tope mensual que corta tanto las llamadas como el
encolado.

== Changelog ==

= 0.1.0 =
* Primera versión: traducción del HTML renderizado, enrutado por idioma con
  slugs traducidos, motor de inteligencia artificial con validación
  estructural, editor visual, gestor de cadenas, glosario, roles de traductor,
  paquete de SEO, selector de idioma y traducción del sitio completo por lotes.
