<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

final class V8Client
{
    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly AccessTokenProviderInterface $tokenProvider,
        private readonly Profile $profile,
        private readonly string $requestId,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        if (method_exists($this->tokenProvider, 'setRequestId')) {
            $this->tokenProvider->setRequestId($requestId);
        }
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $jsonBody): array
    {
        return $this->request('POST', $path, [], $jsonBody);
    }

    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): array
    {
        $token = $this->tokenProvider->getAccessToken($this->profile);

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new SuiteCrmHttpException('Failed to initialize SuiteCRM request', 0, '', $path);
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.api+json',
            'User-Agent: suitesidecar-connector-php/0.1.0',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($jsonBody !== null) {
            $encodedBody = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encodedBody === false) {
                curl_close($ch);
                throw new SuiteCrmBadResponseException('Failed to encode SuiteCRM request body');
            }
            $headers[] = 'Content-Type: application/vnd.api+json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = $encodedBody;
        }

        curl_setopt_array($ch, $options);

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $curlError = curl_error($ch);
            curl_close($ch);
            $this->log('SuiteCRM HTTP error: endpoint=' . $path . ' transport_error');
            throw new SuiteCrmHttpException(
                'Failed to reach SuiteCRM endpoint: ' . $curlError,
                0,
                '',
                $path
            );
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($statusCode >= 400) {
            $snippet = $this->bodySnippet((string) $rawResponse);
            $this->log('SuiteCRM HTTP error: endpoint=' . $path . ' status=' . $statusCode);
            throw new SuiteCrmHttpException(
                'SuiteCRM returned HTTP ' . $statusCode,
                $statusCode,
                $snippet,
                $path
            );
        }

        $decoded = json_decode((string) $rawResponse, true);
        if (!is_array($decoded)) {
            $this->log('SuiteCRM JSON parse error: endpoint=' . $path);
            throw new SuiteCrmBadResponseException('SuiteCRM returned invalid JSON response');
        }

        return $decoded;
    }

    private function bodySnippet(string $body): string
    {
        return substr(preg_replace('/\s+/', ' ', trim($body)) ?? '', 0, 200);
    }

    private function log(string $message): void
    {
        error_log('[requestId=' . $this->requestId . '] ' . $message);
    }
}
