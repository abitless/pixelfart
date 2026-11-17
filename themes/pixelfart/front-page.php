<?php
/**
 * Homepage: alle games met tagfilter, sorteren, pagina's, de geluksmachine en het idee-lampje.
 * Filteren/sorteren/pagineren gebeurt in de browser (assets/site.js); zonder JS staan alle games er gewoon.
 * Ook gebruikt door taxonomy-pf_categorie.php (dan staat $pf_tag op die categorie).
 */

$pf_tag   = $pf_tag ?? '';
$pf_games = class_exists( 'PF_Games' ) ? PF_Games::all() : [];
$pf_cats  = class_exists( 'PF_Categories' ) ? PF_Categories::all() : [];

// Rang op populariteit (#1 = meest gespeeld).
$pf_rank = wp_list_pluck( $pf_games, 'plays', 'slug' );
arsort( $pf_rank );
$pf_rank = array_flip( array_keys( $pf_rank ) );

// Standaard: populair.
usort( $pf_games, fn( $a, $b ) => $b['plays'] <=> $a['plays'] ?: strcasecmp( $a['title'], $b['title'] ) );

$pf_active_cat = $pf_tag ? current( wp_list_filter( $pf_cats, [ 'slug' => $pf_tag ] ) ) : null;
$pf_visible    = $pf_tag ? array_filter( $pf_games, fn( $g ) => in_array( $pf_tag, wp_list_pluck( $g['categories'], 'slug' ), true ) ) : $pf_games;

