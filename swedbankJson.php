<?php
/**
 * Wraper för Swedbanks stänga mobilapps API
 * Används först och främst till att hämtra transaktioner från ett konto med peronlig kod.
 *
 * @package SwedbankJSON
 * @author  Eric Wallmander
 *          Date: 2012-01-01
 *          Time: 21:36
 */

// Används för att genererea auth-nyckel
require_once 'uuid.php';

/**
 * Class SwedbankJson

 */
class SwedbankJson
{
    /**
     * EnhetsID som skapas av Swedbank
     */
    const unitID = '8Qg7Jd03WGLaDTZj';

    /**
     * Bas-url för API-anrop
     */
    const baseUri = 'https://auth.api.swedbank.se/TDE_DAP_Portal_REST_WEB/api/v1/';

    /**
     * Useragent som
     */
    const useragent = 'SwedbankMOBPrivateIOS/3.2.0_(iOS;_6.1.3)_Apple/iPhone5,2';

    /**
     * @var resource CURL-resurs
     */
    private $_ch;

    /**
     * @var string Sökväg för cookiejarl
     */
    private $_ckfile;

    /**
     * @var string Auth-nyckel mot Swedbank
     */
    private $_authorization;

    /**
     * @var array Gemensamma headrs i alla anrop mot API:et
     */
    private $_commonHttpHeaders;

    /**
     * @var int Inlogging personnummer
     */
    private $_username;

    /**
     * @var string Personlig kod.
     */
    private $_password;

    /**
     * @var mixed bankID från API:et som sparas för anrop
     */
    private $_bankID;

    /**
     * @var int ID från API:et som sparas för anrop
     */
    private $_id;

    /**
     * Grundläggande upgifter
     *
     * @param int    $username      Personnummer för inlogging till internetbanken
     * @param string $password      Personlig kod för inlogging till internetbanken
     * @param string $authorization Authteristeringnyckel som genereras av getAuthorizationKey(), om inget anges genreras en nyckel. @see self::getAuthorizationKey();
     * @param string $ckfile        Sökväg till mapp där cookiejar kan temporärt sparas
     */
    public function __construct($username, $password, $authorization = '', $ckfile = './')
    {
        $this->_username      = $username;
        $this->_password      = $password;
        $this->_authorization = (!empty($authorization)) ? $authorization : $this->getAuthorizationKey();
        $this->_ckfile        = tempnam($ckfile, "CURLCOOKIE");
    }

    /**
     * Generera auth-nyckel för att kunna komunicera med Swedbanks servrar
     *
     * @return string en slumpad auth-nyckel
     */
    public function getAuthorizationKey()
    {
        return base64_encode($this::unitID . ':' . strtoupper(UUID::v4()));
    }

    /**
     * Utlogging från API:et.
     * @see self::terminate()
     */
    public function __destruct()
    {
        $this->terminate();
    }

    /**
     * Loggar ut från API:et
     */
    public function terminate()
    {
        return $this->putRequest('identification/logout');
    }


    /**
     * Listar alla bankkonton som finns tillgängliga
     *
     * @return object       Lista på alla konton
     * @throws Exception    Något med API-anropet gör att kontorna inte listas
     */
    public function accountList()
    {
        if (empty($ch))
            $this->swedbankInit();

        $output = $this->getRequest('engagement/overview');

        if (!isset($output->transactionAccounts))
            throw new Exception('Bankkonton kunde inte listas.', 6);

        return $output;
    }
    
    
    /**
     * Listar investeringssparande som finns tillgängliga
     *
     * @return object       Lista på alla Investeringssparkonton
     * @throws Exception    Något med API-anropet gör att kontorna inte listas
     */
    public function portfolioList()
    {
        if (empty($ch))
            $this->swedbankInit();

        $output = $this->getRequest('portfolio/holdings');

        if (!isset($output->savingsAccounts))
            throw new Exception('Investeringssparkonton kunde inte listas.', 8);

        return $output;
    }

    /**
     * Lathund för uppoppling
     * Gör både uppkopping och inlogging mot Swedbank.
     * @see self::connect() @see self::login()
     */
    private function swedbankInit()
    {
        $this->login();
    }

