<?php
/**
 * Importación y exportación de traducciones.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Admin;

use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\StringManagerRepository;
use PolyglotAI\Database\StringQuery;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Support\TranslatorLanguages;
use PolyglotAI\Translation\DictionaryFactory;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StringType;

/**
 * Exporta traducciones a CSV y las vuelve a leer.
 *
 * **CSV y no PO.** El formato de gettext solo sabe de cadenas de gettext, y
 * aquí la mayoría no lo son: son textos de una página, atributos, metaetiquetas
 * y slugs, que no tienen ni dominio ni referencia de archivo. Un CSV lo abre
 * cualquier traductor en su hoja de cálculo, vuelve con las mismas columnas y
 * no obliga a inventar un `#: archivo:línea` que no existe.
 *
 * La columna que importa es el **hash**: es lo que empareja cada fila con su
 * cadena. El original va en el archivo para que el traductor lea lo que está
 * traduciendo, pero al volver no se usa para buscar nada, porque si alguien lo
 * edita en la hoja de cálculo dejaría de encajar con nada.
 *
 * La exportación se sirve por trozos. Un sitio grande tiene decenas de miles de
 * cadenas y construir el archivo entero en memoria antes de mandarlo es la
 * forma segura de agotar el límite de PHP justo en el sitio con más contenido.
 */
final class ImportExport {

	/** Cabecera del CSV. */
	private const COLUMNS = array( 'hash', 'type', 'context', 'original', 'translation', 'status' );

