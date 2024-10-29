<?php
defined( 'ABSPATH' ) || die( 'Cannot access pages directly.' );

if ( ! defined( 'A2CBC_STORE_KEY' ) ) {
	define( 'A2CBC_STORE_KEY', 'A2C_store_key' );
}

class BridgeConnector
{
	const CART_ID = 'Woocommerce';
	const BRIDGE_ACTION = 'checkbridge';
	const BRIDGE_FOLDER = 'bridge2cart';
	const BRIDGE_ENDPOINT = 'a2c/v1/bridge-action';
	const ALLOWED_WARNINGS_CODES = [403];

	var $bridgeUrl = '';
	var $root = '';
	var $bridgePath = '';
	var $errorMessage = '';
	var $configFilePath = '/config.php';

	public function __construct() {
		if ( ! class_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		$this->root       = realpath( WP_CONTENT_DIR . '/..' );
		$this->bridgePath = realpath( dirname( __FILE__ ) ) . DIRECTORY_SEPARATOR . self::BRIDGE_FOLDER;
		$this->bridgeUrl  = get_home_url( null, rest_get_url_prefix(), 'rest' ) . DIRECTORY_SEPARATOR . self::BRIDGE_ENDPOINT;
	}

	/**
	 * GetBridgeUrl
	 *
	 * @return string
	 */
	public function getBridgeUrl() {
		return $this->bridgeUrl;
	}

	/**
	 * IsBridgeExist
	 *
	 * @return boolean
	 */
	public function isBridgeExist() {
		if ( is_dir( $this->bridgePath ) && file_exists( $this->bridgePath . '/bridge.php' ) && file_exists( $this->bridgePath . '/config.php' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * InstallBridge
	 *
	 * @return array
	 */
	public function installBridge() {
		if ( $this->isBridgeExist() ) {
			return $this->_checkBridge( true );
		} else {
			return [
				'success' => false,
				'message' => 'Bridge not exist. Please reinstall plugin',
				'custom'  => true,
			];
		}
	}

	/**
	 * UpdateToken
	 *
	 * @param string $token Token
	 *
	 * @return array
	 */
	public function updateToken( $token ) {
		global $wp_filesystem;

		if ( is_a( $wp_filesystem, 'WP_Filesystem_Base') ){
			$config = $wp_filesystem->get_contents( $this->bridgePath . $this->configFilePath );
		} else {
			$result['message'] = 'Can\'t init WP_Filesystem.';

			return $result;
		}

		$result = [
			'success' => false,
			'message' => 'Can\'t update Store Key',
		];

		if ( ! $config ) {
			$result['message'] = 'Can\'t open config.php. Please check permissions';

			return $result;
		}

		$writed = $wp_filesystem->put_contents(
			$this->bridgePath . $this->configFilePath,
			"<?php if ( ! defined( 'ABSPATH' ) ) exit; " . PHP_EOL . "  if (!defined('A2CBC_TOKEN')) {define('A2CBC_TOKEN', '" . $token . "');}",
			FS_CHMOD_FILE
		);

		if ( false === $writed ) {
			$result['message'] = 'Can\'t save config.php. Please check permissions';

			return $result;
		}

		return [
			'success' => true,
			'message' => 'Store Key updated successfully',
		];
	}

	/**
	 * GetStoreKey
	 *
	 * @return string
	 * @throws Exception
	 */
	public function getStoreKey() {
		$isMultiStore = function_exists( 'get_site_meta' );
		$storeKey     = $isMultiStore ? get_site_meta( 1, A2CBC_STORE_KEY, true ) : get_option( A2CBC_STORE_KEY );

		if ( ! $storeKey ) {
			$storeKey = self::generateStoreKey();
			if ( $isMultiStore ) {
				update_site_meta( 1, A2CBC_STORE_KEY, $storeKey );
			} else {
				update_option( A2CBC_STORE_KEY, $storeKey );
			}
		}

		global $wp_filesystem;

		preg_match( "/define\(\\s?'(\w+)',\s*'([^']*)'\\s?\);/", $wp_filesystem->get_contents( $this->bridgePath . $this->configFilePath ), $matches );

		if ( isset( $matches[2] ) && $matches[2] != $storeKey ) {
			$this->updateToken( $storeKey );
		}

		return $storeKey;
	}

	/**
	 * UpdateStoreKey
	 *
	 * @return string
	 * @throws Exception
	 */
	public function updateStoreKey() {
		$storeKey = self::generateStoreKey();
		function_exists( 'update_site_meta' ) ? update_site_meta( 1, A2CBC_STORE_KEY, $storeKey ) : update_option( A2CBC_STORE_KEY, $storeKey );

		return $storeKey;
	}

	/**
	 * GenerateStoreKey
	 *
	 * @return string
	 * @throws Exception
	 */
	public static function generateStoreKey() {
		$bytesLength = 256;

		if ( function_exists( 'random_bytes' ) ) {
			// available in PHP 7
			return md5( random_bytes( $bytesLength ) );
		}

		if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
			$bytes = openssl_random_pseudo_bytes( $bytesLength );
			if ( false !== $bytes ) {
				return md5( $bytes );
			}
		}

		$rand = '';
		for ( $i = 0; $i < $bytesLength; $i ++ ) {
			$rand .= chr( wp_rand( 0, 255 ) );
		}

		return md5( $rand );
	}

	/**
	 * Request
	 *
	 * @param string $storeKey Store Key
	 * @param bool   $isHttp   Store Key
	 *
	 * @return array|WP_Error
	 */
	protected function _request( $storeKey, $isHttp = false ) {
		$params  = [ 'store_root' => isset( $this->root ) ? $this->root : '' ];
		$data    = $this->_prepareUseHash( $storeKey, $params );
		$query   = http_build_query( $data['get'] );
		$headers = [
			'Accept-Language:*',
			'User-Agent:' . $this->_randomUserAgent(),
		];

		$url = $this->bridgeUrl . '?' . $query;

		if ( wp_http_supports( array( 'ssl' ) ) ) {
			$url = set_url_scheme( $url, 'https' );
		}

		if ( $isHttp ) {
			$url = set_url_scheme( $url, 'http' );
		}

		return wp_remote_post( $url,
			[
				'method'      => 'POST',
				'timeout'     => 30,
				'redirection' => 5,
				'blocking'    => true,
				'headers'     => $headers,
				'body'        => $data['post'],
				'cookies'     => [],
			] );

	}

	/**
	 * PrepareUseHash
	 *
	 * @param string     $storeKey Store Key
	 * @param array|null $params   Parameters
	 *
	 * @return array
	 */
	private function _prepareUseHash( $storeKey, array $params = null ) {
		$getParams = [
			'unique'         => md5( uniqid( wp_rand(), 1 ) ),
			'disable_checks' => 1,
			'cart_id'        => self::CART_ID,
		];

		if ( ! is_array( $params ) ) {
			$params = [];
		}

		$params['action']     = self::BRIDGE_ACTION;
		$params['cart_id']    = self::CART_ID;
		$params['store_root'] = rtrim( $this->root, DIRECTORY_SEPARATOR );

		ksort( $params, SORT_STRING );
		$params['a2c_sign'] = hash_hmac( 'sha256', http_build_query( $params ), $storeKey );

		return [
			'get'  => $getParams,
			'post' => $params,
		];
	}

	/**
	 * Get randomUserAgent
	 * Generate random User-Agent
	 *
	 * @return string
	 */
	private function _randomUserAgent() {
		$rand = wp_rand(1, 3);
		switch ($rand) {
			case 1:
				return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.6; rv:25.0) Gecko/2010' . wp_rand(10, 12) . wp_rand(10, 30) . ' Firefox/' . wp_rand(
						10,
						25
					) . '.0';

			case 2:
				return 'Mozilla/6.0 (Windows NT 6.2; WOW64; rv:16.0.1) Gecko/2012' . wp_rand(10, 12) . wp_rand(10, 30) . ' Firefox/' . wp_rand(
						10,
						16
					) . '.0.1';

			case 3:
				return 'Opera/10.' . wp_rand(10, 60) . ' (Windows NT 5.1; U; en) Presto/2.6.30 Version/10.60';
		}
	}

	/**
	 * CheckBridge
	 *
	 * @param bool $isCustom Custom Flag
	 *
	 * @return array
	 */
	protected function _checkBridge( $isCustom = false ) {
		global $wp_filesystem;

		if ( is_a( $wp_filesystem, 'WP_Filesystem_Base') ){
			$content = $wp_filesystem->get_contents( $this->bridgePath . $this->configFilePath );
		} else {
			return [
				'success' => false,
				'message' => 'Can\'t init WP_Filesystem.',
				'custom'  => $isCustom,
				'warning' => false,
			];
		}

		$success = true;

		if ( $content ) {
			$storeKey = '';

			foreach ( explode( "\n", $content ) as $line ) {
				if ( preg_match( '/define\([\'|"]A2CBC_TOKEN[\'|"],[ ]*[\'|"](.*?)[\'|"]\)/s', $line, $matches ) ) {
					$storeKey = $matches[1];
					break;
				}
			}

			$res = $this->_request( $storeKey );

			if ( is_wp_error( $res ) && strpos( $res->get_error_message(), 'cURL error' ) !== false ) {
				// try to http
				$res = $this->_request( $storeKey, true );
			}

			if ( is_wp_error( $res ) ) {
				$success = false;
				$warning = in_array($res->get_error_code(), self::ALLOWED_WARNINGS_CODES);
				$errorMessage = 'Url:' . $this->bridgeUrl . '</br>' . $res->get_error_message();
			} else {
				if ( str_contains( wp_remote_retrieve_body( $res ), 'BRIDGE_OK' ) ) {
					$success = true;
					$errorMessage = 'Bridge install successfully';
					$warning = false;
				} else {
					$success = false;
					$errorMessage = 'Can\'t verify bridge url: ' . $this->bridgeUrl . '.</br>Status code:' . wp_remote_retrieve_response_code( $res );
					$warning = in_array( wp_remote_retrieve_response_code( $res ), self::ALLOWED_WARNINGS_CODES );
				}
			}

			return [
				'success' => $success,
				'message' => $errorMessage,
				'custom'  => $isCustom,
				'warning' => $warning,
			];
		} else {
			$error = error_get_last();

			return [
				'success' => false,
				'message' => 'Url:' . $this->bridgeUrl . '</br>' . $error['message'],
				'custom'  => $isCustom,
				'warning' => false,
			];
		}
	}

}