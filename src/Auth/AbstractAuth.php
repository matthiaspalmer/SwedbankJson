<?php
namespace SwedbankJson\Auth;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SessionCookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;
use SwedbankJson\Exception\ApiException;
use SwedbankJson\Exception\UserException;

/**
 * Class AbstractAuth
 * @package SwedbankJson\Auth
 */
abstract class AbstractAuth implements AuthInterface
{
    /** Auth session name */
    const authSession = 'swedbankjson_auth';

    /** Cookie jar session name */
    const cookieJarSession = 'swedbankjson_cookiejar';

    /** @var string URI to API server */
    private $_baseUri = 'https://auth.api.swedbank.se/TDE_DAP_Portal_REST_WEB/api/';

    /** @var string API version */
    private $_apiVersion = 'v4';

    /** @var string Bank type AppID */
    protected $_appID;

    /** @var string User agent for API client */
    protected $_userAgent;

    /** @var string Generated  required auth key */
    protected $_authorization;

    /** @var object HTTP client lib */
    protected $_client;

    /** @var string Profile type (individual or cooperate) */
    protected $_profileType;

    /** @var bool Debug */
    protected $_debug;

    /** @var object Cookie jar */
    protected $_cookieJar;

    /** @var bool If the authentication method needs to save the session */
    protected $_persistentSession = false;

    /**
     * Setting authorization key
     *
     * If no key is provided, it will be generated
     *
     * @param string $key Key generated by genAuthorizationKey()
     */
    public function setAuthorizationKey($key = '')
    {
        $this->_authorization = (empty($key)) ? $this->genAuthorizationKey() : $key;
    }

    /**
     * Generates authorization key
     *
     * Swedbank app API require a authorization key to able to connect to their API.
     *
     * @return string Randomly generated authorization key
     */
    public function genAuthorizationKey()
    {
        return base64_encode($this->_appID.':'.strtoupper(Uuid::uuid4()));
    }

    /**
     * Sign out
     *
     * Terminate the session, see cleanup();
     *
     * @return object
     */
    public function terminate()
    {
        $result = $this->putRequest('identification/logout');

        $this->cleanup();

        return $result;
    }

    /**
     * Cleans up cookie jar, session data and http client.
     */
    private function cleanup()
    {
        $this->_cookieJar->clear();
        $this->_cookieJar->clearSessionCookies();
        unset($this->_client);

        if ($this->_persistentSession AND isset($_SESSION[self::authSession]))
            unset($_SESSION[self::authSession]);
    }

    /**
     * Setting App data
     *
     * @param array $appdata From AppData class.
     *
     * @throws Exception
     */
    protected function setAppData($appdata)
    {
        if (!is_array($appdata) OR empty($appdata['appID']) OR empty($appdata['useragent']))
            throw new Exception('Not valid app data.', 3);

        $this->_appID       = $appdata['appID'];
        $this->_userAgent   = $appdata['useragent'];
        $this->_profileType = (strpos($this->_userAgent, 'Corporate')) ? 'corporateProfiles' : 'privateProfile'; // Default profile
    }

    /**
     * Sending GET request
     *
     * @param string $apiRequest API method call
     * @param array  $query      GET query
     *
     * @return object   JSON decoded response from the API
     */
    public function getRequest($apiRequest, $query = [])
    {
        $request = $this->createRequest('get', $apiRequest);

        return $this->sendRequest($request, $query);
    }

    /**
     * Sending POST request
     *
     * @param string $apiRequest API method call
     * @param string $data       POST data to be sent
     *
     * @return object JSON decoded response from the API
     */
    public function postRequest($apiRequest, $data = null)
    {
        $headers = [];
        if (!is_null($data))
            $headers['Content-Type'] = 'application/json; charset=UTF-8';

        if (is_array($data))
            $data = json_encode($data);

        $request = $this->createRequest('post', $apiRequest, $headers, $data);

        return $this->sendRequest($request);
    }

    /**
     * Sending PUT request
     *
     * @param string $apiRequest API method call
     *
     * @return object JSON decoded response from the API
     */
    public function putRequest($apiRequest)
    {
        $request = $this->createRequest('put', $apiRequest);

        return $this->sendRequest($request);
    }

    /**
     * Sending DELETE request
     *
     * @param string $apiRequest API method call
     *
     * @return object JSON decoded response from the API
     */
    public function deleteRequest($apiRequest)
    {
        $request = $this->createRequest('delete', $apiRequest);

        return $this->sendRequest($request);
    }

