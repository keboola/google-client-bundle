<?php

declare(strict_types=1);

namespace Keboola\Google\ClientBundle\Tests;

use Exception;
use GuzzleHttp\Exception\ClientException;
use Keboola\Google\ClientBundle\Exception\RestApiException;
use Keboola\Google\ClientBundle\Google\RestApi;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SebastianBergmann\Timer\Timer;

class RestApiTest extends TestCase
{
    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var string */
    private $refreshToken;

    /** @var Logger */
    private $logger;

    /** @var TestHandler */
    private $testHandler;

    protected function initApi(): RestApi
    {
        $this->clientId = $this->getEnv('CLIENT_ID');
        $this->clientSecret = $this->getEnv('CLIENT_SECRET');
        $this->refreshToken = $this->getEnv('REFRESH_TOKEN');
        $this->testHandler = new TestHandler();
        $this->logger = new Logger('Google Rest API tests');
        $this->logger->pushHandler($this->testHandler);

        $api = new RestApi($this->logger);
        $api->setAppCredentials($this->clientId, $this->clientSecret);
        $api->setCredentials('', $this->refreshToken);

        return $api;
    }

    protected function initOAuthApi(): RestApi
    {
        $this->clientId = $this->getEnv('CLIENT_ID');
        $this->clientSecret = $this->getEnv('CLIENT_SECRET');
        $this->refreshToken = $this->getEnv('REFRESH_TOKEN');
        $this->testHandler = new TestHandler();
        $this->logger = new Logger('Google Rest API tests');
        $this->logger->pushHandler($this->testHandler);

        return RestApi::createWithOAuth(
            $this->clientId,
            $this->clientSecret,
            '',
            $this->refreshToken,
            $this->logger,
        );
    }

    protected function getServiceAccountConfig(): array
    {
        $serviceAccountJson = $this->getEnv('SERVICE_ACCOUNT_JSON');
        return json_decode($serviceAccountJson, true);
    }

    protected function initServiceAccountApi(): RestApi
    {
        $this->testHandler = new TestHandler();
        $this->logger = new Logger('Google Rest API tests');
        $this->logger->pushHandler($this->testHandler);

        $serviceAccountConfig = $this->getServiceAccountConfig();
        $scopes = ['https://www.googleapis.com/auth/cloud-platform'];

        return RestApi::createWithServiceAccount(
            $serviceAccountConfig,
            $scopes,
            $this->logger,
        );
    }

    public function testGetAuthorizationUrl(): void
    {
        $restApi = $this->initApi();
        $url = $restApi->getAuthorizationUrl('www.something.com', 'email', 'force', 'offline', 'state');

        $expectedUrl = RestApi::OAUTH_URL . '?response_type=code'
            . '&redirect_uri=www.something.com'
            . '&client_id=' . $this->clientId
            . '&scope=email'
            . '&access_type=offline'
            . '&approval_prompt=force'
            . '&state=state';

        $this->assertEquals($expectedUrl, $url);
    }

    public function testOAuthCreateWithOAuth(): void
    {
        $restApi = $this->initOAuthApi();
        $this->assertEquals(RestApi::AUTH_TYPE_OAUTH, $restApi->getAuthType());
    }

    public function testServiceAccountCreateWithServiceAccount(): void
    {
        if (!$this->hasServiceAccountCredentials()) {
            $this->markTestSkipped('Service account credentials not available');
        }

        $restApi = $this->initServiceAccountApi();
        $this->assertEquals(RestApi::AUTH_TYPE_SERVICE_ACCOUNT, $restApi->getAuthType());
    }

    public function testServiceAccountGetRefreshTokenThrowsException(): void
    {
        if (!$this->hasServiceAccountCredentials()) {
            $this->markTestSkipped('Service account credentials not available');
        }

        $restApi = $this->initServiceAccountApi();

        $this->expectException(RestApiException::class);
        $this->expectExceptionMessage('Refresh token is not applicable for service account authentication');

        $restApi->getRefreshToken();
    }

