<?php

namespace ProcessMaker\Traits;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Mustache_Engine;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Arr;
use ProcessMaker\Exception\HttpResponseException;
use Psr\Http\Message\ResponseInterface;

trait MakeHttpRequests
{
    private $authTypes = [
        'BASIC' => 'basicAuthorization',
        'OAUTH2_BEARER' => 'bearerAuthorization',
        'OAUTH2_PASSWORD' => 'passwordAuthorization',
    ];

    /**
     * Verify certificate ssl
     *
     * @var bool
     */
    protected $verifySsl = true;

    private $mustache = null;

    private function getMustache() {
       if ($this->mustache === null)  {
           $this->mustache = app(Mustache_Engine::class);
       }
       return $this->mustache;
    }

    /**
     * Send a HTTP request based on the datasource, configuration
     * and the process request data.
     *
     * @param array $data
     * @param array $config
     *
     * @return array
     *
     * @throws GuzzleException
     * @throws HttpResponseException
     */
    public function request(array $data = [], array $config = [])
    {
        //$data is modified by the method
        $request = $this->prepareRequest($data, $config);

        try {
            return $this->response($this->call(...$request), $data, $config);
        } catch (ClientException $exception) {
            throw new HttpResponseException($exception->getResponse());
        }
    }

    /**
     * Prepares data for the http request replacing mustache with pm instance
     *
     * @param array $data, request data
     * @param array $config, datasource configuration
     *
     * @return array
     */
    private function prepareRequest(array &$data, array &$config)
    {
        $endpoint = $this->endpoints[$config['endpoint']];
        \Illuminate\Support\Facades\Log::Critical(__FILE__ . " Endpoint config = " . print_r($endpoint, true));
        $method = $this->getMustache()->render($endpoint['method'], $data);
        $url = $this->getMustache()->render($endpoint['url'], $data);

        // If exists a query string in the call, add it to the URL
        if (array_key_exists('queryString', $config)) {
            $separator = strpos($url, '?') ? '&' : '?';
            $url .= $separator . $config['queryString'];
        }

        //xxx verificar por qué está esto si en credential sólo se ve el bearer
        //$this->verifySsl = array_key_exists('verify_certificate', $this->credentials)
                            //? $this->credentials['verify_certificate']
                            //: true;
        $this->verifySsl = false;


        // Datasource works with json responses
        $headers = ['Accept' => 'application/json'];
        if (isset($endpoint['headers']) && is_array($endpoint['headers'])) {
            foreach ($endpoint['headers'] as $header) {
                $headers[$this->getMustache()->render($header['key'], $data)] = $this->getMustache()->render($header['value'], $data);
            }
        }

        if (isset($config['outboundConfig'])) {
            $mappedData = [];
            foreach ($config['outboundConfig'] as $map) {
                $mappedData[$map['property']] =  $map['value'];
            }

            if (empty($endpoint['body'])) {
                $endpoint['body'] = json_encode($mappedData);
                \Illuminate\Support\Facades\Log::Critical(__FILE__ . " Empty Body mappedData = " . print_r($mappedData, true));
            } else {
                \Illuminate\Support\Facades\Log::Critical(__FILE__ . " Body exists endpoint = " . print_r($endpoint, true));
                \Illuminate\Support\Facades\Log::Critical(__FILE__ . " Body exists data = " . print_r($data, true));
                foreach ($config['outboundConfig'] as $map) {
                    $data[$map['property']] = $this->getMustache()->render($map['value'], $data);
                }
                \Illuminate\Support\Facades\Log::Critical(__FILE__ . " Body exists data changed = " . print_r($data, true));
            }
        }

        $body = $this->getMustache()->render($endpoint['body'], $data);
        $bodyType = $this->getMustache()->render($endpoint['body_type'], $data);
        $request = [$method, $url, $headers, $body, $bodyType];
        $request = $this->addAuthorizationHeaders(...$request);
        \Illuminate\Support\Facades\Log::Critical(__FILE__ . " Body to send = " . print_r($body, true));
        \Illuminate\Support\Facades\Log::Critical(__FILE__ . " Body type = " . print_r($bodyType, true));
        \Illuminate\Support\Facades\Log::Critical(__FILE__ . " Method = " . print_r($bodyType, true));
        \Illuminate\Support\Facades\Log::Critical(__FILE__ . " headers = " . print_r($headers, true));
        \Illuminate\Support\Facades\Log::Critical(__FILE__ . " URL = " . print_r($url, true));

        return $request;
    }

