<?php namespace Intraxia\Jaxion\Core;

use Intraxia\Jaxion\Contract\Core\Application as ApplicationContract;
use Intraxia\Jaxion\Contract\Core\HasActions;
use Intraxia\Jaxion\Contract\Core\HasFilters;
use Intraxia\Jaxion\Contract\Core\HasShortcode;
use Intraxia\Jaxion\Contract\Core\Loader as LoaderContract;
use UnexpectedValueException;

/**
 * Class Application
 *
 * @package Intraxia\Jaxion
 */
class Application extends Container implements ApplicationContract {
	/**
	 * Define plugin version on Application.
	 */
	const VERSION = '';

	/**
	 * Singleton instance of the Application object
	 *
	 * @var Application[]
	 */
	protected static $instances = array();

	/**
	 * Instantiates a new Application container.
	 *
	 * The Application constructor enforces the presence of of a single instance
	 * of the Application. If an instance already exists, an Exception will be thrown.
	 *
	 * @param string $file
	 * @param array  $providers
	 *
	 * @throws ApplicationAlreadyBootedException
	 */
	public function __construct( $file, array $providers = array() ) {
		if ( isset( static::$instances[ get_called_class() ] ) ) {
			throw new ApplicationAlreadyBootedException;
		}

		static::$instances[ get_called_class() ] = $this;

		$this->register_constants( $file );
		$this->register_core_services();

		register_activation_hook( $file, array( $this, 'activate' ) );
		register_deactivation_hook( $file, array( $this, 'deactivate' ) );

		parent::__construct( $providers );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws UnexpectedValueException
	 */
	public function boot() {
		$loader = $this->fetch( 'loader' );

		if ( ! $loader instanceof LoaderContract ) {
			throw new UnexpectedValueException;
		}

		foreach ( $this as $alias => $value ) {
			if ( $value instanceof HasActions ) {
				$loader->register_actions( $value );
			}

			if ( $value instanceof HasFilters ) {
				$loader->register_filters( $value );
			}

			if ( $value instanceof HasShortcode ) {
				$loader->register_shortcode( $value );
			}
		}

		add_action( 'plugins_loaded', array( $loader, 'run' ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @codeCoverageIgnore
	 */
	public function activate() {
		// no-op
	}

	/**
	 * {@inheritdoc}
	 *
	 * @codeCoverageIgnore
	 */
	public function deactivate() {
		// no-op
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Application
	 * @throws ApplicationNotBootedException
	 */
	public static function instance() {
		if ( ! isset( static::$instances[ get_called_class() ] ) ) {
			throw new ApplicationNotBootedException;
		}

		return static::$instances[ get_called_class() ];
	}

	/**
	 * {@inheritDoc}
	 */
	public static function shutdown() {
		if ( isset( static::$instances[ get_called_class() ] ) ) {
			unset( static::$instances[ get_called_class() ] );
		}
	}

	/**
	 * Sets the plugin's url, path, and basename.
	 *
	 * @param string $file
	 */
	private function register_constants( $file ) {
		$this->share( 'file', $file );
		$this->share( 'url', plugin_dir_url( $file ) );
		$this->share( 'path', plugin_dir_path( $file ) );
		$this->share( 'basename', $basename = plugin_basename( $file ) );
		$this->share( 'slug', dirname( $basename ) );
		$this->share( 'version', static::VERSION );
	}

	/**
	 * Registers the built-in services with the Application container.
	 */
	private function register_core_services() {
		$this->share( array( 'loader' => 'Intraxia\Jaxion\Contract\Core\Loader' ), function ( $app ) {
			return new Loader( $app );
		} );
		$this->share( array( 'i18n' => 'Intaxia\Jaxion\Contract\Core\I18n' ), function ( $app ) {
			return new I18n( $app->fetch( 'basename' ), $app->fetch( 'path' ) );
		} );
	}
}
