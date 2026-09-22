<?php
/**
 * Exportación de todos los eventos a XML.
 *
 * El formato es el mismo que lee el importador (<item> con <ID>, <title>, <date> y
 * <locations>), con campos adicionales para no perder datos al volver a importar:
 *   - <show><name/><url/></show>                 espectáculo con su URL.
 *   - <province/>, <municipality/>, <venue/>, <street/> en cada localización.
 *   - <catalog> con todas las localizaciones y espectáculos, aunque no tengan eventos.
 *
 * @package WP_Events_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPEC_Exporter {

	/**
	 * Genera el XML con todos los eventos.
	 *
	 * @return string
	 */
	public static function build_xml() {
		$doc               = new DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;

		$root = $doc->createElement( 'events' );
		$root->setAttribute( 'generator', 'WP Events Calendar ' . WPEC_VERSION );
		$root->setAttribute( 'exported', gmdate( 'c' ) );
		$doc->appendChild( $root );

		foreach ( WPEC_DB::query_events( array( 'when' => 'all', 'order' => 'DESC' ) ) as $e ) {
			$item = self::el( $doc, $root, 'item' );
			self::el( $doc, $item, 'ID', $e->id );
			self::el( $doc, $item, 'title', (string) $e->show_name );

			// Fechas.
			$date = self::el( $doc, $item, 'date' );
			self::time_block( $doc, $date, 'start', $e->start_date, $e->event_time );
			self::time_block( $doc, $date, 'end', $e->end_date ? $e->end_date : $e->start_date, $e->event_time ? self::plus_hour( $e->event_time ) : null );
			if ( ! $e->event_time ) {
				self::el( $doc, $date, 'hide_time', '1' );
			}

			// Localización.
			$locs = self::el( $doc, $item, 'locations' );
			if ( $e->venue ) {
				$loc  = self::el( $doc, $locs, 'item' );
				$name = $e->province ? $e->municipality . ', ' . $e->province : $e->municipality;
				self::el( $doc, $loc, 'id', $e->location_id );
				self::el( $doc, $loc, 'name', $name );
				self::el( $doc, $loc, 'address', $e->venue );
				self::el( $doc, $loc, 'province', $e->province );
				self::el( $doc, $loc, 'municipality', $e->municipality );
				self::el( $doc, $loc, 'venue', $e->venue );
				self::el( $doc, $loc, 'street', $e->address );
			}

			// Espectáculo.
			if ( $e->show_name ) {
				$show = self::el( $doc, $item, 'show' );
				self::el( $doc, $show, 'id', $e->show_id );
				self::el( $doc, $show, 'name', $e->show_name );
				self::el( $doc, $show, 'url', (string) $e->show_url );
			}
		}

		// Catálogo completo: también las localizaciones y espectáculos que no tienen eventos.
		$catalog = self::el( $doc, $root, 'catalog' );
		$locs    = self::el( $doc, $catalog, 'locations' );
		foreach ( WPEC_DB::get_locations() as $l ) {
			$loc = self::el( $doc, $locs, 'item' );
			self::el( $doc, $loc, 'id', $l->id );
			self::el( $doc, $loc, 'name', $l->province ? $l->municipality . ', ' . $l->province : $l->municipality );
			self::el( $doc, $loc, 'address', $l->venue );
			self::el( $doc, $loc, 'province', $l->province );
			self::el( $doc, $loc, 'municipality', $l->municipality );
			self::el( $doc, $loc, 'venue', $l->venue );
			self::el( $doc, $loc, 'street', $l->address );
		}
		$shows = self::el( $doc, $catalog, 'shows' );
		foreach ( WPEC_DB::get_shows() as $s ) {
			$show = self::el( $doc, $shows, 'show' );
			self::el( $doc, $show, 'id', $s->id );
			self::el( $doc, $show, 'name', $s->name );
			self::el( $doc, $show, 'url', $s->url );
		}

		return $doc->saveXML();
	}

	/**
	 * XML solo con los espectáculos (Espectáculos → Importar / Exportar).
	 *
	 * @return string
	 */
	public static function build_shows_xml() {
		$doc               = new DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;

		$root = $doc->createElement( 'shows' );
		$root->setAttribute( 'generator', 'WP Events Calendar ' . WPEC_VERSION );
		$root->setAttribute( 'exported', gmdate( 'c' ) );
		$doc->appendChild( $root );

		foreach ( WPEC_DB::get_shows() as $s ) {
			$show = self::el( $doc, $root, 'show' );
			self::el( $doc, $show, 'id', $s->id );
			self::el( $doc, $show, 'name', $s->name );
			self::el( $doc, $show, 'url', $s->url );
		}

		return $doc->saveXML();
	}

	/**
	 * Envía el XML como descarga y termina.
	 *
	 * @param string $what all|shows.
	 */
	public static function download( $what = 'all' ) {
		if ( 'shows' === $what ) {
			$xml      = self::build_shows_xml();
			$filename = 'wpec-shows-' . current_time( 'Ymd-His' ) . '.xml';
		} else {
			$xml      = self::build_xml();
			$filename = 'wpec-events-' . current_time( 'Ymd-His' ) . '.xml';
		}

		nocache_headers();
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $xml ) );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function el( DOMDocument $doc, DOMNode $parent, $name, $value = null ) {
		$node = $doc->createElement( $name );
		if ( null !== $value && '' !== (string) $value ) {
			$node->appendChild( $doc->createTextNode( (string) $value ) );
		}
		$parent->appendChild( $node );
		return $node;
	}

	/**
	 * <start>/<end> con fecha y hora en formato de 12 h.
	 */
	private static function time_block( DOMDocument $doc, DOMNode $parent, $name, $date, $time ) {
		$block = self::el( $doc, $parent, $name );
		self::el( $doc, $block, 'date', $date );
		if ( $time ) {
			list( $h, $m ) = array_map( 'intval', explode( ':', $time ) );
			self::el( $doc, $block, 'hour', ( $h % 12 ) ? $h % 12 : 12 );
			self::el( $doc, $block, 'minutes', $m );
			self::el( $doc, $block, 'ampm', $h >= 12 ? 'PM' : 'AM' );
		}
	}

	private static function plus_hour( $time ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $time ) );
		return sprintf( '%02d:%02d:00', min( 23, $h + 1 ), $m );
	}
}
