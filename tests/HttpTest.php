<?php
/**
 * Transport and argument validation.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Mcp\Http;
use SugarCommerce\Mcp\Implementation;
use SugarCommerce\Mcp\Protocol;
use SugarCommerce\Mcp\Response;
use SugarCommerce\Mcp\Schema;
use SugarCommerce\Mcp\Server;
use SugarCommerce\Mcp\Tool;
use SugarCommerce\Mcp\ToolResult;

#[CoversClass( Http::class )]
#[CoversClass( Schema::class )]
final class HttpTest extends TestCase {

	private function http(): Http {
		$server = new Server( new Implementation( 'test' ), new FakeAuthenticator() );

		$server->add( new Tool(
			name: 'echo',
			description: 'Echo.',
			input_schema: array(
				'type'       => 'object',
				'properties' => array( 'message' => array( 'type' => 'string' ) ),
				'required'   => array( 'message' ),
			),
			handler: static fn( array $a ): ToolResult => ToolResult::text( $a['message'] ),
			scopes: array( 'read' ),
		) );

		return new Http( $server );
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function post( string $body, array $extra = array() ): \SugarCommerce\Mcp\Response {
		return $this->http()->respond(
			array_merge(
				array( 'REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer admin-token' ),
				$extra
			),
			$body
		);
	}

	private function envelope( string $method, array $params = array(), int $id = 1 ): string {
		$params['_meta'] = array(
			Protocol::META_PROTOCOL_VERSION    => Protocol::VERSION,
			Protocol::META_CLIENT_CAPABILITIES => array(),
		);

		return (string) json_encode( array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ) );
	}

	/* ─── Transport ─────────────────────────────────────────────────── */

	#[DataProvider( 'non_post_methods' )]
	public function test_only_post_carries_a_message( string $method ): void {
		$response = $this->http()->respond( array( 'REQUEST_METHOD' => $method ), '' );

		$this->assertSame( 405, $response->status );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function non_post_methods(): array {
		return array(
			'GET'    => array( 'GET' ),
			'PUT'    => array( 'PUT' ),
			'DELETE' => array( 'DELETE' ),
			'HEAD'   => array( 'HEAD' ),
		);
	}

	public function test_malformed_json_is_a_parse_error(): void {
		$response = $this->post( 'not json' );

		$this->assertSame( Protocol::PARSE_ERROR, $response->body['error']['code'] );
		$this->assertSame( 400, $response->status );
	}

	/**
	 * Tool arguments are small; an enormous body is a mistake or an
	 * attempt to exhaust memory, and neither is worth parsing.
	 */
	public function test_an_oversized_body_is_refused(): void {
		$response = $this->post( str_repeat( 'a', 1048577 ) );

		$this->assertSame( 413, $response->status );
	}

	public function test_a_bearer_token_is_extracted(): void {
		$response = $this->post( $this->envelope( 'tools/list' ) );

		$this->assertFalse( $response->is_error() );
	}

	public function test_the_bearer_prefix_is_case_insensitive(): void {
		$response = $this->post( $this->envelope( 'tools/list' ), array( 'HTTP_AUTHORIZATION' => 'bearer admin-token' ) );

		$this->assertFalse( $response->is_error() );
	}

	/**
	 * Apache with CGI drops Authorization unless it is rewritten, which is
	 * why so many deployments mysteriously return 401.
	 */
	public function test_the_apache_cgi_fallback_header_is_read(): void {
		$response = $this->http()->respond(
			array(
				'REQUEST_METHOD'              => 'POST',
				'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer admin-token',
			),
			$this->envelope( 'tools/list' )
		);

		$this->assertFalse( $response->is_error() );
	}

	public function test_a_missing_token_is_unauthorized(): void {
		$response = $this->http()->respond(
			array( 'REQUEST_METHOD' => 'POST' ),
			$this->envelope( 'tools/list' )
		);

		$this->assertSame( 401, $response->status );
	}

	public function test_the_protocol_header_is_passed_through(): void {
		$response = $this->post(
			$this->envelope( 'tools/list' ),
			array( 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18' )
		);

		$this->assertSame( Protocol::HEADER_MISMATCH, $response->body['error']['code'] );
	}

	/**
	 * A 2025-11-25 client over HTTP: the version in the header, no _meta.
	 */
	public function test_a_legacy_client_is_served_over_http(): void {
		$body     = (string) json_encode( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => new \stdClass() ) );
		$response = $this->post( $body, array( 'HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25' ) );

		$this->assertSame( 200, $response->status );
		$this->assertArrayHasKey( 'tools', $response->body['result'] );
		$this->assertArrayNotHasKey( 'resultType', $response->body['result'] );
	}

	/* ─── Reply headers ─────────────────────────────────────────────── */

	public function test_every_reply_is_json_uncached_and_unsniffed(): void {
		$headers = Http::headers( $this->post( $this->envelope( 'ping' ) ) );

		$this->assertSame( array(
			'Content-Type'           => 'application/json',
			'Cache-Control'          => 'no-store',
			'X-Content-Type-Options' => 'nosniff',
		), $headers );
	}

	/**
	 * RFC 6750: a request with no token is told what to send; one with a
	 * bad token is told it is invalid.
	 */
	/**
	 * RFC 9728 §5.1: the challenge says where the authorization server is,
	 * and MCP clients start their sign-in from it.
	 */
	public function test_a_401_points_at_the_resource_metadata(): void {
		$unauthorised = Response::error( 1, Protocol::INVALID_REQUEST, 'Unauthorized.', null, 401 );

		$this->assertSame(
			'Bearer realm="shop", resource_metadata="https://api.example.com/.well-known/oauth-protected-resource/mcp", scope="read write"',
			Http::headers( $unauthorised, 'shop', false, 'https://api.example.com/.well-known/oauth-protected-resource/mcp', 'read write' )['WWW-Authenticate']
		);
		$this->assertStringContainsString( 'error="invalid_token"', Http::headers( $unauthorised, 'shop', true, 'https://x.example/m' )['WWW-Authenticate'] );
		$this->assertStringContainsString( 'resource_metadata="https://x.example/m"', Http::headers( $unauthorised, 'shop', true, 'https://x.example/m' )['WWW-Authenticate'] );
	}

	/**
	 * MCP 2026-07-28, "Runtime Insufficient Scope Errors".
	 */
	public function test_a_403_is_the_step_up_challenge(): void {
		$forbidden = Response::error( 1, Protocol::INVALID_REQUEST, 'Forbidden.', null, 403 );

		$header = Http::headers( $forbidden, 'shop', true, 'https://x.example/m', 'write' )['WWW-Authenticate'];

		$this->assertStringContainsString( 'error="insufficient_scope"', $header );
		$this->assertStringContainsString( 'scope="write"', $header );
		$this->assertStringContainsString( 'resource_metadata="https://x.example/m"', $header );
		$this->assertSame( 'Bearer realm="a", scope="bc"', Http::challenge( 'a', '', '', "b\"c" ) );
	}

	public function test_a_401_carries_a_bearer_challenge(): void {
		$unauthorised = $this->http()->respond( array( 'REQUEST_METHOD' => 'POST' ), $this->envelope( 'tools/list' ) );

		$this->assertSame( 'Bearer realm="my-shop"', Http::headers( $unauthorised, 'my-shop' )['WWW-Authenticate'] );
		$this->assertSame(
			'Bearer realm="my-shop", error="invalid_token", error_description="The token is not valid, or has been revoked."',
			Http::headers( $unauthorised, 'my-shop', token_sent: true )['WWW-Authenticate']
		);
		$this->assertSame( 'Bearer realm="evil"', Http::headers( $unauthorised, "ev\"i\r\nl" )['WWW-Authenticate'], 'Nothing breaks out of the quotes.' );
	}

	/* ─── Batches ───────────────────────────────────────────────────── */

	public function test_a_batch_returns_one_reply_per_request(): void {
		$batch = '[' . $this->envelope( 'ping', array(), 1 ) . ',' . $this->envelope( 'tools/list', array(), 2 ) . ']';

		$response = $this->post( $batch );

		$this->assertCount( 2, $response->body );
		$this->assertSame( 1, $response->body[0]['id'] );
		$this->assertSame( 2, $response->body[1]['id'] );
	}

	public function test_a_batch_of_notifications_returns_no_body(): void {
		$batch = '[{"jsonrpc":"2.0","method":"notifications/initialized"},{"jsonrpc":"2.0","method":"notifications/x"}]';

		$response = $this->post( $batch );

		$this->assertNull( $response->body );
		$this->assertSame( 202, $response->status );
	}

	/* ─── Schema validation ─────────────────────────────────────────── */

	#[DataProvider( 'type_cases' )]
	public function test_types_are_checked( mixed $value, string $type, bool $valid ): void {
		$problems = Schema::validate( $value, array( 'type' => $type ) );

		$this->assertSame( $valid, array() === $problems );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string, 2: bool}>
	 */
	public static function type_cases(): array {
		return array(
			'string ok'        => array( 'a', 'string', true ),
			'string not int'   => array( 1, 'string', false ),
			'integer ok'       => array( 1, 'integer', true ),
			'integer not str'  => array( '1', 'integer', false ),
			'float not int'    => array( 1.5, 'integer', false ),
			// JSON has one numeric type, so an integer satisfies "number".
			'int is a number'  => array( 1, 'number', true ),
			'float is number'  => array( 1.5, 'number', true ),
			'boolean ok'       => array( true, 'boolean', true ),
			'list is array'    => array( array( 1, 2 ), 'array', true ),
			'map is not array' => array( array( 'a' => 1 ), 'array', false ),
			'map is object'    => array( array( 'a' => 1 ), 'object', true ),
			'empty is object'  => array( array(), 'object', true ),
			'null ok'          => array( null, 'null', true ),
		);
	}

	public function test_required_properties_are_enforced(): void {
		$schema = array( 'type' => 'object', 'properties' => array( 'a' => array( 'type' => 'string' ) ), 'required' => array( 'a' ) );

		$this->assertSame( array(), Schema::validate( array( 'a' => 'x' ), $schema ) );
		$this->assertCount( 1, Schema::validate( array(), $schema ) );
	}

	public function test_unknown_properties_are_rejected_when_closed(): void {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array( 'a' => array( 'type' => 'string' ) ),
			'additionalProperties' => false,
		);

		$this->assertSame( array(), Schema::validate( array( 'a' => 'x' ), $schema ) );
		$this->assertCount( 1, Schema::validate( array( 'a' => 'x', 'b' => 'y' ), $schema ) );
	}

	public function test_string_bounds_and_patterns(): void {
		$schema = array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 4, 'pattern' => '^[a-z]+$' );

		$this->assertSame( array(), Schema::validate( 'abc', $schema ) );
		$this->assertNotSame( array(), Schema::validate( 'a', $schema ) );
		$this->assertNotSame( array(), Schema::validate( 'abcde', $schema ) );
		$this->assertNotSame( array(), Schema::validate( 'AB', $schema ) );
	}

	public function test_numeric_bounds(): void {
		$schema = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 );

		$this->assertSame( array(), Schema::validate( 5, $schema ) );
		$this->assertNotSame( array(), Schema::validate( 0, $schema ) );
		$this->assertNotSame( array(), Schema::validate( 11, $schema ) );
	}

	public function test_enums(): void {
		$schema = array( 'type' => 'string', 'enum' => array( 'a', 'b' ) );

		$this->assertSame( array(), Schema::validate( 'a', $schema ) );
		$this->assertNotSame( array(), Schema::validate( 'c', $schema ) );
	}

	public function test_array_items_and_bounds(): void {
		$schema = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => 2 );

		$this->assertSame( array(), Schema::validate( array( 1, 2 ), $schema ) );
		$this->assertNotSame( array(), Schema::validate( array(), $schema ) );
		$this->assertNotSame( array(), Schema::validate( array( 1, 2, 3 ), $schema ) );
		$this->assertNotSame( array(), Schema::validate( array( 'x' ), $schema ) );
	}

	public function test_nested_objects_report_a_path(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'user' => array(
					'type'       => 'object',
					'properties' => array( 'age' => array( 'type' => 'integer' ) ),
					'required'   => array( 'age' ),
				),
			),
		);

		$problems = Schema::validate( array( 'user' => array( 'age' => 'old' ) ), $schema );

		$this->assertStringContainsString( 'user.age', $problems[0] );
	}

	public function test_an_object_schema_is_closed_by_default(): void {
		$schema = Schema::object( array( 'id' => array( 'type' => 'integer' ) ), array( 'id' ) );

		$this->assertSame( array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ) ), 'required' => array( 'id' ), 'additionalProperties' => false ), $schema );
		$this->assertNotSame( array(), Schema::validate( array( 'id' => 1, 'idd' => 2 ), $schema ), 'A misspelt argument is an error, not ignored.' );
		$this->assertSame( array(), Schema::validate( array( 'id' => 1, 'x' => 2 ), Schema::object( array( 'id' => array( 'type' => 'integer' ) ), open: true ) ) );
	}

	/**
	 * A schema author writes a bare pattern, so a stray delimiter must not
	 * let them pass modifiers or break the expression.
	 */
	public function test_a_pattern_containing_a_delimiter_does_not_break_out(): void {
		$problems = Schema::validate( 'a/b', array( 'type' => 'string', 'pattern' => '^a/b$' ) );

		$this->assertSame( array(), $problems );
	}

	/**
	 * `["integer", "null"]` is a JSON Schema type list. It used to be
	 * skipped, so any value — a string, an array — reached a handler
	 * written for an integer or nothing.
	 */
	public function test_a_list_of_types_is_enforced(): void {
		$schema = array( 'type' => array( 'integer', 'null' ), 'minimum' => 1 );

		$this->assertSame( array(), Schema::validate( 5, $schema ) );
		$this->assertSame( array(), Schema::validate( null, $schema ) );
		$this->assertSame( array( 'The value must be an integer or a null.' ), Schema::validate( 'abc', $schema ) );
		$this->assertNotSame( array(), Schema::validate( array( 1 ), $schema ) );
		$this->assertNotSame( array(), Schema::validate( 0, $schema ), 'The other rules still apply.' );

		$nested = Schema::object( array( 'days' => array( 'type' => array( 'integer', 'null' ) ) ) );

		$this->assertNotSame( array(), Schema::validate( array( 'days' => '30; DROP TABLE x' ), $nested ) );
	}

	/**
	 * A type name JSON Schema does not have is a mistake in the schema; it
	 * must not let everything through.
	 */
	public function test_an_unknown_type_name_fails_closed(): void {
		$this->assertNotSame( array(), Schema::validate( 'x', array( 'type' => 'int' ) ) );
		$this->assertNotSame( array(), Schema::validate( 1, array( 'type' => 'int' ) ) );
		$this->assertNotSame( array(), Schema::validate( 1, array( 'type' => array() ) ) );
		$this->assertNotSame( array(), Schema::validate( 1, array( 'type' => 7 ) ) );
		$this->assertSame( array(), Schema::validate( 1, array() ), 'No type at all is still any type.' );
	}

	public function test_string_lengths_count_characters_not_bytes(): void {
		$schema = array( 'type' => 'string', 'maxLength' => 3 );

		$this->assertSame( array(), Schema::validate( 'été', $schema ) );
		$this->assertNotSame( array(), Schema::validate( 'étés', $schema ) );
	}

	/* ─── Origin ────────────────────────────────────────────────────── */

	/**
	 * MCP Streamable HTTP: a server must check Origin, or a web page —
	 * including one that has rebound its own hostname to 127.0.0.1 — can
	 * POST tool calls to it. A text/plain POST needs no CORS preflight.
	 */
	public function test_a_browser_origin_is_refused_unless_allowed(): void {
		$body     = $this->envelope( 'tools/list' );
		$attacker = $this->post( $body, array( 'HTTP_ORIGIN' => 'http://attacker.example' ) );

		$this->assertSame( 403, $attacker->status );
		$this->assertSame( Http::ORIGIN_REFUSED, $attacker->error_message() );
		$this->assertArrayNotHasKey( 'WWW-Authenticate', Http::headers( $attacker ), 'No token can lift an origin refusal.' );

		$open = new Http( new Server( new Implementation( 'test' ) ) );

		$this->assertSame( 403, $open->respond( array( 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'null' ), $body )->status );

		$allowed = new Http( new Server( new Implementation( 'test' ) ), origins: array( 'https://app.example' ) );

		$this->assertSame( 200, $allowed->respond( array( 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://app.example' ), $body )->status );
		$this->assertSame( 403, $allowed->respond( array( 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://app.example.evil' ), $body )->status );
	}

	public function test_a_request_without_an_origin_is_not_affected(): void {
		$body = $this->envelope( 'tools/list' );

		$this->assertSame( 200, $this->post( $body )->status );
		$this->assertSame( 200, $this->post( $body, array( 'HTTP_ORIGIN' => '' ) )->status, 'An empty header is no header.' );
	}

	/* ─── Batch limits ──────────────────────────────────────────────── */

	/**
	 * One request inside the body limit could otherwise be thousands of
	 * tool calls, past any rate limit counted in requests.
	 */
	public function test_an_oversized_batch_is_refused_before_anything_runs(): void {
		$runs   = 0;
		$server = new Server( new Implementation( 'test' ), new FakeAuthenticator() );

		$server->add( new Tool(
			name: 'count',
			description: 'Count.',
			input_schema: Schema::object( array() ),
			handler: static function () use ( &$runs ): ToolResult {
				++$runs;

				return ToolResult::text( 'ok' );
			},
		) );

		$http  = new Http( $server );
		$calls = array_map(
			fn( int $id ): string => $this->envelope( 'tools/call', array( 'name' => 'count', 'arguments' => array() ), $id ),
			range( 1, Http::MAX_BATCH + 1 )
		);

		$refused = $http->respond( array( 'REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer admin-token' ), '[' . implode( ',', $calls ) . ']' );

		$this->assertSame( 400, $refused->status );
		$this->assertSame( 0, $runs );

		$accepted = $http->respond( array( 'REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer admin-token' ), '[' . implode( ',', array_slice( $calls, 0, Http::MAX_BATCH ) ) . ']' );

		$this->assertSame( 200, $accepted->status );
		$this->assertSame( Http::MAX_BATCH, $runs );
	}

	public function test_an_empty_batch_is_an_invalid_request(): void {
		$response = $this->post( '[]' );

		$this->assertSame( 400, $response->status );
		$this->assertSame( Protocol::INVALID_REQUEST, $response->body['error']['code'] );
	}

	/* ─── Failures ──────────────────────────────────────────────────── */

	/**
	 * An authenticator whose database is down throws, and the exception's
	 * message names the database. serve() must answer without it.
	 */
	public function test_serve_answers_a_thrown_exception_with_a_bare_500(): void {
		$server = new Server( new Implementation( 'test' ), new class() implements \SugarCommerce\Mcp\Authenticator {
			public function authenticate( #[\SensitiveParameter] string $token ): ?\SugarCommerce\Mcp\Identity {
				throw new \RuntimeException( 'SQLSTATE[HY000] [2002] mysql://root:hunter2@10.0.0.5/shop' );
			}
		} );

		$log      = tempnam( sys_get_temp_dir(), 'mcp' );
		$previous = ini_set( 'error_log', (string) $log );

		ob_start();

		try {
			( new Http( $server ) )->serve( array( 'REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer x' ), $this->envelope( 'tools/list' ) );
		} finally {
			$output = (string) ob_get_clean();
			ini_set( 'error_log', (string) $previous );
		}

		$logged = (string) file_get_contents( (string) $log );
		unlink( (string) $log );

		$this->assertStringNotContainsString( 'hunter2', $output );
		$this->assertStringNotContainsString( 'mysql', $output );
		$this->assertSame( Protocol::INTERNAL_ERROR, json_decode( $output, true )['error']['code'] );
		$this->assertStringContainsString( 'RuntimeException', $logged, 'The operator still learns what happened.' );
	}

	public function test_invalid_utf8_in_a_reply_does_not_empty_it(): void {
		$response = Response::result( 1, ToolResult::text( "caf\xE9" )->to_array() );

		$this->assertNotSame( '', $response->json() );
		$this->assertSame( "caf\u{FFFD}", json_decode( $response->json(), true )['result']['content'][0]['text'] );
	}

	/* ─── Live transport ────────────────────────────────────────────── */

	/**
	 * Run against a real server over a real socket. Header handling,
	 * status codes and body limits only exist at the transport layer, and
	 * a mock would only be testing the mock.
	 */
	#[Group( 'live' )]
	public function test_a_real_request_over_a_real_socket(): void {
		$root = dirname( __DIR__ );
		$port = 8849;

		$process = proc_open(
			sprintf( 'php -S 127.0.0.1:%d %s', $port, escapeshellarg( $root . '/example/server.php' ) ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			$root
		);

		if ( ! is_resource( $process ) ) {
			$this->markTestSkipped( 'Could not start a PHP server.' );
		}

		try {
			// Wait for the socket rather than sleeping a fixed interval.
			$url = 'http://127.0.0.1:' . $port . '/';

			for ( $attempt = 0; $attempt < 50; $attempt++ ) {
				$probe = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.1 );

				if ( is_resource( $probe ) ) {
					fclose( $probe );
					break;
				}

				usleep( 100000 );
			}

			$body = json_encode( array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'get_product',
					'arguments' => array( 'id' => 1 ),
					'_meta'     => array(
						Protocol::META_PROTOCOL_VERSION    => Protocol::VERSION,
						Protocol::META_CLIENT_CAPABILITIES => array(),
					),
				),
			) );

			$context = stream_context_create( array(
				'http' => array(
					'method'        => 'POST',
					'header'        => "Content-Type: application/json\r\nAuthorization: Bearer sc_read_token\r\n",
					'content'       => $body,
					'ignore_errors' => true,
					'timeout'       => 5,
				),
			) );

			$raw = @file_get_contents( $url, false, $context );

			$this->assertNotFalse( $raw, 'The server did not answer.' );

			$decoded = json_decode( (string) $raw, true );

			$this->assertSame( 'complete', $decoded['result']['resultType'] );
			$this->assertFalse( $decoded['result']['isError'] );
			$this->assertSame( 'Waveform Pro', $decoded['result']['structuredContent']['name'] );
		} finally {
			proc_terminate( $process );
			proc_close( $process );
		}
	}
}
