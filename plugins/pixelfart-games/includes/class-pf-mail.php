<?php
/**
 * E-mail via SMTP, met de gegevens uit wp-config (of een bestand dat wp-config inleest, buiten de webroot).
 * Zo staat het wachtwoord nooit in de database.
 *
 *   define( 'PIXELFART_SMTP_HOST', 'smtp.example.nl' );
 *   define( 'PIXELFART_SMTP_PORT', 587 );            // 587 = STARTTLS, 465 = SSL
 *   define( 'PIXELFART_SMTP_USER', 'noreply@pixelfart.nl' );
 *   define( 'PIXELFART_SMTP_PASSWORD', '...' );
 *   define( 'PIXELFART_SMTP_FROM', 'noreply@pixelfart.nl' );       // optioneel, standaard de gebruiker
 *   define( 'PIXELFART_SMTP_FROM_NAME', 'Pixelfart' );             // optioneel
 *
 * Geen host = WordPress verstuurt zoals altijd (PHP mail()).
 * Testen: wp pixelfart mailtest jij@example.nl
 */

defined( 'ABSPATH' ) || exit;

class PF_Mail {

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'phpmailer_init', [ __CLASS__, 'smtp' ] );
		add_filter( 'wp_mail_from', [ __CLASS__, 'from' ] );
		add_filter( 'wp_mail_from_name', [ __CLASS__, 'from_name' ] );
	}

	public static function enabled(): bool {
		return defined( 'PIXELFART_SMTP_HOST' ) && '' !== (string) PIXELFART_SMTP_HOST;
	}

	/** @param PHPMailer\PHPMailer\PHPMailer $mail */
	public static function smtp( $mail ) {
		$port = defined( 'PIXELFART_SMTP_PORT' ) ? (int) PIXELFART_SMTP_PORT : 587;
		$user = defined( 'PIXELFART_SMTP_USER' ) ? (string) PIXELFART_SMTP_USER : '';

		$mail->isSMTP();
		$mail->Host       = (string) PIXELFART_SMTP_HOST;
		$mail->Port       = $port;
		$mail->SMTPSecure = 465 === $port ? 'ssl' : 'tls';
		$mail->SMTPAutoTLS = true;
		$mail->SMTPAuth   = '' !== $user;
		$mail->Username   = $user;
		$mail->Password   = defined( 'PIXELFART_SMTP_PASSWORD' ) ? (string) PIXELFART_SMTP_PASSWORD : '';
		$mail->Timeout    = 15;
		// Certificaat altijd controleren: geen SMTPOptions die verify_peer uitzetten.
		if ( ! $mail->Sender ) {
			$mail->Sender = self::from( $mail->From ); // envelope-afzender gelijk aan From, voor SPF
		}
	}

	public static function from( $from ) {
		if ( defined( 'PIXELFART_SMTP_FROM' ) && is_email( PIXELFART_SMTP_FROM ) ) {
			return PIXELFART_SMTP_FROM;
		}
		$user = defined( 'PIXELFART_SMTP_USER' ) ? (string) PIXELFART_SMTP_USER : '';
		return is_email( $user ) ? $user : $from;
	}

	public static function from_name( $name ) {
		return defined( 'PIXELFART_SMTP_FROM_NAME' ) && PIXELFART_SMTP_FROM_NAME ? PIXELFART_SMTP_FROM_NAME : 'Pixelfart';
	}
}
