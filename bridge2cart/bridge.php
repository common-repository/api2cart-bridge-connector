<?php

abstract class A2C_DatabaseLink {

	protected static $_maxRetriesToConnect = 5;

	protected static $_sleepBetweenAttempts = 2;

	protected $_config = null;

	protected $_request = null;

	private $_databaseHandle = null;

	protected $_insertedId = 0;

	protected $_affectedRows = 0;


	/**
	 * Constructor
	 *
	 * @param A2C_Config_Adapter $config Config adapter
	 * @return A2C_DatabaseLink
	 */
	public function __construct( $config ) {
		$this->_config = $config;
		$this->_request = $config->getRequest();
	}

	/**
	 * Destructor
	 *
	 * @return void
	 */
	public function __destruct() {
		$this->_releaseHandle();
	}

	/**
	 * TryToConnect
	 *
	 * @return bool|resource|wpdb
	 */
	private function _tryToConnect() {
		$triesCount = self::$_maxRetriesToConnect;

		$link = null;

		while ( ! $link ) {
			if ( ! $triesCount -- ) {
				break;
			}

			$link = $this->_connect();
			if ( ! $link ) {
				sleep( self::$_sleepBetweenAttempts );
			}
		}

		if ( $link ) {
			$this->_afterConnect( $link );

			return $link;
		} else {
			return false;
		}
	}

	/**
	 * GetDatabaseHandle
	 * Database handle getter
	 *
	 * @return bool|resource|wpdb|null
	 */
	final protected function _getDatabaseHandle() {
		if ( $this->_databaseHandle ) {
			return $this->_databaseHandle;
		}

		$this->_databaseHandle = $this->_tryToConnect();

		if ( $this->_databaseHandle ) {
			return $this->_databaseHandle;
		} else {
			exit( esc_html( $this->_errorMsg( 'Can not connect to DB' ) ) );
		}
	}

	/**
	 * ReleaseHandle
	 * Close DB handle and set it to null; used in reconnect attempts
	 *
	 * @return void
	 */
	final protected function _releaseHandle() {
		if ( $this->_databaseHandle ) {
			$this->_closeHandle( $this->_databaseHandle );
		}

		$this->_databaseHandle = null;
	}

	/**
	 * ErrorMsg
	 * Format error message
	 *
	 * @param string $error Raw error message
	 * @return string
	 */
	final protected function _errorMsg( $error ) {
		$className = get_class( $this );

		return '[$className] MySQL Query Error: $error';
	}

	/**
	 * Query
	 *
	 * @param string  $sql       SQL query
	 * @param integer $fetchType Fetch type
	 * @param array   $extParams Extended params
	 * @return array
	 */
	final public function query( $sql, $fetchType, $extParams ) {
		if ( $extParams['set_names'] ) {
			$this->_dbSetNames( $extParams['set_names'] );
		}

		return $this->_query( $sql, $fetchType, $extParams['fetch_fields'] );
	}

	/**
	 * Connect
	 *
	 * @return boolean|null|resource
	 */
	abstract protected function _connect();

	/**
	 * Additional database handle manipulations - e.g. select DB
	 *
	 * @param stdClass $handle DB Handle
	 * @return void
	 */
	abstract protected function _afterConnect( $handle );

	/**
	 * Close DB handle
	 *
	 * @param stdClass $handle DB Handle
	 * @return void
	 */
	abstract protected function _closeHandle( $handle );

	/**
	 * LocalQuery
	 *
	 * @param string $sql sql query
	 * @return array
	 */
	abstract public function localQuery( $sql );

	/**
	 * Query
	 *
	 * @param string  $sql         Sql query
	 * @param integer $fetchType   Fetch Type
	 * @param boolean $fetchFields Fetch fields metadata
	 * @return array
	 */
	abstract protected function _query( $sql, $fetchType, $fetchFields = false );

	/**
	 * GetLastInsertId
	 *
	 * @return string|integer
	 */
	public function getLastInsertId() {
		return $this->_insertedId;
	}

	/**
	 * GetAffectedRows
	 *
	 * @return integer
	 */
	public function getAffectedRows() {
		return $this->_affectedRows;
	}

	/**
	 * DB SetNames
	 *
	 * @param string $charset Charset
	 * @return void
	 */
	abstract protected function _dbSetNames( $charset );

}

/**
 * Class A2C_Mysqli
 */
class A2C_Mysqli extends A2C_DatabaseLink {

	/**
	 * Connect
	 *
	 * @return bool|resource|wpdb|null
	 */
	protected function _connect() {
		global $wpdb;

		if ( ! $wpdb ) {
			require_wp_db();
		}

		if ( empty( $wpdb->dbh ) || empty( $wpdb->dbh->client_info ) ) {
			$wpdb->db_connect( false );
		}

		return $wpdb;
	}

	/**
	 * AfterConnect
	 *
	 * @param mysqli $handle DB Handle
	 * @return void
	 */
	protected function _afterConnect( $handle ) {
	}

	/**
	 * LocalQuery
	 *
	 * @inheritdoc
	 */
	public function localQuery( $sql ) {
		/**
		 * Handle
		 *
		 * @var wpdb $databaseHandle Handle
		 */
		$databaseHandle = $this->_getDatabaseHandle();

		$res = $databaseHandle->get_results( $databaseHandle->prepare($sql), ARRAY_A );

		if ( is_bool( $res ) ) {
			return $res;
		}

		return $res;
	}

	/**
	 * Query
	 *
	 * @inheritdoc
	 */
	protected function _query( $sql, $fetchType, $fetchFields = false ) {
		$result = array(
			'result'        => null,
			'message'       => '',
			'fetchedFields' => '',
		);

		$fetchMode = ARRAY_A;
		switch ( $fetchType ) {
			case 3:
				$fetchMode = OBJECT;
				break;
			case 2:
				$fetchMode = ARRAY_N;
				break;
			case 1:
				$fetchMode = ARRAY_A;
				break;
			default:
				break;
		}

		/**
		 * Handle
		 *
		 * @var wpdb $databaseHandle Handle
		 */
		$databaseHandle = $this->_getDatabaseHandle();

		$res = $databaseHandle->get_results( $databaseHandle->prepare($sql), $fetchMode );

		if ( '' != ( $databaseHandle->last_error ) ) {
			$result['message'] = $this->_errorMsg( $databaseHandle->last_error );

			return $result;
		}

		$this->_affectedRows = $databaseHandle->rows_affected;
		$this->_insertedId   = $databaseHandle->insert_id;

		if ( is_bool( $res ) ) {
			$result['result'] = $res;

			return $result;
		}

		if ( $fetchFields && $res ) {
			$columnInfo = $databaseHandle->__get('col_info');
			$fetchedFields = [];

			foreach ( $columnInfo as $field) {
				$fetchedFields[] = $field;
			}

			$result['fetchedFields'] = $fetchedFields;
		}

		$result['result'] = $res;

		return $result;
	}

	/**
	 * DB SetNames
	 *
	 * @inheritdoc
	 */
	protected function _dbSetNames( $charset ) {
		/**
		 * Handle
		 *
		 * @var wpdb $databaseHandle Handle
		 */
		$databaseHandle = $this->_getDatabaseHandle();

		$databaseHandle->set_charset( $databaseHandle->dbh, $charset );
	}

	/**
	 * CloseHandle
	 *
	 * @param wpdb $handle DB Handle
	 * @return void
	 */
	protected function _closeHandle( $handle ) {
		$handle->flush();
		$handle->close();
	}

}

class A2C_Config_Adapter {

	public $host                = 'localhost';

	public $port                = null;

	public $sock                = null;

	public $username            = 'root';

	public $password            = '';

	public $dbname              = '';

	public $tblPrefix           = '';

	public $timeZone            = null;

	public $cartType                 = 'Wordpress';

	public $cartId                   = '';

	public $imagesDir                = '';

	public $categoriesImagesDir      = '';

	public $productsImagesDir        = '';

	public $manufacturersImagesDir   = '';

	public $categoriesImagesDirs     = '';

	public $productsImagesDirs       = '';

	public $manufacturersImagesDirs  = '';

	public $languages   = array();

	public $cartVars    = array();

	public $request = null;

	/**
	 * Create
	 *
	 * @param WP_REST_Request $request Request
	 *
	 * @return mixed
	 */
	public function create( WP_REST_Request $request ) {
		$cartType  = $this->cartType;
		$className = 'A2C_Config_Adapter_' . $cartType;

		$obj           = new $className($request);
		$obj->cartType = $cartType;

		return $obj;
	}

	/**
	 * GetActiveModules
	 *
	 * @inheritDoc
	 */
	public function getActiveModules( array $a2cData ) {
		return array( 'error' => 'Action is not supported', 'data' => false );
	}

	/**
	 * Get Card ID string from request parameters
	 *
	 * @return string
	 */
	protected function _getRequestCartId() {
		$request = $this->getRequest();
		$parameters = $request->get_params();

		return isset( $parameters['cart_id'] ) ? sanitize_text_field( $parameters['cart_id'] ) : '';
	}

	/**
	 * GetAdapterPath
	 *
	 * @param string $cartType
	 * @return string
	 */
	public function getAdapterPath( $cartType ) {
		return A2CBC_STORE_BASE_DIR . A2CBC_BRIDGE_DIRECTORY_NAME . DIRECTORY_SEPARATOR . 'app' .
					 DIRECTORY_SEPARATOR . 'class' . DIRECTORY_SEPARATOR . 'config_adapter' . DIRECTORY_SEPARATOR . $cartType . '.php';
	}

	/**
	 * SetHostPort
	 *
	 * @param $source
	 */
	public function setHostPort( $source ) {
		$source = trim( $source );

		if ( '' == $source ) {
			$this->host = 'localhost';

			return;
		}

		if ( false !== strpos( $source, '.sock' ) ) {
			$socket = ltrim( $source, 'localhost:' );
			$socket = ltrim( $socket, '127.0.0.1:' );

			$this->host = 'localhost';
			$this->sock = $socket;

			return;
		}

		$conf = explode( ':', $source );

		if ( isset( $conf[0] ) && isset( $conf[1] ) ) {
			$this->host = $conf[0];
			$this->port = $conf[1];
		} elseif ( '/' == $source[0] ) {
			$this->host = 'localhost';
			$this->port = $source;
		} else {
			$this->host = $source;
		}
	}

	/**
	 * Connect
	 *
	 * @return false|A2C_Mysqli
	 */
	public function connect() {
		return new A2C_Mysqli( $this );
	}

	/**
	 * GetCartVersionFromDb
	 *
	 * @param string $field     Field
	 * @param string $tableName Table name
	 * @param string $where     Where
	 *
	 * @return string
	 */
	public function getCartVersionFromDb( $field, $tableName, $where ) {
		global $wpdb;

		$version      = '';
		$globalTables = [ 'users', 'usermeta', 'blogs', 'blogmeta', 'signups', 'site', 'sitemeta', 'sitecategories', 'registration_log' ];

		if ( in_array( $tableName, $globalTables ) ) {
			$tblPrefix = isset( $wpdb->base_prefix ) ? $wpdb->base_prefix : $this->tblPrefix;
		} else {
			$tblPrefix = $this->tblPrefix;
		}

		$link = $this->connect();

		if ( ! $link ) {
			return '[ERROR] MySQL Query Error: Can not connect to DB';
		}

		$result = $link->localQuery( '
			SELECT ' . $field . ' AS version
			FROM ' . $tblPrefix . $tableName . '
			WHERE ' . $where );

		if ( is_array( $result ) && isset( $result[0]['version'] ) ) {
			$version = $result[0]['version'];
		}

		return $version;
	}

	/**
	 * Get Request
	 *
	 * @return null|WP_REST_Request
	 */
	public function getRequest() {
		return $this->request;
	}

	/**
	 * Set Request
	 *
	 * @param WP_REST_Request $request Request
	 */
	public function setRequest( WP_REST_Request $request ) {
		$this->request = $request;
	}
}

class A2C_Bridge {

	/**
	 * A2C_DatabaseLink
	 *
	 * @var A2C_DatabaseLink|null
	 */
	protected $_link  = null; //mysql connection link

	public $config    = null; //config adapter

	/**
	 * Request
	 *
	 * @var WP_REST_Request $request Request
	 */
	public $request;

	/**
	 * Bridge constructor
	 *
	 * A2C_Bridge constructor.
	 *
	 * @param A2C_Config_Adapter $config  Config
	 * @param WP_REST_Request    $request Request
	 */
	public function __construct( A2C_Config_Adapter $config, WP_REST_Request $request ) {
		$this->config  = $config;
		$this->request = $request;

		if ( $this->getAction() != 'savefile' ) {
			$this->_link = $this->config->connect();
		}
	}

	/**
	 * GetTablesPrefix
	 *
	 * @return mixed
	 */
	public function getTablesPrefix() {
		return $this->config->tblPrefix;
	}

	/**
	 * Get Request
	 *
	 * @return WP_REST_Request
	 */
	public function getRequest() {
		return $this->request;
	}

	/**
	 * GetLink
	 *
	 * @return A2C_DatabaseLink|null
	 */
	public function getLink() {
		return $this->_link;
	}

	/**
	 * GetAction
	 *
	 * @return mixed|string
	 */
	private function getAction() {
		$action = $this->request->get_param( 'action' );

		if ( null !== $action ) {
			return str_replace( '.', '', sanitize_text_field( $action ) );
		}

		return '';
	}

	/**
	 * Run
	 *
	 * @return mixed|string
	 */
	public function run() {
		$action = $this->getAction();
		$request = $this->getRequest();
		$parameters = $request->get_params();
		$postParameters = $request->get_body_params();

		if ( 'checkbridge' == $action ) {
			if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
				return ['message' => 'BRIDGE_OK', 'key_id' => A2CBC_BRIDGE_PUBLIC_KEY_ID, 'bridge_version' => A2CBC_BRIDGE_VERSION];
			} else {
				return 'BRIDGE_OK';
			}
		}

		if ( isset( $parameters['token'] ) ) {
			return 'ERROR: Field token is not correct';
		}

		if ( empty( $postParameters ) ) {
			return 'BRIDGE INSTALLED.<br /> Version: ' . A2CBC_BRIDGE_VERSION;
		}

		if ( 'update' == $action ) {
			$this->_checkPossibilityUpdate();
		}

		$className = 'A2C_Bridge_Action_' . ucfirst( $action );
		if ( ! class_exists( $className ) ) {
			return 'ACTION_DO_NOT EXIST' . PHP_EOL;
		}

		$actionObj = new $className();
		@$actionObj->cartType = @$this->config->cartType;
		$res = $actionObj->Perform( $this );
		$this->_destroy();

		return $res;
	}

	/**
	 * Destroy
	 */
	private function _destroy() {
		$this->_link = null;
	}

	/**
	 * CheckPossibilityUpdate
	 *
	 * @return string
	 */
	private function _checkPossibilityUpdate() {
		global $wp_filesystem;

		if ( null === $wp_filesystem ) {
			if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}

			$wp_filesystem = new WP_Filesystem_Direct( '' );
		}

		if ( ! $wp_filesystem->is_writable( __DIR__ ) ) {
			return 'ERROR_BRIDGE_DIR_IS_NOT_WRITABLE';
		}

		if ( ! $wp_filesystem->is_writable( __FILE__ ) ) {
			return 'ERROR_BRIDGE_IS_NOT_WRITABLE';
		}
	}

	/**
	 * Remove php comments from string
	 *
	 * @param string $str String
	 */
	public static function removeComments( $str ) {
		$result        = '';
		$commentTokens = array( T_COMMENT, T_DOC_COMMENT );
		$tokens        = token_get_all( $str );

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], $commentTokens ) ) {
					continue;
				}

				$token = $token[1];
			}

			$result .= $token;
		}

		return $result;
	}

	/**
	 * ParseDefinedConstants
	 *
	 * @param sting   $str        String
	 * @param string  $constNames Const Names
	 * @param boolean $onlyString Only String
	 *
	 * @return array
	 */
	public static function parseDefinedConstants( $str, $constNames = '\w+', $onlyString = true ) {
		$res     = array();
		$pattern = '/define\s*\(\s*[\'"](' . $constNames . ')[\'"]\s*,\s*' . ( $onlyString ? '[\'"]' : '' ) . '(.*?)' . ( $onlyString ? '[\'"]' : '' ) . '\s*\)\s*;/';

		preg_match_all( $pattern, $str, $matches );

		if ( isset( $matches[1] ) && isset( $matches[2] ) ) {
			foreach ( $matches[1] as $key => $constName ) {
				$res[ $constName ] = $matches[2][ $key ];
			}
		}

		return $res;
	}
}

/**
 * Class A2C_Config_Adapter_Wordpress
 */
class A2C_Config_Adapter_Wordpress extends A2C_Config_Adapter {

	const ERROR_CODE_SUCCESS = 0;
	const ERROR_CODE_ENTITY_NOT_FOUND = 1;
	const ERROR_CODE_INTERNAL_ERROR = 2;

	private $_multiSiteEnabled = false;

	private $_pluginName = '';

	private $_wpmlEnabled = false;

