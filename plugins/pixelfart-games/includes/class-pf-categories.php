<?php
/**
 * Categorieën (tags in het ontwerp): taxonomie met een kleur en een 8×8 pixel-icoon per categorie.
 */

defined( 'ABSPATH' ) || exit;

class PF_Categories {

	const TAX = 'pf_categorie';

	/** 8×8 pixel-iconen; '#' = pixel. */
	const ICONS = [
		'alles'     => [ '##.##.##', '##.##.##', '........', '##.##.##', '##.##.##', '........', '##.##.##', '##.##.##' ],
		'arcade'    => [ '...##...', '..####..', '..####..', '...##...', '...##...', '.######.', '########', '########' ],
		'puzzel'    => [ '..##....', '..##....', '######..', '########', '########', '######..', '######..', '........' ],
		'retro'     => [ '########', '#......#', '#.####.#', '#.####.#', '#......#', '########', '.#.##.#.', '........' ],
		'spelers'   => [ '###..###', '###..###', '........', '###..###', '###..###', '###..###', '#.#..#.#', '#.#..#.#' ],
		'snel'      => [ '....###.', '...###..', '..###...', '.######.', '...###..', '..###...', '.##.....', '#.......' ],
		'actie'     => [ '......##', '.....###', '....###.', '#..###..', '.####...', '..##....', '.#.##...', '#.......' ],
		'rustig'    => [ '..####..', '.###....', '###.....', '###.....', '###.....', '####...#', '.######.', '..####..' ],
		'avontuur'  => [ '#.......', '######..', '#######.', '######..', '#.......', '#.......', '#.......', '#.......' ],
		'strategie' => [ '.#.##.#.', '.######.', '..####..', '..####..', '..####..', '.######.', '########', '........' ],
	];

