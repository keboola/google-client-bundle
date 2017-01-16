<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 29/10/15
 * Time: 17:06
 */

namespace Keboola\Google\ClientBundle\Google;

class RestApiTest extends \PHPUnit_Framework_TestCase
{
    private $clientId;

    private $clientSecret;

    protected function initApi()
    {
        $this->clientId = getenv('CLIENT_ID');
        $this->clientSecret = getenv('CLIENT_SECRET');
        $refreshToken = getenv('REFRESH_TOKEN');

        $restApi = new RestApi($this->clientId, $this->clientSecret, null, $refreshToken);

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
}
