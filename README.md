# Polyglot AI

Plugin de WordPress que traduce el sitio entero a los idiomas que configures,
con motor de inteligencia artificial, URLs por idioma y corrección manual que la
traducción automática no pisa nunca.

> Estado: **fase 8 de 8, en curso**. Están la base de traducción, el enrutado por
> idioma, el motor, el editor visual, las cadenas de temas y plugins, el
> contenido dinámico, los correos, el paquete de SEO, el selector en todas sus
> formas, la detección del visitante, los roles de traductor, el gestor de
> cadenas, el glosario, las estadísticas y la traducción del sitio completo por
> lotes. La fase 8 —compatibilidad, rendimiento, seguridad, pruebas de extremo a
> extremo y documentación— está en marcha (ver `CLAUDE.md`).

## Cómo funciona

La traducción se aplica sobre el **HTML ya renderizado**: se captura la salida
de la página, se localizan las cadenas y se sustituyen por su traducción. Por
eso funciona con cualquier tema, constructor o plugin, sin integraciones
específicas.

Lo que no es una cadena de texto **no se toca**: el documento no se vuelve a
serializar, se empalman solo los intervalos de bytes que cambian. El doctype, el
JavaScript, el CSS, los datos estructurados y la codificación salen idénticos.
Si algo sale mal en el proceso, se sirve la página original sin traducir.

## Requisitos

- WordPress 6.6 o superior
- PHP 8.1 o superior
- MySQL 5.7+ o MariaDB 10.4+
- Una clave de API de Anthropic

## Instalación

1. Copia el plugin en `wp-content/plugins/polyglot-ai`.
2. Desde esa carpeta, ejecuta `composer install --no-dev`.
3. Activa el plugin.
4. Añade la clave de API a `wp-config.php`:

```php
define( 'PGAI_API_KEY', 'sk-ant-...' );
```

   Es la vía recomendada: así la clave no se guarda en la base de datos ni
   aparece en las copias de seguridad. También puede introducirse desde el
   panel, en cuyo caso se cifra con las sales de la instalación.

5. En **Polyglot AI → Ajustes**, configura los idiomas y describe el sitio en el
   campo de contexto. Esa descripción mejora notablemente la calidad de la
   traducción.

## Uso

Coloca el selector de idioma con el shortcode:

```
[pgai_language_switcher display="both"]
```

Las páginas traducidas viven en un subdirectorio por idioma: `/en/`, `/ca/`,
`/pt-br/`. Los enlaces internos se reescriben solos.

### El editor visual

Con el sitio abierto en el navegador, **Traducir página** en la barra de
administración abre el editor: el sitio real dentro de un iframe a la derecha y
el panel de traducción a la izquierda.

- Pasa el ratón por encima de cualquier texto y púlsalo para cargarlo en el panel.
- La lista lateral agrupa todas las cadenas de la página —contenido, atributos,
  metas de SEO, título— y se puede buscar en ella.
- `Ctrl+S` guarda. `Ctrl+Intro` guarda y pasa a la siguiente.
- **Sugerir con IA** rellena el campo sin guardar: la traducción no se aplica
  hasta que la aceptas.
- Navegar por el sitio dentro del iframe mantiene el modo de edición.

El marcado que el editor añade a la página **solo existe dentro del iframe**. Una
visita normal recibe el HTML limpio, y para entrar en modo edición hacen falta
las tres cosas a la vez: el parámetro en la URL, la capacidad de traducir y un
nonce válido.

Lo que escribas a mano pasa por la **misma validación estructural** que lo que
devuelve el motor: si pierdes una etiqueta, un `%s` o una URL, se rechaza y se te
dice por qué.

### Contenido que aparece después

Un filtro por AJAX, un carrito que se actualiza o un carrusel que monta su
contenido con JavaScript se saltan la traducción del HTML, porque cuando esta
ocurrió ese texto todavía no existía. Un observador ligero los detecta y aplica
la traducción que ya exista, con caché en la propia pestaña. Se puede desactivar
en los ajustes.

### Correos

Los correos se envían en el idioma del **destinatario**, no en el de quien
provoca el envío: si un administrador cambia el estado de un pedido desde el
escritorio en español, el cliente sigue recibiendo su aviso en inglés.

El idioma de cada usuario se guarda solo al navegar por el sitio, y puede
fijarse a mano en su perfil.

### Traducción en segundo plano

Por defecto, cuando alguien visita una página con cadenas sin traducir se
muestra el original y esas cadenas se anotan para traducirlas en segundo plano;
la traducción aparece en la visita siguiente. **La carga nunca se bloquea y
nunca se llama a la API dentro de la petición de un visitante.** El tráfico de
robots no encola nada, para que un rastreo completo del sitio no dispare el
gasto.

Puede desactivarse en los ajustes para traducir solo desde el panel.

