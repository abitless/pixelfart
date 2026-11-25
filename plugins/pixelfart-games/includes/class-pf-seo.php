<?php
/**
 * SEO en social media, samen met Yoast SEO.
 *
 * - Social-afbeelding per game (1200x630): uploads/pixelfart-games/<CODE>/social.png. Vanzelf gemaakt uit de
 *   thumbnail (2x vergroot, scherpe pixels) op assets/social-base.png, en opnieuw zodra de thumbnail verandert.
 *   Een eigen upload in WP-admin gaat voor (_pf_social_custom).
 * - Titels: "<titel> - spelletjes op pixelfart.nl" (games, pagina's, categorieën, homepage).
 * - Spelpagina: de beschrijving als meta-omschrijving, in het veld van Yoast (sync_desc), zodat je hem in de
 *   snippet-preview ziet en kunt aanpassen. Gedeeld: dezelfde titel, de beschrijving + "geen tracking, geen reclame,
 *   open source", de social-afbeelding.
 * - Structured data: een VideoGame op elke spelpagina, in de schema-graph van Yoast.
 * - Categorieën: een meta-omschrijving als er geen eigen omschrijving is.
 * - Yoast-instellingen die de code verwacht zetten we één keer per CONFIG (ook op productie vanzelf).
 */

defined( 'ABSPATH' ) || exit;

class PF_SEO {

	const CONFIG = '2';
	const W      = 1200;
	const H      = 630;
	/** Waar de thumbnail op social-base.png komt (vak binnen het witte kader). */
	const BOX = [ 27, 27, 768, 576 ];

	/** Achter elke omschrijving van een game op social media (en in zoekmachines als het past). */
	const PROMISE = 'Gratis in je browser. Geen tracking, geen reclame, open source.';

	/** Titel van elke pagina: "<titel> - spelletjes op pixelfart.nl" (Yoast, scheidingsteken sc-dash). */
	const TITLE = '%%sep%% spelletjes op pixelfart.nl';

	/** Langer dan dit kort Google de meta-omschrijving af. */
	const DESC_MAX = 160;

	public static function init() {
		add_action( 'init', [ __CLASS__, 'maybe_configure' ], 40 );
		add_action( 'plugins_loaded', [ __CLASS__, 'hooks' ] ); // Yoast laadt na ons (alfabetisch)
		add_action( 'save_post_' . PF_Games::CPT, [ __CLASS__, 'sync_desc' ], 60 ); // na PF_Admin::save (beschrijving staat er dan)
	}

