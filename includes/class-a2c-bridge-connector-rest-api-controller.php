<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( dirname( __FILE__ ) ) . '/bridge2cart/bridge.php';

/**
 * REST API controller.
 *
 * @since 1.5.0
 */
class A2C_Bridge_Connector_V1_REST_API_Controller extends WP_REST_Controller {
	const HTTP_NO_CONTENT = '204';

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'a2c/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'bridge-action';

	/**
	 * Post type.
	 *
	 * @var string
	 */
	protected $post_type = 'shop_order';

	/**
	 * Register the routes for bridge.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::ALLMETHODS,
					'callback'            => [
						$this,
						'action',
					],
					'permission_callback' => [
						$this,
						'get_items_permissions_check',
					],
					'args'                => $this->get_collection_params(),
				],
			] );
	}

	/**
	 * Check permission.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		if ( get_option( A2CBC_BRIDGE_IS_INSTALLED ) !== false ) {
			$postParams = $request->get_body_params();

			if ( isset( $postParams['aelia_cs_currency'] ) ) {
				unset( $postParams['aelia_cs_currency'] );
			}

			if ( isset( $postParams['action'] ) && 'checkbridge' === $postParams['action'] ) {
				return true;
			}

			if ( ! defined( 'A2CBC_TOKEN' ) ) {
				return false;
			}

			if ( $request->get_param( 'token' ) ) {
				return new WP_Error( 'token_is_not_correct', 'ERROR: Field token is not correct', [ 'status' => 200 ] );
			}

			if ( empty( $postParams['a2c_sign'] ) ) {
				return new WP_Error( 'signature_is_not_correct', 'ERROR: Signature is not correct', [ 'status' => 200 ] );
			}

			$a2cSign = $postParams['a2c_sign'];
			unset( $postParams['a2c_sign'] );
			ksort( $postParams, SORT_STRING );
			$resSign = hash_hmac( 'sha256', http_build_query( $postParams ), A2CBC_TOKEN );

			return $a2cSign === $resSign;
		} else {
			return false;
		}
	}

	/**
	 * Action
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response
	 */
	public function action( WP_REST_Request $request ) {
		$response = new WP_REST_Response();
		$response->set_status( 200 );

		try {
			$adapter = new A2C_Config_Adapter();
			$bridge  = new A2C_Bridge( $adapter->create( $request ), $request );
		} catch ( \Exception $exception ) {
			$response->set_data( $exception->getMessage() );
			$response->set_status( 500 );

			return $response;
		}

		try {
			$bridgeRes = $bridge->run();
		} catch (Throwable $exception) {
			$bridgeRes = $exception->getMessage();
		}

		$res = ! empty( $bridgeRes ) ? $bridgeRes : '';

		if ( self::HTTP_NO_CONTENT == $res ) {
			$response->set_status( self::HTTP_NO_CONTENT );
		}

		$response->set_data( $res );

		return $response;
	}

}
