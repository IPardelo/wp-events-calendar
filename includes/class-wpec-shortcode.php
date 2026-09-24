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
 *   filtro        si | no: filtro de espectáculo, provincia y fecha encima de la lista
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
				'filtro'  => empty( $sc->show_filter ) ? 'no' : 'si',
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
		wp_register_style( 'wpec-public', WPEC_URL . 'assets/css/wpec-public.css', array(), wpec_asset_version( 'assets/css/wpec-public.css' ) );
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
		'filter'       => 'filtro',
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
				'filtro'       => 'no',
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

		$args = array(
			'when'         => $when,
			'order'        => $order,
			'limit'        => absint( $atts['limite'] ),
			'show_id'      => absint( $atts['espectaculo'] ),
			'location_id'  => absint( $atts['localizacion'] ),
			'province'     => sanitize_text_field( $atts['provincia'] ),
			'municipality' => sanitize_text_field( $atts['ayuntamiento'] ),
		);

		wp_enqueue_style( 'wpec-public' );

		// Filtro público: sus opciones salen de los eventos que mostraría el shortcode sin filtrar.
		$filter_html = '';
		$filtered    = false;
		if ( self::is_yes( $atts['filtro'] ) ) {
			$pool = WPEC_DB::query_events( array_merge( $args, array( 'limit' => 0 ) ) );
			if ( $pool ) {
				$filter      = $this->filter_state( $pool, $args );
				$args        = array_merge( $args, $filter['args'] );
				$filtered    = (bool) $filter['args'];
				$filter_html = $this->render_filter( $filter );
			}
		}

		$events = WPEC_DB::query_events( $args );

		if ( ! $events ) {
			$message = $filtered ? __( 'No events match the filter.', 'wp-events-calendar' ) : $atts['vacio'];
			return '<div class="wpec-events wpec-events--empty">' . $filter_html . '<p class="wpec-empty">' . esc_html( $message ) . '</p></div>';
		}

		$html = '<div class="wpec-events">' . $filter_html . '<ul class="wpec-list">';
		foreach ( $events as $event ) {
			$html .= apply_filters( 'wpec_event_html', $this->render_event( $event ), $event, $atts );
		}
		$html .= '</ul></div>';

		return $html;
	}

	/** @var int Número de filtros pintados en la página (para no mezclar sus parámetros). */
	private static $filter_count = 0;

	private static function is_yes( $value ) {
		return in_array( strtolower( remove_accents( trim( (string) $value ) ) ), array( '1', 'si', 'yes', 'true', 'on' ), true );
	}

	/**
	 * Lee de la URL los valores del filtro de esta instancia y prepara sus opciones.
	 * Solo se aceptan valores que existan entre las opciones.
	 *
	 * @param array $pool Eventos del shortcode sin filtrar.
	 * @param array $args Argumentos fijos del shortcode.
	 * @return array
	 */
	private function filter_state( $pool, $args ) {
		self::$filter_count++;
		$prefix = 1 === self::$filter_count ? 'ev_' : 'ev' . self::$filter_count . '_';
		$names  = array(
			'show'     => $prefix . 'espectaculo',
			'province' => $prefix . 'provincia',
			'from'     => $prefix . 'dende',
			'to'       => $prefix . 'ata',
		);

		// Opciones: solo lo que no fija ya el propio shortcode.
		$shows     = array();
		$provinces = array();
		foreach ( $pool as $e ) {
			if ( $e->show_id && $e->show_name ) {
				$shows[ (int) $e->show_id ] = $e->show_name;
			}
			if ( '' !== (string) $e->province ) {
				$provinces[ $e->province ] = $e->province;
			}
		}
		if ( $args['show_id'] ) {
			$shows = array();
		}
		if ( '' !== $args['province'] || '' !== $args['municipality'] || $args['location_id'] ) {
			$provinces = array();
		}
		natcasesort( $shows );
		natcasesort( $provinces );

		$values = array();
		foreach ( $names as $key => $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
			$values[ $key ] = isset( $_GET[ $name ] ) && ! is_array( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
		}
		if ( ! isset( $shows[ (int) $values['show'] ] ) ) {
			$values['show'] = '';
		}
		if ( ! isset( $provinces[ $values['province'] ] ) ) {
			$values['province'] = '';
		}
		foreach ( array( 'from', 'to' ) as $key ) {
			$d = DateTime::createFromFormat( '!Y-m-d', $values[ $key ] );
			if ( ! $d || $d->format( 'Y-m-d' ) !== $values[ $key ] ) {
				$values[ $key ] = '';
			}
		}

		$query = array_filter(
			array(
				'show_id'   => (int) $values['show'],
				'province'  => $values['province'],
				'date_from' => $values['from'],
				'date_to'   => $values['to'],
			)
		);

		return array(
			'id'        => 'wpec-filter-' . self::$filter_count,
			'names'     => $names,
			'values'    => $values,
			'shows'     => $shows,
			'provinces' => $provinces,
			'args'      => $query,
		);
	}

	/**
	 * HTML del formulario de filtro (GET a la misma página).
	 */
	private function render_filter( $f ) {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$id   = $f['id'];

		// Se conservan los demás parámetros de la URL (p. ej. ?page_id=12 sin enlaces permanentes).
		$query = array();
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		$keep   = array();
		$hidden = '';
		foreach ( $query as $key => $value ) {
			if ( in_array( $key, $f['names'], true ) || is_array( $value ) ) {
				continue;
			}
			$keep[ $key ] = rawurlencode( $value );
			$hidden      .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		$clear = add_query_arg( $keep, $path ) . '#' . $id;

		$html  = '<form class="wpec-filter" id="' . esc_attr( $id ) . '" method="get" action="' . esc_url( $path . '#' . $id ) . '">' . $hidden;
		$html .= $this->filter_select( $f['names']['show'], __( 'Show', 'wp-events-calendar' ), __( 'All shows', 'wp-events-calendar' ), $f['shows'], $f['values']['show'] );
		$html .= $this->filter_select( $f['names']['province'], __( 'Province', 'wp-events-calendar' ), __( 'All provinces', 'wp-events-calendar' ), $f['provinces'], $f['values']['province'] );

		$html .= '<fieldset class="wpec-filter__field wpec-filter__dates"><legend class="wpec-filter__label">' . esc_html__( 'Date', 'wp-events-calendar' ) . '</legend>';
		$html .= sprintf(
			'<label><span class="wpec-filter__sub">%1$s</span> <input type="date" name="%2$s" value="%3$s"></label> <label><span class="wpec-filter__sub">%4$s</span> <input type="date" name="%5$s" value="%6$s"></label>',
			esc_html__( 'From', 'wp-events-calendar' ),
			esc_attr( $f['names']['from'] ),
			esc_attr( $f['values']['from'] ),
			esc_html__( 'to', 'wp-events-calendar' ),
			esc_attr( $f['names']['to'] ),
			esc_attr( $f['values']['to'] )
		);
		$html .= '</fieldset>';

		$html .= '<div class="wpec-filter__actions"><button type="submit" class="wpec-filter__submit">' . esc_html__( 'Filter', 'wp-events-calendar' ) . '</button>';
		if ( $f['args'] ) {
			$html .= ' <a class="wpec-filter__clear" href="' . esc_url( $clear ) . '">' . esc_html__( 'Clear filters', 'wp-events-calendar' ) . '</a>';
		}
		$html .= '</div></form>';

		return $html;
	}

	/**
	 * <select> del filtro público. Si no hay opciones (o solo una) no se pinta.
	 */
	private function filter_select( $name, $label, $all, $options, $selected ) {
		if ( count( $options ) < 2 && '' === (string) $selected ) {
			return '';
		}
		$html  = '<label class="wpec-filter__field"><span class="wpec-filter__label">' . esc_html( $label ) . '</span>';
		$html .= '<select name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $all ) . '</option>';
		foreach ( $options as $value => $text ) {
			$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( (string) $selected, (string) $value, false ), esc_html( $text ) );
		}
		return $html . '</select></label>';
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

		// Espectáculo (enlazado si tiene URL) con el círculo de su color delante.
		$name = $e->show_name ? $e->show_name : '';
		if ( $name && $e->show_url ) {
			$show = '<a href="' . esc_url( $e->show_url ) . '">' . esc_html( $name ) . '</a>';
		} else {
			$show = esc_html( $name );
		}
		if ( $show ) {
			$show = WPEC_Admin::color_dot( isset( $e->show_color ) ? $e->show_color : '' ) . $show;
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
