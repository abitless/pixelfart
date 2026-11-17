<?php
/**
 * Title: Wie zijn wij (twee avatars)
 * Slug: pixelfart/wie-zijn-wij
 * Categories: pixelfart
 * Description: Twee kolommen met een pixel-avatar, naam en rol. Vervang de plaatjes door eigen pixelportretten.
 * Keywords: over ons, team, avatar
 */
?>
<!-- wp:columns {"className":"pf-avatars"} -->
<div class="wp-block-columns pf-avatars"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:image {"sizeSlug":"full","className":"is-style-pixelig"} -->
<figure class="wp-block-image size-full is-style-pixelig"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/avatar-vader.svg' ) ); ?>" alt="Pixelportret van vader"/></figure>
<!-- /wp:image -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">VADER</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Bouwt de website en test elk spel (en verliest meestal).</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:image {"sizeSlug":"full","className":"is-style-pixelig"} -->
<figure class="wp-block-image size-full is-style-pixelig"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/avatar-zoon.svg' ) ); ?>" alt="Pixelportret van zoon"/></figure>
<!-- /wp:image -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">ZOON</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Bedenkt en maakt de games. Baas van de highscores.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
