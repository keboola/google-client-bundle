<?php
/**
 * RestApiTest.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 24.6.13
 */

namespace Keboola\Google\ClientBundle\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Guzzle\Service\Client;

class RestApiTest extends WebTestCase
{

	public function testGetUserinfo()
	{
		$storageApiToken = '226-420796630473d70ca6a1505624bb6db871553892';

		$host = 'http://google-client.awsdevel.keboola.com/api-google';
		$client = new Client($host);

		$accesToken = 'ya29.AHES6ZRt-vLacLwH9pe4aL4ahcIbba47Ed2EQ8kdBG3yXXk';
		$refreshToken = '1/jPkPndXe8QiIOP2nNrocpjsa41LPyPW90T63GxBmtOY';

//		$request = $client->get($host . '/user-info?access_token=' . $accesToken . '&refresh_token=' . $refreshToken);
		$request = $client->get("/user-info");
		$response = $request->send();

		var_dump($response->json());
	}
}