	/**
	 * A2C_Config_Adapter_Wordpress constructor.
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;
		$this->_tryLoadConfigs();

		$getActivePlugin = function ( array $cartPlugins ) {
			foreach ( $cartPlugins as $plugin ) {
				$cartId = $this->_getRequestCartId();

				if ( $cartId ) {
					if ( 'Woocommerce' == $cartId && false !== strpos( $plugin, 'woocommerce.php' ) ) {
						return 'woocommerce';
					} elseif ( 'WPecommerce' == $cartId && ( 0 === strpos( $plugin, 'wp-e-commerce' ) || 0 === strpos( $plugin, 'wp-ecommerce' ) ) ) {
						return 'wp-e-commerce';
					}
				} else {
					if ( strpos( $plugin, 'woocommerce.php' ) !== false ) {
						return 'woocommerce';
					} elseif ( strpos( $plugin, 'wp-e-commerce' ) === 0 || strpos( $plugin, 'wp-ecommerce' ) === 0 ) {
						return 'wp-e-commerce';
					}
				}
			};

			return false;
		};

		$activePlugin = false;
		$wpTblPrefix  = $this->tblPrefix;

		if ( $this->_multiSiteEnabled ) {
			$cartPluginsNetwork = $this->getCartVersionFromDb( 'meta_value',
				'sitemeta',
				'meta_key = \'active_sitewide_plugins\'' );

			if ( $cartPluginsNetwork ) {
				$cartPluginsNetwork = unserialize( $cartPluginsNetwork );
				$activePlugin       = $getActivePlugin( array_keys( $cartPluginsNetwork ) );
			}

			if ( false ===$activePlugin ) {
				$link = $this->connect();

				if ( $link ) {
					$blogs = $link->localQuery( 'SELECT blog_id FROM ' . $this->tblPrefix . 'blogs' );
					if ( $blogs ) {
						foreach ( $blogs as $blog ) {
							if ( $blog['blog_id'] > 1 ) {
								$this->tblPrefix = $this->tblPrefix . $blog['blog_id'] . '_';
							}

							$cartPlugins = $this->getCartVersionFromDb( 'option_value', 'options', 'option_name = \'active_plugins\'' );
							if ( $cartPlugins ) {
								$activePlugin = $getActivePlugin( unserialize( $cartPlugins ) );
							}

							if ( $activePlugin ) {
								break;
							} else {
								$this->tblPrefix = $wpTblPrefix;
							}
						}
					}
				} else {
					return '[ERROR] MySQL Query Error: Can not connect to DB';
				}
			}
		} else {
			$cartPlugins = $this->getCartVersionFromDb( 'option_value', 'options', 'option_name = \'active_plugins\'' );
			if ( $cartPlugins ) {
				$activePlugin = $getActivePlugin( unserialize( $cartPlugins ) );
			}
		}

		if ( 'woocommerce' == $activePlugin ) {
			$this->_setWoocommerceData();
		} elseif ( 'wp-e-commerce' == $activePlugin ) {
			$this->_setWpecommerceData();
		} else {
			return 'CART_PLUGIN_IS_NOT_DETECTED';
		}

		$this->_pluginName = $activePlugin;
		$this->tblPrefix   = $wpTblPrefix;

		if (isset($_POST['aelia_cs_currency'])) {
			unset($_POST['aelia_cs_currency']);
		}
	}

	/**
	 * SetWoocommerceData
	 */
	protected function _setWoocommerceData() {
		$this->cartId = 'Woocommerce';
		$version      = $this->getCartVersionFromDb( 'option_value', 'options', 'option_name = \'woocommerce_db_version\'' );

		if ( '' != $version ) {
			$this->cartVars['dbVersion'] = $version;
		}

		$this->cartVars['categoriesDirRelative'] = 'images/categories/';
		$this->cartVars['productsDirRelative']   = 'images/products/';
	}

	/**
	 * ResetGlobalVars
	 *
	 * @return void
	 */
	private function _resetGlobalVars() {
		foreach ( $GLOBALS as $varname => $value ) {
			global $$varname; //$$ is no mistake here

			$$varname = $value;
		}
	}

	/**
	 * SetWpecommerceData
	 */
	protected function _setWpecommerceData() {
		$this->cartId = 'Wpecommerce';
		$version      = $this->getCartVersionFromDb( 'option_value', 'options', 'option_name = \'wpsc_version\'' );
		$bridgeDir = plugin_dir_path(__FILE__);
		$pluginsDir = dirname('../' . dirname($bridgeDir));

		if ( '' != $version ) {
			$this->cartVars['dbVersion'] = $version;
		} else {
			global $wp_filesystem;

			if ( null === $wp_filesystem ) {
				if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
					require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
				}

				$wp_filesystem = new WP_Filesystem_Direct( '' );
			}

			$filePath = $pluginsDir . DIRECTORY_SEPARATOR . 'wp-shopping-cart' . DIRECTORY_SEPARATOR . 'wp-shopping-cart.php';

			if ( file_exists( $filePath ) ) {
				$conf = $wp_filesystem->get_contents( $filePath );
				preg_match("/define\('WPSC_VERSION.*/", $conf, $match);
				if ( isset( $match[0] ) && ! empty( $match[0] ) ) {
					preg_match('/\d.*/', $match[0], $project);
					if ( isset( $project[0] ) && ! empty( $project[0] ) ) {
						$version = $project[0];
						$version = str_replace( array( ' ', '-', '_', '\'', ');', ')', ';' ), '', $version );
						if ( '' != $version ) {
							$this->cartVars['dbVersion'] = strtolower( $version );
						}
					}
				}
			}
		}

