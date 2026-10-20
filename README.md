# MCP Server

A Model Context Protocol server for plain PHP. Implements the **2026-07-28** revision — and serves clients still on **2025-11-25** and **2025-06-18** from the same endpoint — with stateless per-request negotiation, JSON-RPC 2.0 over Streamable HTTP, typed tools with schema validation, and scope-based authorization. Zero dependencies.

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

## Results for clients that read only text

Many clients ignore `structuredContent` and show the model only the text block. `ToolResult::structured()` puts the JSON there; for results that can be long, `summarised()` puts a heading and the JSON there, capped, and says so when it cuts:

```php
return ToolResult::summarised( $orders, 'list_orders: done.' );          // 12,000 characters
return ToolResult::summarised( $orders, 'list_orders: done.', 4000 );
```

```text
list_orders: done.
{"orders":[{"id":1,…
… truncated: 4000 of 51234 characters shown. The full result is in structuredContent; narrow the call (a search, a filter, a smaller page) to see the rest as text.
```

It cuts by character, never through a multibyte one, without needing mbstring. `ToolResult::summary()` returns just the text.

## Argument schemas

```php
use SugarCommerce\Mcp\Schema;

input_schema: Schema::object( [ 'id' => [ 'type' => 'integer' ] ], [ 'id' ] ),
```

Closed by default (`additionalProperties: false`), so a misspelt argument is a retryable error naming it rather than silently ignored. Pass `open: true` to allow others.

## Sending the reply yourself

`Http::serve()` writes the reply. An application with its own response object can build the reply with `respond()` and take the headers from `Http::headers()`, so the two cannot drift:

```php
$reply   = ( new Http( $server ) )->respond( $server_vars, $body );
$headers = Http::headers( $reply, realm: 'my-shop', token_sent: '' !== $token );
```

Every reply is `application/json`, `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`. A 401 carries the RFC 6750 challenge: `Bearer realm="my-shop"` when no token was sent, plus `error="invalid_token"` when one was — clients use the difference to decide between asking for a token and reporting the one they have as wrong.

### OAuth: the challenge that starts a sign-in

A server with an OAuth authorization server (see [`sugarcommerce/oauth-server`](https://github.com/sugarcommerce/oauth-server)) names its RFC 9728 protected resource metadata in the challenge. That is how an MCP client finds where to sign in — Claude starts only on a 401 that carries it:

```php
$headers = Http::headers( $reply, 'my-shop', $token_sent, resource_metadata: $settings->resource_metadata_url(), scope: 'read write' );
// WWW-Authenticate: Bearer realm="my-shop", resource_metadata="https://…/.well-known/oauth-protected-resource/mcp", scope="read write"
```

A 403 gets the step-up challenge, `error="insufficient_scope"` with the scopes needed. `Server::missing_scopes( $message, $identity )` says which scopes a `tools/call` lacks, for an application that wants to answer with it rather than with the tool-not-found error `handle()` gives (which never reveals a hidden tool exists — keep that for tools whose names are private).

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

## Versions: one endpoint, two eras

The 2026-07-28 revision is a breaking change: the protocol is stateless, every request carries `io.modelcontextprotocol/protocolVersion` and `io.modelcontextprotocol/clientCapabilities` in `_meta`, every result carries `resultType`, `initialize` and `ping` are gone, `server/discover` is required, list results are cacheable (`ttlMs`, `cacheScope`), and the error codes moved (`-32020` header mismatch, `-32021` missing capability, `-32022` unsupported version; `-32002` and `-32042` retired).

Hosted assistants did not all move at once, so this server is **dual-era** (2026-07-28, "Versioning and Compatibility"), choosing per request:

| The request | Revision | Served as |
|---|---|---|
| `initialize` | negotiated: the client's version if it is 2025-11-25 or 2025-06-18, otherwise 2025-11-25 | legacy — no `resultType`, no server `_meta` |
| `_meta` names a version | that one; the `MCP-Protocol-Version` header must match | 2026-07-28 rules: capabilities required, `resultType`, `_meta.serverInfo`, cacheable lists |
| no `_meta`, header names a legacy version | the header's | legacy, `ping` included |
| neither | refused, 400 | — |
| a version not served | refused, 400, `-32022` with `supported` and `requested` | — |

`server/discover` answers in any version, since it is how a client learns which to speak. No session is ever assigned to a legacy client (those revisions allow that), so `Server::handle()` stays a pure function and nothing is remembered between requests. 2025-03-26 and earlier are not served: they have no structured tool output and no version header.

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

Every tool publishes all four hints explicitly — `readOnlyHint`, `destructiveHint`, `idempotentHint` and `openWorldHint` (`open_world:` on `Tool`, for a tool that emails, calls another service or makes something usable outside) — and its `title` when given, because the spec's defaults assume the worst and directory reviewers read them as stated.

Note that tool **annotations are untrusted** from the client's point of view: `readOnlyHint` is a hint for a confirmation UI, never an enforcement mechanism. Enforce in the handler.

## Testing

```bash
composer install
composer test
```

97 tests — every protocol guard, version negotiation for each revision served, legacy and modern result shapes,, both error mechanisms, scope filtering and the enumeration defence, exception redaction, the schema validator and closed object schemas, capped text summaries with multibyte characters, the reply headers, the 401 and 403 challenges with the resource metadata pointer, the step-up scopes, and one request over a real socket against the demo server.

## Try it

```bash
php -S 127.0.0.1:8848 example/server.php
```

A three-tool demo store with two tokens of differing scope, so you can watch the tool list change with the caller.

## License

GPL-2.0-or-later
