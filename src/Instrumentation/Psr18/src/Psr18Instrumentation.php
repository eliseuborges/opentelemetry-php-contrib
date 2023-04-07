<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr18;

use function get_cfg_var;
use OpenTelemetry\API\Common\Instrumentation;
use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use function sprintf;
use function strtolower;
use Throwable;

class Psr18Instrumentation
{
    /** @psalm-suppress ArgumentTypeCoercion */
    public const NAME = 'psr18';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.psr18', schemaUrl: TraceAttributes::SCHEMA_URL);

        hook(
            ClientInterface::class,
            'sendRequest',
            pre: static function (ClientInterface $client, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): ?array {
                $request = $params[0] ?? null;
                if (!$request instanceof RequestInterface) {
                    Context::storage()->attach(Context::getCurrent());

                    return null;
                }

                $propagator = Instrumentation\Globals::propagator();
                $parentContext = Context::getCurrent();

                /** @psalm-suppress ArgumentTypeCoercion */
                $spanBuilder = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('HTTP %s', $request->getMethod()))
                    ->setParent($parentContext)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::HTTP_URL, (string) $request->getUri())
                    ->setAttribute(TraceAttributes::HTTP_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::HTTP_FLAVOR, $request->getProtocolVersion())
                    ->setAttribute(TraceAttributes::HTTP_USER_AGENT, $request->getHeaderLine('User-Agent'))
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::NET_PEER_NAME, $request->getUri()->getHost())
                    ->setAttribute(TraceAttributes::NET_PEER_PORT, $request->getUri()->getPort())
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                ;

                foreach ($propagator->fields() as $field) {
                    $request = $request->withoutHeader($field);
                }
                //@todo could we use SDK Configuration to retrieve this, and move into a key such as OTEL_PHP_xxx?
                foreach ((array) (get_cfg_var('otel.instrumentation.http.request_headers') ?: []) as $header) {
                    if ($request->hasHeader($header)) {
                        $spanBuilder->setAttribute(
                            sprintf('http.request.header.%s', strtr(strtolower($header), ['-' => '_'])),
                            $request->getHeader($header)
                        );
                    }
                }

                $span = $spanBuilder->startSpan();
                $context = $span->storeInContext($parentContext);
                $propagator->inject($request, HeadersPropagator::instance(), $context);

                Context::storage()->attach($context);

                return [$request];
            },
            post: static function (ClientInterface $client, array $params, ?ResponseInterface $response, ?Throwable $exception): void {
                $scope = Context::storage()->scope();
                $scope?->detach();

                //@todo do we need the second part of this 'or'?
                if (!$scope || $scope->context() === Context::getCurrent()) {
                    return;
                }

                $span = Span::fromContext($scope->context());

                if ($response) {
                    $span->setAttribute(TraceAttributes::HTTP_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(TraceAttributes::HTTP_FLAVOR, $response->getProtocolVersion());
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_CONTENT_LENGTH, $response->getHeaderLine('Content-Length'));

                    foreach ((array) (get_cfg_var('otel.instrumentation.http.response_headers') ?: []) as $header) {
                        if ($response->hasHeader($header)) {
                            /** @psalm-suppress ArgumentTypeCoercion */
                            $span->setAttribute(sprintf('http.response.header.%s', strtr(strtolower($header), ['-' => '_'])), $response->getHeader($header));
                        }
                    }
                    if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 600) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                }
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            },
        );
    }
}
