<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Admin\ImportExport;
use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\StringManagerRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\TranslatorLanguages;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use PolyglotAI\Translation\StringType;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * La importación se prueba fila a fila: la acción entera termina en exit() y
 * en una redirección, que no son cosas que un test pueda seguir.
 *
 * @covers \PolyglotAI\Admin\ImportExport
 */
final class ImportExportTest extends WP_UnitTestCase {

	private SourceRepository $sources;
	private TranslationRepository $translations;
	private ImportExport $transfer;

	/**
	 * Tablas limpias y el importador listo.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		foreach ( array( 'translations', 'sources' ) as $name ) {
			$table = Schema::table( $name );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM `{$table}`" );
		}

		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English' ) )
		);

		$this->sources      = new SourceRepository();
		$this->translations = new TranslationRepository( new StatusPrecedence() );

		$this->transfer = new ImportExport(
			$languages,
			new StringManagerRepository(),
			$this->sources,
			$this->translations,
			new TranslatorLanguages( $languages )
		);
	}

	/**
	 * Registra una cadena y devuelve su hash y su identificador.
	 *
	 * @param string      $text        Original.
	 * @param string      $translation Traducción.
	 * @param Status|null $status      Estado.
	 * @return array{0: string, 1: int}
	 */
	private function string( string $text, string $translation = '', ?Status $status = null ): array {
		$hash = md5( $text );
		$id   = $this->sources->remember( $hash, $text, StringType::Text );

		if ( null !== $status ) {
			$this->translations->save( $id, 'en_US', $translation, $status );
		}

		return array( $hash, $id );
	}

	/**
	 * Importa una fila.
	 *
	 * @param array<int, string> $row     Fila del CSV.
	 * @param bool               $protect Si se respeta lo escrito a mano.
	 */
	private function import( array $row, bool $protect = true ): string {
		$method = new ReflectionMethod( ImportExport::class, 'import_row' );
		$method->setAccessible( true );

		return (string) $method->invoke( $this->transfer, $row, 'en_US', $protect );
	}

	public function test_guarda_una_traduccion_del_archivo(): void {
		[ $hash, $id ] = $this->string( 'Hola' );

		$this->assertSame( 'saved', $this->import( array( $hash, 'text', '', 'Hola', 'Hello', '' ) ) );

		$this->assertSame( array( $hash => 'Hello' ), $this->translations->lookup( array( $hash ), 'en_US' ) );

		// Lo que trae un archivo lo ha escrito una persona: entra como manual y
		// ninguna traducción automática lo pisará después.
		$this->assertSame( Status::Manual, $this->translations->status_of( $id, 'en_US' ) );
	}

	public function test_un_hash_desconocido_no_inventa_nada(): void {
		$this->assertSame(
			'unknown',
			$this->import( array( md5( 'de otro sitio' ), 'text', '', 'Hola', 'Hello', '' ) )
		);
	}

	public function test_una_fila_sin_traduccion_se_omite(): void {
		[ $hash ] = $this->string( 'Hola' );

		$this->assertSame( 'skipped', $this->import( array( $hash, 'text', '', 'Hola', '', '' ) ) );
		$this->assertSame( 'skipped', $this->import( array( $hash, 'text', '', 'Hola', '   ', '' ) ) );
	}

	public function test_por_defecto_no_pisa_lo_escrito_a_mano(): void {
		// Reimportar un archivo viejo es el accidente más fácil de tener aquí.
		[ $hash, $id ] = $this->string( 'Hola', 'Hello', Status::Manual );

		$this->assertSame( 'skipped', $this->import( array( $hash, 'text', '', 'Hola', 'Hi', '' ) ) );
		$this->assertSame( array( $hash => 'Hello' ), $this->translations->lookup( array( $hash ), 'en_US' ) );
		$this->assertSame( Status::Manual, $this->translations->status_of( $id, 'en_US' ) );
	}

	public function test_tampoco_pisa_lo_revisado(): void {
		[ $hash ] = $this->string( 'Hola', 'Hello', Status::Reviewed );

		$this->assertSame( 'skipped', $this->import( array( $hash, 'text', '', 'Hola', 'Hi', '' ) ) );
	}

	public function test_si_se_pide_expresamente_si_lo_pisa(): void {
		[ $hash ] = $this->string( 'Hola', 'Hello', Status::Manual );

		$this->assertSame( 'saved', $this->import( array( $hash, 'text', '', 'Hola', 'Hi', '' ), false ) );
		$this->assertSame( array( $hash => 'Hi' ), $this->translations->lookup( array( $hash ), 'en_US' ) );
	}

	public function test_si_pisa_una_traduccion_automatica(): void {
		// Lo automático no es trabajo de nadie: un archivo lo mejora.
		[ $hash ] = $this->string( 'Hola', 'Hello', Status::Automatic );

		$this->assertSame( 'saved', $this->import( array( $hash, 'text', '', 'Hola', 'Hi', '' ) ) );
	}

	public function test_el_original_del_archivo_no_se_usa_para_buscar(): void {
		// Si alguien lo edita en la hoja de cálculo, la fila tiene que seguir
		// encajando: lo que empareja es el hash.
		[ $hash ] = $this->string( 'Hola' );

		$this->assertSame(
			'saved',
			$this->import( array( $hash, 'text', '', 'Esto ya no es el original', 'Hello', '' ) )
		);
	}
}
