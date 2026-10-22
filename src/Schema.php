<?php
/**
 * Argument validation against a tool's input schema.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp;

/**
 * Class Schema
 *
 * A deliberately small subset of JSON Schema 2020-12: types (one, or a
 * list of them), `required`,
 * `enum`, `additionalProperties`, string lengths and patterns, numeric
 * bounds, and array sizes.
 *
 * It is not a general validator and does not try to be. The specification
 * asks servers to validate their inputs, and the shapes a tool actually
 * declares are simple; a full implementation would mean `$ref` resolution
 * — which the specification then requires you to refuse to dereference
 * over the network — plus the composition keywords it warns can be turned
 * into a denial-of-service vector. Neither belongs in the request path of
 * a tool call.
 *
 * Validate here to catch the model's mistakes early, and validate again
 * in the handler for anything that matters.
 *
 * @since 1.0.0
 */
final readonly class Schema {

	/**
	 * An object schema for a tool's arguments.
	 *
	 * Closed by default: with `additionalProperties` left out, a model that
	 * misspells an argument has it silently ignored and the call does
	 * something other than what it meant. Closed, the typo is a retryable
	 * error naming the property.
	 *
	 * An empty property list is still an object, which is what a client
	 * expects of a tool that takes nothing.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, array<string, mixed>> $properties Argument schemas, by name.
	 * @param string[]                            $required   Names that must be given.
	 * @param bool                                $open       Whether other names are allowed.
	 *
	 * @return array<string, mixed>
	 */
	public static function object( array $properties, array $required = array(), bool $open = false ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_values( $required ),
			'additionalProperties' => $open,
		);
	}

	/**
	 * Check a value against a schema.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed                $value  Value to check.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Property path, for messages.
	 *
	 * @return list<string> Problems found; empty when valid.
	 */
	public static function validate( mixed $value, array $schema, string $path = '' ): array {
		$problems = array();
		$types    = self::types( $schema['type'] ?? null );
		$type     = null;

		// `type` may name one type or several (`["integer", "null"]`). The
		// value must be one of them; the first it is decides which of the
		// array and object rules apply.
		if ( null !== $types ) {
			foreach ( $types as $candidate ) {
				if ( self::is_type( $value, $candidate ) ) {
					$type = $candidate;
					break;
				}
			}

			if ( null === $type ) {
				return array( self::label( $path ) . ' must be ' . implode( ' or ', array_map( self::article( ... ), $types ) ) . '.' );
			}
		}

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$problems[] = self::label( $path ) . ' must be one of: '
				. implode( ', ', array_map( static fn( mixed $v ): string => is_scalar( $v ) ? (string) $v : '?', $schema['enum'] ) ) . '.';
		}

		if ( is_string( $value ) ) {
			$problems = array_merge( $problems, self::check_string( $value, $schema, $path ) );
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			$problems = array_merge( $problems, self::check_number( $value, $schema, $path ) );
		}

		if ( is_array( $value ) && 'array' === $type ) {
			$problems = array_merge( $problems, self::check_array( $value, $schema, $path ) );
		}

		if ( is_array( $value ) && 'object' === $type ) {
			$problems = array_merge( $problems, self::check_object( $value, $schema, $path ) );
		}

		return $problems;
	}

	/**
	 * The type names a schema's `type` keyword allows.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $type The keyword's value.
	 *
	 * @return list<string>|null Null when the schema names no type, so any
	 *                           value passes the type check. A `type` that
	 *                           is present but names nothing usable is a
	 *                           list nothing matches, never "anything".
	 */
	private static function types( mixed $type ): ?array {
		if ( null === $type ) {
			return null;
		}

		if ( is_string( $type ) ) {
			return array( $type );
		}

		$types = is_array( $type ) ? array_values( array_filter( $type, is_string( ... ) ) ) : array();

		return array() === $types ? array( 'valid type' ) : $types;
	}

	/**
	 * Object rules: required properties, per-property schemas, and
	 * whether unknown keys are allowed.
	 *
	 * @since 1.0.0
	 *
	 * @param array<mixed>         $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Property path.
	 *
	 * @return list<string>
	 */
	private static function check_object( array $value, array $schema, string $path ): array {
		$problems   = array();
		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();

		foreach ( ( is_array( $schema['required'] ?? null ) ? $schema['required'] : array() ) as $required ) {
			if ( is_string( $required ) && ! array_key_exists( $required, $value ) ) {
				$problems[] = self::label( self::join( $path, $required ) ) . ' is required.';
			}
		}

		foreach ( $properties as $name => $rules ) {
			if ( is_array( $rules ) && array_key_exists( $name, $value ) ) {
				$problems = array_merge( $problems, self::validate( $value[ $name ], self::schema( $rules ), self::join( $path, (string) $name ) ) );
			}
		}

		if ( false === ( $schema['additionalProperties'] ?? true ) ) {
			foreach ( array_keys( $value ) as $key ) {
				if ( ! array_key_exists( $key, $properties ) ) {
					$problems[] = 'Unknown property: ' . self::join( $path, (string) $key ) . '.';
				}
			}
		}

		return $problems;
	}

	/**
	 * String rules.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Property path.
	 *
	 * @return list<string>
	 */
	private static function check_string( string $value, array $schema, string $path ): array {
		$problems = array();
		$length   = self::length( $value );

		if ( is_int( $schema['minLength'] ?? null ) && $length < $schema['minLength'] ) {
			$problems[] = self::label( $path ) . ' must be at least ' . $schema['minLength'] . ' characters.';
		}

		if ( is_int( $schema['maxLength'] ?? null ) && $length > $schema['maxLength'] ) {
			$problems[] = self::label( $path ) . ' must be at most ' . $schema['maxLength'] . ' characters.';
		}

		// Delimited here, so a schema author writes a bare pattern and cannot
		// pass modifiers. Not anchored: as in JSON Schema, a pattern matches
		// anywhere unless it says `^…$`. A pattern that fails to compile, or
		// runs out of backtracking, fails the value rather than passing it.
		if ( is_string( $schema['pattern'] ?? null ) && 1 !== @preg_match( '/' . str_replace( '/', '\/', $schema['pattern'] ) . '/u', $value ) ) {
			$problems[] = self::label( $path ) . ' is not in the expected format.';
		}

		return $problems;
	}

	/**
	 * Numeric rules.
	 *
	 * @since 1.0.0
	 *
	 * @param int|float            $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Property path.
	 *
	 * @return list<string>
	 */
	private static function check_number( int|float $value, array $schema, string $path ): array {
		$problems = array();

		if ( is_numeric( $schema['minimum'] ?? null ) && $value < $schema['minimum'] ) {
			$problems[] = self::label( $path ) . ' must be at least ' . $schema['minimum'] . '.';
		}

		if ( is_numeric( $schema['maximum'] ?? null ) && $value > $schema['maximum'] ) {
			$problems[] = self::label( $path ) . ' must be at most ' . $schema['maximum'] . '.';
		}

		return $problems;
	}

	/**
	 * Array rules.
	 *
	 * @since 1.0.0
	 *
	 * @param array<mixed>         $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Property path.
	 *
	 * @return list<string>
	 */
	private static function check_array( array $value, array $schema, string $path ): array {
		$problems = array();

		if ( is_int( $schema['minItems'] ?? null ) && count( $value ) < $schema['minItems'] ) {
			$problems[] = self::label( $path ) . ' must have at least ' . $schema['minItems'] . ' items.';
		}

		if ( is_int( $schema['maxItems'] ?? null ) && count( $value ) > $schema['maxItems'] ) {
			$problems[] = self::label( $path ) . ' must have at most ' . $schema['maxItems'] . ' items.';
		}

		if ( is_array( $schema['items'] ?? null ) ) {
			foreach ( array_values( $value ) as $index => $item ) {
				$problems = array_merge( $problems, self::validate( $item, self::schema( $schema['items'] ), self::join( $path, (string) $index ) ) );
			}
		}

		return $problems;
	}

	/**
	 * Whether a value matches a JSON Schema type.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $value Value.
	 * @param string $type  Type name.
	 *
	 * @return bool
	 */
	private static function is_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			// JSON has one number type, so an integer satisfies "number".
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ),
			'null'    => null === $value,
			// A name JSON Schema does not have is a mistake in the schema,
			// and a mistake must not let every value through.
			default   => false,
		};
	}

	/**
	 * A subschema, with its keys as the validator reads them.
	 *
	 * @since 1.4.0
	 *
	 * @param array<mixed> $rules A decoded subschema.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema( array $rules ): array {
		$schema = array();

		foreach ( $rules as $key => $rule ) {
			$schema[ (string) $key ] = $rule;
		}

		return $schema;
	}

	/**
	 * Characters in a string, without mbstring.
	 *
	 * Every byte that is not a UTF-8 continuation byte starts a character.
	 *
	 * @since 1.4.0
	 *
	 * @param string $value Text.
	 *
	 * @return int
	 */
	private static function length( string $value ): int {
		return strlen( $value ) - (int) preg_match_all( '/[\x80-\xBF]/', $value );
	}

	/**
	 * A readable name for a path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Property path.
	 *
	 * @return string
	 */
	private static function label( string $path ): string {
		return '' === $path ? 'The value' : $path;
	}

	/**
	 * Join a path segment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path    Existing path.
	 * @param string $segment Segment to append.
	 *
	 * @return string
	 */
	private static function join( string $path, string $segment ): string {
		return '' === $path ? $segment : $path . '.' . $segment;
	}

	/**
	 * "a string", "an object".
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Type name.
	 *
	 * @return string
	 */
	private static function article( string $type ): string {
		return ( in_array( $type[0] ?? '', array( 'a', 'e', 'i', 'o', 'u' ), true ) ? 'an ' : 'a ' ) . $type;
	}
}
