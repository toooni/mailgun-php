<?PHP

namespace Mailgun\Connection;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;
use Http\Adapter\HttpAdapter;
use Http\Discovery\HttpAdapterDiscovery;
use Mailgun\Connection\Exceptions\GenericHTTPError;
use Mailgun\Connection\Exceptions\InvalidCredentials;
use Mailgun\Connection\Exceptions\MissingRequiredParameters;
use Mailgun\Connection\Exceptions\MissingEndpoint;
use Mailgun\Constants\Api;
use Mailgun\Constants\ExceptionMessages;
use Psr\Http\Message\ResponseInterface;

/**
 * This class is a wrapper for the Guzzle (HTTP Client Library).
 */
class RestClient
{
    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var HttpAdapter
     */
    protected $httpAdapter;

    /**
     * @var string
     */
    protected $apiEndpoint;

    /**
     * @param string      $apiKey
     * @param string      $apiHost
     * @param string      $apiVersion
     * @param bool        $ssl
     * @param HttpAdapter $adapter
     */
    public function __construct($apiKey, $apiHost, $apiVersion, $ssl, HttpAdapter $adapter = null)
    {
        $this->apiKey = $apiKey;
        $this->httpAdapter = $adapter;
        $this->apiEndpoint = $this->generateEndpoint($apiHost, $apiVersion, $ssl);
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array  $body
     * @param array  $files
     * @param array  $headers
     *
     * @return \stdClass
     *
     * @throws GenericHTTPError
     * @throws InvalidCredentials
     * @throws MissingEndpoint
     * @throws MissingRequiredParameters
     */
    protected function send($method, $uri, $body = null, $files = [], array $headers = [])
    {
        if ($this->httpAdapter === null) {
            $this->httpAdapter = HttpAdapterDiscovery::find();
        }

        $headers['User-Agent'] = Api::SDK_USER_AGENT.'/'.Api::SDK_VERSION;
        $headers['Authorization'] = 'Basic '.base64_encode(sprintf('%s:%s', Api::API_USER, $this->apiKey));

        if (!empty($files)) {
            $body = new MultipartStream($files);
            $headers['Content-Type'] = 'multipart/form-data; boundary='.$body->getBoundary();
        }

        $request = new Request($method, $this->apiEndpoint.$uri, $headers, $body);
        $response = $this->httpAdapter->sendRequest($request);

        return $this->responseHandler($response);
    }

    /**
     * @param string $endpointUrl
     * @param array  $postData
     * @param array  $files
     *
     * @return \stdClass
     *
     * @throws GenericHTTPError
     * @throws InvalidCredentials
     * @throws MissingEndpoint
     * @throws MissingRequiredParameters
     */
    public function post($endpointUrl, $postData = array(), $files = array())
    {
        $postFiles = [];

        $fields = ['message', 'attachment', 'inline'];
        foreach ($fields as $fieldName) {
            if (isset($files[$fieldName])) {
                if (is_array($files[$fieldName])) {
                    foreach ($files[$fieldName] as $file) {
                        $postFiles[] = $this->prepareFile($fieldName, $file);
                    }
                } else {
                    $postFiles[] = $this->prepareFile($fieldName, $files[$fieldName]);
                }
            }
        }

        $postDataMultipart = [];
        foreach ($postData as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subValue) {
                    $postDataMultipart[] = [
                        'name' => $key,
                        'contents' => $subValue,
                    ];
                }
            } else {
                $postDataMultipart[] = [
                    'name' => $key,
                    'contents' => $value,
                ];
            }
        }

