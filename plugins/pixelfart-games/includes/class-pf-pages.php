<?php
/**
 * Vaste pagina's van de site die de code verwacht (menu en footer linken ernaar).
 *
 * Eén keer per VERSION, zodat een nieuwe pagina ook op productie vanzelf verschijnt.
 * Bestaande inhoud passen we alleen aan als die nog precies is zoals wij hem neerzetten:
 * wat iemand in WP-admin heeft veranderd, blijft staan.
 */

defined( 'ABSPATH' ) || exit;

class PF_Pages {

	const VERSION = '2';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'maybe_upgrade' ], 30 );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'pf_pages' ) === self::VERSION ) {
			return;
		}
		update_option( 'pf_pages', self::VERSION, true ); // eerst: nooit twee keer tegelijk

		// 1. Pixelmuur: een eigen pagina in het menu, met de muur groot en de erelijst van pixelkopers.
		if ( ! get_page_by_path( 'pixelmuur' ) ) {
			wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'pixelmuur',
				'post_title'   => 'Pixelmuur',
				'post_content' => self::pixelmuur(),
			] );
		}

		// 2. Topscores: de muur stond onderaan, die heeft nu een eigen pagina.
		$top = get_page_by_path( 'topscores' );
		if ( $top ) {
			$old = $top->post_content;
			$new = preg_replace( '#\s*<!-- wp:separator -->\s*<hr class="wp-block-separator has-alpha-channel-opacity"/>\s*<!-- /wp:separator -->\s*<!-- wp:heading -->\s*<h2 class="wp-block-heading">Onze pixelmuur donateurs</h2>\s*<!-- /wp:heading -->\s*<!-- wp:pixelfart/pixel-wall /-->\s*$#', "\n", $old );
			if ( $new && $new !== $old ) {
				wp_update_post( [ 'ID' => $top->ID, 'post_content' => rtrim( $new ) . "\n" ] );
			}
		}
		// 3. Nieuws: alle aankondigingen als tijdlijn (thema: page-nieuws.php). De inhoud is een optionele inleiding.
		if ( ! get_page_by_path( 'nieuws' ) ) {
			wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'nieuws',
				'post_title'   => 'Nieuws',
				'post_content' => '',
			] );
		}
		do_action( 'litespeed_purge_all', 'Pixelfart: pagina\'s bijgewerkt' );
	}

	private static function pixelmuur(): string {
		return <<<'HTML'
<!-- wp:paragraph {"textColor":"dim"} -->
<p class="has-dim-color has-text-color">Elke gekleurde pixel is gekocht door iemand die Pixelfart een warm hart toedraagt. Klik op een pixel om te zien van wie hij is.</p>
<!-- /wp:paragraph -->

<!-- wp:pixelfart/pixel-wall {"big":true,"align":"wide"} /-->

<!-- wp:separator {"className":"is-style-pixels"} -->
<hr class="wp-block-separator has-alpha-channel-opacity is-style-pixels"/>
<!-- /wp:separator -->

<!-- wp:pixelfart/pixel-donors {"align":"wide"} /-->
HTML;
	}
}
