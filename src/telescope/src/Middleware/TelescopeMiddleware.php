<?php

declare(strict_types=1);
/**
 * This file is part of friendsofhyperf/components.
 *
 * @link     https://github.com/friendsofhyperf/components
 * @document https://github.com/friendsofhyperf/components/blob/main/README.md
 * @contact  huangdijia@gmail.com
 */

namespace FriendsOfHyperf\Telescope\Middleware;

use FriendsOfHyperf\Telescope\IncomingEntry;
use FriendsOfHyperf\Telescope\Telescope;
use FriendsOfHyperf\Telescope\TelescopeConfig;
use FriendsOfHyperf\Telescope\TelescopeContext;
use Hyperf\Collection\Arr;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\Rpc\Context as RpcContext;
use Hyperf\Server\Event;
use Hyperf\Stringable\Str;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Hyperf\Collection\collect;
use function Hyperf\Config\config;
use function Hyperf\Coroutine\defer;

class TelescopeMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected ContainerInterface $container,
        protected TelescopeConfig $telescopeConfig
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $this->telescopeConfig->isEnable('request')) {
            return $handler->handle($request);
        }

        if (! $batchId = $request->getHeaderLine('batch-id')) {
            $batchId = $this->getRpcBatchId();
        }

        TelescopeContext::getOrSetBatch($batchId);

        $response = $handler->handle($request);
        $response = $response->withHeader('batch-id', $batchId);

        defer(fn () => $this->requestHandled($request, $response));

        return $response;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     */
    public function requestHandled($request, $response)
    {
        $psr7Request = $request;
        $psr7Response = $response;
        $startTime = $psr7Request->getServerParams()['request_time_float'];

        if ($this->incomingRequest($psr7Request)) {
            /** @var Dispatched $dispatched */
            $dispatched = $psr7Request->getAttribute(Dispatched::class);
            $entry = IncomingEntry::make([
                'ip_address' => $psr7Request->getHeaderLine('x-real-ip') ?: $psr7Request->getServerParams()['remote_addr'] ?? 'unknown',
                'uri' => $psr7Request->getRequestTarget(),
                'method' => $psr7Request->getMethod(),
                'controller_action' => $dispatched->handler ? $dispatched->handler->callback : '',
                'middleware' => TelescopeContext::getMiddlewares(),
                'headers' => $psr7Request->getHeaders(),
                'payload' => $this->getRequestPayload($psr7Request),
                'session' => '',
                'response_status' => $psr7Response->getStatusCode(),
                'response' => $this->getResponsePayload($psr7Response),
                'duration' => $startTime ? floor((microtime(true) - $startTime) * 1000) : null,
                'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            ]);

            if ($this->isRpcRequest($psr7Request)) {
                Telescope::recordService($entry);
            } else {
                Telescope::recordRequest($entry);
            }
        }
    }

    protected function incomingRequest(ServerRequestInterface $psr7Request): bool
    {
        if ($this->telescopeConfig->isPatchOnly($psr7Request)) {
            return true;
        }

        return ! $this->telescopeConfig->isPathIgnored($psr7Request);
    }

    protected function getResponsePayload(ResponseInterface $response): string|array
    {
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $content = $stream->getContents();
        if (is_string($content)) {
            if (! $this->contentWithinLimits($content)) {
                return 'Purged By Hyperf Telescope';
            }
            if (
                is_array(json_decode($content, true))
                && json_last_error() === JSON_ERROR_NONE
            ) {
                return $this->contentWithinLimits($content)/* @phpstan-ignore-line */
                ? $this->hideParameters(json_decode($content, true), Telescope::$hiddenResponseParameters)
                : 'Purged By Hyperf Telescope';
            }
            if (Str::startsWith(strtolower($response->getHeaderLine('content-type') ?: ''), 'text/plain')) {
                return $this->contentWithinLimits($content) ? $content : 'Purged By Hyperf Telescope'; /* @phpstan-ignore-line */
            }
            if (Str::contains($response->getHeaderLine('content-type'), 'application/grpc') !== false) {
                return TelescopeContext::getGrpcResponsePayload() ?: 'Purged By Hyperf Telescope';
            }
        }

        if (empty($content)) {
            return 'Empty Response';
        }

        return 'HTML Response';
    }

    protected function contentWithinLimits(string $content): bool
    {
        $limit = 64;
        return mb_strlen($content) / 1000 <= $limit;
    }

    /**
     * Hide the given parameters.
     */
    protected function hideParameters(array $data, array $hidden): array
    {
        foreach ($hidden as $parameter) {
            if (Arr::get($data, $parameter)) {
                Arr::set($data, $parameter, '********');
            }
        }

        return $data;
    }

    protected function getRpcBatchId(): string
    {
        return $this->getRpcContext()['batch-id'] ?? '';
    }

    protected function getRpcContext(): array
    {
        if (! $this->container->has(RpcContext::class)) {
            return [];
        }

        return $this->container->get(RpcContext::class)->get('telescope.carrier', []);
    }

    protected function isRpcRequest(ServerRequestInterface $psr7Request): bool
    {
        $handler = $this->parseHandler($psr7Request);
        if (
            $handler
            && (
                is_a($handler, \Hyperf\RpcServer\Server::class, true)
                || is_a($handler, \Hyperf\JsonRpc\HttpServer::class, true)
                || is_a($handler, \Hyperf\GrpcServer\Server::class, true)
            )
        ) {
            return true;
        }

        return false;
    }

    protected function parseHandler(ServerRequestInterface $psr7Request): mixed
    {
        $dispatched = $psr7Request->getAttribute(Dispatched::class);
        $serverName = $dispatched->serverName ?? 'http';
        $serverConfig = collect(config('server.servers'))->firstWhere('name', $serverName);
        $handlerClass = $serverConfig['callbacks'][Event::ON_RECEIVE][0] ?? $serverConfig['callbacks'][Event::ON_REQUEST][0] ?? null;
        return is_string($handlerClass) && $this->container->has($handlerClass) ? $this->container->get($handlerClass) : null;
    }

    protected function getRequestPayload(ServerRequestInterface $psr7Request): array|string
    {
        $handler = $this->parseHandler($psr7Request);

        if ($handler && is_a($handler, \Hyperf\GrpcServer\Server::class, true)) {
            return TelescopeContext::getGrpcRequestPayload() ?: '';
        }
        $data = is_array($psr7Request->getParsedBody()) ? $psr7Request->getParsedBody() : [];
        return $psr7Request->getQueryParams() + $data;
    }
}