		if ( file_exists( $pluginsDir . DIRECTORY_SEPARATOR . 'shopp' . DIRECTORY_SEPARATOR . 'Shopp.php' )
				 || file_exists( $pluginsDir . DIRECTORY_SEPARATOR . 'wp-e-commerce' . DIRECTORY_SEPARATOR . 'editor.php' )
		) {
			$this->imagesDir              = wp_upload_dir( null, false )['basedir'] . DIRECTORY_SEPARATOR . 'wpsc' . DIRECTORY_SEPARATOR;
			$this->categoriesImagesDir    = $this->imagesDir . 'category_images' . DIRECTORY_SEPARATOR;
			$this->productsImagesDir      = $this->imagesDir . 'product_images' . DIRECTORY_SEPARATOR;
			$this->manufacturersImagesDir = $this->imagesDir;
		} elseif ( file_exists( $pluginsDir . DIRECTORY_SEPARATOR . 'wp-e-commerce' . DIRECTORY_SEPARATOR . 'wp-shopping-cart.php' ) ) {
			$this->imagesDir              = wp_upload_dir( null, false )['basedir'] . DIRECTORY_SEPARATOR . '';
			$this->categoriesImagesDir    = $this->imagesDir . 'wpsc' . DIRECTORY_SEPARATOR . 'category_images' . DIRECTORY_SEPARATOR;
			$this->productsImagesDir      = $this->imagesDir;
			$this->manufacturersImagesDir = $this->imagesDir;
		} else {
			$this->imagesDir              = 'images' . DIRECTORY_SEPARATOR;
			$this->categoriesImagesDir    = $this->imagesDir;
			$this->productsImagesDir      = $this->imagesDir;
			$this->manufacturersImagesDir = $this->imagesDir;
		}
	}

	/**
	 * TryLoadConfigs
	 *
	 * @return boolean
	 */
	protected function _tryLoadConfigs() {
		global $wpdb;

		try {
			if ( defined( 'DB_NAME' ) && defined( 'DB_USER' ) && defined( 'DB_HOST' ) ) {
				$this->dbname   = DB_NAME;
				$this->username = DB_USER;
				$this->setHostPort( DB_HOST );
			} else {
				return false;
			}

			if ( defined( 'DB_PASSWORD' ) ) {
				$this->password = DB_PASSWORD;
			} elseif ( defined( 'DB_PASS' ) ) {
				$this->password = DB_PASS;
			} else {
				return false;
			}

			$this->imagesDir = wp_upload_dir( null, false )['basedir'];

			$this->_multiSiteEnabled = ( defined( 'MULTISITE' ) && MULTISITE === true );

			if ( $this->_multiSiteEnabled ) {
				$this->imagesDir = str_replace('/sites/' . get_current_blog_id(), '', $this->imagesDir);

				if ( defined( 'WP_SITEURL' ) ) {
					$this->cartVars['wp_siteurl'] = WP_SITEURL;
				}

				if ( defined( 'WP_HOME' ) ) {
					$this->cartVars['wp_home'] = WP_HOME;
				}
			}

			$this->cartVars['wp_content_url'] = content_url();

			if ( isset( $table_prefix ) ) {
				$this->tblPrefix = $table_prefix;
			} elseif ( isset( $wpdb->base_prefix ) ) {
				$this->tblPrefix = $wpdb->base_prefix;
			} elseif ( isset( $GLOBALS['table_prefix'] ) ) {
				$this->tblPrefix = $GLOBALS['table_prefix'];
			}
		} catch ( Exception $e ) {
			die( 'ERROR_READING_STORE_CONFIG_FILE' );
		}

		foreach ( get_defined_vars() as $key => $val ) {
			$GLOBALS[ $key ] = $val;
		}

		return true;
	}

	/**
	 * SendEmailNotifications
	 *
	 * @param array $a2cData Notifications data
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function sendEmailNotifications( array $a2cData ) {
		if ( 'woocommerce' === $this->_pluginName ) {
			return $this->_wcEmailNotification( $a2cData );
		} else {
			throw new Exception( 'Action is not supported' );
		}
	}

	/**
	 * WcEmailNotification
	 *
	 * @param array $a2cData Notifications data
	 *
	 * @return boolean
	 */
	private function _wcEmailNotification( array $a2cData ) {
		if ( function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $a2cData['store_id'] );
		}

		$emails = WC()->mailer()->get_emails();//init mailer

		foreach ( $a2cData['notifications'] as $notification ) {
			if ( isset( $notification['wc_class'] ) ) {
				if ( isset( $emails[ $notification['wc_class'] ] ) ) {
					call_user_func_array( array( $emails[ $notification['wc_class'] ], 'trigger' ), $notification['data'] );
				} else {
					return false;
				}
			} else {
				do_action( $notification['wc_action'], $notification['data'] );
			}
		}

		return true;
	}

	/**
	 * TriggerEvents
	 *
	 * @inheritDoc
	 * @return boolean
	 */
	public function triggerEvents( array $a2cData ) {
		if ( function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $a2cData['store_id'] );
		}

		foreach ( $a2cData['events'] as $event ) {
			if ( 'update' === $event['event'] ) {
				switch ( $event['entity_type'] ) {
					case 'product':
						$product = WC()->product_factory->get_product( $event['entity_id'] );
						if ( in_array( 'stock_status', $event['updated_meta'], true ) ) {
							do_action( 'woocommerce_product_set_stock_status', $product->get_id(), $product->get_stock_status(), $product );
						}

						if ( in_array( 'stock_quantity', $event['updated_meta'], true ) ) {
							do_action( 'woocommerce_product_set_stock', $product );
						}

						do_action( 'woocommerce_product_object_updated_props', $product, $event['updated_meta'] );
						break;
					case 'variant':
						$product = WC()->product_factory->get_product( $event['entity_id'] );
						if ( in_array( 'stock_status', $event['updated_meta'], true ) ) {
							do_action( 'woocommerce_variation_set_stock_status', $event['entity_id'], $product->get_stock_status(), $product );
						}

						if ( in_array( 'stock_quantity', $event['updated_meta'], true ) ) {
							do_action( 'woocommerce_variation_set_stock', $product );
						}

						do_action( 'woocommerce_product_object_updated_props', $product, $event['updated_meta'] );
						break;
					case 'order':
						$entity = WC()->order_factory->get_order( $event['entity_id'] );
						do_action( 'woocommerce_order_status_' . $event['status']['to'], $entity->get_id(), $entity );

						if ( isset( $event['status']['from'] ) ) {
							do_action( 'woocommerce_order_status_' . $event['status']['from'] . '_to_' . $event['status']['to'], $entity->get_id(), $entity );
							do_action( 'woocommerce_order_status_changed', $entity->get_id(), $event['status']['from'], $event['status']['to'], $entity );
						}
						break;
					case 'shipment':
						$entity = WC()->order_factory->get_order( $event['entity_id'] );
						$data = unserialize( $a2cData['metaData'], ['allowed_classes' => ['stdClass']] );

						if ( empty($data) ) {
							$entity->delete_meta_data( '_wc_shipment_tracking_items' );
						} else {
							$entity->update_meta_data( '_wc_shipment_tracking_items', $data );
						}

						$entity->save_meta_data();
						do_action( 'update_order_status_after_adding_tracking', $event['status'], $entity );
				}
			} elseif ( 'delete' === $event['event'] ) {
				switch ( $event['entity_type'] ) {
					case 'shipment':
						$entity = WC()->order_factory->get_order( $event['entity_id'] );

						foreach ( $event['tracking_info'] as $trackingInfo ) {
							$trackingProvider = $trackingInfo['tracking_provider'];
							$trackingNumber   = $trackingInfo['tracking_number'];

							// translators: %1$s is the tracking provider, %2$s is the tracking number
							$note = sprintf( esc_html__( 'Tracking info was deleted for tracking provider %1$s with tracking number %2$s',
								'api2cart-bridge-connector' ),
								$trackingProvider,
								$trackingNumber );
							// Add the note
							$entity->add_order_note( $note );
						}
				}
			}
		}

		return true;
	}

	/**
	 * SetMetaData
	 *
	 * @inheritDoc
	 * @return array
	 */
	public function setMetaData( array $a2cData ) {
		$response = [
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		];

		$reportError = function ( $e ) use ( $response ) {
			$response['error']      = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			if ( function_exists( 'switch_to_blog' ) ) {
				switch_to_blog( $a2cData['store_id'] );
			}

			$id = (int) $a2cData['entity_id'];

			switch ( $a2cData['entity'] ) {
				case 'variant':
				case 'product':
					$entity = WC()->product_factory->get_product( $id );
					break;
				case 'order':
					$entity = WC()->order_factory->get_order( $id );
					break;
				case 'category':
					$entity = get_term( $id, 'product_cat' );
					break;
				case 'customer':
					$entity = new WC_Customer( $id );
					break;
			}

			if ( ! $entity ) {
				$response['error_code'] = self::ERROR_CODE_ENTITY_NOT_FOUND;
				$response['error']      = $a2cData['entity'];
			} elseif ( 'category' != $a2cData['entity'] ) {
				if ( isset( $a2cData['meta'] ) ) {
					foreach ( $a2cData['meta'] as $key => $value ) {
						$entity->add_meta_data( $key, $value, true );
					}
				}

				if ( isset( $a2cData['unset_meta'] ) ) {
					foreach ( $a2cData['unset_meta'] as $key ) {
						$entity->delete_meta_data( $key );
					}
				}

				if ( isset( $a2cData['meta'] ) || isset( $a2cData['unset_meta'] ) ) {
					$entity->save();

					if ( isset( $a2cData['meta'] ) ) {
						global $wpdb;
						$wpdb->set_blog_id( $a2cData['store_id'] );
						$keys = implode( '\', \'', $wpdb->_escape( array_keys( $a2cData['meta'] ) ) );

						switch ( $a2cData['entity'] ) {
							case 'product':
							case 'order':
								$qRes = $wpdb->get_results(
									$wpdb->prepare('
										SELECT pm.meta_id, pm.meta_key, pm.meta_value
										FROM ' . $wpdb->postmeta . ' AS pm
										WHERE pm.post_id = %d
										AND pm.meta_key IN (\'%s\')',
										$id,
										$keys
									)
								);
								break;

							case 'customer':
								$qRes = $wpdb->get_results(
									$wpdb->prepare( '
										SELECT um.umeta_id AS \'meta_id\', um.meta_key, um.meta_value
										FROM ' . $wpdb->usermeta . ' AS um
										WHERE um.user_id = %d
										AND um.meta_key IN (\'%s\')',
										$id,
										$keys
									)
								);

								break;
						}

						$response['result']['meta'] = $qRes;
					}

					if ( isset( $a2cData['unset_meta'] ) ) {
						foreach ( $a2cData['unset_meta'] as $key ) {
							$response['result']['removed_meta'][ $key ] = ! (bool) $entity->get_meta( $key );
						}
					}
				}
			} else {
				if ( isset( $a2cData['meta'] ) ) {
					global $wpdb;

					foreach ( $a2cData['meta'] as $key => $value ) {
						add_term_meta( $id, $key, $value );
					}

					$wpdb->set_blog_id( $a2cData['store_id'] );
					$keys = implode( '\', \'', $wpdb->_escape( array_keys( $a2cData['meta'] ) ) );

					$qRes = $wpdb->get_results(
						$wpdb->prepare( '
							SELECT tm.meta_id, tm.meta_key, tm.meta_value
							FROM ' . $wpdb->termmeta . ' AS tm
							WHERE tm.term_id = %d
							AND tm.meta_key IN (\'%s\')',
							$id,
							$keys
						)
					);

					$response['result']['meta'] = $qRes;
				}

				if ( isset( $a2cData['unset_meta'] ) ) {
					foreach ( $a2cData['unset_meta'] as $key ) {
						delete_term_meta( $id, $key );

						$response['result']['removed_meta'][ $key ] = ! (bool) get_term_meta( $id, $key );
					}
				}
			}
		} catch ( Exception $e ) {
			return $reportError( $e );
		} catch ( Throwable $e ) {
			return $reportError( $e );
		}

		return $response;
	}

	/**
	 * GetTranslations
	 *
	 * @inheritDoc
	 * @return array
	 */
	public function getTranslations( array $a2cData ) {
		$response = [
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		];

		$reportError = function ( $e ) use ( $response ) {
			$response['error']      = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			if ( function_exists( 'switch_to_blog' ) ) {
				switch_to_blog( $a2cData['store_id'] );
			}

			foreach ( $a2cData['strings'] as $key => $stringData ) {
				$response['result'][ $key ] = call_user_func('esc_html__', $stringData['id'], $stringData['domain'] );
			}
		} catch ( Exception $e ) {
			return $reportError( $e );
		} catch ( Throwable $e ) {
			return $reportError( $e );
		}

		return $response;
	}

	/**
	 * @inheritDoc
	 * @return array
	 */
	public function getWdrPrice( array $a2cData )
	{
		$response = [
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		];

		$reportError = function ( $e ) use ( $response ) {
			$response['error'] = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			if ( function_exists( 'switch_to_blog' ) ) {
				switch_to_blog( $a2cData['store_id'] );
			}

			$class = new Wdr\App\Controllers\ManageDiscount();

			foreach ( $a2cData['args'] as $args ) {
				$response['result'][$args[0]][$args[1]] = $class::calculateInitialAndDiscountedPrice( $args[0], $args[1] );
			}
		} catch ( Exception $e ) {
			return $reportError($e);
		} catch ( Throwable $e ) {
			return $reportError( $e );
		}

		return $response;
	}

	/**
	 * SetOrderNotes
	 *
	 * @inheritDoc
	 * @return array
	 */
	public function setOrderNotes( array $a2cData ) {
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		);

		$reportError = function ( $e ) use ( $response ) {
			$response['error']      = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			if ( function_exists( 'switch_to_blog' ) ) {
				switch_to_blog( $a2cData['store_id'] );
			}

			$order = WC()->order_factory->get_order( (int) $a2cData['order_id'] );

			if ( ! $order ) {
				$response['error_code'] = self::ERROR_CODE_ENTITY_NOT_FOUND;
				$response['error']      = 'Entity not found';
			} else {
				if ( empty( $a2cData['from'] ) ) {
					/* translators: %s: new order status */
					$transition_note = sprintf( esc_html__( 'Order status set to %s.', 'api2cart-bridge-connector' ), wc_get_order_status_name( $a2cData['to'] ) );

					if ( empty( $a2cData['added_by_user'] ) ) {
						$this->_addOrderNote( $order->get_id(), $transition_note );
					} else {
						$this->_addOrderNote( $order->get_id(), $transition_note, 0, true );
					}
				} else {
					/* translators: 1: old order status 2: new order status */
					$transition_note = sprintf( esc_html__( 'Order status changed from %1$s to %2$s.', 'api2cart-bridge-connector' ),
						wc_get_order_status_name( $a2cData['from'] ),
						wc_get_order_status_name( $a2cData['to'] ) );

					if ( empty( $a2cData['added_by_user'] ) ) {
						$this->_addOrderNote( $order->get_id(), $transition_note );
					} else {
						$this->_addOrderNote( $order->get_id(), $transition_note, 0, true );
					}
				}
			}
		} catch ( Exception $e ) {
			return $reportError( $e );
		} catch ( Throwable $e ) {
			return $reportError( $e );
		}

		return $response;
	}

	/**
	 * GetImagesUrls
	 *
	 * @param array $a2cData
	 *
	 * @return array
	 */
	public function getImagesUrls( array $a2cData ) {
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		);

		$reportError = function ( $e ) use ( $response ) {
			$response['error']      = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			foreach ( $a2cData as $imagesCollection ) {
				if ( function_exists( 'switch_to_blog' ) ) {
					switch_to_blog( $imagesCollection['store_id'] );
				}

				$images = array();
				foreach ( $imagesCollection['ids'] as $id ) {
					$images[ $id ] = wp_get_attachment_url( $id );
				}

				$response['result'][ $imagesCollection['store_id'] ] = array( 'images' => $images );
			}
		} catch ( Exception $e ) {
			return $reportError( $e );
		} catch ( Throwable $e ) {
			return $reportError( $e );
		}

		return $response;
	}

	/**
	 * GetPlugins
	 *
	 * @return array
	 */
	public function getPlugins() {
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		);

		$reportError = function ( $e ) use ( $response ) {
			$response['error']      = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				$response['result']['plugins'] = get_plugins();
			} else {
				$response['result']['plugins'] = get_plugins();
			}
		} catch ( Exception $e ) {
			return $reportError( $e );
		} catch ( Throwable $e ) {
			return $reportError( $e );
		}

		return $response;
	}

	/**
	 * OrderUpdate
	 *
	 * @param array $a2cData Data
	 *
	 * @return array
	 */
	public function orderUpdate( array $a2cData ) {
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		);

		$reportError = function ( $e ) use ( $response ) {
			$response['error']      = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			foreach ( get_defined_vars() as $key => $val ) {
				$GLOBALS[ $key ] = $val;
			}

			$this->_resetGlobalVars();

			if ( function_exists( 'switch_to_blog' ) ) {
				switch_to_blog( $a2cData['order']['store_id'] );
			}

			$entity = WC()->order_factory->get_order( $a2cData['order']['id'] );

			if ( isset( $a2cData['order']['notify_customer'] ) && false === $a2cData['order']['notify_customer'] ) {
				$disableEmails = function () {
					return false;
				};

				add_filter( 'woocommerce_email_enabled_customer_completed_order', $disableEmails, 100, 0 );
				add_filter( 'woocommerce_email_enabled_customer_invoice', $disableEmails, 100, 0 );
				add_filter( 'woocommerce_email_enabled_customer_note', $disableEmails, 100, 0 );
				add_filter( 'woocommerce_email_enabled_customer_on_hold_order', $disableEmails, 100, 0 );
				add_filter( 'woocommerce_email_enabled_customer_processing_order', $disableEmails, 100, 0 );
				add_filter( 'woocommerce_email_enabled_customer_refunded_order', $disableEmails, 100, 0 );
			}

			if ( isset( $a2cData['order']['status']['id'] ) ) {
				$entity->set_status( $a2cData['order']['status']['id'],
					isset( $a2cData['order']['status']['transition_note'] ) ? $a2cData['order']['status']['transition_note'] : '',
					true );
			}

			if ( isset( $a2cData['order']['completed_date'] ) ) {
				$entity->set_date_completed( $a2cData['order']['completed_date'] );
			}

			if ( isset( $a2cData['order']['admin_comment'] ) ) {
				$this->_addOrderNote( $entity->get_id(), $a2cData['order']['admin_comment']['text'], 1 );
			}

			if ( isset( $a2cData['order']['customer_note'] ) ) {
				$entity->set_customer_note( $a2cData['order']['customer_note'] );
			}

			if ( isset( $a2cData['order']['admin_private_comment'] ) ) {
				$this->_addOrderNote( $entity->get_id(), $a2cData['order']['admin_private_comment']['text'], 0, true );
			}

			$entity->save();

			$response['result'] = true;
		} catch ( Exception $e ) {
			return $reportError( $e );
		} catch ( Throwable $e ) {
			return $reportError( $e );
		}

		return $response;
	}

	/**
	 * Category Add
	 *
	 * @param array $a2cData Data
	 *
	 * @return array
	 */
	public function categoryAdd( array $a2cData ) {
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		);

		$reportError = function ( $e ) use ( $response ) {
			$response['error'] = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			$termType = 'product_cat';

			if ( function_exists( 'switch_to_blog' ) ) {
				switch_to_blog( $a2cData['store_id'] );
			}

			$termArgs = [
				'slug'        => $a2cData['meta_data']['slug'],
				'parent'      => $a2cData['meta_data']['parent'],
				'description' => $a2cData['meta_data']['description'],
			];

			//WPML support
			if ( defined( 'ICL_SITEPRESS_VERSION' ) && defined( 'ICL_PLUGIN_INACTIVE' ) && !ICL_PLUGIN_INACTIVE && class_exists( 'SitePress' ) ) {
				if ( isset( $a2cData['meta_data']['icl_tax_product_cat_language'] ) ) {
					do_action( 'wpml_switch_language', $a2cData['meta_data']['icl_tax_product_cat_language'] );
					$currentLangCode = $a2cData['meta_data']['icl_tax_product_cat_language'];
				} else {
					$currentLangCode = apply_filters( 'wpml_default_language', null );
				}

				if ( isset( $a2cData['meta_data']['icl_translation_of'] ) ) {
					$trId = apply_filters( 'wpml_element_trid', null, (int)$a2cData['meta_data']['icl_translation_of'], 'tax_' . $termType );
				} else {
					$trId = null;
				}

				// fix hierarchy.
				if ( $a2cData['meta_data']['parent'] ) {
					$originalParentTranslated = apply_filters( 'translate_object_id', $a2cData['meta_data']['parent'], $termType, false, $currentLangCode );

					if ( $originalParentTranslated ) {
						$termArgs['parent'] = $originalParentTranslated;
					} else {
						$termParent = get_term_by( 'id', (int)$a2cData['meta_data']['parent'], 'product_cat' );

						if ( $termParent ) {
							$trIdParent = apply_filters( 'wpml_element_trid', null, $termParent->term_taxonomy_id, 'tax_' . $termType );
							$parentTranslations = apply_filters( 'wpml_get_element_translations', null, $trIdParent, 'tax_' . $termType, false, true );

							if ( !key_exists( $currentLangCode, $parentTranslations ) ) {
								$termParentArgs = [
									'name' => $termParent->name,
									'slug' => WPML_Terms_Translations::term_unique_slug( $termParent->slug, $termType, $currentLangCode ),
								];

								$newParentTerm = wp_insert_term( $termParent->name, $termType, $termParentArgs );

								if ( $newParentTerm && !is_wp_error( $newParentTerm ) ) {
									$setLanguageArgs = array(
										'element_id'    => $newParentTerm['term_taxonomy_id'],
										'element_type'  => 'tax_' . $termType,
										'trid'          => $trIdParent,
										'language_code' => $currentLangCode,
									);

									do_action( 'wpml_set_element_language_details', $setLanguageArgs );
									$termArgs['parent'] = $newParentTerm['term_id'];
								}
							} else {
								$termArgs['parent'] = $parentTranslations[$currentLangCode]['term_id'];
							}
						}
					}
				}

				$newTerm = wp_insert_term( $a2cData['meta_data']['tag-name'], $termType, $termArgs );

				if ( $newTerm && !is_wp_error( $newTerm ) ) {
					$setLanguageArgs = array(
						'element_id'    => $newTerm['term_taxonomy_id'],
						'element_type'  => 'tax_' . $termType,
						'trid'          => $trId,
						'language_code' => $currentLangCode,
					);

					do_action( 'wpml_set_element_language_details', $setLanguageArgs );
				} else {
					throw new Exception( esc_html__( '[BRIDGE ERROR]: Can\'t create category!', 'api2cart-bridge-connector' ) );
				}
			} else {
				$newTerm = wp_insert_term( $a2cData['meta_data']['tag-name'], $termType, $termArgs );
			}

			return $newTerm;
		} catch ( Exception $e ) {
			return $reportError( $e );
		} catch ( Throwable $e ) {
			return $reportError( $e );
		}
	}

	/**
	 * @param array $a2cData A2C Data
	 *
	 * @return array
	 */
	public function categoryAddBatch( array $a2cData ) {
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => [],
		);

		$reportError = function ( $e ) {
			return [
				'message'    => $e->getMessage(),
				'error_code' => $e->getCode(),
			];
		};

		if ( function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $a2cData['store_id'] );
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) && defined( 'ICL_PLUGIN_INACTIVE' ) && !ICL_PLUGIN_INACTIVE && class_exists( 'SitePress' ) ) {
			$this->_wpmlEnabled = true;

			$sitepress = WPML\Container\make( '\SitePress' );
			$activeLanguages = $sitepress->get_active_languages( true );
		} else {
			$activeLanguages = [];
		}

		foreach ( $a2cData['data'] as $key => $item ) {
			$response['result'][$key]['id'] = null;

			try {
				$term     = null;
				$taxonomy = 'product_cat';
				$name     = $item['name'];
				$args     = [];

				if ( isset( $item['description'] ) ) {
					$args['description'] = $item['description'];
				}

				if ( isset( $item['slug'] ) ) {
					$args['slug'] = $item['slug'];
				}

				if ( isset( $item['parent'] ) ) {
					$args['parent'] = $item['parent'];
				}

				$term = wp_insert_term( $name, $taxonomy, $args );

				if ( is_wp_error( $term ) ) {
					throw new Exception( esc_html__( 'Can\'t create category! Error: ' . $term->get_error_message(), 'bridge-connector' ) );
				}

				$term = get_term( $term['term_id'], $taxonomy );
				$termId = (int) $term->term_id;
				$response['result'][$key]['id'] = $termId;

				if ( isset( $item['menu_order'] ) ) {
					update_term_meta( $termId, 'order', $item['menu_order'] );
				}

				if ( $this->_wpmlEnabled ) {
					$this->_translateTaxonomy( [$termId], $taxonomy, $activeLanguages, true );
				}

				if ( isset( $item['image'] ) ) {
					$upload = wc_rest_upload_image_from_url( esc_url_raw( $item['image']['src'] ) );

					if ( is_wp_error( $upload ) ) {
						if ( !apply_filters( 'woocommerce_rest_suppress_image_upload_error', false, $upload, $termId, [$item['image']] ) ) {
							throw new WC_Data_Exception( 'woocommerce_product_image_upload_error', $upload->get_error_message() );
						} else {
							continue;
						}
					}

					$attachmentId = wc_rest_set_uploaded_image_as_attachment( $upload, $termId );

					if ( $attachmentId && wp_attachment_is_image( $attachmentId ) ) {
						if ( $this->_wpmlEnabled ) {
							foreach ($activeLanguages as $activeLanguage) {
								do_action( 'wpml_switch_language', $activeLanguage['code'] );
								$trId = apply_filters( 'translate_object_id', $termId, $taxonomy, false, $activeLanguage['code'] );

								if ( !is_null( $trId ) ) {
									update_term_meta( $trId, 'thumbnail_id', $attachmentId );
								}
							}
						} else {
							update_term_meta( $termId, 'thumbnail_id', $attachmentId );
						}

						if ( ! empty( $item['image']['alt'] ) ) {
							update_post_meta( $attachmentId, '_wp_attachment_image_alt', wc_clean( $item['image']['alt'] ) );
						}

						if ( ! empty( $item['image']['name'] ) ) {
							wp_update_post(
								array(
									'ID'         => $attachmentId,
									'post_title' => wc_clean( $item['image']['name'] ),
								)
							);
						}
					} else {
						delete_term_meta( $termId, 'thumbnail_id' );
					}
				}
			} catch ( Exception $e ) {
				$response['result'][$key]['errors'][] = $reportError( $e );
			} catch ( Throwable $e ) {
				$response['result'][$key]['errors'][] = $reportError( $e );
			}
		}

		return $response;
	}

	/**
	 * Category Update
	 *
	 * @param array $a2cData Data
	 *
	 * @return array
	 */
	public function categoryUpdate(array $a2cData) {
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		);

		$reportError = function ($e) use ($response) {
			$response['error'] = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			$args = array_merge(
				$a2cData,
				array(
					'action'   => 'editedtag',
					'taxonomy' => 'product_cat',
				)
			);

			if (isset($args['icl_tax_product_cat_language'])) {
				$sitepress = WPML\Container\make( '\SitePress' );
				$sitepress->switch_lang($args['icl_tax_product_cat_language']);
			}

			wp_update_term($args['tag_ID'], 'product_cat', $args);
			$response['result'] = true;
		} catch (Exception $e) {
			return $reportError($e);
		} catch (Throwable $e) {
			return $reportError($e);
		}

		return $response;
	}

	/**
	 * Category Delete
	 *
	 * @param array $a2cData Data
	 *
	 * @return array
	 */
	public function categoryDelete(array $a2cData) {
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		);

		$reportError = function ($e) use ($response) {
			$response['error'] = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			if (isset($a2cData['icl_tax_product_cat_language'])) {
				$sitepress = WPML\Container\make( '\SitePress' );
				$sitepress->switch_lang($a2cData['icl_tax_product_cat_language']);
			}

			wp_delete_term( $a2cData['entity_id'], 'product_cat' );
			$response['result'] = true;
		} catch (Exception $e) {
			return $reportError($e);
		} catch (Throwable $e) {
			return $reportError($e);
		}

		return $response;
	}

	/**
	 * Send Return Emails
	 *
	 * @param array $a2cData Data
	 *
	 * @return array
	 */
	public function sendReturnEmails(array $a2cData)
	{
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error' => null,
			'result' => array()
		);

		$reportError = function ($e) use ($response) {
			$response['error'] = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			if (function_exists('switch_to_blog')) {
				switch_to_blog($a2cData['store_id']);
			}

			if ($a2cData['plugin'] === 'woocommerce-refund-and-exchange-lite') {
				if ($a2cData['is_comment']) {
					$customer_email = WC()->mailer()->emails['wps_rma_order_messages_email'];
					$customer_email->trigger($a2cData['data']['msg'], [], $a2cData['data']['to'], $a2cData['order_id']);
				} else {
					if (!$a2cData['is_update_method'] || $a2cData['return_status'] === 'pending') {
						do_action('wps_rma_refund_req_email', $a2cData['order_id']);
					}

					if ($a2cData['return_status'] === 'complete') {
						do_action('wps_rma_refund_req_accept_email', $a2cData['order_id']);
					} elseif ($a2cData['return_status'] === 'cancel') {
						do_action('wps_rma_refund_req_cancel_email', $a2cData['order_id']);
					}
				}
			}
		} catch (Exception $e) {
			return $reportError($e);
		} catch (Throwable $e) {
			return $reportError($e);
		}

		return $response;
	}

	/**
	 * Image Add
	 *
	 * @param array $a2cData Data
	 *
	 * @return array
	 */
	public function imageAdd( array $a2cData )
	{
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		);

		$reportError = function ( $e ) use ( $response ) {
			$response[ 'error' ]      = $e->getMessage();
			$response[ 'error_code' ] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		$allowedMimes = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'gif'          => 'image/gif',
			'png'          => 'image/png',
			'bmp'          => 'image/bmp',
			'tiff|tif'     => 'image/tiff',
			'ico'          => 'image/x-icon',
			'webp'         => 'image/webp',
		);

		try {
			require_once( ABSPATH . 'wp-admin/includes/image.php' );
			$productIds = $a2cData[ 'product_ids' ];
			$productId  = $a2cData[ 'product_ids' ][ 0 ];
			$variantIds = $a2cData[ 'variant_ids' ];

			$alt = '';

			if ( !empty( $a2cData[ 'alt' ] ) ) {
				$alt = $a2cData[ 'alt' ];
			}

			if ( function_exists( 'switch_to_blog' ) ) {
				switch_to_blog( $a2cData[ 'store_id' ] );
			}

			if ( $a2cData['content'] ) {
				$img      = str_replace( 'data:image/jpeg;base64,', '', $a2cData[ 'content' ] );
				$img      = str_replace( ' ', '+', $img );
				$decoded  = base64_decode( $img );
				$filename = $a2cData[ 'name' ];

				$file = wp_upload_bits( $filename, null, $decoded );
				if ( $file[ 'error' ] !== false ) {
					/* translators: %s: File name */
					throw new Exception( sprintf( esc_html__( '[BRIDGE ERROR]: File save failed %s!', 'api2cart-bridge-connector' ), $filename ) );
				}

			} elseif ( $a2cData['source'] ) {
				$imageUrl  = $a2cData[ 'source' ];
				$filename = $a2cData[ 'name' ];
				$parsedUrl = wp_parse_url( $imageUrl );

				if ( !$parsedUrl || !is_array( $parsedUrl ) ) {
					/* translators: %s: Image Url */
					throw new Exception( sprintf( esc_html__( '[BRIDGE ERROR]: Invalid URL %s!', 'api2cart-bridge-connector' ), $parsedUrl ) );
				}

				$imageUrl = esc_url_raw( $imageUrl );

				if ( !function_exists( 'download_url' ) ) {
					include_once ABSPATH . 'wp-admin/includes/file.php';
				}

				$fileArray               = array();
				$fileArray[ 'name' ]     = basename( $filename );
				$fileArray[ 'tmp_name' ] = download_url( $imageUrl );

				if ( is_wp_error( $fileArray[ 'tmp_name' ] ) ) {
					throw new Exception(
					/* translators: %s: Image Url */
						sprintf( esc_html__( '[BRIDGE ERROR]: Some error occurred while retrieving the remote image by URL: %s!', 'api2cart-bridge-connector' ), $imageUrl ) . ' ' . sprintf(
						/* translators: %s: Error message */
							esc_html__( 'Error: %s', 'api2cart-bridge-connector' ),
							$fileArray[ 'tmp_name' ]->get_error_message()
						)
					);
				}

				$file = wp_handle_sideload(
					$fileArray,
					array(
						'test_form' => false,
						'mimes'     => $allowedMimes,
					),
					current_time( 'Y/m' )
				);

				if ( isset( $file[ 'error' ] ) ) {
					wp_delete_file( $fileArray[ 'tmp_name' ] );

					throw new Exception( 'IMAGE NOT SUPPORTED', $imageUrl );
				}

				do_action( 'woocommerce_rest_api_uploaded_image_from_url', $file, $imageUrl );
			}

			if ( empty( $file['file'] ) ) {
				throw new Exception( esc_html__( '[BRIDGE ERROR]: No image has been uploaded!', 'api2cart-bridge-connector' ) );
			}

			if ( defined( 'ICL_SITEPRESS_VERSION' ) && defined( 'ICL_PLUGIN_INACTIVE' ) && !ICL_PLUGIN_INACTIVE && class_exists( 'SitePress' ) ) {
				global $sitepress;

				$sitepress       = WPML\Container\make( '\SitePress' );
				$currentLanguage = apply_filters( 'wpml_current_language', null );

				foreach ( $productIds as $productId ) {
					$objectId = apply_filters( 'wpml_object_id', $productId, 'post_product', true, $currentLanguage );

					if ( $objectId === null ) {
						continue;
					}

					$trid         = apply_filters( 'wpml_element_trid', null, $objectId, 'post_product' );
					$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_product', false, true );

					foreach ( $translations as $translation ) {
						if ( is_object( $translation ) ) {
							$productIds[] = $translation->{'element_id'};
						} elseif ( is_array( $translation ) ) {
							$productIds[] = $translation[ 'element_id' ];
						}
					}
				}

				foreach ( $variantIds as $variantId ) {
					$objectId = apply_filters( 'wpml_object_id', $variantId, 'post_product_variation', true, $currentLanguage );

					if ( $objectId === null ) {
						continue;
					}

					$trid         = apply_filters( 'wpml_element_trid', null, $objectId, 'post_product_variation' );
					$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_product_variation', false, true );

					foreach ( $translations as $translation ) {
						if ( is_object( $translation ) ) {
							$variantIds[] = $translation->{'element_id'};
						} elseif ( is_array( $translation ) ) {
							$variantIds[] = $translation[ 'element_id' ];
						}
					}
				}

				$productIds = array_unique( $productIds );
				$variantIds = array_unique( $variantIds );
			}

			$attachmentId = wp_insert_attachment(
				[
					'guid'           => wp_upload_dir()['url'] . '/' . basename( $file['file'] ),
					'post_mime_type' => $file[ 'type' ],
					'post_title'     => basename( $file[ 'file' ] ),
					'post_content'   => '',
					'post_status'    => 'inherit',
				],
				$file[ 'file' ],
				$productId
			);

			if ( !empty( $alt ) ) {
				update_post_meta( $attachmentId, '_wp_attachment_image_alt', wc_clean( $alt ) );
			}

			$attachmentData = wp_generate_attachment_metadata( $attachmentId, $file[ 'file' ] );

			wp_update_attachment_metadata( $attachmentId, $attachmentData );

			foreach ( $productIds as $productId ) {
				if ( $a2cData[ 'is_thumbnail' ] ) {
					set_post_thumbnail( $productId, $attachmentId );
				}

				if ( $a2cData[ 'is_gallery' ] ) {
					$WCProduct = WC()->product_factory->get_product( $productId );

					if ( $WCProduct->get_type() !== 'variation' ) {
						$galleryIds   = $WCProduct->get_gallery_image_ids();
						$galleryIds[] = $attachmentId;
						$WCProduct->set_gallery_image_ids( array_unique( $galleryIds ) );
						$WCProduct->save();
					}
				}
			}

			foreach ( $variantIds as $variantId ) {
				if ( $a2cData[ 'is_thumbnail' ] ) {
					set_post_thumbnail( $variantId, $attachmentId );
				}

				if ( $a2cData[ 'is_gallery' ] ) {
					$images = get_post_meta( $variantId, 'woo_variation_gallery_images', true );//https://wordpress.org/plugins/woo-variation-gallery support

					if ( empty( $images ) ) {
						$variationGallery = [ $attachmentId ];
					} else {
						$variationGallery = array_unique( array_merge( [ $attachmentId ], $images ) );
					}

					update_post_meta( $variantId, 'woo_variation_gallery_images', $variationGallery );
				}
			}

			$response[ 'result' ][ 'image_id' ] = $attachmentId;
			$response[ 'result' ][ 'src' ]      = str_replace( home_url(), '', wp_get_attachment_url( $attachmentId ) );

		} catch ( Exception $e ) {
			return $reportError( $e );
		} catch ( Throwable $e ) {
			return $reportError( $e );
		}

		return $response;
	}

	/**
	 * @return array
	 */
	public function productAddAction( array $a2cData ) {
		$productId = 0;

		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		);

		$reportError = function ( $e ) use ( $response ) {
			$response['error']      = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			$response['result']['product_id'] = $this->_importProduct( $a2cData );
		} catch ( Exception $e ) {
			$this->_cleanGarbage( $productId );

			return $reportError( $e );
		} catch ( Throwable $e ) {
			$this->_cleanGarbage( $productId );

			return $reportError( $e );
		}

		return $response;
	}

	/**
	 * @param array $a2cData Data
	 *
	 * @return array
	 */
	public function productDeleteAction( array $a2cData )
	{
		global $wpdb;

		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error' => null,
			'result' => []
		);

		$reportError = function ( $e ) {
			return [
				'message' => $e->getMessage(),
				'error_code' => $e->getCode()
			];
		};

		if ( function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $a2cData['store_id'] );
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) && defined( 'ICL_PLUGIN_INACTIVE' ) && !ICL_PLUGIN_INACTIVE && class_exists( 'SitePress' ) ) {
			$this->_wpmlEnabled = true;
		}

		if ( !is_array( $a2cData['data'] ) ) {
			$a2cData['data'] = ['id' => $a2cData['data']];
		}

		foreach ( $a2cData['data'] as $key => $item ) {
			try {
				$product = null;
				$productId      = (int) $item['id'];
				$response['result'][$key]['id'] = $productId;

				//WPML support
				if ($this->_wpmlEnabled) {
					$trIdProduct         = apply_filters( 'wpml_element_trid', null, $productId, 'post_product' );
					$productTranslations = apply_filters( 'wpml_get_element_translations', null, $trIdProduct, 'post_product', false, true );

					foreach ( $productTranslations as $translation ) {
						do_action( 'wpml_switch_language', $translation->language_code );
						$product = wc_get_product( $translation->element_id );

						if ( $product && $product->is_type( 'variable' ) ) {
							foreach ( $product->get_children() as $childId ) {
								$child = wc_get_product( $childId );

								if ( ! empty( $child ) ) {
									$trIdChild = apply_filters( 'wpml_element_trid', null, $child->get_id(), 'post_product_variation' );
									$child->delete( true );

									$wpdb->query(
										$wpdb->prepare("
											DELETE
											FROM {$wpdb->prefix}icl_translations
											WHERE trid=%d",
											$trIdChild
										)
									);
								}
							}
						}

						if ($product) {
							$parentId = $product->get_parent_id();
							$product->delete( true );

							if ( 0 !== $parentId ) {
								wc_delete_product_transients( $parentId );
							}
						}
					}

					$wpdb->query(
						$wpdb->prepare("
							DELETE
							FROM {$wpdb->prefix}icl_translations
							WHERE trid=%d",
							$trIdProduct
						)
					);
				} else {
					$product = wc_get_product( $productId );

					if ( $product && $product->is_type( 'variable' ) ) {
						foreach ( $product->get_children() as $childId ) {
							$child = wc_get_product( $childId );

							if ( ! empty( $child ) ) {
								$child->delete( true );
							}
						}
					}

					if ($product) {
						$parentId = $product->get_parent_id();
						$product->delete( true );

						if ( 0 !== $parentId ) {
							wc_delete_product_transients( $parentId );
						}
					}
				}

			} catch ( Exception $e ) {
				$response['result'][$key]['errors'][] = $reportError( $e );
			} catch ( Throwable $e ) {
				$response['result'][$key]['errors'][] = $reportError( $e );
			}
		}

		return $response;
	}

	/**
	 * @param array $a2cData A2C Data
	 *
	 * @return array
	 */
	public function productAddBatchAction( array $a2cData ) {
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => [],
		);

		$reportError = function ( $e ) {
			return [
				'message'    => $e->getMessage(),
				'error_code' => $e->getCode(),
			];
		};

		if ( function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $a2cData['store_id'] );
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) && defined( 'ICL_PLUGIN_INACTIVE' ) && !ICL_PLUGIN_INACTIVE && class_exists( 'SitePress' ) ) {
			$this->_wpmlEnabled = true;
		}

		foreach ( $a2cData['data'] as $key => $item ) {
			$response['result'][$key]['id'] = null;

			try {
				$product = null;
				$product = $this->_importProductBatch( $item );
				$productId = $product->get_id();
				$response['result'][$key]['id'] = $productId;

				//WPML support
				if ( $this->_wpmlEnabled ) {
					$trIdProduct         = apply_filters( 'wpml_element_trid', null, $productId, 'post_product' );
					$productTranslations = apply_filters( 'wpml_get_element_translations', null, $trIdProduct, 'post_product', false, true );

					foreach ( $productTranslations as $translation ) {
						wc_delete_product_transients( $translation->element_id );
						wp_cache_delete( 'product-' . $translation->element_id, 'products' );
					}
				} else {
					wc_delete_product_transients( $productId );
					wp_cache_delete( 'product-' . $productId, 'products' );
				}

			} catch ( Exception $e ) {
				if ( isset($product) && $product instanceof WC_Data) {
					$this->_cleanGarbage( $product->get_id() );
				}

				$response['result'][$key]['errors'][] = $reportError( $e );
			} catch ( Throwable $e ) {
				if ( isset($product) && $product instanceof WC_Data) {
					$this->_cleanGarbage( $product->get_id() );
				}

				$response['result'][$key]['errors'][] = $reportError( $e );
			}
		}

		return $response;
	}

	/**
	 * @return array
	 */
	public function productUpdateAction( array $a2cData ) {
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => array(),
		);

		$reportError = function ( $e ) use ( $response ) {
			$response['error']      = $e->getMessage();
			$response['error_code'] = self::ERROR_CODE_INTERNAL_ERROR;

			return $response;
		};

		try {
			$response['result']['product_id'] = $this->_importProduct( $a2cData, (int)$a2cData['product_data']['id'] );
		} catch ( Exception $e ) {
			return $reportError( $e );
		} catch ( Throwable $e ) {
			return $reportError( $e );
		}

		return $response;
	}

	/**
	 * @param array $a2cData A2C Data
	 *
	 * @return array
	 */
	public function productUpdateBatchAction( array $a2cData ) {
		$response = array(
			'error_code' => self::ERROR_CODE_SUCCESS,
			'error'      => null,
			'result'     => [],
		);

		$reportError = function ( $e ) {
			return [
				'message'    => $e->getMessage(),
				'error_code' => $e->getCode(),
			];
		};

		if ( function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $a2cData['store_id'] );
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) && defined( 'ICL_PLUGIN_INACTIVE' ) && !ICL_PLUGIN_INACTIVE && class_exists( 'SitePress' ) ) {
			$this->_wpmlEnabled = true;
		}

		foreach ( $a2cData['data'] as $key => $item ) {
			$response['result'][$key]['id'] = null;

			try {
				$product = null;
				$product = $this->_importProductBatch( $item );
				$productId = $product->get_id();
				$response['result'][$key]['id'] = $productId;

				//WPML support
				if ( $this->_wpmlEnabled ) {
					$trIdProduct         = apply_filters( 'wpml_element_trid', null, $productId, 'post_product' );
					$productTranslations = apply_filters( 'wpml_get_element_translations', null, $trIdProduct, 'post_product', false, true );

					foreach ( $productTranslations as $translation ) {
						wc_delete_product_transients( $translation->element_id );
						wp_cache_delete( 'product-' . $translation->element_id, 'products' );
					}
				} else {
					wc_delete_product_transients( $productId );
					wp_cache_delete( 'product-' . $productId, 'products' );
				}

			} catch ( Exception $e ) {
				$response['result'][$key]['errors'][] = $reportError( $e );
			} catch ( Throwable $e ) {
				$response['result'][$key]['errors'][] = $reportError( $e );
			}
		}

		return $response;
	}

	/**
	 * @param array $a2cData Data
	 * @param int   $id      ID
	 *
	 * @return int
	 * @throws Exception
	 */
	protected function _importProduct( array $a2cData, int $id = 0 ) {
		if ( function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $a2cData['store_id'] );
		}

		if ( isset( $a2cData['product_data']['internal_data']['wpml_current_lang_id'] ) ) {
			do_action( 'wpml_switch_language', $a2cData['product_data']['internal_data']['wpml_current_lang_id'] );
		}

		$className = 'WC_Product_' . implode( '_', array_map( 'ucfirst', explode( '-', $a2cData['product_data']['type'] ) ) );

		if ( !class_exists( $className ) ) {
			/* translators: %s: Class name */
			throw new Exception( sprintf( esc_html__( '[BRIDGE ERROR]: Class %s not exist!', 'api2cart-bridge-connector' ), $className ) );
		}

		/**
		 * @var $product WC_Product
		 */
		$product = new $className( $id );

		foreach ( $a2cData['product_data']['data'] as $productProperty => $productData ) {
			if ( method_exists( $product, 'set_' . $productProperty ) ) {
				call_user_func_array( array($product, 'set_' . $productProperty), [$productData] );
			}
		}

		$changeSet   = $product->get_changes();
		$metaChanges = [];

		$metaChangesKeys = array_diff(
			array_keys( $changeSet ),
			[
				'description',
				'short_description',
				'name',
				'parent_id',
				'reviews_allowed',
				'status',
				'menu_order',
				'date_created',
				'date_modified',
				'slug',
				'post_password',
			]
		);

		foreach ( $metaChangesKeys as $key ) {
			$metaChanges[ $key ] = $changeSet[ $key ];
		}

		if ( isset( $a2cData['product_data']['meta_data'] ) ) {
			$metaChanges += $a2cData['product_data']['meta_data'];
		}

		if (isset($changeSet['description'])) {
			remove_filter('content_save_pre', 'wp_filter_post_kses');
		}

		$product->save();

		$productId = $product->get_id();

		if ( isset( $a2cData['product_data']['meta_data'] ) ) {
			foreach ( $a2cData['product_data']['meta_data'] as $metaKey => $metaData ) {
				if ( $metaKey === '_product_attributes' ) {
					$this->_readAttributes( $product, maybe_unserialize( $metaData ) );
				} else {
					if ( is_null( $metaData ) ) {
						delete_post_meta( $productId, $metaKey );
					} else {
						update_post_meta( $productId, $metaKey, $metaData );
					}
				}
			}
		}

		if ( isset( $a2cData['product_data']['terms_data'] ) ) {
			foreach ( $a2cData['product_data']['terms_data'] as $termName => $terms ) {
				$termIds = array_unique( array_column( $terms, 'name' ) );
				$append  = false;

				if ( isset( $terms[0]['append'] ) ) {
					$append = (bool)$terms[0]['append'];
				}

				if ( !$append ) {
					wp_delete_object_term_relationships( $productId, $termName );
					wp_set_post_terms( $productId, $termIds, $termName );
				} else {
					wp_set_post_terms( $productId, $termIds, $termName, true );
				}
			}
		}

		$this->_wpmlSync( $product, $a2cData, $metaChanges );

		return $productId;
	}

	/**
	 * @param array $data Data
	 *
	 * @return WC_Data|WP_Error
	 * @throws WC_Data_Exception
	 */
	protected function _importProductBatch( array $data ) {
		$id = isset( $data['product_data']['id'] ) ? absint( $data['product_data']['id'] ) : 0;

		if ( isset( $data['product_data']['type'] ) ) {
			$className = 'WC_Product_' . implode( '_', array_map( 'ucfirst', explode( '-', $data['product_data']['type'] ) ) );

			if ( !class_exists( $className ) ) {
				$className = 'WC_Product_Simple';
			}

			$product = new $className( $id );
		} elseif ( isset( $data['product_data']['id'] ) ) {
			$product = wc_get_product( $id );
		} else {
			$product = new WC_Product_Simple();
		}

		if ( isset( $data['product_data']['internal_data']['wpml_current_lang_id'] ) && $this->_wpmlEnabled ) {
			do_action( 'wpml_switch_language', $data['product_data']['internal_data']['wpml_current_lang_id'] );
		}

		if ( isset( $data['product_data']['meta_data']['_sku'] ) ) {
			$product->set_sku( wc_clean( $data['product_data']['meta_data']['_sku'] ) );
		}

		if ( isset( $data['product_data']['data']['name'] ) ) {
			$product->set_name( wp_filter_post_kses( $data['product_data']['data']['name'] ) );
		}

		if ( isset( $data['product_data']['data']['description'] ) ) {
			$product->set_description( wp_filter_post_kses( $data['product_data']['data']['description'] ) );
		}

		if ( isset( $data['product_data']['data']['short_description'] ) ) {
			$product->set_short_description( wp_filter_post_kses( $data['product_data']['data']['short_description'] ) );
		}

		if ( isset( $data['product_data']['data']['status'] ) ) {
			$product->set_status( get_post_status_object( $data['product_data']['data']['status'] ) ? $data['product_data']['data']['status'] : 'draft' );
		}

		if ( isset( $data['product_data']['data']['slug'] ) ) {
			$product->set_slug( $data['product_data']['data']['slug'] );
		}

		if ( isset( $data['product_data']['data']['virtual'] ) ) {
			$product->set_virtual( $data['product_data']['data']['virtual'] );
		}

		if ( isset( $data['product_data']['data']['downloadable'] ) ) {
			$product->set_downloadable( $data['product_data']['data']['downloadable'] );
		}

		if ( isset( $data['product_data']['data']['tax_class'] ) ) {
			$product->set_tax_class( $data['product_data']['data']['tax_class'] );
		}

		if ( isset( $data['product_data']['data']['catalog_visibility'] ) ) {
			$product->set_catalog_visibility( $data['product_data']['data']['catalog_visibility'] );
		}

		if ( isset( $data['product_data']['data']['virtual'] ) && true === $data['product_data']['data']['virtual'] ) {
			$product->set_weight( '' );
			$product->set_height( '' );
			$product->set_length( '' );
			$product->set_width( '' );
		} else {
			if ( isset( $data['product_data']['data']['weight'] ) ) {
				$product->set_weight( $data['product_data']['data']['weight'] );
			}

			if ( isset( $data['product_data']['data']['height'] ) ) {
				$product->set_height( $data['product_data']['data']['height'] );
			}

			if ( isset( $data['product_data']['data']['width'] ) ) {
				$product->set_width( $data['product_data']['data']['width'] );
			}

			if ( isset( $data['product_data']['data']['length'] ) ) {
				$product->set_length( $data['product_data']['data']['length'] );
			}
		}

		if ( in_array( $product->get_type(), ['variable', 'grouped'], true ) ) {
			$product->set_regular_price( '' );
			$product->set_sale_price( '' );
			$product->set_date_on_sale_to( '' );
			$product->set_date_on_sale_from( '' );
			$product->set_price( '' );
		} else {
			if ( isset( $data['product_data']['data']['regular_price'] ) ) {
				$product->set_regular_price( $data['product_data']['data']['regular_price'] );
			}

			if ( isset( $data['product_data']['data']['sale_price'] ) ) {
				$product->set_sale_price( $data['product_data']['data']['sale_price'] );
			}

			if ( isset( $data['product_data']['data']['date_on_sale_from'] ) ) {
				$product->set_date_on_sale_from( $data['product_data']['data']['date_on_sale_from'] );
			}

			if ( isset( $data['product_data']['data']['date_on_sale_to'] ) ) {
				$product->set_date_on_sale_to( $data['product_data']['data']['date_on_sale_to'] );
			}
		}

		if ( isset( $data['product_data']['data']['parent_id'] ) ) {
			$product->set_parent_id( $data['product_data']['data']['parent_id'] );
		}

		if ( isset( $data['product_data']['data']['in_stock'] ) ) {
			$stock_status = true === $data['product_data']['data']['in_stock'] ? 'instock' : 'outofstock';
		} else {
			$stock_status = $product->get_stock_status();
		}

		if ( 'yes' === get_option( 'woocommerce_manage_stock' ) ) {
			if ( isset( $data['product_data']['data']['manage_stock'] ) ) {
				$product->set_manage_stock( $data['product_data']['data']['manage_stock'] );
			}

			if ( isset( $data['product_data']['data']['backorders'] ) ) {
				$product->set_backorders( $data['product_data']['data']['backorders'] );
			}

			if ( $product->get_manage_stock() ) {
				if ( !$product->is_type( 'variable' ) ) {
					$product->set_stock_status( $stock_status );
				}

				if ( isset( $data['product_data']['data']['stock_quantity'] ) ) {
					$product->set_stock_quantity( wc_stock_amount( $data['product_data']['data']['stock_quantity'] ) );
				}
			} else {
				$product->set_manage_stock( 'no' );
				$product->set_stock_quantity( '' );
				$product->set_stock_status( $stock_status );
			}
		} elseif ( !$product->is_type( 'variable' ) ) {
			$product->set_stock_status( $stock_status );
		}

		if ( isset( $data['product_data']['data']['attributes'] )) {
			$this->_setAttributes($product, $data);
		}

		$changeSet   = $product->get_changes();
		$metaChanges = [];

		$metaChangesKeys = array_diff(
			array_keys( $changeSet ),
			[
				'description',
				'short_description',
				'name',
				'parent_id',
				'reviews_allowed',
				'status',
				'menu_order',
				'date_created',
				'date_modified',
				'slug',
				'post_password',
			]
		);

		foreach ( $metaChangesKeys as $key ) {
			$metaChanges[$key] = $changeSet[$key];
		}

		if ( isset( $data['product_data']['meta_data'] ) ) {
			$metaChanges += $data['product_data']['meta_data'];
		}

		if ( isset( $data['product_data']['meta_data']['_product_attributes'] ) ) {
			$this->_readAttributes( $product, maybe_unserialize( $data['product_data']['meta_data']['_product_attributes'] ) );
		}

		if ( array_key_exists( '_upsell_ids', $data['product_data']['meta_data'] ) ) {
			$upsells = array();
			$ids     = $data['product_data']['meta_data']['_upsell_ids'];

			if ( !empty( $ids ) ) {
				foreach ( $ids as $id ) {
					if ( $id && $id > 0 ) {
						$upsells[] = $id;
					}
				}
			}

			$product->set_upsell_ids( $upsells );
		}

		if ( array_key_exists( '_crosssell_ids', $data['product_data']['meta_data'] ) ) {
			$crosssells = array();
			$ids        = $data['product_data']['meta_data']['_crosssell_ids'];

			if ( !empty( $ids ) ) {
				foreach ( $ids as $id ) {
					if ( $id && $id > 0 ) {
						$crosssells[] = $id;
					}
				}
			}

			$product->set_cross_sell_ids( $crosssells );
		}

		if ( isset( $data['product_data']['meta_data'] ) ) {
			foreach ( $data['product_data']['meta_data'] as $metaKey => $metaData ) {
				if ( in_array( $metaKey, ['_product_attributes', '_upsell_ids', '_crosssell_ids', '_sku'] ) ) {
					continue;
				} else {
					if ( is_null( $metaData ) ) {
						$product->delete_meta_data( $metaKey );
					} else {
						$product->update_meta_data( $metaKey, $metaData );
					}
				}
			}
		}

		if ( isset( $data['product_data']['images'] ) ) {
			$product = $this->_setProductImages( $product, $data['product_data']['images'] );
		}

		$product->save();

		$productId = $product->get_id();

		if ( isset( $data['product_data']['terms_data'] ) ) {
			foreach ( $data['product_data']['terms_data'] as $termName => $terms ) {
				$termIds = array_unique( array_column( $terms, 'name' ) );
				$append  = false;

				if ( isset( $terms[0]['append'] ) ) {
					$append = (bool)$terms[0]['append'];
				}

				if ( !$append ) {
					wp_delete_object_term_relationships( $productId, $termName );
					wp_set_post_terms( $productId, $termIds, $termName );
				} else {
					wp_set_post_terms( $productId, $termIds, $termName, true );
				}
			}
		}

		if ( isset( $data['product_data']['internal_data']['no_wpml_sync'] ) && $data['product_data']['internal_data']['no_wpml_sync'] ) {
			return $product;
		}

		$this->_wpmlSync( $product, $data, $metaChanges );

		return $product;
	}

	/**
	 * Set product images.
	 *
	 * @param WC_Product $product Product instance.
	 * @param array      $images  Images data.
	 *
	 * @return WC_Product
	 * @throws WC_Data_Exception
	 */
	protected function _setProductImages( WC_Product $product, array $images ) {
		$productId = $product->get_id();

		if ( $product->get_type() === 'variation' ) {
			$productType = 'post_product_variation';
		} else {
			$productType = 'post_product';
		}

		$images = is_array( $images ) ? array_filter( $images ) : [];

		if ( !empty( $images ) ) {
			$galleryPositions = [];

			foreach ( $images as $pos => $image ) {
				$attachment_id = isset( $image['id'] ) ? absint( $image['id'] ) : 0;

				if ( 0 === $attachment_id && isset( $image['src'] ) ) {
					$upload = wc_rest_upload_image_from_url( esc_url_raw( $image['src'] ) );

					if ( is_wp_error( $upload ) ) {
						if ( !apply_filters( 'woocommerce_rest_suppress_image_upload_error', false, $upload, $productId, $images ) ) {
							throw new WC_Data_Exception( 'woocommerce_product_image_upload_error', $upload->get_error_message() );
						} else {
							continue;
						}
					}

					$attachment_id = wc_rest_set_uploaded_image_as_attachment( $upload, $productId );
				}

				if ( !wp_attachment_is_image( $attachment_id ) ) {
					/* translators: %s: attachment id */
					throw new WC_Data_Exception(
						'woocommerce_product_invalid_image_id', sprintf( __( '#%s is an invalid image ID.', 'bridge-connector' ), $attachment_id )
					);
				}

				$galleryPositions[$attachment_id] = absint( isset( $image['position'] ) ? $image['position'] : $pos );

				if ( !empty( $image['alt'] ) ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', wc_clean( $image['alt'] ) );
				}

				if ( !empty( $image['name'] ) ) {
					wp_update_post(
						array(
							'ID'         => $attachment_id,
							'post_title' => $image['name'],
						)
					);
				}

				if ( !empty( $image['src'] ) ) {
					update_post_meta( $attachment_id, '_wc_attachment_source', esc_url_raw( $image['src'] ) );
				}
			}

			asort( $galleryPositions );

			$gallery = array_keys( $galleryPositions );

			// Featured image is in position 0.
			$imageId = array_shift( $gallery );

			if ( $this->_wpmlEnabled ) {
				$currentLanguage = apply_filters( 'wpml_current_language', null );

				$objectId = apply_filters( 'wpml_object_id', $productId, $productType, true, $currentLanguage );

				if ( $objectId !== null ) {
					$trid         = apply_filters( 'wpml_element_trid', null, $objectId, 'post_product' );
					$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_product', false, true );

					foreach ( $translations as $translation ) {
						$trProduct = WC()->product_factory->get_product( $translation->{'element_id'} );

						if ( $trProduct ) {
							$trProduct->set_image_id( $imageId );
							$trProduct->set_gallery_image_ids( $gallery );

							if ( class_exists( 'Woo_Variation_Gallery' ) && $gallery && $trProduct->get_type() === 'post_product_variation' ) {
								$trProduct->add_meta_data( 'woo_variation_gallery_images', $gallery, true );
							}
						}
					}
				}
			} else {
				$product->set_image_id( $imageId );
				$product->set_gallery_image_ids( $gallery );

				if ( class_exists( 'Woo_Variation_Gallery' ) && $gallery && $productType === 'post_product_variation' ) {
					$product->add_meta_data( 'woo_variation_gallery_images', $gallery, true );
				}
			}

			$product->set_image_id( $imageId );
			$product->set_gallery_image_ids( $gallery );

			if ( class_exists( 'Woo_Variation_Gallery' ) && $gallery && $productType === 'post_product_variation' ) {
				$product->add_meta_data( 'woo_variation_gallery_images', $gallery, true );
			}
		} else {
			if ( $this->_wpmlEnabled ) {
				$currentLanguage = apply_filters( 'wpml_current_language', null );

				$objectId = apply_filters( 'wpml_object_id', $productId, $productType, true, $currentLanguage );

				if ( $objectId !== null ) {
					$trid         = apply_filters( 'wpml_element_trid', null, $objectId, 'post_product' );
					$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_product', false, true );

					foreach ( $translations as $translation ) {
						$trProduct = WC()->product_factory->get_product( $translation->{'element_id'} );

						if ( $trProduct ) {
							$trProduct->set_image_id( '' );
							$trProduct->set_gallery_image_ids( [] );

							if ( class_exists( 'Woo_Variation_Gallery' ) && $trProduct->get_type() === 'post_product_variation' ) {
								$product->delete_meta_data( 'woo_variation_gallery_images' );
							}
						}
					}
				}
			} else {
				$product->set_image_id( '' );
				$product->set_gallery_image_ids( [] );

				if ( class_exists( 'Woo_Variation_Gallery' ) && $product->get_type() === 'post_product_variation' ) {
					$product->delete_meta_data( 'woo_variation_gallery_images' );
				}
			}
		}

		return $product;
	}

	/**
	 * @param WC_Product $product     Product
	 * @param array      $a2cData     Data
	 * @param array      $metaChanges Meta Data changes
	 *
	 * @throws Exception
	 */
	protected function _wpmlSync( WC_Product $product, array $a2cData, array $metaChanges ) {
		$productId = $product->get_id();

		//WPML support
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && defined( 'ICL_PLUGIN_INACTIVE' ) && !ICL_PLUGIN_INACTIVE && class_exists( 'SitePress' ) ) {
			global $sitepress, $wpdb;

			$sitepress = WPML\Container\make( '\SitePress' );

			$productType         = $product->get_type();
			$elementType         = $productType === 'variation' ? 'post_product_variation' : 'post_product';
			$activeLanguages     = $sitepress->get_active_languages( true );
			$productLangCode     = apply_filters( 'wpml_element_language_code', null, ['element_id' => $productId, 'element_type' => $elementType] );
			$trIdProduct         = apply_filters( 'wpml_element_trid', null, $productId, $elementType );
			$productTranslations = apply_filters( 'wpml_get_element_translations', null, $trIdProduct, $elementType, false, true );

			if ( isset( $a2cData['product_data']['terms_data']['product_cat'] ) ) {
				$this->_translateTaxonomy(
					array_unique( array_column( $a2cData['product_data']['terms_data']['product_cat'], 'name' ) ),
					'product_cat',
					$activeLanguages
				);
			}

			if ( isset( $a2cData['product_data']['terms_data']['product_tag'] ) ) {
				$this->_translateTaxonomy(
					array_unique( array_column( $a2cData['product_data']['terms_data']['product_tag'], 'name' ) ),
					'product_tag',
					$activeLanguages
				);
			}

			if ( isset( $a2cData['product_data']['internal_data']['wpml_only_translate_to'] ) ) {
				if ( is_array( $a2cData['product_data']['internal_data']['wpml_only_translate_to'] ) ) {
					foreach ( $a2cData['product_data']['internal_data']['wpml_only_translate_to'] as $languageCode ) {
						do_action( 'wpml_switch_language', $languageCode );
						$sitepress->make_duplicate( $productId, $languageCode );
					}
				} else {
					do_action( 'wpml_switch_language', $a2cData['product_data']['internal_data']['wpml_only_translate_to'] );
					$sitepress->make_duplicate( $productId, $a2cData['product_data']['internal_data']['wpml_only_translate_to'] );
				}

				$productTranslations = apply_filters( 'wpml_get_element_translations', null, $trIdProduct, $elementType, false, true );
			}

			unset( $metaChanges['image_id'] );
			unset( $metaChanges['category_ids'] );
			unset( $metaChanges['tag_ids'] );
			unset( $metaChanges['gallery_image_ids'] );
			$attributes = $product->get_attributes( 'db' );

			foreach ( $productTranslations as $translation ) {
				if ( !is_null( $translation->source_language_code ) ) {
					do_action( 'wpml_switch_language', $translation->language_code );
					$className = 'WC_Product_' . implode( '_', array_map( 'ucfirst', explode( '-', $productType ) ) );

					if ( class_exists( $className ) ) {
						$trProduct = new $className( $translation->element_id );
					} else {
						$trProduct = WC()->product_factory->get_product( $translation->element_id );
					}

					if ( $trProduct instanceof WC_Product ) {
						if ( $attributes ) {
							$trProduct->set_attributes( $attributes );
						}

						foreach ( $metaChanges as $productProperty => $productData ) {
							if ( method_exists( $trProduct, 'set_' . $productProperty ) && $productData !== null ) {
								call_user_func_array( array($trProduct, 'set_' . $productProperty), [$productData] );
							} elseif ( $productProperty !== '_product_attributes' ) {
								update_post_meta( $trProduct->get_id(), $productProperty, $productData );
							}
						}

						if ( $product->get_type() === 'variation' ) {
							$this->_wpmlSyncVariation( $product, $trProduct, $translation->language_code );
						}

						if (isset($a2cData['product_data']['internal_data']['wpml_translations_meta'][$translation->language_code])) {
							foreach ( $a2cData['product_data']['internal_data']['wpml_translations_meta'][$translation->language_code] as $metaKey => $metaValue ) {
								if ( is_null( $metaValue ) ) {
									delete_post_meta( $trProduct->get_id(), $metaKey );
								} else {
									update_post_meta( $trProduct->get_id(), $metaKey, $metaValue );
								}
							}
						}

						if ( $trProduct->get_changes() ) {
							$trProduct->save();
						}
					}
				}
			}

			foreach ( $productTranslations as $translation ) {
				do_action( 'wpml_switch_language', $translation->language_code );

				if ( isset( $a2cData['product_data']['terms_data']['product_cat'] ) ) {
					//instead wp_delete_object_term_relationships
					$wpdb->query(
						$wpdb->prepare("
							DELETE tr
							FROM {$wpdb->term_relationships}  tr
								JOIN {$wpdb->term_taxonomy} tt
									ON tt.term_taxonomy_id = tr.term_taxonomy_id
							WHERE tr.object_id IN (%d) AND tt.taxonomy = %s",
							$translation->element_id,
							'product_cat'
						)
					);
					wp_set_object_terms(
						$translation->element_id,
						array_unique( array_column( $a2cData['product_data']['terms_data']['product_cat'], 'name' ) ),
						'product_cat'
					);
				}

				if ( isset( $a2cData['product_data']['terms_data'] ) ) {
					foreach ( $a2cData['product_data']['terms_data'] as $taxonomy => $items ) {
						$forAllLang = false;
						$append     = false;

						if ( isset( $items[0]['all_lang'] ) ) {
							$forAllLang = (bool)$items[0]['all_lang'];
						}

						if ( isset( $items[0]['append'] ) ) {
							$append = (bool)$items[0]['append'];
						}

						if ( $taxonomy === 'product_cat' || !$forAllLang ) {
							continue;
						}

						$oldObjectTerms = wp_get_object_terms(
							$translation->element_id,
							$taxonomy,
							array(
								'fields'                 => 'ids',
								'orderby'                => 'none',
								'update_term_meta_cache' => false,
							)
						);

						//instead wp_delete_object_term_relationships
						$wpdb->query(
							$wpdb->prepare("
								DELETE tr
								FROM {$wpdb->term_relationships}  tr
									JOIN {$wpdb->term_taxonomy} tt
										ON tt.term_taxonomy_id = tr.term_taxonomy_id
								WHERE tr.object_id IN (%d) AND tt.taxonomy = %s",
								$translation->element_id,
								$taxonomy
							)
						);

						foreach ( $items as $item ) {
							$termId = term_exists( $item['name'], $taxonomy );

							if ( $termId ) {
								wp_set_object_terms( $translation->element_id, (int)$termId['term_id'], $taxonomy, true );
							}
						}

						if ( $append ) {
							wp_set_object_terms( $translation->element_id, $oldObjectTerms, $taxonomy, true );
						}
					}
				}
			}

			$this->_rollbackProtectedData( $productId, $a2cData, $productLangCode );
		}
	}

	/**
	 * @param WC_Product $product   Product
	 * @param WC_Product $trProduct Product translation
	 * @param string     $langCode  Language Code
	 *
	 * @throws Exception
	 */
	protected function _wpmlSyncVariation ( WC_Product $product, WC_Product $trProduct, string $langCode ) {
		if ( $attributes = $product->get_attributes() ) {
			$translatedAttributes = [];

			foreach ( $attributes as $attributeName => $attributeValue ) {
				if ( taxonomy_exists( $attributeName ) ) {
					$term = get_term_by( 'slug', $attributeValue, $attributeName );

					if ( $term && !is_wp_error( $term ) ) {
						$translatedTermId = apply_filters( 'translate_object_id', $term->term_id, $attributeName, false, $langCode );

						do_action( 'wpml_switch_language', $langCode );
						$translatedTerm = get_term_by( 'id', $translatedTermId, $attributeName );

						if ( $translatedTerm && !is_wp_error( $translatedTerm ) ) {
							$trAttributeValue = $translatedTerm->slug;
						} else {
							$trAttributeValue = $attributeValue;
						}
					} else {
						$trAttributeValue = $attributeValue;
					}
				} else {
					$trAttributeValue = apply_filters( 'wpml_translate_single_string', $attributeValue, 'bridge-connector', $attributeName, $langCode);
				}

				$translatedAttributes[$attributeName] = $trAttributeValue;
			}

			$trProduct->set_attributes( $translatedAttributes );
		}

		if ( $imageId = $product->get_image_id() ) {
			$trProduct->set_image_id( $imageId );
		}
	}

	/**
	 * @param WC_Product $product Product
	 * @param array      $data    Meta attributes
	 */
	protected function _setAttributes( WC_Product $product, array $data) {
		global $wpdb;

		if ( $product->is_type( 'variation' ) ) {
			$attributes        = [];
			$parent            = wc_get_product( $data['product_data']['data']['parent_id'] );
			$parentAttributes = $parent->get_attributes();

			foreach ( $data['product_data']['data']['attributes'] as $attribute ) {
				$attributeId   = 0;
				$rawAttributeName = '';

				if ( ! empty( $attribute['id'] ) ) {
					$attributeId      = absint( $attribute['id'] );
					$rawAttributeName = wc_attribute_taxonomy_name_by_id( $attributeId );
				} elseif ( ! empty( $attribute['name'] ) ) {
					$rawAttributeName = sanitize_title( $attribute['name'] );
				}

				if ( ! $attributeId && ! $rawAttributeName ) {
					continue;
				}

				$attributeName = sanitize_title( $rawAttributeName );

				if ( ! isset( $parentAttributes[ $attributeName ] ) || ! $parentAttributes[ $attributeName ]->get_variation() ) {
					continue;
				}

				$attributeKey   = sanitize_title( $parentAttributes[ $attributeName ]->get_name() );
				$attributeValue = isset( $attribute['option'] ) ? wc_clean( stripslashes( $attribute['option'] ) ) : '';

				if ( $parentAttributes[ $attributeName ]->is_taxonomy() ) {
					$term = get_term_by( 'name', $attributeValue, $rawAttributeName );

					if ( $term && ! is_wp_error( $term ) ) {
						$attributeValue = $term->slug;
					} else {
						$attributeValue = sanitize_title( $attributeValue );
					}
				}

				$attributes[ $attributeKey ] = $attributeValue;
			}

			$product->set_attributes( $attributes );
		} else {
			$attributes = [];

			foreach ( $data['product_data']['data']['attributes'] as $attribute ) {
				$attributeId   = 0;
				$attributeName = '';

				if ( ! empty( $attribute['id'] ) ) {
					$attributeId   = absint( $attribute['id'] );
					$attributeName = wc_attribute_taxonomy_name_by_id( $attributeId );
				} elseif ( ! empty( $attribute['name'] ) ) {
					$attributeName = wc_clean( $attribute['name'] );
				}

				if ( ! $attributeId && ! $attributeName ) {
					continue;
				}

				if ( $attributeId ) {

					if ( isset( $attribute['options'] ) ) {
						$options = $attribute['options'];

						if ( ! is_array( $attribute['options'] ) ) {
							$options = explode( WC_DELIMITER, $options );
						}

						$values = array_map( 'wc_sanitize_term_text_based', $options );
						$values = array_filter( $values, 'strlen' );
					} else {
						$values = array();
					}

					if ( ! empty( $values ) ) {
						$attributeObject = new WC_Product_Attribute();
						$attributeObject->set_id( $attributeId );
						$attributeObject->set_name( $attributeName );
						$attributeObject->set_options( $values );
						$attributeObject->set_position( isset( $attribute['position'] ) ? (string) absint( $attribute['position'] ) : '0' );
						$attributeObject->set_visible( ( isset( $attribute['visible'] ) && $attribute['visible'] ) ? 1 : 0 );
						$attributeObject->set_variation( ( isset( $attribute['variation'] ) && $attribute['variation'] ) ? 1 : 0 );
						$attributes[] = $attributeObject;
					}
				} elseif ( isset( $attribute['options'] ) ) {
					if ( is_array( $attribute['options'] ) ) {
						$values = $attribute['options'];
					} else {
						$values = explode( WC_DELIMITER, $attribute['options'] );
					}
					$attributeObject = new WC_Product_Attribute();
					$attributeObject->set_name( $attributeName );
					$attributeObject->set_options( $values );
					$attributeObject->set_position( isset( $attribute['position'] ) ? (string) absint( $attribute['position'] ) : '0' );
					$attributeObject->set_visible( ( isset( $attribute['visible'] ) && $attribute['visible'] ) ? 1 : 0 );
					$attributeObject->set_variation( ( isset( $attribute['variation'] ) && $attribute['variation'] ) ? 1 : 0 );
					$attributes[] = $attributeObject;
				}
			}

			$product->set_attributes( $attributes );

			if ($this->_wpmlEnabled) {
				$sitepress = WPML\Container\make( '\SitePress' );

				$activeLanguages = $sitepress->get_active_languages( true );

				/** @var WC_Product_Attribute $attribute */
				foreach ($attributes as $attribute) {
					if ($attribute->is_taxonomy()) {
						$terms  = $attribute->get_terms();
						$values = [];

						/** @var WP_Term $term */
						foreach ( $terms as $term ) {
							$values[] = $term->term_id;
						}

						if ($values) {
							$this->_translateTaxonomy(array_unique( $values ), $attribute->get_taxonomy(), $activeLanguages);
						}
					}
				}

				$trIdProduct         = apply_filters('wpml_element_trid', null, $product->get_id(), 'post_product');
				$productTranslations = apply_filters('wpml_get_element_translations', null, $trIdProduct, 'post_product', false, true);

				foreach ($productTranslations as $translation) {
					do_action('wpml_switch_language', $translation->language_code);
					$trProduct = WC()->product_factory->get_product( $translation->element_id );

					if ($trProduct instanceof WC_Product) {

						/** @var WC_Product_Attribute $attribute */
						foreach ($attributes as $attribute) {
							if ($attribute->is_taxonomy()) {
								$terms  = $attribute->get_terms();
								$values = [];

								/** @var WP_Term $term */
								foreach ( $terms as $term ) {
									$values[] = $term->term_id;
								}

								//instead wp_delete_object_term_relationships
								$wpdb->query(
									$wpdb->prepare("
										DELETE tr
										FROM {$wpdb->term_relationships}  tr
											JOIN {$wpdb->term_taxonomy} tt
												ON tt.term_taxonomy_id = tr.term_taxonomy_id
										WHERE tr.object_id IN (%d) AND tt.taxonomy = %s",
										$translation->element_id,
										$attribute->get_taxonomy()
									)
								);
								wp_set_object_terms(
									$translation->element_id,
									$values,
									$attribute->get_taxonomy()
								);
							}
						}

					}
				}
			}
		}
	}

	/**
	 * @param WC_Product $product        Product
	 * @param array      $metaAttributes Meta attributes
	 */
	protected function _readAttributes( WC_Product $product, array $metaAttributes ) {
		if ( !empty( $metaAttributes ) && is_array( $metaAttributes ) ) {
			$attributes = array();
			foreach ( $metaAttributes as $meta_attribute_key => $meta_attribute_value ) {
				$meta_value = array_merge(
					array(
						'name'         => '',
						'value'        => '',
						'position'     => 0,
						'is_visible'   => 0,
						'is_variation' => 0,
						'is_taxonomy'  => 0,
					),
					(array)$meta_attribute_value
				);

				// Check if is a taxonomy attribute.
				if ( !empty( $meta_value['is_taxonomy'] ) ) {
					if ( !taxonomy_exists( $meta_value['name'] ) ) {
						continue;
					}
					$id      = wc_attribute_taxonomy_id_by_name( $meta_value['name'] );
					$options = wc_get_object_terms( $product->get_id(), $meta_value['name'], 'term_id' );
				} else {
					$id      = 0;
					$options = wc_get_text_attributes( $meta_value['value'] );
				}

				$attribute = new WC_Product_Attribute();
				$attribute->set_id( $id );
				$attribute->set_name( $meta_value['name'] );
				$attribute->set_options( array_unique( $options ) );
				$attribute->set_position( $meta_value['position'] );
				$attribute->set_visible( $meta_value['is_visible'] );
				$attribute->set_variation( $meta_value['is_variation'] );
				$attributes[] = $attribute;
			}
			$product->set_attributes( $attributes );
		}
	}

	/**
	 * @param int         $productId       Product ID
	 * @param array       $a2cData         Data
	 * @param string|null $productLangCode Lang code
	 */
	protected function _rollbackProtectedData( int $productId, array $a2cData, $productLangCode ) {
		global $wpdb;

		if ( isset( $a2cData['product_data']['internal_data']['wpml_current_lang_id'] ) ) {
			$productLangCode = $a2cData['product_data']['internal_data']['wpml_current_lang_id'];
		}

		if ( isset( $a2cData['product_data']['protected_data'] ) ) {
			$trIdProduct         = apply_filters( 'wpml_element_trid', null, $productId, 'post_product' );
			$productTranslations = apply_filters( 'wpml_get_element_translations', null, $trIdProduct, 'post_product', false, true );

			foreach ( $productTranslations as $translation ) {
				if ( $productLangCode !== $translation->language_code && isset( $a2cData['product_data']['protected_data'][ $translation->language_code ] ) ) {
					$fieldAssignments = [];
					$values = [];

					foreach ($a2cData['product_data']['protected_data'][ $translation->language_code ] as $field => $value) {
						$fieldAssignments[] = "$field = %s";
						$values[] = $value;
					}

					$fields = implode(', ', $fieldAssignments);

					$values[] = $translation->element_id;

					$wpdb->query(
						$wpdb->prepare("
							UPDATE {$wpdb->posts}
							SET {$fields}
							WHERE ID = %d",
							$values
						)
					);
				}
			}
		}
	}

	/**
	 * @param array  $termIds         Term IDs
	 * @param string $taxonomy        Taxonomy name
	 * @param array  $activeLanguages Active Languages
	 * @param bool   $useSlug         Use Slug flag
	 */
	protected function _translateTaxonomy( array $termIds, string $taxonomy, array $activeLanguages, bool $useSlug = false ) {
		$termType = $taxonomy;
		$terms    = [];

		foreach ( $termIds as $termId ) {
			if ( $taxonomy === 'product_tag' ) {
				$term = get_term_by( 'name', $termId, $taxonomy );
			} else {
				$term = get_term_by( 'id', (int)$termId, $taxonomy );
			}

			if ( $term ) {
				$terms[] = $term;
			}
		}

		foreach ( $terms as $term ) {
			foreach ( $activeLanguages as $language ) {
				do_action( 'wpml_switch_language', $language['code'] );
				$tr_id = apply_filters( 'translate_object_id', $term->term_id, $termType, false, $language['code'] );

				if ( is_null( $tr_id ) ) {
					$term_args = [];

					// hierarchy - parents.
					if ( is_taxonomy_hierarchical( $termType ) ) {
						// fix hierarchy.
						if ( $term->parent ) {
							$original_parent_translated = apply_filters( 'translate_object_id', $term->parent, $termType, false, $language['code'] );
							if ( $original_parent_translated ) {
								$term_args['parent'] = $original_parent_translated;
							}
						}
					}

					$term_name         = $term->name;
					$slug              = ( $useSlug ? $term->slug : $term->name ) . '-' . $language['code'];
					$slug              = WPML_Terms_Translations::term_unique_slug( $slug, $termType, $language['code'] );
					$term_args['slug'] = $slug;

					$new_term = wp_insert_term( $term_name, $termType, $term_args );

					if ( $new_term && !is_wp_error( $new_term ) ) {
						$tt_id = apply_filters( 'wpml_element_trid', null, $term->term_taxonomy_id, 'tax_' . $termType );

						$set_language_args = array(
							'element_id'    => $new_term['term_taxonomy_id'],
							'element_type'  => 'tax_' . $termType,
							'trid'          => $tt_id,
							'language_code' => $language['code'],
						);

						do_action( 'wpml_set_element_language_details', $set_language_args );
					}
				}
			}
		}
	}

	/**
	 * @param int $productId Product ID
	 */
	protected function _cleanGarbage( int $productId ) {
		try {
			if ( defined( 'ICL_SITEPRESS_VERSION' ) && defined( 'ICL_PLUGIN_INACTIVE' ) && !ICL_PLUGIN_INACTIVE && class_exists( 'SitePress' ) ) {
				$trIdProduct         = apply_filters( 'wpml_element_trid', null, $productId, 'post_product' );
				$productTranslations = apply_filters( 'wpml_get_element_translations', null, $trIdProduct, 'post_product', false, true );

				foreach ( $productTranslations as $translation ) {
					do_action( 'wpml_switch_language', $translation->language_code );
					$product = WC()->product_factory->get_product( $translation->element_id );

					if ( $product instanceof WC_Product ) {
						$product->delete( true );
					}
				}
			} else {
				$product = WC()->product_factory->get_product( $productId );

				if ( $product instanceof WC_Product ) {
					$product->delete( true );
				}
			}
		} catch ( Throwable $e ) {
		}
	}

	/**
	 * Adds a note (comment) to the order. Order must exist.
	 *
	 * @param string $orderId        Order ID
	 * @param string $note           Note to add.
	 * @param int    $isCustomerNote Is this a note for the customer?.
	 * @param bool   $addedByAdmin   Was the note added by a admin?
	 *
	 * @return int                       Comment ID.
	 */
	protected function _addOrderNote( string $orderId, string $note, int $isCustomerNote = 0, bool $addedByAdmin = false ) {
		if ($addedByAdmin) {
			$comment_admin_email = get_option( 'admin_email' );
			$user                = get_user_by( 'email', $comment_admin_email );

			if ( $user ) {
				$comment_author = $user->display_name;
			} else {
				$comment_author = $comment_admin_email;
			}
		} else {
			$comment_author        = esc_html__( 'WooCommerce', 'api2cart-bridge-connector' );
			$comment_author_email  = esc_html__( 'WooCommerce', 'api2cart-bridge-connector' ) . '@';
			$comment_author_email .= isset( $_SERVER['HTTP_HOST'] ) ? str_replace( 'www.', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : 'noreply.com';
			$comment_author_email  = sanitize_email( $comment_author_email );
		}

		$commentdata = apply_filters(
			'woocommerce_new_order_note_data',
			array(
				'comment_post_ID'      => $orderId,
				'comment_author'       => $comment_author,
				'comment_author_email' => $comment_author_email,
				'comment_author_url'   => '',
				'comment_content'      => $note,
				'comment_agent'        => 'WooCommerce',
				'comment_type'         => 'order_note',
				'comment_parent'       => 0,
				'comment_approved'     => 1,
			),
			array(
				'order_id'         => $orderId,
				'is_customer_note' => $isCustomerNote,
			)
		);

		$commentId = wp_insert_comment( $commentdata );

		if ( $isCustomerNote ) {
			add_comment_meta( $commentId, 'is_customer_note', 1 );

			do_action(
				'woocommerce_new_customer_note',
				array(
					'order_id'      => $orderId,
					'customer_note' => $commentdata['comment_content'],
				)
			);
		}

		return $commentId;
	}
}

