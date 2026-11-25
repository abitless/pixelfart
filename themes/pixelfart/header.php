<?php
/**
 * Header met logo en de grijpmachine als menu.
 */
[ $pf_items, $pf_active ] = pf_nav_items();
$pf_news_list             = class_exists( 'PF_Announcements' ) ? PF_Announcements::recent() : [];
?><!doctype html>
<html <?php language_attributes(); ?> class="no-js">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#000000">
<script>(function(h){h.classList.replace('no-js','js');try{var v=sessionStorage.getItem('pf_logo')||'',t=Date.now()-(+v.split(':')[1]||0);if(v==='1'||(v&&t>6e3))h.classList.add('logo-seen');else if(v)h.style.setProperty('--la-start',-t/1e3+'s');sessionStorage.setItem('pf_logo','1')}catch(e){}})(document.documentElement)</script>
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
					<li><a href="<?php echo esc_url( $item['url'] ); ?>" data-i="<?php echo (int) $i; ?>" class="<?php echo $i === $pf_active ? 'is-active' : ''; ?>" <?php echo $i === $pf_active ? 'aria-current="page"' : ''; ?>><?php if ( 'nieuws' === $item['slug'] && $pf_news_list ) : // rood bolletje: site.js kijkt welke berichten deze browser al gelezen heeft ?><span class="claw-label"><?php echo esc_html( $item['label'] ); ?><span class="news-dot" data-news-dot data-news="<?php echo esc_attr( wp_json_encode( $pf_news_list ) ); ?>" hidden><span class="screen-reader-text"> (ongelezen nieuws)</span></span></span><?php else : ?><?php echo esc_html( $item['label'] ); ?><?php endif; ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
		</div>
	</header>
	<?php
	$pf_ann = ( is_front_page() && class_exists( 'PF_Announcements' ) ) ? PF_Announcements::current() : null; // alleen op de homepage
	if ( $pf_ann ) :
		$pf_ann_auto = PF_Announcements::is_auto( $pf_ann ) ? $pf_ann : PF_Announcements::fallback( $pf_ann ); // reserve: komt in beeld als je de handmatige wegklikt
		$pf_ann_vast = PF_Announcements::important( $pf_ann ); // belangrijke mededeling: niet weg te klikken
		?>
		<?php if ( ! PF_Announcements::is_auto( $pf_ann ) ) : ?>
			<aside class="announce" id="aankondiging" data-ann="<?php echo (int) $pf_ann->ID; ?>" aria-label="Aankondiging">
				<div class="announce-box">
					<span class="announce-icon" aria-hidden="true"><?php echo pf_icon( 'megaphone', 3 ); // phpcs:ignore ?></span>
					<div class="announce-body">
						<strong class="announce-title"><?php echo esc_html( get_the_title( $pf_ann ) ); ?></strong>
						<?php echo PF_Announcements::html( $pf_ann ); // phpcs:ignore -- gefilterd met wp_kses ?>
					</div>
					<?php if ( ! $pf_ann_vast ) : ?>
						<button type="button" class="announce-close js-only" data-ann-close aria-label="Aankondiging sluiten">×</button>
					<?php endif; ?>
				</div>
			</aside>
		<?php endif; ?>
		<?php if ( $pf_ann_auto ) : // nieuwe game of update: één regel, met "Lees meer" naar de nieuwspagina ?>
			<?php
			$pf_ann_game = PF_Announcements::game( $pf_ann_auto );
			$pf_ann_type = PF_Announcements::type( $pf_ann_auto );
			?>
			<aside class="announce announce-line is-<?php echo esc_attr( $pf_ann_type ); ?>" id="aankondiging-regel" data-ann="<?php echo (int) $pf_ann_auto->ID; ?>" aria-label="Aankondiging" <?php echo $pf_ann_auto === $pf_ann ? '' : 'hidden'; ?>>
				<div class="announce-box">
					<span class="announce-kind"><?php echo esc_html( mb_strtoupper( PF_Announcements::TYPES[ $pf_ann_type ] ) ); ?>:</span>
					<?php if ( $pf_ann_game ) : ?>
						<a class="announce-game" href="<?php echo esc_url( get_permalink( $pf_ann_game ) ); ?>"><?php echo esc_html( get_the_title( $pf_ann_game ) ); ?></a>
					<?php else : ?>
						<span class="announce-game"><?php echo esc_html( get_the_title( $pf_ann_auto ) ); ?></span>
					<?php endif; ?>
					<a class="announce-more" href="<?php echo esc_url( PF_Announcements::news_url( $pf_ann_auto ) ); ?>">Lees meer ▶</a>
					<button type="button" class="announce-close js-only" data-ann-close aria-label="Aankondiging sluiten">×</button>
				</div>
			</aside>
		<?php endif; ?>
		<?php // Weggeklikt? Dan meteen verbergen, zonder eerst te knipperen, en de regel eronder tonen. Alleen deze browser onthoudt het (pf_ann: lijst met id's). ?>
		<script>(function(){var g;try{g=JSON.parse(localStorage.getItem('pf_ann')||'[]')}catch(e){g=[]}if(!Array.isArray(g))g=[String(g)];var a=document.getElementById('aankondiging'),r=document.getElementById('aankondiging-regel'),x=function(el){return el&&g.indexOf(el.dataset.ann)>-1};<?php echo $pf_ann_vast ? '' : 'if(x(a))a.hidden=true;'; ?>if(r)r.hidden=x(r)||!!(a&&!a.hidden)})()</script>
	<?php endif; ?>
	<main class="site-main" id="main">
