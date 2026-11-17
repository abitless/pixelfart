<?php
/**
 * ALTCHA (https://altcha.org, MIT): spambescherming zonder cookies, tracking of externe dienst.
 *
 * De browser rekent een kleine proof-of-work uit (protocol v3, SHA-256):
 *  - wij kiezen een geheime teller en leiden daar een sleutel van af: sha256( salt ‖ nonce ‖ teller );
 *  - de browser krijgt de eerste helft van die sleutel (keyPrefix) en moet de teller terugvinden;
 *  - controleren kost ons één HMAC (keySignature), geen herberekening.
 * Elke oplossing is één keer bruikbaar en verloopt na CHALLENGE_TTL seconden.
 */

defined( 'ABSPATH' ) || exit;

class PF_Altcha {

	const ALGORITHM     = 'SHA-256';
	const CHALLENGE_TTL = 20 * MINUTE_IN_SECONDS;
	const KEY_LENGTH    = 32;

	/**
	 * Moeilijkheid: de teller ligt tussen deze grenzen. De browser probeert gemiddeld zo vaak.
	 * Rond de 50.000 pogingen is op een telefoon een fractie van een seconde: onmerkbaar voor mensen,
	 * maar duur voor wie duizenden berichten wil spammen.
	 */
	const COUNTER_MIN = 20000;
	const COUNTER_MAX = 80000;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes() {
		register_rest_route( PF_Rest::NS, '/altcha', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'challenge_response' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function challenge_response() {
		do_action( 'litespeed_control_set_nocache', 'altcha challenge' );
		$res = new WP_REST_Response( self::create_challenge() );
		$res->header( 'Cache-Control', 'no-store, max-age=0' );
		return $res;
	}

	/* ---------- geheimen ---------- */

	private static function secret( string $purpose ): string {
		$master = get_option( 'pf_altcha_secret' );
		if ( ! $master ) {
			$master = wp_generate_password( 64, true, true );
			update_option( 'pf_altcha_secret', $master, true );
		}
		return hash_hmac( 'sha256', $purpose, $master . wp_salt( 'nonce' ) );
	}

	/* ---------- challenge ---------- */

	public static function create_challenge(): array {
		$nonce   = random_bytes( 16 );
		$salt    = random_bytes( 16 );
		$counter = random_int( self::COUNTER_MIN, self::COUNTER_MAX );
		$key     = self::derive_key( $salt, $nonce, $counter );

		$params = [
			'algorithm'    => self::ALGORITHM,
			'cost'         => 1,
			'expiresAt'    => time() + self::CHALLENGE_TTL,
			'keyLength'    => self::KEY_LENGTH,
			'keyPrefix'    => bin2hex( substr( $key, 0, intdiv( self::KEY_LENGTH, 2 ) ) ),
			'keySignature' => hash_hmac( 'sha256', $key, self::secret( 'key' ) ),
			'nonce'        => bin2hex( $nonce ),
			'salt'         => bin2hex( $salt ),
		];
		return [
			'parameters' => $params,
			'signature'  => hash_hmac( 'sha256', self::canonical( $params ), self::secret( 'signature' ) ),
		];
	}

	/** SHA-256 met cost 1: sha256( salt ‖ nonce ‖ teller als uint32 big-endian ). */
	private static function derive_key( string $salt, string $nonce, int $counter ): string {
		return substr( hash( 'sha256', $salt . $nonce . pack( 'N', $counter ), true ), 0, self::KEY_LENGTH );
	}

	/** Zoals canonicalJSON() in ALTCHA: sleutels gesorteerd, compacte JSON. */
	private static function canonical( array $params ): string {
		ksort( $params );
		return wp_json_encode( $params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/* ---------- controle ---------- */

	/**
	 * Controleer de payload uit het verborgen veld van de widget (base64-JSON).
	 *
	 * @return true|WP_Error
	 */
	public static function verify( $payload ) {
		$fail = new WP_Error( 'pf_altcha', 'De robotcheck is niet gelukt. Probeer het nog eens.', [ 'status' => 400 ] );
		if ( ! is_string( $payload ) || strlen( $payload ) > 4096 ) {
			return $fail;
		}
		$data   = json_decode( (string) base64_decode( $payload, true ), true );
		$params = $data['challenge']['parameters'] ?? null;
		$sig    = $data['challenge']['signature'] ?? '';
		$key    = $data['solution']['derivedKey'] ?? '';
		if ( ! is_array( $params ) || ! is_string( $sig ) || ! is_string( $key ) || ! preg_match( '/^(?:[0-9a-f]{2})+$/', $key ) ) {
			return $fail;
		}
		if ( empty( $params['expiresAt'] ) || (int) $params['expiresAt'] < time() ) {
			return new WP_Error( 'pf_altcha_expired', 'De robotcheck is verlopen. Probeer het nog eens.', [ 'status' => 400 ] );
		}
		if ( ! hash_equals( hash_hmac( 'sha256', self::canonical( $params ), self::secret( 'signature' ) ), $sig ) ) {
			return $fail;
		}
		if ( ! hash_equals( (string) ( $params['keySignature'] ?? '' ), hash_hmac( 'sha256', hex2bin( $key ), self::secret( 'key' ) ) ) ) {
			return $fail;
		}
		// Eenmalig: dezelfde oplossing twee keer insturen gaat niet.
		$used = 'pf_altcha_' . substr( $sig, 0, 32 );
		if ( get_transient( $used ) ) {
			return $fail;
		}
		set_transient( $used, 1, max( 60, (int) $params['expiresAt'] - time() ) );
		return true;
	}
}
