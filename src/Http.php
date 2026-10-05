<?php
/**
 * Streamable HTTP transport.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp;

/**
 * Class Http
 *
 * Reads a request from the environment, hands it to a {@see Server}, and
 * writes the reply.
 *
 * Everything that touches PHP's globals lives here so that {@see Server}
 * stays a pure function and the protocol can be tested without a socket.
 *
 * @since 1.0.0
 */
final readonly class Http {

	/**
	 * Largest request body accepted.
	 *
	 * Tool arguments are small. A body far past this is a mistake or an
	 * attempt to exhaust memory, and either way there is nothing useful
	 * to parse.
	 */
	private const int MAX_BODY = 1048576;

	/**
	 * @since 1.0.0
	 *
	 * @param Server $server Server to dispatch to.
	 * @param string $realm  Named in the challenge on a 401. Since 1.1.0.
	 */
	public function __construct( private Server $server, private string $realm = 'mcp' ) {}

	/**
	 * The headers a reply goes out with.
	 *
	 * For an application that sends the reply through its own response
	 * object rather than {@see self::serve()}, so the two cannot differ:
	 *
	 * - `Cache-Control: no-store` — tool results are live data, and an
	 *   intermediary must never cache one.
	 * - `X-Content-Type-Options: nosniff` — the body is JSON that can carry
	 *   text a model or a user wrote; a browser must not guess it is HTML.
	 * - `WWW-Authenticate` on a 401, as RFC 6750 writes it. HTTP requires a
	 *   challenge on every 401, and MCP clients read it to decide between
	 *   "ask for a token" and "the token you have is wrong" — without it
	 *   some retry the same rejected token, or give up without asking.
	 *   `invalid_token` only when one was sent: a request that sent none is
	 *   told what to send, not that it sent something bad.
	 *
	 * @since 1.1.0
	 *
	 * @param Response $response   The reply.
	 * @param string   $realm      The protection space, e.g. your app's name.
	 * @param bool     $token_sent Whether the request carried a bearer token.
	 *
	 * @return array<string, string>
	 */
	public static function headers( Response $response, string $realm = 'mcp', bool $token_sent = false ): array {
		$headers = array(
			'Content-Type'           => 'application/json',
			'Cache-Control'          => 'no-store',
			'X-Content-Type-Options' => 'nosniff',
		);

		if ( 401 === $response->status ) {
			$headers['WWW-Authenticate'] = 'Bearer realm="' . str_replace( array( '"', '\\', "\r", "\n" ), '', $realm ) . '"'
				. ( $token_sent ? ', error="invalid_token", error_description="The token is not valid, or has been revoked."' : '' );
		}

		return $headers;
	}

	/**
	 * Serve the current request and send the reply.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server_vars Overrides `$_SERVER`, for tests.
	 * @param string|null               $body        Overrides the request body.
	 *
	 * @return void
	 */
	public function serve( ?array $server_vars = null, ?string $body = null ): void {
		$server_vars = $server_vars ?? $_SERVER;
		$response    = $this->respond( $server_vars, $body ?? $this->read_body() );

		http_response_code( $response->status );

		foreach ( self::headers( $response, $this->realm, '' !== $this->bearer( $server_vars ) ) as $name => $value ) {
			header( $name . ': ' . $value );
		}

		if ( null !== $response->body ) {
			echo $response->json();
		}
	}

	/**
	 * Build the response for a request, without sending anything.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $server_vars Server variables.
	 * @param string               $body        Raw request body.
	 *
	 * @return Response
	 */
	public function respond( array $server_vars, string $body ): Response {
		$method = strtoupper( (string) ( $server_vars['REQUEST_METHOD'] ?? 'GET' ) );

		// Only POST carries a message. GET is reserved for the server-sent
		// event stream, which this transport does not implement.
		if ( 'POST' !== $method ) {
			return Response::error( null, Protocol::INVALID_REQUEST, 'Only POST is accepted.', null, 405 );
		}

		if ( strlen( $body ) > self::MAX_BODY ) {
			return Response::error( null, Protocol::INVALID_REQUEST, 'The request body is too large.', null, 413 );
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return Response::error( null, Protocol::PARSE_ERROR, 'The request body is not valid JSON.', null, 400 );
		}

		$token  = $this->bearer( $server_vars );
		$header = $server_vars['HTTP_MCP_PROTOCOL_VERSION'] ?? null;

		// A batch is a JSON array of messages rather than one object.
		if ( array_is_list( $decoded ) ) {
			return $this->batch( $decoded, $token, is_string( $header ) ? $header : null );
		}

		return $this->server->handle( $decoded, $token, is_string( $header ) ? $header : null );
	}

	/**
	 * Handle a batch of messages.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $messages Decoded messages.
	 * @param string            $token    Bearer token.
	 * @param string|null       $header   Protocol version header.
	 *
	 * @return Response
	 */
	private function batch( array $messages, #[\SensitiveParameter] string $token, ?string $header ): Response {
		$replies = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$response = $this->server->handle( $message, $token, $header );

			// Notifications produce no reply, and a batch of them produces
			// no body at all.
			if ( null !== $response->body ) {
				$replies[] = $response->body;
			}
		}

		if ( array() === $replies ) {
			return Response::accepted();
		}

		return new Response( $replies, 200 );
	}

	/**
	 * Extract the bearer token.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $server_vars Server variables.
	 *
	 * @return string Empty when absent.
	 */
	private function bearer( array $server_vars ): string {
		$header = (string) (
			$server_vars['HTTP_AUTHORIZATION']
			// Apache with CGI hides Authorization unless it is passed
			// through explicitly; this is the usual rewrite target.
			?? $server_vars['REDIRECT_HTTP_AUTHORIZATION']
			?? ''
		);

		if ( 1 === preg_match( '/^Bearer\s+(.+)$/i', trim( $header ), $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Read the request body.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function read_body(): string {
		$body = file_get_contents( 'php://input', false, null, 0, self::MAX_BODY + 1 );

		return false === $body ? '' : $body;
	}
}
