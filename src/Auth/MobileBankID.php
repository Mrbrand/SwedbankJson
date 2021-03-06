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

use SwedbankJson\AppData;
use Exception;

/**
 * Class MobileBankID
 * @package SwedbankJson\Auth
 */
class MobileBankID extends AbstractAuth
{
    /**
     * @var int Inlogging personnummer
     */
    private $_username;

    /**
     * @var bool Om BankId har tidigare bilvit verifierad eller ej
     */
    protected $_verified = false;

    /**
     * MobileBankID constructor.
     *
     * @param string|array $bankApp  ID för vilken bank som ska anropas, eller array med appdata uppgifter.
     * @param int          $username Personnummer för inlogging till internetbanken
     * @param bool         $debug    Sätt true för att göra felsökning, annars false eller null
     */
    public function __construct($bankApp, $username, $debug = false)
    {
        $this->setAppData((!is_array($bankApp)) ? AppData::bankAppId($bankApp) : $bankApp);
        $this->_username = $username;
        $this->_debug    = (bool)$debug;
        $this->setAuthorizationKey();
        $this->persistentSession();
    }

    /**
     * Inleder inlogging med Mobilt BankID
     *
     * Ser till att verifieringsförfrågan skickas till användarens mobila BankID
     *
     * @return bool
     * @throws Exception
     */
    public function initAuth()
    {
        if ($this->_verified)
            return true;

        $output = $this->postRequest(
            'identification/bankid/mobile',
            [
                'useEasyLogin'        => false,
                'generateEasyLoginId' => false,
                'userId'              => $this->_username,
            ]);

        if ($output->status != 'USER_SIGN')
            throw new Exception('Kan inte koppla bankID.', 10);

        $this->saveSession();

        return true;
    }

    /**
     * Verifierings kontroll
     *
     * @return bool
     * @throws Exception
     */
    public function verify()
    {
        if ($this->_verified)
            return true;

        $output = $this->getRequest('identification/bankid/mobile/verify');

        if (empty($output->status))
            throw new Exception('BankID är inte verifierad.', 11);

        // Om status är "COMPLETE", är det lyckad inlogging
        $this->_verified = ($output->status == 'COMPLETE');

        $this->saveSession();

        return $this->_verified;
    }

    /**
     * Inlogging
     *
     * Verifiering måste vara genomförd för att kunna genomföra restireande steg i inloggingsprocessen
     *
     * @return bool         True om inloggingen lyckades
     * @throws Exception    Fel vid inloggen
     */
    public function login()
    {
        if (!$this->_verified)
            throw new Exception('BankID är inte verifierad.', 12);

        return true;
    }

    /**
     * För sparande av session
     *
     * Modifierad för att inkludera $_verified
     *
     * @return array Lista på attribut som ska sparas
     */
    public function __sleep()
    {
        $sleepAttr   = parent::__sleep();
        $sleepAttr[] = '_verified';
        return $sleepAttr;
    }
}
