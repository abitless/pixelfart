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
$pf_hs   = class_exists( 'PF_Scores' ) && PF_Scores::enabled( $g ) ? [ 'order' => $g['score']['order'], 'label' => $g['score']['label'], 'top' => PF_Scores::top( $g['id'] ) ] : null;
$pf_back = wp_get_referer() && str_starts_with( wp_get_referer(), home_url() ) && ! str_contains( wp_get_referer(), '/spel/' ) ? wp_get_referer() : home_url( '/' );
?><!doctype html>
<html <?php language_attributes(); ?> class="no-js">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#000000">
<?php if ( ! defined( 'WPSEO_VERSION' ) ) : // zonder Yoast SEO: de basis zelf (anders doet PF_SEO het via Yoast) ?>
<?php if ( $g['description'] ) : ?><meta name="description" content="<?php echo esc_attr( $g['description'] ); ?>"><?php endif; ?>
<?php if ( $g['thumb_url'] ) : ?><meta property="og:image" content="<?php echo esc_url( $g['thumb_url'] ); ?>"><?php endif; ?>
<?php endif; ?>
<script>document.documentElement.classList.replace('no-js','js');</script>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'player-page' ); ?>>
<?php wp_body_open(); ?>
<div class="player" data-player data-id="<?php echo (int) $g['id']; ?>" data-version="<?php echo esc_attr( $g['version'] ); ?>"<?php if ( $pf_hs ) : ?> data-scores="<?php echo esc_attr( wp_json_encode( $pf_hs ) ); ?>"<?php endif; ?>>
	<div class="player-bar">
		<a class="btn" href="<?php echo esc_url( $pf_back ); ?>" data-back>◀ TERUG</a>
		<h1 class="player-title"><?php echo esc_html( $g['title'] ); ?></h1>
		<span class="player-rating"><?php echo pf_stars( (float) $g['rating'], (int) $g['rating_cnt'] ); // phpcs:ignore ?> <span class="player-votes" title="Aantal stemmen">(<span data-cnt><?php echo (int) $g['rating_cnt']; ?></span>)</span></span>
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
			<?php if ( $pf_hs ) : ?>
				<form class="hs-entry" data-hs-entry hidden novalidate aria-labelledby="hs-title">
					<div class="hs-box">
						<h2 class="hs-title" id="hs-title">NIEUWE TOPSCORE!</h2>
						<div class="hs-got"><span data-hs-score></span> <?php echo esc_html( $pf_hs['label'] ); ?> · plek <span data-hs-rank></span></div>
						<p class="hs-help">Kies je drie letters <span class="hs-keys"><?php echo pf_key( 'up' ) . pf_key( 'down' ); // phpcs:ignore ?> of typen, <?php echo pf_key( 'ENTER' ); // phpcs:ignore ?> = opslaan</span></p>
						<div class="hs-letters" role="group" aria-label="Initialen">
							<?php for ( $i = 1; $i <= 3; $i++ ) : ?>
								<div class="hs-slot">
									<button type="button" data-up tabindex="-1" aria-label="Letter <?php echo $i; ?> omhoog">▲</button>
									<input class="hs-letter" maxlength="1" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-label="Letter <?php echo $i; ?>">
									<button type="button" data-down tabindex="-1" aria-label="Letter <?php echo $i; ?> omlaag">▼</button>
								</div>
							<?php endfor; ?>
						</div>
						<div class="robot" data-robot></div>
						<div class="form-error" data-error hidden></div>
						<div class="hs-actions">
							<button type="submit" class="btn btn-yellow">OPSLAAN ▶</button>
							<button type="button" class="btn btn-ghost" data-hs-skip>OVERSLAAN</button>
						</div>
						<p class="hs-privacy">We bewaren alleen deze drie letters, je score en de datum.</p>
					</div>
				</form>
			<?php endif; ?>
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
					<dt>Licentie</dt><dd><a href="https://www.gnu.org/licenses/gpl-3.0.html" rel="license noopener" target="_blank">GPL-3.0</a> · <a href="https://creativecommons.org/licenses/by-sa/4.0/deed.nl" rel="license noopener" target="_blank">CC BY-SA</a></dd>
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

			<?php if ( $pf_hs ) : ?>
				<section class="panel-sec">
					<h2>TOPSCORES</h2>
					<ol class="hiscore-list" data-hiscores>
						<?php foreach ( $pf_hs['top'] as $s ) : ?>
							<li><span class="hs-rank"><?php echo (int) $s['rank']; ?></span><span class="hs-name"><?php echo esc_html( $s['name'] ); ?></span><span class="hs-score"><?php echo esc_html( pf_num( $s['score'] ) ); ?></span></li>
						<?php endforeach; ?>
					</ol>
					<div class="muted" data-hs-empty <?php echo $pf_hs['top'] ? 'hidden' : ''; ?>>Nog geen scores. Word jij de eerste?</div>
					<div class="muted"><?php echo esc_html( 'asc' === $pf_hs['order'] ? 'Lager is beter' : 'Hoger is beter' ); ?> · in <?php echo esc_html( $pf_hs['label'] ); ?></div>
					<?php $pf_hs_page = get_page_by_path( 'topscores' ); ?>
					<?php if ( $pf_hs_page ) : ?><a href="<?php echo esc_url( get_permalink( $pf_hs_page ) ); ?>">Alle topscores ▸</a><?php endif; ?>
				</section>

				<div class="panel-rule"></div>
			<?php endif; ?>

			<section class="panel-sec">
				<h2>REACTIES (<?php echo count( $pf_comments ); ?>)</h2>
				<?php if ( comments_open() ) : ?>
					<form class="js-only" data-comment-form novalidate>
						<div class="c-who">
							<label for="c-name">Jouw 3 letters</label>
							<input class="input initials" id="c-name" name="name" placeholder="ABC" maxlength="3" minlength="3" pattern="[A-Za-z]{3}" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
						</div>
						<label class="screen-reader-text" for="c-text">Reactie</label>
						<textarea class="input" id="c-text" name="text" rows="3" placeholder="Wat vond je ervan?" maxlength="600"></textarea>
						<label class="hp" aria-hidden="true">Website <input name="hp" tabindex="-1" autocomplete="off"></label>
						<div class="robot" data-robot></div>
						<div class="form-error" data-error hidden></div>
						<div class="form-ok" data-ok hidden>Bedankt! Je reactie verschijnt zodra we hem gelezen hebben.</div>
						<button type="submit" class="btn" disabled>PLAATS ▶</button>
					</form>
				<?php endif; ?>
				<?php if ( $pf_comments ) : ?>
					<ul class="comments">
						<?php foreach ( $pf_comments as $c ) : ?>
							<li>
								<?php $pf_ver = get_comment_meta( $c->comment_ID, 'pf_version', true ); ?>
								<details class="comment">
									<summary class="comment-head">
										<span class="comment-name" style="color:<?php echo esc_attr( pf_name_color( $c->comment_author ) ); ?>"><?php echo esc_html( $c->comment_author ); ?></span>
										<time datetime="<?php echo esc_attr( mysql2date( 'c', $c->comment_date_gmt ) ); ?>"><?php echo esc_html( pf_when( $c->comment_date_gmt ) ); ?></time>
										<span class="comment-i" title="Meer info" aria-label="Meer info">i</span>
									</summary>
									<dl class="comment-info">
										<dt>Datum</dt><dd><?php echo esc_html( wp_date( 'j F Y, H:i', strtotime( $c->comment_date_gmt . ' UTC' ) ) ); ?></dd>
										<dt>Gameversie</dt><dd><?php echo esc_html( $pf_ver ?: 'onbekend' ); ?><?php echo $pf_ver && $pf_ver !== $g['version'] ? ' <span class="muted">(nu ' . esc_html( $g['version'] ) . ')</span>' : ''; ?></dd>
									</dl>
								</details>
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
