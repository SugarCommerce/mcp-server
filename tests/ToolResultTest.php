<?php
/**
 * What a tool returns.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.1.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Mcp\ToolResult;

/**
 * Class ToolResultTest
 *
 * The text block of a summarised result: the data for clients that read
 * nothing else, and never so much of it that one call fills a model's
 * context.
 */
#[CoversClass( ToolResult::class )]
final class ToolResultTest extends TestCase {

	/* ─── Summaries ─────────────────────────────────────────────────── */

	public function test_a_short_result_is_given_whole_after_its_heading(): void {
		$this->assertSame(
			"list_files: done.\n{\"files\":[{\"id\":1,\"name\":\"a/b.wav\",\"gain\":1.0}]}",
			ToolResult::summary( array( 'files' => array( array( 'id' => 1, 'name' => 'a/b.wav', 'gain' => 1.0 ) ) ), 'list_files: done.' )
		);
	}

	public function test_a_long_result_is_cut_and_says_where_the_rest_is(): void {
		$text = ToolResult::summary( array( 'rows' => str_repeat( 'x', 500 ) ), 'list_orders: done.', 100 );

		$this->assertStringStartsWith( "list_orders: done.\n{\"rows\":\"xxx", $text );
		$this->assertStringContainsString( 'truncated: 100 of 511 characters shown', $text );
		$this->assertStringContainsString( 'structuredContent', $text );
	}

	/**
	 * Counted and cut by character, never through the middle of one.
	 */
	public function test_truncation_keeps_multibyte_characters_whole(): void {
		$text = ToolResult::summary( array( 'name' => str_repeat( 'é', 25 ) . str_repeat( '😀', 25 ) ), 'get_order: done.', 40 );

		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ) );
		$this->assertStringContainsString( 'truncated: 40 of 61 characters shown', $text );
		$this->assertSame( '{"name":"' . str_repeat( 'é', 25 ) . str_repeat( '😀', 6 ), explode( "\n", $text )[1] );
	}

	public function test_exactly_the_limit_is_not_truncated(): void {
		$this->assertStringNotContainsString( 'truncated', ToolResult::summary( 'abcdefgh', 'h', 10 ) );
		$this->assertStringContainsString( 'truncated: 0 of 10', ToolResult::summary( 'abcdefgh', 'h', -5 ) );
	}

	public function test_the_structured_block_is_the_data(): void {
		$result = ToolResult::summarised( array( 'ok' => true ), 'resend_order: done.' )->to_array();

		$this->assertSame( array( 'ok' => true ), $result['structuredContent'] );
		$this->assertSame( "resend_order: done.\n{\"ok\":true}", $result['content'][0]['text'] );
		$this->assertFalse( $result['isError'] );
	}

	public function test_the_default_limit_is_twelve_thousand(): void {
		$text = ToolResult::summary( str_repeat( 'a', 13000 ), 'h' );

		$this->assertStringContainsString( 'truncated: 12000 of 13002 characters shown', $text );
		$this->assertSame( ToolResult::SUMMARY_LIMIT, 12000 );
	}
}
