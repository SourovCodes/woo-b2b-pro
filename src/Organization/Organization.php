<?php
/**
 * Organization model.
 *
 * @package WooB2B
 */

namespace WooB2B\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Read-side model around the wb2b_organization post type. A customer belongs to
 * at most one organization via the _wb2b_organization_id user meta.
 */
final class Organization {

	public const POST_TYPE    = 'wb2b_organization';
	public const USER_META    = '_wb2b_organization_id';
	public const META_PREFIX  = '_wb2b_billing_';

	/**
	 * Billing address keys stored as post meta (WooCommerce naming, no prefix).
	 *
	 * @var string[]
	 */
	private const ADDRESS_KEYS = array(
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
		'email',
		'phone',
	);

	/**
	 * Per-request cache of user → organization lookups.
	 *
	 * @var array<int, int>  Maps user ID to organization ID (0 = none).
	 */
	private static $user_cache = array();

	/**
	 * Organization post ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Use find() / for_user().
	 *
	 * @param int $id Organization post ID.
	 */
	private function __construct( int $id ) {
		$this->id = $id;
	}

	/**
	 * Address keys handled by the plugin.
	 *
	 * @return string[]
	 */
	public static function address_keys(): array {
		return self::ADDRESS_KEYS;
	}

	/**
	 * Load a published organization by ID.
	 *
	 * @param int $id Post ID.
	 */
	public static function find( int $id ): ?Organization {
		if ( $id <= 0 ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		return new self( (int) $post->ID );
	}

	/**
	 * Load the organization a user belongs to, if any.
	 *
	 * @param int $user_id User ID.
	 */
	public static function for_user( int $user_id ): ?Organization {
		if ( $user_id <= 0 ) {
			return null;
		}

		if ( ! array_key_exists( $user_id, self::$user_cache ) ) {
			$organization_id                    = (int) get_user_meta( $user_id, self::USER_META, true );
			$organization                       = self::find( $organization_id );
			self::$user_cache[ $user_id ] = $organization ? $organization->id() : 0;
		}

		$cached = self::$user_cache[ $user_id ];
		return $cached > 0 ? new self( $cached ) : null;
	}

	/**
	 * Assign a user to an organization (a user belongs to one organization only).
	 *
	 * @param int $user_id    User ID.
	 * @param int $organization_id Organization post ID, 0 to unassign.
	 */
	public static function assign_user( int $user_id, int $organization_id ): void {
		if ( $organization_id > 0 ) {
			update_user_meta( $user_id, self::USER_META, $organization_id );
		} else {
			delete_user_meta( $user_id, self::USER_META );
		}
		unset( self::$user_cache[ $user_id ] );
	}

	/**
	 * Reset the per-request cache (used by tests and after meta writes).
	 */
	public static function flush_cache(): void {
		self::$user_cache = array();
	}

	/**
	 * Organization post ID.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Organization display name (post title).
	 */
	public function name(): string {
		return (string) get_the_title( $this->id );
	}

	/**
	 * A single billing address field.
	 *
	 * @param string $key One of address_keys(), or 'company' for the name.
	 */
	public function address_field( string $key ): string {
		if ( 'company' === $key ) {
			return $this->name();
		}
		if ( ! in_array( $key, self::ADDRESS_KEYS, true ) ) {
			return '';
		}
		return (string) get_post_meta( $this->id, self::META_PREFIX . $key, true );
	}

	/**
	 * Full billing address, WooCommerce-style keys plus 'company'.
	 *
	 * @return array<string,string>
	 */
	public function address(): array {
		$address = array( 'company' => $this->name() );
		foreach ( self::ADDRESS_KEYS as $key ) {
			$address[ $key ] = $this->address_field( $key );
		}
		return $address;
	}

	/**
	 * Whether a usable billing address has been entered. Enforcement is
	 * skipped for organizations without one so members are not locked out of
	 * checkout with an empty address.
	 */
	public function has_address(): bool {
		foreach ( array( 'address_1', 'city', 'postcode', 'country' ) as $key ) {
			if ( '' !== $this->address_field( $key ) ) {
				return true;
			}
		}
		return false;
	}
}
