<?php
/**
 * Topscores (blok pixelfart/highscores): per game met een lijst de beste spelers.
 *
 * @var array $attributes
 */

defined( 'ABSPATH' ) || exit;

do_action( 'litespeed_tag_add', 'pf_scores' ); // ververst zodra er een nieuwe score is

$pf_limit = max( 1, min( 10, (int) ( $attributes['limit'] ?? 3 ) ) );
$pf_games = array_filter( PF_Games::all(), [ 'PF_Scores', 'enabled' ] );
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'pf-hiscores' ] ); // phpcs:ignore ?>>
	<?php if ( ! $pf_games ) : ?>
		<p class="is-dim">Nog geen games met een topscorelijst.</p>
	<?php endif; ?>
	<?php foreach ( $pf_games as $pf_g ) : ?>
		<?php $pf_top = PF_Scores::top( $pf_g['id'], $pf_limit ); ?>
		<section class="pf-hiscore" style="--c:<?php echo esc_attr( $pf_g['color'] ); ?>">
			<h3><a href="<?php echo esc_url( $pf_g['permalink'] ); ?>"><?php echo esc_html( mb_strtoupper( $pf_g['title'] ) ); ?></a></h3>
			<?php if ( $pf_top ) : ?>
				<ol class="hiscore-list">
					<?php foreach ( $pf_top as $pf_s ) : ?>
						<li><span class="hs-rank"><?php echo (int) $pf_s['rank']; ?></span><span class="hs-name"><?php echo esc_html( $pf_s['name'] ); ?></span><span class="hs-score"><?php echo esc_html( number_format_i18n( $pf_s['score'] ) ); ?> <small><?php echo esc_html( $pf_g['score']['label'] ); ?></small></span></li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p class="hs-empty">Nog niemand. Word jij de eerste?</p>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