	/** Filas que se leen de la base de datos de una vez. */
	private const CHUNK = 500;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry        $languages    Idiomas del sitio.
	 * @param StringManagerRepository $strings      Consultas del gestor.
	 * @param SourceRepository        $sources      Repositorio de cadenas.
	 * @param TranslationRepository   $translations Repositorio de traducciones.
	 * @param TranslatorLanguages     $access       Idiomas asignados a cada traductor.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly StringManagerRepository $strings,
		private readonly SourceRepository $sources,
		private readonly TranslationRepository $translations,
		private readonly TranslatorLanguages $access
	) {}

	/**
	 * Engancha las dos acciones.
	 */
	public function register(): void {
		add_action( 'admin_post_pgai_export', array( $this, 'export' ) );
		add_action( 'admin_post_pgai_import', array( $this, 'import' ) );
	}

	/**
	 * Envía el CSV.
	 */
	public function export(): void {
		$language = $this->requested_language( 'pgai_export' );

		$query = new StringQuery(
			$language,
			'',
			Status::tryFrom( $this->requested( 'status' ) ),
			StringType::tryFrom( $this->requested( 'type' ) ),
			1,
			self::CHUNK,
			true
		);

		$this->send_headers( $language );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			exit;
		}

		fputcsv( $output, self::COLUMNS );

		$page = 1;

		do {
			$rows = $this->strings->search(
				new StringQuery(
					$query->language,
					'',
					$query->status,
					$query->type,
					$page,
					self::CHUNK,
					true
				)
			);

			foreach ( $rows as $row ) {
				fputcsv(
					$output,
					array(
						$row['hash'],
						$row['type'],
						(string) $row['context'],
						$row['original'],
						$row['translation'],
						$row['status'],
					)
				);
			}

			$fetched = count( $rows );

			++$page;
		} while ( self::CHUNK === $fetched );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );
		exit;
	}

	/**
	 * Lee un CSV y guarda lo que traiga.
	 */
	public function import(): void {
		$language = $this->requested_language( 'pgai_import' );

		// requested_language() ya ha comprobado el nonce y la capacidad.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$file = $_FILES['pgai_file']['tmp_name'] ?? '';

		if ( ! is_string( $file ) || '' === $file || ! is_uploaded_file( $file ) ) {
			$this->finish( $language, array( 'error' => 'file' ) );
		}

		// Sin esto, lo escrito a mano y lo revisado se pierde al reimportar un
		// archivo viejo, que es el accidente más fácil de tener aquí.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$protect = ! isset( $_POST['pgai_overwrite'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file, 'r' );

		if ( false === $handle ) {
			$this->finish( $language, array( 'error' => 'file' ) );
		}

		$saved   = 0;
		$skipped = 0;
		$unknown = 0;
		$first   = true;

		while ( true ) {
			$row = fgetcsv( $handle );

			if ( false === $row ) {
				break;
			}

			if ( $first ) {
				$first = false;

				// La primera fila puede ser la cabecera o no, según quién haya
				// guardado el archivo.
				if ( 'hash' === strtolower( trim( (string) ( $row[0] ?? '' ) ) ) ) {
					continue;
				}
			}

			$outcome = $this->import_row( $row, $language, $protect );

			if ( 'saved' === $outcome ) {
				++$saved;
			} elseif ( 'unknown' === $outcome ) {
				++$unknown;
			} else {
				++$skipped;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		if ( $saved > 0 ) {
			DictionaryFactory::invalidate();
		}

		$this->finish(
			$language,
			array(
				'saved'   => $saved,
				'skipped' => $skipped,
				'unknown' => $unknown,
			)
		);
	}

	/**
	 * Guarda una fila del CSV.
	 *
	 * @param array<int, string|null> $row      Fila.
	 * @param string                  $language Locale.
	 * @param bool                    $protect  Si se respeta lo escrito por una persona.
	 * @return string saved, skipped o unknown.
	 */
	private function import_row( array $row, string $language, bool $protect ): string {
		$hash        = sanitize_text_field( (string) ( $row[0] ?? '' ) );
		$translation = (string) ( $row[4] ?? '' );

		if ( '' === $hash || '' === trim( $translation ) ) {
			return 'skipped';
		}

		$ids = $this->sources->ids_by_hash( array( $hash ) );

		// Un hash que no está en la base de datos es una cadena que ya no
		// existe, o un archivo de otro sitio: no se inventa nada.
		if ( ! isset( $ids[ $hash ] ) ) {
			return 'unknown';
		}

		$source_id = $ids[ $hash ];
		$current   = $this->translations->status_of( $source_id, $language );

		if ( $protect && null !== $current && $current->is_human() ) {
			return 'skipped';
		}

		// Lo que trae un archivo lo ha escrito una persona, así que entra como
		// manual y ninguna traducción automática lo pisará después.
		return $this->translations->save( $source_id, $language, $translation, Status::Manual )
			? 'saved'
			: 'skipped';
	}

	/**
	 * Comprueba permisos y nonce y devuelve el idioma pedido.
	 *
	 * @param string $action Nombre del nonce.
	 */
	private function requested_language( string $action ): string {
		if ( ! current_user_can( Capabilities::TRANSLATE ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'polyglot-ai' ) );
		}

		check_admin_referer( $action );

		$locale   = $this->requested( 'language' );
		$language = $this->languages->by_locale( $locale );

		if ( null === $language || $this->languages->is_default( $language->slug ) ) {
			wp_die( esc_html__( 'Ese idioma no se traduce.', 'polyglot-ai' ) );
		}

		if ( ! $this->access->allows( get_current_user_id(), $language->locale ) ) {
			wp_die( esc_html__( 'No tienes asignado este idioma.', 'polyglot-ai' ) );
		}

		return $language->locale;
	}

	/**
	 * Un parámetro de la petición, saneado.
	 *
	 * El nonce ya se ha comprobado en requested_language().
	 *
	 * @param string $key Nombre.
	 */
	private function requested( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$value = $_REQUEST[ $key ] ?? '';

		return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
	}

	/**
	 * Cabeceras de la descarga.
	 *
	 * @param string $language Locale.
	 */
	private function send_headers( string $language ): void {
		$name = sprintf( 'polyglot-ai-%s-%s.csv', $language, gmdate( 'Y-m-d' ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $name );
		header( 'Cache-Control: no-store' );

		// Sin esto, cualquier búfer abierto por el tema o por otro plugin mete
		// su salida dentro del CSV y el archivo llega corrupto.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Vuelve al gestor con el resultado.
	 *
	 * @param string                $language Locale.
	 * @param array<string, scalar> $result   Resultado de la importación.
	 */
	private function finish( string $language, array $result ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge( array( 'pgai-lang' => $language ), $result ),
				menu_page_url( StringsPage::SLUG, false )
			)
		);
		exit;
	}
}