    /**
     * Inlogging
     * Loggar in med personummer och personig kod för att få reda på bankID och den tillfälliga profil-id:t
     *
     * @return bool         True om inloggingen lyckades
     * @throws Exception    Fel vid inloggen
     */
    private function login()
    {
        $data_string = json_encode(array( 'userId' => $this->_username, 'password' => $this->_password ));
        $output      = $this->postRequest('identification/personalcode', $data_string);

        if (!isset($output->links->next->uri))
            throw new Exception('Inlogging misslyckades. Kontrollera användarnman, lösenord och authorization-nyckel.', 4);

        // Hämtar ID-nummer
        $profile = $this->profile();

        $this->_bankID = $profile->banks[0]->bankId;
        $this->_id     = $profile->banks[0]->privateProfile->id;

        $this->menus();

        return true;
    }

    /**
     * Visar kontodetaljer och transaktioner för konto
     *
     * @param $accoutID string  Unika och slumpade konto-id från Swedbank API
     * @param $getAll   bool    True om alla transaktioner ska visas, annars falls
     *
     * @return object           Avkodad JSON med kontinformationn
     * @throws Exception        AccoutID inte stämmer
     */
    public function accountDetails($accoutID, $getAll = false)
    {
        $query = array();
        if ($getAll)
            $query = array( 'transactionsPerPage' => 10000, 'page' => 1 );

        $output = $this->getRequest('engagement/transactions/' . $accoutID, null, $query);

        if (!isset($output->transactions))
            throw new Exception('AccountID stämmer inte', 7);

        return $output;
    }

    /**
     * Profilinfomation
     * Få tillgång till BankID och ID.
     *
     * @return array        JSON-avkodad data om profilen
     * @throws Exception    Fel med  anrop mot API:et
     */
    private function profile()
    {
        $output = $this->getRequest('profile/');

        if (!isset($output->banks[0]->bankId))
            throw new Exception('Något med fel med profilsidan.', 5);

        return $output;
    }

    /**
     * Innehåller information om menyer och annan information för appen
     *
     * @return object Avkodad information från JSON om möjlga anrop och menystrutur för app
     */
    private function menus()
    {
        return $this->postRequest('profile/private/' . $this->_bankID);
    }


    /**
     * Skickar GET-förfrågan
     *
     * @param string $requestType   Typ av anrop mot API:et
     * @param string $baseURL       Bas-url, om inget anges körs self::baseUri @see self::baseUri
     * @param array  $query         Fråga för GET-anrop
     * @param bool   $debug         True om debug-data ska retuneras
     *
     * @return object    JSON-avkodad information från API:et eller debugdata om $debug är satt till true.
     */
    private function getRequest($requestType, $baseURL = self::baseUri, $query = array(), $debug = false)
    {
        if (empty($this->_ch))
            $this->initCurl();

        if (is_null($baseURL))
            $baseURL = self::baseUri;

        $dsid = $this->dsid();

        $httpQuery = http_build_query(array_merge($query, array( 'dsid' => $dsid )));

        curl_setopt($this->_ch, CURLOPT_URL, $baseURL . $requestType . '?' . $httpQuery);
        curl_setopt($this->_ch, CURLOPT_HTTPGET, true);
        curl_setopt($this->_ch, CURLOPT_HTTPHEADER, $this->requestHeaders($dsid));
        if ($debug)
        {
            curl_setopt($this->_ch, CURLOPT_HEADER, 1);
            curl_setopt($this->_ch, CURLINFO_HEADER_OUT, 1);
        }

        $data = curl_exec($this->_ch);

        if ($debug)
        {
            $headers = curl_getinfo($this->_ch, CURLINFO_HEADER_OUT);

            return array( 'request' => $headers, 'response' => $data );
        }
        else
            return json_decode($data);
    }