/**
 * Class A2C_Bridge_Action_Send_Notification
 */
class A2C_Bridge_Action_Send_Notification {

	/**
	 * Perform
	 *
	 * @param A2C_Bridge $bridge
	 */
	public function Perform( A2C_Bridge $bridge ) {
		$response = array(
			'error'   => false,
			'code'    => null,
			'message' => null,
		);

		$request = $bridge->getRequest();
		$parameters = $request->get_params();

		$cartId = sanitize_text_field( $parameters['cartId'] );

		try {
			switch ( $cartId ) {
				case 'Woocommerce':
					$msgClasses = sanitize_text_field( $parameters['data_notification']['msg_classes'] );
					$callParams = sanitize_text_field( $parameters['data_notification']['msg_params'] );
					$storeId    = sanitize_text_field( $parameters['data_notification']['store_id'] );
					if ( function_exists( 'switch_to_blog' ) ) {
						switch_to_blog( $storeId );
					}

					$emails = wc()->mailer()->get_emails();
					foreach ( $msgClasses as $msgClass ) {
						if ( isset( $emails[ $msgClass ] ) ) {
							call_user_func_array( array( $emails[ $msgClass ], 'trigger' ), $callParams[ $msgClass ] );
						}
					}

					return wp_json_encode( $response );
			}
		} catch ( Exception $e ) {
			$response['error']   = true;
			$response['code']    = $e->getCode();
			$response['message'] = $e->getMessage();

			return wp_json_encode( $response );
		}
	}

}

