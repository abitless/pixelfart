<?php
/**
 * Pixelfart-thema. De games zelf (berichttype, opslag, REST) zitten in de plugin Pixelfart Games.
 */

defined( 'ABSPATH' ) || exit;

const PF_THEME_VERSION = '1.0.0';

/** Pixel-bitmaps voor iconen in het thema ('#' = pixel). */
const PF_BMP = [
	'play'     => [ '#.......', '###.....', '#####...', '#######.', '#####...', '###.....', '#.......' ],
	'star'     => [ '...##...', '...##...', '########', '.######.', '..####..', '.##..##.', '##....##' ],
	'fs'       => [ '###..###', '#......#', '#......#', '........', '........', '#......#', '#......#', '###..###' ],
	'bubble'   => [ '########', '#......#', '#......#', '#......#', '########', '##......', '#.......' ],
	'bulb'     => [ '...####...', '.########.', '##########', '##########', '##########', '##########', '.########.', '..######..', '...####...', '...####...' ],
	'bulbHi'   => [ '..........', '..........', '..#.......', '.##.......', '.#........' ],
	'bulbBase' => [ '..######..', '...####...', '..######..', '....##....' ],
];

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', [ 'style', 'script', 'navigation-widgets' ] );
	add_theme_support( 'responsive-embeds' );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'pixelfart', get_stylesheet_uri(), [], PF_THEME_VERSION );
	wp_enqueue_script( 'pixelfart', get_theme_file_uri( 'assets/site.js' ), [], PF_THEME_VERSION, [ 'strategy' => 'defer', 'in_footer' => true ] );
	wp_localize_script( 'pixelfart', 'PF', [
		'rest' => esc_url_raw( rest_url( 'pixelfart/v1/' ) ),
		'home' => esc_url_raw( home_url( '/' ) ),
	] );

	// Geen blok-CSS nodig: de paar blokken in pagina's stylen we zelf.
	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'classic-theme-styles' );
	wp_dequeue_style( 'global-styles' );
}, 20 );

// Preload de twee fonts (klein, en zonder flits van een ander font).
add_action( 'wp_head', function () {
	foreach ( [ 'press-start-2p-latin', 'vt323-latin' ] as $f ) {
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

/** De drie menu-items (GAMES / OVER ONS / CONTACT) en welke actief is. */
function pf_nav_items(): array {
	$about   = get_page_by_path( 'over-ons' );
	$contact = get_page_by_path( 'contact' );
	$items   = [
		[ 'label' => 'GAMES', 'url' => home_url( '/' ) ],
		[ 'label' => 'OVER ONS', 'url' => $about ? get_permalink( $about ) : home_url( '/over-ons/' ) ],
		[ 'label' => 'CONTACT', 'url' => $contact ? get_permalink( $contact ) : home_url( '/contact/' ) ],
	];
	$active = 0;
	if ( $about && is_page( $about->ID ) ) {
		$active = 1;
	} elseif ( $contact && is_page( $contact->ID ) ) {
		$active = 2;
	} elseif ( is_page() ) {
		$active = -1;
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
