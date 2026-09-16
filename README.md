# MCP Server

A Model Context Protocol server for plain PHP. Implements the **2026-07-28** revision: stateless per-request negotiation, JSON-RPC 2.0 over Streamable HTTP, typed tools with schema validation, and scope-based authorization. Zero dependencies.

## Why

Giving a language model access to your application means exposing tools it can call unsupervised. The official SDKs are TypeScript and Python; if your application is PHP, the usual answer is a sidecar service, which means a second runtime, a second deploy, and a network hop between the model and the data it is asking about.

This is the server, in your existing process.

## Requirements

PHP 8.5+, `ext-json`

## Installation

```bash
composer require sugarcommerce/mcp-server
```

## Usage

```php
use SugarCommerce\Mcp\{Http, Implementation, Server, Tool, ToolResult};

$server = new Server(
    new Implementation( 'my-shop', '1.0.0' ),
    new MyTokenStore(),                       // implements Authenticator
    'Tools for managing the shop catalogue.'  // shown to the model
);

$server->add( new Tool(
    name: 'get_product',
    description: 'Get one product by its numeric id. Returns an error if no such product exists.',
    input_schema: [
        'type'       => 'object',
        'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
        'required'   => [ 'id' ],
        'additionalProperties' => false,
    ],
    handler: function ( array $args, ?Identity $who ): ToolResult {
        $product = $repository->find( $args['id'] );

        return null === $product
            ? ToolResult::failed( 'No product with id ' . $args['id'] . '. Try list_products for valid ids.' )
            : ToolResult::structured( $product, $product['name'] . ' — ' . $product['price'] );
    },
    scopes: [ 'read' ],
    read_only: true,
) );

( new Http( $server ) )->serve();
```

Point a client at it:

```bash
claude mcp add --transport http my-shop https://example.com/mcp \
  --header "Authorization: Bearer sc_…"
```

## Two kinds of error

This is the distinction that most determines whether a model can use your server.

| | Mechanism | The model can… |
|---|---|---|
| **Protocol error** | JSON-RPC `error` | little — the request itself was wrong |
| **Tool execution error** | Result with `isError: true` | retry, having been told what to fix |

```php
// The model can act on this.
return ToolResult::failed( 'That date is in the past. Today is 1 August 2026.' );

// It can do nothing useful with this.
throw new RuntimeException( 'Invalid date' );
```

Unknown tools and malformed requests are protocol errors, and the library raises them for you. Everything your handler knows about — a missing record, a rejected value, a business rule — should be a `ToolResult::failed()` carrying a sentence the model can use.

A handler that throws is caught and reported as a **generic** failure. An exception message can carry a DSN, a query or a credential, and this response goes to a language model and often into a transcript, so it is never passed through.

## Authorization

Implement `Authenticator` — where tokens live and what a scope means are your decisions:

```php
final readonly class MyTokenStore implements Authenticator {

    public function authenticate( #[\SensitiveParameter] string $token ): ?Identity {
        $row = $this->db->find_token( hash( 'sha256', $token ) );

        if ( null === $row || $row->revoked ) {
            return null;
        }

        return new Identity( id: $row->id, scopes: $row->scopes, label: $row->name );
    }
}
```

Compare secrets with `hash_equals`, never `===` — string comparison short-circuits on the first differing byte and leaks the correct prefix through timing.

Tools declare the scopes they need. A caller sees only the tools their scopes permit, and calling one they cannot see returns **exactly** the same error as calling one that does not exist — otherwise the error message becomes an enumeration oracle for your privileged tools.

`Identity` with scope `*` holds everything, so a single-operator install need not enumerate scopes it does not use.

## Auditing

```php
$server->audit( function ( string $tool, array $args, ?Identity $who, bool $ok ): void {
    $this->log->write( $who?->id, $tool, $args, $ok );
} );
```

Tool calls are arbitrary code execution against your data on behalf of a language model. Log them.

## What is new in 2026-07-28

If you have an implementation targeting `2025-06-18` or `2025-11-25`, it will not interoperate. The changes are breaking:

- **The protocol is stateless.** Nothing may be inferred from an earlier request, even on the same connection. Every request carries `io.modelcontextprotocol/protocolVersion` and `io.modelcontextprotocol/clientCapabilities` in `_meta`, both **required** — a request missing either is `-32602` with HTTP 400.
- **Every result carries `resultType`** — `"complete"` or `"input_required"`. A client treats an absent one as `"complete"` only for backward compatibility.
- **New error codes**: `-32020` header mismatch, `-32021` missing client capability, `-32022` unsupported protocol version.
- **`-32002` and `-32042` are retired** and must never be emitted; resource-not-found is now `-32602`.
- **New codes must not land in `-32000`–`-32019`**, which is now legacy.
- **Tool sets may vary by authorization** — credentials are per-request input — but never by connection.

The practical consequence of statelessness is that there is no session to keep. `Server::handle()` is a pure function from a decoded message to a response, so the server scales horizontally for free and is testable without a socket.

## Stateful tools

There is no protocol-level session, so a server cannot relate one call to the next implicitly. Return an explicit handle and take it back as an argument:

```php
// create_basket  → { "basket_id": "bsk_a1b2c3" }
// add_item       ← { "basket_id": "bsk_a1b2c3", "sku": "…" }
```

Three things to get right, none of them enforced by the protocol: validate the caller's authorization against the handle on **every** call (a handle is a name, not a capability); make handles opaque, since ones that encode structure invite guessing; and state the retention policy in the creating tool's description, so the model can see it when deciding to create state.

## Argument validation

Arguments are checked against the tool's `inputSchema` before the handler runs, and failures come back as retryable tool errors naming the property.

The validator covers types, `required`, `enum`, `additionalProperties`, string lengths and patterns, numeric bounds, and array sizes — deliberately not the whole of JSON Schema. A full implementation would need `$ref` resolution, which the specification then requires you to refuse to dereference over the network, plus the composition keywords it warns can be turned into a denial-of-service vector. Neither belongs in the request path of a tool call.

Validate here to catch the model's mistakes early. Validate again in the handler for anything that matters.

## Writing tool descriptions

The description is not documentation — it is the prompt, and the only thing the model reads when deciding whether this tool answers the question in front of it.

```php
// Weak
description: 'Gets a product.'

// Useful
description: 'Get one product by its numeric id. Returns an error if no such
              product exists — call list_products first if you need valid ids.'
```

Say what it does, when to reach for it, and what it will refuse.

## Not implemented

Tools only. Resources, prompts, sampling, elicitation, subscriptions and the Tasks extension are all absent, and `initialize` advertises only the `tools` capability so a client knows not to ask.

Tools are what an application server has to offer, and they are the whole of what most integrations use.

## Security

See [SECURITY.md](SECURITY.md). The specification asks servers to validate inputs, enforce access control, rate limit, and sanitise outputs; the first two are handled here, and the last two are yours — pair this with [`sugarcommerce/rate-limit`](https://github.com/sugarcommerce/rate-limit) in front of `serve()`.

Note that tool **annotations are untrusted** from the client's point of view: `readOnlyHint` is a hint for a confirmation UI, never an enforcement mechanism. Enforce in the handler.

## Testing

```bash
composer install
composer test
```

77 tests — every protocol guard, both error mechanisms, scope filtering and the enumeration defence, exception redaction, the schema validator, and one request over a real socket against the demo server.

## Try it

```bash
php -S 127.0.0.1:8848 example/server.php
```

A three-tool demo store with two tokens of differing scope, so you can watch the tool list change with the caller.

## License

GPL-2.0-or-later
