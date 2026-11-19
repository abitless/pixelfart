<?php
/**
 * Footer: op de homepage het ideeënformulier, overal de aanmelding voor de nieuwsbrief (nieuwe games).
 */
$pf_home = ( is_front_page() || is_tax( 'pf_categorie' ) ) && ! get_query_var( 'pf_nieuwsbrief' );
?>
	</main>
	<section class="foot-forms<?php echo $pf_home ? ' has-idea' : ''; ?>" aria-label="Meedoen">
		<?php if ( $pf_home && class_exists( 'PF_Messages' ) ) : ?>
			<div class="foot-box foot-idea" id="idee">
				<div class="foot-head">
					<?php
					$b    = pf_pixel_icon( PF_BMP['bulb'], 4, 'px-icon' );
					$hi   = pf_pixel_icon( PF_BMP['bulbHi'], 4, 'px-icon bulb-hi', 'position:absolute;left:0;top:0' );
					$base = pf_pixel_icon( PF_BMP['bulbBase'], 4, 'px-icon bulb-base', 'top:36px' );
					?>
					<button type="button" class="bulb-btn" data-bulb tabindex="-1" aria-hidden="true"><span class="bulb" style="width:40px;height:56px"><?php echo $b . $hi . $base; // phpcs:ignore ?></span></button>
					<div>
						<h2 id="idea-title">IDEE OF VERZOEK?</h2>
						<p>Een spel dat je mist, of iets dat beter kan? Laat het ons weten.</p>
					</div>
				</div>
				<div class="sent-box" data-sent hidden>
					<strong>LAMPJE GAAT AAN!</strong>
					<span>Bedankt voor je idee. Misschien staat het straks tussen de games.</span>
					<button type="button" class="btn btn-ghost" data-again>NOG EEN IDEE</button>
				</div>
				<form class="js-only" data-form="idee" novalidate aria-labelledby="idea-title">
					<div class="idea-types" role="group" aria-label="Soort idee">
						<?php foreach ( PF_Messages::TYPES as $i => $t ) : ?>
							<button type="button" data-type="<?php echo esc_attr( $t ); ?>" aria-pressed="false" aria-controls="idea-fields"><?php echo esc_html( $t ); ?></button>
						<?php endforeach; ?>
					</div>
					<?php // Ingeklapt tot je een soort kiest: rustiger op de homepage. ?>
					<div class="idea-fields" id="idea-fields" data-idea-fields hidden>
					<label class="screen-reader-text" for="idea-text">Je idee</label>
					<textarea class="input" id="idea-text" name="text" rows="3" placeholder="Bijvoorbeeld: een spel met een pinguïn die moet glijden…"></textarea>
					<label class="screen-reader-text" for="idea-name">Je naam</label>
					<input class="input" id="idea-name" name="name" placeholder="Je naam (mag ook leeg)" autocomplete="nickname">
					<label class="hp" aria-hidden="true">Website <input name="hp" tabindex="-1" autocomplete="off"></label>
					<div class="robot" data-robot></div>
					<div class="form-error" data-error hidden></div>
					<button type="submit" class="btn btn-yellow" disabled>VERSTUUR ▶</button>
					</div>
				</form>
			</div>
		<?php endif; ?>

		<?php if ( class_exists( 'PF_Newsletter' ) ) : ?>
			<div class="foot-box foot-news" id="nieuwsbrief">
				<div class="foot-head">
					<span class="mail-icon" aria-hidden="true"><?php echo pf_icon( 'mail', 4 ); // phpcs:ignore ?></span>
					<div>
						<h2 id="news-title">NIEUWE GAME? MAIL!</h2>
						<p>Laat je e-mailadres achter, dan krijg je een mailtje als er een nieuwe game online staat. Verder niets.</p>
					</div>
				</div>
				<div class="sent-box" data-sent hidden>
					<strong>BIJNA KLAAR!</strong>
					<span>Kijk in je mail en klik op de link om je aanmelding te bevestigen. Geen mail? Kijk ook even bij spam.</span>
				</div>
				<form class="js-only" data-form="nieuwsbrief" novalidate aria-labelledby="news-title">
					<label class="screen-reader-text" for="news-email">Je e-mailadres</label>
					<div class="news-row">
						<input class="input" id="news-email" name="email" type="email" placeholder="jij@voorbeeld.nl" autocomplete="email" required>
						<button type="submit" class="btn btn-green">AANMELDEN ▶</button>
					</div>
					<label class="hp" aria-hidden="true">Website <input name="hp" tabindex="-1" autocomplete="off"></label>
					<div class="robot" data-robot></div>
					<div class="form-error" data-error hidden></div>
					<p class="fine">We bewaren alleen je e-mailadres en sturen alleen mails over nieuwe games. Afmelden kan in elke mail. Jonger dan 16? Vraag het eerst aan je ouders. <a href="<?php echo esc_url( get_privacy_policy_url() ?: home_url( '/privacy/' ) ); ?>">Privacy</a></p>
				</form>
			</div>
		<?php endif; ?>
	</section>
	<footer class="site-footer">
		<div class="foot-left">
			<span><span style="color:var(--green)" aria-hidden="true">■</span> <a href="<?php echo esc_url( get_option( 'pf_source_url' ) ?: ( defined( 'PIXELFART_SOURCE_URL' ) ? PIXELFART_SOURCE_URL : 'https://github.com/abitless/pixelfart' ) ); ?>" rel="noopener">Open source</a></span>
			<span><span style="color:var(--blue)" aria-hidden="true">■</span> <a href="<?php echo esc_url( get_privacy_policy_url() ?: home_url( '/privacy/' ) ); ?>">Privacy first</a> — geen cookies, geen tracking</span>
			<span><span style="color:var(--yellow)" aria-hidden="true">■</span> Een hobbyproject van vader &amp; zoon</span>
			<?php $pf_ouders = get_page_by_path( 'ouders' ); ?>
			<span><span style="color:var(--pink)" aria-hidden="true">■</span> <a href="<?php echo esc_url( $pf_ouders ? get_permalink( $pf_ouders ) : home_url( '/ouders/' ) ); ?>">Voor ouders</a></span>
		</div>
		<div class="foot-right">
			<?php if ( class_exists( 'PF_Donations' ) ) : ?>
				<a class="btn btn-pink foot-donate" href="<?php echo esc_url( PF_Donations::page_url() ); ?>"><?php echo pf_icon( 'heart', 2 ); // phpcs:ignore ?> DONEER</a>
			<?php endif; ?>
			<span>Hosting gesponsord door <a href="https://bitless.nl" target="_blank" rel="noopener">bitless</a></span>
			<span class="mute">Super groene hosting · gehost in Nederland</span>
		</div>
	</footer>
</div>
<?php wp_footer(); ?>
</body>
</html>
