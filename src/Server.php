<?php
/**
 * The MCP server.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp;

/**
 * Class Server
 *
 * Dispatches JSON-RPC messages for the 2026-07-28 revision, and for the
 * two before it (2025-11-25, 2025-06-18) that clients still send — a
 * "dual-era" server in the 2026-07-28 spec's words.
 *
 * The revision is stateless by design: nothing is remembered between
 * requests, and every request must carry its own protocol version and
 * client capabilities. That makes the server trivially horizontally
 * scalable and, more usefully here, trivially testable — {@see handle()}
 * is a pure function from a decoded message to a {@see Response}, with no
 * superglobals and no output.
 *
 * @since 1.0.0
 */
final class Server {

	/**
	 * How long a client may cache a list or a discovery answer: five
	 * minutes. The tools only change when the code is deployed.
	 */
	public const int TTL_MS = 300_000;

	/**
	 * Registered tools, keyed by name.
	 *
	 * @var array<string, Tool>
	 */
	private array $tools = array();

	/**
	 * Called after every tool invocation.
	 *
	 * @var (callable(string, array<string, mixed>, Identity|null, bool): void)|null
	 */
	private mixed $auditor = null;

	/**
	 * @since 1.0.0
	 *
	 * @param Implementation     $info          How this server identifies itself.
	 * @param Authenticator|null $authenticator Credential verification, or
	 *                                          null to accept every caller —
	 *                                          only ever right for stdio or a
	 *                                          loopback-bound process.
	 * @param string             $instructions  Guidance returned by
	 *                                          `initialize`, describing what
	 *                                          this server is for.
	 */
	public function __construct(
		private readonly Implementation $info,
		private readonly ?Authenticator $authenticator = null,
		private readonly string $instructions = '',
	) {}

	/* ─── Registration ──────────────────────────────────────────────── */

	/**
	 * Register a tool.
	 *
	 * @since 1.0.0
	 *
	 * @param Tool $tool Tool.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException On a duplicate name.
	 */
	public function add( Tool $tool ): self {
		if ( isset( $this->tools[ $tool->name ] ) ) {
			throw new \InvalidArgumentException( 'A tool named "' . $tool->name . '" is already registered.' );
		}

		$this->tools[ $tool->name ] = $tool;

		return $this;
	}

	/**
	 * Record every tool call.
	 *
	 * The callback receives the tool name, the arguments, the caller and
	 * whether it succeeded. Tool calls are arbitrary code execution
	 * against your data on behalf of a language model; log them.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(string, array<string, mixed>, Identity|null, bool): void $auditor
	 *        fn(string $tool, array $arguments, ?Identity $identity, bool $ok): void
	 *
	 * @return self
	 */
	public function audit( callable $auditor ): self {
		$this->auditor = $auditor;

		return $this;
	}

	/**
	 * Every registered tool.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, Tool>
	 */
	public function tools(): array {
		return $this->tools;
	}

	/**
	 * The scopes a caller lacks for the tool a message calls.
	 *
	 * For a resource server that answers a call it cannot make with the
	 * MCP step-up challenge — 403 and `insufficient_scope` — rather than the
	 * tool-not-found error handle() gives. handle() never reveals that a
	 * hidden tool exists, which is right for tools whose names are private;
	 * an application whose tool list is public (documented, say) can
	 * challenge for the scopes it knows a client may be granted, and must
	 * not for any other.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $message  Decoded JSON-RPC message.
	 * @param Identity|null        $identity The caller.
	 *
	 * @return list<string> Empty when the message is not a `tools/call`, names
	 *                  no registered tool, or the caller holds every scope.
	 */
	public function missing_scopes( array $message, ?Identity $identity ): array {
		if ( 'tools/call' !== ( $message['method'] ?? null ) ) {
			return array();
		}

		$params = $message['params'] ?? null;
		$name   = is_array( $params ) ? $params['name'] ?? null : null;
		$tool = is_string( $name ) ? $this->tools[ $name ] ?? null : null;

		if ( null === $tool || $tool->permits( $identity ) ) {
			return array();
		}

		return array_values( array_filter( $tool->scopes, static fn ( string $scope ): bool => null === $identity || ! $identity->has( $scope ) ) );
	}

	/* ─── Dispatch ──────────────────────────────────────────────────── */

