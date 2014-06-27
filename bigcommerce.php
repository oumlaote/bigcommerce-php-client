<?php

/**
 * Lightweight PHP client for the Bigcommerce API
 * Only compatible with Oauth2 Bigcommerce Apps
 */
class BigcommerceClient
{
    private $store_context;
    private $token;
    private $client_id;
    private $secret;
    private $last_response_headers = null;

    /**
     * Sets $store_context, $client_id, $secret and $token upon class instantiation
     * 
     * @param string $store_context Base path for the authorized store context, in the format: stores/{store_hash}
     * @param string $client_id     The Client ID for your app
     * @param string $secret        The Client Secret for your app
     * @param string $token         OAuth2 access token
     */
    public function __construct($store_context, $client_id, $secret, $token = '')
    {
        $this->store_context = $store_context;
        $this->token = $token;
        $this->client_id = $client_id;
        $this->secret = $secret;
    }

    /**
     * Retrieve OAuth2 token to access Bigcommerce API resources on behalf of the user
     * 
     * @param  string $code         Temporary access code
     * @param  string $scope        List of authorization scopes
     * @param  string $redirect_uri Must be identical to your registered Auth Callback URL
     * @return bool|string          The generated OAuth2 access token or FALSE upon failure
     */
    public function getAccessToken($code, $scope, $redirect_uri)
    {
        $url = "https://login.bigcommerce.com/oauth2/token";

        $payload = array(
            'grant_type' => 'authorization_code',
            'client_id' => $this->client_id,
            'client_secret' => $this->secret,
            'context' => $this->store_context,
            'code' => $code,
            'scope' => $scope,
            'redirect_uri' => $redirect_uri
        );

        $response = $this->curlHttpRequest('POST', $url, '', $payload, array('Content-Type: application/x-www-form-urlencoded'));
        $response = json_decode($response, true);
        if (isset($response['access_token'])) {
            $this->token = $response['access_token'];
            return $response['access_token'];
        }
        return false;
    }

    /**
     * Make an api call to the Bigcommerce API
     * 
     * @param  string $method HTTP method: GET|POST|PUT|DELETE
     * @param  string $path   Path to the ressources
     * @param  array  $params Query parameters
     * @return array          Response from the BigCommerce API
     */
    public function call($method, $path, $params = array())
    {
        $baseurl = "https://api.bigcommerce.com/{$this->store_context}/";

        $url = $baseurl.ltrim($path, '/');
        $query = in_array($method, array('GET','DELETE')) ? $params : array();
        $payload = in_array($method, array('POST','PUT')) ? stripslashes(json_encode($params)) : array();
        $request_headers = in_array($method, array('POST','PUT')) ? array("Content-Type: application/json; charset=utf-8", 'Expect:') : array();

        $request_headers[] = 'Accept: application/json';
        $request_headers[] = 'X-Auth-Client: ' . $this->client_id;
        $request_headers[] = 'X-Auth-Token: ' . $this->token;

        $response = $this->curlHttpRequest($method, $url, $query, $payload, $request_headers);
        $response = json_decode($response, true);

        if (isset($response['error']) or ($this->last_response_headers['http_status_code'] >= 400)) {
            if ($this->last_response_headers['http_status_code'] == 429) {
                // Handle api rate limits
                $waiting_time = $this->last_response_headers['X-Retry-After'];
                sleep($waiting_time);
                $this->call($method, $path, $params);
            } else {
                throw new BigCommerceApiException($method, $path, $params, $this->last_response_headers, $response);
            }
        }

        return $response;
    }

    private function curlHttpRequest($method, $url, $query = '', $payload = '', $request_headers = array())
    {
        $url = $this->curlAppendQuery($url, $query);
        $ch = curl_init($url);
        $this->curlSetopts($ch, $method, $payload, $request_headers);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            throw new BigCommerceCurlException($error, $errno);
        }
        list($message_headers, $message_body) = preg_split("/\r\n\r\n|\n\n|\r\r/", $response, 2);
        $this->last_response_headers = $this->curlParseHeaders($message_headers);

        return $message_body;
    }

    private function curlAppendQuery($url, $query)
    {
        if (empty($query)) {
            return $url;
        }
        if (is_array($query)) {
            return "$url?".http_build_query($query);
        } else {
            return "$url?$query";
        }
    }

    private function curlSetopts($ch, $method, $payload, $request_headers)
    {
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'oumlaote-bigcommerce-php-client');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($request_headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
        }

        if ($method != 'GET' && !empty($payload)) {
            if (is_array($payload)) {
                $payload = http_build_query($payload);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
    }

    private function curlParseHeaders($message_headers)
    {
        $header_lines = preg_split("/\r\n|\n|\r/", $message_headers);
        $headers = array();
        list(, $headers['http_status_code'], $headers['http_status_message']) = explode(' ', trim(array_shift($header_lines)), 3);
        foreach ($header_lines as $header_line) {
            list($name, $value) = explode(':', $header_line, 2);
            $name = strtolower($name);
            $headers[$name] = trim($value);
        }

        return $headers;
    }
}

class BigCommerceCurlException extends Exception
{
}

class BigCommerceApiException extends Exception
{
    protected $method;
    protected $path;
    protected $params;
    protected $response_headers;
    protected $response;

    public function __construct($method, $path, $params, $response_headers, $response)
    {
        $this->method = $method;
        $this->path = $path;
        $this->params = $params;
        $this->response_headers = $response_headers;
        $this->response = $response;

        parent::__construct($response_headers['http_status_message'], $response_headers['http_status_code']);
    }

    public function getMethod()
    {
        return $this->method;
    }
    public function getPath()
    {
        return $this->path;
    }
    public function getParams()
    {
        return $this->params;
    }
    public function getResponseHeaders()
    {
        return $this->response_headers;
    }
    public function getResponse()
    {
        return $this->response;
    }
}