/**
 * Class A2C_Bridge_Action_Savefile
 */
class A2C_Bridge_Action_Savefile {

	protected $_imageType = null;

	protected $_mageLoaded = false;

	/**
	 * Perform
	 *
	 * @param $bridge
	 */
	public function Perform( A2C_Bridge $bridge ) {
		$request = $bridge->getRequest();
		$parameters = $request->get_params();

		$source      = esc_url_raw( $parameters['src'] );
		$destination = sanitize_text_field( $parameters['dst'] );
		$width       = (int) sanitize_key( $parameters['width'] );
		$height      = (int) sanitize_key( $parameters['height'] );

		return $this->_saveFile( $source, $destination, $width, $height );
	}

	/**
	 * SaveFile
	 *
	 * @param $source
	 * @param $destination
	 * @param $width
	 * @param $height
	 * @param $local
	 * @return string
	 */
	public function _saveFile( $source, $destination, $width, $height ) {
		$extensions = [
			'3g2',
			'3gp',
			'7z',
			'aac',
			'accdb',
			'accde',
			'accdr',
			'accdt',
			'ace',
			'adt',
			'adts',
			'afa',
			'aif',
			'aifc',
			'aiff',
			'alz',
			'amv',
			'apk',
			'arc',
			'arj',
			'ark',
			'asf',
			'avi',
			'b1',
			'b6z',
			'ba',
			'bh',
			'bmp',
			'cab',
			'car',
			'cda',
			'cdx',
			'cfs',
			'cpt',
			'csv',
			'dar',
			'dd',
			'dgc',
			'dif',
			'dmg',
			'doc',
			'docm',
			'docx',
			'dot',
			'dotx',
			'drc',
			'ear',
			'eml',
			'eps',
			'f4a',
			'f4b',
			'f4p',
			'f4v',
			'flv',
			'gca',
			'genozip',
			'gifv',
			'ha',
			'hki',
			'ice',
			'iso',
			'jar',
			'kgb',
			'lha',
			'lzh',
			'lzx',
			'm2ts',
			'm2v',
			'm4a',
			'm4p',
			'm4v',
			'mid',
			'midi',
			'mkv',
			'mng',
			'mov',
			'mp2',
			'mp3',
			'mp4',
			'mpe',
			'mpeg',
			'mpg',
			'mpv',
			'mts',
			'mxf',
			'nsv',
			'ogg',
			'ogv',
			'pak',
			'partimg',
			'pdf',
			'pea',
			'phar',
			'pim',
			'pit',
			'pot',
			'potm',
			'potx',
			'ppam',
			'pps',
			'ppsm',
			'ppsx',
			'ppt',
			'pptm',
			'pptx',
			'psd',
			'pst',
			'pub',
			'qda',
			'qt',
			'rar',
			'rk',
			'rm',
			'rmvb',
			'roq',
			'rtf',
			's7z',
			'sda',
			'sea',
			'sen',
			'sfx',
			'shk',
			'sit',
			'sitx',
			'sldm',
			'sldx',
			'sqx',
			'svi',
			'tar',
			'bz2',
			'gz',
			'lz',
			'xz',
			'zst',
			'tbz2',
			'tgz',
			'tif',
			'tiff',
			'tlz',
			'tmp',
			'ts',
			'txt',
			'txz',
			'uca',
			'uha',
			'viv',
			'vob',
			'vsd',
			'vsdm',
			'vsdx',
			'vss',
			'vssm',
			'vst',
			'vstm',
			'vstx',
			'war',
			'wav',
			'wbk',
			'webm',
			'wim',
			'wks',
			'wma',
			'wmd',
			'wms',
			'wmv',
			'wmz',
			'wp5',
			'wpd',
			'xar',
			'xla',
			'xlam',
			'xlm',
			'xls',
			'xlsm',
			'xlsx',
			'xlt',
			'xltm',
			'xltx',
			'xp3',
			'xps',
			'yuv',
			'yz1',
			'zip',
			'zipx',
			'zoo',
			'zpaq',
			'zz',
			'png',
			'jpeg',
			'jpg',
			'gif',
			'',
		];
		preg_match( '/\.[\w]+$/', $destination, $fileExtension );
		$fileExtension = isset( $fileExtension[0] ) ? $fileExtension[0] : '';

		if ( ! in_array( str_replace( '.', '', $fileExtension ), $extensions ) ) {
			return 'ERROR_INVALID_FILE_EXTENSION';
		}

		if ( ! preg_match( '/^https?:\/\//i', $source ) ) {
			$result = $this->_createFile( $source, $destination );
		} else {
			$result = $this->_saveFileCurl( $source, $destination );
		}

		if ( 'OK' != $result ) {
			return $result;
		}

		$destination = A2CBC_STORE_BASE_DIR . $destination;

		if ( 0 != $width && 0 != $height ) {
			$this->_scaled2( $destination, $width, $height );
		}

		return $result;
	}