    public function testServiceAccountAuthentication(): void
    {
        if (!$this->hasServiceAccountCredentials()) {
            $this->markTestSkipped('Service account credentials not available');
        }

        $restApi = $this->initServiceAccountApi();
        $accessToken = $restApi->getAccessToken();

        $this->assertNotEmpty($accessToken);
        $this->assertIsString($accessToken);
    }

    public function testServiceAccountRequest(): void
    {
        if (!$this->hasServiceAccountCredentials()) {
            $this->markTestSkipped('Service account credentials not available');
        }

        $restApi = $this->initServiceAccountApi();
        $serviceAccountConfig = $this->getServiceAccountConfig();
        $serviceAccountEmail = $serviceAccountConfig['client_email'];
        $response = $restApi->request(sprintf(
            '/oauth2/v3/tokeninfo?access_token=%s',
            $restApi->getAccessToken(),
        ));

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertArrayHasKey('azp', $body);
        $this->assertArrayHasKey('aud', $body);
        $this->assertArrayHasKey('access_type', $body);
    }

    public function testServiceAccountTokenCaching(): void
    {
        if (!$this->hasServiceAccountCredentials()) {
            $this->markTestSkipped('Service account credentials not available');
        }

        $restApi = $this->initServiceAccountApi();

        // Get token twice - should be cached
        $token1 = $restApi->getAccessToken();
        $token2 = $restApi->getAccessToken();

        $this->assertEquals($token1, $token2);
        $this->assertNotEmpty($token1);
    }

    public function testServiceAccountInvalidConfiguration(): void
    {
        $this->testHandler = new TestHandler();
        $this->logger = new Logger('Google Rest API tests');
        $this->logger->pushHandler($this->testHandler);

        $invalidConfig = [
            'type' => 'service_account',
            'project_id' => 'invalid-project',
            // Missing required fields like private_key, client_email
        ];

        $this->expectException(RestApiException::class);
        $this->expectExceptionMessage('Failed to initialize service account credentials');

        RestApi::createWithServiceAccount(
            $invalidConfig,
            ['https://www.googleapis.com/auth/cloud-platform'],
            $this->logger,
        );
    }

    public function testServiceAccountRequestValidation(): void
    {
        // Create instance without proper configuration
        $api = new RestApi(null);

        // Manually set auth type to service account but don't initialize properly
        $reflection = new ReflectionClass($api);
        $authTypeProperty = $reflection->getProperty('authType');
        $authTypeProperty->setAccessible(true);
        $authTypeProperty->setValue($api, RestApi::AUTH_TYPE_SERVICE_ACCOUNT);

        $this->expectException(RestApiException::class);
        $this->expectExceptionMessage(
            'Service account configuration and scopes must be set for service account authentication',
        );

        $api->request('/test-endpoint');
    }

    public function testServiceAccountWithoutRequiredScopes(): void
    {
        if (!$this->hasServiceAccountCredentials()) {
            $this->markTestSkipped('Service account credentials not available');
        }

        $serviceAccountConfig = $this->getServiceAccountConfig();

        $this->expectException(RestApiException::class);
        $this->expectExceptionMessage('Service account configuration and scopes are required');

        // Try to create with empty scopes
        RestApi::createWithServiceAccount(
            $serviceAccountConfig,
            [], // Empty scopes
            $this->logger,
        );
    }

    public function testRefreshToken(): void
    {
        $restApi = $this->initApi();
        $response = $restApi->refreshToken();

        $this->assertArrayHasKey('access_token', $response);
        $this->assertNotEmpty($response['access_token']);
    }

    public function testOAuthRefreshToken(): void
    {
        $restApi = $this->initOAuthApi();
        $response = $restApi->refreshToken();

        $this->assertArrayHasKey('access_token', $response);
        $this->assertNotEmpty($response['access_token']);
    }

    public function testRequest(): void
    {
        $restApi = $this->initApi();
        $response = $restApi->request('/oauth2/v3/userinfo');
        $body = json_decode($response->getBody()->getContents(), true);
        self::assertIsArray($body);

        $this->assertArrayHasKey('sub', $body);
        $this->assertArrayHasKey('email', $body);
        $this->assertArrayHasKey('name', $body);
    }

