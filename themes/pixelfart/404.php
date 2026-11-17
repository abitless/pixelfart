<?php
get_header();
?>
<div class="notfound">
	<h1 class="h-pix">404 · GAME OVER</h1>
	<p style="margin:0;color:var(--dim)">Deze pagina bestaat niet (meer). Misschien is hij opgegeten door een pixel.</p>
	<a class="btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">◀ NAAR DE GAMES</a>
</div>
<?php
get_footer();
