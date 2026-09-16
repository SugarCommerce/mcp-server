<?php
/**
 * The authenticated caller.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp;

/**
 * Class Identity
 *
 * Who is calling and what they are allowed to do.
 *
 * Credentials are per-request input, not connection state — which is why
 * the tool list may legitimately differ between two callers while never
 * differing between two connections from the same caller.
 *
 * @since 1.0.0
 */
final readonly class Identity {

	/**
	 * @since 1.0.0
	 *
	 * @param string               $id       Stable identifier, for audit.
	 * @param string[]             $scopes   Granted scopes.
	 * @param string               $label    Human-readable name, for logs.
	 * @param array<string, mixed> $metadata Anything the application needs
	 *                                       to carry into a handler.
	 */
	public function __construct(
		public string $id,
		public array $scopes = array(),
		public string $label = '',
		public array $metadata = array(),
	) {}

	/**
	 * Whether this identity holds a scope.
	 *
	 * A caller holding `*` holds everything, which keeps a single-operator
	 * install from having to enumerate scopes it does not use.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope Scope name.
	 *
	 * @return bool
	 */
	public function has( string $scope ): bool {
		return in_array( '*', $this->scopes, true ) || in_array( $scope, $this->scopes, true );
	}

	/**
	 * Whether this identity holds every one of these scopes.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $scopes Required scopes.
	 *
	 * @return bool
	 */
	public function has_all( array $scopes ): bool {
		foreach ( $scopes as $scope ) {
			if ( ! $this->has( $scope ) ) {
				return false;
			}
		}

		return true;
	}
}
