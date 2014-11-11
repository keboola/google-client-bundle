<?php
/**
 * RestApi.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 21.6.13
 */

namespace Keboola\Google\ClientBundle\Google;

use GuzzleHttp\Client;
use GuzzleHttp\Event\AbstractTransferEvent;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;

use GuzzleHttp\Subscriber\Retry\RetrySubscriber;
use Keboola\Google\ClientBundle\Exception\RestApiException;

class RestApi {
	const ACCOUNTS_URL      = 'https://accounts.google.com/o/oauth2/';

	const OAUTH_URL         = 'https://accounts.google.com/o/oauth2/auth';
	const OAUTH_TOKEN_URL   = 'https://accounts.google.com/o/oauth2/token';

	const USER_INFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

	protected $maxBackoffs = 5;
	protected $backoffCallback403;

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

		$this->backoffCallback403 = function () {};
	}

	protected function getClient($baseUrl = '')
	{
		return new Client([
			'base_url' => $baseUrl,
		]);
	}

	protected function createExponentialBackoffSubscriber()
	{
		$filter = RetrySubscriber::createChainFilter([
			RetrySubscriber::createCurlFilter(),
			RetrySubscriber::createStatusFilter([500,502,503,504]),
			$this->getBackoffCallback()
		]);

		return new RetrySubscriber([
			'filter' => $filter,
			'max' => $this->maxBackoffs,
		]);
	}

	public function setBackoffsCount($cnt)
	{
		$this->maxBackoffs = $cnt;
	}

	public function setBackoffCallback403($function)
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
	 * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
	 * @return array accessToken, refreshToken
	 */
	public function authorize($code, $redirectUri)
	{
		$client = $this->getClient(self::ACCOUNTS_URL);
		$client->getEmitter()->attach($this->createExponentialBackoffSubscriber());

		$response = $client->post('token', [
			'headers' => [
				'Content-Type'	=> 'application/x-www-form-urlencoded',
				'Content-Transfer-Encoding' => 'binary'
			],
			'body' => [
				'code'	        => $code,
				'client_id'	    => $this->clientId,
				'client_secret'	=> $this->clientSecret,
				'redirect_uri'	=> $redirectUri,
				'grant_type'	=> 'authorization_code'
			]
		])->json();

		$this->accessToken = $response['access_token'];
		$this->refreshToken = $response['refresh_token'];

		return $response;
	}

	public function refreshToken()
	{
		$client = $this->getClient(self::ACCOUNTS_URL);
		$client->getEmitter()->attach($this->createExponentialBackoffSubscriber());

		$response = $client->post('token', [
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Content-Transfer-Encoding' => 'binary'
			],
			'body' => [
				'refresh_token'	=> $this->refreshToken,
				'client_id'		=> $this->clientId,
				'client_secret' => $this->clientSecret,
				'grant_type'	=> 'refresh_token'
			]
		])->json();

		$this->accessToken = $response['access_token'];
		if (isset($response['refresh_token'])) {
			$this->refreshToken = $response['refresh_token'];
		}

		if ($this->refreshTokenCallback != null) {
			call_user_func($this->refreshTokenCallback, $this->accessToken, $this->refreshToken);
		}

		return $response;
	}

	public function getBackoffCallback()
	{
		$api = $this;
		return function ($retries, AbstractTransferEvent $event) use ($api) {

			/** @var Response $response */
			$response = $event->getResponse() ?: null;
			if ($response) {

				if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
					return false;
				}

				if ($response->getStatusCode() == 401) {
					$tokens = $api->refreshToken();
					$event->getRequest()->setHeader('Authorization', 'Bearer ' . $tokens['access_token']);
				}

				if ($response->getStatusCode() == 403) {
					call_user_func($this->backoffCallback403, $response);
				}
			}

			return true;
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
		return $this->request($url, $method, $addHeaders, $params);
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

		$headers = [
			'Accept'        => 'application/json',
			'Authorization'	=> 'Bearer ' . $this->accessToken
		];

		if (null != $addHeaders && is_array($addHeaders)) {
			foreach($addHeaders as $k => $v) {
				$headers[$k] = $v;
			}
		}

		$client = $this->getClient();
		$client->getEmitter()->attach($this->createExponentialBackoffSubscriber());

		/** @var Request $request */
		switch (strtolower($method)) {
			case 'get':
				$request = $client->get($url, [
					'headers' => $headers,
					'body' => $params
				]);
				break;
			case 'post':
				$request = $client->post($url, [
					'headers' => $headers,
					'body' => $params
				]);
				break;
		}

		return $request;
	}
}