### Traducción del sitio completo

Desde el panel se lanza la traducción de todo el sitio a un idioma. Antes de
enviar nada estima lo que va a costar en tokens; después usa los lotes
asíncronos de la API, que cuestan la mitad, y va informando del progreso. Se
puede pausar y reanudar: lo ya enviado no se tira, porque se cobra igual.

### SEO

El título, la meta descripción, las Open Graph, las Twitter Cards y los datos
estructurados se traducen como cualquier otro texto de la página, y las URLs
canónicas y de paginación reciben su prefijo de idioma. Funciona con Yoast SEO,
Rank Math, SEOPress y All in One SEO sin configurar nada, porque se trabaja
sobre el HTML que emiten y no sobre los hooks de cada uno.

Hay además un sitemap por idioma: dentro del sitemap de WordPress si está en
pie, y dentro del índice del plugin de SEO activo si lo ha sustituido.

### El equipo

El rol **Traductor** tiene capacidades propias, se le pueden asignar solo
algunos idiomas y no entra en el escritorio. El gestor de cadenas permite
buscar, filtrar por estado y editar en masa, y hay importación y exportación en
CSV que por defecto no pisa lo revisado ni lo escrito a mano.

El **glosario** fija traducciones obligatorias y la lista de términos que no se
traducen nunca; ambos viajan dentro del prompt.

## Preguntas frecuentes

**¿Se pierden mis correcciones manuales al retraducir?**
No. Una traducción marcada como manual o revisada no la sobrescribe nunca un
proceso automático, ni al retraducir, ni al cambiar de modelo, ni al reimportar.

**¿Qué pasa si desactivo el plugin?**
El sitio vuelve a su estado original. No se borra nada: desactivar es
reversible. El borrado solo ocurre al desinstalar, y solo si lo has marcado en
los ajustes.

**¿Se envían datos personales a la API?**
No. Quedan fuera las páginas de carrito, pago, cuenta y pedidos —se le preguntan
a WooCommerce, así que la exclusión acierta con el slug traducido de cada idioma
y no depende de cómo se llamen—, las respuestas a cualquier formulario enviado
por POST, el contenido de los `<textarea>` y las vistas previas de borradores.
La lista de rutas excluidas es ampliable en los ajustes.

**¿Funciona con caché de página?**
Sí. Cada idioma tiene su propia URL, así que cada uno se cachea por separado, y
al guardar una traducción o cambiar un slug se le pide al plugin de caché que
vacíe lo suyo para que el cambio se vea enseguida. Se reconocen WP Rocket, W3
Total Cache, WP Super Cache, LiteSpeed Cache, Cache Enabler, WP Fastest Cache y
SiteGround Optimizer; para las demás hay una acción donde engancharse.

**¿Qué pasa si la traducción rompe el diseño?**
No llega a publicarse. Toda traducción se compara con el original: si pierde una
etiqueta HTML, un marcador `%s`, un shortcode o una URL, se descarta y se sigue
mostrando el texto original.

## Desarrollo

```bash
composer install
npm install

composer test           # PHPUnit, suite unitaria
composer test:install   # Descarga WordPress y su biblioteca de tests
composer test:integration  # PHPUnit contra WordPress y MySQL reales
composer phpcs          # WordPress Coding Standards
composer phpstan        # Análisis estático, nivel 6

npm run build      # Compila el editor y la vista previa
npm run test:js    # Jest
npm run lint:js    # ESLint
npm run env:start  # WordPress local con wp-env

bash tests/e2e/install.sh  # Deja un WordPress servido con el plugin activo
npm run test:e2e           # Playwright contra ese sitio
```

Las pruebas de extremo a extremo no necesitan Docker: `tests/e2e/install.sh`
instala un WordPress, activa el plugin, configura dos idiomas, crea una página
con el selector dentro, guarda una traducción y lo sirve con el servidor
integrado de PHP. Con `wp-env` se puede saltar el script apuntando
`PGAI_E2E_URL` a su puerto. Si el navegador ya está instalado fuera de
`node_modules`, `PGAI_E2E_CHROMIUM` dice dónde está en vez de descargar otro.

La suite unitaria corre **sin Docker y sin una instalación de WordPress**:
descarga la HTML API real del WordPress mínimo soportado y prueba el analizador
contra el parser de verdad, no contra una imitación. La de integración sí
necesita MySQL y corre contra un WordPress completo.

El editor no funciona desde una copia del repositorio sin compilar: los recursos
de `assets/build/` no se versionan. Si faltan, la pantalla del editor lo dice en
lugar de quedarse en blanco.

Documentación de hooks, filtros y capacidades en [`docs/hooks.md`](docs/hooks.md).
Decisiones de arquitectura en [`CLAUDE.md`](CLAUDE.md).
