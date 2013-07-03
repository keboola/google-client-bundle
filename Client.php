<?php
/**
 * Client.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 21.6.13
 */

namespace Keboola\Google\ClientBundle;


use Keboola\Google\ClientBundle\Google\RestApi;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Syrup\ComponentBundle\Component\Component;
use Keboola\StorageApi\Client as SapiClient;

class Client extends Component
{
	const USER_INFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

	protected $_name = 'google';
	protected $_prefix = 'api';

	protected function checkParams($params, $required)
	{
		foreach($required as $r) {
			if (!isset($params[$r])) {
				throw new HttpException(400, sprintf("Parameter %s is required", $r));
			}
		}
	}

	public function getAuthUrl($params)
	{
		$this->checkParams($params, array('redirectUri', 'scope'));

		$approvalPrompt = isset($params['approvalPrompt'])?$params['approvalPrompt']:'auto';
		$accessType = isset($params['accessType'])?$params['accessType']:'offline';
		$state = isset($params['state'])?$params['state']:'';

		/** @var RestApi $restApi */
		$restApi = $this->_container->get('google_rest_api');

		$authUrl = $restApi->getAuthorizationUrl(
			$params['redirectUri'], $params['scope'], $approvalPrompt, $accessType, $state
		);

		return array('auth-url' => $authUrl);
	}

	public function getTokens($params)
	{
		$this->checkParams($params, array('redirectUri', 'code'));

		/** @var RestApi $restApi */
		$restApi = $this->_container->get('google_rest_api');

		$tokens = $restApi->authorize($params['code'], $params['redirectUri']);

		return array('tokens' => $tokens);
	}

	/**
	 * Obtain basic user information
	 */
	public function getUserInfo($params)
	{
		$this->checkParams($params, array('access_token', 'refresh_token'));

		/** @var RestApi $restApi */
		$restApi = $this->_container->get('google_rest_api');
		$restApi->setCredentials($params['access_token'], $params['refresh_token']);

		$response = $restApi->call(self::USER_INFO_URL, 'GET');

		return array(
			'user-info' => $response->json()
		);
	}
}
