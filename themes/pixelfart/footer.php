<?php
/**
 * Footer en het idee-venster (opent vanaf het lampje).
 */
?>
	</main>
	<footer class="site-footer">
		<div class="foot-left">
			<span><span style="color:var(--green)" aria-hidden="true">■</span> <a href="<?php echo esc_url( get_option( 'pf_source_url' ) ?: '#' ); ?>">Open source</a></span>
			<span><span style="color:var(--blue)" aria-hidden="true">■</span> Privacy first — geen cookies, geen tracking</span>
			<span><span style="color:var(--yellow)" aria-hidden="true">■</span> Een hobbyproject van vader &amp; zoon</span>
		</div>
		<div class="foot-right">
			<span>Hosting gesponsord door <a href="https://bitless.nl" target="_blank" rel="noopener">bitless</a></span>
			<span class="mute">Super groene hosting · gehost in Nederland</span>
		</div>
	</footer>
</div>

<dialog class="modal" id="idea-modal" aria-labelledby="idea-title">
	<div class="modal-box">
		<div class="modal-head">
			<h2 id="idea-title">IDEE OF VERZOEK</h2>
			<button type="button" class="modal-x" data-close aria-label="Sluiten">X</button>
		</div>
		<div data-sent hidden>
			<p style="margin:0 0 14px;line-height:1.2">Lampje gaat aan! Bedankt voor je idee. Misschien staat het straks hier tussen de games.</p>
			<button type="button" class="btn btn-yellow" data-close>SLUITEN</button>
		</div>
		<form data-form="idee" novalidate>
			<div class="idea-types" role="group" aria-label="Soort idee">
				<?php foreach ( PF_Messages::TYPES as $i => $t ) : ?>
					<button type="button" data-type="<?php echo esc_attr( $t ); ?>" aria-pressed="<?php echo 0 === $i ? 'true' : 'false'; ?>"><?php echo esc_html( $t ); ?></button>
				<?php endforeach; ?>
			</div>
			<label class="screen-reader-text" for="idea-text">Je idee</label>
			<textarea class="input" id="idea-text" name="text" rows="4" placeholder="Bijvoorbeeld: een spel met een pinguïn die moet glijden…"></textarea>
			<label class="screen-reader-text" for="idea-name">Je naam</label>
			<input class="input" id="idea-name" name="name" placeholder="Je naam (mag ook leeg)" autocomplete="nickname">
			<label class="hp" aria-hidden="true">Website <input name="hp" tabindex="-1" autocomplete="off"></label>
			<div class="form-error" data-error hidden></div>
			<button type="submit" class="btn btn-yellow" disabled>VERSTUUR ▶</button>
		</form>
	</div>
</dialog>
<?php wp_footer(); ?>
</body>
</html>
