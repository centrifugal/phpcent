<?php

declare(strict_types=1);

// The behaviours of TestServer, chosen by the first segment of the path:
// /{behaviour}/api/{method}.

use Centrifugal\Phpcent\Endpoints\ConnectionToken;
use Centrifugal\Phpcent\Http\HttpRequest;
use Centrifugal\Phpcent\Proxy\ConnectHandler;
use Centrifugal\Phpcent\Proxy\ConnectRequest;
use Centrifugal\Phpcent\Proxy\ConnectResult;

require __DIR__ . '/../../vendor/autoload.php';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), \PHP_URL_PATH);
$parts = explode('/', trim($path, '/'));
$behaviour = $parts[0] ?? '';

switch ($behaviour) {
    case 'ok':
        header('Content-Type: application/json');
        echo json_encode(['result' => [
            'offset' => 1,
            'epoch' => 'e',
            // What the request carried, for the tests to look at.
            'echo' => [
                'headers' => getallheaders(),
                'body' => file_get_contents('php://input'),
            ],
        ]]);
        break;
    case 'slowheaders':
        usleep(2_000_000);
        header('Content-Type: application/json');
        echo '{"result":{}}';
        break;
    case 'trickle':
        header('Content-Type: application/json');
        echo '{"result":{"x":"';
        for ($i = 0; $i < 30; $i++) {
            echo 'a';
            flush();
            usleep(100_000);
        }
        echo '"}}';
        break;
    case 'redirect':
        header('Location: https://elsewhere.test/api/publish', true, 302);
        break;
    case 'bigerror':
        http_response_code(502);
        header('Content-Type: text/html');
        echo '<html>' . str_repeat('x', 200_000) . '</html>';
        break;
    case 'html':
        header('Content-Type: text/html');
        echo '<html>not centrifugo</html>';
        break;
    case 'serve-connect':
        (new ConnectHandler(static fn(ConnectRequest $r, HttpRequest $h): ConnectResult => new ConnectResult(
            user: (string) $h->header('x-user'),
            data: $r->data,
        )))->serve();
        break;
    case 'serve-token':
        (new ConnectionToken(static fn(HttpRequest $h): string => 'token-for-' . ($h->query['who'] ?? '')))->serve();
        break;
    default:
        http_response_code(404);
}
