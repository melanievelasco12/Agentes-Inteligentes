<?php

declare(strict_types=1);

/** Construye autorizaciones OAuth e intercambia o renueva tokens de Brightspace. */
final class OAuthClient
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $scope;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) getenv('BRIGHTSPACE_BASE_URL'), '/');
        $this->clientId = (string) getenv('BRIGHTSPACE_CLIENT_ID');
        $this->clientSecret = (string) getenv('BRIGHTSPACE_CLIENT_SECRET');
        $this->redirectUri = (string) getenv('BRIGHTSPACE_REDIRECT_URI');
        $this->scope = (string) getenv('BRIGHTSPACE_SCOPE');

        if ($this->baseUrl === '' || $this->clientId === '' || $this->clientSecret === '' || $this->redirectUri === '') {
            throw new RuntimeException('La configuración OAuth de Brightspace está incompleta.');
        }
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'state' => $state,
        ]);

        return 'https://auth.brightspace.com/oauth2/auth?' . $query;
    }

    public function tokenFromCode(string $code): array
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    private function requestToken(array $parameters): array
    {
        // OAuth requiere enviar estos parametros como formulario, no como JSON.
        $curl = curl_init('https://auth.brightspace.com/core/connect/token');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($parameters),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false || $error !== '') {
            AppLogger::error('OAuth request failed at network layer.', ['error' => $error, 'status' => $status, 'parameters' => array_keys($parameters)]);
            throw new RuntimeException('Error de conexión OAuth: ' . ($error !== '' ? $error : 'sin detalle del cURL.'));
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $bodyPreview = trim(preg_replace('/\s+/', ' ', substr($response, 0, 240)));
            AppLogger::error('OAuth response was not valid JSON.', ['status' => $status, 'response_preview' => $bodyPreview]);
            throw new RuntimeException(
                'Brightspace devolvió una respuesta no válida durante OAuth (HTTP ' . $status . '). '
                . 'Respuesta: ' . ($bodyPreview !== '' ? $bodyPreview : 'vacía')
            );
        }

        if ($status < 200 || $status >= 300) {
            $message = $data['error_description'] ?? ($data['error'] ?? 'Brightspace rechazó la solicitud OAuth.');
            AppLogger::warning('OAuth token request rejected.', ['status' => $status, 'message' => $message, 'response' => $data]);
            throw new RuntimeException($message . ' (HTTP ' . $status . ').');
        }

        $accessToken = trim((string) ($data['access_token'] ?? ''));
        if ($accessToken === '') {
            AppLogger::error('OAuth token response missing access token.', ['response' => $data]);
            throw new RuntimeException('Brightspace no devolvió un access_token válido.');
        }

        return $data;
    }
}
