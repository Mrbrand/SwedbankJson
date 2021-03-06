<?php
/**
 * Wrapper för Swedbanks stänga API för mobilappar
 *
 * @package SwedbankJson
 * @author  Eric Wallmander
 *          Date: 2014-02-25
 *          Time: 21:36
 */

namespace SwedbankJson\Auth;

use Exception;
use Rhumsaa\Uuid\Uuid;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SessionCookieJar;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use SwedbankJson\Exception\ApiException;
use SwedbankJson\Exception\UserException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Class AbstractAuth
 * @package SwedbankJson\Auth
 */
abstract class AbstractAuth implements AuthInterface
{
    /**
     * Namn för auth session
     */
    const authSession = 'swedbankjson_auth';

    /**
     * Namn för cookieJar session
     */
    const cookieJarSession = 'swedbankjson_cookiejar';

    /**
     * @var string Adress till API-server
     */
    private $_baseUri = 'https://auth.api.swedbank.se/TDE_DAP_Portal_REST_WEB/api/';

    /**
     * @var string API-version
     */
    private $_apiVersion = 'v4';

    /**
     * @var string AppID. Ett ID som finns i Swedbanks appar.
     */
    protected $_appID;

    /**
     * @var string  User agent för appen
     */
    protected $_userAgent;

    /**
     * @var string Auth-nyckel mot Swedbank
     */
    protected $_authorization;

    /**
     * @var resource CURL-resurs
     */
    protected $_client;

    /**
     * @var string Profiltyp (företag eller privatperson)
     */
    protected $_profileType;

    /**
     * @var bool Debugging
     */
    protected $_debug;

    /**
     * @var object Guzzle Cookie Jar
     */
    protected $_cookieJar;

    /**
     * @var bool Om inlogningstypen behöver sparas mellan sessioner
     */
    protected $_persistentSession = false;

    /**
     * Ange AuthorizationKey
     *
     * Om ingen nyckel anges, genereras automaiskt en nyckel.
     *
     * @param string $key Sätta en egen AuthorizationKey
     */
    public function setAuthorizationKey($key = '')
    {
        $this->_authorization = (empty($key)) ? $this->genAuthorizationKey() : $key;
    }

    /**
     * Generera auth-nyckel för att kunna kommunicera med Swedbanks servrar
     *
     * @return string en slumpad auth-nyckel
     */
    public function genAuthorizationKey()
    {
        return base64_encode($this->_appID.':'.strtoupper(Uuid::uuid4()));
    }

    /**
     * Loggar ut från API:et
     */
    public function terminate()
    {
        $result = $this->putRequest('identification/logout');

        $this->cleanup();

        return $result;
    }

    /**
     * Uppresning av cookiejar och sessioner
     */
    private function cleanup()
    {
        // Cleanup
        $this->_cookieJar->clear();
        $this->_cookieJar->clearSessionCookies();
        unset($this->_client);

        if ($this->_persistentSession AND isset($_SESSION[self::authSession]))
            unset($_SESSION[self::authSession]);
    }

    /**
     * Lägger nödvändig appdata för att kommunicera med API:et. Bland annat appID för att generera nycklar.
     *
     * @param array $appdata
     *
     * @throws \Exception       Om rätt fält inte existerar eller är tomma
     */
    protected function setAppData($appdata)
    {
        if (!is_array($appdata) OR empty($appdata['appID']) OR empty($appdata['useragent']))
            throw new Exception('Fel inmatning av AppData!', 3);

        $this->_appID       = $appdata['appID'];
        $this->_userAgent   = $appdata['useragent'];
        $this->_profileType = (strpos($this->_userAgent, 'Corporate')) ? 'corporateProfiles' : 'privateProfile'; // För standardprofil
    }

    /**
     * Skickar GET-förfrågan
     *
     * @param string $apiRequest Typ av anrop mot API:et
     * @param array  $query      Fråga för GET-anrop
     *
     * @return object    JSON-avkodad information från API:et
     */
    public function getRequest($apiRequest, $query = [])
    {
        $request = $this->createRequest('get', $apiRequest);

        return $this->sendRequest($request, $query);
    }

