<?php

declare(strict_types=1);

namespace Keboola\Google\ClientBundle\Tests;

use Keboola\Google\ClientBundle\Google\RestApi;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
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

    protected function initApi(): RestApi
    {
        $this->clientId = $this->getEnv('CLIENT_ID');
        $this->clientSecret = $this->getEnv('CLIENT_SECRET');
        $this->refreshToken = $this->getEnv('REFRESH_TOKEN');
        $this->logger = new Logger('Google Rest API tests');

        return new RestApi(
            $this->clientId,
            $this->clientSecret,
            '',
            $this->refreshToken,
            $this->logger
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

    public function testRefreshToken(): void
    {
        $restApi = $this->initApi();
        $response = $restApi->refreshToken();

        $this->assertArrayHasKey('access_token', $response);
        $this->assertNotEmpty($response['access_token']);
    }

    public function testRequest(): void
    {
        $restApi = $this->initApi();
        $response = $restApi->request('/oauth2/v3/userinfo');
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertArrayHasKey('sub', $body);
        $this->assertArrayHasKey('email', $body);
        $this->assertArrayHasKey('name', $body);
    }

    public function testDelayFn(): void
    {
        Timer::start();

        $delayFn = function ($retries) {
            return (int) (5000 * pow(2, $retries - 1) + rand(0, 500));
        };

        $restApi = $this->initApi();
        $restApi->setDelayFn($delayFn);

        $response = $restApi->request('/oauth2/v3/userinfo');

        Timer::stop();
        $time = Timer::timeSinceStartOfRequest();

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertArrayHasKey('sub', $body);
        $this->assertArrayHasKey('email', $body);
        $this->assertArrayHasKey('name', $body);
        $this->assertGreaterThan(5, $time);
    }

    protected function getEnv(string $name): string
    {
        $value = getenv($name);

        if ($value === false) {
            throw new \Exception(sprintf('Environment variable "%s" cannot be empty', $name));
        }

        return $value;
    }

}
