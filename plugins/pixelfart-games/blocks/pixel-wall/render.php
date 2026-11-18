<?php
/**
 * Pixelmuur (blok pixelfart/pixel-wall). De muur zelf tekent assets/donate.js op een canvas;
 * de pixels komen uit GET /pixelfart/v1/pixels (zo is de muur altijd actueel, ook als de pagina gecachet is).
 */

defined( 'ABSPATH' ) || exit;

$pf_here  = PF_Donations::on_donate_page();
$pf_title = trim( (string) ( $attributes['title'] ?? 'ONZE PIXELFANS' ) );
$pf_keys = function_exists( 'pf_key' ) ? pf_key( 'left' ) . pf_key( 'up' ) . pf_key( 'down' ) . pf_key( 'right' ) . ' ' . pf_key( 'SPATIE' ) : '';
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'pf-wall' ] ); // phpcs:ignore ?> data-pixel-wall<?php echo $pf_here ? ' data-here id="pixelmuur"' : ''; ?>>
	<noscript><p>Voor de pixelmuur heb je JavaScript nodig.</p></noscript>
	<div class="wall-frame js-only">
		<?php if ( '' !== $pf_title ) : ?>
			<h3 class="wall-title"><span class="wall-title-deco" aria-hidden="true"></span><?php echo esc_html( $pf_title ); ?><span class="wall-title-deco" aria-hidden="true"></span></h3>
		<?php endif; ?>
		<div class="wall-scroll" data-wall-scroll>
			<canvas class="wall-canvas" data-wall-canvas width="480" height="240" tabindex="0" role="img" aria-label="Pixelmuur. Klik op een gekleurde pixel om te zien wie hem kocht."></canvas>
		</div>
		<div class="wall-info" data-wall-info aria-live="polite" hidden></div>
	</div>

	<?php if ( $pf_here ) : ?>
		<div class="wall-tools js-only">
			<div class="wall-paint">
				<div class="wall-palette" data-wall-palette role="radiogroup" aria-label="Kleur"></div>
				<button type="button" class="btn btn-ghost wall-reset" data-wall-reset disabled>RESET</button>
			</div>
			<p class="fine wall-help">Kies een kleur en klik op lege vakjes. Klik nog eens om een vakje weer leeg te maken, of begin opnieuw met RESET.<?php if ( $pf_keys ) : ?> Of gebruik <span class="nf-keys" aria-hidden="true"><?php echo $pf_keys; // phpcs:ignore ?></span><?php endif; ?></p>
		</div>
		<form class="wall-buy js-only" data-wall-buy novalidate hidden>
			<p class="wall-count" data-wall-count></p>
			<div class="wall-fields">
				<label class="c-who"><span>Je naam</span> <input class="input initials" name="name" maxlength="3" autocomplete="off" spellcheck="false" placeholder="ABC" aria-describedby="wall-name-help"></label>
				<label class="wall-msg"><span>Je boodschap <small data-msg-left></small></span>
					<textarea class="input" name="message" rows="2" maxlength="<?php echo (int) PF_Donations::MSG_MAX; ?>" placeholder="Bijvoorbeeld: Flipperkoorts is de beste game!"></textarea>
				</label>
			</div>
			<p class="fine" id="wall-name-help">Drie letters, geen echte naam. Iedereen die op je pixels klikt, ziet je letters, je boodschap, de datum en hoeveel pixels je kocht.<?php echo PF_Donations::moderate() ? ' Er spelen hier kinderen: je tekening, letters en boodschap verschijnen nadat wij ze bekeken hebben. Jij ziet je tekening meteen.' : ''; ?></p>
			<label class="hp" aria-hidden="true">Website <input name="hp" tabindex="-1" autocomplete="off"></label>
			<div class="robot" data-robot></div>
			<?php if ( PF_Donations::fee( false ) ) : ?>
				<label class="check"><input type="checkbox" name="kosten" value="1"> <span data-cover-label>Ik betaal de kosten van de betaaldienst ook</span></label>
			<?php endif; ?>
			<p class="fine pf-fee" data-fee></p>
			<div class="form-error" data-error hidden></div>
			<div class="wall-actions">
				<button type="submit" class="btn btn-pink" data-submit>KOOP</button>
			</div>
			<p class="fine">Je betaalt veilig met iDEAL | Wero via Mollie. Jonger dan 16? Vraag het eerst aan je ouders.</p>
		</form>
	<?php elseif ( $attributes['link'] ?? true ) : ?>
		<p class="js-only"><a class="btn btn-pink" href="<?php echo esc_url( PF_Donations::page_url() ); ?>#pixelmuur">KOOP OOK EEN PIXEL ▶</a></p>
	<?php endif; ?>
</div>
