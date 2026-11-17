<?php
/**
 * Spelerpagina: de game schermvullend in een iframe, met een zijpaneel voor info, rating en reacties.
 */

the_post();
$g = PF_Games::get( get_post() );

$pf_comments = get_comments( [
	'post_id' => $g['id'],
	'status'  => 'approve',
	'type'    => 'comment',
	'orderby' => 'comment_date_gmt',
	'order'   => 'DESC',
] );
$pf_back = wp_get_referer() && str_starts_with( wp_get_referer(), home_url() ) && ! str_contains( wp_get_referer(), '/spel/' ) ? wp_get_referer() : home_url( '/' );
?><!doctype html>
<html <?php language_attributes(); ?> class="no-js">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#000000">
<?php if ( $g['description'] ) : ?><meta name="description" content="<?php echo esc_attr( $g['description'] ); ?>"><?php endif; ?>
<?php if ( $g['thumb_url'] ) : ?><meta property="og:image" content="<?php echo esc_url( $g['thumb_url'] ); ?>"><?php endif; ?>
<script>document.documentElement.classList.replace('no-js','js');</script>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'player-page' ); ?>>
<?php wp_body_open(); ?>
<div class="player" data-player data-id="<?php echo (int) $g['id']; ?>">
	<div class="player-bar">
		<a class="btn" href="<?php echo esc_url( $pf_back ); ?>" data-back>◀ TERUG</a>
		<h1 class="player-title"><?php echo esc_html( $g['title'] ); ?></h1>
		<span class="player-rating" title="Gemiddelde rating">★ <span data-avg><?php echo esc_html( pf_avg( $g ) ); ?></span> (<span data-cnt><?php echo (int) $g['rating_cnt']; ?></span>)</span>
		<button type="button" class="tool-btn js-only" data-fs aria-label="Volledig scherm" style="display:inline-flex;align-items:center;gap:8px"><?php echo pf_icon( 'fs' ); // phpcs:ignore ?><span class="lbl">VOLLEDIG</span></button>
		<button type="button" class="tool-btn js-only" data-panel-toggle aria-controls="game-panel" aria-expanded="true">VERBERG ▸</button>
	</div>
	<div class="player-body">
		<div class="player-stage" data-stage>
			<?php if ( $g['game_url'] ) : ?>
				<iframe src="<?php echo esc_url( $g['game_url'] ); ?>" title="<?php echo esc_attr( $g['title'] ); ?>" sandbox="<?php echo esc_attr( PF_Hosting::SANDBOX ); ?>" allow="fullscreen; autoplay; gamepad" allowfullscreen referrerpolicy="no-referrer" data-frame></iframe>
			<?php else : ?>
				<p class="player-missing">Deze game wordt nog gebouwd. Kom snel terug!</p>
			<?php endif; ?>
			<button type="button" class="tool-btn fs-exit" data-fs-exit hidden>✕ SLUIT</button>
		</div>

		<aside class="panel" id="game-panel" data-panel>
			<section class="panel-sec">
				<h2>OVER DEZE GAME</h2>
				<?php if ( $g['description'] ) : ?><p class="game-desc"><?php echo esc_html( $g['description'] ); ?></p><?php endif; ?>
				<div class="chips"><?php foreach ( $g['categories'] as $c ) { echo pf_chip( $c ); } // phpcs:ignore ?></div>
				<dl class="game-facts">
					<dt>Leeftijd</dt><dd><?php echo esc_html( 'ALL' === $g['age'] ? 'iedereen' : $g['age'] ); ?></dd>
					<?php if ( $g['author'] ) : ?><dt>Gemaakt door</dt><dd><?php echo esc_html( $g['author'] ); ?></dd><?php endif; ?>
					<dt>Online sinds</dt><dd><?php echo esc_html( wp_date( 'j F Y', strtotime( $g['published'] ) ) ); ?></dd>
					<dt>Versie</dt><dd><?php echo esc_html( $g['version'] ); ?> · <?php echo esc_html( $g['code'] ); ?></dd>
					<dt>Gespeeld</dt><dd><span data-plays><?php echo esc_html( pf_num( $g['plays'] ) ); ?></span>×</dd>
				</dl>
			</section>

			<div class="panel-rule"></div>

			<section class="panel-sec js-only">
				<h2 id="rate-h">JOUW RATING</h2>
				<div class="stars" data-stars role="group" aria-labelledby="rate-h">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<button type="button" data-star="<?php echo $i; ?>" aria-label="<?php echo $i . ' ' . ( 1 === $i ? 'ster' : 'sterren' ); ?>"><?php echo pf_icon( 'star', 4 ); // phpcs:ignore ?></button>
					<?php endfor; ?>
				</div>
				<div class="muted" data-rate-note aria-live="polite">Klik op een ster om te stemmen.</div>
			</section>

			<div class="panel-rule"></div>

			<section class="panel-sec">
				<h2>REACTIES (<?php echo count( $pf_comments ); ?>)</h2>
				<?php if ( comments_open() ) : ?>
					<form class="js-only" data-comment-form novalidate>
						<label class="screen-reader-text" for="c-name">Naam</label>
						<input class="input" id="c-name" name="name" placeholder="Naam" maxlength="40" autocomplete="nickname">
						<label class="screen-reader-text" for="c-text">Reactie</label>
						<textarea class="input" id="c-text" name="text" rows="3" placeholder="Wat vond je ervan?" maxlength="600"></textarea>
						<label class="hp" aria-hidden="true">Website <input name="hp" tabindex="-1" autocomplete="off"></label>
						<div class="form-error" data-error hidden></div>
						<div class="form-ok" data-ok hidden>Bedankt! Je reactie verschijnt zodra we hem gelezen hebben.</div>
						<button type="submit" class="btn" disabled>PLAATS ▶</button>
					</form>
				<?php endif; ?>
				<?php if ( $pf_comments ) : ?>
					<ul class="comments">
						<?php foreach ( $pf_comments as $c ) : ?>
							<li>
								<div class="comment-head">
									<span style="color:<?php echo esc_attr( pf_name_color( $c->comment_author ) ); ?>"><?php echo esc_html( $c->comment_author ); ?></span>
									<time datetime="<?php echo esc_attr( mysql2date( 'c', $c->comment_date_gmt ) ); ?>"><?php echo esc_html( pf_when( $c->comment_date_gmt ) ); ?></time>
								</div>
								<div class="comment-text"><?php echo nl2br( esc_html( $c->comment_content ) ); ?></div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<div class="muted">Nog geen reacties. Wees de eerste!</div>
				<?php endif; ?>
			</section>
		</aside>
	</div>
</div>
<?php wp_footer(); ?>
</body>
</html>