    /**
     * Profile type
     *
     * @return string
     */
    public function getProfileType()
    {
        return $this->_profileType;
    }

    /**
     * HTTP client object
     *
     * @return object
     */
    public function getClient()
    {
        return $this->_client;
    }

    /**
     * Prepare HTTP requests
     *
     * @param string $method     Request type (eg. GET)
     * @param string $apiRequest API method call
     * @param array  $headers    Extra headers
     * @param string $body       Body content
     *
     * @return Request
     */
    private function createRequest($method, $apiRequest, $headers = [], $body = null)
    {
        // Initiate HTTP client if missing
        if (empty($this->_client))
        {
            $this->_cookieJar = ($this->_persistentSession) ? new SessionCookieJar(self::cookieJarSession, true) : new CookieJar();

            $stack = HandlerStack::create();

            if ($this->_debug)
            {
                if (!class_exists(Logger::class))
                    throw new UserException('Components for logging is missing (Monolog).', 1);

                $log = new Logger('Log');

                $stream = new StreamHandler('swedbankjson.log');
                $stream->setFormatter(new LineFormatter("[%datetime%]\n\t%message%\n", null, true));
                $log->pushHandler($stream);

                $stack->push(Middleware::log($log, new MessageFormatter("{req_headers}\n\n{req_body}\n\t{res_headers}\n\n{res_body}\n")));
            }

            $this->_client = new Client([
                'base_uri'        => $this->_baseUri.$this->_apiVersion.'/',
                'headers'         => [
                    'Authorization'    => $this->_authorization,
                    'Accept'           => '*/*',
                    'Accept-Language'  => 'sv-se',
                    'Accept-Encoding'  => 'gzip, deflate',
                    'Connection'       => 'keep-alive',
                    'Proxy-Connection' => 'keep-alive',
                    'User-Agent'       => $this->_userAgent,
                ],
                'allow_redirects' => ['max' => 10, 'referer' => true],
                'verify'          => false, // Skipping TLS certificate verification of Swedbank API. Only for preventive purposes.
                'handler'         => $stack,
                //'debug'           => $this->_debug,
            ]);
        }

        return new Request($method, $apiRequest, $headers, $body);
    }

    /**
     * Sending HTTP request
     *
     * @param Request $request
     * @param array   $query   HTTP query for GET requests
     * @param array   $options HTTP client configurations
     *
     * @return object JSON decoded response from the API
     */
    private function sendRequest(Request $request, array $query = [], array $options = [])
    {
        $dsid = $this->dsid();

        $this->_cookieJar->setCookie(new SetCookie([
            'Name'   => 'dsid',
            'Value'  => $dsid,
            'Path'   => '/',
            'Domain' => 0,
        ]));

        $options['cookies'] = $this->_cookieJar;
        $options['query']   = array_merge($query, ['dsid' => $dsid]);

        try
        {
            $response = $this->_client->send($request, $options);
        } catch (ServerException $e)
        {
            $this->cleanup();
            throw new ApiException($e->getResponse());
        } catch (ClientException $e)
        {
            $this->terminate();
            throw new ApiException($e->getResponse());
        }

        return json_decode($response->getBody());
    }

    /**
     * Save session data between sessions
     *
     * @throws Exception
     */
    protected function persistentSession()
    {
        if (session_status() !== PHP_SESSION_ACTIVE OR !isset($_SESSION))
            throw new Exception('Can not create session. Session_start() has not been set.', 4);

        $this->_persistentSession = true;
    }

    /**
     * Save session
     */
    protected function saveSession()
    {
        $_SESSION[self::authSession] = serialize($this);
    }

    /**
     * For persistent sessions
     *
     * @return array List of attributes to be saved
     */
    public function __sleep()
    {
        return ['_appID', '_userAgent', '_authorization', '_profileType', '_debug', '_persistentSession',];
    }

    /**
     * Generate dsid
     *
     * Randomly generated 8 characters made for each request to the API.
     * Likely to be used for braking cache.
     *
     * @return string 8 random generated characters
     */
    private function dsid()
    {
        // Generate 8 characters
        $dsid = substr(sha1(mt_rand()), rand(1, 30), 8);

        // 4 characters to uppercase
        $dsid = substr($dsid, 0, 4).strtoupper(substr($dsid, 4, 4));

        return str_shuffle($dsid);
    }

    /**
     * Overwrite base URI to API server
     *
     * @param string $baseUri URI to API server. Exclude version
     */
    protected function setBaseUri($baseUri)
    {
        $this->_baseUri = $baseUri;
    }
}