	/**
	 * Handle one decoded message.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $message Decoded JSON-RPC message.
	 * @param string               $token   Bearer token from the request.
	 * @param string|null          $header_version The `MCP-Protocol-Version`
	 *                                             header, when present.
	 *
	 * @return Response
	 */
	public function handle( array $message, #[\SensitiveParameter] string $token = '', ?string $header_version = null ): Response {
		$request = Request::from_array( $message );

		if ( '' === $request->method ) {
			return Response::error( null, Protocol::INVALID_REQUEST, 'The message has no method.', null, 400 );
		}

		// A notification is one-way: the receiver must not answer it.
		if ( $request->is_notification() ) {
			return Response::accepted();
		}

		$identity = null;

		if ( null !== $this->authenticator ) {
			$identity = '' === $token ? null : $this->authenticator->authenticate( $token );

			if ( null === $identity ) {
				return Response::error(
					$request->id,
					Protocol::INVALID_REQUEST,
					'Unauthorized. Supply a valid Authorization: Bearer token.',
					null,
					401
				);
			}
		}

		// The legacy handshake: how a client on 2025-11-25 or earlier opens.
		// It carries no per-request version, so it is answered before one
		// is looked for.
		if ( 'initialize' === $request->method ) {
			return $this->initialize( $request );
		}

		// Discovery answers whatever version the client speaks: it is how
		// a client learns which to speak.
		if ( 'server/discover' === $request->method ) {
			return $this->discover( $request );
		}

		$version = $this->version( $request, $header_version );

		if ( $version instanceof Response ) {
			return $version;
		}

		return match ( $request->method ) {
			// Removed in 2026-07-28; kept for the revisions that have it.
			'ping'       => Protocol::modern( $version )
				? Response::error( $request->id, Protocol::METHOD_NOT_FOUND, 'Method not found: ping' )
				: $this->reply( $request, $version, array() ),
			'tools/list' => $this->list_tools( $request, $identity, $version ),
			'tools/call' => $this->call_tool( $request, $identity, $version ),
			default      => Response::error(
				$request->id,
				Protocol::METHOD_NOT_FOUND,
				'Method not found: ' . $request->method
			),
		};
	}

	/**
	 * The revision a request is in, or the refusal.
	 *
	 * Two eras on one endpoint (MCP 2026-07-28, "Versioning and
	 * Compatibility"):
	 *
	 * - **Modern** (2026-07-28): the version is in the request's `_meta`,
	 *   with the client's capabilities, and the `MCP-Protocol-Version`
	 *   header, when sent, must match it.
	 * - **Legacy** (2025-11-25, 2025-06-18): the version was agreed in
	 *   `initialize` and every later request carries it in the header.
	 *   Nothing is remembered between requests — no session is ever
	 *   assigned, which those revisions allow — so the header is the whole
	 *   of what this server knows.
	 *
	 * A request with neither is refused rather than read as 2025-03-26 (as
	 * the legacy transport would allow): this server does not speak it.
	 *
	 * @since 1.3.0
	 *
	 * @param Request     $request        Request.
	 * @param string|null $header_version `MCP-Protocol-Version` header.
	 *
	 * @return string|Response The revision, or the error to send.
	 */
	private function version( Request $request, ?string $header_version ): string|Response {
		$header  = null === $header_version ? '' : trim( $header_version );
		$version = $request->protocol_version();

		if ( '' === $version && '' === $header ) {
			return Response::error(
				$request->id,
				Protocol::INVALID_PARAMS,
				'Missing required _meta field: ' . Protocol::META_PROTOCOL_VERSION . ' (or, for a client on ' . implode( ' or ', Protocol::LEGACY ) . ', the MCP-Protocol-Version header).',
				array( 'supported' => Protocol::SUPPORTED ),
				400
			);
		}

		if ( '' !== $version && '' !== $header && $header !== $version ) {
			return Response::error(
				$request->id,
				Protocol::HEADER_MISMATCH,
				'The MCP-Protocol-Version header does not match the version in the request body.',
				array( 'header' => $header, 'body' => $version ),
				400
			);
		}

		$version = '' === $version ? $header : $version;

		if ( ! Protocol::supports( $version ) ) {
			return Response::error(
				$request->id,
				Protocol::UNSUPPORTED_PROTOCOL_VERSION,
				'Unsupported protocol version: ' . $version,
				array( 'supported' => Protocol::SUPPORTED, 'requested' => $version ),
				400
			);
		}

		if ( Protocol::modern( $version ) && null === $request->client_capabilities() ) {
			return Response::error(
				$request->id,
				Protocol::INVALID_PARAMS,
				'Missing required _meta field: ' . Protocol::META_CLIENT_CAPABILITIES,
				null,
				400
			);
		}

		return $version;
	}

	/**
	 * A result, shaped for the revision.
	 *
	 * 2026-07-28 requires `resultType` on every result and asks servers to
	 * name themselves in `_meta`; the earlier revisions have neither.
	 *
	 * @since 1.3.0
	 *
	 * @param Request              $request Request.
	 * @param string               $version Revision.
	 * @param array<string, mixed> $result  Payload.
	 *
	 * @return Response
	 */
	private function reply( Request $request, string $version, array $result ): Response {
		return Protocol::modern( $version )
			? Response::result( $request->id, $result, $this->info )
			: Response::legacy( $request->id, $result );
	}

	/* ─── Methods ───────────────────────────────────────────────────── */

