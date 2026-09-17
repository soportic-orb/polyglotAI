<?php
/**
 * Custodia de la clave de API.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Support;

/**
 * Lo único que el cliente HTTP necesita saber de la clave.
 *
 * La implementación de verdad la descifra de la base de datos o la lee de una
 * constante de wp-config.php, y para eso necesita WordPress entero. El cliente
 * no: solo necesita la cadena. Separarlo es lo que permite probar la capa HTTP
 * —cabeceras, reintentos, decodificación— sin levantar WordPress.
 */
interface ApiKeyInterface {

	/**
	 * Clave configurada, o null si no hay ninguna.
	 */
	public function get(): ?string;
}
