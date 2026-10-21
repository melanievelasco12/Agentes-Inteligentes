<?php

declare(strict_types=1);

require_once __DIR__ . '/BrightspaceBatchClient.php';

/** Cliente HTTP de Brightspace con OAuth, paginacion y solicitudes por lotes. */
final class BrightspaceClient implements BrightspaceBatchClient
{
    private const MAX_BATCH_REQUESTS = 8;

    private string $lpApiVersion;

    public function __construct(
        private OAuthClient $oauth,
        private OAuthTokenStore $tokenStore,
        private string $baseUrl,
        private string $apiVersion,
        ?string $lpApiVersion = null
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->apiVersion = trim($this->apiVersion, '/');
        $lpApiVersion ??= (string) getenv('BRIGHTSPACE_LP_API_VERSION');
        $this->lpApiVersion = trim($lpApiVersion !== '' ? $lpApiVersion : $this->apiVersion, '/');

        if ($this->baseUrl === '' || $this->apiVersion === '' || $this->lpApiVersion === '') {
            throw new RuntimeException('La configuración de la API de Brightspace está incompleta.');
        }
    }

    public function get(string $product, string $path, array $query = []): array
    {
        return $this->requestJson('GET', $product, $path, $query);
    }

    public function post(string $product, string $path, array $body = []): array
    {
        return $this->requestJson('POST', $product, $path, [], $body);
    }

    public function put(string $product, string $path, array $body): array
    {
        return $this->requestJson('PUT', $product, $path, [], $body);
    }

    /**
     * Executes a bounded set of independent API requests concurrently.
     *
     * @param array<int, array{method: string, product: string, path: string, query?: array, body?: array}> $requests
     * @return array<int, array{status: int, data: array<string|int, mixed>|null, error: string|null}>
     */
    public function requestBatchJson(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        if (count($requests) > self::MAX_BATCH_REQUESTS) {
            throw new InvalidArgumentException('El lote de solicitudes de Brightspace excede el máximo permitido.');
        }

        // El token se obtiene una vez y se comparte entre las solicitudes concurrentes.
        $token = $this->accessToken();
        $multi = curl_multi_init();
        $handles = [];
        $results = [];

        try {
            foreach ($requests as $key => $request) {
                $method = strtoupper((string) ($request['method'] ?? ''));
                $product = trim((string) ($request['product'] ?? ''), '/');
                $path = '/' . ltrim((string) ($request['path'] ?? ''), '/');
                $query = is_array($request['query'] ?? null) ? $request['query'] : [];
                $body = is_array($request['body'] ?? null) ? $request['body'] : [];
                if (!in_array($method, ['GET', 'PUT'], true) || $product === '' || $path === '/') {
                    throw new InvalidArgumentException('La solicitud por lotes de Brightspace no es válida.');
                }

                $apiVersion = strtolower($product) === 'lp' ? $this->lpApiVersion : $this->apiVersion;
                $url = $this->baseUrl . '/d2l/api/' . $product . '/' . $apiVersion . $path;
                if ($query !== []) {
                    $url .= '?' . http_build_query($query);
                }

                $handle = curl_init($url);
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json',
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $token,
                    ],
                    CURLOPT_POSTFIELDS => $method === 'PUT'
                        ? json_encode($body, JSON_THROW_ON_ERROR)
                        : null,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);

                curl_multi_add_handle($multi, $handle);
                $handles[$key] = ['handle' => $handle, 'url' => $url, 'method' => $method];
            }

            $running = 0;
            do {
                do {
                    $multiStatus = curl_multi_exec($multi, $running);
                } while (
                    defined('CURLM_CALL_MULTI_PERFORM')
                    && $multiStatus === constant('CURLM_CALL_MULTI_PERFORM')
                );
                if ($multiStatus !== CURLM_OK) {
                    throw new RuntimeException(
                        'No se pudo ejecutar el lote de solicitudes de Brightspace: '
                        . curl_multi_strerror($multiStatus)
                    );
                }

                if ($running > 0) {
                    $selected = curl_multi_select($multi, 1.0);
                    if ($selected === -1 || $selected === false) {
                        usleep(100000);
                    }
                }
            } while ($running > 0);

