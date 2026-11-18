<?php
/**
 * Doneren (blok pixelfart/donate-widget): bedrag en eenmalig/per maand kiezen.
 * Op de doneerpagina betaal je meteen; elders brengt de knop je met je keuze naar de doneerpagina.
 * Zonder JavaScript werkt het als gewoon formulier naar de doneerpagina.
 *
 * @var array $attributes
 */

defined( 'ABSPATH' ) || exit;

$pf_here  = PF_Donations::on_donate_page();
$pf_uid   = wp_unique_id( 'pfd-' );
$pf_title = trim( (string) ( $attributes['title'] ?? '' ) );
$pf_text  = trim( (string) ( $attributes['text'] ?? '' ) );
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'pf-donate' ] ); // phpcs:ignore ?> data-donate-widget<?php echo $pf_here ? ' data-here id="doneer"' : ''; ?>>
	<?php if ( $pf_title ) : ?>
		<h2 class="pf-donate-title"><?php echo function_exists( 'pf_icon' ) ? pf_icon( 'heart', 3 ) : ''; // phpcs:ignore ?> <?php echo esc_html( mb_strtoupper( $pf_title ) ); ?></h2>
	<?php endif; ?>
	<?php if ( $pf_text ) : ?>
		<p class="pf-donate-text"><?php echo esc_html( $pf_text ); ?></p>
	<?php endif; ?>
	<form class="pf-donate-form" method="get" action="<?php echo esc_url( PF_Donations::page_url() ); ?>#doneer" novalidate>
		<fieldset class="pick">
			<legend>Bedrag</legend>
			<?php foreach ( PF_Donations::AMOUNTS as $pf_i => $pf_c ) : ?>
				<label class="pick-opt"><input type="radio" name="bedrag" value="<?php echo (int) ( $pf_c / 100 ); ?>" <?php checked( 0 === $pf_i ); ?>><span><?php echo esc_html( PF_Donations::euro( $pf_c ) ); ?></span></label>
			<?php endforeach; ?>
		</fieldset>
		<fieldset class="pick pick-small">
			<legend>Hoe vaak</legend>
			<label class="pick-opt"><input type="radio" name="per" value="eenmalig" checked><span>EENMALIG</span></label>
			<label class="pick-opt"><input type="radio" name="per" value="maand"><span>PER MAAND</span></label>
		</fieldset>
		<?php if ( $pf_here ) : ?>
			<div class="pf-donate-email" data-email hidden>
				<label for="<?php echo esc_attr( $pf_uid ); ?>-mail">Je e-mailadres</label>
				<input class="input" id="<?php echo esc_attr( $pf_uid ); ?>-mail" name="email" type="email" autocomplete="email" placeholder="jij@voorbeeld.nl">
				<p class="fine">Alleen voor een bedankmail met een link om te stoppen. Stoppen kan altijd met één klik; daarna wissen we je adres.</p>
			</div>
			<label class="hp" aria-hidden="true">Website <input name="hp" tabindex="-1" autocomplete="off"></label>
		<?php endif; ?>
		<?php if ( PF_Donations::fee( false ) ) : ?>
			<label class="check"><input type="checkbox" name="kosten" value="1"> <span data-cover-label>Ik betaal de kosten van de betaaldienst ook</span></label>
		<?php endif; ?>
		<p class="fine pf-fee" data-fee></p>
		<div class="form-error" data-error hidden></div>
		<button type="submit" class="btn btn-pink" data-submit>DONEER <?php echo esc_html( PF_Donations::euro( PF_Donations::AMOUNTS[0] ) ); ?> ▶</button>
		<?php if ( $pf_here ) : ?>
			<p class="fine">Je betaalt veilig met iDEAL | Wero via Mollie. Jonger dan 16? Vraag het eerst aan je ouders.</p>
		<?php endif; ?>
	</form>
</div>
