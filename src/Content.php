<?php
/**
 * Content block builders.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp;

/**
 * Class Content
 *
 * The block types a tool result may contain.
 *
 * @since 1.0.0
 */
final readonly class Content {

	/**
	 * A text block.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Text.
	 *
	 * @return array<string, mixed>
	 */
	public static function text( string $text ): array {
		return array( 'type' => 'text', 'text' => $text );
	}

	/**
	 * An image block.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data      Raw bytes; base64-encoded here.
	 * @param string $mime_type Content type.
	 *
	 * @return array<string, mixed>
	 */
	public static function image( string $data, string $mime_type = 'image/png' ): array {
		return array( 'type' => 'image', 'data' => base64_encode( $data ), 'mimeType' => $mime_type );
	}

	/**
	 * An audio block.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data      Raw bytes; base64-encoded here.
	 * @param string $mime_type Content type.
	 *
	 * @return array<string, mixed>
	 */
	public static function audio( string $data, string $mime_type = 'audio/wav' ): array {
		return array( 'type' => 'audio', 'data' => base64_encode( $data ), 'mimeType' => $mime_type );
	}

	/**
	 * A link to a resource the client may fetch.
	 *
	 * Cheaper than embedding when the payload is large or the client may
	 * not need it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $uri         Resource URI.
	 * @param string $name        Short name.
	 * @param string $description What it is.
	 * @param string $mime_type   Content type.
	 *
	 * @return array<string, mixed>
	 */
	public static function resource_link( string $uri, string $name, string $description = '', string $mime_type = '' ): array {
		$block = array( 'type' => 'resource_link', 'uri' => $uri, 'name' => $name );

		if ( '' !== $description ) {
			$block['description'] = $description;
		}

		if ( '' !== $mime_type ) {
			$block['mimeType'] = $mime_type;
		}

		return $block;
	}

	/**
	 * An embedded resource.
	 *
	 * @since 1.0.0
	 *
	 * @param string $uri       Resource URI.
	 * @param string $text      Contents.
	 * @param string $mime_type Content type.
	 *
	 * @return array<string, mixed>
	 */
	public static function resource( string $uri, string $text, string $mime_type = 'text/plain' ): array {
		return array(
			'type'     => 'resource',
			'resource' => array( 'uri' => $uri, 'text' => $text, 'mimeType' => $mime_type ),
		);
	}
}
