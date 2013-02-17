<?php
Namespace NexmoLib;

/**
 * Class Account handles interaction with your Nexmo account
 *
 * Usage: $var = new NexmoLib\Account($accountKey, $accountSecret);
 * Methods:
 *     balance()
 *     smsPricing($countryCode)
 *     getCountryDialingCode($countryCode)
 *     numbersList()
 *     numbersSearch($countryCode, $pattern)
 *     numbersBuy($countryCode, $msisdn)
 *     numbersCancel($countryCode, $msisdn)
 *
 */

class Account
{
    private $nxKey = '';
    private $nxSecret = '';
    
    public $restBaseUrl = 'https://rest.nexmo.com/';
    private $restCommands = array(
        'getBalance' => array('method' => 'GET', 'url' => '/account/get-balance/{k}/{s}'),
        'getPricing' => array('method' => 'GET', 'url' => '/account/get-pricing/outbound/{k}/{s}/{countryCode}'),
        'getOwnNumbers' => array('method' => 'GET', 'url' => '/account/numbers/{k}/{s}'),
        'searchNumbers' => array(
            'method' => 'GET',
            'url' => '/number/search/{k}/{s}/{countryCode}?pattern={pattern}'
        ),
        'buyNumber' => array('method' => 'POST', 'url' => '/number/buy/{k}/{s}/{countryCode}/{msisdn}'),
        'cancelNumber' => array('method' => 'POST', 'url' => '/number/cancel/{k}/{s}/{countryCode}/{msisdn}')
    );
    
    
    private $cache = array();
    
