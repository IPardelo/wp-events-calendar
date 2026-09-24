<?php
/**
 * Acceso a datos: tablas propias para eventos, localizaciones y espectáculos.
 *
 * @package WP_Events_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPEC_DB {

	/**
	 * Nombre completo de una tabla del plugin.
	 *
	 * @param string $name events|locations|shows.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpec_' . $name;
	}

	/**
	 * Crea o actualiza las tablas.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$shows     = self::table( 'shows' );
		$locations = self::table( 'locations' );
		$events    = self::table( 'events' );
		$codes     = self::table( 'shortcodes' );

		dbDelta(
			"CREATE TABLE $shows (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(255) NOT NULL,
				url varchar(2048) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY name (name(191))
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $locations (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				province varchar(100) NOT NULL DEFAULT '',
				municipality varchar(150) NOT NULL DEFAULT '',
				venue varchar(255) NOT NULL,
				address varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY province (province)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $events (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				start_date date NOT NULL,
				end_date date DEFAULT NULL,
				event_time time DEFAULT NULL,
				location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				show_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY start_date (start_date),
				KEY location_id (location_id),
				KEY show_id (show_id)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $codes (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				tag varchar(64) NOT NULL,
				show_when varchar(10) NOT NULL DEFAULT 'upcoming',
				max_events int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY tag (tag)
			) $charset;"
		);

		update_option( 'wpec_db_version', WPEC_DB_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * Espectáculos
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $args { @type int $id, @type string $search (nombre y URL) }
	 */
	public static function get_shows( $args = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['id'] ) ) {
			$where[]  = 'id = %d';
			$params[] = (int) $args['id'];
		}
		if ( isset( $args['search'] ) && '' !== trim( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where[]  = '(name LIKE %s OR url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$sql = 'SELECT * FROM ' . self::table( 'shows' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY name ASC';
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function get_show( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'shows' ) . ' WHERE id = %d', $id ) );
	}

	public static function save_show( $data, $id = 0 ) {
		global $wpdb;
		$row = array(
			'name' => $data['name'],
			'url'  => $data['url'],
		);
		if ( $id ) {
			$wpdb->update( self::table( 'shows' ), $row, array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
			return $id;
		}
		$wpdb->insert( self::table( 'shows' ), $row, array( '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/* ---------------------------------------------------------------------
	 * Localizaciones
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $args { @type string $province, @type string $municipality, @type string $search (todas las columnas) }
	 */
	public static function get_locations( $args = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'province', 'municipality' ) as $col ) {
			if ( isset( $args[ $col ] ) && '' !== $args[ $col ] ) {
				$where[]  = "$col = %s";
				$params[] = $args[ $col ];
			}
		}
		if ( isset( $args['search'] ) && '' !== trim( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where[]  = '(province LIKE %s OR municipality LIKE %s OR venue LIKE %s OR address LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		$sql = 'SELECT * FROM ' . self::table( 'locations' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY province ASC, municipality ASC, venue ASC';
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Provincias registradas (sin repetir).
	 *
	 * @return string[]
	 */
	public static function get_provinces() {
		global $wpdb;
		return $wpdb->get_col( 'SELECT DISTINCT province FROM ' . self::table( 'locations' ) . " WHERE province <> '' ORDER BY province ASC" );
	}

	/**
	 * Ayuntamientos registrados con su provincia (para filtrar el desplegable según la provincia).
	 *
	 * @return array[] Lista de array( 'municipality' => string, 'province' => string ).
	 */
	public static function get_municipalities() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT DISTINCT municipality, province FROM ' . self::table( 'locations' ) . " WHERE municipality <> '' ORDER BY municipality ASC", ARRAY_A );
	}

	public static function get_location( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'locations' ) . ' WHERE id = %d', $id ) );
	}

	public static function save_location( $data, $id = 0 ) {
		global $wpdb;
		$row = array(
			'province'     => $data['province'],
			'municipality' => $data['municipality'],
			'venue'        => $data['venue'],
			'address'      => $data['address'],
		);
		$fmt = array( '%s', '%s', '%s', '%s' );
		if ( $id ) {
			$wpdb->update( self::table( 'locations' ), $row, array( 'id' => $id ), $fmt, array( '%d' ) );
			return $id;
		}
		$wpdb->insert( self::table( 'locations' ), $row, $fmt );
		return (int) $wpdb->insert_id;
	}

	/* ---------------------------------------------------------------------
	 * Eventos
	 * ------------------------------------------------------------------ */

	public static function get_event( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'events' ) . ' WHERE id = %d', $id ) );
	}

	public static function save_event( $data, $id = 0 ) {
		global $wpdb;
		$row = array(
			'start_date'  => $data['start_date'],
			'end_date'    => $data['end_date'],
			'event_time'  => $data['event_time'],
			'location_id' => $data['location_id'],
			'show_id'     => $data['show_id'],
		);
		$fmt = array( '%s', '%s', '%s', '%d', '%d' );

		// $wpdb convierte null en '' con %s; se guardan los NULL de forma explícita.
		$nulls = array();
		foreach ( array( 'end_date', 'event_time' ) as $col ) {
			if ( null === $row[ $col ] ) {
				$nulls[] = $col;
			}
		}

		if ( $id ) {
			$wpdb->update( self::table( 'events' ), $row, array( 'id' => $id ), $fmt, array( '%d' ) );
		} else {
			$wpdb->insert( self::table( 'events' ), $row, $fmt );
			$id = (int) $wpdb->insert_id;
		}

		foreach ( $nulls as $col ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'events' ) . " SET $col = NULL WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $id;
	}

	/**
	 * Consulta de eventos con los datos de localización y espectáculo.
	 *
	 * @param array $args {
	 *     @type string $when         upcoming|past|all.
	 *     @type string $order        ASC|DESC.
	 *     @type int    $limit        0 = sin límite.
	 *     @type int    $offset
	 *     @type int    $show_id
	 *     @type int    $location_id
	 *     @type string $province
	 *     @type string $municipality
	 * }
	 * @return array
	 */
	public static function query_events( $args = array() ) {
		global $wpdb;

		list( $where, $params ) = self::events_where( $args );

		$order = ( isset( $args['order'] ) && 'DESC' === strtoupper( $args['order'] ) ) ? 'DESC' : 'ASC';
		$sql   = 'SELECT e.*, s.name AS show_name, s.url AS show_url,
				l.province, l.municipality, l.venue, l.address
			FROM ' . self::table( 'events' ) . ' e
			LEFT JOIN ' . self::table( 'shows' ) . ' s ON s.id = e.show_id
			LEFT JOIN ' . self::table( 'locations' ) . " l ON l.id = e.location_id
			WHERE $where
			ORDER BY e.start_date $order, (e.event_time IS NULL) ASC, e.event_time $order, e.id $order";

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		}

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count_events( $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::events_where( $args );
		$sql = 'SELECT COUNT(*) FROM ' . self::table( 'events' ) . ' e
			LEFT JOIN ' . self::table( 'shows' ) . ' s ON s.id = e.show_id
			LEFT JOIN ' . self::table( 'locations' ) . " l ON l.id = e.location_id
			WHERE $where";
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Construye el WHERE compartido por query_events y count_events.
	 *
	 * @return array [ string $where, array $params ]
	 */
	private static function events_where( $args ) {
		$where  = array( '1=1' );
		$params = array();
		$today  = current_time( 'Y-m-d' );
		$when   = isset( $args['when'] ) ? $args['when'] : 'all';

		if ( 'upcoming' === $when ) {
			$where[]  = 'COALESCE(e.end_date, e.start_date) >= %s';
			$params[] = $today;
		} elseif ( 'past' === $when ) {
			$where[]  = 'COALESCE(e.end_date, e.start_date) < %s';
			$params[] = $today;
		}

		if ( ! empty( $args['show_id'] ) ) {
			$where[]  = 'e.show_id = %d';
			$params[] = (int) $args['show_id'];
		}
		if ( ! empty( $args['location_id'] ) ) {
			$where[]  = 'e.location_id = %d';
			$params[] = (int) $args['location_id'];
		}
		if ( ! empty( $args['province'] ) ) {
			$where[]  = 'l.province = %s';
			$params[] = $args['province'];
		}
		if ( ! empty( $args['municipality'] ) ) {
			$where[]  = 'l.municipality = %s';
			$params[] = $args['municipality'];
		}

		// Rango de fechas: eventos que se solapan con [date_from, date_to].
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'COALESCE(e.end_date, e.start_date) >= %s';
			$params[] = $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'e.start_date <= %s';
			$params[] = $args['date_to'];
		}

		// Búsqueda de texto en todas las columnas visibles del evento.
		if ( isset( $args['search'] ) && '' !== trim( $args['search'] ) ) {
			global $wpdb;
			$like    = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$cols    = array( 'e.start_date', 'e.end_date', 'e.event_time', 's.name', 's.url', 'l.province', 'l.municipality', 'l.venue', 'l.address' );
			$where[] = '(' . implode( ' OR ', array_map( function ( $c ) { return "$c LIKE %s"; }, $cols ) ) . ')';
			$params  = array_merge( $params, array_fill( 0, count( $cols ), $like ) );
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * ¿Existe ya un evento con la misma fecha, localización y espectáculo?
	 */
	public static function event_exists( $start_date, $location_id, $show_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table( 'events' ) . ' WHERE start_date = %s AND location_id = %d AND show_id = %d LIMIT 1',
				$start_date,
				$location_id,
				$show_id
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Shortcodes personalizados
	 * ------------------------------------------------------------------ */

	public static function get_shortcodes() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table( 'shortcodes' ) . ' ORDER BY name ASC' );
	}

	public static function get_shortcode( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'shortcodes' ) . ' WHERE id = %d', $id ) );
	}

	public static function get_shortcode_by_tag( $tag ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'shortcodes' ) . ' WHERE tag = %s', $tag ) );
	}

	public static function save_shortcode( $data, $id = 0 ) {
		global $wpdb;
		$row = array(
			'name'       => $data['name'],
			'tag'        => $data['tag'],
			'show_when'  => $data['show_when'],
			'max_events' => (int) $data['max_events'],
		);
		$fmt = array( '%s', '%s', '%s', '%d' );
		if ( $id ) {
			$wpdb->update( self::table( 'shortcodes' ), $row, array( 'id' => $id ), $fmt, array( '%d' ) );
			return $id;
		}
		$wpdb->insert( self::table( 'shortcodes' ), $row, $fmt );
		return (int) $wpdb->insert_id;
	}

	/* ---------------------------------------------------------------------
	 * Comunes
	 * ------------------------------------------------------------------ */

	public static function delete( $table, $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table( $table ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Número de eventos que usan una localización o un espectáculo.
	 *
	 * @param string $column location_id|show_id.
	 * @param int    $id
	 * @return int
	 */
	public static function count_events_using( $column, $id ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'location_id', 'show_id' ), true ) ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'events' ) . " WHERE $column = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