get_header();
?>
<div class="home" data-home data-tag="<?php echo esc_attr( $pf_tag ); ?>">
	<ul class="tag-menu" aria-label="Filter op categorie">
		<li><a class="tag-btn <?php echo $pf_tag ? '' : 'is-active'; ?>" href="<?php echo esc_url( home_url( '/' ) ); ?>" data-tag="">
			<?php echo PF_Categories::icon_html( 'alles' ); // phpcs:ignore ?>
			<span>Alles</span><span class="n"><?php echo count( $pf_games ); ?></span>
		</a></li>
		<?php foreach ( $pf_cats as $c ) : ?>
			<li><a class="tag-btn <?php echo $pf_tag === $c['slug'] ? 'is-active' : ''; ?>" href="<?php echo esc_url( get_term_link( $c['id'] ) ); ?>" data-tag="<?php echo esc_attr( $c['slug'] ); ?>" data-name="<?php echo esc_attr( $c['name'] ); ?>">
				<?php echo PF_Categories::icon_html( $c['icon'] ); // phpcs:ignore ?>
				<span><?php echo esc_html( $c['name'] ); ?></span><span class="n"><?php echo (int) $c['count']; ?></span>
			</a></li>
		<?php endforeach; ?>
	</ul>

	<div class="list-head">
		<h1 class="h-pix"><span data-heading><?php echo esc_html( $pf_active_cat ? mb_strtoupper( $pf_active_cat['name'] ) : 'ALLE GAMES' ); ?></span> <span class="count">(<span data-count><?php echo count( $pf_visible ); ?></span>)</span></h1>
		<div class="sorts js-only" role="group" aria-label="Sorteren">
			<span>sorteer:</span>
			<button type="button" class="sort-btn" data-sort="pop" aria-pressed="true">POPULAIR</button>
			<button type="button" class="sort-btn" data-sort="rating" aria-pressed="false">BESTE</button>
			<button type="button" class="sort-btn" data-sort="new" aria-pressed="false">NIEUW</button>
			<button type="button" class="sort-btn" data-sort="az" aria-pressed="false">A–Z</button>
		</div>
	</div>

	<div class="home-layout">
		<div class="home-games">
			<ul class="game-grid" data-grid>
				<?php foreach ( $pf_games as $g ) :
					$slugs = wp_list_pluck( $g['categories'], 'slug' );
					$hide  = $pf_tag && ! in_array( $pf_tag, $slugs, true );
					?>
					<li data-slug="<?php echo esc_attr( $g['slug'] ); ?>"
						data-title="<?php echo esc_attr( $g['title'] ); ?>"
						data-plays="<?php echo (int) $g['plays']; ?>"
						data-rating="<?php echo esc_attr( $g['rating'] ); ?>"
						data-pub="<?php echo esc_attr( $g['published'] ); ?>"
						data-tags="<?php echo esc_attr( implode( ' ', $slugs ) ); ?>"
						data-color="<?php echo esc_attr( $g['color'] ); ?>"
						data-url="<?php echo esc_url( $g['permalink'] ); ?>"
						<?php echo $hide ? 'hidden' : ''; ?>>
						<a class="card" href="<?php echo esc_url( $g['permalink'] ); ?>" style="--accent:<?php echo esc_attr( $g['color'] ); ?>">
							<div class="card-thumb" style="--stripe:<?php echo esc_attr( $g['color'] ); ?>55">
								<?php if ( $g['thumb_url'] ) : ?>
									<img src="<?php echo esc_url( $g['thumb_url'] ); ?>" alt="" width="384" height="288" loading="lazy" decoding="async">
								<?php endif; ?>
								<span class="badge badge-rank">#<?php echo (int) $pf_rank[ $g['slug'] ] + 1; ?></span>
								<?php if ( $g['is_new'] ) : ?><span class="badge badge-new">NIEUW</span><?php endif; ?>
								<?php if ( 'ALL' !== $g['age'] ) : ?><span class="badge badge-age"><?php echo esc_html( $g['age'] ); ?></span><?php endif; ?>
							</div>
							<div class="card-body">
								<h2 class="card-title" style="margin:0;font-weight:400"><?php echo esc_html( $g['title'] ); ?></h2>
								<div class="stats">
									<span title="Keer gespeeld"><?php echo pf_icon( 'play' ); // phpcs:ignore ?><span class="screen-reader-text">Gespeeld:</span><?php echo esc_html( pf_num( $g['plays'] ) ); ?></span>
									<span class="stat-star" title="Gemiddelde rating"><?php echo pf_icon( 'star' ); // phpcs:ignore ?><span class="screen-reader-text">Rating:</span><?php echo esc_html( pf_avg( $g ) ); ?></span>
									<span title="Reacties"><?php echo pf_icon( 'bubble' ); // phpcs:ignore ?><span class="screen-reader-text">Reacties:</span><?php echo (int) $g['comments']; ?></span>
								</div>
								<div class="chips">
									<?php foreach ( $g['categories'] as $c ) { echo pf_chip( $c ); } // phpcs:ignore ?>
								</div>
							</div>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="no-results" data-empty <?php echo $pf_visible ? 'hidden' : ''; ?>>Geen games met deze tag. Nog niet.</div>
			<nav class="pager js-only" data-pager aria-label="Pagina's" hidden></nav>
		</div>

		<aside class="home-aside" aria-label="Extra">
			<div class="bandit js-only" data-bandit>
				<div class="bandit-box">
					<div class="bandit-title">GELUKS<br>MACHINE</div>
					<div class="bandit-lights" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
					<button type="button" class="reel" data-spin aria-label="Draai de geluksmachine voor een willekeurige game">
						<span class="reel-row"><i></i><b></b></span>
						<span class="reel-row mid"><i></i><b></b></span>
						<span class="reel-row"><i></i><b></b></span>
					</button>
					<a class="bandit-play" data-play href="#" hidden>SPEEL ▶</a>
					<div class="bandit-hint" data-hint aria-live="polite">trek aan de arm!</div>
				</div>
				<div class="bandit-bolt" aria-hidden="true"></div>
				<button type="button" class="lever" data-spin title="Trek aan de arm" aria-label="Trek aan de arm">
					<span class="lever-rod"></span><span class="lever-pivot"></span><span class="lever-ball"><i></i></span>
				</button>
			</div>

			<button type="button" class="idea-btn js-only" data-idea title="Idee of verzoek?">
				<?php
				$b    = pf_pixel_icon( PF_BMP['bulb'], 6, 'px-icon' );
				$hi   = pf_pixel_icon( PF_BMP['bulbHi'], 6, 'px-icon bulb-hi', 'position:absolute;left:0;top:0' );
				$base = pf_pixel_icon( PF_BMP['bulbBase'], 6, 'px-icon bulb-base', 'top:54px' );
				?>
				<span class="bulb" style="width:60px;height:84px" aria-hidden="true"><?php echo $b . $hi . $base; // phpcs:ignore ?></span>
				<span class="idea-text"><strong>IDEE?</strong><span data-idea-hint>klik op het lampje</span></span>
			</button>
		</aside>
	</div>
</div>
<?php
get_footer();
