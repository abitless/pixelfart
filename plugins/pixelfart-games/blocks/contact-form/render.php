<?php
/**
 * Contactformulier (blok pixelfart/contact-form). Versturen gaat via de REST-route pixelfart/v1/messages.
 *
 * @var array    $attributes
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'pf-contact' ] ); // phpcs:ignore ?>>
	<div class="sent-box" data-sent hidden>
		<strong>BERICHT VERSTUURD!</strong>
		<div>Bedankt <span data-sent-name></span>. We lezen alles, maar soms pas na het huiswerk.</div>
		<button type="button" class="btn btn-ghost" data-again>NOG EEN BERICHT</button>
	</div>
	<form data-form="contact" novalidate>
		<label class="field"><span>NAAM</span><input class="input" name="name" autocomplete="name" maxlength="60" required></label>
		<label class="field"><span>E-MAIL</span><input class="input" name="email" type="email" autocomplete="email" required></label>
		<label class="field"><span>BERICHT</span><textarea class="input" name="text" rows="5" maxlength="3000" required></textarea></label>
		<label class="hp" aria-hidden="true">Website <input name="hp" tabindex="-1" autocomplete="off"></label>
		<div class="robot" data-robot></div>
		<div class="form-error" data-error hidden></div>
		<noscript><p class="form-error">Het formulier werkt met JavaScript. Zet het aan, dan kun je ons een bericht sturen.</p></noscript>
		<button type="submit" class="btn">VERSTUUR ▶</button>
	</form>
</div>
