<?php
/**
 * Pixelfart-thema. De games zelf (berichttype, opslag, REST) zitten in de plugin Pixelfart Games.
 */

defined( 'ABSPATH' ) || exit;

const PF_THEME_VERSION = '1.6.0';

/** Pixel-bitmaps voor iconen in het thema ('#' = pixel). */
const PF_BMP = [
	'play'     => [ '#.......', '###.....', '#####...', '#######.', '#####...', '###.....', '#.......' ],
	'star'     => [ '...##...', '...##...', '########', '.######.', '..####..', '.##..##.', '##....##' ],
	'fs'       => [ '###..###', '#......#', '#......#', '........', '........', '#......#', '#......#', '###..###' ],
	'bubble'   => [ '########', '#......#', '#......#', '#......#', '########', '##......', '#.......' ],
	'bulb'     => [ '...####...', '.########.', '##########', '##########', '##########', '##########', '.########.', '..######..', '...####...', '...####...' ],
	'bulbHi'   => [ '..........', '..........', '..#.......', '.##.......', '.#........' ],
	'bulbBase' => [ '..######..', '...####...', '..######..', '....##....' ],
	'kLeft'    => [ '..#', '.##', '###', '.##', '..#' ],
	'kRight'   => [ '#..', '##.', '###', '##.', '#..' ],
	'kUp'      => [ '..#..', '.###.', '#####' ],
	'kDown'    => [ '#####', '.###.', '..#..' ],
	'heart'    => [ '.##.##.', '#######', '#######', '.#####.', '..###..', '...#...' ],
	'megaphone' => [ '.......##', '.....####', '#.#######', '#.#######', '#.#######', '.....####', '.......##', '..##.....', '..##.....' ],
	'mail'     => [ '##########', '##......##', '#.#....#.#', '#..#..#..#', '#...##...#', '#........#', '##########' ],
];

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', [ 'style', 'script', 'navigation-widgets' ] );
	add_theme_support( 'responsive-embeds' );

	// Blokeditor: de editor ziet eruit als de site.
	add_theme_support( 'editor-styles' );
	add_editor_style( [ 'style.css', 'assets/content.css' ] );
	remove_theme_support( 'core-block-patterns' ); // alleen onze eigen patronen
} );

// Geen patronen van wordpress.org ophalen (scheelt verzoeken naar buiten).
add_filter( 'should_load_remote_block_patterns', '__return_false' );

// Dit thema heeft "Update URI: false": mu-plugins/bitless-code.php houdt het buiten de updatecheck van wordpress.org.

// Alleen de CSS van blokken die echt op de pagina staan.
add_filter( 'should_load_separate_core_block_assets', '__return_true' );

add_action( 'init', function () {
	register_block_pattern_category( 'pixelfart', [ 'label' => 'Pixelfart' ] );

	$styles = [
		'core/group'     => [ 'pixelkader' => 'Pixelkader', 'streepjes' => 'Streepjes (plaatshouder)' ],
		'core/image'     => [ 'pixelig' => 'Pixelig met kader' ],
		'core/button'    => [ 'spook' => 'Spook (omlijnd)' ],
		'core/separator' => [ 'pixels' => 'Pixels' ],
		'core/list'      => [ 'pixelbolletjes' => 'Pixelbolletjes' ],
	];
	foreach ( $styles as $block => $list ) {
		foreach ( $list as $name => $label ) {
			register_block_style( $block, [ 'name' => $name, 'label' => $label ] );
		}
	}
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'pixelfart', get_stylesheet_uri(), [], PF_THEME_VERSION );
	if ( is_page() || is_single() ) {
		wp_enqueue_style( 'pixelfart-content', get_theme_file_uri( 'assets/content.css' ), [ 'pixelfart' ], PF_THEME_VERSION );
	}
	wp_enqueue_script( 'pixelfart', get_theme_file_uri( 'assets/site.js' ), [], PF_THEME_VERSION, [ 'strategy' => 'defer', 'in_footer' => true ] );
	wp_localize_script( 'pixelfart', 'PF', [
		'rest' => esc_url_raw( rest_url( 'pixelfart/v1/' ) ),
		'home' => esc_url_raw( home_url( '/' ) ),
		'games' => class_exists( 'PF_Hosting' ) ? PF_Hosting::origin() : '',
		// ALTCHA (spamcheck) laden we pas als iemand een formulier gebruikt.
		'altcha' => [
			'js'     => get_theme_file_uri( 'assets/vendor/altcha/altcha.min.js' ) . '?v=3.5.0',
			'css'    => get_theme_file_uri( 'assets/vendor/altcha/altcha.css' ) . '?v=3.5.0',
			'worker' => get_theme_file_uri( 'assets/vendor/altcha/sha.js' ) . '?v=3.5.0',
		],
	] );

	wp_dequeue_style( 'classic-theme-styles' ); // knopstijlen komen uit theme.json
}, 20 );

// Preload de twee fonts (klein, en zonder flits van een ander font).
add_action( 'wp_head', function () {
	foreach ( [ 'press-start-2p-latin', 'pixelify-sans-latin' ] as $f ) {
		printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( get_theme_file_uri( "assets/fonts/$f.woff2" ) ) );
	}
}, 1 );

// Rustiger <head>: geen emoji-script, geen feeds van reacties.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'wp_head', 'feed_links_extra', 3 );
remove_action( 'wp_head', 'wp_generator' );

add_filter( 'document_title_separator', fn() => '·' );

