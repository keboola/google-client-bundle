# Keboola Google Client Bundle

Keboola Google API Client with OAuth 2.0 and Service Account authentication support.

## Installation

```bash
composer require keboola/google-client-bundle
```

## Usage

This library supports two types of authentication:

1. **OAuth 2.0** - for applications that need access to user data
2. **Service Account** - for server-to-server communication without user intervention

### OAuth 2.0 Authentication

#### Classic approach

```php
use Keboola\Google\ClientBundle\Google\RestApi;

$api = new RestApi($logger); // Optional logger parameter
$api->setAppCredentials($clientId, $clientSecret);
$api->setCredentials($accessToken, $refreshToken);

// Get authorization URL
$authUrl = $api->getAuthorizationUrl(
    'http://localhost/callback',
    'https://www.googleapis.com/auth/drive.readonly',
    'force',
    'offline'
);

// Authorize using code
$tokens = $api->authorize($code, 'http://localhost/callback');

// API calls
$response = $api->request('/drive/v2/files');
```

#### New factory approach

```php
use Keboola\Google\ClientBundle\Google\RestApi;

$api = RestApi::createWithOAuth(
    $clientId,
    $clientSecret,
    $accessToken,
    $refreshToken
);

// Usage same as above
$response = $api->request('/drive/v2/files');
```

### Service Account Authentication

```php
use Keboola\Google\ClientBundle\Google\RestApi;

// Service account JSON configuration (from Google Cloud Console)
$serviceAccountConfig = [
    'type' => 'service_account',
    'project_id' => 'your-project-id',
    'private_key_id' => 'key-id',
    'private_key' => '-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n',
    'client_email' => 'your-service-account@your-project.iam.gserviceaccount.com',
    'client_id' => '123456789',
    'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
    'token_uri' => 'https://oauth2.googleapis.com/token',
    'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
    'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/your-service-account%40your-project.iam.gserviceaccount.com'
];

// Scope definitions
$scopes = [
    'https://www.googleapis.com/auth/cloud-platform',
    'https://www.googleapis.com/auth/drive.readonly'
];

// Create API client
$api = RestApi::createWithServiceAccount(
    $serviceAccountConfig,
    $scopes
);

// API calls
$response = $api->request('/drive/v2/files');
```

#### Loading Service Account from JSON file

```php
use Keboola\Google\ClientBundle\Google\RestApi;

// Load from JSON file
$serviceAccountConfig = json_decode(
    file_get_contents('/path/to/service-account-key.json'),
    true
);

$scopes = ['https://www.googleapis.com/auth/cloud-platform'];

$api = RestApi::createWithServiceAccount($serviceAccountConfig, $scopes);
$response = $api->request('/your-api-endpoint');
```

## Differences between OAuth and Service Account

| Property | OAuth 2.0 | Service Account |
|----------|-----------|-----------------|
| Authentication type | User-based | Server-to-server |
| Refresh token | ✅ Yes | ❌ No (not needed) |
| Authorization | Requires user consent | Automatic |
| Usage | Access to user data | Access to application data |
| Token expiration | Based on refresh token | Automatic renewal |

## Advanced Usage

### Retry and Backoff

```php
$api->setBackoffsCount(10); // Number of retries
$api->setDelayFn(function($retries) {
    return 1000 * pow(2, $retries); // Exponential backoff
});
```

### Logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('google-api');
$logger->pushHandler(new StreamHandler('php://stdout'));

$api = RestApi::createWithServiceAccount(
    $serviceAccountConfig,
    $scopes,
    $logger
);
```

### Custom HTTP Options

```php
$response = $api->request('/endpoint', 'POST', [
    'Content-Type' => 'application/json'
], [
    'json' => ['key' => 'value'],
    'timeout' => 30
]);
```

## Testing

```bash
# OAuth tests (require environment variables)
export CLIENT_ID="your-client-id"
export CLIENT_SECRET="your-client-secret"
export REFRESH_TOKEN="your-refresh-token"

# Service Account tests (optional)
export SERVICE_ACCOUNT_JSON='{"type":"service_account","project_id":"your-project",...}'

# Run tests
composer tests
```

## Requirements

- PHP ^7.1
- guzzlehttp/guzzle ^6.0
- google/auth ^1.26

## License

MIT
