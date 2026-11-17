<?php
/**
 * Contactpagina: intro uit de editor + het formulier (komt binnen onder Games › Berichten).
 */
get_header();
the_post();
?>
<article class="page-wrap narrow">
	<h1 class="h-pix"><?php echo esc_html( mb_strtoupper( get_the_title() ) ); ?></h1>
	<div class="sent-box" data-sent hidden>
		<strong>BERICHT VERSTUURD!</strong>
		<div>Bedankt <span data-sent-name></span>. We lezen alles, maar soms pas na het huiswerk.</div>
		<button type="button" class="btn btn-ghost" data-again>NOG EEN BERICHT</button>
	</div>
	<form class="page-wrap narrow" data-form="contact" novalidate style="gap:24px">
		<div class="entry" style="color:var(--dim)"><?php the_content(); ?></div>
		<label class="field"><span>NAAM</span><input class="input" name="name" autocomplete="name" required></label>
		<label class="field"><span>E-MAIL</span><input class="input" name="email" type="email" autocomplete="email" required></label>
		<label class="field"><span>BERICHT</span><textarea class="input" name="text" rows="5" required></textarea></label>
		<label class="hp" aria-hidden="true">Website <input name="hp" tabindex="-1" autocomplete="off"></label>
		<div class="form-error" data-error hidden></div>
		<noscript><p class="form-error">Het formulier werkt met JavaScript. Zet het aan, dan kun je ons een bericht sturen.</p></noscript>
		<button type="submit" class="btn">VERSTUUR ▶</button>
	</form>
</article>
<?php
get_footer();