add_action( 'admin_notices', function () {
	if ( ! class_exists( 'PF_Games' ) ) {
		echo '<div class="notice notice-error"><p>Het Pixelfart-thema heeft de plugin <strong>Pixelfart Games</strong> nodig.</p></div>';
	}
} );

/* ---------- helpers ---------- */

/** Pixel-icoon uit PF_BMP. */
function pf_icon( string $name, int $scale = 2, string $class = 'px-icon' ): string {
	return function_exists( 'pf_pixel_icon' ) ? pf_pixel_icon( PF_BMP[ $name ], $scale, $class ) : '';
}

/** De menu-items (GAMES / TOPSCORES / OVER ONS / CONTACT) en welke actief is (-1 = geen). */
function pf_nav_items(): array {
	$items  = [ [ 'label' => 'GAMES', 'url' => home_url( '/' ), 'page' => null ] ];
	foreach ( [ 'topscores' => 'TOPSCORES', 'over-ons' => 'OVER ONS', 'contact' => 'CONTACT' ] as $slug => $label ) {
		$page    = get_page_by_path( $slug );
		$items[] = [ 'label' => $label, 'url' => $page ? get_permalink( $page ) : home_url( "/$slug/" ), 'page' => $page ];
	}
	$active = is_page() || is_404() || get_query_var( 'pf_nieuwsbrief' ) ? -1 : 0;
	foreach ( $items as $i => $item ) {
		if ( $item['page'] && is_page( $item['page']->ID ) ) {
			$active = $i;
		}
	}
	return [ $items, $active ];
}

/** Getal als "1.204". */
function pf_num( $n ): string {
	return number_format( (float) $n, 0, ',', '.' );
}

/** Gemiddelde als "4,6", of "–" zonder stemmen. */
function pf_avg( array $g ): string {
	return $g['rating_cnt'] ? number_format( $g['rating'], 1, ',', '' ) : '–';
}

/**
 * Rating als vijf pixelsterren, afgerond op halve sterren (3,5 = drie en een halve). Geen cijfer.
 * site.js kan ze na een stem bijwerken via data-rating-stars.
 */
function pf_stars( float $avg, int $count, int $scale = 2 ): string {
	$half = $count ? round( $avg * 2 ) / 2 : 0;
	$out  = '';
	for ( $i = 1; $i <= 5; $i++ ) {
		$state = $half >= $i ? 'full' : ( $half >= $i - 0.5 ? 'half' : 'empty' );
		$out  .= '<span class="rs is-' . $state . '">' . pf_icon( 'star', $scale ) . pf_icon( 'star', $scale, 'px-icon rs-fill' ) . '</span>';
	}
	return sprintf(
		'<span class="rating-stars" data-rating-stars role="img" aria-label="%s" title="%s">%s</span>',
		esc_attr( pf_stars_label( $half, $count ) ),
		esc_attr( pf_stars_label( $half, $count ) ),
		$out
	);
}

/** Tekst voor schermlezers en tooltip bij de sterren. */
function pf_stars_label( float $half, int $count ): string {
	if ( ! $count ) {
		return 'Nog geen sterren gegeven';
	}
	$n = fmod( $half, 1 ) ? number_format( $half, 1, ',', '' ) : (string) (int) $half;
	return "$n van 5 sterren";
}

/**
 * Toetsenbordtoets als pixel-icoon: pf_key( 'left' ) of pf_key( 'ENTER' ).
 * Pijlen: left, right, up, down. Alles anders wordt als tekst op de toets gezet.
 */
function pf_key( string $key, int $scale = 2 ): string {
	$map = [ 'left' => 'kLeft', 'right' => 'kRight', 'up' => 'kUp', 'down' => 'kDown' ];
	$in  = isset( $map[ $key ] ) ? pf_icon( $map[ $key ], $scale ) : esc_html( $key );
	return '<kbd class="key' . ( isset( $map[ $key ] ) ? '' : ' key-word' ) . '">' . $in . '</kbd>';
}

/** Categorie-chip zoals op de kaarten. */
function pf_chip( array $c ): string {
	return sprintf(
		'<span class="chip" style="--c:%s">%s%s</span>',
		esc_attr( $c['color'] ),
		PF_Categories::icon_html( $c['icon'] ),
		esc_html( $c['name'] )
	);
}

/** Reactiedatum als "gisteren", "3 dagen geleden". */
function pf_when( string $gmt_date ): string {
	$diff = time() - strtotime( $gmt_date . ' UTC' );
	if ( $diff < HOUR_IN_SECONDS ) {
		return 'zojuist';
	}
	if ( $diff < DAY_IN_SECONDS ) {
		return 'vandaag';
	}
	$days = (int) floor( $diff / DAY_IN_SECONDS );
	if ( 1 === $days ) {
		return 'gisteren';
	}
	if ( $days < 7 ) {
		return "$days dagen geleden";
	}
	if ( $days < 14 ) {
		return 'vorige week';
	}
	if ( $days < 60 ) {
		return floor( $days / 7 ) . ' weken geleden';
	}
	return wp_date( 'j M Y', strtotime( $gmt_date . ' UTC' ) );
}

/** Vaste kleur per naam bij reacties (zoals in het ontwerp). */
function pf_name_color( string $name ): string {
	$cols = [ '#5dde7a', '#6ec9ff', '#ffcf4a', '#ff5c8a', '#c9a6ff', '#ff9a4a', '#9be3d0' ];
	return $cols[ crc32( strtolower( $name ) ) % count( $cols ) ];
}
