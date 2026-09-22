<?php
/**
 * Importación de eventos desde un XML (<item> con <ID>, <title>, <date> y <locations>),
 * como el que genera la exportación del propio plugin.
 *
 * Flujo en dos pasos:
 *   1. Se sube el fichero, se analiza y se guarda el resultado en un transient.
 *   2. Vista previa: se asigna cada título a un espectáculo (existente o nuevo),
 *      se ven las localizaciones que se crearán y se confirma la importación.
 *
 * @package WP_Events_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPEC_Importer {

	const MAX_SIZE = 5242880; // 5 MB.

	/**
	 * Clave del transient con los datos analizados del usuario actual.
	 */
	public static function transient_key() {
		return 'wpec_import_' . get_current_user_id();
	}

	/* =====================================================================
	 * Análisis del XML
	 * ================================================================== */

	/**
	 * Analiza el fichero subido.
	 *
	 * @param string $path Ruta temporal del fichero.
	 * @return array|WP_Error { events: array, locations: array, titles: array }
	 */
	public static function parse_file( $path ) {
		$xml = self::load_xml( $path );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}
		if ( ! isset( $xml->item ) ) {
			return new WP_Error( 'items', __( 'No events (<item>) were found in the XML.', 'wp-events-calendar' ) );
		}

		$events    = array();
		$locations = array();
		$titles    = array();
		$shows     = array(); // título => array( 'url' => string ) cuando el XML trae <show> (exportado por este plugin).

		foreach ( $xml->item as $item ) {
			$title = self::clean_text( (string) $item->title );
			if ( isset( $item->show->name ) && '' !== trim( (string) $item->show->name ) ) {
				$title           = self::clean_text( (string) $item->show->name );
				$shows[ $title ] = array( 'url' => esc_url_raw( trim( (string) $item->show->url ) ) );
			}
			$dates = self::date_node( $item );
			if ( ! $dates ) {
				continue;
			}

			$start = self::valid_date( (string) $dates->start->date );
			if ( ! $start ) {
				continue;
			}
			$end = self::valid_date( (string) $dates->end->date );
			if ( ! $end || $end <= $start ) {
				$end = null;
			}

			$hidden = '1' === trim( (string) $dates->hide_time ) || '1' === trim( (string) $dates->allday );
			$time   = $hidden ? null : self::to_24h( (string) $dates->start->hour, (string) $dates->start->minutes, (string) $dates->start->ampm );

			$loc_keys = array();
			if ( isset( $item->locations->item ) ) {
				foreach ( $item->locations->item as $loc ) {
					$parsed = self::parse_location( $loc );
					if ( ! $parsed ) {
						continue;
					}
					$key = self::location_key( $parsed['municipality'], $parsed['venue'] );
					if ( ! isset( $locations[ $key ] ) ) {
						$locations[ $key ] = $parsed;
					}
					$loc_keys[] = $key;
				}
			}

			$titles[ $title ] = isset( $titles[ $title ] ) ? $titles[ $title ] + 1 : 1;

			$events[] = array(
				'xml_id' => (string) $item->ID,
				'title'  => $title,
				'start'  => $start,
				'end'    => $end,
				'time'   => $time,
				'locs'   => array_values( array_unique( $loc_keys ) ),
			);
		}

		// Catálogo (exportado por este plugin): localizaciones y espectáculos sin eventos.
		if ( isset( $xml->catalog ) ) {
			if ( isset( $xml->catalog->locations->item ) ) {
				foreach ( $xml->catalog->locations->item as $loc ) {
					$parsed = self::parse_location( $loc );
					if ( $parsed ) {
						$key = self::location_key( $parsed['municipality'], $parsed['venue'] );
						if ( ! isset( $locations[ $key ] ) ) {
							$locations[ $key ] = $parsed;
						}
					}
				}
			}
			if ( isset( $xml->catalog->shows->show ) ) {
				foreach ( $xml->catalog->shows->show as $show ) {
					$name = self::clean_text( (string) $show->name );
					if ( '' === $name ) {
						continue;
					}
					if ( ! isset( $titles[ $name ] ) ) {
						$titles[ $name ] = 0; // Espectáculo sin eventos.
					}
					if ( ! isset( $shows[ $name ] ) ) {
						$shows[ $name ] = array( 'url' => esc_url_raw( trim( (string) $show->url ) ) );
					}
				}
			}
		}

		if ( ! $events ) {
			return new WP_Error( 'none', __( 'No event with a valid date was found.', 'wp-events-calendar' ) );
		}

		ksort( $titles, SORT_NATURAL | SORT_FLAG_CASE );
		uasort(
			$locations,
			function ( $a, $b ) {
				return strcasecmp( $a['province'] . $a['municipality'] . $a['venue'], $b['province'] . $b['municipality'] . $b['venue'] );
			}
		);

		return array(
			'events'    => $events,
			'locations' => $locations,
			'titles'    => $titles,
			'shows'     => $shows,
		);
	}

	/**
	 * Nodo <date> con las fechas del evento.
	 */
	private static function date_node( $item ) {
		return ( isset( $item->date ) && isset( $item->date->start ) ) ? $item->date : null;
	}

	/**
	 * Convierte una localización del XML (name = "Concello, Provincia", address = local)
	 * al formato del plugin.
	 */
	private static function parse_location( $loc ) {
		// Formato propio del plugin (exportado por WPEC_Exporter): campos explícitos.
		if ( isset( $loc->venue ) && '' !== trim( (string) $loc->venue ) ) {
			$venue = self::clean_text( (string) $loc->venue );
			return array(
				'source_id'       => (string) $loc->id,
				'province'     => self::clean_text( (string) $loc->province ),
				'municipality' => '' !== trim( (string) $loc->municipality ) ? self::clean_text( (string) $loc->municipality ) : $venue,
				'venue'        => $venue,
				'address'      => self::clean_text( (string) $loc->street ),
			);
		}

		$name    = self::clean_text( (string) $loc->name );
		$address = self::clean_text( (string) $loc->address );
		if ( '' === $name && '' === $address ) {
			return null;
		}

		$municipality = $name;
		$province     = '';
		$pos          = strrpos( $name, ',' );
		if ( false !== $pos ) {
			$municipality = trim( substr( $name, 0, $pos ) );
			$province     = trim( substr( $name, $pos + 1 ) );
		}

		return array(
			'source_id'       => (string) $loc->id,
			'province'     => $province,
			'municipality' => $municipality,
			'venue'        => '' !== $address ? $address : $municipality,
			'address'      => '',
		);
	}

	private static function clean_text( $s ) {
		// Algunos títulos vienen doblemente escapados (&amp;#8211;): se decodifica dos veces.
		$s = html_entity_decode( html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $s ) ) );
	}

	private static function valid_date( $s ) {
		$s = trim( $s );
		$d = DateTime::createFromFormat( '!Y-m-d', $s );
		return ( $d && $d->format( 'Y-m-d' ) === $s ) ? $s : null;
	}

	private static function to_24h( $hour, $minutes, $ampm ) {
		if ( '' === trim( $hour ) ) {
			return null;
		}
		$h = (int) $hour;
		$m = (int) $minutes;
		$a = strtoupper( trim( $ampm ) );
		if ( 'PM' === $a && $h < 12 ) {
			$h += 12;
		} elseif ( 'AM' === $a && 12 === $h ) {
			$h = 0;
		}
		if ( $h < 0 || $h > 23 || $m < 0 || $m > 59 ) {
			return null;
		}
		return sprintf( '%02d:%02d:00', $h, $m );
	}

	/**
	 * Importa solo espectáculos (nombre y URL) desde un XML.
	 * Lee cualquier <show> con <name>: el XML de Espectáculos → Exportar
	 * y también la exportación completa de eventos.
	 *
	 * - Si el espectáculo no existe (mismo nombre, sin mayúsculas ni acentos), se crea.
	 * - Si ya existe y no tiene URL, se le añade la del XML.
	 * - Si ya existe con URL, no se toca.
	 *
	 * @param string $path Ruta del fichero.
	 * @return array|WP_Error { created, updated, existing }
	 */
	public static function import_shows_file( $path ) {
		$xml = self::load_xml( $path );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$nodes = $xml->xpath( '//show[name]' );
		if ( ! $nodes ) {
			return new WP_Error( 'shows', __( 'No shows (<show> with <name>) were found in the XML.', 'wp-events-calendar' ) );
		}

		$stats    = array(
			'created'  => 0,
			'updated'  => 0,
			'existing' => 0,
		);
		$existing = array();
		foreach ( WPEC_DB::get_shows() as $s ) {
			$existing[ self::normalize( $s->name ) ] = $s;
		}

		$seen = array();
		foreach ( $nodes as $node ) {
			$name = self::clean_text( (string) $node->name );
			$url  = esc_url_raw( trim( (string) $node->url ) );
			$norm = self::normalize( $name );
			if ( '' === $name || isset( $seen[ $norm ] ) ) {
				continue; // Vacío o repetido dentro del propio fichero.
			}
			$seen[ $norm ] = true;

			if ( isset( $existing[ $norm ] ) ) {
				$show = $existing[ $norm ];
				if ( '' === (string) $show->url && '' !== $url ) {
					WPEC_DB::save_show( array( 'name' => $show->name, 'url' => $url ), (int) $show->id );
					$stats['updated']++;
				} else {
					$stats['existing']++;
				}
				continue;
			}

			WPEC_DB::save_show( array( 'name' => $name, 'url' => $url ) );
			$stats['created']++;
		}

		return $stats;
	}

	/**
	 * Lee y valida un fichero XML.
	 *
	 * @return SimpleXMLElement|WP_Error
	 */
	private static function load_xml( $path ) {
		$xml_string = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $xml_string || '' === trim( $xml_string ) ) {
			return new WP_Error( 'empty', __( 'The file is empty.', 'wp-events-calendar' ) );
		}
		if ( preg_match( '/<!DOCTYPE/i', $xml_string ) ) {
			return new WP_Error( 'doctype', __( 'The XML cannot contain a DOCTYPE.', 'wp-events-calendar' ) );
		}

		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $xml_string, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		$errs = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( false === $xml ) {
			$line = $errs ? $errs[0]->line : 0;
			/* translators: %d: line number */
			return new WP_Error( 'xml', sprintf( __( 'The XML is not well-formed (line %d).', 'wp-events-calendar' ), $line ) );
		}
		return $xml;
	}

	/* =====================================================================
	 * Coincidencias con los datos existentes
	 * ================================================================== */

	public static function normalize( $s ) {
		return strtolower( trim( preg_replace( '/\s+/u', ' ', remove_accents( (string) $s ) ) ) );
	}

	public static function location_key( $municipality, $venue ) {
		return self::normalize( $municipality ) . '|' . self::normalize( $venue );
	}

	/**
	 * Mapa clave => id de las localizaciones que ya existen en la web.
	 */
	public static function existing_locations() {
		$map = array();
		foreach ( WPEC_DB::get_locations() as $l ) {
			$map[ self::location_key( $l->municipality, $l->venue ) ] = (int) $l->id;
		}
		return $map;
	}

	/**
	 * Mapa nombre normalizado => id de los espectáculos existentes.
	 */
	public static function existing_shows() {
		$map = array();
		foreach ( WPEC_DB::get_shows() as $s ) {
			$map[ self::normalize( $s->name ) ] = (int) $s->id;
		}
		return $map;
	}

	/**
	 * Nombre de espectáculo sugerido para un título: sin el año
	 * ("Cortello de amor 2025" → "Cortello de amor").
	 */
	public static function suggested_show_name( $title, $data = null ) {
		// Si el XML trae el espectáculo explícito, se respeta tal cual.
		if ( $data && isset( $data['shows'][ $title ] ) ) {
			return $title;
		}
		$name = trim( preg_replace( '/\s+(19|20)\d{2}\b/', '', $title ) );
		return '' !== $name ? $name : $title;
	}

	/* =====================================================================
	 * Importación
	 * ================================================================== */

	/**
	 * Importa los datos analizados.
	 *
	 * @param array $data    Resultado de parse_file().
	 * @param array $mapping título => array( 'action' => 'new'|'skip'|<id>, 'name' => string ).
	 * @return array Contadores.
	 */
	public static function import( $data, $mapping ) {
		$stats = array(
			'events'      => 0,
			'locations'   => 0,
			'shows'       => 0,
			'duplicates'  => 0,
			'no_location' => 0,
			'skipped'     => 0,
		);

		// 1. Espectáculos.
		$shows    = self::existing_shows();
		$title_to = array();
		foreach ( array_keys( $data['titles'] ) as $title ) {
			$m      = isset( $mapping[ $title ] ) ? $mapping[ $title ] : array( 'action' => 'new', 'name' => self::suggested_show_name( $title, $data ) );
			$action = $m['action'];

			if ( 'skip' === $action ) {
				$title_to[ $title ] = 0;
				continue;
			}
			if ( 'new' !== $action && WPEC_DB::get_show( (int) $action ) ) {
				$title_to[ $title ] = (int) $action;
				continue;
			}

			$name = '' !== trim( $m['name'] ) ? trim( $m['name'] ) : self::suggested_show_name( $title, $data );
			$norm = self::normalize( $name );
			if ( ! isset( $shows[ $norm ] ) ) {
				$url            = isset( $data['shows'][ $title ]['url'] ) ? $data['shows'][ $title ]['url'] : '';
				$shows[ $norm ] = WPEC_DB::save_show( array( 'name' => $name, 'url' => $url ) );
				$stats['shows']++;
			}
			$title_to[ $title ] = $shows[ $norm ];
		}

		// 2. Localizaciones (solo las que no existen en la web).
		$locs = self::existing_locations();
		foreach ( $data['locations'] as $key => $l ) {
			if ( isset( $locs[ $key ] ) ) {
				continue;
			}
			$locs[ $key ] = WPEC_DB::save_location( $l );
			$stats['locations']++;
		}

		// 3. Eventos (uno por localización; se omiten los que ya existen).
		foreach ( $data['events'] as $e ) {
			$show_id = isset( $title_to[ $e['title'] ] ) ? $title_to[ $e['title'] ] : 0;
			if ( ! $show_id ) {
				$stats['skipped']++;
				continue;
			}
			if ( ! $e['locs'] ) {
				$stats['no_location']++;
				continue;
			}
			foreach ( $e['locs'] as $key ) {
				$location_id = $locs[ $key ];
				if ( WPEC_DB::event_exists( $e['start'], $location_id, $show_id ) ) {
					$stats['duplicates']++;
					continue;
				}
				WPEC_DB::save_event(
					array(
						'start_date'  => $e['start'],
						'end_date'    => $e['end'],
						'event_time'  => $e['time'],
						'location_id' => $location_id,
						'show_id'     => $show_id,
					)
				);
				$stats['events']++;
			}
		}

		return $stats;
	}
}
