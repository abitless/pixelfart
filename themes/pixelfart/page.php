<?php
/**
 * Gewone pagina (o.a. Over ons). De inhoud beheer je in de editor.
 */
get_header();
while ( have_posts() ) :
	the_post();
	?>
	<article class="page-wrap">
		<h1 class="h-pix"><?php echo esc_html( mb_strtoupper( get_the_title() ) ); ?></h1>
		<div class="entry"><?php the_content(); ?></div>
	</article>
	<?php
endwhile;
get_footer();
