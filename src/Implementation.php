<?php
/**
 * A name and version pair identifying a client or server.
 *
 * @package   SugarMill\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\Mcp;

/**
 * Class Implementation
 *
 * Self-reported by whoever sends it and verified by nothing. Useful for
 * display, logging and debugging; never for a security decision, and
 * never as a reason to change behaviour.
 *
 * @since 1.0.0
 */
final readonly class Implementation {

	/**
	 * @since 1.0.0
	 *
	 * @param string $name    Implementation name.
	 * @param string $version Implementation version.
	 * @param string $title   Optional display name.
	 */
	public function __construct(
		public string $name,
		public string $version = '1.0.0',
		public string $title = '',
	) {}

	/**
	 * The wire form.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		$data = array( 'name' => $this->name, 'version' => $this->version );

		if ( '' !== $this->title ) {
			$data['title'] = $this->title;
		}

		return $data;
	}

	/**
	 * Build from a decoded payload.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Decoded value.
	 *
	 * @return self|null Null when absent or malformed.
	 */
	public static function from( mixed $value ): ?self {
		if ( ! is_array( $value ) || ! is_string( $value['name'] ?? null ) ) {
			return null;
		}

		return new self(
			$value['name'],
			is_string( $value['version'] ?? null ) ? $value['version'] : '',
			is_string( $value['title'] ?? null ) ? $value['title'] : '',
		);
	}
}
