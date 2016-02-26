<?php
namespace Intraxia\Jaxion\Axolotl;

use stdClass;
use WP_Post;

/**
 * Class Model
 *
 * Shared model methods and properties, allowing models
 * to transparently map some attributes to an underlying WP_Post
 * object and others to postmeta or a custom table.
 *
 * @package    Intraxia\Jaxion
 * @subpackage Axolotl
 * @since      0.1.0
 */
abstract class Model {
	/**
	 * Model attributes.
	 *
	 * @var array
	 */
	private $attributes = array(
		'table' => array(),
		'post'  => null,
	);

	/**
	 * Model's original attributes.
	 *
	 * @var array
	 */
	private $original = array(
		'table' => array(),
		'post'  => null,
	);

	/**
	 * Which custom table does this model uses.
	 *
	 * If false, model wil fall back to postmeta.
	 *
	 * @var bool|string
	 */
	protected $table = false;

	/**
	 * Whether to use WP_Post mappings.
	 *
	 * @var bool
	 */
	protected $post = true;

	/**
	 * Custom post type.
	 *
	 * @var bool|string
	 */
	protected $type = false;

	/**
	 * Properties which are allowed to be set on the model.
	 *
	 * If this array is empty, any attributes can be set on the model.
	 *
	 * @var string[]
	 */
	protected $fillable = array();

	/**
	 * Properties which cannot be automatically filled on the model.
	 *
	 * If the model is unguarded, these properties can be filled.
	 *
	 * @var array
	 */
	protected $guarded = array();

	/**
	 * Properties which should not be serialized.
	 *
	 * @var array
	 */
	protected $hidden = array();

	/**
	 * Properties which should be serialized.
	 *
	 * @var array
	 */
	protected $visible = array();

	/**
	 * Whether the model's properties are guarded.
	 *
	 * When false, allows guarded properties to be filled.
	 *
	 * @var bool
	 */
	protected $is_guarded = true;

	/**
	 * Constructs a new model with provided attributes.
	 *
	 * If 'post' is passed as one of the attributes, the underlying post
	 * will be overwritten.
	 *
	 * @param array <string, mixed> $attributes
	 */
	public function __construct( array $attributes = array() ) {
		$this->sync_original();

		if ( $this->post ) {
			$this->create_default_post();
		}

		$this->refresh( $attributes );
	}

	/**
	 * Refreshes the model's current attributes with the provided array.
	 *
	 * The model's attributes will match what was provided in the array,
	 * and any attributes not passed
	 *
	 * @param array $attributes
	 */
	public function refresh( array $attributes ) {
		$this->clear();

		foreach ( $attributes as $name => $value ) {
			$this->set_attribute( $name, $value );
		}
	}

	/**
	 * Get the model's attributes.
	 *
	 * Returns the array of for the model that will either need to be
	 * saved in postmeta or a separate table.
	 *
	 * @return array
	 */
	public function get_attributes() {
		return $this->attributes['table'];
	}

	/**
	 * Get the model's original attributes.
	 *
	 * @return array
	 */
	public function get_original_attributes() {
		return $this->original['table'];
	}

	/**
	 * Get the model's underlying post.
	 *
	 * Returns the underlying WP_Post object for the model, representing
	 * the data that will be save in the wp_posts table.
	 *
	 * @return false|WP_Post
	 */
	public function get_underlying_post() {
		if ( isset( $this->attributes['post'] ) ) {
			return $this->attributes['post'];
		}

		return false;
	}

	/**
	 * Get the model's original underlying post.
	 *
	 * @return WP_Post
	 */
	public function get_original_post() {
		return $this->original['post'];
	}

	/**
	 * Magic __set method.
	 *
	 * Passes the name and value to set_attribute, which is where the magic happens.
	 *
	 * @param string $name
	 * @param mixed  $value
	 */
	public function __set( $name, $value ) {
		$this->set_attribute( $name, $value );
	}

	/**
	 * Sets the model attributes.
	 *
	 * Checks whether the model attribute can be set, check if it
	 * maps to the WP_Post property, otherwise, assigns it to the
	 * table attribute array.
	 *
	 * @param string $name
	 * @param mixed  $value
	 *
	 * @return $this
	 */
	public function set_attribute( $name, $value ) {
		if ( 'post' === $name ) {
			return $this->override_post( $value );
		}

		if ( ! $this->is_fillable( $name ) ) {
			return $this;
		}

		if ( $method = $this->has_map_method( $name ) ) {
			$this->attributes['post']->{$this->{$method}()} = $value;
		} else {
			$this->attributes['table'][ $name ] = $value;
		}

		return $this;
	}

	/**
	 * Retrieves all the attribute keys for the model.
	 *
	 * @todo memoize this method
	 *
	 * @return array
	 */
	public function get_attribute_keys() {
		return array_unique( array_merge( $this->fillable, $this->guarded, $this->hidden, $this->visible ) );
	}