	/**
	 * LoadImage
	 *
	 * @param         $filename
	 * @param boolean $skipJpg
	 * @return boolean|resource
	 */
	private function _loadImage( $filename, $skipJpg = true ) {
		$imageInfo = @getimagesize( $filename );
		if ( false === $imageInfo ) {
			return false;
		}

		$this->_imageType = $imageInfo[2];

		switch ( $this->_imageType ) {
			case IMAGETYPE_JPEG:
				$image = imagecreatefromjpeg( $filename );
				break;
			case IMAGETYPE_GIF:
				$image = imagecreatefromgif( $filename );
				break;
			case IMAGETYPE_PNG:
				$image = imagecreatefrompng( $filename );
				break;
			default:
				return false;
		}

		if ( $skipJpg && ( IMAGETYPE_JPEG == $this->_imageType ) ) {
			return false;
		}

		return $image;
	}

	/**
	 * SaveImage
	 *
	 * @param         $image
	 * @param         $filename
	 * @param integer $imageType
	 * @param integer $compression
	 * @return boolean
	 */
	private function _saveImage( $image, $filename, $imageType = IMAGETYPE_JPEG, $compression = 85 ) {
		$result = true;
		if ( IMAGETYPE_JPEG == $imageType ) {
			$result = imagejpeg( $image, $filename, $compression );
		} elseif ( IMAGETYPE_GIF == $imageType ) {
			$result = imagegif( $image, $filename );
		} elseif ( IMAGETYPE_PNG == $imageType ) {
			$result = imagepng( $image, $filename );
		}

		imagedestroy( $image );

		return $result;
	}