    /**
     * Add authorization parameters
     *
     * @param array ...$config
     *
     * @return array
     */
    private function addAuthorizationHeaders(...$config)
    {
        if (isset($this->authTypes[$this->authtype])) {
            $callable = [$this, $this->authTypes[$this->authtype]];
            return call_user_func_array($callable, $config);
        }
        return $config;
    }

    /**
     * Add basic authorization to header
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string $body
     * @param $bodyType
     *
     * @return array
     */
    private function basicAuthorization($method, $url, $headers, $body, $bodyType)
    {
        if (isset($this->credentials) && is_array($this->credentials)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->credentials['username'] . ':' . $this->credentials['password']);
        }
        return [$method, $url, $headers, $body, $bodyType];
    }

    /**
     * Add bearer authorization to header
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string $body
     * @param $bodyType
     *
     * @return array
     */
    private function bearerAuthorization($method, $url, $headers, $body, $bodyType)
    {
        if (isset($this->credentials) && is_array($this->credentials)) {
            $headers['Authorization'] = 'Bearer ' . $this->credentials['token'];
        }
        return [$method, $url, $headers, $body, $bodyType];
    }

    /**
     * Get token with credentials
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string $body
     * @param $bodyType
     *
     * @return array
     */
    private function passwordAuthorization($method, $url, $headers, $body, $bodyType)
    {
        if (isset($this->credentials) && is_array($this->credentials)) {
            //todo enable mustache
            $config = [
                'username' => $this->credentials['username'],
                'password' => $this->credentials['password'],
                'grant_type' => 'password',
                'client_id' => $this->credentials['client_id'],
                'client_secret' => $this->credentials['client_secret'],
            ];

            $token = $this->response($this->call('POST', $this->credentials['url'], ['Accept' => 'application/json'], json_encode($config), 'form-data'), [], ['dataMapping' => []], new Mustache_Engine());
            $headers['Authorization'] = 'Bearer ' . $token['response']['access_token'];
        }
        return [$method, $url, $headers, $body, $bodyType];
    }

    /**
     * Prepare the response, using the mapping configuration
     *
     * @param Response $response
     * @param array $data
     * @param array $config
     * @param Mustache_Engine $mustache
     *
     * @return array
     * @throws HttpResponseException
     */
    private function response($response, array $data = [], array $config = [])
    {
        $status = $response->getStatusCode();
        switch (true) {
            case $status == 200:
                $content = json_decode($response->getBody()->getContents(), true);
                break;
            case $status > 200 && $status < 300:
                $content = [];
                break;
            default:
                throw new HttpResponseException($response);
        }
        $mapped = [];
        !is_array($content) ?: $merged = array_merge($data, $content);
        $mapped['status'] = $status;
        $mapped['response'] = $content;

        if (isset($config['dataMapping'])) {
            foreach ($config['dataMapping'] as $map) {
                //$value = $mustache->render($map['value'], $merged);
                $value = Arr::get($merged, $map['value'], '');
                Arr::set($mapped, $map['key'], $value);
            }
        }
        return $mapped;
    }

    /**
     * Call an HTTP request
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param $body
     * @param string $bodyType
     *
     * @return mixed|ResponseInterface
     *
     * @throws GuzzleException
     */
    private function call($method, $url, array $headers, $body, $bodyType)
    {
        $client = new Client(['verify' => $this->verifySsl]);
        $options = [];
        if ($bodyType === 'form-data') {
            $options['form_params'] = json_decode($body, true);
        }
        $request = new Request($method, $url, $headers, $body);
        return $client->send($request, $options);
    }
}
