<?php
/**
 * Topscores (blok pixelfart/highscores): alle lijsten onder elkaar, met bovenaan filters op game en op naam.
 *
 * De filters zijn een gewoon GET-formulier (?game=CODE&naam=ABC): werkt ook zonder JavaScript en je kunt
 * de link delen. site.js verstuurt het vanzelf zodra je een game kiest of drie letters hebt getypt.
 * - Geen filter: per game de beste $limit.
 * - Eén game: de beste 25 van die game.
 * - Een naam: per game alle scores van die naam (uit de bewaarde KEEP), met hun plaats.
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
		<label class="hs-filter-game"><span>Game</span>
			<select name="game" class="input">
				<option value="">Alle games</option>
				<?php foreach ( $pf_games as $pf_g ) : ?>
					<option value="<?php echo esc_attr( $pf_g['code'] ); ?>"<?php selected( $pf_g['code'], $pf_want ); ?>><?php echo esc_html( $pf_g['title'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
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
		if ( $pf_naam ) {
			$pf_top = array_values( array_filter( PF_Scores::top( $pf_g['id'], PF_Scores::KEEP ), fn( $s ) => $s['name'] === $pf_naam ) );
			if ( ! $pf_top ) {
				continue;
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
					<?php foreach ( $pf_top as $pf_s ) : ?>
						<li class="<?php echo $pf_s['rank'] <= 3 ? 'is-top' . (int) $pf_s['rank'] : ''; ?>"><span class="hs-rank"><?php echo (int) $pf_s['rank']; ?></span><span class="hs-name"><?php echo esc_html( $pf_s['name'] ); ?></span><span class="hs-score"><?php echo esc_html( number_format_i18n( $pf_s['score'] ) ); ?> <small><?php echo esc_html( $pf_g['score']['label'] ); ?></small></span><span class="hs-date"><?php echo esc_html( wp_date( 'j M Y', strtotime( $pf_s['date'] . ' 12:00 UTC' ) ) ); ?></span></li>
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