	/**
	 * CreateFile
	 *
	 * @param string $source      Source
	 * @param string $destination Destination
	 *
	 * @return string
	 */
	private function _createFile( $source, $destination ) {
		global $wp_filesystem;

		if ( null === $wp_filesystem ) {
			if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}

			$wp_filesystem = new WP_Filesystem_Direct( '' );
		}

		if ( $this->_createDir( dirname( $destination ) ) !== false ) {
			$body = base64_decode( $source );
			if ( false === $body || false === $wp_filesystem->file_put_contents( $destination, $body ) ) {
				return '[BRIDGE ERROR] File save failed!';
			}

			return 'OK';
		}

		return '[BRIDGE ERROR] Directory creation failed!';
	}

	/**
	 * SaveFileCurl
	 *
	 * @param $source
	 * @param $destination
	 * @return string
	 */
	private function _saveFileCurl( $source, $destination ) {
		global $wp_filesystem;

		if ( null === $wp_filesystem ) {
			if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}

			$wp_filesystem = new WP_Filesystem_Direct( '' );
		}

		$source = $this->_escapeSource( $source );
		if ( $this->_createDir( dirname( $destination ) ) !== false ) {
			$headers = [
				'Accept-Language:*',
				'User-Agent: "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1"',
			];

			$dst = $wp_filesystem->get_contents( dirname( $destination ) );
			if ( false === $dst ) {
				return '[BRIDGE ERROR] Can\'t create  $destination!';
			}

			$request = wp_remote_get( $source,
				[
					'method'      => 'GET',
					'timeout'     => 60,
					'redirection' => 5,
					'httpversion' => '1.0',
					'blocking'    => true,
					'stream'      => true,
					'filename'    => $destination,
					'headers'     => $headers,
					'cookies'     => [],
				] );

			if ( wp_remote_retrieve_response_code( $request ) != 200 ) {
				return '[BRIDGE ERROR] Bad response received from source, HTTP code wp_remote_retrieve_response_code($request)!';
			}

			return 'OK';
		} else {
			return '[BRIDGE ERROR] Directory creation failed!';
		}
	}

	/**
	 * EscapeSource
	 *
	 * @param $source
	 * @return mixed
	 */
	private function _escapeSource( $source ) {
		return str_replace( ' ', '%20', $source );
	}

	/**
	 * CreateDir
	 *
	 * @param $dir
	 * @return boolean
	 */
	private function _createDir( $dir ) {
		global $wp_filesystem;

		if ( null === $wp_filesystem ) {
			if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}

			$wp_filesystem = new WP_Filesystem_Direct( '' );
		}

		$uploadsPath = wp_upload_dir( null, false )['basedir'];

		$dirParts    = explode( '/', str_replace( $uploadsPath, '', $dir ) );
		$uploadsPath = rtrim( $uploadsPath, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

		foreach ( $dirParts as $item ) {
			if ( '' == $item ) {
				continue;
			}

			$uploadsPath .= $item . DIRECTORY_SEPARATOR;

			if ( ! is_dir( $uploadsPath ) ) {
				$res = $wp_filesystem->mkdir( $uploadsPath, FS_CHMOD_DIR );

				if ( ! $res ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Scaled2 method optimizet for prestashop
	 *
	 * @param $destination
	 * @param $destWidth
	 * @param $destHeight
	 * @return string
	 */
	private function _scaled2( $destination, $destWidth, $destHeight ) {
		$method = 0;

		$sourceImage = $this->_loadImage( $destination, false );

		if ( false === $sourceImage ) {
			return 'IMAGE NOT SUPPORTED';
		}

		$sourceWidth  = imagesx( $sourceImage );
		$sourceHeight = imagesy( $sourceImage );

		$widthDiff  = $destWidth / $sourceWidth;
		$heightDiff = $destHeight / $sourceHeight;

		if ( $widthDiff > 1 && $heightDiff > 1 ) {
			$nextWidth  = $sourceWidth;
			$nextHeight = $sourceHeight;
		} else {
			if ( intval( $method ) == 2 || ( intval( $method ) == 0 && $widthDiff > $heightDiff ) ) {
				$nextHeight = $destHeight;
				$nextWidth  = intval( ( $sourceWidth * $nextHeight ) / $sourceHeight );
				$destWidth  = ( ( intval( $method ) == 0 ) ? $destWidth : $nextWidth );
			} else {
				$nextWidth  = $destWidth;
				$nextHeight = intval( $sourceHeight * $destWidth / $sourceWidth );
				$destHeight = ( intval( $method ) == 0 ? $destHeight : $nextHeight );
			}
		}

		$borderWidth  = intval( ( $destWidth - $nextWidth ) / 2 );
		$borderHeight = intval( ( $destHeight - $nextHeight ) / 2 );

		$destImage = imagecreatetruecolor( $destWidth, $destHeight );

		$white = imagecolorallocate( $destImage, 255, 255, 255 );
		imagefill( $destImage, 0, 0, $white );

		imagecopyresampled( $destImage, $sourceImage, $borderWidth, $borderHeight, 0, 0, $nextWidth, $nextHeight, $sourceWidth, $sourceHeight );
		imagecolortransparent( $destImage, $white );

		return $this->_saveImage( $destImage, $destination, $this->_imageType, 100 ) ? 'OK' : 'CAN\'T SCALE IMAGE';
	}

}

/**
 * Class A2C_Bridge_Action_Query
 */
class A2C_Bridge_Action_Query {

	/**
	 * Extract extended query params from post and request
	 */
	public static function requestToExtParams( array $parameters ) {
		return array(
			'fetch_fields' => ( isset( $parameters['fetchFields'] ) && ( intval( $parameters['fetchFields'] ) == 1 ) ),
			'set_names'    => isset( $parameters['set_names'] ) ? sanitize_text_field( $parameters['set_names'] ) : false,
		);
	}

	/**
	 * SetSqlMode
	 *
	 * @param A2C_Bridge $bridge Bridge Instance
	 *
	 * @return boolean|array
	 */
	public static function setSqlMode( A2C_Bridge $bridge ) {
		$sqlSettings = $bridge->getRequest()->get_param( 'sql_settings' );

		if ($sqlSettings) {
			try {
				if (isset($sqlSettings['sql_modes'])) {
					if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
						$query = 'SET SESSION SQL_MODE=' . A2C_decrypt( $sqlSettings['sql_modes'], true );
					} else {
						$query = 'SET SESSION SQL_MODE=' . base64_decode( A2C_swapLetters( $sqlSettings['sql_modes'] ) );
					}

					$bridge->getLink()->localQuery($query);
				}

				if (isset($sqlSettings['sql_variables'])) {
					if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
						$query = A2C_decrypt( $sqlSettings['sql_variables'], true );
					} else {
						$query = base64_decode( A2C_swapLetters( $sqlSettings['sql_variables'] ) );
					}

					$bridge->getLink()->localQuery($query);
				}
			} catch (Throwable $exception) {
				if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
					return A2C_encrypt(
						serialize(
							[
								'error'         => $exception->getMessage(),
								'query'         => $query,
								'failedQueryId' => 0,
							]
						)
					);
				} else {
					return base64_encode(
						serialize(
							[
								'error'         => $exception->getMessage(),
								'query'         => $query,
								'failedQueryId' => 0,
							]
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * Perform
	 *
	 * @param A2C_Bridge $bridge Bridge instance
	 * @return boolean
	 */
	public function Perform( A2C_Bridge $bridge ) {
		$request = $bridge->getRequest();
		$parameters = $request->get_params();

		if ( isset( $parameters['query'] ) && isset( $parameters['fetchMode'] ) ) {

			if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
				$query = A2C_decrypt(sanitize_text_field( $parameters['query'] ) , true);
			} else {
				$query = base64_decode(A2C_swapLetters( sanitize_text_field( $parameters['query'] ) ) );
			}

			$fetchMode = (int) $parameters['fetchMode'];

			if ( ! self::setSqlMode( $bridge ) ) {
				return false;
			}

			$res = $bridge->getLink()->query( $query, $fetchMode, self::requestToExtParams($parameters) );

			if ( is_array( $res['result'] ) || is_bool( $res['result'] ) ) {
				$result = serialize( array(
					'res'           => $res['result'],
					'fetchedFields' => @$res['fetchedFields'],
					'insertId'      => $bridge->getLink()->getLastInsertId(),
					'affectedRows'  => $bridge->getLink()->getAffectedRows(),
				) );

				if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
					return A2C_encrypt( $result );
				} else {
					return base64_encode( $result );
				}
			} else {
				if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
					return A2C_encrypt( serialize( ['error' => $res['message'], 'query' => $query, 'failedQueryId' => 0] ) );
				} else {
					return base64_encode( $res['message'] );
				}
			}
		} else {
			return false;
		}
	}

}

class A2C_Bridge_Action_Platform_Action {

	/**
	 * Perform
	 *
	 * @param A2C_Bridge $bridge
	 */
	public function Perform( A2C_Bridge $bridge ) {
		global $wpdb;
		$request = $bridge->getRequest();
		$parameters = $request->get_params();

		if ( empty( $wpdb->dbh ) || empty( $wpdb->dbh->client_info ) ) {
			$wpdb->db_connect( false );
		}

		if ( isset( $parameters['platform_action'], $parameters['data'] ) && $parameters['platform_action'] && method_exists( $bridge->config,
				$parameters['platform_action'] ) ) {
			$response = array( 'error' => null, 'data' => null );

			try {
				if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
					$data = json_decode( A2C_decrypt( $parameters['data'], true ), true );
				} else {
					$data = json_decode( base64_decode( A2C_swapLetters( $parameters['data'] ) ), true );
				}

				$response['data'] = $bridge->config->{sanitize_text_field( $parameters['platform_action'] )}( $data );
			} catch ( Exception $e ) {
				$response['error']['message'] = $e->getMessage();
				$response['error']['code']    = $e->getCode();
			} catch ( Throwable $e ) {
				$response['error']['message'] = $e->getMessage();
				$response['error']['code']    = $e->getCode();
			}

			return wp_json_encode( $response );
		} else {
			return wp_json_encode( array( 'error' => array( 'message' => 'Action is not supported' ), 'data' => null ) );
		}
	}

}

/**
 * Class A2C_Bridge_Action_Phpinfo
 */
class A2C_Bridge_Action_Phpinfo {


	/**
	 * Perform
	 *
	 * @param A2C_Bridge $bridge
	 */
	public function Perform( A2C_Bridge $bridge ) {
		return phpinfo();
	}

}

class A2C_Bridge_Action_Multiquery {

	protected $_lastInsertIds = array();
	protected $_result        = array();

	/**
	 * Perform
	 *
	 * @param A2C_Bridge $bridge
	 * @return boolean|null
	 */
	public function Perform( A2C_Bridge $bridge ) {
		$request = $bridge->getRequest();
		$parameters = $request->get_params();

		if ( isset( $parameters['queries'] ) && isset( $parameters['fetchMode'] ) ) {
			wp_raise_memory_limit( 'admin' );

			if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
				$queries = json_decode( A2C_decrypt( sanitize_text_field( $parameters['queries'] ), true) );
			} else {
				$queries = json_decode( base64_decode( A2C_swapLetters( sanitize_text_field( $parameters['queries'] ) ) ) );
			}

			$count   = 0;

			if ( ! A2C_Bridge_Action_Query::setSqlMode( $bridge ) ) {
				return false;
			}

			foreach ( $queries as $queryId => $query ) {
				if ( $count ++ > 0 ) {
					$query = preg_replace_callback( '/_A2C_LAST_\{([a-zA-Z0-9_\-]{1,32})\}_INSERT_ID_/', array( $this, '_replace' ), $query );
					$query = preg_replace_callback( '/A2C_USE_FIELD_\{([\w\d\s\-]+)\}_FROM_\{([a-zA-Z0-9_\-]{1,32})\}_QUERY/',
						array( $this, '_replaceWithValues' ),
						$query );
				}

				$res = $bridge->getLink()->query( $query, (int) $parameters['fetchMode'], A2C_Bridge_Action_Query::requestToExtParams($parameters) );
				if ( is_array( $res['result'] ) || is_bool( $res['result'] ) ) {
					$queryRes = array(
						'res'           => $res['result'],
						'fetchedFields' => @$res['fetchedFields'],
						'insertId'      => $bridge->getLink()->getLastInsertId(),
						'affectedRows'  => $bridge->getLink()->getAffectedRows(),
					);

					$this->_result[ $queryId ]        = $queryRes;
					$this->_lastInsertIds[ $queryId ] = $queryRes['insertId'];
				} else {
					$data['error']         = $res['message'];
					$data['failedQueryId'] = $queryId;
					$data['query']         = $query;

					if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
						return A2C_encrypt( serialize( $data ) );
					} else {
						return base64_encode( serialize( $data ) );
					}
				}
			}

			if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
				return A2C_encrypt( serialize( $this->_result ) );
			} else {
				return base64_encode( serialize( $this->_result ) );
			}
		} else {
			return false;
		}
	}

	/**
	 * Replace
	 *
	 * @param $matches
	 *
	 * @return mixed
	 */
	protected function _replace( $matches ) {
		return $this->_lastInsertIds[ $matches[1] ];
	}

	/**
	 * ReplaceWithValues
	 *
	 * @param $matches
	 *
	 * @return string
	 */
	protected function _replaceWithValues( $matches ) {
		$values = array();
		if ( isset( $this->_result[ $matches[2] ]['res'] ) ) {
			foreach ( $this->_result[ $matches[2] ]['res'] as $row ) {
				if ( null === $row[ $matches[1] ] ) {
					$values[] = $row[ $matches[1] ];
				} else {
					$values[] = addslashes( $row[ $matches[1] ] );
				}
			}
		}

		return '\'' . implode( '\', \'', array_unique( $values ) ) . '\'';
	}

}

