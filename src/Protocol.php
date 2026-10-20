<?php
/**
 * Protocol constants and error codes.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp;

/**
 * Class Protocol
 *
 * Wire-level constants for the Model Context Protocol.
 *
 * @since 1.0.0
 */
final readonly class Protocol {

	/**
	 * The latest revision this server implements.
	 */
	public const string VERSION = '2026-07-28';

	/**
	 * Revisions that carry the version on every request ("modern").
	 */
	public const array MODERN = array( self::VERSION );

	/**
	 * Revisions that agree a version in `initialize` ("legacy"), newest
	 * first. Claude's and ChatGPT's connectors spoke these when this was
	 * written; 2025-03-26 and earlier are not served (no structured tool
	 * output, no protocol version header).
	 */
	public const array LEGACY = array( '2025-11-25', '2025-06-18' );

	/**
	 * Every revision served, newest first.
	 */
	public const array SUPPORTED = array( self::VERSION, '2025-11-25', '2025-06-18' );

	/* ─── `_meta` keys reserved by the specification ────────────────── */

	public const string META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
	public const string META_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';
	public const string META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
	public const string META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

	/* ─── JSON-RPC 2.0 error codes ──────────────────────────────────── */

	public const int PARSE_ERROR = -32700;
	public const int INVALID_REQUEST = -32600;
	public const int METHOD_NOT_FOUND = -32601;
	public const int INVALID_PARAMS = -32602;
	public const int INTERNAL_ERROR = -32603;

	/* ─── Codes defined by MCP ──────────────────────────────────────── */

	/**
	 * The `MCP-Protocol-Version` header disagrees with the body.
	 */
	public const int HEADER_MISMATCH = -32020;

	/**
	 * The request needs a client capability that was not declared.
	 */
	public const int MISSING_CLIENT_CAPABILITY = -32021;

	/**
	 * The requested protocol revision is not one this server speaks.
	 */
	public const int UNSUPPORTED_PROTOCOL_VERSION = -32022;

	/**
	 * Codes retired by this revision, which must never be emitted.
	 *
	 * `-32002` meant "resource not found" up to 2025-11-25 and is now
	 * `-32602`; `-32042` was a 2025-11-25-only elicitation code. Both
	 * remain reserved so they cannot be reused for something else.
	 */
	public const array RETIRED = array( -32002, -32042 );

	/**
	 * Whether a protocol revision is one this server speaks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $version Revision string.
	 *
	 * @return bool
	 */
	public static function supports( string $version ): bool {
		return in_array( $version, self::SUPPORTED, true );
	}

	/**
	 * Whether a revision is a modern one: stateless, `_meta` on every request.
	 *
	 * @since 1.3.0
	 *
	 * @param string $version Revision string.
	 *
	 * @return bool
	 */
	public static function modern( string $version ): bool {
		return in_array( $version, self::MODERN, true );
	}

	/**
	 * The revision to answer an `initialize` with.
	 *
	 * The one asked for when this server speaks it in that era, otherwise
	 * the latest legacy one (2025-11-25, "Version Negotiation").
	 *
	 * @since 1.3.0
	 *
	 * @param string $requested The client's `protocolVersion`.
	 *
	 * @return string
	 */
	public static function negotiate( string $requested ): string {
		return in_array( $requested, self::LEGACY, true ) ? $requested : self::LEGACY[0];
	}
}