    /**
     * Skickar POST-förfrågan
     *
     * @param string $apiRequest  Typ av anrop mot API:et
     * @param string $data Data som ska skickas i strängformat
     *
     * @return object    JSON-avkodad information från API:et
     */
    public function postRequest($apiRequest, $data = null)
    {
        $headers = [];
        if (!is_null($data))
            $headers['Content-Type'] = 'application/json; charset=UTF-8';

        if(is_array($data))
            $data = json_encode($data);

        $request = $this->createRequest('post', $apiRequest, $headers, $data);

        return $this->sendRequest($request);
    }

    /**
     * Skickar PUT-förfrågan
     *
     * @param string $apiRequest Typ av anrop mot API:et
     *
     * @return object    Avkodad JSON-data från API:et
     */
    public function putRequest($apiRequest)
    {
        $request = $this->createRequest('put', $apiRequest);

        return $this->sendRequest($request);
    }

    /**
     * Skickar DELETE-förfrågan
     *
     * @param string $apiRequest Typ av anrop mot API:et
     *
     * @return object    Avkodad JSON-data från API:et
     */
    public function deleteRequest($apiRequest)
    {
        $request = $this->createRequest('delete', $apiRequest);

        return $this->sendRequest($request);
    }

    /**
     * Retunterar inställd profil
     *
     * @return string
     */
    public function getProfileType()
    {
        return $this->_profileType;
    }

    /**
     * Guzzle klientobjekt
     *
     * @return resource
     */
    public function getClient()
    {
        return $this->_client;
    }

    /**
     * Gemensam hantering av HTTP requests
     *
     * @param string $method     Typ av HTTP förfrågan (ex. GET, POST)
     * @param string $apiRequest Requesttyp till API
     * @param array  $headers    Extra HTTP headers
     * @param string $body       Body innehåll
     *
     * @return Request
     */
    private function createRequest($method, $apiRequest, $headers = [], $body = null)
    {
        if (empty($this->_client))
        {
            $this->_cookieJar = ($this->_persistentSession) ? new SessionCookieJar(self::cookieJarSession, true) : new CookieJar();

            $stack = HandlerStack::create();

            if ($this->_debug)
            {
                if(!class_exists(Logger::class))
                    throw new UserException('Komponenter för logging saknas (Monolog) som krävs för debugging.', 1);

                $log   = new Logger('Log');

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
                'verify'          => false, // Skippar SSL-koll av Swedbanks API certifikat. Enbart för förebyggande syfte.
                'handler'         => $stack,
                //'debug'           => $this->_debug,
            ]);
        }

        return new Request($method, $apiRequest, $headers, $body);
    }

    /**
     * Skicka/verkställ HTTP request
     *
     * @param Request $request
     * @param array   $query   Fråga för GET-anrop
     * @param array   $options Guzzle konfiguration
     *
     * @return mixed    Json-objekt med data från API:et @see json_decode();
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
     *  Slår på sessions-data ska sparas mellan sessioner
     */
    protected function persistentSession()
    {
        if (session_status() !== PHP_SESSION_ACTIVE OR !isset($_SESSION))
            throw new Exception('Kan ej skapa session. Se till att session_start() är satt.',4);

        $this->_persistentSession = true;
    }

    /**
     * Sparar auth session
     */
    protected function saveSession()
    {
        $_SESSION[self::authSession] = serialize($this);
    }

    /**
     * För sparande av session
     *
     * @return array Lista på attribut som ska sparas
     */
    public function __sleep()
    {
        return ['_appID', '_userAgent', '_authorization', '_profileType', '_debug', '_persistentSession',];
    }

    /**
     * Generering av dsid
     * Slumpar 8 tecken som måste skickas med i varje anrop.
     *
     * @return string   8 slumpvalda tecken
     */
    private function dsid()
    {
        // Välj 8 tecken
        $dsid = substr(sha1(mt_rand()), rand(1, 30), 8);

        // Gör 4 tecken till versaler
        $dsid = substr($dsid, 0, 4).strtoupper(substr($dsid, 4, 4));

        return str_shuffle($dsid);
    }

    /**
     * Sätta en annan adress till API-server
     *
     * @param string $baseUri URI till API-server (Exlusive version)
     */
    protected function setBaseUri($baseUri)
    {
        $this->_baseUri = $baseUri;
    }
}