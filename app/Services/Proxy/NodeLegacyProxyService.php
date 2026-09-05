<?php

namespace App\Services\Proxy;

use App\Exceptions\ServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class NodeLegacyProxyService
{
    public function isEnabled(): bool
    {
        return (bool) config('services.node_legacy.enabled', true);
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('services.node_legacy.url', 'http://127.0.0.1:4000'), '/');
    }

    public function forward(Request $request, string $apiPath): Response
    {
        if (! $this->isEnabled()) {
            throw new ServiceUnavailableException(
                'خدمة معالجة الكتب معطّلة. فعّل NODE_LEGACY_ENABLED أو أكمل نقل API إلى Laravel.'
            );
        }

        $url = $this->baseUrl().'/api/'.ltrim($apiPath, '/');
        $query = $request->getQueryString();
        if (is_string($query) && $query !== '') {
            $url .= '?'.$query;
        }

        $headers = $this->collectForwardHeaders($request);
        $timeout = (int) config('services.node_legacy.timeout', 300);

        try {
            $client = Http::withHeaders($headers)->timeout($timeout);

            $response = $this->hasUploadedFiles($request)
                ? $this->sendMultipart($client, $request, $url)
                : $this->sendDefault($client, $request, $url);
        } catch (ConnectionException) {
            throw new ServiceUnavailableException(
                'خدمة معالجة الكتب غير متاحة. شغّل backend-node-legacy: cd backend-node-legacy && npm run dev'
            );
        }

        return response($response->body(), $response->status())
            ->withHeaders($this->filterResponseHeaders($response->headers()));
    }

    /**
     * @return array<string, string>
     */
    private function collectForwardHeaders(Request $request): array
    {
        $headers = [];

        foreach (['Authorization', 'Accept', 'Content-Type', 'X-Request-Id'] as $name) {
            $value = $request->header($name);

            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        if (! isset($headers['Accept'])) {
            $headers['Accept'] = 'application/json';
        }

        return $headers;
    }

    private function hasUploadedFiles(Request $request): bool
    {
        return count($request->allFiles()) > 0;
    }

  private function sendDefault(
        \Illuminate\Http\Client\PendingRequest $client,
        Request $request,
        string $url,
    ): HttpClientResponse {
        $contentType = (string) $request->header('Content-Type', 'application/json');
        $body = $request->getContent();

        if ($body === '' && in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            $payload = $request->all();
            if ($payload !== []) {
                $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
                $contentType = 'application/json';
            }
        }

        $pending = $client->withBody($body, $contentType);

        return $pending->send($request->method(), $url);
    }

    private function sendMultipart(
        \Illuminate\Http\Client\PendingRequest $client,
        Request $request,
        string $url,
    ): HttpClientResponse {
        $multipart = [];

        foreach ($request->allFiles() as $key => $file) {
            if (is_array($file)) {
                continue;
            }

            $multipart[] = [
                'name' => $key,
                'contents' => fopen($file->getRealPath(), 'r'),
                'filename' => $file->getClientOriginalName(),
                'headers' => [
                    'Content-Type' => $file->getMimeType() ?: 'application/octet-stream',
                ],
            ];
        }

        foreach ($request->except(array_keys($request->allFiles())) as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $multipart[] = [
                'name' => $key,
                'contents' => (string) $value,
            ];
        }

        return $client->asMultipart()->send($request->method(), $url, [
            'multipart' => $multipart,
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    private function filterResponseHeaders(array $headers): array
    {
        $forward = [];
        $allowed = ['content-type', 'cache-control', 'x-request-id'];

        foreach ($allowed as $name) {
            if (! empty($headers[$name][0])) {
                $forward[$name] = $headers[$name][0];
            }
        }

        return $forward;
    }
}