    /**
     * Skickar POST-förfrågan
     *
     * @param string $requestType   Typ av anrop mot API:et
     * @param string $data_string   Data som ska skickas i strängformat och enligt http_build_query() @see http_build_query()
     * @param bool   $debug         True om debug-data ska retuneras
     *
     * @return object    JSON-avkodad information från API:et eller debugdata om $debug är satt till true.
     */
    private function postRequest($requestType, $data_string = '', $debug = false)
    {
        if (empty($this->_ch))
            $this->initCurl();

        $dsid = $this->dsid();
        curl_setopt($this->_ch, CURLOPT_URL, self::baseUri . $requestType . '?dsid=' . $dsid);
        curl_setopt($this->_ch, CURLOPT_POST, true);
        if (!empty($data_string))
        {
            curl_setopt($this->_ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($this->_ch, CURLOPT_HTTPHEADER, $this->requestHeaders($dsid, $data_string));
        }
        else
            curl_setopt($this->_ch, CURLOPT_HTTPHEADER, $this->requestHeaders($dsid));

        if ($debug)
        {
            curl_setopt($this->_ch, CURLOPT_HEADER, 1);
            curl_setopt($this->_ch, CURLINFO_HEADER_OUT, 1);
        }

        $data = curl_exec($this->_ch);

        if ($debug)
        {
            $headers = curl_getinfo($this->_ch, CURLINFO_HEADER_OUT);

            return array( 'request' => $headers, 'response' => $data );
        }
        else
            return json_decode($data);
    }


    /**
     * Skickar PUT-förfrågan
     *
     * @param string $requestType     Typ av anrop mot API:et
     * @param bool   $debug           Sätt till true för att via debug-information
     *
     * @return object    Avkodad JSON-data från API:et eller debuginformation om $debug är satt till true.
     */
    private function putRequest($requestType, $debug = false)
    {
        if (empty($this->_ch))
            $this->initCurl();

        $dsid = $this->dsid();
        curl_setopt($this->_ch, CURLOPT_URL, self::baseUri . $requestType . '?dsid=' . $dsid);
        curl_setopt($this->_ch, CURLOPT_PUT, true);
        curl_setopt($this->_ch, CURLOPT_HTTPHEADER, $this->requestHeaders($dsid));
        if ($debug)
        {
            curl_setopt($this->_ch, CURLOPT_HEADER, 1);
            curl_setopt($this->_ch, CURLINFO_HEADER_OUT, 1);
        }

        $data = curl_exec($this->_ch);

        if ($debug)
        {
            $headers = curl_getinfo($this->_ch, CURLINFO_HEADER_OUT);

            return array( 'request' => $headers, 'response' => $data );
        }
        else
            return json_decode($data);
    }

    /**
     * Iniserar CURL och gemensamma HTTP-headers
     */
    private function initCurl()
    {
        $this->_commonHttpHeaders = array(
            'Authorization: ' . $this->_authorization,
            'Accept: */*',
            'Accept-Language: sv-se',
            'Accept-Encoding: gzip, deflate',
            'Connection: keep-alive',
            'Proxy-Connection: keep-alive'
        );

        $this->_ch = curl_init();
        curl_setopt($this->_ch, CURLOPT_COOKIEJAR, $this->_ckfile);
        curl_setopt($this->_ch, CURLOPT_COOKIEFILE, $this->_ckfile);
        curl_setopt($this->_ch, CURLOPT_USERAGENT, self::useragent);
        curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->_ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($this->_ch, CURLOPT_FOLLOWLOCATION, true);
    }

    /**
     * Generering av dsid
     * Slumpar 8 tecken som måste skickas med i varje anrop. Antagligen för att API:et vill förhindra en cache skapas
     *
     * @return string   8 slumpvalda tecken
     */
    private function dsid()
    {
        $dsid = substr(sha1(mt_rand()), rand(1, 30), 8);
        $dsid = substr($dsid, 0, 4) . strtoupper(substr($dsid, 4, 4));

        return str_shuffle($dsid);
    }

    /**
     * Gemensama HTTP-headers som anapassas efter typ av anriop
     *
     * @param string $dsid          8 slumpvalda tecken @see self::dsid();
     * @param string $data_string   Data som ska skickas. Används för att räkna ut längen på texten.
     *
     * @return array    HTTP:haders för att användas i CURL-anrop.
     */
    private function requestHeaders($dsid, $data_string = '')
    {
        curl_setopt($this->_ch, CURLOPT_COOKIE, "dsid=$dsid");
        $requestHeader = $this->_commonHttpHeaders;

        if (!empty($data_string))
        {
            $requestHeader[] = 'Content-Type: application/json; charset=UTF-8';
            $requestHeader[] = 'Content-Length: ' . strlen($data_string);
        }

        return $requestHeader;
    }
}