    /**
     * @param $nxKey Your Nexmo account key
     * @param $nxSecret Your Nexmo secret
     */
    public function __construct($apiKey, $apiSecret)
    {
        $this->nxKey = $apiKey;
        $this->nxSecret = $apiSecret;
    }
    
    
    /**
     * Return your account balance in Euros
     * @return float|bool
     */
    public function balance()
    {
        if (!isset($this->cache['balance'])) {
            $tmp = $this->apiCall('getBalance');
            if (!$tmp['data']) {
                return false;
            }

            $this->cache['balance'] = $tmp['data']['value'];
        }
        
        return (float)$this->cache['balance'];
    }
    
    
    /**
     * Find out the price to send a message to a country
     * @param $countryCode Country code to return the SMS price for
     * @return float|bool
     */
    public function smsPricing($countryCode)
    {
        $countryCode = strtoupper($countryCode);
        
        if (!isset($this->cache['countryCodes'])) {
            $this->cache['countryCodes'] = array();
        }
        
        if (!isset($this->cache['countryCodes'][$countryCode])) {
            $tmp = $this->apiCall('getPricing', array('countryCode'=>$countryCode));
            if (!$tmp['data']) {
                return false;
            }
            
            $this->cache['countryCodes'][$countryCode] = $tmp['data'];
        }
        
        return (float)$this->cache['countryCodes'][$countryCode]['mt'];
    }
    
    
    /**
     * Return a countries international dialing code
     * @param $countryCode Country code to return the dialing code for
     * @return string|bool
     */
    public function getCountryDialingCode($countryCode)
    {
        $countryCode = strtoupper($countryCode);
        
        if (!isset($this->cache['countryCodes'])) {
            $this->cache['countryCodes'] = array();
        }
        
        if (!isset($this->cache['countryCodes'][$countryCode])) {
            $tmp = $this->apiCall('getPricing', array('countryCode'=>$countryCode));
            if (!$tmp['data']) {
                return false;
            }
            
            $this->cache['countryCodes'][$countryCode] = $tmp['data'];
        }
        
        return (string)$this->cache['countryCodes'][$countryCode]['prefix'];
    }
    
    
    /**
     * Get an array of all purchased numbers for your account
     * @return array|bool
     */
    public function numbersList()
    {
        if (!isset($this->cache['ownNumbers'])) {
            $tmp = $this->apiCall('getOwnNumbers');
            if (!$tmp['data']) {
                return false;
            }
            
            $this->cache['getOwnNumbers'] = $tmp['data'];
        }
        
        if (!$this->cache['getOwnNumbers']['numbers']) {
            return array();
        }
        
        return $this->cache['getOwnNumbers']['numbers'];
    }
    
    
    /**
     * Search available numbers to purchase for your account
     * @param $countryCode Country code to search available numbers in
     * @param $pattern Number pattern to search for
     * @return bool
     */
    public function numbersSearch($countryCode, $pattern)
    {
        $countryCode = strtoupper($countryCode);
        
        $tmp = $this->apiCall('searchNumbers', array('countryCode'=>$countryCode, 'pattern'=>$pattern));
        if (!$tmp['data'] || !isset($tmp['data']['numbers'])) {
            return false;
        }
        return $tmp['data']['numbers'];
    }
    
    
    /**
     * Purchase an available number to your account
     * @param $countryCode Country code for your desired number
     * @param $msisdn Full number which you wish to purchase
     * @return bool
     */
    public function numbersBuy($countryCode, $msisdn)
    {
        $countryCode = strtoupper($countryCode);
        
        $tmp = $this->apiCall('buyNumber', array('countryCode'=>$countryCode, 'msisdn'=>$msisdn));
        return ($tmp['http_code'] === 200);
    }
    
    
    /**
     * Cancel an existing number on your account
     * @param $countryCode Country code for which the number is for
     * @param $msisdn The number to cancel
     * @return bool
     */
    public function numbersCancel($countryCode, $msisdn)
    {
        $countryCode = strtoupper($countryCode);
        
        $tmp = $this->apiCall('cancelNumber', array('countryCode'=>$countryCode, 'msisdn'=>$msisdn));
        return ($tmp['http_code'] === 200);
    }
    
    
    /**
     * Run a REST command on Nexmo SMS services
     * @param $command
     * @param array $data
     * @return array|bool
     */
    private function apiCall($command, $data = array())
    {
        if (!isset($this->restCommands[$command])) {
            return false;
        }
        
        $cmd = $this->restCommands[$command];
        
        $url = $cmd['url'];
        $url = str_replace(array('{k}', '{s}'), array($this->nxKey, $this->nxSecret), $url);
        
        $parsedData = array();
        foreach ($data as $k => $v) {
            $parsedData['{'.$k.'}'] = $v;
        }
        $url = str_replace(array_keys($parsedData), array_values($parsedData), $url);
        
        $url = trim($this->restBaseUrl, '/') . $url;
        $postData = '';
        
        // If available, use CURL
        if (function_exists('curl_version')) {
            
            $toNexmo = curl_init($url);
            curl_setopt($toNexmo, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($toNexmo, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($toNexmo, CURLOPT_HTTPHEADER, array('Accept: application/json'));
            
            if ($cmd['method'] == 'POST') {
                curl_setopt($toNexmo, CURLOPT_POST, true);
                curl_setopt($toNexmo, CURLOPT_POSTFIELDS, $postData);
            }
            
            $fromNexmo = curl_exec($toNexmo);
            $curlInfo = curl_getinfo($toNexmo);
            $httpResponseCode = $curlInfo['http_code'];
            curl_close($toNexmo);
            
        } elseif (ini_get('allow_url_fopen')) {
            // No CURL available so try the awesome file_get_contents
            
            $opts = array(
                'http' => array(
                    'method'  => 'GET',
                    'header'  => 'Accept: application/json'
                )
            );
            
            if ($cmd['method'] == 'POST') {
                $opts['http']['method'] = 'POST';
                $opts['http']['header'] .= "\r\nContent-type: application/x-www-form-urlencoded";
                $opts['http']['content'] = $postData;
            }
            
            $context = stream_context_create($opts);
            $fromNexmo = file_get_contents($url, false, $context);
            
            // et the response code
            preg_match('/HTTP\/[^ ]+ ([0-9]+)/i', $httpResponseHeader[0], $m);
            $httpResponseCode = $m[1];
            
        } else {
            // No way of sending a HTTP post :(
            return false;
        }
        
        $data = json_decode($fromNexmo, true);
        return array(
            'data' => $data,
            'http_code' => (int)$httpResponseCode
        );
        
    }
}