        return $this->send('POST', $endpointUrl, [], array_merge($postDataMultipart, $postFiles));
    }

    /**
     * @param string $endpointUrl
     * @param array  $queryString
     *
     * @return \stdClass
     *
     * @throws GenericHTTPError
     * @throws InvalidCredentials
     * @throws MissingEndpoint
     * @throws MissingRequiredParameters
     */
    public function get($endpointUrl, $queryString = array())
    {
        return $this->send('GET', $endpointUrl.'?'.http_build_query($queryString));
    }

    /**
     * @param string $endpointUrl
     *
     * @return \stdClass
     *
     * @throws GenericHTTPError
     * @throws InvalidCredentials
     * @throws MissingEndpoint
     * @throws MissingRequiredParameters
     */
    public function delete($endpointUrl)
    {
        return $this->send('DELETE', $endpointUrl);
    }

    /**
     * @param string $endpointUrl
     * @param array  $putData
     *
     * @return \stdClass
     *
     * @throws GenericHTTPError
     * @throws InvalidCredentials
     * @throws MissingEndpoint
     * @throws MissingRequiredParameters
     */
    public function put($endpointUrl, $putData)
    {
        return $this->send('PUT', $endpointUrl, $putData);
    }

    /**
     * @param ResponseInterface $responseObj
     *
     * @return \stdClass
     *
     * @throws GenericHTTPError
     * @throws InvalidCredentials
     * @throws MissingEndpoint
     * @throws MissingRequiredParameters
     */
    public function responseHandler(ResponseInterface $responseObj)
    {
        $httpResponseCode = $responseObj->getStatusCode();
        if ($httpResponseCode === 200) {
            $data = (string) $responseObj->getBody();
            $jsonResponseData = json_decode($data, false);
            $result = new \stdClass();
            // return response data as json if possible, raw if not
            $result->http_response_body = $data && $jsonResponseData === null ? $data : $jsonResponseData;
        } elseif ($httpResponseCode == 400) {
            throw new MissingRequiredParameters(ExceptionMessages::EXCEPTION_MISSING_REQUIRED_PARAMETERS.$this->getResponseExceptionMessage($responseObj));
        } elseif ($httpResponseCode == 401) {
            throw new InvalidCredentials(ExceptionMessages::EXCEPTION_INVALID_CREDENTIALS);
        } elseif ($httpResponseCode == 404) {
            throw new MissingEndpoint(ExceptionMessages::EXCEPTION_MISSING_ENDPOINT.$this->getResponseExceptionMessage($responseObj));
        } else {
            throw new GenericHTTPError(ExceptionMessages::EXCEPTION_GENERIC_HTTP_ERROR, $httpResponseCode, $responseObj->getBody());
        }
        $result->http_response_code = $httpResponseCode;

        return $result;
    }

    /**
     * @param ResponseInterface $responseObj
     *
     * @return string
     */
    protected function getResponseExceptionMessage(ResponseInterface $responseObj)
    {
        $body = (string) $responseObj->getBody();
        $response = json_decode($body);
        if (json_last_error() == JSON_ERROR_NONE && isset($response->message)) {
            return ' '.$response->message;
        }
    }

    /**
     * @param string $apiEndpoint
     * @param string $apiVersion
     * @param bool   $ssl
     *
     * @return string
     */
    protected function generateEndpoint($apiEndpoint, $apiVersion, $ssl)
    {
        if (!$ssl) {
            return 'http://'.$apiEndpoint.'/'.$apiVersion.'/';
        } else {
            return 'https://'.$apiEndpoint.'/'.$apiVersion.'/';
        }
    }

    /**
     * Prepare a file for the postBody.
     *
     * @param string       $fieldName
     * @param string|array $filePath
     */
    protected function prepareFile($fieldName, $filePath)
    {
        $filename = null;
        // Backward compatibility code
        if (is_array($filePath)) {
            $filename = $filePath['remoteName'];
            $filePath = $filePath['filePath'];
        }

        // Remove leading @ symbol
        if (strpos($filePath, '@') === 0) {
            $filePath = substr($filePath, 1);
        }

        return [
            'name' => $fieldName,
            'contents' => fopen($filePath, 'r'),
            'filename' => $filename,
        ];
    }
}
