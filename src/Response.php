<?php
/**
 * JSON-RPC response envelopes.
 *
 * @package   Sugar\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace Sugar\Mcp;

/**
 * Class Response
 *
 * A JSON-RPC envelope plus the HTTP status that carries it.
 *
 * @since 1.0.0
 */
final readonly class Response {

	/**
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $body   Envelope, or null for no content.
	 * @param int                       $status HTTP status.
	 */
	public function __construct(
		public ?array $body,
		public int $status = 200,
	) {}

	/**
	 * A successful result.
	 *
	 * Every result carries `resultType`, which the 2026-07-28 revision
	 * requires so a client can tell a completed call from one awaiting
	 * more input without inspecting the shape.
	 *
	 * @since 1.0.0
	 *
	 * @param string|int|null      $id         Request id.
	 * @param array<string, mixed> $result     Result payload.
	 * @param Implementation|null  $server     Server identity for `_meta`.
	 * @param string               $resultType `complete` or `input_required`.
	 *
	 * @return self
	 */
	public static function result(
		string|int|null $id,
		array $result,
		?Implementation $server = null,
		string $resultType = 'complete'
	): self {
		$result = array_merge( array( 'resultType' => $resultType ), $result );

		if ( null !== $server ) {
			$result['_meta'] = array_merge(
				is_array( $result['_meta'] ?? null ) ? $result['_meta'] : array(),
				array( Protocol::META_SERVER_INFO => $server->to_array() )
			);
		}

		return new self( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ) );
	}

	/**
	 * An error.
	 *
	 * @since 1.0.0
	 *
	 * @param string|int|null $id      Request id.
	 * @param int             $code    Error code.
	 * @param string          $message Human-readable message.
	 * @param mixed           $data    Extra detail.
	 * @param int             $status  HTTP status.
	 *
	 * @return self
	 */
	public static function error(
		string|int|null $id,
		int $code,
		string $message,
		mixed $data = null,
		int $status = 200
	): self {
		$error = array( 'code' => $code, 'message' => $message );

		if ( null !== $data ) {
			$error['data'] = $data;
		}

		return new self( array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => $error ), $status );
	}

	/**
	 * No content — the correct reply to a notification.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function accepted(): self {
		return new self( null, 202 );
	}

	/**
	 * Whether this response carries an error.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_error(): bool {
		return isset( $this->body['error'] );
	}

	/**
	 * The envelope as JSON.
	 *
	 * @since 1.0.0
	 *
	 * @return string Empty for a no-content response.
	 */
	public function json(): string {
		if ( null === $this->body ) {
			return '';
		}

		return (string) json_encode( $this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
