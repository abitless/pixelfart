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
<script>document.documentElement.classList.replace('no-js','js');try{sessionStorage.getItem('pf_logo')?document.documentElement.classList.add('logo-seen'):sessionStorage.setItem('pf_logo','1')}catch(e){}</script>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#main">Naar de inhoud</a>
<div class="site">
	<header class="site-header">
		<a class="logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Pixelfart, naar de games" rel="home">
			<?php echo file_get_contents( __DIR__ . '/parts/logo-art.svg' ); // phpcs:ignore -- eigen SVG (parts/logo-art.svg), geen invoer van buiten ?>
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
	<?php $pf_ann = class_exists( 'PF_Announcements' ) ? PF_Announcements::current() : null; ?>
	<?php if ( $pf_ann ) : ?>
		<aside class="announce" id="aankondiging" data-ann="<?php echo (int) $pf_ann->ID; ?>" aria-label="Aankondiging">
			<div class="announce-box">
				<span class="announce-icon" aria-hidden="true"><?php echo pf_icon( 'megaphone', 3 ); // phpcs:ignore ?></span>
				<div class="announce-body">
					<strong class="announce-title"><?php echo esc_html( get_the_title( $pf_ann ) ); ?></strong>
					<?php echo PF_Announcements::html( $pf_ann ); // phpcs:ignore -- gefilterd met wp_kses ?>
				</div>
				<button type="button" class="announce-close js-only" data-ann-close aria-label="Aankondiging sluiten">×</button>
			</div>
		</aside>
		<?php // Weggeklikt? Dan meteen verbergen, zonder eerst te knipperen. Alleen deze browser onthoudt het. ?>
		<script>try{if(localStorage.getItem('pf_ann')==='<?php echo (int) $pf_ann->ID; ?>')document.getElementById('aankondiging').hidden=true}catch(e){}</script>
	<?php endif; ?>
	<main class="site-main" id="main">
