<?php
/**
 * REST request validation helpers.
 *
 * @package TheExporter
 */

namespace TheExporter\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Class RequestValidator
 */
class RequestValidator {

	/**
	 * Parse JSON body with required keys.
	 *
	 * @param \WP_REST_Request $request  Request.
	 * @param array            $required Required keys.
	 * @return array|\WP_REST_Response
	 */
	public static function json_params( $request, array $required = array() ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'Invalid JSON body',
				'code'    => 'invalid_json',
			), 400 );
		}

		$missing = array();
		foreach ( $required as $key ) {
			if ( ! isset( $params[ $key ] ) || '' === $params[ $key ] ) {
				$missing[] = $key;
			}
		}

		if ( ! empty( $missing ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'Missing required fields: ' . implode( ', ', $missing ),
				'code'    => 'missing_fields',
				'fields'  => $missing,
			), 400 );
		}

		return $params;
	}

	/**
	 * Sanitize migration ID from params.
	 *
	 * @param array $params Params.
	 * @return string
	 */
	public static function migration_id( array $params ) {
		return sanitize_text_field( $params['migration_id'] );
	}
}
