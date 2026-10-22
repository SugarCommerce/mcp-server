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

	/**
	 * A legacy client's request after initialize: the version in the
	 * header, no per-request _meta.
	 *
	 * @param array<string, mixed> $params
	 */
	private function legacy( string $method, array $params = array() ): array {
		return array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params );
	}

	/**
	 * 2025-11-25 "Version Negotiation": echo a version the server speaks.
	 */
	#[DataProvider( 'legacy_versions' )]
	public function test_initialize_echoes_a_legacy_version_it_speaks( string $version ): void {
		$response = $this->server()->handle( $this->legacy( 'initialize', array( 'protocolVersion' => $version ) ), self::ADMIN );

		$this->assertSame( $version, $response->body['result']['protocolVersion'] );
		$this->assertArrayHasKey( 'tools', $response->body['result']['capabilities'] );
		$this->assertSame( 'test', $response->body['result']['serverInfo']['name'] );
		$this->assertArrayNotHasKey( 'resultType', $response->body['result'], 'Legacy results have no resultType.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function legacy_versions(): array {
		return array( '2025-11-25' => array( '2025-11-25' ), '2025-06-18' => array( '2025-06-18' ) );
	}

	/**
	 * Otherwise the latest legacy one — never 2026-07-28, which has no
	 * initialize.
	 */
	public function test_initialize_answers_an_unknown_version_with_the_latest_legacy_one(): void {
		foreach ( array( '2024-11-05', '2025-03-26', '2026-07-28', '2099-01-01', '' ) as $asked ) {
			$response = $this->server()->handle( $this->legacy( 'initialize', '' === $asked ? array() : array( 'protocolVersion' => $asked ) ), self::ADMIN );

			$this->assertSame( '2025-11-25', $response->body['result']['protocolVersion'], $asked );
		}
	}

	/**
	 * A legacy session, as Claude's and ChatGPT's connectors run one: the
	 * header names the version, there is no _meta, and results are shaped
	 * for that revision.
	 */
	#[DataProvider( 'legacy_versions' )]
	public function test_a_legacy_client_lists_and_calls_tools( string $version ): void {
		$server = $this->server();
		$list   = $server->handle( $this->legacy( 'tools/list' ), self::READER, $version );
		$call   = $server->handle( $this->legacy( 'tools/call', array( 'name' => 'echo', 'arguments' => array( 'message' => 'hi' ) ) ), self::READER, $version );
		$ping   = $server->handle( $this->legacy( 'ping' ), self::READER, $version );

		$this->assertSame( array( 'echo', 'explodes' ), array_column( $list->body['result']['tools'], 'name' ) );
		$this->assertArrayNotHasKey( 'resultType', $list->body['result'] );
		$this->assertArrayNotHasKey( 'ttlMs', $list->body['result'] );
		$this->assertArrayNotHasKey( '_meta', $list->body['result'] );
		$this->assertSame( 'hi', $call->body['result']['content'][0]['text'] );
		$this->assertArrayNotHasKey( 'resultType', $call->body['result'] );
		$this->assertSame( '{}', json_encode( $ping->body['result'] ) );
	}

	public function test_a_legacy_request_in_an_unsupported_version_is_refused(): void {
		$response = $this->server()->handle( $this->legacy( 'tools/list' ), self::ADMIN, '2025-03-26' );

		$this->assertSame( 400, $response->status );
		$this->assertSame( Protocol::UNSUPPORTED_PROTOCOL_VERSION, $response->body['error']['code'] );
		$this->assertSame( Protocol::SUPPORTED, $response->body['error']['data']['supported'] );
	}

	/**
	 * 2026-07-28 removed ping.
	 */
	public function test_a_modern_ping_is_not_found(): void {
		$response = $this->server()->handle( $this->message( 'ping' ), self::ADMIN );

		$this->assertSame( Protocol::METHOD_NOT_FOUND, $response->body['error']['code'] );
	}

	public function test_server_discover_names_every_version(): void {
		$response = $this->server()->handle( $this->message( 'server/discover' ), self::ADMIN );
		$result   = $response->body['result'];

		$this->assertSame( array( '2026-07-28', '2025-11-25', '2025-06-18' ), $result['supportedVersions'] );
		$this->assertSame( 'complete', $result['resultType'] );
		$this->assertSame( 'test', $result['_meta'][ Protocol::META_SERVER_INFO ]['name'] );
		$this->assertArrayHasKey( 'ttlMs', $result );
		$this->assertSame( 'public', $result['cacheScope'] );

		// Even asked in a version it does not speak: it is how a client finds out.
		$this->assertFalse( $this->server()->handle( $this->message( 'server/discover', array(), 1, '2099-01-01' ), self::ADMIN )->is_error() );
	}

	public function test_a_modern_tool_list_is_a_private_cacheable_result(): void {
		$result = $this->server()->handle( $this->message( 'tools/list' ), self::READER )->body['result'];

		$this->assertSame( Server::TTL_MS, $result['ttlMs'] );
		$this->assertSame( 'private', $result['cacheScope'] );
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
			'server/discover' => array( 'server/discover', array() ),
			'tools/list'      => array( 'tools/list', array() ),
			'tools/call' => array( 'tools/call', array( 'name' => 'echo', 'arguments' => array( 'message' => 'hi' ) ) ),
		);
	}

	public function test_results_identify_the_server(): void {
		$response = $this->server()->handle( $this->message( 'tools/list' ), self::ADMIN );

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

	public function test_an_unknown_revision_is_refused_with_the_list(): void {
		$response = $this->server()->handle( $this->message( 'tools/list', array(), 1, '2025-03-26' ), self::ADMIN );

		$this->assertSame( Protocol::UNSUPPORTED_PROTOCOL_VERSION, $response->body['error']['code'] );
		$this->assertSame( Protocol::SUPPORTED, $response->body['error']['data']['supported'] );
		$this->assertSame( '2025-03-26', $response->body['error']['data']['requested'] );
	}

	public function test_a_request_with_no_version_anywhere_is_refused(): void {
		$response = $this->server()->handle( $this->legacy( 'tools/list' ), self::ADMIN );

		$this->assertSame( 400, $response->status );
		$this->assertSame( Protocol::INVALID_PARAMS, $response->body['error']['code'] );
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

		foreach ( $tools as $tool ) {
			$this->assertSame( array( 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint' ), array_keys( $tool['annotations'] ), 'Every hint is explicit.' );
		}
	}

	public function test_a_title_and_an_open_world_hint_are_published(): void {
		$tool = new Tool(
			name: 'send',
			description: 'Send an email.',
			input_schema: array( 'type' => 'object' ),
			handler: static fn(): ToolResult => ToolResult::text( 'sent' ),
			title: 'Send an email',
			open_world: true,
		);

		$definition = $tool->definition();

		$this->assertSame( 'Send an email', $definition['title'] );
		$this->assertSame( 'Send an email', $definition['annotations']['title'] );
		$this->assertTrue( $definition['annotations']['openWorldHint'] );
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

	/* ─── Arguments and results ─────────────────────────────────────── */

	public function test_arguments_that_are_not_an_object_are_refused(): void {
		$response = $this->server()->handle(
			$this->message( 'tools/call', array( 'name' => 'echo', 'arguments' => array( 'hello' ) ) ),
			self::ADMIN
		);

		$this->assertTrue( $response->body['result']['isError'] );
		$this->assertStringContainsString( 'must be an object', $response->body['result']['content'][0]['text'] );
	}

	/**
	 * A handler that returns something other than a result or text is a
	 * bug; it is answered as one failed call, not as "Array" with a warning.
	 */
	public function test_a_handler_returning_neither_a_result_nor_text_fails_generically(): void {
		$server = new Server( new Implementation( 'test' ) );

		$server->add( new Tool(
			name: 'rows',
			description: 'Returns rows by mistake.',
			input_schema: array( 'type' => 'object' ),
			handler: static fn(): array => array( array( 'password' => 'hunter2' ) ),
		) );
		$server->add( new Tool(
			name: 'number',
			description: 'Returns a number.',
			input_schema: array( 'type' => 'object' ),
			handler: static fn(): int => 42,
		) );

		$rows = $server->handle( $this->message( 'tools/call', array( 'name' => 'rows' ) ) );

		$this->assertTrue( $rows->body['result']['isError'] );
		$this->assertSame( 'The tool failed to run.', $rows->body['result']['content'][0]['text'] );
		$this->assertSame( '42', $server->handle( $this->message( 'tools/call', array( 'name' => 'number' ) ) )->body['result']['content'][0]['text'] );
	}

	public function test_request_object_keeps_only_named_members(): void {
		$request = Request::from_array( array( 'method' => 'tools/call', 'id' => 1, 'params' => array( 'arguments' => array( 'a' => 1, 7 => 2 ) ) ) );

		$this->assertSame( array( 'a' => 1 ), $request->object( 'arguments' ) );
		$this->assertSame( array(), $request->object( 'missing' ) );
	}
}
