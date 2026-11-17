<?php
/**
 * Header met logo en de grijpmachine als menu.
 */
[ $pf_items, $pf_active ] = pf_nav_items();
?><!doctype html>
<html <?php language_attributes(); ?> class="no-js">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#000000">
<script>document.documentElement.classList.replace('no-js','js');</script>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#main">Naar de inhoud</a>
<div class="site">
	<header class="site-header">
		<a class="logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Pixelfart, naar de games" rel="home">
			<span>PIXEL</span><span class="logo-f" aria-hidden="true">f</span><span>ART</span>
		</a>
		<div class="nav-wrap">
		<span class="claw-keys js-only" title="Gebruik de pijltjestoetsen om door het menu te gaan" aria-hidden="true"><?php echo pf_key( 'left' ) . pf_key( 'right' ); // phpcs:ignore ?></span>
		<nav class="claw-nav" aria-label="Hoofdmenu" style="--sel:<?php echo (int) max( 0, $pf_active ); ?>;--n:<?php echo count( $pf_items ); ?>" <?php echo $pf_active < 0 ? 'data-phase="release"' : ''; ?>>
			<div class="claw-rail" aria-hidden="true"></div>
			<div class="claw" aria-hidden="true">
				<div class="claw-trolley"></div>
				<div class="claw-rope"></div>
				<div class="claw-head"></div>
				<div class="claw-arms"><span></span><span></span></div>
			</div>
			<ul class="claw-menu">
				<?php foreach ( $pf_items as $i => $item ) : ?>
					<li><a href="<?php echo esc_url( $item['url'] ); ?>" data-i="<?php echo (int) $i; ?>" class="<?php echo $i === $pf_active ? 'is-active' : ''; ?>" <?php echo $i === $pf_active ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $item['label'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
		</div>
	</header>
	<main class="site-main" id="main">
