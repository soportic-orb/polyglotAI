<?php
/**
 * Definición de las tablas del plugin.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Database;

/**
 * Crea y actualiza las tablas propias.
 *
 * El modelo está normalizado: el texto original vive una sola vez en
 * pgai_sources y cada idioma añade una fila en pgai_translations. Ver ADR-02
 * para por qué no hay una tabla por idioma.
 */
final class Schema {

	/** Versión del esquema. Súbela al cambiar cualquier tabla. */
	public const VERSION = 1;

	/** Opción donde se guarda la versión instalada. */
	private const VERSION_OPTION = 'pgai_schema_version';

	/**
	 * Nombre completo de una tabla.
	 *
	 * @param string $name Nombre corto: sources, translations, slugs o api_log.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'pgai_' . $name;
	}

	/**
	 * Crea o actualiza las tablas si hace falta.
	 *
	 * @param bool $force Si se fuerza aunque la versión coincida.
	 */
	public function install( bool $force = false ): void {
		if ( ! $force && (int) get_option( self::VERSION_OPTION, 0 ) === self::VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $this->statements() as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Borra todas las tablas. Solo se usa en la desinstalación.
	 */
	public function drop(): void {
		global $wpdb;

		foreach ( array( 'api_log', 'slugs', 'translations', 'sources' ) as $name ) {
			$table = self::table( $name );

			// El nombre de la tabla no puede ir por prepare y se construye a
			// partir del prefijo de $wpdb, no de entrada del usuario.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Sentencias CREATE TABLE para dbDelta.
	 *
	 * @return string[]
	 */
	private function statements(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		$sources      = self::table( 'sources' );
		$translations = self::table( 'translations' );
		$slugs        = self::table( 'slugs' );
		$api_log      = self::table( 'api_log' );

		return array(
			// El hash es ascii_bin: es hexadecimal, no necesita utf8mb4 y así el
			// índice ocupa la cuarta parte.
			"CREATE TABLE {$sources} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	hash char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	type varchar(20) NOT NULL,
	domain varchar(100) DEFAULT NULL,
	context varchar(190) DEFAULT NULL,
	original longtext NOT NULL,
	first_seen datetime NOT NULL,
	last_seen datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY hash (hash),
	KEY type_domain (type,domain),
	KEY last_seen (last_seen)
) {$collate};",

			"CREATE TABLE {$translations} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	source_id bigint(20) unsigned NOT NULL,
	language varchar(20) NOT NULL,
	translation longtext NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'pending',
	engine varchar(40) DEFAULT NULL,
	model varchar(80) DEFAULT NULL,
	reviewed_by bigint(20) unsigned DEFAULT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY source_language (source_id,language),
	KEY language_status (language,status)
) {$collate};",

			// El índice language_translated es el que resuelve el enrutado
			// inverso: /en/contact-us/ hasta la entrada cuyo slug es /contacto/.
			"CREATE TABLE {$slugs} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	object_type varchar(30) NOT NULL,
	object_subtype varchar(60) DEFAULT NULL,
	object_id bigint(20) unsigned NOT NULL DEFAULT 0,
	language varchar(20) NOT NULL,
	original_slug varchar(200) NOT NULL,
	translated_slug varchar(200) NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'automatic',
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY object_language (object_type,object_id,language),
	KEY language_translated (language,translated_slug),
	KEY language_original (language,original_slug)
) {$collate};",

			"CREATE TABLE {$api_log} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at datetime NOT NULL,
	engine varchar(40) NOT NULL,
	model varchar(80) DEFAULT NULL,
	language varchar(20) DEFAULT NULL,
	batch_id varchar(80) DEFAULT NULL,
	strings smallint(5) unsigned NOT NULL DEFAULT 0,
	input_tokens int(10) unsigned NOT NULL DEFAULT 0,
	output_tokens int(10) unsigned NOT NULL DEFAULT 0,
	cache_read_tokens int(10) unsigned NOT NULL DEFAULT 0,
	cache_creation_tokens int(10) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT 'ok',
	error text DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY created_at (created_at),
	KEY language_created (language,created_at)
) {$collate};",
		);
	}
}
