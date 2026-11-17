<?php
/**
 * /nieuwsbrief/: aanmelding bevestigen of afmelden. Gebruikt de header, footer en stijl van het thema.
 */

defined( 'ABSPATH' ) || exit;

$pf_nb = PF_Newsletter::handle();
add_filter( 'pre_get_document_title', fn() => 'Nieuwsbrief · ' . get_bloginfo( 'name' ) );
get_header();
?>
<div class="page-wrap narrow nb-page">
	<h1 class="h-pix">NIEUWSBRIEF</h1>
	<?php if ( 'vraag' === $pf_nb['state'] ) : ?>
		<?php if ( 'bevestig' === $pf_nb['action'] ) : ?>
			<p>Wil je een mailtje op <strong><?php echo esc_html( $pf_nb['email'] ); ?></strong> als er een nieuwe game op Pixelfart staat?</p>
		<?php else : ?>
			<p>Wil je geen mails meer op <strong><?php echo esc_html( $pf_nb['email'] ); ?></strong>? Dan wissen we je adres meteen.</p>
		<?php endif; ?>
		<form method="post">
			<button type="submit" class="btn <?php echo 'bevestig' === $pf_nb['action'] ? 'btn-yellow' : ''; ?>"><?php echo 'bevestig' === $pf_nb['action'] ? 'JA, BEVESTIG ▶' : 'AFMELDEN ▶'; ?></button>
		</form>
	<?php elseif ( 'bevestigd' === $pf_nb['state'] ) : ?>
		<div class="sent-box"><strong>GELUKT!</strong><span>Je krijgt een mailtje op <?php echo esc_html( $pf_nb['email'] ); ?> zodra er een nieuwe game is. Afmelden kan met de link onderaan elke mail.</span></div>
		<a class="btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">NAAR DE GAMES ▶</a>
	<?php elseif ( 'afgemeld' === $pf_nb['state'] ) : ?>
		<div class="sent-box"><strong>AFGEMELD</strong><span>We hebben <?php echo esc_html( $pf_nb['email'] ); ?> gewist. Je krijgt geen mails meer.</span></div>
		<a class="btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">NAAR DE GAMES ▶</a>
	<?php else : ?>
		<p>Deze link klopt niet (meer). Misschien ben je al afgemeld, of is de bevestiging verlopen. Je kunt je onderaan elke pagina opnieuw aanmelden.</p>
		<a class="btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">NAAR DE GAMES ▶</a>
	<?php endif; ?>
</div>
<?php
get_footer();
