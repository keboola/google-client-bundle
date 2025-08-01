<?php

declare(strict_types=1);

namespace Keboola\Google\ClientBundle\Google;

use Google\Auth\CredentialsLoader;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Keboola\Google\ClientBundle\Exception\RestApiException;
use Keboola\Google\ClientBundle\Guzzle\RetryCallbackMiddleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class RestApi
{
    public const API_URI = 'https://www.googleapis.com';
    public const OAUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const DEFAULT_CONNECT_TIMEOUT = 30;
    private const DEFAULT_REQUEST_TIMEOUT = 5 * 60;

    public const AUTH_TYPE_OAUTH = 'oauth';
    public const AUTH_TYPE_SERVICE_ACCOUNT = 'service_account';

    /** @var int */
    protected $maxBackoffs = 7;

    /** @var callable */
    protected $backoffCallback403;

    /** @var string */
    protected $accessToken = '';

    /** @var string */
    protected $refreshToken = '';

    /** @var string */
    protected $clientId;

    /** @var string */
    protected $clientSecret;

    /** @var callable */
    protected $refreshTokenCallback;

    /** @var callable */
    protected $delayFn = null;

    /** @var ?LoggerInterface */
    protected $logger;

    /** @var string */
    protected $authType = self::AUTH_TYPE_OAUTH;

    /** @var ?array */
    protected $serviceAccountConfig;

    /** @var ?array<string> */
    protected $scopes;

    /** @var mixed */
    protected $serviceAccountCredentials;

    /** @var ?string */
    protected $serviceAccountAccessToken;

    /** @var ?int */
    protected $serviceAccountTokenExpiry;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;

        $this->backoffCallback403 = function () {
            return true;
        };
    }

    /**
     * Factory method for creating REST API client with OAuth authentication
     */
    public static function createWithOAuth(
        string $clientId,
        string $clientSecret,
        string $accessToken = '',
        string $refreshToken = '',
        ?LoggerInterface $logger = null,
    ): self {
        $instance = new self($logger);
        $instance->authType = self::AUTH_TYPE_OAUTH;
        $instance->clientId = $clientId;
        $instance->clientSecret = $clientSecret;
        $instance->setCredentials($accessToken, $refreshToken);

        $instance->backoffCallback403 = function () {
            return true;
        };

        return $instance;
    }

    /**
     * Factory method for creating REST API client with Service Account authentication
     */
    public static function createWithServiceAccount(
        array $serviceAccountConfig,
        array $scopes,
        ?LoggerInterface $logger = null,
    ): self {
        $instance = new self($logger);
        $instance->authType = self::AUTH_TYPE_SERVICE_ACCOUNT;
        $instance->serviceAccountConfig = $serviceAccountConfig;
        $instance->scopes = $scopes;
        $instance->initializeServiceAccountCredentials();
        return $instance;
    }

    /**
     * Initialize Service Account credentials using Google Auth SDK
     */
    protected function initializeServiceAccountCredentials(): void
    {
        if ($this->serviceAccountConfig === null || empty($this->scopes)) {
            throw new RestApiException('Service account configuration and scopes are required', 400);
        }

        try {
            $this->serviceAccountCredentials = CredentialsLoader::makeCredentials(
                $this->scopes,
                $this->serviceAccountConfig,
            );
        } catch (Throwable $e) {
            throw new RestApiException('Failed to initialize service account credentials: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get access token for Service Account
     */
    protected function getServiceAccountAccessToken(): string
    {
        // Return cached token if still valid
        if ($this->serviceAccountAccessToken !== null &&
            $this->serviceAccountTokenExpiry !== null &&
            time() < $this->serviceAccountTokenExpiry - 60) { // 60s buffer
            return $this->serviceAccountAccessToken;
        }

        if ($this->serviceAccountCredentials === null) {
            throw new RestApiException('Service account credentials not initialized', 500);
        }

        try {
            // Fetch new access token using Google Auth SDK
            $authToken = $this->serviceAccountCredentials->fetchAuthToken();

            if (!isset($authToken['access_token'])) {
                throw new RestApiException('Failed to retrieve access token from service account', 500);
            }

            $this->serviceAccountAccessToken = $authToken['access_token'];
            $this->serviceAccountTokenExpiry = time() + ($authToken['expires_in'] ?? 3600);

            return $this->serviceAccountAccessToken;
        } catch (Throwable $e) {
            throw new RestApiException('Failed to fetch service account access token: ' . $e->getMessage(), 500);
        }
    }

    public static function createRetryMiddleware(
        callable $decider,
        callable $callback,
        ?callable $delay = null,
    ): callable {
        return function (callable $handler) use ($decider, $callback, $delay) {
            return new RetryCallbackMiddleware($decider, $callback, $handler, $delay);
        };
    }

    public function createRetryDecider(int $maxRetries = 5): callable
    {
        return function (
            $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
        ) use ($maxRetries) {
            $decision = $this->decideRetry($retries, $maxRetries, $response);
            if ($decision) {
                $this->logRetryRequest($retries, $request, $response);
            }

            return $decision;
        };
    }

    public function createRetryCallback(): callable
    {
        $api = $this;

        return function (
            RequestInterface $request,
            ?ResponseInterface $response = null,
        ) use ($api) {
            if ($response && $response->getStatusCode() === 401) {
                if ($api->authType === self::AUTH_TYPE_SERVICE_ACCOUNT) {
                    // For Service Account, get new access token
                    $api->serviceAccountAccessToken = null; // Clear cache
                    $accessToken = $api->getServiceAccountAccessToken();
                    return $request->withHeader('Authorization', 'Bearer ' . $accessToken);
                } else {
                    // For OAuth, use refresh token
                    $tokens = $api->refreshToken();
                    return $request->withHeader('Authorization', 'Bearer ' . $tokens['access_token']);
                }
            }
            return $request;
        };
    }

    protected function getClient(string $baseUri = self::API_URI): Client
    {
        $handlerStack = HandlerStack::create(new CurlHandler());

        $handlerStack->push(self::createRetryMiddleware(
            $this->createRetryDecider($this->maxBackoffs),
            $this->createRetryCallback(),
            $this->delayFn,
        ));

        return new Client([
            'base_uri' => $baseUri,
            'handler' => $handlerStack,
            'connect_timeout' => self::DEFAULT_CONNECT_TIMEOUT,
            'timeout' => self::DEFAULT_REQUEST_TIMEOUT,
        ]);
    }

    public function setDelayFn(callable $delayFn): void
    {
        $this->delayFn = $delayFn;
    }

    public function setBackoffsCount(int $cnt): void
    {
        $this->maxBackoffs = $cnt;
    }

    public function setBackoffCallback403(callable $function): void
    {
        $this->backoffCallback403 = $function;
    }

    public function setCredentials(string $accessToken, string $refreshToken): void
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
    }

    public function getAccessToken(): string
    {
        if ($this->authType === self::AUTH_TYPE_SERVICE_ACCOUNT) {
            return $this->getServiceAccountAccessToken();
        }

        return $this->accessToken;
    }

    public function getRefreshToken(): array
    {
        if ($this->authType === self::AUTH_TYPE_SERVICE_ACCOUNT) {
            throw new RestApiException('Refresh token is not applicable for service account authentication', 400);
        }

        return $this->refreshToken();
    }

    public function getAuthType(): string
    {
        return $this->authType;
    }

    public function setRefreshTokenCallback(callable $callback): void
    {
        $this->refreshTokenCallback = $callback;
    }

    public function setAppCredentials(string $clientId, string $clientSecret): void
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    public function getAuthorizationUrl(
        string $redirectUri,
        string $scope,
        string $approvalPrompt = 'force',
        string $accessType = 'offline',
        string $state = '',
    ): string {
        $params = [
            'response_type=code',
            'redirect_uri=' . urlencode($redirectUri),
            'client_id=' . urlencode($this->clientId),
            'scope=' . urlencode($scope),
            'access_type=' . urlencode($accessType),
            'approval_prompt=' . urlencode($approvalPrompt),
        ];

        if ($state) {
            $params[] = 'state=' . urlencode($state);
        }

        $params = implode('&', $params);

        return self::OAUTH_URL . "?$params";
    }

    public function authorize(string $code, string $redirectUri): array
    {
        $client = $this->getClient();

        $response = $client->request('post', '/oauth2/v4/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ],
        ]);

        /** @var array<string, string> $responseBody */
        $responseBody = json_decode((string) $response->getBody(), true);

        $this->accessToken = $responseBody['access_token'];
        $this->refreshToken = $responseBody['refresh_token'];

        return $responseBody;
    }

    public function refreshToken(): array
    {
        $client = new Client(['base_uri' => self::API_URI]);

        $response = $client->request('post', '/oauth2/v4/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token',
            ],
        ]);

        /** @var array<string, string> $responseBody */
        $responseBody = json_decode($response->getBody()->getContents(), true);

        $this->accessToken = $responseBody['access_token'];
        if (isset($responseBody['refresh_token'])) {
            $this->refreshToken = $responseBody['refresh_token'];
        }

        if ($this->refreshTokenCallback !== null) {
            call_user_func($this->refreshTokenCallback, $this->accessToken, $this->refreshToken);
        }

        return $responseBody;
    }

    public function request(
        string $url,
        string $method = 'GET',
        array $addHeaders = [],
        array $options = [],
    ): Response {
        $method = strtolower($method);
        if (!in_array($method, ['get', 'head', 'post', 'put', 'patch', 'delete', 'options'])) {
            throw new RestApiException('Wrong http method specified', 500);
        }

        // Validate authentication based on type
        if ($this->authType === self::AUTH_TYPE_OAUTH && $this->refreshToken === null) {
            throw new RestApiException('Refresh token must be set for OAuth authentication', 400);
        } elseif ($this->authType === self::AUTH_TYPE_SERVICE_ACCOUNT &&
            ($this->serviceAccountConfig === null || $this->scopes === null || empty($this->scopes))
        ) {
            throw new RestApiException(
                'Service account configuration and scopes must be set for service account authentication',
                400,
            );
        }

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];

        foreach ($addHeaders as $k => $v) {
            $headers[$k] = $v;
        }

        $options['headers'] = $headers;

        return $this->getClient()->$method($url, $options);
    }

    protected function logRetryRequest(
        int $retries,
        RequestInterface $request,
        ?ResponseInterface $response = null,
    ): void {
        if ($this->logger !== null) {
            $headersForLog = $request->getHeaders();
            if (array_key_exists('authorization', array_change_key_case($headersForLog, CASE_LOWER))) {
                $headersForLog['Authorization'] = '*****';
            }

            $context = [
                'request' => [
                    'uri' => $request->getUri()->__toString(),
                    'headers' => $headersForLog,
                    'method' => $request->getMethod(),
                    'body' => $request->getBody()->getContents(),
                ],
            ];

            if ($response) {
                $context['response'] = [
                    'statusCode' => $response->getStatusCode(),
                    'reason' => $response->getReasonPhrase(),
                    'body' => $response->getBody()->getContents(),
                ];

                $this->logger->info(
                    sprintf('Retrying request (%dx) - reason: %s', $retries, $response->getReasonPhrase()),
                    $context,
                );
            } else {
                $this->logger->info(sprintf('Retrying request (%dx)', $retries), $context);
            }
        }
    }

    protected function decideRetry(int $retries, int $maxRetries, ?ResponseInterface $response = null): bool
    {
        if ($response) {
            if ($retries >= $maxRetries) {
                return false;
            }
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                return false;
            }
            if ($statusCode === 400) {
                if (strpos((string) $response->getBody(), 'Unknown metric') !== false) {
                    return true;
                }
                return false;
            }
            //allow only one retry for refreshing token
            if ($statusCode === 401 && $retries > 0 && $response->getReasonPhrase() !== 'Service Unavailable') {
                return false;
            }
            if ($statusCode === 403) {
                return call_user_func($this->backoffCallback403, $response);
            }
        }
        return true;
    }
}
