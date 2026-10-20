<?php
/**
 * Protocol dispatch.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Mcp\Content;
use SugarCommerce\Mcp\Identity;
use SugarCommerce\Mcp\Implementation;
use SugarCommerce\Mcp\Protocol;
use SugarCommerce\Mcp\Request;
use SugarCommerce\Mcp\Response;
use SugarCommerce\Mcp\Server;
use SugarCommerce\Mcp\Tool;
use SugarCommerce\Mcp\ToolResult;

#[CoversClass( Server::class )]
#[CoversClass( Request::class )]
#[CoversClass( Response::class )]
#[CoversClass( Tool::class )]
#[CoversClass( ToolResult::class )]
#[CoversClass( Identity::class )]
#[CoversClass( Protocol::class )]
#[CoversClass( Implementation::class )]
#[CoversClass( Content::class )]
final class ServerTest extends TestCase {

	private const ADMIN  = 'admin-token';
	private const READER = 'reader-token';

	private function server(): Server {
		$server = new Server( new Implementation( 'test', '1.0.0' ), new FakeAuthenticator() );

		$server->add( new Tool(
			name: 'echo',
			description: 'Echo a message back.',
			input_schema: array(
				'type'       => 'object',
				'properties' => array( 'message' => array( 'type' => 'string', 'minLength' => 1 ) ),
				'required'   => array( 'message' ),
				'additionalProperties' => false,
			),
			handler: static fn( array $a ): ToolResult => ToolResult::text( $a['message'] ),
			scopes: array( 'read' ),
			read_only: true,
		) );

		$server->add( new Tool(
			name: 'destroy',
			description: 'A tool needing the write scope.',
			input_schema: array( 'type' => 'object', 'additionalProperties' => false ),
			handler: static fn(): ToolResult => ToolResult::text( 'done' ),
			scopes: array( 'write' ),
			destructive: true,
		) );

		$server->add( new Tool(
			name: 'explodes',
			description: 'Always throws.',
			input_schema: array( 'type' => 'object', 'additionalProperties' => false ),
			handler: static function (): ToolResult {
				throw new \RuntimeException( 'Connection failed: mysql://root:hunter2@10.0.0.5/shop' );
			},
			scopes: array( 'read' ),
		) );

		return $server;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function message( string $method, array $params = array(), string|int|null $id = 1, string $version = Protocol::VERSION ): array {
		if ( 'initialize' !== $method ) {
			$params['_meta'] = array_merge(
				array(
					Protocol::META_PROTOCOL_VERSION    => $version,
					Protocol::META_CLIENT_CAPABILITIES => array(),
				),
				is_array( $params['_meta'] ?? null ) ? $params['_meta'] : array()
			);
		}

		$message = array( 'jsonrpc' => '2.0', 'method' => $method, 'params' => $params );

		if ( null !== $id ) {
			$message['id'] = $id;
		}

		return $message;
	}

	/* ─── Lifecycle ─────────────────────────────────────────────────── */

	public function test_initialize_reports_the_current_revision(): void {
		$response = $this->server()->handle( $this->message( 'initialize' ), self::ADMIN );

		$this->assertSame( Protocol::VERSION, $response->body['result']['protocolVersion'] );
		$this->assertSame( '2026-07-28', $response->body['result']['protocolVersion'] );
		$this->assertArrayHasKey( 'tools', $response->body['result']['capabilities'] );
		$this->assertSame( 'test', $response->body['result']['serverInfo']['name'] );
	}

	/**
	 * `initialize` is how a client learns the version, so it cannot be
	 * required to already know it.
	 */
	public function test_initialize_does_not_require_the_meta_block(): void {
		$response = $this->server()->handle(
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array() ),
			self::ADMIN
		);

		$this->assertFalse( $response->is_error() );
	}

	public function test_initialize_rejects_a_version_it_cannot_speak(): void {
		$response = $this->server()->handle(
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-06-18' ) ),
			self::ADMIN
		);

		$this->assertSame( Protocol::UNSUPPORTED_PROTOCOL_VERSION, $response->body['error']['code'] );
		$this->assertSame( 400, $response->status );
	}

	public function test_ping_answers(): void {
		$response = $this->server()->handle( $this->message( 'ping' ), self::ADMIN );

		$this->assertFalse( $response->is_error() );
	}

	/**
	 * The receiver must not respond to a notification.
	 */
	public function test_a_notification_gets_no_body(): void {
		$response = $this->server()->handle(
			array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ),
			self::ADMIN
		);

		$this->assertNull( $response->body );
		$this->assertSame( 202, $response->status );
	}

	public function test_an_unknown_method_is_reported(): void {
		$response = $this->server()->handle( $this->message( 'resources/list' ), self::ADMIN );

		$this->assertSame( Protocol::METHOD_NOT_FOUND, $response->body['error']['code'] );
	}

	/* ─── Every result carries resultType ───────────────────────────── */

	/**
	 * Required by the 2026-07-28 revision so a client can tell a finished
	 * call from one awaiting input without inspecting the shape.
	 */
	#[DataProvider( 'answering_methods' )]
	public function test_every_result_declares_its_type( string $method, array $params ): void {
		$response = $this->server()->handle( $this->message( $method, $params ), self::ADMIN );

		$this->assertSame( 'complete', $response->body['result']['resultType'] );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function answering_methods(): array {
		return array(
			'initialize' => array( 'initialize', array() ),
			'ping'       => array( 'ping', array() ),
			'tools/list' => array( 'tools/list', array() ),
			'tools/call' => array( 'tools/call', array( 'name' => 'echo', 'arguments' => array( 'message' => 'hi' ) ) ),
		);
	}

	public function test_results_identify_the_server(): void {
		$response = $this->server()->handle( $this->message( 'ping' ), self::ADMIN );

		$this->assertSame( 'test', $response->body['result']['_meta'][ Protocol::META_SERVER_INFO ]['name'] );
	}

	/* ─── Stateless per-request negotiation ─────────────────────────── */

	public function test_a_request_without_a_protocol_version_is_malformed(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/list',
			'params'  => array( '_meta' => array( Protocol::META_CLIENT_CAPABILITIES => array() ) ),
		);

		$response = $this->server()->handle( $message, self::ADMIN );

		$this->assertSame( Protocol::INVALID_PARAMS, $response->body['error']['code'] );
		$this->assertSame( 400, $response->status );
	}

	public function test_a_request_without_client_capabilities_is_malformed(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/list',
			'params'  => array( '_meta' => array( Protocol::META_PROTOCOL_VERSION => Protocol::VERSION ) ),
		);

		$response = $this->server()->handle( $message, self::ADMIN );

		$this->assertSame( Protocol::INVALID_PARAMS, $response->body['error']['code'] );
		$this->assertSame( 400, $response->status );
	}

	public function test_an_older_revision_is_refused(): void {
		$response = $this->server()->handle( $this->message( 'tools/list', array(), 1, '2025-06-18' ), self::ADMIN );

		$this->assertSame( Protocol::UNSUPPORTED_PROTOCOL_VERSION, $response->body['error']['code'] );
		$this->assertSame( array( Protocol::VERSION ), $response->body['error']['data']['supported'] );
	}

	public function test_a_header_disagreeing_with_the_body_is_refused(): void {
		$response = $this->server()->handle( $this->message( 'tools/list' ), self::ADMIN, '2025-06-18' );

		$this->assertSame( Protocol::HEADER_MISMATCH, $response->body['error']['code'] );
		$this->assertSame( 400, $response->status );
	}

	public function test_a_matching_header_is_accepted(): void {
		$response = $this->server()->handle( $this->message( 'tools/list' ), self::ADMIN, Protocol::VERSION );

		$this->assertFalse( $response->is_error() );
	}

	/**
	 * These codes belonged to earlier revisions and must never be emitted
	 * again, or a client will read them as something they no longer mean.
	 */
	public function test_retired_error_codes_are_never_emitted(): void {
		$messages = array(
			$this->message( 'tools/call', array( 'name' => 'missing', 'arguments' => array() ) ),
			$this->message( 'resources/list' ),
			$this->message( 'tools/list', array(), 1, '2020-01-01' ),
		);

		foreach ( $messages as $message ) {
			$code = $this->server()->handle( $message, self::ADMIN )->body['error']['code'] ?? 0;

			$this->assertNotContains( $code, Protocol::RETIRED );
		}
	}

	/* ─── Authentication ────────────────────────────────────────────── */

	public function test_an_unauthenticated_call_is_refused(): void {
		$response = $this->server()->handle( $this->message( 'tools/list' ), '' );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 401, $response->status );
	}

	public function test_an_unknown_token_is_refused(): void {
		$response = $this->server()->handle( $this->message( 'tools/list' ), 'not-a-token' );

		$this->assertSame( 401, $response->status );
	}

	public function test_a_server_without_an_authenticator_accepts_anyone(): void {
		$server = new Server( new Implementation( 'open' ) );
		$server->add( new Tool( 'open', 'No scopes.', array( 'type' => 'object' ), static fn(): ToolResult => ToolResult::text( 'ok' ) ) );

		$response = $server->handle( $this->message( 'tools/list' ) );

		$this->assertFalse( $response->is_error() );
		$this->assertCount( 1, $response->body['result']['tools'] );
	}

	/* ─── Tool listing ──────────────────────────────────────────────── */

	public function test_the_tool_list_is_filtered_by_scope(): void {
		$admin  = $this->server()->handle( $this->message( 'tools/list' ), self::ADMIN );
		$reader = $this->server()->handle( $this->message( 'tools/list' ), self::READER );

		$this->assertCount( 3, $admin->body['result']['tools'] );
		$this->assertSame(
			array( 'echo', 'explodes' ),
			array_column( $reader->body['result']['tools'], 'name' )
		);
	}

	public function test_the_order_is_stable_so_clients_can_cache(): void {
		$first  = $this->server()->handle( $this->message( 'tools/list' ), self::ADMIN );
		$second = $this->server()->handle( $this->message( 'tools/list' ), self::ADMIN );

		$this->assertSame(
			array_column( $first->body['result']['tools'], 'name' ),
			array_column( $second->body['result']['tools'], 'name' )
		);
	}

	public function test_definitions_carry_behaviour_hints(): void {
		$tools = $this->server()->handle( $this->message( 'tools/list' ), self::ADMIN )->body['result']['tools'];
		$byName = array_column( $tools, null, 'name' );

		$this->assertTrue( $byName['echo']['annotations']['readOnlyHint'] );
		$this->assertTrue( $byName['destroy']['annotations']['destructiveHint'] );
		$this->assertFalse( $byName['destroy']['annotations']['readOnlyHint'] );
	}

	/* ─── Tool calls ────────────────────────────────────────────────── */

	public function test_a_tool_runs(): void {
		$response = $this->server()->handle(
			$this->message( 'tools/call', array( 'name' => 'echo', 'arguments' => array( 'message' => 'hello' ) ) ),
			self::ADMIN
		);

		$this->assertFalse( $response->body['result']['isError'] );
		$this->assertSame( 'hello', $response->body['result']['content'][0]['text'] );
	}

	public function test_an_unknown_tool_is_a_protocol_error(): void {
		$response = $this->server()->handle(
			$this->message( 'tools/call', array( 'name' => 'nope', 'arguments' => array() ) ),
			self::ADMIN
		);

		$this->assertSame( Protocol::INVALID_PARAMS, $response->body['error']['code'] );
	}

	/**
	 * Distinguishing "forbidden" from "no such tool" would let a caller
	 * enumerate the tools they are not entitled to.
	 */
	public function test_a_forbidden_tool_is_indistinguishable_from_a_missing_one(): void {
		$forbidden = $this->server()->handle(
			$this->message( 'tools/call', array( 'name' => 'destroy', 'arguments' => array() ) ),
			self::READER
		);

		$missing = $this->server()->handle(
			$this->message( 'tools/call', array( 'name' => 'destroy_x', 'arguments' => array() ) ),
			self::READER
		);

		$this->assertSame( $forbidden->body['error']['code'], $missing->body['error']['code'] );
		$this->assertSame( 'Unknown tool: destroy', $forbidden->body['error']['message'] );
	}

	/**
	 * A model can fix a bad argument if it is told what was wrong, but can
	 * do nothing with a JSON-RPC error code.
	 */
	public function test_invalid_arguments_come_back_as_a_retryable_tool_error(): void {
		$response = $this->server()->handle(
			$this->message( 'tools/call', array( 'name' => 'echo', 'arguments' => array( 'message' => 42 ) ) ),
			self::ADMIN
		);

		$this->assertFalse( $response->is_error(), 'This must not be a protocol error.' );
		$this->assertTrue( $response->body['result']['isError'] );
		$this->assertStringContainsString( 'must be a string', $response->body['result']['content'][0]['text'] );
	}

	public function test_a_missing_required_argument_is_reported(): void {
		$response = $this->server()->handle(
			$this->message( 'tools/call', array( 'name' => 'echo', 'arguments' => array() ) ),
			self::ADMIN
		);

		$this->assertTrue( $response->body['result']['isError'] );
		$this->assertStringContainsString( 'is required', $response->body['result']['content'][0]['text'] );
	}

	/**
	 * An exception can carry a DSN, a query or a credential, and this
	 * response goes to a language model and often into a transcript.
	 */
	public function test_a_thrown_exception_never_leaks_its_message(): void {
		$response = $this->server()->handle(
			$this->message( 'tools/call', array( 'name' => 'explodes', 'arguments' => array() ) ),
			self::ADMIN
		);

		$text = $response->body['result']['content'][0]['text'];

		$this->assertTrue( $response->body['result']['isError'] );
		$this->assertStringNotContainsString( 'hunter2', $text );
		$this->assertStringNotContainsString( 'mysql://', $text );
		$this->assertStringNotContainsString( '10.0.0.5', $text );
	}

	/* ─── Auditing ──────────────────────────────────────────────────── */

	public function test_every_call_reaches_the_auditor(): void {
		$seen   = array();
		$server = $this->server()->audit(
			static function ( string $tool, array $args, ?Identity $who, bool $ok ) use ( &$seen ): void {
				$seen[] = array( $tool, $who?->id, $ok );
			}
		);

		$server->handle( $this->message( 'tools/call', array( 'name' => 'echo', 'arguments' => array( 'message' => 'a' ) ) ), self::ADMIN );
		$server->handle( $this->message( 'tools/call', array( 'name' => 'explodes', 'arguments' => array() ) ), self::ADMIN );
		$server->handle( $this->message( 'tools/call', array( 'name' => 'echo', 'arguments' => array() ) ), self::ADMIN );

		$this->assertSame(
			array(
				array( 'echo', 'admin', true ),
				array( 'explodes', 'admin', false ),
				array( 'echo', 'admin', false ),
			),
			$seen
		);
	}

	/* ─── Registration ──────────────────────────────────────────────── */

	public function test_a_duplicate_tool_name_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		$server = new Server( new Implementation( 'test' ) );
		$tool   = new Tool( 'same', 'x', array( 'type' => 'object' ), static fn(): ToolResult => ToolResult::text( '' ) );

		$server->add( $tool )->add( $tool );
	}

	#[DataProvider( 'bad_tool_names' )]
	public function test_a_malformed_tool_name_is_refused( string $name ): void {
		$this->expectException( InvalidArgumentException::class );

		new Tool( $name, 'x', array( 'type' => 'object' ), static fn(): ToolResult => ToolResult::text( '' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function bad_tool_names(): array {
		return array(
			'space'   => array( 'my tool' ),
			'comma'   => array( 'a,b' ),
			'slash'   => array( 'a/b' ),
			'empty'   => array( '' ),
			'too long' => array( str_repeat( 'a', 129 ) ),
		);
	}

	public function test_an_input_schema_must_be_an_object_schema(): void {
		$this->expectException( InvalidArgumentException::class );

		new Tool( 'ok', 'x', array( 'type' => 'string' ), static fn(): ToolResult => ToolResult::text( '' ) );
	}

	/* ─── Identity ──────────────────────────────────────────────────── */

	public function test_a_wildcard_scope_grants_everything(): void {
		$identity = new Identity( 'root', array( '*' ) );

		$this->assertTrue( $identity->has( 'anything' ) );
		$this->assertTrue( $identity->has_all( array( 'read', 'write', 'delete' ) ) );
	}

	public function test_scopes_are_checked_individually(): void {
		$identity = new Identity( 'partial', array( 'read' ) );

		$this->assertTrue( $identity->has( 'read' ) );
		$this->assertFalse( $identity->has( 'write' ) );
		$this->assertFalse( $identity->has_all( array( 'read', 'write' ) ) );
	}

	/* ─── Results ───────────────────────────────────────────────────── */

	public function test_structured_results_repeat_the_json_as_text(): void {
		$result = ToolResult::structured( array( 'a' => 1 ) );

		$this->assertSame( array( 'a' => 1 ), $result->structured );
		$this->assertSame( '{"a":1}', $result->content[0]['text'] );
	}

	public function test_a_structured_result_can_carry_a_summary(): void {
		$result = ToolResult::structured( array( 'a' => 1 ), 'One thing.' );

		$this->assertSame( 'One thing.', $result->content[0]['text'] );
		$this->assertSame( array( 'a' => 1 ), $result->structured );
	}

	public function test_content_blocks_are_shaped_correctly(): void {
		$this->assertSame( array( 'type' => 'text', 'text' => 'x' ), Content::text( 'x' ) );
		$this->assertSame( base64_encode( 'bytes' ), Content::image( 'bytes' )['data'] );
		$this->assertSame( 'audio/wav', Content::audio( 'bytes' )['mimeType'] );
		$this->assertSame( 'resource_link', Content::resource_link( 'file:///a', 'a' )['type'] );
	}

	/**
	 * `"properties": []` is not a JSON Schema object; `{}` is.
	 */
	public function test_a_tool_with_no_arguments_lists_an_object_of_properties(): void {
		$tool = new \SugarCommerce\Mcp\Tool(
			name: 'ping',
			description: 'Ping.',
			input_schema: array( 'type' => 'object', 'properties' => array() ),
			handler: static fn (): \SugarCommerce\Mcp\ToolResult => \SugarCommerce\Mcp\ToolResult::text( 'pong' ),
		);

		$this->assertSame( '{"type":"object","properties":{}}', json_encode( $tool->definition()['inputSchema'] ) );
	}
	/* ─── Step-up ───────────────────────────────────────────────────── */

	public function test_missing_scopes_names_what_a_call_needs(): void {
		$server = $this->server();
		$reader = new Identity( 'reader', array( 'read' ) );

		$this->assertSame( array( 'write' ), $server->missing_scopes( $this->message( 'tools/call', array( 'name' => 'destroy' ) ), $reader ) );
		$this->assertSame( array( 'write' ), $server->missing_scopes( $this->message( 'tools/call', array( 'name' => 'destroy' ) ), null ) );
		$this->assertSame( array(), $server->missing_scopes( $this->message( 'tools/call', array( 'name' => 'echo' ) ), $reader ) );
		$this->assertSame( array(), $server->missing_scopes( $this->message( 'tools/call', array( 'name' => 'nope' ) ), $reader ) );
		$this->assertSame( array(), $server->missing_scopes( $this->message( 'tools/call', array( 'name' => array( 'destroy' ) ) ), $reader ) );
		$this->assertSame( array(), $server->missing_scopes( $this->message( 'tools/list' ), $reader ) );
		$this->assertSame( array(), $server->missing_scopes( $this->message( 'tools/call', array( 'name' => 'destroy' ) ), new Identity( 'admin', array( '*' ) ) ) );
	}
}