	/** Standaardcategorieën uit het ontwerp (+ Avontuur en Strategie). */
	const DEFAULTS = [
		'Arcade'    => [ '#5dde7a', 'arcade' ],
		'Puzzel'    => [ '#6ec9ff', 'puzzel' ],
		'Retro'     => [ '#ffcf4a', 'retro' ],
		'2 spelers' => [ '#ff5c8a', 'spelers' ],
		'Snel'      => [ '#c9a6ff', 'snel' ],
		'Actie'     => [ '#ff9a4a', 'actie' ],
		'Rustig'    => [ '#9be3d0', 'rustig' ],
		'Avontuur'  => [ '#f2a65a', 'avontuur' ],
		'Strategie' => [ '#b8c4ff', 'strategie' ],
	];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( self::TAX . '_add_form_fields', [ __CLASS__, 'add_fields' ] );
		add_action( self::TAX . '_edit_form_fields', [ __CLASS__, 'edit_fields' ] );
		add_action( 'created_' . self::TAX, [ __CLASS__, 'save_fields' ] );
		add_action( 'edited_' . self::TAX, [ __CLASS__, 'save_fields' ] );
	}

	public static function register() {
		register_taxonomy( self::TAX, PF_Games::CPT, [
			'labels'            => [
				'name'          => 'Categorieën',
				'singular_name' => 'Categorie',
				'add_new_item'  => 'Nieuwe categorie',
				'edit_item'     => 'Categorie bewerken',
				'search_items'  => 'Zoek categorieën',
			],
			'hierarchical'      => true, // vinkjes in plaats van vrije tags
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => [ 'slug' => 'categorie' ],
		] );
	}

	public static function seed_defaults() {
		foreach ( self::DEFAULTS as $name => [ $color, $icon ] ) {
			if ( term_exists( $name, self::TAX ) ) {
				continue;
			}
			$t = wp_insert_term( $name, self::TAX );
			if ( ! is_wp_error( $t ) ) {
				update_term_meta( $t['term_id'], 'pf_color', $color );
				update_term_meta( $t['term_id'], 'pf_icon', $icon );
			}
		}
	}

	/**
	 * Alle categorieën met kleur en icoon, op volgorde van het ontwerp.
	 *
	 * @return array<int, array{id:int, slug:string, name:string, color:string, icon:string, count:int}>
	 */
	public static function all(): array {
		$terms = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		$order = array_keys( self::DEFAULTS );
		usort( $terms, function ( $a, $b ) use ( $order ) {
			$ia = array_search( $a->name, $order, true );
			$ib = array_search( $b->name, $order, true );
			$ia = false === $ia ? 99 : $ia;
			$ib = false === $ib ? 99 : $ib;
			return $ia <=> $ib ?: strcasecmp( $a->name, $b->name );
		} );
		return array_map( [ __CLASS__, 'to_array' ], $terms );
	}

	public static function to_array( WP_Term $t ): array {
		$color = get_term_meta( $t->term_id, 'pf_color', true ) ?: '#bdbdbd';
		$icon  = get_term_meta( $t->term_id, 'pf_icon', true );
		return [
			'id'    => $t->term_id,
			'slug'  => $t->slug,
			'name'  => $t->name,
			'color' => $color,
			'icon'  => isset( self::ICONS[ $icon ] ) ? $icon : 'arcade',
			'count' => (int) $t->count,
		];
	}

	/** Categorieën van één game. */
	public static function for_game( int $post_id ): array {
		$terms = get_the_terms( $post_id, self::TAX );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return [];
		}
		$all   = wp_list_pluck( self::all(), 'name' );
		$terms = array_map( [ __CLASS__, 'to_array' ], $terms );
		usort( $terms, fn( $a, $b ) => array_search( $a['name'], $all, true ) <=> array_search( $b['name'], $all, true ) );
		return $terms;
	}

	/**
	 * Een pixel-icoon als box-shadow (zoals in het ontwerp): één span, geen afbeelding.
	 */
	public static function icon_html( string $icon, int $scale = 2, string $class = 'px-icon' ): string {
		$rows = self::ICONS[ $icon ] ?? self::ICONS['arcade'];
		return pf_pixel_icon( $rows, $scale, $class );
	}

	/* ---------- admin: kleur en icoon per categorie ---------- */

	public static function add_fields() {
		?>
		<div class="form-field">
			<label for="pf_color">Kleur</label>
			<input type="color" name="pf_color" id="pf_color" value="#5dde7a">
		</div>
		<div class="form-field">
			<label for="pf_icon">Pixel-icoon</label>
			<?php self::icon_select( 'arcade' ); ?>
		</div>
		<?php
	}

	public static function edit_fields( WP_Term $term ) {
		$c = self::to_array( $term );
		?>
		<tr class="form-field">
			<th><label for="pf_color">Kleur</label></th>
			<td><input type="color" name="pf_color" id="pf_color" value="<?php echo esc_attr( $c['color'] ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th><label for="pf_icon">Pixel-icoon</label></th>
			<td><?php self::icon_select( $c['icon'] ); ?></td>
		</tr>
		<?php
	}

	private static function icon_select( string $current ) {
		echo '<select name="pf_icon" id="pf_icon">';
		foreach ( array_keys( self::ICONS ) as $key ) {
			if ( 'alles' === $key ) {
				continue;
			}
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $key ), selected( $current, $key, false ) );
		}
		echo '</select>';
	}

	public static function save_fields( int $term_id ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		// Nonce wordt al door het standaard term-formulier gecontroleerd.
		if ( isset( $_POST['pf_color'] ) ) {
			update_term_meta( $term_id, 'pf_color', sanitize_hex_color( wp_unslash( $_POST['pf_color'] ) ) ?: '#bdbdbd' );
		}
		if ( isset( $_POST['pf_icon'] ) && isset( self::ICONS[ $_POST['pf_icon'] ] ) ) {
			update_term_meta( $term_id, 'pf_icon', sanitize_key( $_POST['pf_icon'] ) );
		}
	}
}

/**
 * Teken een pixel-bitmap ('#' = pixel) als één span met box-shadows, zoals het ontwerp doet.
 * Kleur via `color` (currentColor).
 */
function pf_pixel_icon( array $rows, int $s = 2, string $class = 'px-icon', string $extra_style = '' ): string {
	$sh = [];
	foreach ( $rows as $y => $row ) {
		foreach ( str_split( $row ) as $x => $c ) {
			if ( '#' === $c ) {
				$sh[] = sprintf( '%dpx %dpx 0 0', ( $x + 1 ) * $s, ( $y + 1 ) * $s );
			}
		}
	}
	$w = strlen( $rows[0] ) * $s;
	$h = count( $rows ) * $s;
	return sprintf(
		'<span class="%1$s" aria-hidden="true" style="width:%2$dpx;height:%3$dpx;%7$s"><span style="left:%4$dpx;top:%4$dpx;width:%5$dpx;height:%5$dpx;box-shadow:%6$s"></span></span>',
		esc_attr( $class ), $w, $h, -$s, $s, implode( ',', $sh ), esc_attr( $extra_style )
	);
}
