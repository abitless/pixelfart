<?php
/**
 * Doneerknop (blok pixelfart/donate-button): een knop met hartje naar de doneerpagina.
 *
 * @var array $attributes
 */

defined( 'ABSPATH' ) || exit;

$pf_text = trim( (string) ( $attributes['text'] ?? '' ) ) ?: 'DONEER';
$pf_icon = function_exists( 'pf_icon' ) ? pf_icon( 'heart', 2 ) : '♥';
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'pf-donate-btn' ] ); // phpcs:ignore ?>>
	<a class="btn btn-pink" href="<?php echo esc_url( PF_Donations::page_url() ); ?>"><?php echo $pf_icon; // phpcs:ignore ?> <?php echo esc_html( mb_strtoupper( $pf_text ) ); ?></a>
</div>
