<?php
/**
 * What a tool returns.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp;

/**
 * Class ToolResult
 *
 * The distinction that matters here is between a **tool execution error**
 * and a **protocol error**.
 *
 * A protocol error — unknown tool, malformed request — is a JSON-RPC
 * error, and a model can rarely do anything useful with it. A tool
 * execution error is a normal result carrying `isError: true`, and it is
 * handed to the model precisely so it can correct itself: "that date is
 * in the past, today is 1 August 2026" is actionable in a way that a
 * `-32602` is not.
 *
 * Returning a failed lookup as a JSON-RPC error is the common mistake,
 * and it silently removes the model's ability to retry.
 *
 * @since 1.0.0
 */
final readonly class ToolResult {

	/**
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $content    Content blocks.
	 * @param mixed                            $structured Structured payload,
	 *                                                     matching the tool's
	 *                                                     output schema.
	 * @param bool                             $is_error   Whether the tool failed.
	 */
	public function __construct(
		public array $content = array(),
		public mixed $structured = null,
		public bool $is_error = false,
	) {}

	/**
	 * A plain-text result.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Text.
	 *
	 * @return self
	 */
	public static function text( string $text ): self {
		return new self( array( Content::text( $text ) ) );
	}

	/**
	 * A structured result.
	 *
	 * The JSON is repeated as a text block, which the specification
	 * recommends for clients that do not read `structuredContent`.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $data    Any JSON-serialisable value.
	 * @param string $summary Optional prose for the text block; the
	 *                        encoded JSON is used when omitted.
	 *
	 * @return self
	 */
	public static function structured( mixed $data, string $summary = '' ): self {
		$encoded = (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return new self( array( Content::text( '' !== $summary ? $summary : $encoded ) ), $data );
	}

	/**
	 * A failure the model should see and can act on.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message What went wrong, in terms the model can use.
	 *
	 * @return self
	 */
	public static function failed( string $message ): self {
		return new self( array( Content::text( $message ) ), null, true );
	}

	/**
	 * The wire form.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$result = array( 'content' => $this->content, 'isError' => $this->is_error );

		if ( null !== $this->structured ) {
			$result['structuredContent'] = $this->structured;
		}

		return $result;
	}
}
