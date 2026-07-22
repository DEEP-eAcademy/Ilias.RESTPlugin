<?php
/**
 * Minimal curl-based HTTP client for exercising the REST plugin's endpoints
 * from a plain PHP CLI script, without any external dependencies.
 */
class RestApiTestClient
{
    /** @var string */
    private $baseUrl;

    /** @var bool */
    private $verifyTls;

    /** @var string|null */
    private $accessToken;

    public function __construct($baseUrl, $verifyTls = true)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->verifyTls = $verifyTls;
    }

    /**
     * Authenticate via the Resource Owner Password Credentials grant
     * (POST /v2/oauth2/token, grant_type=password) -- the grant type the
     * IACUBUS / ILIAS Pegasus app itself uses to log a user in.
     *
     * @return array Decoded token response (access_token, refresh_token, expires_in, ...)
     * @throws RuntimeException on transport error or non-2xx response
     */
    public function authenticateWithPassword($apiKey, $apiSecret, $username, $password)
    {
        $result = $this->request('POST', '/v2/oauth2/token', array(
            'grant_type' => 'password',
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'username' => $username,
            'password' => $password,
        ), null);

        if ($result['status'] !== 200 || !isset($result['json']['access_token'])) {
            throw new \RuntimeException(
                'Failed to obtain access token (HTTP ' . $result['status'] . '): ' . $result['body']
            );
        }

        $this->accessToken = $result['json']['access_token'];
        return $result['json'];
    }

    /**
     * Perform a GET request against a plugin route (eg. '/v3/ilias-app/theme').
     * Set $useToken=false to test unauthenticated access.
     */
    public function get($route, array $query = array(), $useToken = true)
    {
        return $this->request('GET', $route, $query, $useToken ? $this->accessToken : null);
    }

    /**
     * Perform a POST request against a plugin route.
     * Set $useToken=false to test unauthenticated access.
     */
    public function post($route, array $body = array(), $useToken = true)
    {
        return $this->request('POST', $route, $body, $useToken ? $this->accessToken : null);
    }

    /**
     * @return array{status:int, headers:array, body:string, json:array|null}
     */
    private function request($method, $route, array $params, $bearerToken)
    {
        $url = $this->baseUrl . $route;
        $headers = array('Accept: application/json');

        $ch = curl_init();
        if ($method === 'GET') {
            if (!empty($params)) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
            }
        } else {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        if ($bearerToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyTls);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyTls ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Request to $url failed: $error");
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($body, true);
        return array(
            'status' => $status,
            'body' => $body,
            'json' => (json_last_error() === JSON_ERROR_NONE) ? $json : null,
        );
    }
}
