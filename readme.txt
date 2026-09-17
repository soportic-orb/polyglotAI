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
datos estructurados y la codificación salen idénticos.

Características de esta versión:

* Idiomas ilimitados con variantes regionales y soporte RTL.
* URLs por idioma en subdirectorio, con enlaces internos reescritos.
* Etiquetas hreflang, x-default y atributo lang correctos.
* Traducción automática por lotes con validación estructural.
* Las correcciones manuales no se sobrescriben nunca.
* Selector de idioma por shortcode.
* Rol de traductor con capacidades granulares.

== Privacidad ==

El plugin envía el contenido de las páginas a la API de Anthropic para
traducirlo. Las rutas con datos personales (cuenta, carrito y finalización de
compra) están excluidas por defecto y la lista es ampliable desde los ajustes.

No se envía ningún dato a la API durante la visita de un usuario: la traducción
se genera en segundo plano o desde el panel.

== Installation ==

1. Copia el plugin en `wp-content/plugins/polyglot-ai`.
2. Ejecuta `composer install --no-dev` en la carpeta del plugin.
3. Actívalo.
4. Añade `define( 'PGAI_API_KEY', 'sk-ant-...' );` a `wp-config.php`.
5. Configura los idiomas en Polyglot AI → Ajustes.

== Frequently Asked Questions ==

= ¿Se pierden mis correcciones manuales al retraducir? =

No. Una traducción marcada como manual o revisada no la sobrescribe ningún
proceso automático.

= ¿Qué pasa si desactivo el plugin? =

El sitio vuelve a su estado original. No se borra nada.

= ¿Funciona con plugins de caché? =

Sí. Cada idioma tiene su propia URL y se cachea por separado.

== Changelog ==

= 0.1.0 =
* Primera versión: base de traducción, enrutado por idioma, motor de IA y
  selector básico.
