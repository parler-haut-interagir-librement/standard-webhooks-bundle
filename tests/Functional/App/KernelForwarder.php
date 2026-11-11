<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Functional\App;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The HTTP client's other end: each request it sends is handled by the test kernel, and its answer comes back.
 */
final readonly class KernelForwarder
{
    public function __construct(
        private HttpKernelInterface $kernel,
    ) {
    }

    /**
     * @param array{headers?: list<string>, body?: string} $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $request = Request::create($url, $method, content: $options['body'] ?? '');

        foreach ($options['headers'] ?? [] as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $request->headers->set($name, $value);
        }

        $response = $this->kernel->handle($request, HttpKernelInterface::SUB_REQUEST);

        return new MockResponse((string) $response->getContent(), ['http_code' => $response->getStatusCode()]);
    }
}
