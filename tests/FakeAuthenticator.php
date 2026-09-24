<?php
/**
 * A token table for the tests.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp\Tests;

use SugarCommerce\Mcp\Authenticator;
use SugarCommerce\Mcp\Identity;

/**
 * Class FakeAuthenticator
 *
 * @since 1.0.0
 */
final readonly class FakeAuthenticator implements Authenticator {

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function authenticate( #[\SensitiveParameter] string $token ): ?Identity {
		// Constant-time even in a fixture, because it is the thing people
		// copy out of a test into production.
		return match ( true ) {
			hash_equals( 'admin-token', $token )  => new Identity( 'admin', array( '*' ), 'Admin' ),
			hash_equals( 'reader-token', $token ) => new Identity( 'reader', array( 'read' ), 'Reader' ),
			default                               => null,
		};
	}
}
