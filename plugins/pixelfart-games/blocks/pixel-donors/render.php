<?php
/**
 * Pixelfans (blok pixelfart/pixel-donors): erelijst van iedereen die pixels kocht.
 * De lijst tekent assets/donate.js uit dezelfde gegevens als de muur (GET /pixelfart/v1/pixels):
 * altijd actueel, ook op een gecachete pagina, en alleen wat goedgekeurd is.
 *
 * @var array $attributes
 */

defined( 'ABSPATH' ) || exit;

$pf_title = trim( (string) ( $attributes['title'] ?? '' ) );
?>
<section <?php echo get_block_wrapper_attributes( [ 'class' => 'pf-donors' ] ); // phpcs:ignore ?> data-pixel-donors aria-label="Pixelfans">
	<?php if ( '' !== $pf_title ) : ?>
		<h2 class="donors-title"><?php echo esc_html( $pf_title ); ?></h2>
	<?php endif; ?>
	<noscript><p>Voor de lijst met pixelfans heb je JavaScript nodig.</p></noscript>
	<p class="donors-count js-only" data-donors-count></p>
	<ol class="donors-list js-only" data-donors-list></ol>
</section>
