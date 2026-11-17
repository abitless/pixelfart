<?php
/**
 * Terugval voor alles zonder eigen template (bijv. losse berichten).
 */
get_header();
?>
<div class="page-wrap">
	<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
		<article>
			<h1 class="h-pix"><a href="<?php the_permalink(); ?>" style="color:inherit"><?php echo esc_html( mb_strtoupper( get_the_title() ) ); ?></a></h1>
			<div class="entry"><?php is_singular() ? the_content() : the_excerpt(); ?></div>
		</article>
	<?php endwhile; else : ?>
		<p>Hier is (nog) niks.</p>
	<?php endif; ?>
</div>
<?php
get_footer();