    public function testOAuthRequest(): void
    {
        $restApi = $this->initOAuthApi();
        $response = $restApi->request('/oauth2/v3/userinfo');
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertArrayHasKey('sub', $body);
        $this->assertArrayHasKey('email', $body);
        $this->assertArrayHasKey('name', $body);
    }

    public function testDelayFn(): void
    {
        $timer = new Timer();
        $timer->start();

        $delayFn = function ($retries) {
            return (int) (5000 * pow(2, $retries - 1) + rand(0, 500));
        };

        $restApi = $this->initApi();
        $restApi->setDelayFn($delayFn);

        $response = $restApi->request('/oauth2/v3/userinfo');

        $time = $timer->stop()->asSeconds();

        $body = json_decode($response->getBody()->getContents(), true);
        self::assertIsArray($body);

        $this->assertArrayHasKey('sub', $body);
        $this->assertArrayHasKey('email', $body);
        $this->assertArrayHasKey('name', $body);
        $this->assertGreaterThan(5, $time);
    }

    public function testRetries(): void
    {
        $restApi = $this->initApi();
        $restApi->setBackoffsCount(2);
        try {
            $restApi->request('/auth/invalid-scope');
        } catch (ClientException $e) {
        }

        $this->assertNotCount(0, $this->testHandler->getRecords());

        foreach ($this->testHandler->getRecords() as $key => $value) {
            $this->assertEquals(Logger::INFO, $value['level']);
            $this->assertEquals(sprintf('Retrying request (%dx) - reason: Not Found', $key), $value['message']);
            $this->assertEquals(
                [
                    'request' => [
                        'uri' => 'https://www.googleapis.com/auth/invalid-scope',
                        'headers' => [
                            'User-Agent' => ['GuzzleHttp/7'],
                            'Host' => ['www.googleapis.com'],
                            'Accept' => ['application/json'],
                            'Authorization' => '*****',
                        ],
                        'method' => 'GET',
                        'body' => '',
                    ],
                    'response' => [
                        'statusCode' => 404,
                        'reason' => 'Not Found',
                        'body' => 'You are receiving this error either because your input OAuth2 scope name is '
                            . "invalid or it refers to a newer scope that is outside the domain of this legacy API.\n\n"
                            . 'This API was built at a time when the scope name format was not yet standardized. '
                            . 'This is no longer the case and all valid scope names (both old and new) are catalogued '
                            . 'at https://developers.google.com/identity/protocols/oauth2/scopes. Use that webpage to'
                            . ' lookup (manually) the scope name associated with the API you are trying to call and use'
                            . " it to craft your OAuth2 request.\n",
                    ],
                ],
                $value['context'],
            );
        }
    }

    public function testDoNotRetryOnWrongCredentials(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('Google Rest API tests');
        $logger->pushHandler($testHandler);
        $api = RestApi::createWithOAuth(
            $this->getEnv('CLIENT_ID'),
            $this->getEnv('CLIENT_SECRET') . 'invalid',
            '',
            $this->getEnv('REFRESH_TOKEN'),
            $logger,
        );

        try {
            $api->request('/oauth2/v3/userinfo');
        } catch (ClientException $e) {
            $this->assertStringContainsString('401 Unauthorized', $e->getMessage());
        }

        $this->assertCount(1, $testHandler->getRecords());
        $this->assertStringContainsString(
            'Retrying request (0x) - reason: Unauthorized',
            $testHandler->getRecords()[0]['message'],
        );
    }

    protected function getEnv(string $name): string
    {
        $value = getenv($name);

        if ($value === false) {
            throw new Exception(sprintf('Environment variable "%s" cannot be empty', $name));
        }

        return $value;
    }

    protected function hasServiceAccountCredentials(): bool
    {
        return getenv('SERVICE_ACCOUNT_JSON') !== false;
    }
}
