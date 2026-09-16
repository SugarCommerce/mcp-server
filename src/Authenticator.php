<?php
/**
 * Credential verification contract.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp;

/**
 * Interface Authenticator
 *
 * Turns whatever arrived in the `Authorization` header into an
 * {@see Identity}, or nothing.
 *
 * Deliberately an interface: where the tokens live, how they are hashed
 * and what a scope means are all application decisions, and a library
 * that guessed at them would be wrong everywhere.
 *
 * An implementation **must** compare secrets with `hash_equals`. A `===`
 * on a token short-circuits at the first differing byte and leaks the
 * correct prefix through timing.
 *
 * @since 1.0.0
 */
interface Authenticator {

	/**
	 * Resolve a bearer token to a caller.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token Raw bearer token.
	 *
	 * @return Identity|null Null when the token is unknown, revoked or expired.
	 */
	public function authenticate( #[\SensitiveParameter] string $token ): ?Identity;
}