	public static function hooks() {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}
		add_filter( 'wpseo_metadesc', [ __CLASS__, 'metadesc' ] );
		add_filter( 'wpseo_opengraph_desc', [ __CLASS__, 'social_desc' ] );
		add_filter( 'wpseo_twitter_description', [ __CLASS__, 'social_desc' ] );
		add_action( 'wpseo_add_opengraph_images', [ __CLASS__, 'og_image' ] );
		add_filter( 'wpseo_twitter_image', [ __CLASS__, 'twitter_image' ] );
		add_filter( 'wpseo_schema_graph', [ __CLASS__, 'schema' ], 10, 2 );
		add_filter( 'wpseo_sitemap_urlimages', [ __CLASS__, 'sitemap_images' ], 10, 2 );
		add_filter( 'wpseo_robots', [ __CLASS__, 'robots' ] );
		add_filter( 'wpseo_title', [ __CLASS__, 'title' ] );
		add_filter( 'disable_wpseo_json_ld_search', '__return_true' ); // de site heeft geen zoekfunctie
	}

	/* ---------- social-afbeelding per game ---------- */

	public static function social_file( string $code ): string {
		return PF_Games::dir( $code ) . 'social.png';
	}

	/** URL van de social-afbeelding (maakt hem zo nodig eerst), of '' als er geen thumbnail is. */
	public static function social_url( int $id ): string {
		$code = (string) PF_Games::meta( $id, 'code' );
		if ( ! $code ) {
			return '';
		}
		self::ensure( $id );
		$f = self::social_file( $code );
		return file_exists( $f ) ? PF_Games::url( $code ) . 'social.png?v=' . filemtime( $f ) : '';
	}

	/** Maak de social-afbeelding als hij ontbreekt of ouder is dan de thumbnail (niet bij een eigen upload). */
	public static function ensure( int $id ): void {
		$code  = (string) PF_Games::meta( $id, 'code' );
		$thumb = PF_Games::dir( $code ) . 'thumb.png';
		$f     = self::social_file( $code );
		if ( ! $code || get_post_meta( $id, '_pf_social_custom', true ) || ! file_exists( $thumb ) ) {
			return;
		}
		if ( ! file_exists( $f ) || filemtime( $thumb ) > filemtime( $f ) ) {
			self::generate( $id );
		}
	}

	/** Thumbnail in het kader van social-base.png, zo groot mogelijk met hele pixels (meestal 2x). */
	public static function generate( int $id ): bool {
		$code  = (string) PF_Games::meta( $id, 'code' );
		$thumb = PF_Games::dir( $code ) . 'thumb.png';
		$base  = PIXELFART_DIR . 'assets/social-base.png';
		if ( ! function_exists( 'imagecreatefrompng' ) || ! file_exists( $thumb ) || ! file_exists( $base ) ) {
			return false;
		}
		$img = @imagecreatefrompng( $base );
		$src = @imagecreatefromstring( (string) file_get_contents( $thumb ) );
		if ( ! $img || ! $src ) {
			return false;
		}
		[ $bx, $by, $bw, $bh ] = self::BOX;
		$sw    = imagesx( $src );
		$sh    = imagesy( $src );
		$scale = min( $bw / $sw, $bh / $sh );
		$scale = $scale >= 1 ? floor( $scale ) : $scale; // hele pixels als het kan
		$dw    = (int) round( $sw * $scale );
		$dh    = (int) round( $sh * $scale );
		imagecopyresized( $img, $src, $bx + intdiv( $bw - $dw, 2 ), $by + intdiv( $bh - $dh, 2 ), 0, 0, $dw, $dh, $sw, $sh ); // zonder vervagen
		$ok = imagepng( $img, self::social_file( $code ), 9 );
		imagedestroy( $img );
		imagedestroy( $src );
		return $ok;
	}

	/** Eigen social-afbeelding (WP-admin): bijsnijden tot 1200x630 en opslaan. */
	public static function install_custom( int $id, string $bytes ): bool {
		$code = (string) PF_Games::meta( $id, 'code' );
		$tmp  = wp_tempnam( 'pf-social' );
		file_put_contents( $tmp, $bytes );
		$editor = wp_get_image_editor( $tmp );
		$ok     = ! is_wp_error( $editor )
			&& ! is_wp_error( $editor->resize( self::W, self::H, true ) )
			&& ! is_wp_error( $editor->save( self::social_file( $code ), 'image/png' ) );
		@unlink( $tmp );
		if ( $ok ) {
			update_post_meta( $id, '_pf_social_custom', 1 );
		}
		return $ok;
	}

	/** Terug naar de automatische afbeelding uit de thumbnail. */
	public static function reset( int $id ): void {
		delete_post_meta( $id, '_pf_social_custom' );
		self::generate( $id );
	}

	/* ---------- teksten ---------- */

	private static function game(): ?array {
		return is_singular( PF_Games::CPT ) ? PF_Games::get( get_queried_object_id() ) : null;
	}

	/** Heeft deze pagina een eigen tekst in Yoast? Dan blijven we eraf. */
	private static function own( string $key ): bool {
		return '' !== (string) get_post_meta( get_queried_object_id(), '_yoast_wpseo_' . $key, true );
	}

	/** Omschrijving van de game + de belofte. */
	public static function game_desc( array $g ): string {
		$d = trim( (string) $g['description'] );
		if ( '' !== $d && ! preg_match( '/[.!?]$/u', $d ) ) {
			$d .= '.';
		}
		return trim( $d . ' ' . self::PROMISE );
	}

	/** Meta-omschrijving van een game: de beschrijving, met de belofte erachter als dat binnen DESC_MAX past. */
	public static function seo_desc( array $g ): string {
		$full = self::game_desc( $g );
		$d    = trim( (string) $g['description'] );
		return mb_strlen( $full ) <= self::DESC_MAX || '' === $d ? $full : $d;
	}

	/**
	 * Zet de meta-omschrijving van de game in het veld van Yoast (zichtbaar en aan te passen in de snippet-preview).
	 * Alleen als het veld leeg is of nog onze vorige automatische tekst bevat: wat je zelf schrijft, blijft staan.
	 */
	public static function sync_desc( int $id ) {
		if ( wp_is_post_revision( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		$g = PF_Games::get( $id );
		if ( ! $g ) {
			return;
		}
		$new  = self::seo_desc( $g );
		$cur  = (string) get_post_meta( $id, '_yoast_wpseo_metadesc', true );
		$auto = (string) get_post_meta( $id, '_pf_seo_desc', true );
		if ( '' === $cur || $cur === $auto ) {
			update_post_meta( $id, '_yoast_wpseo_metadesc', $new );
			update_post_meta( $id, '_pf_seo_desc', $new );
		}
	}

	public static function metadesc( $desc ) {
		$g = self::game();
		if ( $g && ! self::own( 'metadesc' ) ) {
			return self::seo_desc( $g ); // vangnet; normaal staat hij al in het veld (sync_desc)
		}
		if ( is_tax( 'pf_categorie' ) && ! trim( (string) $desc ) ) {
			return self::term_desc( get_queried_object() );
		}
		return $desc;
	}

	public static function social_desc( $desc ) {
		$g = self::game();
		return $g && ! self::own( 'opengraph-description' ) ? self::game_desc( $g ) : self::metadesc( $desc );
	}

	/** Nieuwsbriefpagina (/nieuwsbrief/): eigen titel. */
	public static function title( $title ) {
		return get_query_var( 'pf_nieuwsbrief' ) ? 'Nieuwsbrief - spelletjes op pixelfart.nl' : $title;
	}

	/** Nieuwsbriefpagina en vervolgpagina's van het nieuws niet in zoekmachines. */
	public static function robots( $robots ) {
		$news = class_exists( 'PF_Announcements' ) && is_page( PF_Announcements::PAGE_SLUG ) && ! empty( $_GET[ PF_Announcements::PAGE_VAR ] ); // phpcs:ignore
		return get_query_var( 'pf_nieuwsbrief' ) || $news ? 'noindex, follow' : $robots;
	}

	/* ---------- afbeeldingen ---------- */

	/** @param \Yoast\WP\SEO\Values\Open_Graph\Images $images */
	public static function og_image( $images ) {
		$g = self::game();
		if ( $g && ! self::own( 'opengraph-image' ) && ( $url = self::social_url( $g['id'] ) ) ) {
			$images->add_image( [ 'url' => $url, 'width' => self::W, 'height' => self::H, 'type' => 'image/png', 'alt' => $g['title'] ] );
		}
	}

	public static function twitter_image( $img ) {
		$g = self::game();
		return $g && ! self::own( 'twitter-image' ) ? ( self::social_url( $g['id'] ) ?: $img ) : $img;
	}

	public static function sitemap_images( $images, $post_id ) {
		if ( PF_Games::CPT === get_post_type( $post_id ) && ( $url = self::social_url( (int) $post_id ) ) ) {
			$images[] = [ 'src' => $url ];
		}
		return $images;
	}

	/* ---------- structured data ---------- */

	public static function schema( $graph, $context ) {
		$g = self::game();
		if ( ! $g || ! is_array( $graph ) ) {
			return $graph;
		}
		$url   = get_permalink( $g['id'] );
		$cats  = wp_list_pluck( $g['categories'], 'name' );
		$image = array_values( array_filter( [ self::social_url( $g['id'] ), $g['thumb_url'] ] ) );
		$game  = [
			'@type'               => 'VideoGame',
			'@id'                 => $url . '#game',
			'name'                => $g['title'],
			'url'                 => $url,
			'description'         => (string) $g['description'],
			'image'               => $image,
			'genre'               => $cats,
			'gamePlatform'        => 'Webbrowser',
			'applicationCategory' => 'Game',
			'operatingSystem'     => 'Alle (in de browser)',
			'playMode'            => in_array( '2 spelers', $cats, true ) ? [ 'SinglePlayer', 'MultiPlayer' ] : 'SinglePlayer',
			'inLanguage'          => 'nl-NL',
			'isAccessibleForFree' => true,
			'offers'              => [ '@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock', 'url' => $url ],
			'softwareVersion'     => $g['version'],
			'license'             => 'https://spdx.org/licenses/' . PF_Games::LICENSE_CODE . '.html',
			'contentRating'       => 'ALL' === $g['age'] ? 'Voor iedereen' : $g['age'],
			'publisher'           => [ '@id' => home_url( '/#organization' ) ],
			'author'              => $g['author'] ? [ '@type' => 'Person', 'name' => $g['author'] ] : [ '@id' => home_url( '/#organization' ) ],
			'mainEntityOfPage'    => [ '@id' => $url ],
		];
		if ( $g['published'] ) {
			$game['datePublished'] = $g['published'];
		}
		if ( $g['rating_cnt'] > 0 ) {
			$game['aggregateRating'] = [ '@type' => 'AggregateRating', 'ratingValue' => round( (float) $g['rating'], 1 ), 'ratingCount' => (int) $g['rating_cnt'], 'bestRating' => 5, 'worstRating' => 1 ];
		}
		$graph[] = array_filter( $game, fn( $v ) => '' !== $v && [] !== $v && null !== $v );
		return $graph;
	}

	/* ---------- Yoast-instellingen ---------- */

	/**
	 * Eén keer per CONFIG: de Yoast-instellingen waar de code op rekent. Wat iemand daarna in WP-admin
	 * verandert, blijft staan (tot CONFIG omhoog gaat). Afbeeldingen (logo, standaard social-afbeelding) staan in
	 * de mediabibliotheek met de meta _pf_social = <naam>.
	 */
	public static function maybe_configure() {
		if ( ! defined( 'WPSEO_VERSION' ) || get_option( 'pf_seo' ) === self::CONFIG ) {
			return;
		}
		update_option( 'pf_seo', self::CONFIG, true );
		$media = fn( string $name ) => (int) ( get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_pf_social', 'meta_value' => $name, 'fields' => 'ids', 'numberposts' => 1 ] )[0] ?? 0 );
		$logo  = $media( 'logo' );
		$home  = $media( 'home' );

		$set = function ( string $option, array $values ) {
			$cur = get_option( $option, [] );
			update_option( $option, array_merge( is_array( $cur ) ? $cur : [], $values ) );
		};
		$set( 'wpseo', [
			'tracking'                       => false,
			'show_onboarding_notice'         => false,
			'should_redirect_after_install_free' => false,
			'enable_xml_sitemap'             => true,
			'enable_admin_bar_menu'          => false,
			'enable_llms_txt'                => false,
		] );
		$desc = 'Kleine pixelgames die je meteen in je browser speelt. Gratis, zonder account, zonder tracking en zonder reclame. Gemaakt door een vader en zoon, en helemaal open source.';
		$set( 'wpseo_titles', array_filter( [
			'separator'                  => 'sc-dash',
			'title-home-wpseo'           => 'Pixelfart ' . self::TITLE,
			'metadesc-home-wpseo'        => $desc,
			'title-' . PF_Games::CPT     => '%%title%% ' . self::TITLE,
			'title-page'                 => '%%title%% ' . self::TITLE,
			'title-tax-pf_categorie'     => '%%term_title%% ' . self::TITLE,
			'showdate-' . PF_Games::CPT  => false, // geen datum in de zoekresultaten
			'showdate-page'              => false,
			'company_or_person'          => 'company',
			'company_name'               => 'Pixelfart',
			'website_name'               => 'Pixelfart',
			'company_logo'               => $logo ? wp_get_attachment_url( $logo ) : null,
			'company_logo_id'            => $logo ?: null,
			'disable-author'             => true,
			'disable-date'               => true,
			'disable-post_format'        => true,
			'disable-attachment'         => true,
			'noindex-tax-category'       => true,
			'noindex-tax-post_tag'       => true,
			'breadcrumbs-enable'         => false,
			// homepage op social media (de homepage is geen statische pagina)
			'open_graph_frontpage_title'    => 'Pixelfart - spelletjes op pixelfart.nl',
			'open_graph_frontpage_desc'     => $desc,
			'open_graph_frontpage_image'    => $home ? wp_get_attachment_url( $home ) : null,
			'open_graph_frontpage_image_id' => $home ?: null,
		], fn( $v ) => null !== $v ) );
		$set( 'wpseo_social', array_filter( [
			'opengraph'              => true,
			'twitter'                => true,
			'twitter_card_type'      => 'summary_large_image',
			'og_default_image'       => $home ? wp_get_attachment_url( $home ) : null,
			'og_default_image_id'    => $home ?: null,
		], fn( $v ) => null !== $v ) );
		// Omschrijvingen in de velden van Yoast, zodat ze ook in de snippet-preview staan.
		foreach ( get_posts( [ 'post_type' => PF_Games::CPT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
			self::sync_desc( $id );
		}
		if ( class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
			foreach ( get_terms( [ 'taxonomy' => 'pf_categorie', 'hide_empty' => false ] ) as $t ) {
				if ( '' === (string) WPSEO_Taxonomy_Meta::get_term_meta( $t, 'pf_categorie', 'desc' ) ) {
					WPSEO_Taxonomy_Meta::set_value( $t->term_id, 'pf_categorie', 'wpseo_desc', self::term_desc( $t ) );
				}
			}
		}
		do_action( 'litespeed_purge_all', 'Pixelfart: SEO-instellingen' );
	}

	/** Omschrijving van een categorie. */
	public static function term_desc( WP_Term $t ): string {
		return sprintf( 'Speel %d gratis %s-spelletjes van Pixelfart, meteen in je browser. Geen tracking, geen reclame, open source.', (int) $t->count, mb_strtolower( $t->name ) );
	}
}
