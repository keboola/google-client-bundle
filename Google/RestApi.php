<?php
/**
 * RestApi.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 21.6.13
 */

namespace Keboola\Google\ClientBundle\Google;

use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Plugin\Backoff\CallbackBackoffStrategy;
use Guzzle\Plugin\Backoff\CurlBackoffStrategy;
use Guzzle\Plugin\Backoff\ExponentialBackoffStrategy;
use Guzzle\Plugin\Backoff\HttpBackoffStrategy;
use Guzzle\Plugin\Backoff\TruncatedBackoffStrategy;
use Guzzle\Service\Client as HttpClient;
use Guzzle\Plugin\Backoff\BackoffPlugin;
use Keboola\Google\ClientBundle\Exception\RestApiException;

class RestApi {
	const OAUTH_URL = 'https://accounts.google.com/o/oauth2/auth';
	const OAUTH_TOKEN_URL = 'https://accounts.google.com/o/oauth2/token';
	const USER_INFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

	protected $accessToken;
	protected $refreshToken;
	protected $clientId;
	protected $clientSecret;

	protected $refreshTokenCallback;

	public function __construct($clientId, $clientSecret, $accessToken = null, $refreshToken = null)
	{
		$this->clientId = $clientId;
		$this->clientSecret = $clientSecret;
		$this->setCredentials($accessToken, $refreshToken);
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
	 * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
	 * @return array accessToken, refreshToken
	 */
	public function authorize($code, $redirectUri)
	{
		$client = new HttpClient();
		$request = $client->post(self::OAUTH_TOKEN_URL, array(
			'Content-Type'	=> 'application/x-www-form-urlencoded',
			'Content-Transfer-Encoding' => 'binary'
		), array(
			'code'	        => $code,
			'client_id'	    => $this->clientId,
			'client_secret'	=> $this->clientSecret,
			'redirect_uri'	=> $redirectUri,
			'grant_type'	=> 'authorization_code'
		));

		$response = $request->send();

		if ($response->getStatusCode() != 200) {
			throw new RestApiException($response->getStatusCode(), $response->getMessage());
		}

		$body = $response->json();

		$this->accessToken = $body['access_token'];
		$this->refreshToken = $body['refresh_token'];

		return $body;
	}

	public function refreshToken()
	{
		$client = new HttpClient();
		$request = $client->post(self::OAUTH_TOKEN_URL, array(
			'Content-Type'	=> 'application/x-www-form-urlencoded',
			'Content-Transfer-Encoding' => 'binary'
		), array(
			'refresh_token'	=> $this->refreshToken,
			'client_id'		=> $this->clientId,
			'client_secret' => $this->clientSecret,
			'grant_type'	=> 'refresh_token'
		));

		$response = $request->send();

		if ($response->getStatusCode() != 200) {
			throw new RestApiException($response->getStatusCode(), $response->getMessage());
		}

		$body = $response->json();

		$this->accessToken = $body['access_token'];
		if (isset($body['refresh_token'])) {
			$this->refreshToken = $body['refresh_token'];
		}

		if ($this->refreshTokenCallback != null) {
			call_user_func($this->refreshTokenCallback, $this->accessToken, $this->refreshToken);
		}

		return $body;
	}

	public function getBackoffCallback()
	{
		$api = $this;
		return function($retries, Request $request, Response $response, $e) use ($api) {
			if ($response) {
				//Short circuit the rest of the checks if it was successful
				if ($response->isSuccessful()) {
					return false;
				}
				if ($response->getStatusCode() == 401) {
					$tokens = $api->refreshToken();
					$request->setHeader('Authorization', 'Bearer ' . $tokens['access_token']);
				}
				return true;
			}
		};
	}

	/**
	 * Call Google REST API
	 *
	 * @param String $url
	 * @param String $method
	 * @param Array $addHeaders
	 * @param Array $params
	 * @throws RestApiException
	 * @return Response $response
	 */
	public function call($url, $method = 'GET', $addHeaders = array(), $params = array())
	{
		/** @var Response $response */
		$response = $this->request($url, $method, $addHeaders, $params)->send();

		if ($response->getStatusCode() != 200) {
			throw new RestApiException($response->getStatusCode(), $response->getMessage());
		}

		return $response;
	}

	/**
	 * @param $url
	 * @param string $method
	 * @param array $addHeaders
	 * @param array $params
	 * @return Request
	 * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
	 */
	public function request($url, $method = 'GET', $addHeaders = array(), $params = array())
	{
		if (null == $this->accessToken) {
			throw new RestApiException("Access Token must be set", 400);
		}

		$headers = array(
			'Accept' => 'application/json',
			'Authorization'	=> 'Bearer ' . $this->accessToken
		);

		if (null != $addHeaders && is_array($addHeaders)) {
			foreach($addHeaders as $k => $v) {
				$headers[$k] = $v;
			}
		}

		$client = new HttpClient();
		$client->addSubscriber(new BackoffPlugin(new TruncatedBackoffStrategy(3,
			new HttpBackoffStrategy(null,
				new CurlBackoffStrategy(null,
					new CallbackBackoffStrategy($this->getBackoffCallback(), true,
						new ExponentialBackoffStrategy()
					)
				)
			)
		)));

		/** @var Request $request */
		switch (strtolower($method)) {
			case 'get':
				$request = $client->get($url, $headers, $params);
				break;
			case 'post':
				$request = $client->post($url, $headers, $params);
				break;
		}

		return $request;
	}
}



