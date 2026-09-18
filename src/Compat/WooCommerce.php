<?php
/**
 * Integración con WooCommerce.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Compat;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\RequestContext;

/**
 * Hace que los correos de un pedido salgan en el idioma en que se hizo.
 *
 * El resolvedor de correos (`Mail\LanguageResolver`) mira el idioma guardado en
 * el usuario, y para una tienda eso no basta: **la mayoría de los pedidos los
 * hacen invitados**, que no tienen usuario ni preferencia guardada, así que sus
 * avisos salían todos en el idioma por defecto del sitio. Aquí se anota el
 * idioma en el propio pedido cuando se hace y se devuelve al enviar sus correos.
 *
 * **Manda el idioma del pedido sobre el del usuario**, y no al revés. El del
 * pedido es el de la compra de la que habla ese correo concreto; el del usuario
 * se guarda solo al navegar (`Languages\UserLanguage`), así que puede ser
 * simplemente la última página que miró, incluso después de comprar.
 *
 * **Cómo se sabe de qué pedido es un correo.** WooCommerce compone cada aviso en
 * un objeto `WC_Email` que lleva dentro el pedido, y lo pasa por
 * `woocommerce_mail_callback_params` justo antes de llamar a `wp_mail()`. Como
 * nuestro traductor de correos se engancha dentro de `wp_mail()`, para entonces
 * el pedido ya está anotado. Se consume una sola vez: si no se borrara al
 * usarlo, el siguiente correo del sitio —uno de recuperar contraseña, por
 * ejemplo— saldría en el idioma del último pedido que pasó por aquí.
 *
 * Todo va por nombre de función y de hook, sin `use` de ninguna clase de
 * WooCommerce: si no está instalado, estos filtros no se disparan nunca.
 */
final class WooCommerce {

	/**
	 * Metadato del pedido donde se guarda el idioma.
	 */
	public const ORDER_META = '_pgai_language';

	/**
	 * Pedido cuyo correo se está enviando ahora mismo.
	 *
	 * @var object|null
	 */
	private ?object $sending = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param RequestContext   $request   Petición en curso.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly RequestContext $request
	) {}

	/**
	 * Engancha la integración.
	 */
	public function register(): void {
		// Los dos caminos de compra: el clásico y el de los bloques, que pasa
		// por la Store API y no dispara el hook del clásico.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'remember' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'remember' ) );

		add_filter( 'woocommerce_mail_callback_params', array( $this, 'capture' ), 10, 2 );
		add_filter( 'pgai_recipient_language', array( $this, 'language_of_order' ) );
	}

	/**
	 * Anota en el pedido el idioma en que se está comprando.
	 *
	 * @param mixed $order Pedido de WooCommerce.
	 */
	public function remember( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		// update_meta_data() sobre el propio pedido, no update_post_meta(): es
		// lo único que funciona con el almacenamiento de pedidos en tabla
		// propia (HPOS) y con el clásico a la vez. WooCommerce lo guarda al
		// terminar de crear el pedido.
		$order->update_meta_data( self::ORDER_META, $this->request->language()->locale );
	}

	/**
	 * Anota de qué pedido es el correo que se va a enviar.
	 *
	 * @param mixed $params Argumentos de wp_mail.
	 * @param mixed $email  Objeto WC_Email que lo envía.
	 * @return mixed
	 */
	public function capture( $params, $email = null ) {
		$order = is_object( $email ) && isset( $email->object ) ? $email->object : null;

		$this->sending = is_object( $order ) && method_exists( $order, 'get_meta' ) ? $order : null;

		return $params;
	}

	/**
	 * Idioma del pedido cuyo correo se está enviando.
	 *
	 * @param mixed $language Idioma que haya resuelto ya el resolvedor.
	 * @return mixed
	 */
	public function language_of_order( $language ) {
		$order = $this->sending;

		// De un solo uso: el siguiente correo del sitio no tiene por qué ser de
		// este pedido ni de ninguno.
		$this->sending = null;

		if ( null === $order ) {
			return $language;
		}

		$locale = $order->get_meta( self::ORDER_META );

		if ( ! is_string( $locale ) || '' === $locale ) {
			return $language;
		}

		$found = $this->languages->by_locale( $locale );

		return $found instanceof Language ? $found : $language;
	}
}
