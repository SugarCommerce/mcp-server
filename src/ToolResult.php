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
	 * Most characters of JSON a summarised text block carries by default.
	 *
	 * Twelve thousand: a detailed record with its children fits, and a page
	 * of fifty rows mostly does.
	 *
	 * @since 1.1.0
	 */
	public const int SUMMARY_LIMIT = 12000;

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
		$encoded = (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );

		return new self( array( Content::text( '' !== $summary ? $summary : $encoded ) ), $data );
	}

	/**
	 * A structured result whose text block is a heading and the JSON, capped.
	 *
	 * Many clients read only the text block. A text block that says "done"
	 * and nothing else tells such a client's model the call worked and gives
	 * it nothing to work with; one that is the whole of a two-hundred-row
	 * page spends a large share of its context on a list it will mostly not
	 * read. So the text is the JSON after a one-line heading, cut at a
	 * character limit — by character, never through a multibyte one — and
	 * past the limit it says so, and where the rest is, rather than ending
	 * mid-string as though that were the whole answer.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed  $data    Any JSON-serialisable value.
	 * @param string $heading First line of the text block, e.g. "list_files: done.".
	 * @param int    $limit   Characters of JSON the text block carries.
	 *
	 * @return self
	 */
	public static function summarised( mixed $data, string $heading, int $limit = self::SUMMARY_LIMIT ): self {
		return new self( array( Content::text( self::summary( $data, $heading, $limit ) ) ), $data );
	}

	/**
	 * The text block summarised() writes.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed  $data    Any JSON-serialisable value.
	 * @param string $heading First line.
	 * @param int    $limit   Characters of JSON to keep.
	 *
	 * @return string
	 */
	public static function summary( mixed $data, string $heading, int $limit = self::SUMMARY_LIMIT ): string {
		$json = (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE );

		// Characters, not bytes: every byte that is not a UTF-8 continuation
		// byte starts one. json_encode() only ever writes valid UTF-8.
		$length = strlen( $json ) - (int) preg_match_all( '/[\x80-\xBF]/', $json );
		$limit  = max( 0, $limit );

		if ( $length <= $limit ) {
			return $heading . "\n" . $json;
		}

		return sprintf(
			"%s\n%s\n… truncated: %d of %d characters shown. The full result is in structuredContent; narrow the call (a search, a filter, a smaller page) to see the rest as text.",
			$heading,
			self::first_characters( $json, $limit ),
			$limit,
			$length
		);
	}

	/**
	 * The first characters of a UTF-8 string, never cutting through one.
	 *
	 * Without mbstring, which this package does not require.
	 *
	 * @param string $text  Valid UTF-8.
	 * @param int    $count Characters to keep.
	 *
	 * @return string
	 */
	private static function first_characters( string $text, int $count ): string {
		$bytes = 0;
		$size  = strlen( $text );

		for ( $kept = 0; $kept < $count && $bytes < $size; ++$kept ) {
			$lead   = ord( $text[ $bytes ] );
			$bytes += match ( true ) {
				$lead < 0x80 => 1,
				$lead < 0xE0 => 2,
				$lead < 0xF0 => 3,
				default      => 4,
			};
		}

		return substr( $text, 0, $bytes );
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
