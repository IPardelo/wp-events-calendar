<?php
/**
 * Shortcode [eventos]: lista pública de eventos.
 *
 * Atributos:
 *   mostrar       proximos (por defecto) | pasados | todos
 *   limite        número máximo de eventos (0 = todos)
 *   orden         asc | desc  (por defecto asc para próximos, desc para pasados)
 *   espectaculo   ID de un espectáculo para filtrar
 *   localizacion  ID de una localización para filtrar
 *   provincia     nombre de provincia para filtrar
 *   ayuntamiento  nombre de ayuntamiento para filtrar
 *   vacio         texto cuando no hay eventos
 *
 * @package WP_Events_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPEC_Shortcode {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_shortcode( 'eventos', array( $this, 'render' ) );
		add_shortcode( 'wpec_eventos', array( $this, 'render' ) ); // Alias por si otro plugin usa [eventos].
		add_shortcode( 'wpec_events', array( $this, 'render' ) );  // Alias en inglés.
		add_action( 'init', array( $this, 'register_custom' ), 20 );
	}

	/**
	 * Etiquetas que usa el propio plugin y no se pueden usar para shortcodes personalizados.
	 */
	/** @var string[] Etiquetas personalizadas que registró este plugin. */
	public static $registered = array();

	public static function reserved_tags() {
		return array( 'eventos', 'wpec_eventos', 'wpec_events' );
	}

	/**
	 * Registra los shortcodes creados en Eventos → Shortcodes.
	 * Si otro plugin ya usa la misma etiqueta, no se sobrescribe.
	 */
	public function register_custom() {
		foreach ( WPEC_DB::get_shortcodes() as $sc ) {
			if ( shortcode_exists( $sc->tag ) ) {
				continue;
			}
			self::$registered[] = $sc->tag;
			$defaults = array(
				'mostrar' => $sc->show_when,
				'limite'  => (int) $sc->max_events,
			);
			add_shortcode(
				$sc->tag,
				function ( $atts ) use ( $defaults ) {
					return $this->render( $atts, $defaults );
				}
			);
		}
	}

	public function register_assets() {
		wp_register_style( 'wpec-public', WPEC_URL . 'assets/css/wpec-public.css', array(), WPEC_VERSION );
	}

	/**
	 * Alias de atributos en inglés y gallego => nombre canónico.
	 */
	private static $aliases = array(
		// Inglés.
		'show'         => 'mostrar',
		'limit'        => 'limite',
		'order'        => 'orden',
		'show_id'      => 'espectaculo',
		'location_id'  => 'localizacion',
		'province'     => 'provincia',
		'municipality' => 'ayuntamiento',
		'empty'        => 'vacio',
		// Gallego.
		'amosar'       => 'mostrar',
		'orde'         => 'orden',
		'concello'     => 'ayuntamiento',
		'baleiro'      => 'vacio',
	);

	/**
	 * @param array|string $atts     Atributos del shortcode.
	 * @param array        $defaults Valores por defecto (los usan los shortcodes personalizados).
	 */
	public function render( $atts, $defaults = array() ) {
		$atts = is_array( $atts ) ? $atts : array();
		foreach ( self::$aliases as $alias => $canonical ) {
			if ( isset( $atts[ $alias ] ) && ! isset( $atts[ $canonical ] ) ) {
				$atts[ $canonical ] = $atts[ $alias ];
			}
		}
		foreach ( (array) $defaults as $key => $value ) {
			if ( ! isset( $atts[ $key ] ) ) {
				$atts[ $key ] = $value;
			}
		}

		$atts = shortcode_atts(
			array(
				'mostrar'      => 'proximos',
				'limite'       => 0,
				'orden'        => '',
				'espectaculo'  => 0,
				'localizacion' => 0,
				'provincia'    => '',
				'ayuntamiento' => '',
				'vacio'        => __( 'There are no scheduled events.', 'wp-events-calendar' ),
			),
			$atts,
			'eventos'
		);

		$when_map = array(
			'proximos' => 'upcoming',
			'próximos' => 'upcoming',
			'upcoming' => 'upcoming',
			'pasados'  => 'past',
			'past'     => 'past',
			'todos'    => 'all',
			'all'      => 'all',
		);
		$mostrar = strtolower( trim( $atts['mostrar'] ) );
		$when    = isset( $when_map[ $mostrar ] ) ? $when_map[ $mostrar ] : 'upcoming';

		$order = strtoupper( trim( $atts['orden'] ) );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'past' === $when ? 'DESC' : 'ASC';
		}

		$events = WPEC_DB::query_events(
			array(
				'when'         => $when,
				'order'        => $order,
				'limit'        => absint( $atts['limite'] ),
				'show_id'      => absint( $atts['espectaculo'] ),
				'location_id'  => absint( $atts['localizacion'] ),
				'province'     => sanitize_text_field( $atts['provincia'] ),
				'municipality' => sanitize_text_field( $atts['ayuntamiento'] ),
			)
		);

		wp_enqueue_style( 'wpec-public' );

		if ( ! $events ) {
			return '<div class="wpec-events wpec-events--empty"><p>' . esc_html( $atts['vacio'] ) . '</p></div>';
		}

		$html = '<div class="wpec-events"><ul class="wpec-list">';
		foreach ( $events as $event ) {
			$html .= apply_filters( 'wpec_event_html', $this->render_event( $event ), $event, $atts );
		}
		$html .= '</ul></div>';

		return $html;
	}

	private function render_event( $e ) {
		$start = strtotime( $e->start_date );

		// Bloque de fecha destacado.
		$badge = sprintf(
			'<div class="wpec-badge" aria-hidden="true"><span class="wpec-badge__day">%s</span><span class="wpec-badge__month">%s</span><span class="wpec-badge__year">%s</span></div>',
			esc_html( date_i18n( 'j', $start ) ),
			esc_html( date_i18n( 'M', $start ) ),
			esc_html( date_i18n( 'Y', $start ) )
		);

		// Espectáculo (enlazado si tiene URL).
		$name = $e->show_name ? $e->show_name : '';
		if ( $name && $e->show_url ) {
			$show = '<a href="' . esc_url( $e->show_url ) . '">' . esc_html( $name ) . '</a>';
		} else {
			$show = esc_html( $name );
		}

		// Fecha(s) y hora: la hora solo aparece si está definida.
		$when = '<time datetime="' . esc_attr( $e->start_date ) . '">' . esc_html( WPEC_Admin::format_dates( $e->start_date, $e->end_date ) ) . '</time>';
		if ( $e->event_time ) {
			$when .= ' <span class="wpec-sep">·</span> <span class="wpec-time">' . esc_html( WPEC_Admin::format_time( $e->event_time ) ) . '</span>';
		}

		// Localización.
		$where = '';
		if ( $e->venue ) {
			$place = array_filter( array( $e->municipality, $e->province ? '(' . $e->province . ')' : '' ) );
			$where = '<span class="wpec-venue">' . esc_html( $e->venue ) . '</span>';
			if ( $e->address ) {
				$where .= ' <span class="wpec-sep">—</span> <span class="wpec-address">' . esc_html( $e->address ) . '</span>';
			}
			if ( $place ) {
				$where .= '<span class="wpec-place">' . esc_html( implode( ' ', $place ) ) . '</span>';
			}
		}

		return '<li class="wpec-event">'
			. $badge
			. '<div class="wpec-info">'
			. ( $show ? '<p class="wpec-show">' . $show . '</p>' : '' )
			. '<p class="wpec-when">' . $when . '</p>'
			. ( $where ? '<p class="wpec-where">' . $where . '</p>' : '' )
			. '</div></li>';
	}
}
