<?php
/**
 * Mini-client voor de Mollie API (v2): alleen wat we nodig hebben voor donaties.
 * Geen SDK of extra bibliotheek: een paar HTTPS-verzoeken via WordPress zelf.
 *
 * De sleutel staat in wp-config.php (PIXELFART_MOLLIE_KEY) of onder Donaties › Instellingen.
 * Een sleutel die met test_ begint, gebruikt Mollies testomgeving: er wordt dan niet echt betaald.
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
		$args = [
			'method'  => $method,
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . self::key(),
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'Pixelfart/' . PIXELFART_VERSION,
			],
		];
		if ( $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::API . ltrim( $path, '/' ), $args );
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