/**
 * Class A2C_Bridge_Action_Getconfig
 */
class A2C_Bridge_Action_Getconfig {

	/**
	 * ParseMemoryLimit
	 *
	 * @param $val
	 * @return integer
	 */
	private function parseMemoryLimit( $val ) {
		$valInt = (int) $val;
		$last   = strtolower( $val[ strlen( $val ) - 1 ] );

		switch ( $last ) {
			case 'g':
				$valInt *= 1024;
			//case giga
			case 'm':
				$valInt *= 1024;
			//case mega
			case 'k':
				$valInt *= 1024;
			//case kilo
		}

		return $valInt;
	}

	/**
	 * GetMemoryLimit
	 *
	 * @return mixed
	 */
	private function getMemoryLimit() {
		$memoryLimit = trim( @ini_get( 'memory_limit' ) );

		if ( strlen( $memoryLimit ) === 0 ) {
			$memoryLimit = '0';
		}

		return $this->parseMemoryLimit( $memoryLimit );
	}

	/**
	 * IsZlibSupported
	 *
	 * @return boolean
	 */
	private function isZlibSupported() {
		return function_exists( 'gzdecode' );
	}

	/**
	 * Perform
	 *
	 * @param $bridge
	 */
	public function Perform( A2C_Bridge $bridge ) {
		try {
			$timeZone = date_default_timezone_get();
		} catch ( Exception $e ) {
			$timeZone = 'UTC';
		}

		$result = array(
			'images'        => array(
				'imagesPath'               => $bridge->config->imagesDir, // path to images folder - relative to store root
				'categoriesImagesPath'     => $bridge->config->categoriesImagesDir,
				'categoriesImagesPaths'    => $bridge->config->categoriesImagesDirs,
				'productsImagesPath'       => $bridge->config->productsImagesDir,
				'productsImagesPaths'      => $bridge->config->productsImagesDirs,
				'manufacturersImagesPath'  => $bridge->config->manufacturersImagesDir,
				'manufacturersImagesPaths' => $bridge->config->manufacturersImagesDirs,
			),
			'languages'     => $bridge->config->languages,
			'baseDirFs'     => A2CBC_STORE_BASE_DIR,    // filesystem path to store root
			'bridgeVersion' => A2CBC_BRIDGE_VERSION,
			'bridgeKeyId'   => defined('A2CBC_BRIDGE_PUBLIC_KEY_ID') ? A2CBC_BRIDGE_PUBLIC_KEY_ID : '',
			'databaseName'  => $bridge->config->dbname,
			'cartDbPrefix'  => $bridge->config->tblPrefix,
			'memoryLimit'   => $this->getMemoryLimit(),
			'zlibSupported' => $this->isZlibSupported(),
			'cartVars'      => $bridge->config->cartVars,
			'time_zone'     => isset($bridge->config->timeZone) ? $bridge->config->timeZone : $timeZone,
		);

		if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
			return A2C_encrypt( serialize( $result ) );
		} else {
			return ( serialize( $result ) );
		}
	}

}

/**
 * Class A2C_Bridge_Action_GetShipmentProviders
 */
class A2C_Bridge_Action_GetShipmentProviders {

	/**
	 * Perform
	 *
	 * @param A2C_Bridge $bridge
	 *
	 * @return false|string
	 */
	public function Perform( A2C_Bridge $bridge ) {
		$response = array( 'error' => null, 'data' => null );

		switch ( $bridge->config->cartType ) {
			case 'Wordpress':
				if ( 'Woocommerce' === $bridge->config->cartId ) {
					if ( class_exists( 'WC_Shipment_Tracking_Actions' ) ) {
						try {
							$st   = new WC_Shipment_Tracking_Actions();
							$res  = $st->get_providers();
							$data = array();

							foreach ( $res as $country => $providers ) {
								foreach ( $providers as $providerName => $url ) {
									$data[ sanitize_title( $providerName ) ] = array(
										'name'    => $providerName,
										'country' => $country,
										'url'     => $url,
									);
								}
							}

							$response['data'] = $data;
						} catch ( Exception $e ) {
							$response['error']['message'] = $e->getMessage();
							$response['error']['code']    = $e->getCode();
						}
					} else {
						$response['error']['message'] = 'File does not exist';
					}
				} else {
					$response['error']['message'] = 'Action is not supported';
				}
				break;
			default:
				$response['error']['message'] = 'Action is not supported';
		}

		return wp_json_encode( $response );
	}

}

/**
 * Class A2C_Bridge_Action_CreateRefund
 */
class A2C_Bridge_Action_CreateRefund {

	/**
	 * Check request key
	 *
	 * @param string $requestKey Request Key
	 * @return boolean
	 */
	private function _checkRequestKey( $requestKey ) {
		$request = wp_remote_post( A2CBC_BRIDGE_CHECK_REQUEST_KEY_LINK,
			[
				'method'      => 'POST',
				'timeout'     => 60,
				'redirection' => 5,
				'httpversion' => '1.0',
				'sslverify'   => false,
				http_build_query( array( 'request_key' => $requestKey, 'store_key' => A2CBC_TOKEN ) ),
			] );

		if ( wp_remote_retrieve_response_code( $request ) != 200 ) {
			return '[BRIDGE ERROR] Bad response received from source, HTTP code wp_remote_retrieve_response_code($request)!';
		}

		try {
			$res = json_decode( $request['body'] );
		} catch ( Exception $e ) {
			return false;
		}

		return isset( $res->success ) && $res->success;
	}

	/**
	 * Perform
	 *
	 * @param A2C_Bridge $bridge
	 * @return void
	 */
	public function Perform( A2C_Bridge $bridge ) {
		$response = array( 'error' => null, 'data' => null );
		$request = $bridge->getRequest();
		$parameters = $request->get_params();

		if ( ! isset( $parameters['request_key'] ) || ! $this->_checkRequestKey( sanitize_text_field( $parameters['request_key'] ) ) ) {
			$response['error']['message'] = 'Not authorized';
			echo wp_json_encode( $response );

			return;
		}

		$orderId           = $parameters['order_id'];
		$isOnline          = $parameters['is_online'];
		$refundMessage     = isset( $parameters['refund_message'] ) ? sanitize_text_field( $parameters['refund_message'] ) : '';
		$itemsData         = json_decode( sanitize_text_field( $parameters['items'] ), true );
		$totalRefund       = isset( $parameters['total_refund'] ) ? (float) $parameters['total_refund'] : null;
		$shippingRefund    = isset( $parameters['shipping_refund'] ) ? (float) $parameters['shipping_refund'] : null;
		$adjustmentRefund  = isset( $parameters['adjustment_refund'] ) ? (float) $parameters['adjustment_refund'] : null;
		$restockItems      = isset( $parameters['restock_items'] ) ? filter_var( $parameters['restock_items'], FILTER_VALIDATE_BOOLEAN ) : false;
		$sendNotifications = isset( $parameters['send_notifications'] ) ? filter_var( $parameters['send_notifications'], FILTER_VALIDATE_BOOLEAN ) : false;

		try {
			switch ( $bridge->config->cartType ) {
				case 'Wordpress':
					if ( 'Woocommerce' ===$bridge->config->cartId ) {
						$order = wc_get_order( $orderId );

						if ( $isOnline ) {
							if ( WC()->payment_gateways() ) {
								$paymentGateways = WC()->payment_gateways->payment_gateways();
							}

							if ( ! ( isset( $paymentGateways[ $order->payment_method ] ) && $paymentGateways[ $order->payment_method ]->supports( 'refunds' ) ) ) {
								throw new Exception( 'Order payment method does not support refunds' );
							}
						}

						$refund = wc_create_refund( array(
							'amount'         => ! is_null( $totalRefund ) ? (float) $totalRefund : $order->get_remaining_refund_amount(),
							'reason'         => $refundMessage,
							'order_id'       => $orderId,
							'line_items'     => $itemsData,
							'refund_payment' => false, // dont repay refund immediately for better error processing
							'restock_items'  => $restockItems,
						) );

						if ( is_wp_error( $refund ) ) {
							$response['error']['code']    = $refund->get_error_code();
							$response['error']['message'] = $refund->get_error_message();
						} elseif ( ! $refund ) {
							$response['error']['message'] = 'An error occurred while attempting to create the refund';
						}

						if ( $response['error'] ) {
							echo wp_json_encode( $response );

							return;
						}

						if ( $isOnline ) {
							if ( WC()->payment_gateways() ) {
								$paymentGateways = WC()->payment_gateways->payment_gateways();
							}

							if ( isset( $paymentGateways[ $order->payment_method ] ) && $paymentGateways[ $order->payment_method ]->supports( 'refunds' ) ) {
								try {
									$result = $paymentGateways[ $order->payment_method ]->process_refund( $orderId,
										$refund->get_refund_amount(),
										$refund->get_refund_reason() );
								} catch ( Exception $e ) {
									$refund->delete( true ); // delete($force_delete = true)
									throw $e;
								}

								if ( is_wp_error( $result ) ) {
									$refund->delete( true );
									$response['error']['code']    = $result->get_error_code();
									$response['error']['message'] = $result->get_error_message();
								} elseif ( ! $result ) {
									$refund->delete( true );
									$response['error']['message'] = 'An error occurred while attempting to repay the refund using the payment gateway API';
								} else {
									$response['data']['refunds'][] = $refund->get_id();
								}
							} else {
								$refund->delete( true );
								$response['error']['message'] = 'Order payment method does not support refunds';
							}
						}
					} else {
						$response['error']['message'] = 'Action is not supported';
					}
					break;

				default:
					$response['error']['message'] = 'Action is not supported';
			}
		} catch ( Exception $e ) {
			unset( $response['data'] );
			$response['error']['message'] = $e->getMessage();
			$response['error']['code']    = $e->getCode();
		}

		return wp_json_encode( $response );

	}

}

/**
 * Class A2C_Bridge_Action_Batchsavefile
 */
class A2C_Bridge_Action_Batchsavefile extends A2C_Bridge_Action_Savefile {


	/**
	 * Perform
	 *
	 * @param A2C_Bridge $bridge
	 */
	public function Perform( A2C_Bridge $bridge ) {
		$result = array();
		$request = $bridge->getRequest();
		$parameters = $request->get_params();

		if (isset($parameters['files'])) {

			foreach ( $parameters['files'] as $fileInfo ) {
				$result[ $fileInfo['id'] ] = $this->_saveFile( sanitize_text_field( $fileInfo['source'] ),
					sanitize_text_field( $fileInfo['target'] ),
					(int) $fileInfo['width'],
					(int) $fileInfo['height'] );
			}
		}

		return serialize( $result );
	}

}

/**
 * Class A2C_Bridge_Action_Basedirfs
 */
class A2C_Bridge_Action_Basedirfs {


	/**
	 * Perform
	 *
	 * @param A2C_Bridge $bridge
	 */
	public function Perform( A2C_Bridge $bridge ) {
		echo esc_html(A2CBC_STORE_BASE_DIR);
	}

}

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'A2CBC_BRIDGE_VERSION', '167' );
define( 'A2CBC_BRIDGE_CHECK_REQUEST_KEY_LINK', 'http://app.api2cart.com/request/key/check' );
define( 'A2CBC_BRIDGE_DIRECTORY_NAME', basename( getcwd() ) );
define( 'A2CBC_BRIDGE_PUBLIC_KEY', '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzndeF8B8e+ElYjoRTBaC
aNySz31gK3zasphhRlIOT6LwKQFutTYmWgkd4id2XwZE/wglsgnUXXmPcPp4+OdR
6va+rgS3rtJA7I5DDj4oBVJ2cyOHgKtTnu5QnGXKM1nSBnx6t/BRZ+sVnTpKcyd0
WtGakDIrzaouvOBYWFS5AT6LgWZnFj408Qx+UmAFQDR5oFAlkdCb1kUWSYhV1eNG
vEAfIeDnFCbi+oyuzvMoMF8mFaNJ2MWfoPVHJl4TqnqK4RLTaYf3KLVAZrxniMQ2
lN75G21rFG3ammcPtqiXiD6N034N0AviHCc2EkmmcFspH9qmOy/ITOyHHs1w9NNx
uwIDAQAB
-----END PUBLIC KEY-----
');
define('A2CBC_BRIDGE_PUBLIC_KEY_ID', '4947ae9e4d350512cd240ec52d60c3a1');
define('A2CBC_BRIDGE_ENABLE_ENCRYPTION', extension_loaded('openssl'));

A2C_show_error( 0 );

require_once 'config.php';

if ( ! defined( 'A2CBC_TOKEN' ) ) {
	die( 'ERROR_TOKEN_NOT_DEFINED' );
}

if ( strlen( A2CBC_TOKEN ) !== 32 ) {
	die( 'ERROR_TOKEN_LENGTH' );
}

function A2C_show_error( $status ) {
	if ( $status ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
			if ( substr( phpversion(), 0, 1 ) >= 5 ) {
				error_reporting( E_ALL & ~E_STRICT );
				return;
			} else {
				error_reporting( E_ALL );
				return;
			}
		}
	}

	error_reporting( 0 );
}

/**
 * A2C_exceptions_error_handler
 *
 * @param $severity
 * @param $message
 * @param $filename
 * @param $lineno
 *
 * @throws ErrorException
 */
function A2C_exceptions_error_handler( $severity, $message, $filename, $lineno ) {
	if ( error_reporting() === 0 ) {
		return;
	}

	if ( strpos( $message, 'Declaration of' ) === 0 ) {
		return;
	}

	if ( error_reporting() & $severity ) {
		throw new ErrorException( $message, 0, $severity, $filename, $lineno );
	}
}

set_error_handler( 'A2C_exceptions_error_handler' );

/**
 * A2C_getPHPExecutable
 *
 * @return boolean|mixed|string
 */
function A2C_getPHPExecutable() {
	$paths   = explode( PATH_SEPARATOR, getenv( 'PATH' ) );
	$paths[] = PHP_BINDIR;
	foreach ( $paths as $path ) {
		// we need this for XAMPP (Windows)
		if ( isset( $_SERVER['WINDIR'] ) && strstr( $path, 'php.exe' ) && file_exists( $path ) && is_file( $path ) ) {
			return $path;
		} else {
			$phpExecutable = $path . DIRECTORY_SEPARATOR . 'php' . ( isset( $_SERVER['WINDIR'] ) ? '.exe' : '' );
			if ( file_exists( $phpExecutable ) && is_file( $phpExecutable ) ) {
				return $phpExecutable;
			}
		}
	}

	return false;
}

/**
 * A2C_swapLetters
 *
 * @param $input
 *
 * @return string
 */
function A2C_swapLetters( $input ) {
	$default = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
	$custom  = 'ZYXWVUTSRQPONMLKJIHGFEDCBAzyxwvutsrqponmlkjihgfedcba9876543210+/';

	return strtr( $input, $default, $custom );
}

/**
 * A2C_encrypt
 *
 * @param string $data Data to encrypt
 *
 * @return string
 */
function A2C_encrypt( $data ) {
	if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
		$len = 2048/8 - 42;
		$data = str_split( gzcompress( $data ), $len );
		$result = '';

		foreach ( $data as $d ) {
			if ( openssl_public_encrypt( $d, $encrypted, A2CBC_BRIDGE_PUBLIC_KEY, OPENSSL_PKCS1_OAEP_PADDING ) ) {
				$result .= $encrypted;
			} else {
				throw new Exception( esc_html__( 'ERROR_ENCRYPT', 'api2cart-bridge-connector' ) );
			}
		}

		return bin2hex( $result );
	} else {
		return base64_encode( $data );
	}
}

/**
 *  A2C_decrypt
 *
 * @param string $data    Data to decrypt
 * @param false  $encoded decode data flag
 *
 * @return false|mixed|string
 */
function A2C_decrypt( $data, $encoded = false ) {
	if ( A2CBC_BRIDGE_ENABLE_ENCRYPTION ) {
		if ( $encoded ) {
			$data = @hex2bin( $data );

			if ( empty( $data ) ) {
				throw new Exception( esc_html__( 'ERROR_INVALID_HEXDECIMAL_VALUE', 'api2cart-bridge-connector' ) );
			}
		}

		$data = str_split( $data, 256 );
		$result = '';

		foreach ( $data as $d ) {
			if ( openssl_public_decrypt( $d, $decrypted, A2CBC_BRIDGE_PUBLIC_KEY ) ) {
				$result .= $decrypted;
			} else {
				throw new Exception( esc_html__( 'ERROR_DECRYPT', 'api2cart-bridge-connector' ) );
			}
		}

		return gzuncompress( $result );
	} else {
		return $data;
	}
}
