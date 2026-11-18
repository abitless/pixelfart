<?php
/**
 * Mini-client voor de Mollie API (v2): alleen wat we nodig hebben voor donaties.
 * Geen SDK of extra bibliotheek: een paar HTTPS-verzoeken via WordPress zelf.
 *
 * De sleutel staat in wp-config.php (PIXELFART_MOLLIE_KEY) of onder Donaties › Instellingen.
 * Een sleutel die met test_ begint, gebruikt Mollies testomgeving: er wordt dan niet echt betaald.
 *
 * Ondertekenen (https://docs.mollie.com/reference/request-signing): zet in wp-config.php
 *   define( 'PIXELFART_MOLLIE_SIGNING_KEY', '/pad/buiten/de/webroot/mollie-ed25519.pem' );
 * Dan krijgt elk verzoek een Ed25519-handtekening. De privésleutel komt nooit in de database of in git.
 */

defined( 'ABSPATH' ) || exit;

class PF_Mollie {

	const API = 'https://api.mollie.com/v2/';

	public static function key(): string {
		if ( defined( 'PIXELFART_MOLLIE_KEY' ) ) {
			return (string) PIXELFART_MOLLIE_KEY;
		}
		return (string) get_option( 'pf_mollie_key', '' );
	}

	public static function configured(): bool {
		return (bool) preg_match( '/^(live|test)_[A-Za-z0-9]{20,}$/', self::key() );
	}

