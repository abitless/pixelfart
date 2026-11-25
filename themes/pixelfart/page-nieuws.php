<?php
/**
 * Nieuwspagina: alle aankondigingen als tijdlijn, nieuwste bovenaan, tien per pagina (?pagina=2).
 * Ouder dan zes maanden staat er niet meer op (archief). De inhoud van de pagina zelf is een optionele inleiding.
 * Elk bericht heeft links een vinkje "gelezen"; wat nog niet gelezen is krijgt "NIEUW". "Alles gelezen!" vinkt alles af.
 * Alleen deze browser onthoudt het (site.js, localStorage).
 */
get_header();
$pf_page = max( 1, absint( $_GET[ class_exists( 'PF_Announcements' ) ? PF_Announcements::PAGE_VAR : 'pagina' ] ?? 1 ) ); // phpcs:ignore -- alleen een paginanummer
[ $pf_news, $pf_pages ] = class_exists( 'PF_Announcements' ) ? PF_Announcements::news( $pf_page ) : [ [], 0 ];
while ( have_posts() ) :
	the_post();
	?>
	<article class="page-wrap news-wrap">
		<div class="news-head">
			<h1 class="h-pix"><?php echo esc_html( mb_strtoupper( get_the_title() ) ); ?></h1>
			<?php if ( $pf_news ) : ?>
				<button type="button" class="btn btn-yellow js-only" data-news-read disabled>ALLES GELEZEN!</button>
			<?php endif; ?>
		</div>
		<?php if ( trim( get_the_content() ) ) : ?>
			<div class="entry"><?php the_content(); ?></div>
		<?php endif; ?>

		<?php if ( ! $pf_news ) : ?>
			<p class="news-empty">Nog geen nieuws. Kom snel terug!</p>
		<?php else : ?>
			<ol class="news-list">
				<?php foreach ( $pf_news as $pf_item ) : ?>
					<?php
					$pf_type = PF_Announcements::type( $pf_item );
					$pf_ts   = (int) get_post_timestamp( $pf_item );
					?>
					<li class="news-item is-<?php echo esc_attr( $pf_type ); ?>" id="nieuws-<?php echo (int) $pf_item->ID; ?>" data-news-id="<?php echo (int) $pf_item->ID; ?>">
						<input type="checkbox" class="news-check js-only" data-news-check aria-label="<?php echo esc_attr( 'Gelezen: ' . get_the_title( $pf_item ) ); ?>" title="Gelezen">
						<time class="news-date" datetime="<?php echo esc_attr( wp_date( 'c', $pf_ts ) ); ?>"><?php echo esc_html( wp_date( 'j M Y', $pf_ts ) ); ?></time>
						<div class="news-box">
							<div class="news-meta">
								<span class="news-kind"><?php echo esc_html( mb_strtoupper( PF_Announcements::TYPES[ $pf_type ] ) ); ?></span>
								<span class="news-new" hidden>NIEUW</span>
							</div>
							<h2 class="news-title"><?php echo esc_html( get_the_title( $pf_item ) ); ?></h2>
							<div class="news-body"><?php echo PF_Announcements::html( $pf_item ); // phpcs:ignore -- gefilterd met wp_kses ?></div>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>

			<?php if ( $pf_pages > 1 ) : ?>
				<nav class="news-pages" aria-label="Meer nieuws">
					<?php if ( $pf_page > 1 ) : ?>
						<a class="btn btn-ghost" href="<?php echo esc_url( PF_Announcements::page_url( $pf_page - 1 ) ); ?>" rel="prev">◀ NIEUWER</a>
					<?php endif; ?>
					<span class="news-count">PAGINA <?php echo (int) $pf_page; ?> / <?php echo (int) $pf_pages; ?></span>
					<?php if ( $pf_page < $pf_pages ) : ?>
						<a class="btn btn-ghost" href="<?php echo esc_url( PF_Announcements::page_url( $pf_page + 1 ) ); ?>" rel="next">OUDER ▶</a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</article>
	<?php
endwhile;
get_footer();
