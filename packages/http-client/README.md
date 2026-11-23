# Thesis HTTP Client

Send an HTTP/1.1 request inside a Thesis async scope. The final response head is available as soon as
`send()` returns. Read the body under the deadline you want for the download. The client supplies a body that implements
`Closer`. Close it to stop early and release the socket immediately. The socket also closes when the body ends, a read fails permanently, or the last reference to the body is released.

```php
use Thesis\Http\Request;
use Thesis\HttpClient\Client;
use Thesis\Http\Response;
use Uri\Rfc3986\Uri;
use function Thesis\Async\timeout;
use function Thesis\Time\sec;

$request = new Request('GET', new Uri('https://example.com/'));
$response = timeout(sec(5), static fn() => new Client()->send($request));
$status = $response->status;
$response->closeBody();
```

Use `withResponse()` to close the body when a callback returns or throws:

```php
$status = new Client()->withResponse($request, static fn(Response $response) => $response->status);
```

Each request opens a fresh TCP connection. HTTPS verifies the peer through thesis/net.
Pass a Net ClientTls configuration to the constructor to use a private CA.
The client sends the request body before reading the response. Redirects are returned to the caller.
