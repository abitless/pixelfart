<?php
/**
 * 404: een doolhof. Loop met de pijltjestoetsen (of de knoppen) naar GAMES en je bent terug bij de games.
 * Het doolhof wordt in de browser getekend (assets/site.js); zonder JS is er gewoon de knop.
 */
get_header();
?>
<div class="notfound">
	<h1 class="h-pix">404 · VERDWAALD</h1>
	<p class="nf-text">Deze pagina bestaat niet (meer). Zoek je weg terug naar <strong class="nf-goal">GAMES</strong> met de pijltjestoetsen <span class="nf-keys" aria-hidden="true"><?php echo pf_key( 'left' ) . pf_key( 'up' ) . pf_key( 'down' ) . pf_key( 'right' ); // phpcs:ignore ?></span></p>

	<div class="maze js-only" data-maze data-target="<?php echo esc_url( home_url( '/' ) ); ?>">
		<canvas class="maze-canvas" data-maze-canvas role="img" aria-label="Doolhof. Jij bent het groene blokje linksboven, GAMES staat rechtsonder."></canvas>
		<div class="maze-pad" role="group" aria-label="Lopen">
			<button type="button" class="key key-btn" data-dir="up" aria-label="Omhoog"><?php echo pf_icon( 'kUp', 3 ); // phpcs:ignore ?></button>
			<button type="button" class="key key-btn" data-dir="left" aria-label="Naar links"><?php echo pf_icon( 'kLeft', 3 ); // phpcs:ignore ?></button>
			<button type="button" class="key key-btn" data-dir="down" aria-label="Omlaag"><?php echo pf_icon( 'kDown', 3 ); // phpcs:ignore ?></button>
			<button type="button" class="key key-btn" data-dir="right" aria-label="Naar rechts"><?php echo pf_icon( 'kRight', 3 ); // phpcs:ignore ?></button>
		</div>
		<p class="maze-status" data-maze-status aria-live="polite"></p>
	</div>

	<a class="btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">◀ NAAR DE GAMES</a>
</div>
<?php
get_footer();
