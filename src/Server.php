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
 * Dispatches JSON-RPC messages for the 2026-07-28 revision.
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
	 * Registered tools, keyed by name.
	 *
	 * @var array<string, Tool>
	 */
	private array $tools = array();

	/**
	 * Called after every tool invocation.
	 *
	 * @var callable|null
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
	 * @param callable $auditor fn(string $tool, array $arguments, ?Identity $identity, bool $ok): void
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
	 * @return string[] Empty when the message is not a `tools/call`, names
	 *                  no registered tool, or the caller holds every scope.
	 */
	public function missing_scopes( array $message, ?Identity $identity ): array {
		if ( 'tools/call' !== ( $message['method'] ?? null ) ) {
			return array();
		}

		$name = $message['params']['name'] ?? null;
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

		$rejection = $this->check_protocol( $request, $header_version );

		if ( null !== $rejection ) {
			return $rejection;
		}

		return match ( $request->method ) {
			'initialize' => $this->initialize( $request ),
			'ping'       => Response::result( $request->id, array(), $this->info ),
			'tools/list' => $this->list_tools( $request, $identity ),
			'tools/call' => $this->call_tool( $request, $identity ),
			default      => Response::error(
				$request->id,
				Protocol::METHOD_NOT_FOUND,
				'Method not found: ' . $request->method
			),
		};
	}

	/**
	 * Enforce the per-request protocol metadata.
	 *
	 * Under the stateless model these fields are not optional and not
	 * inferable from an earlier call, so a request without them is
	 * malformed rather than merely unusual.
	 *
	 * @since 1.0.0
	 *
	 * @param Request     $request        Request.
	 * @param string|null $header_version `MCP-Protocol-Version` header.
	 *
	 * @return Response|null Null when the request is acceptable.
	 */
	private function check_protocol( Request $request, ?string $header_version ): ?Response {
		// `initialize` is how a client discovers the version, so it cannot
		// be required to already know it.
		if ( 'initialize' === $request->method ) {
			return null;
		}

		$version = $request->protocol_version();

		if ( '' === $version ) {
			return Response::error(
				$request->id,
				Protocol::INVALID_PARAMS,
				'Missing required _meta field: ' . Protocol::META_PROTOCOL_VERSION,
				null,
				400
			);
		}

		if ( null !== $header_version && '' !== $header_version && $header_version !== $version ) {
			return Response::error(
				$request->id,
				Protocol::HEADER_MISMATCH,
				'The MCP-Protocol-Version header does not match the version in the request body.',
				array( 'header' => $header_version, 'body' => $version ),
				400
			);
		}

		if ( ! Protocol::supports( $version ) ) {
			return Response::error(
				$request->id,
				Protocol::UNSUPPORTED_PROTOCOL_VERSION,
				'Unsupported protocol version: ' . $version,
				array( 'supported' => Protocol::SUPPORTED ),
				400
			);
		}

		if ( null === $request->client_capabilities() ) {
			return Response::error(
				$request->id,
				Protocol::INVALID_PARAMS,
				'Missing required _meta field: ' . Protocol::META_CLIENT_CAPABILITIES,
				null,
				400
			);
		}

		return null;
	}

	/* ─── Methods ───────────────────────────────────────────────────── */

	/**
	 * Answer `initialize`.
	 *
	 * @since 1.0.0
	 *
	 * @param Request $request Request.
	 *
	 * @return Response
	 */
	private function initialize( Request $request ): Response {
		$requested = $request->string( 'protocolVersion' );

		if ( '' !== $requested && ! Protocol::supports( $requested ) ) {
			return Response::error(
				$request->id,
				Protocol::UNSUPPORTED_PROTOCOL_VERSION,
				'Unsupported protocol version: ' . $requested,
				array( 'supported' => Protocol::SUPPORTED ),
				400
			);
		}

		$result = array(
			'protocolVersion' => Protocol::VERSION,
			'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
			'serverInfo'      => $this->info->to_array(),
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
	 * stable so clients can cache it.
	 *
	 * @since 1.0.0
	 *
	 * @param Request       $request  Request.
	 * @param Identity|null $identity Caller.
	 *
	 * @return Response
	 */
	private function list_tools( Request $request, ?Identity $identity ): Response {
		$definitions = array();

		foreach ( $this->tools as $tool ) {
			if ( $tool->permits( $identity ) ) {
				$definitions[] = $tool->definition();
			}
		}

		return Response::result( $request->id, array( 'tools' => $definitions ), $this->info );
	}

	/**
	 * Answer `tools/call`.
	 *
	 * @since 1.0.0
	 *
	 * @param Request       $request  Request.
	 * @param Identity|null $identity Caller.
	 *
	 * @return Response
	 */
	private function call_tool( Request $request, ?Identity $identity ): Response {
		$name = $request->string( 'name' );
		$tool = $this->tools[ $name ] ?? null;

		// An unknown tool, and one the caller may not see, are answered
		// identically. Distinguishing them would let a caller enumerate
		// the tools they are not entitled to.
		if ( null === $tool || ! $tool->permits( $identity ) ) {
			return Response::error( $request->id, Protocol::INVALID_PARAMS, 'Unknown tool: ' . $name );
		}

		$arguments = $request->array( 'arguments' );
		$problems  = Schema::validate( $arguments, $tool->input_schema );

		// An invalid argument is the model's mistake and the model can fix
		// it, so it comes back as a tool execution error rather than a
		// protocol error it cannot act on.
		if ( array() !== $problems ) {
			$this->record( $tool->name, $arguments, $identity, false );

			return Response::result(
				$request->id,
				ToolResult::failed( 'Invalid arguments: ' . implode( ' ', $problems ) )->to_array(),
				$this->info
			);
		}

		try {
			$result = $tool->call( $arguments, $identity );
		} catch ( \Throwable $e ) {
			$this->record( $tool->name, $arguments, $identity, false );

			// The message is deliberately generic: an exception can carry a
			// file path, a query, or a credential, and this response goes
			// to a language model and often into a transcript.
			return Response::result(
				$request->id,
				ToolResult::failed( 'The tool failed to run.' )->to_array(),
				$this->info
			);
		}

		$this->record( $tool->name, $arguments, $identity, ! $result->is_error );

		return Response::result( $request->id, $result->to_array(), $this->info );
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