	/**
	 * De Ed25519-sleutel voor sodium (64 bytes), of '' als ondertekenen uit staat.
	 * PIXELFART_MOLLIE_SIGNING_KEY is een pad naar het PEM-bestand, of de PEM-tekst zelf.
	 */
	public static function signing_key(): string {
		static $sk = null;
		if ( null !== $sk ) {
			return $sk;
		}
		$sk = '';
		if ( ! defined( 'PIXELFART_MOLLIE_SIGNING_KEY' ) || ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			return $sk;
		}
		$pem = (string) PIXELFART_MOLLIE_SIGNING_KEY;
		if ( ! str_contains( $pem, '-----BEGIN' ) ) {
			$pem = is_readable( $pem ) ? (string) file_get_contents( $pem ) : ''; // phpcs:ignore
		}
		if ( ! preg_match( '/-----BEGIN PRIVATE KEY-----(.+?)-----END PRIVATE KEY-----/s', $pem, $m ) ) {
			return $sk;
		}
		// PKCS#8 voor Ed25519: een vaste kop van 16 bytes en dan de geheime seed van 32 bytes.
		$der = (string) base64_decode( preg_replace( '/\s+/', '', $m[1] ), true );
		if ( 48 !== strlen( $der ) || '302e020100300506032b657004220420' !== bin2hex( substr( $der, 0, 16 ) ) ) {
			return $sk;
		}
		$sk = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( substr( $der, 16 ) ) );
		return $sk;
	}

	/** Is er een sleutel ingesteld die we niet kunnen lezen? Dan liever niets versturen dan ongetekend. */
	public static function signing_broken(): bool {
		return defined( 'PIXELFART_MOLLIE_SIGNING_KEY' ) && '' === self::signing_key();
	}

	/** De publieke sleutel als PEM, om in het Mollie-dashboard te plakken (en te controleren). */
	public static function signing_public_pem(): string {
		$sk = self::signing_key();
		if ( '' === $sk ) {
			return '';
		}
		$der = hex2bin( '302a300506032b6570032100' ) . sodium_crypto_sign_publickey_from_secretkey( $sk );
		return "-----BEGIN PUBLIC KEY-----\n" . base64_encode( $der ) . "\n-----END PUBLIC KEY-----";
	}

	/**
	 * De headers voor een ondertekend verzoek. $path is het pad zoals verstuurd (/v2/...), zonder query.
	 */
	public static function sign( string $method, string $path, string $body, string $idempotency = '' ): array {
		$sk = self::signing_key();
		if ( '' === $sk ) {
			return [];
		}
		$t       = microtime( true );
		$at      = gmdate( 'Y-m-d\TH:i:s', (int) $t ) . sprintf( '.%03dZ', (int) ( ( $t - floor( $t ) ) * 1000 ) );
		$payload = implode( "\n", [ strtolower( $method ), $path, '', $at, $idempotency, hash( 'sha256', $body ) ] );
		return [
			'X-Client-Signed-At' => $at,
			'X-Client-Signature' => base64_encode( sodium_crypto_sign_detached( $payload, $sk ) ),
		];
	}

	public static function is_test(): bool {
		return str_starts_with( self::key(), 'test_' );
	}

	/** Bedrag in centen naar Mollie-notatie ("5.00"). */
	public static function money( int $cents ): array {
		return [ 'currency' => 'EUR', 'value' => number_format( $cents / 100, 2, '.', '' ) ];
	}

	/**
	 * @return array|WP_Error Het antwoord van Mollie als array.
	 */
	public static function request( string $method, string $path, array $body = [] ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'pf_mollie_key', 'Er is nog geen Mollie-sleutel ingesteld.' );
		}
		if ( self::signing_broken() ) {
			error_log( 'Pixelfart/Mollie: PIXELFART_MOLLIE_SIGNING_KEY is ingesteld, maar de sleutel is niet te lezen.' ); // phpcs:ignore
			return new WP_Error( 'pf_mollie_sign', 'De betaling kon niet worden gestart. Probeer het later nog eens.', [ 'status' => 502 ] );
		}
		$path    = ltrim( $path, '/' );
		$payload = $body ? (string) wp_json_encode( $body ) : '';
		// Een POST krijgt een eigen sleutel: probeert iets het opnieuw, dan maakt Mollie geen tweede betaling.
		$idem = 'POST' === $method ? wp_generate_uuid4() : '';
		$args = [
			'method'  => $method,
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . self::key(),
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'Pixelfart/' . PIXELFART_VERSION,
			],
		];
		if ( $idem ) {
			$args['headers']['Idempotency-Key'] = $idem;
		}
		$args['headers'] += self::sign( $method, (string) wp_parse_url( self::API . $path, PHP_URL_PATH ), $payload, $idem );
		if ( '' !== $payload ) {
			$args['body'] = $payload; // Precies de bytes die we hebben ondertekend.
		}
		$res = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'pf_mollie_http', 'Mollie is even niet bereikbaar. Probeer het zo nog eens.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 204 === $code ) {
			return [];
		}
		if ( $code >= 300 || ! is_array( $data ) ) {
			$detail = is_array( $data ) ? ( $data['detail'] ?? '' ) : '';
			error_log( 'Pixelfart/Mollie ' . $method . ' ' . $path . ' → ' . $code . ' ' . $detail ); // phpcs:ignore
			return new WP_Error( 'pf_mollie', 'De betaling kon niet worden gestart. Probeer het later nog eens.', [ 'status' => 502, 'mollie' => $code ] );
		}
		return $data;
	}

	public static function create_payment( array $body ) {
		return self::request( 'POST', 'payments', $body );
	}

	public static function get_payment( string $id ) {
		if ( ! preg_match( '/^tr_[A-Za-z0-9]+$/', $id ) ) {
			return new WP_Error( 'pf_mollie_id', 'Onbekende betaling.' );
		}
		return self::request( 'GET', 'payments/' . $id );
	}

	public static function create_customer( string $email ) {
		return self::request( 'POST', 'customers', [ 'email' => $email ] );
	}

	public static function create_customer_payment( string $customer, array $body ) {
		return self::request( 'POST', 'customers/' . rawurlencode( $customer ) . '/payments', $body );
	}

	public static function create_subscription( string $customer, array $body ) {
		return self::request( 'POST', 'customers/' . rawurlencode( $customer ) . '/subscriptions', $body );
	}

	public static function cancel_subscription( string $customer, string $sub ) {
		return self::request( 'DELETE', 'customers/' . rawurlencode( $customer ) . '/subscriptions/' . rawurlencode( $sub ) );
	}

	/** Een klant (en daarmee het machtigingsgegeven) bij Mollie wissen als er niets meer loopt. */
	public static function delete_customer( string $customer ) {
		return self::request( 'DELETE', 'customers/' . rawurlencode( $customer ) );
	}
}
