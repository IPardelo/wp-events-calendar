<?php
/**
 * Menú de administración: Eventos, Localizaciones y Espectáculos.
 *
 * @package WP_Events_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPEC_Admin {

	const PER_PAGE = 20;

	/** @var string[] Errores de validación del formulario enviado. */
	private $errors = array();

	/** @var array|null Valores enviados, para volver a pintar el formulario si hay errores. */
	private $posted = null;

	/** @var bool La subida del XML falló y hay que volver a mostrar la pantalla de importación. */
	private $import_failed = false;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Capacidad necesaria para gestionar el calendario (editores y administradores por defecto).
	 */
	public static function capability() {
		return apply_filters( 'wpec_capability', 'edit_pages' );
	}

	public function register_menu() {
		$cap = self::capability();

		add_menu_page(
			__( 'Events', 'wp-events-calendar' ),
			__( 'Events', 'wp-events-calendar' ),
			$cap,
			'wpec-events',
			array( $this, 'page_events' ),
			'dashicons-calendar-alt',
			26
		);
		add_submenu_page( 'wpec-events', __( 'Events', 'wp-events-calendar' ), __( 'Events', 'wp-events-calendar' ), $cap, 'wpec-events', array( $this, 'page_events' ) );
		add_submenu_page( 'wpec-events', __( 'Locations', 'wp-events-calendar' ), __( 'Locations', 'wp-events-calendar' ), $cap, 'wpec-locations', array( $this, 'page_locations' ) );
		add_submenu_page( 'wpec-events', __( 'Shows', 'wp-events-calendar' ), __( 'Shows', 'wp-events-calendar' ), $cap, 'wpec-shows', array( $this, 'page_shows' ) );
		add_submenu_page( 'wpec-events', __( 'Shortcodes', 'wp-events-calendar' ), __( 'Shortcodes', 'wp-events-calendar' ), $cap, 'wpec-shortcodes', array( $this, 'page_shortcodes' ) );
	}

	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'wpec-' ) ) {
			return;
		}
		wp_enqueue_style( 'wpec-admin', WPEC_URL . 'assets/css/wpec-admin.css', array(), WPEC_VERSION );
		wp_enqueue_script( 'wpec-admin', WPEC_URL . 'assets/js/wpec-admin.js', array(), WPEC_VERSION, true );
	}

	/* =====================================================================
	 * Procesado de formularios y borrados
	 * ================================================================== */

	public function handle_actions() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( 'wpec-events', 'wpec-locations', 'wpec-shows', 'wpec-shortcodes' ), true ) ) {
			return;
		}
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		// Borrado (enlace con nonce).
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			$id = absint( $_GET['id'] );
			check_admin_referer( 'wpec_delete_' . $page . '_' . $id );
			$this->handle_delete( $page, $id );
			return;
		}

		// Importación (subida del XML y confirmación).
		if ( 'wpec-events' === $page && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( isset( $_POST['wpec_import_upload'] ) ) {
				check_admin_referer( 'wpec_import_upload' );
				$this->handle_import_upload();
				return;
			}
			if ( isset( $_POST['wpec_export'] ) ) {
				check_admin_referer( 'wpec_export' );
				WPEC_Exporter::download();
			}
			if ( isset( $_POST['wpec_import_run'] ) ) {
				check_admin_referer( 'wpec_import_run' );
				$this->handle_import_run();
				return;
			}
		}

		// Espectáculos: importar / exportar.
		if ( 'wpec-shows' === $page && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( isset( $_POST['wpec_shows_export'] ) ) {
				check_admin_referer( 'wpec_shows_export' );
				WPEC_Exporter::download( 'shows' );
			}
			if ( isset( $_POST['wpec_shows_import'] ) ) {
				check_admin_referer( 'wpec_shows_import' );
				$this->handle_shows_import();
				return;
			}
		}

		// Guardado (formulario POST).
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['wpec_form'] ) ) {
			check_admin_referer( 'wpec_save_' . $page );
			$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

			switch ( $page ) {
				case 'wpec-events':
					$this->save_event( $id );
					break;
				case 'wpec-locations':
					$this->save_location( $id );
					break;
				case 'wpec-shows':
					$this->save_show( $id );
					break;
				case 'wpec-shortcodes':
					$this->save_shortcode( $id );
					break;
			}
		}
	}

	private function handle_delete( $page, $id ) {
		$map = array(
			'wpec-events'    => array( 'events', null ),
			'wpec-locations' => array( 'locations', 'location_id' ),
			'wpec-shows'     => array( 'shows', 'show_id' ),
			'wpec-shortcodes' => array( 'shortcodes', null ),
		);
		list( $table, $fk ) = $map[ $page ];

		if ( $fk && WPEC_DB::count_events_using( $fk, $id ) > 0 ) {
			$this->redirect( $page, 'in_use' );
		}

		WPEC_DB::delete( $table, $id );
		$this->redirect( $page, 'deleted' );
	}

	private function save_event( $id ) {
		$data = array(
			'start_date'  => $this->posted_date( 'start_date' ),
			'end_date'    => $this->posted_date( 'end_date' ),
			'event_time'  => $this->posted_time( 'event_time' ),
			'location_id' => isset( $_POST['location_id'] ) ? absint( $_POST['location_id'] ) : 0,
			'show_id'     => isset( $_POST['show_id'] ) ? absint( $_POST['show_id'] ) : 0,
		);

		if ( ! $data['start_date'] ) {
			$this->errors[] = __( 'The start date is required and must be valid.', 'wp-events-calendar' );
		}
		if ( $data['start_date'] && $data['end_date'] && $data['end_date'] < $data['start_date'] ) {
			$this->errors[] = __( 'The end date cannot be earlier than the start date.', 'wp-events-calendar' );
		}
		if ( $data['end_date'] === $data['start_date'] ) {
			$data['end_date'] = null; // Evento de un solo día.
		}
		if ( ! $data['location_id'] || ! WPEC_DB::get_location( $data['location_id'] ) ) {
			$this->errors[] = __( 'Select a location.', 'wp-events-calendar' );
		}
		if ( ! $data['show_id'] || ! WPEC_DB::get_show( $data['show_id'] ) ) {
			$this->errors[] = __( 'Select a show.', 'wp-events-calendar' );
		}

		$this->finish_save( 'wpec-events', $data, $id, array( 'WPEC_DB', 'save_event' ) );
	}

	private function save_location( $id ) {
		$data = array(
			'province'     => $this->posted_text( 'province' ),
			'municipality' => $this->posted_text( 'municipality' ),
			'venue'        => $this->posted_text( 'venue' ),
			'address'      => $this->posted_text( 'address' ),
		);

		if ( '' === $data['province'] ) {
			$this->errors[] = __( 'The province is required.', 'wp-events-calendar' );
		}
		if ( '' === $data['municipality'] ) {
			$this->errors[] = __( 'The municipality is required.', 'wp-events-calendar' );
		}
		if ( '' === $data['venue'] ) {
			$this->errors[] = __( 'The venue name is required.', 'wp-events-calendar' );
		}

		$this->finish_save( 'wpec-locations', $data, $id, array( 'WPEC_DB', 'save_location' ) );
	}

	private function save_show( $id ) {
		$raw_url = isset( $_POST['url'] ) ? trim( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data    = array(
			'name' => $this->posted_text( 'name' ),
			'url'  => '' === $raw_url ? '' : esc_url_raw( $raw_url ),
		);

		if ( '' === $data['name'] ) {
			$this->errors[] = __( 'The show name is required.', 'wp-events-calendar' );
		}
		if ( '' !== $raw_url && '' === $data['url'] ) {
			$this->errors[] = __( 'The URL is not valid.', 'wp-events-calendar' );
		}

		$this->finish_save( 'wpec-shows', $data, $id, array( 'WPEC_DB', 'save_show' ) );
	}

	private function save_shortcode( $id ) {
		$raw_tag = $this->posted_text( 'tag' );
		$data    = array(
			'name'       => $this->posted_text( 'name' ),
			'tag'        => self::make_tag( '' !== $raw_tag ? $raw_tag : $this->posted_text( 'name' ) ),
			'show_when'  => $this->posted_text( 'show_when' ),
			'max_events' => isset( $_POST['max_events'] ) ? absint( $_POST['max_events'] ) : 0,
		);

		if ( '' === $data['name'] ) {
			$this->errors[] = __( 'The name is required.', 'wp-events-calendar' );
		}
		if ( ! in_array( $data['show_when'], array( 'upcoming', 'past', 'all' ), true ) ) {
			$data['show_when'] = 'upcoming';
		}
		if ( '' === $data['tag'] ) {
			$this->errors[] = __( 'The shortcode can only contain letters, numbers and underscores.', 'wp-events-calendar' );
		} else {
			$other = WPEC_DB::get_shortcode_by_tag( $data['tag'] );
			if ( in_array( $data['tag'], WPEC_Shortcode::reserved_tags(), true ) || ( $other && (int) $other->id !== $id ) ) {
				/* translators: %s: shortcode tag */
				$this->errors[] = sprintf( __( 'The shortcode [%s] is already in use. Choose another one.', 'wp-events-calendar' ), $data['tag'] );
			} elseif ( ! $other && shortcode_exists( $data['tag'] ) ) {
				/* translators: %s: shortcode tag */
				$this->errors[] = sprintf( __( 'Another plugin or the theme already uses the shortcode [%s]. Choose another one.', 'wp-events-calendar' ), $data['tag'] );
			}
		}

		$this->finish_save( 'wpec-shortcodes', $data, $id, array( 'WPEC_DB', 'save_shortcode' ) );
	}

	/**
	 * Convierte un texto en una etiqueta de shortcode válida: minúsculas, números y guiones bajos.
	 * "Próximas funcións" → "proximas_funcions".
	 */
	public static function make_tag( $text ) {
		$tag = strtolower( remove_accents( (string) $text ) );
		$tag = preg_replace( '/[^a-z0-9_]+/', '_', $tag );
		$tag = trim( preg_replace( '/_+/', '_', $tag ), '_' );
		return substr( $tag, 0, 50 );
	}

	private function finish_save( $page, $data, $id, $saver ) {
		if ( $this->errors ) {
			$this->posted       = $data;
			$this->posted['id'] = $id;
			return; // Se vuelve a pintar el formulario con los errores.
		}
		call_user_func( $saver, $data, $id );
		$this->redirect( $page, $id ? 'updated' : 'added' );
	}

	private function redirect( $page, $msg, $extra = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page, 'wpec_msg' => $msg ), $extra ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Importación
	 * ------------------------------------------------------------------ */

	private function handle_import_upload() {
		$this->import_failed = true; // Mantiene la pantalla de importación si hay errores.

		if ( empty( $_FILES['wpec_file']['tmp_name'] ) || ! empty( $_FILES['wpec_file']['error'] ) ) {
			$this->errors[] = __( 'Select an XML file.', 'wp-events-calendar' );
			return;
		}
		$file = $_FILES['wpec_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ext  = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
		if ( 'xml' !== $ext ) {
			$this->errors[] = __( 'The file must have the .xml extension.', 'wp-events-calendar' );
			return;
		}
		if ( $file['size'] > WPEC_Importer::MAX_SIZE ) {
			$this->errors[] = __( 'The file is too large (maximum 5 MB).', 'wp-events-calendar' );
			return;
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->errors[] = __( 'The uploaded file could not be read.', 'wp-events-calendar' );
			return;
		}

		$data = WPEC_Importer::parse_file( $file['tmp_name'] );
		if ( is_wp_error( $data ) ) {
			$this->errors[] = $data->get_error_message();
			return;
		}

		set_transient( WPEC_Importer::transient_key(), $data, HOUR_IN_SECONDS );
		wp_safe_redirect( $this->page_url( 'wpec-events', array( 'action' => 'import', 'step' => 'preview' ) ) );
		exit;
	}

	/**
	 * Valida el fichero subido en $_FILES['wpec_file'] y devuelve su ruta temporal (o null con errores).
	 */
	private function uploaded_xml_path() {
		if ( empty( $_FILES['wpec_file']['tmp_name'] ) || ! empty( $_FILES['wpec_file']['error'] ) ) {
			$this->errors[] = __( 'Select an XML file.', 'wp-events-calendar' );
			return null;
		}
		$file = $_FILES['wpec_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'xml' !== strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) ) ) {
			$this->errors[] = __( 'The file must have the .xml extension.', 'wp-events-calendar' );
			return null;
		}
		if ( $file['size'] > WPEC_Importer::MAX_SIZE ) {
			$this->errors[] = __( 'The file is too large (maximum 5 MB).', 'wp-events-calendar' );
			return null;
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->errors[] = __( 'The uploaded file could not be read.', 'wp-events-calendar' );
			return null;
		}
		return $file['tmp_name'];
	}

	private function handle_shows_import() {
		$this->import_failed = true; // Si hay errores, se vuelve a mostrar la pantalla de importación.
		$path                = $this->uploaded_xml_path();
		if ( ! $path ) {
			return;
		}
		$stats = WPEC_Importer::import_shows_file( $path );
		if ( is_wp_error( $stats ) ) {
			$this->errors[] = $stats->get_error_message();
			return;
		}
		$this->redirect(
			'wpec-shows',
			'shows_imported',
			array(
				'sc' => $stats['created'],
				'su' => $stats['updated'],
				'se' => $stats['existing'],
			)
		);
	}

	private function handle_import_run() {
		$data = get_transient( WPEC_Importer::transient_key() );
		if ( ! $data ) {
			$this->redirect( 'wpec-events', 'import_expired', array( 'action' => 'import' ) );
		}

		$titles  = array_keys( $data['titles'] );
		$actions = isset( $_POST['wpec_map'] ) ? (array) wp_unslash( $_POST['wpec_map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$names   = isset( $_POST['wpec_name'] ) ? (array) wp_unslash( $_POST['wpec_name'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$mapping = array();
		foreach ( $titles as $i => $title ) {
			$action = isset( $actions[ $i ] ) ? sanitize_key( $actions[ $i ] ) : 'new';
			if ( ! in_array( $action, array( 'new', 'skip' ), true ) ) {
				$action = (string) absint( $action );
			}
			$mapping[ $title ] = array(
				'action' => $action,
				'name'   => isset( $names[ $i ] ) ? sanitize_text_field( $names[ $i ] ) : '',
			);
		}

		$stats = WPEC_Importer::import( $data, $mapping );
		delete_transient( WPEC_Importer::transient_key() );

		$this->redirect(
			'wpec-events',
			'imported',
			array(
				'when' => 'all',
				'ie'   => $stats['events'],
				'il'   => $stats['locations'],
				'is'   => $stats['shows'],
				'idu'  => $stats['duplicates'],
				'inl'  => $stats['no_location'],
				'ik'   => $stats['skipped'],
			)
		);
	}

	private function posted_text( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/** Devuelve Y-m-d o null. */
	private function posted_date( $key ) {
		$v = $this->posted_text( $key );
		$d = DateTime::createFromFormat( '!Y-m-d', $v );
		return ( $d && $d->format( 'Y-m-d' ) === $v ) ? $v : null;
	}

	/** Devuelve H:i:s o null. */
	private function posted_time( $key ) {
		$v = $this->posted_text( $key );
		if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v ) ) {
			return strlen( $v ) === 5 ? $v . ':00' : $v;
		}
		return null;
	}

	/* =====================================================================
	 * Utilidades de pintado
	 * ================================================================== */

	private function current_action() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( $this->import_failed ) {
			return 'import';
		}
		if ( null !== $this->posted ) {
			return $this->posted['id'] ? 'edit' : 'new';
		}
		return $action;
	}

	private function notices() {
		$messages = array(
			'added'   => array( 'success', __( 'Item added.', 'wp-events-calendar' ) ),
			'updated' => array( 'success', __( 'Changes saved.', 'wp-events-calendar' ) ),
			'deleted' => array( 'success', __( 'Item deleted.', 'wp-events-calendar' ) ),
			'in_use'  => array( 'error', __( 'It cannot be deleted: there are events using it. Change or delete those events first.', 'wp-events-calendar' ) ),
			'import_expired' => array( 'warning', __( 'The import preview has expired. Please upload the file again.', 'wp-events-calendar' ) ),
		);
		$msg = isset( $_GET['wpec_msg'] ) ? sanitize_key( wp_unslash( $_GET['wpec_msg'] ) ) : '';
		if ( isset( $messages[ $msg ] ) && null === $this->posted ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $messages[ $msg ][0] ),
				esc_html( $messages[ $msg ][1] )
			);
		}
		if ( 'imported' === $msg ) {
			$this->import_notice();
		}
		if ( 'shows_imported' === $msg ) {
			$this->shows_import_notice();
		}
		if ( $this->errors ) {
			echo '<div class="notice notice-error"><ul>';
			foreach ( $this->errors as $e ) {
				echo '<li>' . esc_html( $e ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	private function page_url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private function row_actions( $page, $id ) {
		$edit   = $this->page_url( $page, array( 'action' => 'edit', 'id' => $id ) );
		$delete = wp_nonce_url(
			$this->page_url( $page, array( 'action' => 'delete', 'id' => $id ) ),
			'wpec_delete_' . $page . '_' . $id
		);
		printf(
			'<a href="%1$s">%2$s</a> | <a href="%3$s" class="wpec-delete" onclick="return confirm(\'%4$s\');">%5$s</a>',
			esc_url( $edit ),
			esc_html__( 'Edit', 'wp-events-calendar' ),
			esc_url( $delete ),
			esc_js( __( 'Are you sure you want to delete it?', 'wp-events-calendar' ) ),
			esc_html__( 'Delete', 'wp-events-calendar' )
		);
	}

	private function header( $page, $title, $show_add = true ) {
		echo '<div class="wrap wpec-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1> ';
		if ( $show_add ) {
			printf(
				'<a href="%s" class="page-title-action">%s</a>',
				esc_url( $this->page_url( $page, array( 'action' => 'new' ) ) ),
				esc_html__( 'Add new', 'wp-events-calendar' )
			);
			if ( in_array( $page, array( 'wpec-events', 'wpec-shows' ), true ) ) {
				printf(
					' <a href="%s" class="page-title-action">%s</a>',
					esc_url( $this->page_url( $page, array( 'action' => 'import' ) ) ),
					esc_html__( 'Import / Export', 'wp-events-calendar' )
				);
			}
		}
		echo '<hr class="wp-header-end">';
		$this->notices();
	}

	private function form_open( $page, $id ) {
		echo '<form method="post" action="' . esc_url( $this->page_url( $page, $id ? array( 'action' => 'edit', 'id' => $id ) : array( 'action' => 'new' ) ) ) . '" class="wpec-form">';
		wp_nonce_field( 'wpec_save_' . $page );
		echo '<input type="hidden" name="wpec_form" value="1">';
		echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
	}

	private function form_close( $page, $id ) {
		echo '</tbody></table>';
		submit_button( $id ? __( 'Save changes', 'wp-events-calendar' ) : __( 'Add', 'wp-events-calendar' ), 'primary', 'submit', false );
		echo ' <a href="' . esc_url( $this->page_url( $page ) ) . '" class="button">' . esc_html__( 'Cancel', 'wp-events-calendar' ) . '</a>';
		echo '</form>';
	}

	private function field_row( $label, $for, $html, $required = false ) {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s%3$s</label></th><td>%4$s</td></tr>',
			esc_attr( $for ),
			esc_html( $label ),
			$required ? ' <span class="wpec-required">*</span>' : '',
			$html // Se construye escapado en cada llamada.
		);
	}

	private function load_item( $getter ) {
		if ( null !== $this->posted ) {
			return (object) $this->posted;
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		return $id ? call_user_func( $getter, $id ) : null;
	}

	/**
	 * Formatea las fechas de un evento según los ajustes de WordPress.
	 */
	public static function format_dates( $start, $end ) {
		$fmt = get_option( 'date_format' );
		$out = date_i18n( $fmt, strtotime( $start ) );
		if ( $end && $end !== $start ) {
			$out .= ' – ' . date_i18n( $fmt, strtotime( $end ) );
		}
		return $out;
	}

	public static function format_time( $time ) {
		return $time ? date_i18n( get_option( 'time_format' ), strtotime( '1970-01-01 ' . $time ) ) : '';
	}

	/* =====================================================================
	 * Filtros de los listados
	 * ================================================================== */

	/**
	 * Lee un filtro de la URL.
	 *
	 * @param string $key  Nombre del parámetro.
	 * @param string $type text|int|date.
	 */
	private function filter_value( $key, $type = 'text' ) {
		if ( ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'int' === $type ? 0 : '';
		}
		$raw = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_array( $raw ) ) {
			return 'int' === $type ? 0 : '';
		}
		switch ( $type ) {
			case 'int':
				return absint( $raw );
			case 'date':
				$raw = sanitize_text_field( $raw );
				$d   = DateTime::createFromFormat( '!Y-m-d', $raw );
				return ( $d && $d->format( 'Y-m-d' ) === $raw ) ? $raw : '';
			default:
				return sanitize_text_field( $raw );
		}
	}

	/**
	 * Abre el formulario de filtros (GET) de un listado.
	 *
	 * @param string $page   Slug de la página.
	 * @param array  $hidden Parámetros que se conservan al filtrar.
	 */
	private function filter_bar_open( $page, $hidden = array() ) {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="wpec-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		foreach ( $hidden as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
	}

	/**
	 * Cierra el formulario de filtros con el buscador y los botones.
	 *
	 * @param string $page   Slug de la página.
	 * @param bool   $active Hay algún filtro aplicado.
	 * @param int    $count  Resultados encontrados.
	 */
	private function filter_bar_close( $page, $active, $count ) {
		printf(
			'<span class="wpec-filter wpec-filter--search"><label for="f_s" class="screen-reader-text">%1$s</label><input type="search" id="f_s" name="f_s" value="%2$s" placeholder="%1$s"></span>',
			esc_attr__( 'Search…', 'wp-events-calendar' ),
			esc_attr( $this->filter_value( 'f_s' ) )
		);
		echo '<span class="wpec-filter wpec-filter--actions">';
		submit_button( __( 'Filter', 'wp-events-calendar' ), 'secondary', '', false );
		if ( $active ) {
			echo ' <a href="' . esc_url( $this->page_url( $page ) ) . '" class="button-link wpec-clear">' . esc_html__( 'Clear filters', 'wp-events-calendar' ) . '</a>';
		}
		echo '</span></form>';
		if ( $active ) {
			/* translators: %d: number of results */
			echo '<p class="wpec-results">' . esc_html( sprintf( _n( '%d result', '%d results', $count, 'wp-events-calendar' ), $count ) ) . '</p>';
		}
	}

	/**
	 * <select> de filtro.
	 *
	 * @param string $name     Parámetro.
	 * @param string $label    Etiqueta (también opción "todos").
	 * @param array  $options  Lista de array( value, label, attrs ).
	 * @param string $selected Valor seleccionado.
	 */
	private function filter_select( $name, $label, $options, $selected ) {
		echo '<span class="wpec-filter">';
		echo '<label for="' . esc_attr( $name ) . '" class="screen-reader-text">' . esc_html( $label ) . '</label>';
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html( $label ) . '</option>';
		foreach ( $options as $o ) {
			$attrs = '';
			if ( ! empty( $o[2] ) ) {
				foreach ( $o[2] as $k => $v ) {
					$attrs .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
				}
			}
			printf( '<option value="%s"%s%s>%s</option>', esc_attr( $o[0] ), selected( (string) $selected, (string) $o[0], false ), $attrs, esc_html( $o[1] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</select></span>';
	}

	/* =====================================================================
	 * Página: Eventos
	 * ================================================================== */

	public function page_events() {
		$action = $this->current_action();
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			$this->form_event();
			return;
		}
		if ( 'import' === $action ) {
			$this->page_import();
			return;
		}

		$filters = array(
			'show_id'     => $this->filter_value( 'f_show', 'int' ),
			'location_id' => $this->filter_value( 'f_location', 'int' ),
			'date_from'   => $this->filter_value( 'f_from', 'date' ),
			'date_to'     => $this->filter_value( 'f_to', 'date' ),
			'search'      => $this->filter_value( 'f_s' ),
		);
		$active = (bool) array_filter( $filters );

		// Con un rango de fechas, por defecto se buscan todos los eventos (no solo los próximos).
		$default_when = ( $filters['date_from'] || $filters['date_to'] ) ? 'all' : 'upcoming';
		$when         = isset( $_GET['when'] ) ? sanitize_key( wp_unslash( $_GET['when'] ) ) : $default_when;
		$when         = in_array( $when, array( 'upcoming', 'past', 'all' ), true ) ? $when : $default_when;
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$args         = array_merge(
			$filters,
			array(
				'when'   => $when,
				'order'  => 'past' === $when ? 'DESC' : 'ASC',
				'limit'  => self::PER_PAGE,
				'offset' => ( $paged - 1 ) * self::PER_PAGE,
			)
		);
		$total  = WPEC_DB::count_events( $args );
		$events = WPEC_DB::query_events( $args );

		// Parámetros de filtro que se conservan en las pestañas.
		$filter_query = array_filter(
			array(
				'f_show'     => $filters['show_id'],
				'f_location' => $filters['location_id'],
				'f_from'     => $filters['date_from'],
				'f_to'       => $filters['date_to'],
				'f_s'        => $filters['search'],
			)
		);

		$this->header( 'wpec-events', __( 'Events', 'wp-events-calendar' ) );

		$views = array(
			'upcoming' => __( 'Upcoming', 'wp-events-calendar' ),
			'past'     => __( 'Past', 'wp-events-calendar' ),
			'all'      => __( 'All', 'wp-events-calendar' ),
		);
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $views as $key => $label ) {
			$links[] = sprintf(
				'<li><a href="%s"%s>%s <span class="count">(%d)</span></a></li>',
				esc_url( $this->page_url( 'wpec-events', array_merge( $filter_query, array( 'when' => $key ) ) ) ),
				$key === $when ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				WPEC_DB::count_events( array_merge( $filters, array( 'when' => $key ) ) )
			);
		}
		echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</ul>';

		// Barra de filtros.
		$this->filter_bar_open( 'wpec-events', isset( $_GET['when'] ) ? array( 'when' => $when ) : array() );
		$show_opts = array();
		foreach ( WPEC_DB::get_shows() as $sh ) {
			$show_opts[] = array( $sh->id, $sh->name );
		}
		$this->filter_select( 'f_show', __( 'All shows', 'wp-events-calendar' ), $show_opts, $filters['show_id'] );
		$loc_opts = array();
		foreach ( WPEC_DB::get_locations() as $l ) {
			$loc_opts[] = array( $l->id, self::location_label( $l ) );
		}
		$this->filter_select( 'f_location', __( 'All locations', 'wp-events-calendar' ), $loc_opts, $filters['location_id'] );
		printf(
			'<span class="wpec-filter wpec-filter--dates"><label for="f_from">%1$s</label> <input type="date" id="f_from" name="f_from" value="%2$s"> <label for="f_to">%3$s</label> <input type="date" id="f_to" name="f_to" value="%4$s"></span>',
			esc_html__( 'From', 'wp-events-calendar' ),
			esc_attr( $filters['date_from'] ),
			esc_html__( 'to', 'wp-events-calendar' ),
			esc_attr( $filters['date_to'] )
		);
		$this->filter_bar_close( 'wpec-events', $active, $total );

		echo '<table class="wp-list-table widefat fixed striped wpec-table"><thead><tr>';
		echo '<th class="column-date">' . esc_html__( 'Date', 'wp-events-calendar' ) . '</th>';
		echo '<th class="column-time">' . esc_html__( 'Time', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Show', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Location', 'wp-events-calendar' ) . '</th>';
		echo '<th class="column-actions">' . esc_html__( 'Actions', 'wp-events-calendar' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $events ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No events found.', 'wp-events-calendar' ) . '</td></tr>';
		}
		foreach ( $events as $e ) {
			echo '<tr>';
			echo '<td>' . esc_html( self::format_dates( $e->start_date, $e->end_date ) ) . '</td>';
			echo '<td>' . ( $e->event_time ? esc_html( self::format_time( $e->event_time ) ) : '<span class="wpec-muted">—</span>' ) . '</td>';
			echo '<td>' . esc_html( $e->show_name ? $e->show_name : __( '(show deleted)', 'wp-events-calendar' ) ) . '</td>';
			echo '<td>' . esc_html( self::location_label( $e ) ) . '</td>';
			echo '<td>';
			$this->row_actions( 'wpec-events', $e->id );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$this->pagination( $total, $paged );
		echo '</div>';
	}

	public static function location_label( $l ) {
		if ( empty( $l->venue ) ) {
			return __( '(location deleted)', 'wp-events-calendar' );
		}
		$parts = array_filter( array( $l->venue, $l->municipality, $l->province ) );
		return implode( ', ', $parts );
	}

	private function pagination( $total, $paged ) {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			)
		);
		echo '</div></div>';
	}

	private function form_event() {
		$item      = $this->load_item( array( 'WPEC_DB', 'get_event' ) );
		$id        = $item && ! empty( $item->id ) ? (int) $item->id : 0;
		$locations = WPEC_DB::get_locations();
		$shows     = WPEC_DB::get_shows();

		$this->header( 'wpec-events', $id ? __( 'Edit event', 'wp-events-calendar' ) : __( 'New event', 'wp-events-calendar' ), false );

		if ( ! $locations || ! $shows ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: 1: link to locations, 2: link to shows */
				esc_html__( 'Before creating an event you need at least one %1$s and one %2$s.', 'wp-events-calendar' ),
				'<a href="' . esc_url( $this->page_url( 'wpec-locations', array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'location', 'wp-events-calendar' ) . '</a>',
				'<a href="' . esc_url( $this->page_url( 'wpec-shows', array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'show', 'wp-events-calendar' ) . '</a>'
			);
			echo '</p></div>';
		}

		$v = function ( $key ) use ( $item ) {
			return $item && isset( $item->$key ) ? (string) $item->$key : '';
		};

		$this->form_open( 'wpec-events', $id );

		$this->field_row(
			__( 'Start date', 'wp-events-calendar' ),
			'start_date',
			'<input type="date" id="start_date" name="start_date" value="' . esc_attr( $v( 'start_date' ) ) . '" required>',
			true
		);
		$this->field_row(
			__( 'End date', 'wp-events-calendar' ),
			'end_date',
			'<input type="date" id="end_date" name="end_date" value="' . esc_attr( $v( 'end_date' ) ) . '">'
			. '<p class="description">' . esc_html__( 'Leave it empty if the event lasts a single day.', 'wp-events-calendar' ) . '</p>'
		);
		$this->field_row(
			__( 'Time', 'wp-events-calendar' ),
			'event_time',
			'<input type="time" id="event_time" name="event_time" value="' . esc_attr( substr( $v( 'event_time' ), 0, 5 ) ) . '">'
			. '<p class="description">' . esc_html__( 'Optional. If left empty it will not be shown in the list.', 'wp-events-calendar' ) . '</p>'
		);

		$opts = '<option value="">' . esc_html__( '— Select —', 'wp-events-calendar' ) . '</option>';
		foreach ( $locations as $l ) {
			$opts .= sprintf( '<option value="%d"%s>%s</option>', $l->id, selected( $v( 'location_id' ), (string) $l->id, false ), esc_html( self::location_label( $l ) ) );
		}
		$this->field_row(
			__( 'Location', 'wp-events-calendar' ),
			'location_id',
			'<select id="location_id" name="location_id" required>' . $opts . '</select>',
			true
		);

		$opts = '<option value="">' . esc_html__( '— Select —', 'wp-events-calendar' ) . '</option>';
		foreach ( $shows as $s ) {
			$opts .= sprintf( '<option value="%d"%s>%s</option>', $s->id, selected( $v( 'show_id' ), (string) $s->id, false ), esc_html( $s->name ) );
		}
		$this->field_row(
			__( 'Show', 'wp-events-calendar' ),
			'show_id',
			'<select id="show_id" name="show_id" required>' . $opts . '</select>',
			true
		);

		$this->form_close( 'wpec-events', $id );
		echo '</div>';
	}

	/* =====================================================================
	 * Página: Localizaciones
	 * ================================================================== */

	public function page_locations() {
		$action = $this->current_action();
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			$this->form_location();
			return;
		}

		$filters = array(
			'province'     => $this->filter_value( 'f_province' ),
			'municipality' => $this->filter_value( 'f_municipality' ),
			'search'       => $this->filter_value( 'f_s' ),
		);
		$items = WPEC_DB::get_locations( $filters );

		$this->header( 'wpec-locations', __( 'Locations', 'wp-events-calendar' ) );

		$this->filter_bar_open( 'wpec-locations' );
		$opts = array();
		foreach ( WPEC_DB::get_provinces() as $prov ) {
			$opts[] = array( $prov, $prov );
		}
		$this->filter_select( 'f_province', __( 'All provinces', 'wp-events-calendar' ), $opts, $filters['province'] );
		$opts = array();
		$seen = array();
		foreach ( WPEC_DB::get_municipalities() as $m ) {
			// Un mismo ayuntamiento puede aparecer con provincias distintas; se agrupan sus provincias.
			if ( isset( $seen[ $m['municipality'] ] ) ) {
				$opts[ $seen[ $m['municipality'] ] ][2]['data-province'] .= '|' . $m['province'];
				continue;
			}
			$seen[ $m['municipality'] ] = count( $opts );
			$opts[]                     = array( $m['municipality'], $m['municipality'], array( 'data-province' => $m['province'] ) );
		}
		$this->filter_select( 'f_municipality', __( 'All municipalities', 'wp-events-calendar' ), $opts, $filters['municipality'] );
		$this->filter_bar_close( 'wpec-locations', (bool) array_filter( $filters ), count( $items ) );

		echo '<table class="wp-list-table widefat fixed striped wpec-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Province', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Municipality', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Venue', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Address', 'wp-events-calendar' ) . '</th>';
		echo '<th class="column-actions">' . esc_html__( 'Actions', 'wp-events-calendar' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $items ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No locations found.', 'wp-events-calendar' ) . '</td></tr>';
		}
		foreach ( $items as $l ) {
			echo '<tr>';
			echo '<td>' . esc_html( $l->province ) . '</td>';
			echo '<td>' . esc_html( $l->municipality ) . '</td>';
			echo '<td><strong>' . esc_html( $l->venue ) . '</strong></td>';
			echo '<td>' . esc_html( $l->address ) . '</td>';
			echo '<td>';
			$this->row_actions( 'wpec-locations', $l->id );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function form_location() {
		$item = $this->load_item( array( 'WPEC_DB', 'get_location' ) );
		$id   = $item && ! empty( $item->id ) ? (int) $item->id : 0;

		$this->header( 'wpec-locations', $id ? __( 'Edit location', 'wp-events-calendar' ) : __( 'New location', 'wp-events-calendar' ), false );
		$this->form_open( 'wpec-locations', $id );

		$fields = array(
			'province'     => array( __( 'Province', 'wp-events-calendar' ), true ),
			'municipality' => array( __( 'Municipality', 'wp-events-calendar' ), true ),
			'venue'        => array( __( 'Venue name', 'wp-events-calendar' ), true ),
			'address'      => array( __( 'Address', 'wp-events-calendar' ), false ),
		);
		foreach ( $fields as $key => $f ) {
			$this->field_row(
				$f[0],
				$key,
				sprintf(
					'<input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s"%3$s>',
					esc_attr( $key ),
					esc_attr( $item && isset( $item->$key ) ? $item->$key : '' ),
					$f[1] ? ' required' : ''
				),
				$f[1]
			);
		}

		$this->form_close( 'wpec-locations', $id );
		echo '</div>';
	}

	/* =====================================================================
	 * Página: Espectáculos
	 * ================================================================== */

	public function page_shows() {
		$action = $this->current_action();
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			$this->form_show();
			return;
		}
		if ( 'import' === $action ) {
			$this->page_shows_import();
			return;
		}

		$filters = array(
			'id'     => $this->filter_value( 'f_show', 'int' ),
			'search' => $this->filter_value( 'f_s' ),
		);
		$items = WPEC_DB::get_shows( $filters );

		$this->header( 'wpec-shows', __( 'Shows', 'wp-events-calendar' ) );

		$this->filter_bar_open( 'wpec-shows' );
		$opts = array();
		foreach ( WPEC_DB::get_shows() as $sh ) {
			$opts[] = array( $sh->id, $sh->name );
		}
		$this->filter_select( 'f_show', __( 'All shows', 'wp-events-calendar' ), $opts, $filters['id'] );
		$this->filter_bar_close( 'wpec-shows', (bool) array_filter( $filters ), count( $items ) );

		echo '<table class="wp-list-table widefat fixed striped wpec-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'URL', 'wp-events-calendar' ) . '</th>';
		echo '<th class="column-actions">' . esc_html__( 'Actions', 'wp-events-calendar' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $items ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No shows found.', 'wp-events-calendar' ) . '</td></tr>';
		}
		foreach ( $items as $s ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $s->name ) . '</strong></td>';
			echo '<td>' . ( $s->url ? '<a href="' . esc_url( $s->url ) . '" target="_blank" rel="noopener">' . esc_html( $s->url ) . '</a>' : '<span class="wpec-muted">—</span>' ) . '</td>';
			echo '<td>';
			$this->row_actions( 'wpec-shows', $s->id );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function form_show() {
		$item = $this->load_item( array( 'WPEC_DB', 'get_show' ) );
		$id   = $item && ! empty( $item->id ) ? (int) $item->id : 0;

		$this->header( 'wpec-shows', $id ? __( 'Edit show', 'wp-events-calendar' ) : __( 'New show', 'wp-events-calendar' ), false );
		$this->form_open( 'wpec-shows', $id );

		$this->field_row(
			__( 'Show name', 'wp-events-calendar' ),
			'name',
			'<input type="text" class="regular-text" id="name" name="name" value="' . esc_attr( $item ? $item->name : '' ) . '" required>',
			true
		);

		// Sugerencias con las páginas publicadas de la web.
		$pages    = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title' ) );
		$datalist = '<datalist id="wpec-pages">';
		foreach ( $pages as $p ) {
			$datalist .= '<option value="' . esc_attr( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</option>';
		}
		$datalist .= '</datalist>';

		$this->field_row(
			__( 'URL', 'wp-events-calendar' ),
			'url',
			'<input type="url" class="large-text" id="url" name="url" list="wpec-pages" placeholder="https://" value="' . esc_attr( $item ? $item->url : '' ) . '">'
			. $datalist
			. '<p class="description">' . esc_html__( 'Page of the show on this site. Start typing or double-click to see the published pages.', 'wp-events-calendar' ) . '</p>'
		);

		$this->form_close( 'wpec-shows', $id );
		echo '</div>';
	}

	/* =====================================================================
	 * Página: Importar eventos
	 * ================================================================== */

	private function import_notice() {
		$c = array();
		foreach ( array( 'ie', 'il', 'is', 'idu', 'inl', 'ik' ) as $k ) {
			$c[ $k ] = isset( $_GET[ $k ] ) ? absint( $_GET[ $k ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$lines = array(
			/* translators: %d: number */
			sprintf( _n( '%d event imported.', '%d events imported.', $c['ie'], 'wp-events-calendar' ), $c['ie'] ),
			/* translators: %d: number */
			sprintf( _n( '%d new location.', '%d new locations.', $c['il'], 'wp-events-calendar' ), $c['il'] ),
			/* translators: %d: number */
			sprintf( _n( '%d new show.', '%d new shows.', $c['is'], 'wp-events-calendar' ), $c['is'] ),
		);
		if ( $c['idu'] ) {
			/* translators: %d: number */
			$lines[] = sprintf( _n( '%d event already existed and was skipped.', '%d events already existed and were skipped.', $c['idu'], 'wp-events-calendar' ), $c['idu'] );
		}
		if ( $c['inl'] ) {
			/* translators: %d: number */
			$lines[] = sprintf( _n( '%d event without a location was not imported.', '%d events without a location were not imported.', $c['inl'], 'wp-events-calendar' ), $c['inl'] );
		}
		if ( $c['ik'] ) {
			/* translators: %d: number */
			$lines[] = sprintf( _n( '%d event was skipped as you chose.', '%d events were skipped as you chose.', $c['ik'], 'wp-events-calendar' ), $c['ik'] );
		}
		echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Import completed.', 'wp-events-calendar' ) . '</strong></p><ul>';
		foreach ( $lines as $l ) {
			echo '<li>' . esc_html( $l ) . '</li>';
		}
		echo '</ul></div>';
	}

	private function page_import() {
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data = 'preview' === $step && ! $this->import_failed ? get_transient( WPEC_Importer::transient_key() ) : false;

		$this->header( 'wpec-events', __( 'Import / Export', 'wp-events-calendar' ), false );

		if ( ! $data ) {
			$this->import_upload_form();
			$this->export_form();
			echo '<p><a href="' . esc_url( $this->page_url( 'wpec-events' ) ) . '">&larr; ' . esc_html__( 'Back to events', 'wp-events-calendar' ) . '</a></p>';
		} else {
			$this->import_preview( $data );
		}
		echo '</div>';
	}

	private function import_upload_form() {
		echo '<div class="wpec-card">';
		echo '<h2>' . esc_html__( 'Import', 'wp-events-calendar' ) . '</h2>';
		echo '<p>' . esc_html__( 'Upload an XML file with events (<item> elements with <ID>, <title>, <date> and <locations>), such as one exported from this plugin. You will see a preview before importing.', 'wp-events-calendar' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( $this->page_url( 'wpec-events', array( 'action' => 'import' ) ) ) . '">';
		wp_nonce_field( 'wpec_import_upload' );
		echo '<p><input type="file" name="wpec_file" accept=".xml,text/xml,application/xml" required></p>';
		submit_button( __( 'Upload and preview', 'wp-events-calendar' ), 'primary', 'wpec_import_upload', false );
		echo '</form></div>';
	}

	private function export_form() {
		$events    = WPEC_DB::count_events( array( 'when' => 'all' ) );
		$locations = count( WPEC_DB::get_locations() );
		$shows     = count( WPEC_DB::get_shows() );

		echo '<div class="wpec-card">';
		echo '<h2>' . esc_html__( 'Export', 'wp-events-calendar' ) . '</h2>';
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'There is nothing to export yet.', 'wp-events-calendar' ) . '</p></div>';
			return;
		}
		echo '<p>';
		printf(
			/* translators: 1: events, 2: locations, 3: shows */
			esc_html__( 'Download an XML file with all the events (%1$d), locations (%2$d) and shows (%3$d). You can import it later on this or another site.', 'wp-events-calendar' ),
			(int) $events,
			(int) $locations,
			(int) $shows
		);
		echo '</p>';
		echo '<form method="post" action="' . esc_url( $this->page_url( 'wpec-events', array( 'action' => 'import' ) ) ) . '">';
		wp_nonce_field( 'wpec_export' );
		submit_button( __( 'Download XML', 'wp-events-calendar' ), 'secondary', 'wpec_export', false );
		echo '</form></div>';
	}

	private function import_preview( $data ) {
		$shows          = WPEC_DB::get_shows();
		$existing_shows = WPEC_Importer::existing_shows();
		$existing_locs  = WPEC_Importer::existing_locations();

		$no_loc = 0;
		$multi  = 0;
		foreach ( $data['events'] as $e ) {
			if ( ! $e['locs'] ) {
				$no_loc++;
			} elseif ( count( $e['locs'] ) > 1 ) {
				$multi++;
			}
		}
		$new_locs = array_diff_key( $data['locations'], $existing_locs );

		echo '<div class="notice notice-info inline"><p>';
		printf(
			/* translators: 1: events, 2: titles, 3: locations, 4: new locations */
			esc_html__( 'The file contains %1$d events with %2$d different titles and %3$d locations (%4$d do not exist on the site and will be created).', 'wp-events-calendar' ),
			count( $data['events'] ),
			count( $data['titles'] ),
			count( $data['locations'] ),
			count( $new_locs )
		);
		echo '</p>';
		if ( $no_loc ) {
			/* translators: %d: number */
			echo '<p>' . esc_html( sprintf( _n( '%d event has no location and will not be imported.', '%d events have no location and will not be imported.', $no_loc, 'wp-events-calendar' ), $no_loc ) ) . '</p>';
		}
		if ( $multi ) {
			/* translators: %d: number */
			echo '<p>' . esc_html( sprintf( _n( '%d event has several locations: one event will be created for each.', '%d events have several locations: one event will be created for each.', $multi, 'wp-events-calendar' ), $multi ) ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Events that already exist (same date, location and show) will be skipped.', 'wp-events-calendar' ) . '</p>';
		echo '</div>';

		echo '<form method="post" action="' . esc_url( $this->page_url( 'wpec-events', array( 'action' => 'import', 'step' => 'preview' ) ) ) . '">';
		wp_nonce_field( 'wpec_import_run' );

		// Títulos → espectáculos.
		echo '<h2>' . esc_html__( 'Shows', 'wp-events-calendar' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The XML has no shows: choose which show each title belongs to, or create a new one.', 'wp-events-calendar' ) . '</p>';
		echo '<table class="wp-list-table widefat fixed striped wpec-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Title in the XML', 'wp-events-calendar' ) . '</th>';
		echo '<th class="column-count">' . esc_html__( 'Events', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Show', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Name if new', 'wp-events-calendar' ) . '</th>';
		echo '</tr></thead><tbody>';

		$i = 0;
		foreach ( $data['titles'] as $title => $count ) {
			$suggested = WPEC_Importer::suggested_show_name( $title, $data );
			$match     = isset( $existing_shows[ WPEC_Importer::normalize( $suggested ) ] ) ? $existing_shows[ WPEC_Importer::normalize( $suggested ) ] : ( isset( $existing_shows[ WPEC_Importer::normalize( $title ) ] ) ? $existing_shows[ WPEC_Importer::normalize( $title ) ] : 0 );

			$opts  = '<option value="new"' . selected( $match, 0, false ) . '>' . esc_html__( '+ Create new', 'wp-events-calendar' ) . '</option>';
			$opts .= '<option value="skip">' . esc_html__( '✕ Do not import', 'wp-events-calendar' ) . '</option>';
			foreach ( $shows as $s ) {
				$opts .= sprintf( '<option value="%d"%s>%s</option>', $s->id, selected( $match, (int) $s->id, false ), esc_html( $s->name ) );
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( $title ) . '</strong></td>';
			echo '<td>' . (int) $count . '</td>';
			echo '<td><select name="wpec_map[' . (int) $i . ']" class="wpec-map">' . $opts . '</select></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><input type="text" class="regular-text" name="wpec_name[' . (int) $i . ']" value="' . esc_attr( $suggested ) . '"></td>';
			echo '</tr>';
			$i++;
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'If several titles get the same new name (e.g. “Cortello de amor 2024” and “Cortello de amor 2025” → “Cortello de amor”), a single show will be created.', 'wp-events-calendar' ) . '</p>';

		// Localizaciones.
		echo '<h2>' . esc_html__( 'Locations', 'wp-events-calendar' ) . '</h2>';
		echo '<table class="wp-list-table widefat fixed striped wpec-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Province', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Municipality', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Venue', 'wp-events-calendar' ) . '</th>';
		echo '<th class="column-status">' . esc_html__( 'Status', 'wp-events-calendar' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $data['locations'] as $key => $l ) {
			$exists = isset( $existing_locs[ $key ] );
			echo '<tr>';
			echo '<td>' . ( '' !== $l['province'] ? esc_html( $l['province'] ) : '<span class="wpec-muted">—</span>' ) . '</td>';
			echo '<td>' . esc_html( $l['municipality'] ) . '</td>';
			echo '<td>' . esc_html( $l['venue'] ) . '</td>';
			echo '<td>' . ( $exists
				? '<span class="wpec-badge-status wpec-badge-status--exists">' . esc_html__( 'Already exists', 'wp-events-calendar' ) . '</span>'
				: '<span class="wpec-badge-status wpec-badge-status--new">' . esc_html__( 'Will be created', 'wp-events-calendar' ) . '</span>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<p class="submit">';
		submit_button( __( 'Import', 'wp-events-calendar' ), 'primary', 'wpec_import_run', false );
		echo ' <a href="' . esc_url( $this->page_url( 'wpec-events', array( 'action' => 'import' ) ) ) . '" class="button">' . esc_html__( 'Upload another file', 'wp-events-calendar' ) . '</a>';
		echo '</p></form>';
	}

	/* =====================================================================
	 * Página: Shortcodes
	 * ================================================================== */

	private static function when_labels() {
		return array(
			'upcoming' => __( 'Upcoming', 'wp-events-calendar' ),
			'past'     => __( 'Past', 'wp-events-calendar' ),
			'all'      => __( 'All', 'wp-events-calendar' ),
		);
	}

	public function page_shortcodes() {
		$action = $this->current_action();
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			$this->form_shortcode();
			return;
		}

		$this->header( 'wpec-shortcodes', __( 'Shortcodes', 'wp-events-calendar' ) );
		echo '<p class="description">' . esc_html__( 'Create shortcodes with your own name and settings, and paste them into any page or post.', 'wp-events-calendar' ) . '</p>';

		$items  = WPEC_DB::get_shortcodes();
		$labels = self::when_labels();

		echo '<table class="wp-list-table widefat fixed striped wpec-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Shortcode', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Events', 'wp-events-calendar' ) . '</th>';
		echo '<th>' . esc_html__( 'Maximum', 'wp-events-calendar' ) . '</th>';
		echo '<th class="column-actions">' . esc_html__( 'Actions', 'wp-events-calendar' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $items ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No shortcodes found.', 'wp-events-calendar' ) . '</td></tr>';
		}
		foreach ( $items as $sc ) {
			$code = '[' . $sc->tag . ']';
			echo '<tr>';
			echo '<td><strong>' . esc_html( $sc->name ) . '</strong></td>';
			echo '<td><span class="wpec-sc-code"><code class="wpec-code">' . esc_html( $code ) . '</code> ';
			echo '<button type="button" class="button button-small wpec-copy" data-copy="' . esc_attr( $code ) . '" data-done="' . esc_attr__( 'Copied!', 'wp-events-calendar' ) . '">' . esc_html__( 'Copy', 'wp-events-calendar' ) . '</button></span>';
			if ( ! $this->shortcode_is_ours( $sc->tag ) ) {
				echo '<br><span class="wpec-warning">' . esc_html__( 'Another plugin or the theme uses this shortcode, so it will not show the events. Change it.', 'wp-events-calendar' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( isset( $labels[ $sc->show_when ] ) ? $labels[ $sc->show_when ] : $sc->show_when ) . '</td>';
			echo '<td>' . ( $sc->max_events ? (int) $sc->max_events : esc_html__( 'No limit', 'wp-events-calendar' ) ) . '</td>';
			echo '<td>';
			$this->row_actions( 'wpec-shortcodes', $sc->id );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * ¿La etiqueta está registrada por este plugin (y no por otro)?
	 */
	private function shortcode_is_ours( $tag ) {
		return in_array( $tag, WPEC_Shortcode::$registered, true );
	}

	private function form_shortcode() {
		$item = $this->load_item( array( 'WPEC_DB', 'get_shortcode' ) );
		$id   = $item && ! empty( $item->id ) ? (int) $item->id : 0;
		$v    = function ( $key, $default = '' ) use ( $item ) {
			return $item && isset( $item->$key ) ? (string) $item->$key : $default;
		};

		$this->header( 'wpec-shortcodes', $id ? __( 'Edit shortcode', 'wp-events-calendar' ) : __( 'New shortcode', 'wp-events-calendar' ), false );
		$this->form_open( 'wpec-shortcodes', $id );

		$this->field_row(
			__( 'Name', 'wp-events-calendar' ),
			'name',
			'<input type="text" class="regular-text" id="name" name="name" value="' . esc_attr( $v( 'name' ) ) . '" required>'
			. '<p class="description">' . esc_html__( 'For example: Upcoming performances.', 'wp-events-calendar' ) . '</p>',
			true
		);
		$this->field_row(
			__( 'Shortcode', 'wp-events-calendar' ),
			'tag',
			'<code>[</code><input type="text" class="regular-text code" id="tag" name="tag" value="' . esc_attr( $v( 'tag' ) ) . '" pattern="[A-Za-z0-9_]*"><code>]</code>'
			. '<p class="description">' . esc_html__( 'Leave it empty to create it from the name. Only letters, numbers and underscores.', 'wp-events-calendar' ) . '</p>'
		);

		$radios = '';
		foreach ( self::when_labels() as $key => $label ) {
			$radios .= sprintf(
				'<label class="wpec-radio"><input type="radio" name="show_when" value="%s"%s> %s</label>',
				esc_attr( $key ),
				checked( $v( 'show_when', 'upcoming' ), $key, false ),
				esc_html( $label )
			);
		}
		$this->field_row( __( 'Events to show', 'wp-events-calendar' ), 'show_when', '<fieldset>' . $radios . '</fieldset>' );

		$this->field_row(
			__( 'Maximum number of events', 'wp-events-calendar' ),
			'max_events',
			'<input type="number" class="small-text" id="max_events" name="max_events" min="0" step="1" value="' . esc_attr( $v( 'max_events', '0' ) ) . '">'
			. '<p class="description">' . esc_html__( '0 = no limit.', 'wp-events-calendar' ) . '</p>'
		);

		$this->form_close( 'wpec-shortcodes', $id );
		echo '</div>';
	}

	/* =====================================================================
	 * Página: Espectáculos → Importar / Exportar
	 * ================================================================== */

	private function shows_import_notice() {
		$c = array();
		foreach ( array( 'sc', 'su', 'se' ) as $k ) {
			$c[ $k ] = isset( $_GET[ $k ] ) ? absint( $_GET[ $k ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$lines = array(
			/* translators: %d: number */
			sprintf( _n( '%d new show.', '%d new shows.', $c['sc'], 'wp-events-calendar' ), $c['sc'] ),
		);
		if ( $c['su'] ) {
			/* translators: %d: number */
			$lines[] = sprintf( _n( '%d existing show without URL now has the URL from the file.', '%d existing shows without URL now have the URL from the file.', $c['su'], 'wp-events-calendar' ), $c['su'] );
		}
		if ( $c['se'] ) {
			/* translators: %d: number */
			$lines[] = sprintf( _n( '%d show already existed and was not changed.', '%d shows already existed and were not changed.', $c['se'], 'wp-events-calendar' ), $c['se'] );
		}
		echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Import completed.', 'wp-events-calendar' ) . '</strong></p><ul>';
		foreach ( $lines as $l ) {
			echo '<li>' . esc_html( $l ) . '</li>';
		}
		echo '</ul></div>';
	}

	private function page_shows_import() {
		$this->header( 'wpec-shows', __( 'Shows', 'wp-events-calendar' ) . ': ' . __( 'Import / Export', 'wp-events-calendar' ), false );

		// Importar.
		echo '<div class="wpec-card">';
		echo '<h2>' . esc_html__( 'Import', 'wp-events-calendar' ) . '</h2>';
		echo '<p>' . esc_html__( 'Upload an XML file with shows (<show> elements with <name> and <url>), such as one exported from this screen. Shows that do not exist are created; existing ones are not changed, except to add the URL if they had none.', 'wp-events-calendar' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( $this->page_url( 'wpec-shows', array( 'action' => 'import' ) ) ) . '">';
		wp_nonce_field( 'wpec_shows_import' );
		echo '<p><input type="file" name="wpec_file" accept=".xml,text/xml,application/xml" required></p>';
		submit_button( __( 'Import', 'wp-events-calendar' ), 'primary', 'wpec_shows_import', false );
		echo '</form></div>';

		// Exportar.
		$count = count( WPEC_DB::get_shows() );
		echo '<div class="wpec-card">';
		echo '<h2>' . esc_html__( 'Export', 'wp-events-calendar' ) . '</h2>';
		if ( ! $count ) {
			echo '<p>' . esc_html__( 'There is nothing to export yet.', 'wp-events-calendar' ) . '</p></div>';
		} else {
			/* translators: %d: number of shows */
			echo '<p>' . esc_html( sprintf( _n( 'Download an XML file with the only show (name and URL).', 'Download an XML file with all %d shows (name and URL).', $count, 'wp-events-calendar' ), $count ) ) . '</p>';
			echo '<form method="post" action="' . esc_url( $this->page_url( 'wpec-shows', array( 'action' => 'import' ) ) ) . '">';
			wp_nonce_field( 'wpec_shows_export' );
			submit_button( __( 'Download XML', 'wp-events-calendar' ), 'secondary', 'wpec_shows_export', false );
			echo '</form></div>';
		}

		echo '<p><a href="' . esc_url( $this->page_url( 'wpec-shows' ) ) . '">&larr; ' . esc_html__( 'Back to shows', 'wp-events-calendar' ) . '</a></p>';
		echo '</div>';
	}
}
