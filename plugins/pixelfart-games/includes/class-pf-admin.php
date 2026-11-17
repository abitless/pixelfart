<?php
/**
 * Beheer: uploadformulier met metadata op het bewerkscherm van een game, kolommen in het overzicht.
 */

defined( 'ABSPATH' ) || exit;

class PF_Admin {

	const NONCE = 'pf_game_save';
	const MAX_HTML_BYTES = 15 * MB_IN_BYTES;

	public static function init() {
		add_action( 'add_meta_boxes_' . PF_Games::CPT, [ __CLASS__, 'meta_boxes' ] );
		add_action( 'post_edit_form_tag', [ __CLASS__, 'form_enctype' ] );
		add_action( 'save_post_' . PF_Games::CPT, [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_filter( 'wp_insert_post_data', [ __CLASS__, 'default_open_comments' ], 10, 2 );
		add_filter( 'enter_title_here', fn( $t, $p ) => PF_Games::CPT === $p->post_type ? 'Titel van de game' : $t, 10, 2 );

		add_filter( 'manage_' . PF_Games::CPT . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . PF_Games::CPT . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
		add_filter( 'manage_edit-' . PF_Games::CPT . '_sortable_columns', fn( $c ) => $c + [ 'pf_plays' => 'pf_plays' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'sort_by_plays' ] );
		add_action( 'admin_post_pf_restore_version', [ __CLASS__, 'restore_version' ] );
	}

	public static function form_enctype( WP_Post $post ) {
		if ( PF_Games::CPT === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	public static function default_open_comments( array $data, array $postarr ) {
		if ( PF_Games::CPT === $data['post_type'] && empty( $postarr['ID'] ) ) {
			$data['comment_status'] = 'open';
		}
		return $data;
	}

	public static function assets( string $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || PF_Games::CPT !== $screen->post_type || ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		wp_enqueue_style( 'pf-admin', PIXELFART_URL . 'assets/admin.css', [], PIXELFART_VERSION );
		wp_enqueue_script( 'pf-admin', PIXELFART_URL . 'assets/admin.js', [], PIXELFART_VERSION, true );
		wp_localize_script( 'pf-admin', 'PFA', [
			'capture' => PF_Games::base_url() . '_tools/capture.html',
			'origin'  => PF_Hosting::origin(),
		] );
	}

	public static function meta_boxes() {
		add_meta_box( 'pf_game_file', 'Game en metadata', [ __CLASS__, 'box_game' ], PF_Games::CPT, 'normal', 'high' );
		add_meta_box( 'pf_game_thumb', 'Thumbnail (4:3, pixelig)', [ __CLASS__, 'box_thumb' ], PF_Games::CPT, 'normal', 'high' );
		add_meta_box( 'pf_game_versions', 'Versies', [ __CLASS__, 'box_versions' ], PF_Games::CPT, 'side', 'default' );
		add_meta_box( 'pf_game_stats', 'Statistieken', [ __CLASS__, 'box_stats' ], PF_Games::CPT, 'side', 'low' );
	}

	public static function box_game( WP_Post $post ) {
		wp_nonce_field( self::NONCE, '_pf_nonce' );
		$g        = PF_Games::get( $post ) ?? [];
		$code     = $g['code'] ?? '';
		$has_file = ! empty( $g['game_url'] );
		$is_new   = 'auto-draft' === $post->post_status;
		?>
		<div class="pf-fields">
			<div class="pf-field pf-wide">
				<label for="pf_html">Game (HTML-bestand)</label>
				<?php if ( $has_file ) : ?>
					<p class="pf-current">
						Huidig bestand: <code><?php echo esc_html( $code ); ?>/game.html</code>
						· <a href="<?php echo esc_url( $g['game_url'] ); ?>" target="_blank" rel="noopener">los openen</a>
						· <a href="<?php echo esc_url( $g['permalink'] ); ?>" target="_blank" rel="noopener">bekijk op de site</a>
					</p>
				<?php endif; ?>
				<input type="file" name="pf_html" id="pf_html" accept=".html,.htm,text/html" <?php echo $has_file ? '' : 'required'; ?>>
				<p class="description"><?php echo $has_file ? 'Upload een nieuw bestand om de game te vervangen (vergeet de versie niet op te hogen).' : 'Eén los HTML-bestand. Google Fonts worden automatisch lokaal gezet.'; ?></p>
			</div>

			<div class="pf-field">
				<label for="pf_code">Gamecode</label>
				<input type="text" name="pf_code" id="pf_code" value="<?php echo esc_attr( $code ); ?>" maxlength="5" pattern="[A-Za-z]{5}" placeholder="BLUST" class="pf-code" autocomplete="off" <?php echo $is_new ? 'data-autosuggest="1"' : ''; ?>>
				<p class="description">5 letters, uniek. Leeg = automatisch op basis van de titel.</p>
			</div>

			<div class="pf-field">
				<label for="pf_version">Versie</label>
				<input type="text" name="pf_version" id="pf_version" value="<?php echo esc_attr( $g['version'] ?? '1.0.0' ); ?>" placeholder="1.0.0">
			</div>

			<div class="pf-field">
				<label for="pf_published">Publicatiedatum</label>
				<input type="date" name="pf_published" id="pf_published" value="<?php echo esc_attr( $is_new ? current_time( 'Y-m-d' ) : ( $g['published'] ?? '' ) ); ?>">
			</div>

			<div class="pf-field">
				<label for="pf_age">Geschikt vanaf</label>
				<select name="pf_age" id="pf_age">
					<?php foreach ( PF_Games::AGES as $age ) : ?>
						<option value="<?php echo esc_attr( $age ); ?>" <?php selected( $g['age'] ?? 'ALL', $age ); ?>><?php echo esc_html( 'ALL' === $age ? 'ALL (iedereen)' : $age ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="pf-field">
				<label for="pf_author">Auteur</label>
				<input type="text" name="pf_author" id="pf_author" value="<?php echo esc_attr( $g['author'] ?? '' ); ?>">
			</div>

			<div class="pf-field pf-wide">
				<label for="pf_description">Korte omschrijving</label>
				<textarea name="pf_description" id="pf_description" rows="3" maxlength="240"><?php echo esc_textarea( $g['description'] ?? '' ); ?></textarea>
				<p class="description">Eén of twee zinnen, max. 240 tekens. Categorieën kies je rechts.</p>
			</div>

			<div class="pf-field pf-wide">
				<label for="pf_changelog_note">Wat is er nieuw?</label>
				<textarea name="pf_changelog_note" id="pf_changelog_note" rows="2" maxlength="500" placeholder="<?php echo $has_file ? 'Bijv. Snellere scooter, bug bij level 3 opgelost.' : 'Eerste versie'; ?>"></textarea>
				<p class="description">Alleen nodig als je een nieuw HTML-bestand uploadt. De vorige versie wordt bewaard; is de versie niet opgehoogd, dan doen we dat (1.0.0 → 1.0.1).</p>
			</div>

			<div class="pf-field">
				<label for="pf_score_order">Topscorelijst</label>
				<select name="pf_score_order" id="pf_score_order">
					<option value="" <?php selected( $g['score']['order'] ?? '', '' ); ?>>Geen</option>
					<option value="desc" <?php selected( $g['score']['order'] ?? '', 'desc' ); ?>>Hoger is beter</option>
					<option value="asc" <?php selected( $g['score']['order'] ?? '', 'asc' ); ?>>Lager is beter (bijv. tijd)</option>
				</select>
				<p class="description">Werkt als de game <code>Pixelfart.score()</code> aanroept.</p>
			</div>

			<div class="pf-field">
				<label for="pf_score_label">Eenheid</label>
				<input type="text" name="pf_score_label" id="pf_score_label" value="<?php echo esc_attr( $g['score']['label'] ?? 'punten' ); ?>" maxlength="20" placeholder="punten">
			</div>

			<div class="pf-field">
				<label for="pf_score_max">Hoogst mogelijke score</label>
				<input type="number" name="pf_score_max" id="pf_score_max" min="0" step="1" value="<?php echo esc_attr( PF_Games::meta( $post->ID, 'score_max', '' ) ); ?>" placeholder="geen grens">
				<p class="description">Alles daarboven is valsspelen en wordt geweigerd.</p>
			</div>
		</div>
		<?php
	}

	public static function box_versions( WP_Post $post ) {
		$g = PF_Games::get( $post );
		if ( ! $g || ! $g['code'] ) {
			echo '<p>Na de eerste upload zie je hier de versies.</p>';
			return;
		}
		echo '<p>Nu online: <strong>' . esc_html( $g['version'] ) . '</strong></p>';
		if ( $g['changelog'] ) {
			echo '<ul class="pf-changelog">';
			foreach ( array_slice( $g['changelog'], 0, 8 ) as $c ) {
				printf( '<li><strong>%s</strong> <span>%s</span><br>%s</li>', esc_html( $c['version'] ), esc_html( wp_date( 'j M Y', strtotime( $c['date'] ) ) ), esc_html( $c['notes'] ) );
			}
			echo '</ul>';
		}
		$versions = PF_Games::versions( $g['code'] );
		if ( $versions && current_user_can( 'unfiltered_html' ) ) {
			echo '<p><strong>Eerdere bestanden</strong></p><ul class="pf-versions">';
			foreach ( $versions as $file => $version ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=pf_restore_version&post=' . $post->ID . '&file=' . rawurlencode( $file ) ), 'pf_restore_' . $post->ID );
				printf( '<li><code>%s</code> · <a href="%s" onclick="return confirm(%s)">terugzetten</a></li>', esc_html( $version ), esc_url( $url ), esc_attr( wp_json_encode( "Versie $version terugzetten? De huidige versie wordt eerst bewaard." ) ) );
			}
			echo '</ul>';
		}
	}

	/** Een eerdere versie terugzetten (de huidige wordt eerst bewaard). */
	public static function restore_version() {
		$id   = absint( $_GET['post'] ?? 0 );
		$file = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );
		check_admin_referer( 'pf_restore_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'unfiltered_html' ) ) {
			wp_die( 'Geen rechten.' );
		}
		$code = (string) PF_Games::meta( $id, 'code' );
		$src  = PF_Games::versions_dir( $code ) . $file;
		if ( ! $code || ! preg_match( '/^[\w.\-]+\.html$/', $file ) || ! is_file( $src ) ) {
			wp_die( 'Versie niet gevonden.' );
		}
		$version = preg_replace( '/(-\d+)?\.html$/', '', $file );
		$current = (string) PF_Games::meta( $id, 'version', '1.0.0' );
		$html    = file_get_contents( $src );
		PF_Games::archive_current( $id, $current );
		PF_Games::install_html( $id, $html );
		update_post_meta( $id, '_pf_version', $version );
		PF_Games::add_changelog( $id, $version, "Teruggezet (was $current)." );
		PF_Games::write_json( $id );
		set_transient( 'pf_notes_' . get_current_user_id(), [ [ 'success', "Versie $version staat weer online. Versie $current is bewaard." ] ], 60 );
		do_action( 'litespeed_purge_post', $id );
		wp_safe_redirect( get_edit_post_link( $id, 'url' ) );
		exit;
	}

	public static function box_thumb( WP_Post $post ) {
		$g = PF_Games::get( $post ) ?? [];
		?>
		<div class="pf-thumb">
			<div class="pf-thumb-preview">
				<img id="pf_thumb_img" src="<?php echo esc_url( $g['thumb_url'] ?? '' ); ?>" alt="" <?php echo empty( $g['thumb_url'] ) ? 'hidden' : ''; ?>>
				<span id="pf_thumb_empty" <?php echo empty( $g['thumb_url'] ) ? '' : 'hidden'; ?>>Nog geen thumbnail</span>
			</div>
			<div class="pf-thumb-actions">
				<p><button type="button" class="button button-primary" id="pf_thumb_auto"
					data-code="<?php echo esc_attr( ! empty( $g['game_url'] ) ? $g['code'] : '' ); ?>">Maak automatisch</button></p>
				<p class="description">Speelt de game een paar seconden af, maakt een screenshot van het speelveld en zet die om naar grove pixels. Niet mooi? Klik nog eens (eventueel met een toets ingedrukt in het voorbeeld) of upload zelf een afbeelding.</p>
				<label for="pf_thumb_file">Of upload een afbeelding</label><br>
				<input type="file" name="pf_thumb_file" id="pf_thumb_file" accept="image/png,image/jpeg,image/webp">
				<label class="pf-check"><input type="checkbox" name="pf_thumb_pixelate" id="pf_thumb_pixelate" value="1" checked> Pixelig maken</label>
				<input type="hidden" name="pf_thumb_data" id="pf_thumb_data">
			</div>
			<div class="pf-thumb-stage" id="pf_thumb_stage" hidden></div>
		</div>
		<?php
	}

	public static function box_stats( WP_Post $post ) {
		$g = PF_Games::get( $post );
		if ( ! $g ) {
			return;
		}
		printf(
			'<p>Gespeeld: <strong>%s×</strong><br>Rating: <strong>%s</strong> (%d stemmen)<br>Reacties: <strong>%d</strong></p>',
			esc_html( number_format_i18n( $g['plays'] ) ),
			esc_html( $g['rating_cnt'] ? number_format_i18n( $g['rating'], 1 ) : '–' ),
			(int) $g['rating_cnt'],
			(int) $g['comments']
		);
		if ( $g['code'] && file_exists( PF_Games::dir( $g['code'] ) . 'game.json' ) ) {
			printf( '<p><a href="%s" target="_blank" rel="noopener">game.json bekijken</a></p>', esc_url( PF_Games::url( $g['code'] ) . 'game.json' ) );
		}
	}

	/* ---------- opslaan ---------- */

	public static function save( int $id, WP_Post $post ) {
		if ( ! isset( $_POST['_pf_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_pf_nonce'] ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$notes       = [];
		$old_version = (string) PF_Games::meta( $id, 'version', '1.0.0' );

		// Gamecode
		$old  = (string) PF_Games::meta( $id, 'code' );
		$code = PF_Games::sanitize_code( wp_unslash( $_POST['pf_code'] ?? '' ) );
		if ( strlen( $code ) !== 5 ) {
			$code = $old ?: PF_Games::suggest_code( $post->post_title, $id );
		} elseif ( PF_Games::code_in_use( $code, $id ) ) {
			$taken   = $code;
			$code    = $old ?: PF_Games::suggest_code( $post->post_title, $id );
			$notes[] = [ 'warning', sprintf( 'Code %s is al in gebruik; deze game houdt %s.', $taken, $code ) ];
		}
		if ( $old && $old !== $code ) {
			if ( file_exists( PF_Games::dir( $code ) ) ) {
				$notes[] = [ 'error', sprintf( 'Map voor %s bestaat al; code niet gewijzigd.', $code ) ];
				$code    = $old;
			} else {
				PF_Games::rename_code( $id, $old, $code );
			}
		}
		update_post_meta( $id, '_pf_code', $code );

		// Metadata
		$published = sanitize_text_field( wp_unslash( $_POST['pf_published'] ?? '' ) );
		update_post_meta( $id, '_pf_published', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $published ) ? $published : current_time( 'Y-m-d' ) );
		update_post_meta( $id, '_pf_version', sanitize_text_field( wp_unslash( $_POST['pf_version'] ?? '' ) ) ?: '1.0.0' );
		update_post_meta( $id, '_pf_description', mb_substr( sanitize_textarea_field( wp_unslash( $_POST['pf_description'] ?? '' ) ), 0, 240 ) );
		update_post_meta( $id, '_pf_author', sanitize_text_field( wp_unslash( $_POST['pf_author'] ?? '' ) ) );
		$age = sanitize_text_field( wp_unslash( $_POST['pf_age'] ?? 'ALL' ) );
		update_post_meta( $id, '_pf_age', in_array( $age, PF_Games::AGES, true ) ? $age : 'ALL' );
		$order = sanitize_key( wp_unslash( $_POST['pf_score_order'] ?? '' ) );
		update_post_meta( $id, '_pf_score_order', in_array( $order, [ 'desc', 'asc' ], true ) ? $order : '' );
		$max = absint( $_POST['pf_score_max'] ?? 0 );
		$max ? update_post_meta( $id, '_pf_score_max', $max ) : delete_post_meta( $id, '_pf_score_max' );
		update_post_meta( $id, '_pf_score_label', mb_substr( sanitize_text_field( wp_unslash( $_POST['pf_score_label'] ?? '' ) ), 0, 20 ) ?: 'punten' );

		// Game-HTML: voert eigen JavaScript uit op de site, dus alleen voor wie ongefilterde HTML mag plaatsen.
		if ( ! empty( $_FILES['pf_html']['tmp_name'] ) && UPLOAD_ERR_OK === $_FILES['pf_html']['error'] ) {
			$name = sanitize_file_name( $_FILES['pf_html']['name'] );
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$notes[] = [ 'error', 'Je hebt geen rechten om HTML met scripts te uploaden.' ];
			} elseif ( ! preg_match( '/\.html?$/i', $name ) ) {
				$notes[] = [ 'error', 'Alleen .html-bestanden zijn toegestaan.' ];
			} elseif ( $_FILES['pf_html']['size'] > self::MAX_HTML_BYTES ) {
				$notes[] = [ 'error', 'Het HTML-bestand is groter dan 15 MB.' ];
			} else {
				$html  = file_get_contents( $_FILES['pf_html']['tmp_name'] ); // phpcs:ignore
				$note  = sanitize_textarea_field( wp_unslash( $_POST['pf_changelog_note'] ?? '' ) );
				$notes = array_merge( $notes, PF_Games::publish_html( $id, $html, $note, (string) PF_Games::meta( $id, 'version', '1.0.0' ), $old_version ) );
			}
		}

		// Thumbnail: eigen upload gaat voor de automatische.
		$bytes = null;
		if ( ! empty( $_FILES['pf_thumb_file']['tmp_name'] ) && UPLOAD_ERR_OK === $_FILES['pf_thumb_file']['error'] ) {
			$bytes = file_get_contents( $_FILES['pf_thumb_file']['tmp_name'] ); // phpcs:ignore
		} elseif ( ! empty( $_POST['pf_thumb_data'] ) && preg_match( '#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', wp_unslash( $_POST['pf_thumb_data'] ), $m ) ) {
			$bytes = base64_decode( $m[1] );
		}
		if ( $bytes ) {
			if ( PF_Games::install_thumb( $id, $bytes ) ) {
				$notes[] = [ 'success', 'Thumbnail opgeslagen.' ];
			} else {
				$notes[] = [ 'error', 'Thumbnail kon niet worden verwerkt (PNG, JPG of WebP).' ];
			}
		}

		if ( 'publish' === $post->post_status && ! file_exists( PF_Games::dir( $code ) . 'game.html' ) ) {
			$notes[] = [ 'warning', 'Deze game is gepubliceerd maar heeft nog geen HTML-bestand.' ];
		}
		if ( $notes ) {
			set_transient( 'pf_notes_' . get_current_user_id(), $notes, 60 );
		}
		// game.json wordt daarna geschreven door PF_Games::after_save (priority 50).
	}

	public static function notices() {
		$key   = 'pf_notes_' . get_current_user_id();
		$notes = get_transient( $key );
		if ( ! $notes ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notes as [ $type, $msg ] ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $msg ) );
		}
	}

	/* ---------- overzicht ---------- */

	public static function columns( array $cols ): array {
		$new = [];
		foreach ( $cols as $k => $v ) {
			if ( 'title' === $k ) {
				$new['pf_thumb'] = '';
			}
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['pf_code']  = 'Code';
				$new['pf_plays'] = 'Gespeeld';
				$new['pf_rate']  = 'Rating';
			}
		}
		return $new;
	}

	public static function column( string $col, int $id ) {
		$g = PF_Games::get( $id );
		switch ( $col ) {
			case 'pf_thumb':
				if ( $g['thumb_url'] ) {
					printf( '<img src="%s" alt="" width="64" height="48" style="image-rendering:pixelated;display:block">', esc_url( $g['thumb_url'] ) );
				}
				break;
			case 'pf_code':
				echo '<code>' . esc_html( $g['code'] ) . '</code>';
				break;
			case 'pf_plays':
				echo esc_html( number_format_i18n( $g['plays'] ) );
				break;
			case 'pf_rate':
				echo esc_html( $g['rating_cnt'] ? number_format_i18n( $g['rating'], 1 ) . ' (' . $g['rating_cnt'] . ')' : '–' );
				break;
		}
	}

	public static function sort_by_plays( WP_Query $q ) {
		if ( is_admin() && $q->is_main_query() && 'pf_plays' === $q->get( 'orderby' ) ) {
			$q->set( 'meta_key', '_pf_plays' );
			$q->set( 'orderby', 'meta_value_num' );
		}
	}
}
