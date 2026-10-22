<?php
/**
 * A tool the model may call.
 *
 * @package   SugarCommerce\Mcp
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mcp;

/**
 * Class Tool
 *
 * A name, a JSON Schema for its arguments, a handler, and the scopes a
 * caller needs to see or run it.
 *
 * The description is not documentation — it is the prompt. It is the only
 * thing the model reads when deciding whether this tool answers the
 * question in front of it, so it should say what the tool does, when to
 * reach for it, and anything it will refuse.
 *
 * @since 1.0.0
 */
final readonly class Tool {

	/**
	 * @since 1.0.0
	 *
	 * @param string               $name         Unique identifier.
	 * @param string               $description  What it does, for the model.
	 * @param array<string, mixed> $input_schema JSON Schema for arguments.
	 *                                           Must be an object schema;
	 *                                           use
	 *                                           `['type'=>'object','additionalProperties'=>false]`
	 *                                           for a tool with none.
	 * @param callable(array<string, mixed>, Identity|null): mixed $handler
	 *                                           Runs the tool with the
	 *                                           validated arguments and the
	 *                                           caller, returning a
	 *                                           ToolResult — or a string,
	 *                                           number or Stringable, sent
	 *                                           as text.
	 * @param string               $title        Display name.
	 * @param array<string, mixed> $output_schema Schema for structured output.
	 * @param string[]             $scopes       Scopes required to see and call it.
	 * @param bool                 $read_only    Whether it only reads. Surfaced
	 *                                           as an annotation so a client can
	 *                                           decide what needs confirming.
	 * @param bool                 $destructive  Whether it can destroy data.
	 * @param bool                 $idempotent   Whether repeating it is harmless.
	 * @param bool                 $open_world   Whether it reaches beyond this
	 *                                           server's own data — sends an
	 *                                           email, calls another service,
	 *                                           makes something the outside
	 *                                           world can use. Since 1.3.0.
	 *
	 * @throws \InvalidArgumentException On a malformed name or schema.
	 */
	public function __construct(
		public string $name,
		public string $description,
		public array $input_schema,
		public mixed $handler,
		public string $title = '',
		public array $output_schema = array(),
		public array $scopes = array(),
		public bool $read_only = false,
		public bool $destructive = false,
		public bool $idempotent = false,
		public bool $open_world = false,
	) {
		// The specification allows letters, digits, underscore, hyphen and
		// dot, up to 128 characters. A name outside that is liable to be
		// rejected or mangled by a client.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_.\-]{1,128}$/', $name ) ) {
			throw new \InvalidArgumentException(
				'A tool name must be 1-128 characters of letters, digits, underscore, hyphen or dot: ' . $name
			);
		}

		// `inputSchema` must be a valid JSON Schema object, never null —
		// clients validate arguments against it before calling.
		if ( 'object' !== ( $input_schema['type'] ?? null ) ) {
			throw new \InvalidArgumentException( 'The input schema for "' . $name . '" must be an object schema.' );
		}

		// @phpstan-ignore function.alreadyNarrowedType (a caller without static analysis can pass anything)
		if ( ! is_callable( $handler ) ) {
			throw new \InvalidArgumentException( 'The handler for "' . $name . '" is not callable.' );
		}
	}

	/**
	 * The definition as `tools/list` returns it.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		$schema = $this->input_schema;

		// An empty PHP array encodes as `[]`, which is not a schema object,
		// and a client that validates tools/list refuses the whole list over
		// one tool that takes no arguments.
		if ( array() === ( $schema['properties'] ?? null ) ) {
			$schema['properties'] = new \stdClass();
		}

		$definition = array(
			'name'        => $this->name,
			'description' => $this->description,
			'inputSchema' => $schema,
		);

		if ( '' !== $this->title ) {
			$definition['title'] = $this->title;
		}

		if ( array() !== $this->output_schema ) {
			$definition['outputSchema'] = $this->output_schema;
		}

		// Every hint explicit, never left to the spec's defaults (which
		// assume the worst: destructive and open-world), because directory
		// reviewers and confirmation prompts read them as stated. A title,
		// when given, rides in the annotations too, where 2025-03-26-era
		// clients look for it.
		$definition['annotations'] = array(
			'readOnlyHint'    => $this->read_only,
			'destructiveHint' => $this->destructive,
			'idempotentHint'  => $this->idempotent,
			'openWorldHint'   => $this->open_world,
		) + ( '' === $this->title ? array() : array( 'title' => $this->title ) );

		return $definition;
	}

	/**
	 * Whether an identity holds every scope this tool requires.
	 *
	 * @since 1.0.0
	 *
	 * @param Identity|null $identity Caller, or null when unauthenticated.
	 *
	 * @return bool
	 */
	public function permits( ?Identity $identity ): bool {
		if ( array() === $this->scopes ) {
			return true;
		}

		return null !== $identity && $identity->has_all( $this->scopes );
	}

	/**
	 * Run it.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $arguments Validated arguments.
	 * @param Identity|null        $identity  Caller.
	 *
	 * @return ToolResult
	 *
	 * @throws \UnexpectedValueException When the handler returns something
	 *                                   that is neither a result nor text.
	 */
	public function call( array $arguments, ?Identity $identity ): ToolResult {
		$result = ( $this->handler )( $arguments, $identity );

		if ( $result instanceof ToolResult ) {
			return $result;
		}

		if ( is_string( $result ) || is_int( $result ) || is_float( $result ) || $result instanceof \Stringable ) {
			return ToolResult::text( (string) $result );
		}

		throw new \UnexpectedValueException( 'The handler for "' . $this->name . '" returned ' . get_debug_type( $result ) . ', not a ToolResult.' );
	}
}
