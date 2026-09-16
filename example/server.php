<?php
/**
 * A runnable demo server.
 *
 * php -S 127.0.0.1:8848 example/server.php
 *
 * @package SugarCommerce\Mcp
 */

declare( strict_types=1 );

require __DIR__ . '/../vendor/autoload.php';

use SugarCommerce\Mcp\{Authenticator, Http, Identity, Implementation, Server, Tool, ToolResult};

/** A toy authenticator. A real one looks tokens up and uses hash_equals. */
final readonly class DemoTokens implements Authenticator {
	public function authenticate( #[\SensitiveParameter] string $token ): ?Identity {
		return match ( true ) {
			hash_equals( 'sc_admin_token', $token ) => new Identity( 'admin', array( '*' ), 'Admin' ),
			hash_equals( 'sc_read_token', $token )  => new Identity( 'reader', array( 'read' ), 'Reader' ),
			default                                 => null,
		};
	}
}

$catalogue = array(
	array( 'id' => 1, 'name' => 'Waveform Pro', 'price' => '€49.99', 'stock' => 12 ),
	array( 'id' => 2, 'name' => 'Chamber Lab', 'price' => '€29.99', 'stock' => 0 ),
	array( 'id' => 3, 'name' => 'Tour T-shirt', 'price' => '€25.00', 'stock' => 40 ),
);

$server = new Server(
	new Implementation( 'sugarcart-demo', '1.0.0', 'Sugarcart Demo' ),
	new DemoTokens(),
	'A demonstration store. Use list_products to see the catalogue.'
);

$server->add( new Tool(
	name: 'list_products',
	description: 'List every product in the store with its price and stock level.',
	input_schema: array( 'type' => 'object', 'additionalProperties' => false ),
	handler: static fn( array $args, ?Identity $who ): ToolResult => ToolResult::structured(
		$GLOBALS['catalogue'],
		'The store has ' . count( $GLOBALS['catalogue'] ) . ' products.'
	),
	scopes: array( 'read' ),
	read_only: true,
) );

$server->add( new Tool(
	name: 'get_product',
	description: 'Get one product by its numeric id. Returns an error if no such product exists.',
	input_schema: array(
		'type'       => 'object',
		'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The product id.' ) ),
		'required'   => array( 'id' ),
		'additionalProperties' => false,
	),
	handler: static function ( array $args, ?Identity $who ): ToolResult {
		foreach ( $GLOBALS['catalogue'] as $product ) {
			if ( $product['id'] === $args['id'] ) {
				return ToolResult::structured( $product, $product['name'] . ' — ' . $product['price'] );
			}
		}

		return ToolResult::failed( 'No product with id ' . $args['id'] . '. Try list_products to see valid ids.' );
	},
	scopes: array( 'read' ),
	read_only: true,
) );

$server->add( new Tool(
	name: 'restock',
	description: 'Add stock to a product. Requires the write scope.',
	input_schema: array(
		'type'       => 'object',
		'properties' => array(
			'id'       => array( 'type' => 'integer', 'minimum' => 1 ),
			'quantity' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 ),
		),
		'required'   => array( 'id', 'quantity' ),
	),
	handler: static fn( array $args, ?Identity $who ): ToolResult => ToolResult::text(
		'Added ' . $args['quantity'] . ' units to product ' . $args['id'] . '.'
	),
	scopes: array( 'write' ),
) );

$GLOBALS['catalogue'] = $catalogue;

( new Http( $server ) )->serve();