            foreach ($handles as $key => $request) {
                $handle = $request['handle'];
                $response = curl_multi_getcontent($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $curlError = curl_error($handle);

                if ($response === false || $curlError !== '') {
                    $error = 'Error de conexión con Brightspace: '
                        . ($curlError !== '' ? $curlError : 'sin detalle del cURL.');
                    AppLogger::error('Brightspace API batch request failed.', [
                        'url' => $request['url'],
                        'method' => $request['method'],
                        'error' => $curlError,
                        'status' => $status,
                    ]);
                    $results[$key] = ['status' => $status, 'data' => null, 'error' => $error];
                    continue;
                }

                $data = json_decode($response, true);
                if (!is_array($data)) {
                    $bodyPreview = trim(preg_replace('/\s+/', ' ', substr($response, 0, 240)));
                    $detail = $bodyPreview !== '' ? $bodyPreview : 'vacía';
                    $retryable = $status === 429 || $status >= 500;
                    $error = ($retryable ? 'Error de conexión con Brightspace: ' : '')
                        . 'Brightspace devolvió una respuesta no válida (HTTP ' . $status
                        . ') para ' . $request['url'] . '. Respuesta: ' . $detail;
                    AppLogger::error('Brightspace batch request returned a malformed payload.', [
                        'url' => $request['url'],
                        'method' => $request['method'],
                        'status' => $status,
                        'response_preview' => $detail,
                    ]);
                    $results[$key] = ['status' => $status, 'data' => null, 'error' => $error];
                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    $message = isset($data['Message']) && is_string($data['Message'])
                        ? $data['Message']
                        : 'Brightspace rechazó la solicitud.';
                    $retryable = $status === 429 || $status >= 500;
                    $error = ($retryable ? 'Error de conexión con Brightspace: ' : '')
                        . $message . ' HTTP ' . $status . '.';
                    AppLogger::warning('Brightspace API batch request was rejected.', [
                        'url' => $request['url'],
                        'method' => $request['method'],
                        'status' => $status,
                        'message' => $message,
                    ]);
                    $results[$key] = ['status' => $status, 'data' => null, 'error' => $error];
                    continue;
                }

                $results[$key] = ['status' => $status, 'data' => $data, 'error' => null];
            }
        } finally {
            foreach ($handles as $request) {
                curl_multi_remove_handle($multi, $request['handle']);
                curl_close($request['handle']);
            }
            curl_multi_close($multi);
        }

        return $results;
    }

    /**
     * Token de acceso vigente, con renovación automática.
     *
     * Expuesto para que los recorridos masivos puedan construir sus propias
     * solicitudes paralelas sin duplicar la lógica de autenticación.
     */
    public function accessTokenForDiscovery(): string
    {
        return $this->accessToken();
    }

    /** URL base de la API, sin versión, para armar rutas paralelas. */
    public function apiBaseUrl(): string
    {
        return $this->baseUrl . '/d2l/api';
    }

    /** Versión de la API configurada, para construir rutas manuales. */
    public function apiVersion(): string
    {
        return $this->apiVersion;
    }

    private function requestJson(string $method, string $product, string $path, array $query = [], array $body = []): array
    {
        $path = '/' . ltrim($path, '/');
        $product = trim($product, '/');
        $apiVersion = strtolower($product) === 'lp' ? $this->lpApiVersion : $this->apiVersion;
        $url = $this->baseUrl . '/d2l/api/' . $product . '/' . $apiVersion . $path;

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $this->request($method, $url, $this->accessToken(), $body);
    }

    private function accessToken(): string
    {
        $tokens = $this->tokenStore->get();

        if ($tokens === null) {
            throw new RuntimeException('Brightspace no está conectado.');
        }

        if (strtotime($tokens['expires_at']) > time() + 60) {
            return $tokens['access_token'];
        }

        if (!is_string($tokens['refresh_token']) || $tokens['refresh_token'] === '') {
            throw new RuntimeException('El token de Brightspace expiró y no hay refresh token.');
        }

        $refreshedTokens = $this->oauth->refresh($tokens['refresh_token']);
        $this->tokenStore->save($refreshedTokens);
        $latestTokens = $this->tokenStore->get();

        if ($latestTokens === null) {
            throw new RuntimeException('No se pudo recuperar el token renovado de Brightspace.');
        }

        return $latestTokens['access_token'];
    }

    private function request(string $method, string $url, string $accessToken, array $body = []): array
    {
        // Mantiene la validacion TLS habilitada y centraliza el manejo de respuesta HTTP/JSON.
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_POSTFIELDS => $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false || $error !== '') {
            AppLogger::error('Brightspace API request failed.', ['url' => $url, 'method' => $method, 'error' => $error, 'status' => $status]);
            throw new RuntimeException('Error de conexión con Brightspace: ' . ($error !== '' ? $error : 'sin detalle del cURL.'));
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $bodyPreview = trim(preg_replace('/\s+/', ' ', substr($response, 0, 240)));
            $detail = $bodyPreview !== '' ? $bodyPreview : 'vacía';
            AppLogger::error('Brightspace API returned a malformed payload.', ['url' => $url, 'method' => $method, 'status' => $status, 'response_preview' => $detail]);
            throw new RuntimeException(
                'Brightspace devolvió una respuesta no válida (HTTP ' . $status . ') para ' . $url
                . '. Respuesta: ' . $detail
            );
        }

        if ($status < 200 || $status >= 300) {
            $message = isset($data['Message']) && is_string($data['Message'])
                ? $data['Message']
                : 'Brightspace rechazó la solicitud.';
            AppLogger::warning('Brightspace API rejected request.', ['url' => $url, 'method' => $method, 'status' => $status, 'message' => $message]);
            throw new RuntimeException($message . ' HTTP ' . $status . '.');
        }

        return $data;
    }
}
