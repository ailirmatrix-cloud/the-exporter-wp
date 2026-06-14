<?php
/**
 * Pairing tokens for site-to-site transfer.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class RemoteAuth
 */
class RemoteAuth {

	const OPTION_KEY = 'te_pairing_tokens';
	const TOKEN_TTL  = DAY_IN_SECONDS;

	/**
	 * Generate a pairing token (import site).
	 *
	 * @return array
	 */
	public static function generate_token() {
		self::prune_expired();

		$plain = bin2hex( random_bytes( 32 ) );
		$hash  = self::hash_token( $plain );
		$tokens = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}

		$tokens[ $hash ] = array(
			'expires_at' => time() + self::TOKEN_TTL,
			'created_by' => get_current_user_id(),
			'created_at' => time(),
		);

		update_option( self::OPTION_KEY, $tokens, false );

		return array(
			'success'    => true,
			'token'      => $plain,
			'expires_at' => $tokens[ $hash ]['expires_at'],
			'site_url'   => home_url(),
			'rest_url'   => rest_url( 'the-exporter/v1/' ),
		);
	}

	/**
	 * Verify plain token against stored hashes.
	 *
	 * @param string $plain_token Token from header or body.
	 * @return bool
	 */
	public static function verify_token( $plain_token ) {
		$plain_token = trim( (string) $plain_token );
		if ( '' === $plain_token ) {
			return false;
		}

		self::prune_expired();
		$hash   = self::hash_token( $plain_token );
		$tokens = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $tokens ) || empty( $tokens[ $hash ] ) ) {
			return false;
		}

		$entry = $tokens[ $hash ];
		if ( empty( $entry['expires_at'] ) || time() > (int) $entry['expires_at'] ) {
			unset( $tokens[ $hash ] );
			update_option( self::OPTION_KEY, $tokens, false );
			return false;
		}

		return true;
	}

	/**
	 * Read token from REST request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	public static function token_from_request( $request ) {
		$header = $request->get_header( 'x_te_token' );
		if ( $header ) {
			return sanitize_text_field( $header );
		}
		$params = $request->get_json_params();
		if ( ! empty( $params['token'] ) ) {
			return sanitize_text_field( $params['token'] );
		}
		$body = $request->get_body_params();
		if ( ! empty( $body['token'] ) ) {
			return sanitize_text_field( $body['token'] );
		}
		return '';
	}

	/**
	 * Verify remote import site from export site.
	 *
	 * @param string $remote_url Remote site base URL.
	 * @param string $token      Pairing token.
	 * @return array
	 */
	public static function verify_remote_site( $remote_url, $token ) {
		$remote_url = self::normalize_site_url( $remote_url );
		if ( ! $remote_url ) {
			return array(
				'success' => false,
				'error'   => self::url_validation_error(),
			);
		}
		if ( '' === trim( (string) $token ) ) {
			return array( 'success' => false, 'error' => 'Pairing code is required.' );
		}

		$endpoint = trailingslashit( self::resolve_server_url( $remote_url ) ) . 'wp-json/the-exporter/v1/pairing/verify';
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'X-TE-Token' => $token,
					'Accept'     => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $body['success'] ) ) {
			$msg = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : 'Connection failed (HTTP ' . $code . ')';
			return array( 'success' => false, 'error' => $msg );
		}

		if ( ! empty( $body['php_limits'] ) && is_array( $body['php_limits'] ) ) {
			Settings::update( array(
				'peer_php_limits' => array(
					'upload_max_filesize' => isset( $body['php_limits']['upload_max_filesize'] ) ? (int) $body['php_limits']['upload_max_filesize'] : 0,
					'post_max_size'       => isset( $body['php_limits']['post_max_size'] ) ? (int) $body['php_limits']['post_max_size'] : 0,
				),
			) );
		}

		return array(
			'success'    => true,
			'site_url'   => isset( $body['site_url'] ) ? $body['site_url'] : $remote_url,
			'php_limits' => isset( $body['php_limits'] ) && is_array( $body['php_limits'] ) ? $body['php_limits'] : array(),
			'message'    => isset( $body['message'] ) ? $body['message'] : 'Connected',
		);
	}

	/**
	 * Normalize and validate site URL.
	 *
	 * HTTPS is required for production hosts. HTTP is allowed for local Studio/dev
	 * hosts (localhost, 127.0.0.1, private IPs, .local / .test).
	 *
	 * @param string $url URL.
	 * @return string|false
	 */
	public static function normalize_site_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url ) {
			return false;
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return false;
		}

		$scheme = strtolower( $parsed['scheme'] );
		if ( ! in_array( $scheme, array( 'https', 'http' ), true ) ) {
			return false;
		}

		if ( 'http' === $scheme && ! self::is_local_dev_host( $parsed['host'] ) ) {
			return false;
		}

		return untrailingslashit( $url );
	}

	/**
	 * URL for server-side HTTP from PHP (Docker/Studio: localhost → host gateway).
	 *
	 * Browser-facing URLs stay as localhost:PORT. PHP push/verify from inside a
	 * container must reach the host-mapped port via host.docker.internal.
	 *
	 * @param string $url Normalized site URL.
	 * @return string
	 */
	public static function resolve_server_url( $url ) {
		$url = self::normalize_site_url( $url );
		if ( ! $url ) {
			return '';
		}
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return $url;
		}
		$host = strtolower( $parsed['host'] );
		if ( ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return $url;
		}

		$candidates = array( $url );
		$gateways   = array( 'host.docker.internal', 'host.containers.internal', 'gateway.docker.internal' );
		foreach ( $gateways as $gateway ) {
			if ( self::host_resolves( $gateway ) ) {
				$candidates[] = self::build_url( array_merge( $parsed, array( 'host' => $gateway ) ) );
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( self::probe_reachable( $candidate ) ) {
				return $candidate;
			}
		}

		return $url;
	}

	/**
	 * Quick reachability check for server-side push/verify URL selection.
	 *
	 * @param string $url Normalized site URL.
	 * @return bool
	 */
	public static function probe_reachable( $url ) {
		$url = self::normalize_site_url( $url );
		if ( ! $url ) {
			return false;
		}

		$endpoint = trailingslashit( $url ) . 'wp-json/';
		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 500;
	}

	/**
	 * @param array $parts wp_parse_url parts.
	 * @return string
	 */
	private static function build_url( array $parts ) {
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host   = $parts['host'] ?? '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = $parts['path'] ?? '';
		$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return untrailingslashit( $scheme . $host . $port . $path ) . $query;
	}

	/**
	 * @param string $host Hostname.
	 * @return bool
	 */
	private static function host_resolves( $host ) {
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! empty( $records ) ) {
				return true;
			}
		}
		$resolved = gethostbyname( $host );
		return $resolved !== $host && '' !== $resolved;
	}

	/**
	 * Whether the host is safe for HTTP connected-site testing.
	 *
	 * @param string $host Hostname or IP.
	 * @return bool
	 */
	public static function is_local_dev_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		if ( preg_match( '/\.(local|test)$/', $host ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && self::is_private_ip( $host ) ) {
			return true;
		}
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_private_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * User-facing URL validation error.
	 *
	 * @return string
	 */
	private static function url_validation_error() {
		return __( 'Invalid import site URL. Use HTTPS for production, or http://localhost:PORT for local Studio testing.', 'the-exporter' );
	}

	/**
	 * Hash token for storage.
	 *
	 * @param string $plain Plain token.
	 * @return string
	 */
	private static function hash_token( $plain ) {
		return hash_hmac( 'sha256', $plain, wp_salt( 'te_pairing' ) );
	}

	/**
	 * Remove expired tokens.
	 */
	private static function prune_expired() {
		$tokens = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $tokens ) ) {
			return;
		}
		$now = time();
		$changed = false;
		foreach ( $tokens as $hash => $entry ) {
			if ( empty( $entry['expires_at'] ) || $now > (int) $entry['expires_at'] ) {
				unset( $tokens[ $hash ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION_KEY, $tokens, false );
		}
	}
}
