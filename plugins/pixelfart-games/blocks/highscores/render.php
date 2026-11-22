<?php
/**
 * Topscores (blok pixelfart/highscores): alle lijsten onder elkaar, met bovenaan filters op game en op naam.
 *
 * De filters zijn een gewoon GET-formulier (?game=CODE&naam=ABC): werkt ook zonder JavaScript en je kunt
 * de link delen. site.js verstuurt het vanzelf zodra je een game kiest of drie letters hebt getypt.
 * - Geen filter: per game de beste $limit.
 * - Eén game: de beste 25 van die game.
 * - Een naam: alleen de games waarin die naam voorkomt, met de gewone lijst (alle namen) en die naam gemarkeerd.
 *   Staat de naam lager dan de lijst, dan komt zijn beste score er los onder, met zijn plaats.
 *
 * @var array $attributes
 */

defined( 'ABSPATH' ) || exit;

do_action( 'litespeed_tag_add', 'pf_scores' ); // ververst zodra er een nieuwe score is

$pf_limit = max( 1, min( 10, (int) ( $attributes['limit'] ?? 3 ) ) );
$pf_games = array_values( array_filter( PF_Games::all(), [ 'PF_Scores', 'enabled' ] ) );

// phpcs:disable WordPress.Security.NonceVerification -- alleen lezen en filteren
$pf_want = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['game'] ?? '' ) ) );
$pf_naam = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) wp_unslash( $_GET['naam'] ?? '' ) ) );
// phpcs:enable
$pf_naam = 3 === strlen( $pf_naam ) ? $pf_naam : '';
$pf_codes = array_column( $pf_games, 'code' );
$pf_want  = in_array( $pf_want, $pf_codes, true ) ? $pf_want : '';

$pf_show = $pf_want ? array_values( array_filter( $pf_games, fn( $g ) => $g['code'] === $pf_want ) ) : $pf_games;
$pf_hits  = 0;
$pf_empty = [];
$pf_base = strtok( (string) get_permalink(), '?' );
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'pf-hiscores' ] ); // phpcs:ignore ?>>
	<form class="hs-filter" method="get" action="<?php echo esc_url( $pf_base ); ?>" data-hs-filter role="search" aria-label="Topscores filteren">
		<div class="hs-filter-game"><span id="hs-game-label">Game</span>
			<select name="game" class="input" data-pixel-select aria-labelledby="hs-game-label">
				<option value="">Alle games</option>
				<?php foreach ( $pf_games as $pf_g ) : ?>
					<option value="<?php echo esc_attr( $pf_g['code'] ); ?>" data-color="<?php echo esc_attr( $pf_g['color'] ); ?>"<?php selected( $pf_g['code'], $pf_want ); ?>><?php echo esc_html( $pf_g['title'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<label class="hs-filter-name"><span>Naam</span>
			<input class="input initials" name="naam" value="<?php echo esc_attr( $pf_naam ); ?>" maxlength="3" placeholder="ABC" autocomplete="off" spellcheck="false" pattern="[A-Za-z]{3}" title="Drie letters">
		</label>
		<button type="submit" class="btn btn-yellow hs-filter-go">ZOEK ▶</button>
		<?php if ( $pf_want || $pf_naam ) : ?>
			<a class="hs-filter-reset" href="<?php echo esc_url( $pf_base ); ?>">Alles tonen</a>
		<?php endif; ?>
	</form>

	<?php if ( ! $pf_games ) : ?>
		<p class="is-dim">Nog geen games met een topscorelijst.</p>
	<?php endif; ?>

	<?php foreach ( $pf_show as $pf_g ) : ?>
		<?php
		$pf_extra = [];
		if ( $pf_naam ) {
			$pf_all  = PF_Scores::top( $pf_g['id'], PF_Scores::KEEP );
			$pf_mine = array_values( array_filter( $pf_all, fn( $s ) => $s['name'] === $pf_naam ) );
			if ( ! $pf_mine ) {
				continue;
			}
			$pf_top = array_slice( $pf_all, 0, $pf_want ? 25 : 10 );
			if ( ! array_filter( $pf_top, fn( $s ) => $s['name'] === $pf_naam ) ) {
				$pf_extra = [ $pf_mine[0] ]; // niet in de lijst: zijn beste score los eronder
			}
		} else {
			$pf_top = PF_Scores::top( $pf_g['id'], $pf_want ? 25 : $pf_limit );
			if ( ! $pf_top && ! $pf_want ) {
				$pf_empty[] = $pf_g; // onderaan samen, dan blijft de lijst rustig
				continue;
			}
		}
		$pf_hits++;
		?>
		<section class="pf-hiscore" style="--c:<?php echo esc_attr( $pf_g['color'] ); ?>">
			<h3><a href="<?php echo esc_url( $pf_g['permalink'] ); ?>"><?php echo esc_html( mb_strtoupper( $pf_g['title'] ) ); ?></a></h3>
			<?php if ( $pf_top ) : ?>
				<ol class="hiscore-list">
					<?php foreach ( $pf_extra ? array_merge( $pf_top, [ null ], $pf_extra ) : $pf_top as $pf_s ) : ?>
						<?php if ( null === $pf_s ) : ?>
							<li class="hs-gap" aria-hidden="true">…</li>
							<?php continue; ?>
						<?php endif; ?>
						<li class="<?php echo esc_attr( trim( ( $pf_s['rank'] <= 3 ? 'is-top' . (int) $pf_s['rank'] : '' ) . ( $pf_naam && $pf_s['name'] === $pf_naam ? ' is-me' : '' ) ) ); ?>"><span class="hs-rank"><?php echo (int) $pf_s['rank']; ?></span><span class="hs-name"><?php echo esc_html( $pf_s['name'] ); ?></span><span class="hs-score"><?php echo esc_html( number_format_i18n( $pf_s['score'] ) ); ?> <small><?php echo esc_html( $pf_g['score']['label'] ); ?></small></span><span class="hs-date"><?php echo esc_html( wp_date( 'j M Y', strtotime( $pf_s['date'] . ' 12:00 UTC' ) ) ); ?></span></li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p class="hs-empty">Nog niemand. Word jij de eerste?</p>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>

	<?php if ( $pf_empty ) : ?>
		<p class="hs-open">Nog geen topscores: <?php echo implode( ', ', array_map( fn( $g ) => sprintf( '<a href="%s">%s</a>', esc_url( $g['permalink'] ), esc_html( $g['title'] ) ), $pf_empty ) ); // phpcs:ignore -- hierboven ge-escaped ?>. Word jij de eerste?</p>
	<?php endif; ?>

	<?php if ( $pf_naam && ! $pf_hits ) : ?>
		<p class="hs-none">Geen topscores van <strong><?php echo esc_html( $pf_naam ); ?></strong><?php echo $pf_want ? ' in deze game' : ''; ?>. Nog niet, tenminste.</p>
	<?php endif; ?>
</div>
