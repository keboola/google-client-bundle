<?php
/**
 * RestApi.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 21.6.13
 */

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

    const API_URI = 'https://www.googleapis.com';
    const OAUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    protected $maxBackoffs = 7;
    protected $backoffCallback403;

    protected $accessToken;
    protected $refreshToken;
    protected $clientId;
    protected $clientSecret;

    protected $refreshTokenCallback;

    /** @var callable */
    protected $delayFn = null;

    /** @var Logger */
    protected $logger;

    public function __construct(
        $clientId,
        $clientSecret,
        $accessToken = null,
        $refreshToken = null,
        $logger = null
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->setCredentials($accessToken, $refreshToken);
        $this->logger = $logger;

        $this->backoffCallback403 = function () {
            return true;
        };
    }

    public static function createRetryMiddleware(callable $decider, callable $callback, callable $delay = null)
    {
        return function (callable $handler) use ($decider, $callback, $delay) {
            return new RetryCallbackMiddleware($decider, $callback, $handler, $delay);
        };
    }

    public function createRetryDecider($maxRetries = 5)
    {
        return function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            $error = null
        ) use ($maxRetries) {
            $decision = $this->decideRetry($retries, $maxRetries, $response);
            if ($decision) {
                $this->logRetryRequest($retries, $request, $response);
            }

            return $decision;
        };
    }

    public function createRetryCallback()
    {
        $api = $this;

        return function (
            RequestInterface $request,
            ResponseInterface $response = null
        ) use ($api) {
            if ($response->getStatusCode() == 401) {
                $tokens = $api->refreshToken();
                return $request->withHeader('Authorization', 'Bearer ' . $tokens['access_token']);
            }
            return $request;
        };
    }

    protected function getClient($baseUri = self::API_URI)
    {
        $handlerStack = HandlerStack::create(new CurlHandler());

        $handlerStack->push(self::createRetryMiddleware(
            $this->createRetryDecider($this->maxBackoffs),
            $this->createRetryCallback(),
            $this->delayFn
        ));

        return new Client([
            'base_uri' => $baseUri,
            'handler' => $handlerStack
        ]);
    }

    public function setDelayFn(callable $delayFn)
    {
        $this->delayFn = $delayFn;
    }

    public function setBackoffsCount($cnt)
    {
        $this->maxBackoffs = $cnt;
    }

    /**
     * Set decider function which will decide whether to retry or not on 403 response code
     * @param callable $function
     */
    public function setBackoffCallback403(callable $function)
    {
        $this->backoffCallback403 = $function;
    }

    public function setCredentials($accessToken, $refreshToken)
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
    }

    public function getAccessToken()
    {
        return $this->accessToken;
    }

    public function getRefreshToken()
    {
        return $this->refreshToken();
    }

    public function setRefreshTokenCallback($callback)
    {
        $this->refreshTokenCallback = $callback;
    }

    public function setAppCredentials($clientId, $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Obtains authorization code by user login into Google and approve app
     *
     * @param $redirectUri
     * @param $scope
     * @param string $approvalPrompt
     * @param string $accessType
     * @param string $state
     * @return string
     */
    public function getAuthorizationUrl($redirectUri, $scope, $approvalPrompt = 'force', $accessType = 'offline', $state = '')
    {
        $params = array(
            'response_type=code',
            'redirect_uri=' . urlencode($redirectUri),
            'client_id=' . urlencode($this->clientId),
            'scope=' . urlencode($scope),
            'access_type=' . urlencode($accessType),
            'approval_prompt=' . urlencode($approvalPrompt)
        );

        if ($state) {
            $params[] = 'state=' . urlencode($state);
        }

        $params = implode('&', $params);

        return self::OAUTH_URL . "?$params";
    }

    /**
     * Get OAuth 2.0 Access Token
     *
     * @param String $code - authorization code
     * @param $redirectUri
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return array accessToken, refreshTokenp
     */
    public function authorize($code, $redirectUri)
    {
        $client = $this->getClient();

        $response = $client->request('post', '/oauth2/v4/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'form_params' => [
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code'
            ]
        ]);

        $responseBody = json_decode($response->getBody(), true);

        $this->accessToken = $responseBody['access_token'];
        $this->refreshToken = $responseBody['refresh_token'];

        return $responseBody;
    }

    public function refreshToken()
    {
        $client = new Client(['base_uri' => self::API_URI]);

        $response = $client->request('post', '/oauth2/v4/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'form_params' => [
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token'
            ]
        ]);

        $responseBody = json_decode($response->getBody(), true);

        $this->accessToken = $responseBody['access_token'];
        if (isset($responseBody['refresh_token'])) {
            $this->refreshToken = $responseBody['refresh_token'];
        }

        if ($this->refreshTokenCallback != null) {
            call_user_func($this->refreshTokenCallback, $this->accessToken, $this->refreshToken);
        }

        return $responseBody;
    }

    /**
     * Call Google REST API
     *
     * @param $url
     * @param string $method
     * @param array $addHeaders
     * @param $options
     * @return Response
     * @throws RestApiException
     */
    public function request($url, $method = 'GET', $addHeaders = [], $options = [])
    {
        $method = strtolower($method);
        if (!in_array($method, ['get', 'head', 'post', 'put', 'patch', 'delete', 'options'])) {
            throw new RestApiException("Wrong http method specified", 500);
        }

        if (null == $this->refreshToken) {
            throw new RestApiException("Refresh token must be set", 400);
        }

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->accessToken
        ];

        if (null != $addHeaders && is_array($addHeaders)) {
            foreach ($addHeaders as $k => $v) {
                $headers[$k] = $v;
            }
        }

        $options['headers'] = $headers;

        return $this->getClient()->$method($url, $options);
    }

    protected function logRetryRequest($retries, RequestInterface $request, ResponseInterface $response = null)
    {
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
                    'body' => $request->getBody()->getContents()
                ],
            ];

            if ($response) {
                $context['response'] = [
                    'statusCode' => $response->getStatusCode(),
                    'reason' => $response->getReasonPhrase(),
                    'body' => $response->getBody()->getContents()
                ];
            }

            $this->logger->info(sprintf("Retrying request (%sx)", $retries), $context);
        }
    }

    protected function decideRetry($retries, $maxRetries, ResponseInterface $response = null)
    {
        if ($response) {
            if ($retries >= $maxRetries) {
                return false;
            }
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                return false;
            }
            if ($statusCode == 400) {
                return false;
            }
            if ($statusCode == 403) {
                return call_user_func($this->backoffCallback403, $response);
            }
        }
        return true;
    }
}

