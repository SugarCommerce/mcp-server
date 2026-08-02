<?php
/**
 * Transport and argument validation.
 *
 * @package   Sugar\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace Sugar\Mcp\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sugar\Mcp\Http;
use Sugar\Mcp\Implementation;
use Sugar\Mcp\Protocol;
use Sugar\Mcp\Schema;
use Sugar\Mcp\Server;
use Sugar\Mcp\Tool;
use Sugar\Mcp\ToolResult;

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
	private function post( string $body, array $extra = array() ): \Sugar\Mcp\Response {
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

	/**
	 * A schema author writes a bare pattern, so a stray delimiter must not
	 * let them pass modifiers or break the expression.
	 */
	public function test_a_pattern_containing_a_delimiter_does_not_break_out(): void {
		$problems = Schema::validate( 'a/b', array( 'type' => 'string', 'pattern' => '^a/b$' ) );

		$this->assertSame( array(), $problems );
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