	/**
	 * Retrieves the attribute keys that aren't mapped to a post.
	 *
	 * @todo memoize this method
	 *
	 * @return array
	 */
	public function get_table_keys() {
		$keys = array();

		foreach ( $this->get_attribute_keys() as $key ) {
			if ( ! $this->has_map_method( $key ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Retrieves the attribute keys that are mapped to a post.
	 *
	 * @todo memoize this method
	 *
	 * @return array
	 */
	public function get_post_keys() {
		$keys = array();

		foreach ( $this->get_attribute_keys() as $key ) {
			if ( $this->has_map_method( $key ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Serializes the model's public data into an array.
	 *
	 * @return array
	 */
	public function serialize() {
		$attributes = array();

		if ( $this->visible ) {
			// If visible attributes are set, we'll only reveal those.
			foreach ( $this->visible as $key ) {
				$attributes[ $key ] = $this->get_attribute( $key );
			}
		} elseif ( $this->hidden ) {
			// If hidden attributes are set, we'll grab everything and hide those.
			foreach ( $this->get_attribute_keys() as $key ) {
				if ( ! in_array( $key, $this->hidden ) ) {
					$attributes[ $key ] = $this->get_attribute( $key );
				}
			}
		} else {
			// If nothing is hidden/visible, we'll grab and reveal everything.
			foreach ( $this->get_attribute_keys() as $key ) {
				$attributes[ $key ] = $this->get_attribute( $key );
			}
		}

		return $attributes;
	}

	/**
	 * Syncs the current attributes to the model's original.
	 *
	 * @return $this
	 */
	public function sync_original() {
		$this->original = $this->attributes;

		if ( $this->attributes['post'] instanceof WP_Post ) {
			$this->original['post'] = clone $this->attributes['post'];
		}

		return $this;
	}

	/**
	 * Checks if a given attribute is mass-fillable.
	 *
	 * Returns true if the attribute can be filled, false if it can't.
	 *
	 * @param string $name
	 *
	 * @return bool
	 */
	private function is_fillable( $name ) {
		// `type` is not fillable at all.
		if ( 'type' === $name ) {
			return false;
		}

		// If the `$fillable` array hasn't been defined, pass everything.
		if ( ! $this->fillable ) {
			return true;
		}

		// If this model isn't guarded, everything is fillable.
		if ( ! $this->is_guarded ) {
			return true;
		}

		return in_array( $name, $this->fillable );
	}

	/**
	 * Overrides the current WP_Post with a provided one.
	 *
	 * Resets the post's default values and stores it in the attributes.
	 *
	 * @param WP_Post $value
	 *
	 * @return $this
	 */
	private function override_post( WP_Post $value ) {
		$this->attributes['post'] = $this->enforce_post_defaults( $value );

		return $this;
	}

	/**
	 * Create and set with a new blank post.
	 *
	 * Creates a new WP_Post object, assigns it the default attributes,
	 * and stores it in the attributes.
	 */
	private function create_default_post() {
		$this->attributes['post'] = $this->enforce_post_defaults( new WP_Post( new stdClass ) );
	}

	/**
	 * Enforces values on the post that can't change.
	 *
	 * Primarily, this is used to make sure the post_type always maps
	 * to the model's "$type" property, but this can all be overridden
	 * by the developer to enforce other values in the model.
	 *
	 * @param WP_Post $post
	 *
	 * @return WP_Post
	 */
	protected function enforce_post_defaults( WP_Post $post ) {
		if ( is_string( $this->type ) ) {
			$post->post_type = $this->type;
		}

		return $post;
	}

	/**
	 * Magic __get method.
	 *
	 * Passes the name and value to get_attribute, which is where the magic happens.
	 *
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function __get( $name ) {
		return $this->get_attribute( $name );
	}

	/**
	 * Retrieves the model attribute.
	 *
	 * If the attribute maps to the WP_Post, retrieves it from there.
	 * Otherwise, checks if it's in the attributes array
	 *
	 * @param string $name
	 *
	 * @return mixed
	 *
	 * @throws PropertyDoesNotExistException If property isn't found.
	 */
	public function get_attribute( $name ) {
		if ( 'type' === $name ) {
			return $this->type;
		}

		if ( $method = $this->has_map_method( $name ) ) {
			$value = $this->attributes['post']->{$this->{$method}()};
		} elseif ( $method = $this->has_compute_method( $name ) ) {
			$value = $this->{$method}();
		} else {
			if ( ! isset( $this->attributes['table'][ $name ] ) ) {
				throw new PropertyDoesNotExistException;
			}

			$value = $this->attributes['table'][ $name ];
		}

		return $value;

	}

	/**
	 * Checks whether the attribute has a map method.
	 *
	 * This is used to determine whether the attribute maps to a
	 * property on the underlying WP_Post object. Returns the
	 * method if one exists, returns false if it doesn't.
	 *
	 * @param string $name
	 *
	 * @return false|string
	 */
	protected function has_map_method( $name ) {
		if ( method_exists( $this, $method = "map_{$name}" ) ) {
			return $method;
		}

		return false;
	}

	/**
	 * Checks whether the attribute has a compute method.
	 *
	 * This is used to determine if the attribute should be computed
	 * from other attributes.
	 *
	 * @param string $name
	 *
	 * @return false|string
	 */
	protected function has_compute_method( $name ) {
		if ( method_exists( $this, $method = "compute_{$name}" ) ) {
			return $method;
		}

		return false;
	}

	/**
	 * Clears all the current attributes from the model.
	 *
	 * This does not touch the model's original attributes, and will
	 * only clear fillable attributes, unless the model is unguarded.
	 *
	 * @return $this
	 */
	public function clear() {
		$keys = $this->get_attribute_keys();

		if ( ! $keys ) {
			$keys = array_keys( $this->attributes['table'] );
		}

		foreach ( $keys as $key ) {
			$this->set_attribute( $key, null );
		}

		return $this;
	}

	/**
	 * Unguards the model.
	 *
	 * Sets the model to be unguarded, allowing the filling of
	 * guarded attributes.
	 */
	public function unguard() {
		$this->is_guarded = false;
	}

	/**
	 * Reguards the model.
	 *
	 * Sets the model to be guarded, preventing filling of
	 * guarded attributes.
	 */
	public function reguard() {
		$this->is_guarded = true;
	}
}