<?php
/**
 * A parsed JSON-RPC request.
 *
 * @package   SugarMill\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\Mcp;

/**
 * Class Request
 *
 * One message, self-contained. The 2026-07-28 revision made the protocol
 * stateless: nothing may be inferred from an earlier request, even on the
 * same connection, so the protocol version and client capabilities travel
 * in `_meta` on every single call.
 *
 * @since 1.0.0
 */
final readonly class Request {

	/**
	 * @since 1.0.0
	 *
	 * @param string               $method Method name.
	 * @param array<string, mixed> $params Parameters.
	 * @param string|int|null      $id     Request id; null for a notification.
	 * @param array<string, mixed> $meta   The `_meta` block from params.
	 */
	public function __construct(
		public string $method,
		public array $params = array(),
		public string|int|null $id = null,
		public array $meta = array(),
	) {}

	/**
	 * Parse a decoded JSON-RPC message.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $message Decoded message.
	 *
	 * @return self
	 */
	public static function from_array( array $message ): self {
		$params = is_array( $message['params'] ?? null ) ? $message['params'] : array();
		$meta   = is_array( $params['_meta'] ?? null ) ? $params['_meta'] : array();
		$id     = $message['id'] ?? null;

		return new self(
			is_string( $message['method'] ?? null ) ? $message['method'] : '',
			$params,
			is_string( $id ) || is_int( $id ) ? $id : null,
			$meta
		);
	}

	/**
	 * Whether this is a notification, which must not be answered.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_notification(): bool {
		return null === $this->id;
	}

	/**
	 * The protocol revision this request declares.
	 *
	 * @since 1.0.0
	 *
	 * @return string Empty when absent, which makes the request malformed.
	 */
	public function protocol_version(): string {
		$version = $this->meta[ Protocol::META_PROTOCOL_VERSION ] ?? null;

		return is_string( $version ) ? $version : '';
	}

	/**
	 * The capabilities the client declares for this request.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>|null Null when absent.
	 */
	public function client_capabilities(): ?array {
		$capabilities = $this->meta[ Protocol::META_CLIENT_CAPABILITIES ] ?? null;

		return is_array( $capabilities ) ? $capabilities : null;
	}

	/**
	 * Who the client says it is.
	 *
	 * @since 1.0.0
	 *
	 * @return Implementation|null
	 */
	public function client(): ?Implementation {
		return Implementation::from( $this->meta[ Protocol::META_CLIENT_INFO ] ?? null );
	}

	/**
	 * A parameter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Parameter name.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	public function param( string $key, mixed $default = null ): mixed {
		return $this->params[ $key ] ?? $default;
	}

	/**
	 * A string parameter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Parameter name.
	 *
	 * @return string Empty when absent or not a string.
	 */
	public function string( string $key ): string {
		$value = $this->params[ $key ] ?? null;

		return is_string( $value ) ? $value : '';
	}

	/**
	 * An array parameter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Parameter name.
	 *
	 * @return array<mixed>
	 */
	public function array( string $key ): array {
		$value = $this->params[ $key ] ?? null;

		return is_array( $value ) ? $value : array();
	}
}
