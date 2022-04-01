<?php

declare(strict_types=1);

namespace Keboola\Google\ClientBundle\Google;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Keboola\Google\ClientBundle\Exception\RestApiException;
use Keboola\Google\ClientBundle\Guzzle\RetryCallbackMiddleware;
use Monolog\Logger;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class RestApi
{
    public const API_URI = 'https://www.googleapis.com';
    public const OAUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** @var int */
    protected $maxBackoffs = 7;

    /** @var callable */
    protected $backoffCallback403;

    /** @var string */
    protected $accessToken;

    /** @var string */
    protected $refreshToken;

    /** @var string */
    protected $clientId;

    /** @var string */
    protected $clientSecret;

    /** @var callable */
    protected $refreshTokenCallback;

    /** @var callable */
    protected $delayFn = null;

    /** @var ?Logger */
    protected $logger;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $accessToken = '',
        string $refreshToken = '',
        ?Logger $logger = null
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->setCredentials($accessToken, $refreshToken);
        $this->logger = $logger;

        $this->backoffCallback403 = function () {
            return true;
        };
    }

    public static function createRetryMiddleware(
        callable $decider,
        callable $callback,
        ?callable $delay = null
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
            ?ResponseInterface $response = null
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
            ?ResponseInterface $response = null
        ) use ($api) {
            if ($response && $response->getStatusCode() === 401) {
                $tokens = $api->refreshToken();
                return $request->withHeader('Authorization', 'Bearer ' . $tokens['access_token']);
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
            $this->delayFn
        ));

        return new Client([
            'base_uri' => $baseUri,
            'handler' => $handlerStack,
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
        return $this->accessToken;
    }

    public function getRefreshToken(): array
    {
        return $this->refreshToken();
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
        string $state = ''
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
        array $options = []
    ): Response {
        $method = strtolower($method);
        if (!in_array($method, ['get', 'head', 'post', 'put', 'patch', 'delete', 'options'])) {
            throw new RestApiException('Wrong http method specified', 500);
        }

        if ($this->refreshToken === null) {
            throw new RestApiException('Refresh token must be set', 400);
        }

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->accessToken,
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
        ?ResponseInterface $response = null
    ): void {
        if ($this->logger !== null) {
            $headersForLog = array_map(function ($item, $key) {
                if (strtolower($key) === 'authorization') {
                    return '*****';
                }
                return $item;
            }, $request->getHeaders(), array_keys($request->getHeaders()));

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
            }

            $this->logger->info(sprintf('Retrying request (%sx)', $retries), $context);
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
                return false;
            }
            if ($statusCode === 403) {
                return call_user_func($this->backoffCallback403, $response);
            }
        }
        return true;
    }
}
