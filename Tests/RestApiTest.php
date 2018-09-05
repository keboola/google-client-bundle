<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 29/10/15
 * Time: 17:06
 */

namespace Keboola\Google\ClientBundle\Google;

use Monolog\Logger;

class RestApiTest extends \PHPUnit_Framework_TestCase
{
    private $clientId;

    private $clientSecret;

    private $refreshToken;

    private $logger;

    protected function initApi($delayFn = null)
    {
        $this->clientId = getenv('CLIENT_ID');
        $this->clientSecret = getenv('CLIENT_SECRET');
        $this->refreshToken = getenv('REFRESH_TOKEN');
        $this->logger = new Logger('Google Rest API tests');

        $restApi = new RestApi(
            $this->clientId,
            $this->clientSecret,
            null,
            $this->refreshToken,
            $this->logger,
            $delayFn
        );

        return $restApi;
    }

    public function testGetAuthorizationUrl()
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

    public function testRefreshToken()
    {
        $restApi = $this->initApi();
        $response = $restApi->refreshToken();

        $this->assertArrayHasKey('access_token', $response);
    }

    public function testRequest()
    {
        $restApi = $this->initApi();
        $response = $restApi->request('/oauth2/v3/userinfo');
        $body = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('sub', $body);
        $this->assertArrayHasKey('email', $body);
        $this->assertArrayHasKey('name', $body);
    }

    public function testDelayFn()
    {
        \PHP_Timer::start();

        $delayFn = function ($retries) {
            return (int) (5000 * pow(2, $retries - 1) + rand(0, 500));
        };

        $restApi = $this->initApi($delayFn);
        $response = $restApi->request('/oauth2/v3/userinfo');

        \PHP_Timer::stop();
        $time = \PHP_Timer::timeSinceStartOfRequest();

        $body = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('sub', $body);
        $this->assertArrayHasKey('email', $body);
        $this->assertArrayHasKey('name', $body);
        $this->assertGreaterThan(5, $time);
    }
}
