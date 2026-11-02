<?php
/**
 * Internal JSON API: index.php?fc=module&module=bxmarketplace&controller=api&resource=<path>
 * Auth: "Authorization: Bearer <64-hex token>" (bxmp_api_token) — fallback header "X-Bxmp-Token".
 * See docs/API.md and docs/openapi.yaml.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';

use BrainX\Marketplace\Infrastructure\Api\ApiContext;
use BrainX\Marketplace\Infrastructure\Api\ApiException;
use BrainX\Marketplace\Infrastructure\Api\Router;
use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\ApiTokenRepository;

class BxmarketplaceApiModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;
    public $content_only = true;
    public $display_header = false;
    public $display_footer = false;

    public function postProcess()
    {
        $method = strtoupper((string) ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $resource = trim((string) Tools::getValue('resource', ''), '/');
        $start = microtime(true);
        try {
            $token = $this->bearerToken();
            if ($token === '') {
                throw new ApiException(401, 'Missing bearer token', 'unauthorized');
            }
            $row = (new ApiTokenRepository())->findActive($token);
            if ($row === null) {
                throw new ApiException(401, 'Invalid or revoked token', 'unauthorized');
            }
            $ctx = ApiContext::fromTokenRow($row);

            $body = null;
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $raw = (string) file_get_contents('php://input');
                if ($raw !== '') {
                    $body = json_decode($raw, true);
                    if (!is_array($body)) {
                        throw new ApiException(400, 'Request body must be a JSON object', 'bad_request');
                    }
                } else {
                    $body = [];
                }
            }
            $query = $_GET;
            unset($query['fc'], $query['module'], $query['controller'], $query['resource'], $query['id_lang'], $query['isolang']);

            [$status, $payload] = (new Router($ctx))->dispatch($method, $resource, $query, $body);
            if ($method !== 'GET') {
                Logger::channel('api')->info(sprintf('%s %s -> %d', $method, $resource, $status), ['token' => $ctx->idApiToken, 'ms' => round((microtime(true) - $start) * 1000, 1)]);
            }
            $this->respond($status, $payload);
        } catch (ApiException $e) {
            if ($e->status >= 500 || $e->status === 401 || $e->status === 403) {
                Logger::channel('api')->warning(sprintf('%s %s -> %d %s', $method, $resource, $e->status, $e->getMessage()), ['ip' => Tools::getRemoteAddr()]);
            }
            $this->respond($e->status, $e->toArray());
        } catch (Throwable $e) {
            Logger::channel('api')->error(sprintf('%s %s -> 500 %s', $method, $resource, $e->getMessage()), ['file' => $e->getFile() . ':' . $e->getLine()]);
            $this->respond(500, ['error' => ['code' => 'internal_error', 'message' => _PS_MODE_DEV_ ? $e->getMessage() : 'Internal error']]);
        }
    }

    private function bearerToken(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp((string) $k, 'Authorization') === 0) {
                    $header = (string) $v;
                }
            }
        }
        if (preg_match('/^Bearer\s+([A-Za-z0-9]+)\s*$/i', $header, $m)) {
            return strtolower($m[1]);
        }

        return strtolower(trim((string) ($_SERVER['HTTP_X_BXMP_TOKEN'] ?? '')));
    }

    private function respond(int $status, array $payload): void
    {
        ini_set('serialize_precision', '-1'); // shortest round-trip floats (9.99, not 9.9900000000000002)
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT);
        exit;
    }
}