	/**
	 * Answer `initialize`, the legacy handshake.
	 *
	 * Negotiated as 2025-11-25 says: the requested revision when this
	 * server speaks it, otherwise the latest legacy one it does — never
	 * 2026-07-28, which has no `initialize`. A client that cannot speak the
	 * answer disconnects, which is the spec's own fallback.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Negotiates a legacy revision instead of refusing one.
	 *
	 * @param Request $request Request.
	 *
	 * @return Response
	 */
	private function initialize( Request $request ): Response {
		$result = array(
			'protocolVersion' => Protocol::negotiate( $request->string( 'protocolVersion' ) ),
			'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
			'serverInfo'      => $this->info->to_array(),
		);

		if ( '' !== $this->instructions ) {
			$result['instructions'] = $this->instructions;
		}

		return Response::legacy( $request->id, $result );
	}

	/**
	 * Answer `server/discover` (2026-07-28): versions, capabilities, identity.
	 *
	 * @since 1.3.0
	 *
	 * @param Request $request Request.
	 *
	 * @return Response
	 */
	private function discover( Request $request ): Response {
		$result = array(
			'supportedVersions' => Protocol::SUPPORTED,
			'capabilities'      => array( 'tools' => new \stdClass() ),
			'ttlMs'             => self::TTL_MS,
			'cacheScope'        => 'public',
		);

		if ( '' !== $this->instructions ) {
			$result['instructions'] = $this->instructions;
		}

		return Response::result( $request->id, $result, $this->info );
	}

	/**
	 * Answer `tools/list`.
	 *
	 * The set may vary by the caller's scopes — credentials are
	 * per-request input — but never by connection, and the order is
	 * stable so clients can cache it. 2026-07-28 makes the list a
	 * cacheable result: `ttlMs`, and `cacheScope: private`, because what
	 * one caller sees is not what the next does.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 `$version`.
	 *
	 * @param Request       $request  Request.
	 * @param Identity|null $identity Caller.
	 * @param string        $version  Revision.
	 *
	 * @return Response
	 */
	private function list_tools( Request $request, ?Identity $identity, string $version ): Response {
		$definitions = array();

		foreach ( $this->tools as $tool ) {
			if ( $tool->permits( $identity ) ) {
				$definitions[] = $tool->definition();
			}
		}

		$result = array( 'tools' => $definitions );

		if ( Protocol::modern( $version ) ) {
			$result += array( 'ttlMs' => self::TTL_MS, 'cacheScope' => 'private' );
		}

		return $this->reply( $request, $version, $result );
	}

	/**
	 * Answer `tools/call`. The result's shape is the same in every revision
	 * this server speaks (structured content arrived in 2025-06-18).
	 *
	 * @since 1.0.0
	 * @since 1.3.0 `$version`.
	 *
	 * @param Request       $request  Request.
	 * @param Identity|null $identity Caller.
	 * @param string        $version  Revision.
	 *
	 * @return Response
	 */
	private function call_tool( Request $request, ?Identity $identity, string $version ): Response {
		$name = $request->string( 'name' );
		$tool = $this->tools[ $name ] ?? null;

		// An unknown tool, and one the caller may not see, are answered
		// identically. Distinguishing them would let a caller enumerate
		// the tools they are not entitled to.
		if ( null === $tool || ! $tool->permits( $identity ) ) {
			return Response::error( $request->id, Protocol::INVALID_PARAMS, 'Unknown tool: ' . $name );
		}

		// Validated as sent, so a list where an object belongs is refused
		// rather than quietly emptied; handed on as the object's members.
		$problems  = Schema::validate( $request->array( 'arguments' ), $tool->input_schema );
		$arguments = $request->object( 'arguments' );

		// An invalid argument is the model's mistake and the model can fix
		// it, so it comes back as a tool execution error rather than a
		// protocol error it cannot act on.
		if ( array() !== $problems ) {
			$this->record( $tool->name, $arguments, $identity, false );

			return $this->reply( $request, $version, ToolResult::failed( 'Invalid arguments: ' . implode( ' ', $problems ) )->to_array() );
		}

		try {
			$result = $tool->call( $arguments, $identity );
		} catch ( \Throwable $e ) {
			$this->record( $tool->name, $arguments, $identity, false );

			// The message is deliberately generic: an exception can carry a
			// file path, a query, or a credential, and this response goes
			// to a language model and often into a transcript.
			return $this->reply( $request, $version, ToolResult::failed( 'The tool failed to run.' )->to_array() );
		}

		$this->record( $tool->name, $arguments, $identity, ! $result->is_error );

		return $this->reply( $request, $version, $result->to_array() );
	}

	/**
	 * Hand a call to the auditor, if one is set.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $tool      Tool name.
	 * @param array<string, mixed> $arguments Arguments.
	 * @param Identity|null        $identity  Caller.
	 * @param bool                 $ok        Whether it succeeded.
	 *
	 * @return void
	 */
	private function record( string $tool, array $arguments, ?Identity $identity, bool $ok ): void {
		if ( null !== $this->auditor ) {
			( $this->auditor )( $tool, $arguments, $identity, $ok );
		}
	}
}
