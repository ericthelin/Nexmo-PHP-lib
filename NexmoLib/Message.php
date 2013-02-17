<?php
Namespace NexmoLib;

/**
 * Class Message handles the methods and properties of sending an SMS message.
 * 
 * Usage: $var = new NexmoLib\Message($accountKey, $accountPassword);
 * Methods:
 *     sendText($to, $from, $message, $unicode = null)
 *     sendBinary($to, $from, $body, $udh)
 *     pushWap($to, $from, $title, $url, $validity = 172800000)
 *     displayOverview($nexmoResponse=null)
 *     
 *     inboundText($data=null)
 *     reply($text)
 *     
 *
 */

class Message
{

    // Nexmo account credentials
    private $nxKey = '';
    private $nxSecret = '';

    /**
     * @var string Nexmo server URI
     *
     * We're sticking with the JSON interface here since json
     * parsing is built into PHP and requires no extensions.
     * This will also keep any debugging to a minimum due to
     * not worrying about which parser is being used.
     */
    public $nxUri = 'https://rest.nexmo.com/sms/json';

    
    /**
     * @var array The most recent parsed Nexmo response.
     */
    private $nexmoResponse = '';
    

    /**
     * @var bool If recieved an inbound message
     */
    public $inboundMessage = false;


    // Current message
    public $to = '';
    public $from = '';
    public $text = '';
    public $network = '';
    public $messageId = '';

    // A few options
    public $sslVerify = false; // Verify Nexmo SSL before sending any message


    public function __construct($apiKey, $apiSecret)
    {
        $this->nxKey = $apiKey;
        $this->nxSecret = $apiSecret;
    }



    /**
     * Prepare new text message.
     *
     * If $unicode is not provided we will try to detect the
     * message type. Otherwise set to TRUE if you require
     * unicode characters.
     */
    public function sendText($to, $from, $message, $unicode = null)
    {
    
        // Making sure strings are UTF-8 encoded
        if (!is_numeric($from) && !mb_check_encoding($from, 'UTF-8')) {
            trigger_error('$from needs to be a valid UTF-8 encoded string');
            return false;
        }

        if (!mb_check_encoding($message, 'UTF-8')) {
            trigger_error('$message needs to be a valid UTF-8 encoded string');
            return false;
        }
        
        if ($unicode === null) {
            $containsUnicode = max(array_map('ord', str_split($message))) > 127;
        } else {
            $containsUnicode = (bool)$unicode;
        }
        
        // Make sure $from is valid
        $from = $this->validateOriginator($from);

        // URL Encode
        $from = urlencode($from);
        $message = urlencode($message);
        
        // Send away!
        $post = array(
            'from' => $from,
            'to' => $to,
            'text' => $message,
            'type' => $containsUnicode ? 'unicode' : 'text'
        );
        return $this->sendRequest($post);
        
    }
    
    
    /**
     * Prepare new WAP message.
     */
    public function sendBinary($to, $from, $body, $udh)
    {
    
        //Binary messages must be hex encoded
        $body = bin2hex($body);
        $udh = bin2hex($udh);

        // Make sure $from is valid
        $from = $this->validateOriginator($from);

        // Send away!
        $post = array(
            'from' => $from,
            'to' => $to,
            'type' => 'binary',
            'body' => $body,
            'udh' => $udh
        );
        return $this->sendRequest($post);
        
    }
    
    
    /**
     * Prepare new binary message.
     */
    public function pushWap($to, $from, $title, $url, $validity = 172800000)
    {
        // Making sure $title and $url are UTF-8 encoded
        if (!mb_check_encoding($title, 'UTF-8') || !mb_check_encoding($url, 'UTF-8')) {
            trigger_error('$title and $udh need to be valid UTF-8 encoded strings');
            return false;
        }
        
        // Make sure $from is valid
        $from = $this->validateOriginator($from);

        // Send away!
        $post = array(
            'from' => $from,
            'to' => $to,
            'type' => 'wappush',
            'url' => $url,
            'title' => $title,
            'validity' => $validity
        );
        return $this->sendRequest($post);
        
    }
    
    
    /**
     * Prepare and send a new message.
     */
    private function sendRequest($data)
    {
        // Build the post data
        $data = array_merge($data, array('username' => $this->nxKey, 'password' => $this->nxSecret));
        $post = '';
        foreach ($data as $k => $v) {
            $post .= "&$k=$v";
        }

        // If available, use CURL
        if (function_exists('curl_version')) {

            $toNexmo = curl_init($this->nxUri);
            curl_setopt($toNexmo, CURLOPT_POST, true);
            curl_setopt($toNexmo, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($toNexmo, CURLOPT_POSTFIELDS, $post);

            if (!$this->ssl_verify) {
                curl_setopt($toNexmo, CURLOPT_SSL_VERIFYPEER, false);
            }

            $fromNexmo = curl_exec($toNexmo);
            curl_close($toNexmo);

        } elseif (ini_get('allow_url_fopen')) {
            // No CURL available so try the awesome file_get_contents

            $opts = array(
                'http' => array(
                    'method'  => 'POST',
                    'header'  => 'Content-type: application/x-www-form-urlencoded',
                    'content' => $post
                )
            );
            $context = stream_context_create($opts);
            $fromNexmo = file_get_contents($this->nxUri, false, $context);

        } else {
            // No way of sending a HTTP post :(
            return false;
        }

        
        return $this->nexmoParse($fromNexmo);
     
    }
    
    
    /**
     * Recursively normalise any key names in an object, removing unwanted characters
     */
    private function normaliseKeys($obj)
    {
        // Determine is working with a class or araay
        if ($obj instanceof stdClass) {
            $newObj = new stdClass();
            $isObj = true;
        } else {
            $newObj = array();
            $isObj = false;
        }


        foreach ($obj as $key => $val) {
            // If we come across another class/array, normalise it
            if ($val instanceof stdClass || is_array($val)) {
                $val = $this->normaliseKeys($val);
            }
            
            // Replace any unwanted characters in they key name
            if ($isObj) {
                $newObj->{str_replace('-', '', $key)} = $val;
            } else {
                $newObj[str_replace('-', '', $key)] = $val;
            }
        }

        return $newObj;
    }


    /**
     * Parse server response.
     */
    private function nexmoParse($fromNexmo)
    {
        $response = json_decode($fromNexmo);

        // Copy the response data into an object, removing any '-' characters from the key
        $responseObj = $this->normaliseKeys($response);

        if ($responseObj) {
            $this->nexmoResponse = $responseObj;

            // Find the total cost of this message
            $responseObj->cost = $totalCost = 0;
            if (is_array($responseObj->messages)) {
                foreach ($responseObj->messages as $msg) {
                    $totalCost = $totalCost + (float)$msg->messageprice;
                }

                $responseObj->cost = $totalCost;
            }

            return $responseObj;

        } else {
            // A malformed response
            $this->nexmoResponse = array();
            return false;
        }
        
    }


    /**
     * Validate an originator string
     *
     * If the originator ('from' field) is invalid, some networks may reject the network
     * whilst stinging you with the financial cost! While this cannot correct them, it
     * will try its best to correctly format them.
     */
    private function validateOriginator($inp)
    {
        // Remove any invalid characters
        $ret = preg_replace('/[^a-zA-Z0-9]/', '', (string)$inp);

        if (preg_match('/[a-zA-Z]/', $inp)) {

            // Alphanumeric format so make sure it's < 11 chars
            $ret = substr($ret, 0, 11);

        } else {

            // Numerical, remove any prepending '00'
            if (substr($ret, 0, 2) == '00') {
                $ret = substr($ret, 2);
                $ret = substr($ret, 0, 15);
            }
        }
        
        return (string)$ret;
    }



    /**
     * Display a brief overview of a sent message.
     * Useful for debugging and quick-start purposes.
     */
    public function displayOverview($nexmoResponse = null)
    {
        $info = (!$nexmoResponse) ? $this->nexmoResponse : $nexmoResponse;

        if (!$nexmoResponse) {
            return 'Cannot display an overview of this response';
        }

        // How many messages were sent?
        if ($info->messagecount > 1) {
            $status = 'Your message was sent in ' . $info->messagecount . ' parts';
        } elseif ($info->messagecount == 1) {
            $status = 'Your message was sent';
        } else {
            return 'There was an error sending your message';
        }
        
        // Build an array of each message status and ID
        if (!is_array($info->messages)) {
            $info->messages = array();
        }
        $messageStatus = array();
        foreach ($info->messages as $message) {
            $tmp = array('id'=>'', 'status'=>0);

            if ($message->status != 0) {
                $tmp['status'] = $message->errortext;
            } else {
                $tmp['status'] = 'OK';
                $tmp['id'] = $message->messageid;
            }

            $messageStatus[] = $tmp;
        }
        
        
        // Build the output
        if (isset($_SERVER['HTTP_HOST'])) {
            // HTML output
            $ret = '<table><tr><td colspan="2">'.$status.'</td></tr>';
            $ret .= '<tr><th>Status</th><th>Message ID</th></tr>';
            foreach ($messageStatus as $mstat) {
                $ret .= '<tr><td>'.$mstat['status'].'</td><td>'.$mstat['id'].'</td></tr>';
            }
            $ret .= '</table>';

        } else {

            // CLI output
            $ret = "$status:\n";

            // Get the sizes for the table
            $outSizes = array('id'=>strlen('Message ID'), 'status'=>strlen('Status'));
            foreach ($messageStatus as $mstat) {
                if ($outSizes['id'] < strlen($mstat['id'])) {
                    $outSizes['id'] = strlen($mstat['id']);
                }
                if ($outSizes['status'] < strlen($mstat['status'])) {
                    $outSizes['status'] = strlen($mstat['status']);
                }
            }

            $ret .= '  '.str_pad('Status', $outSizes['status'], ' ').'   ';
            $ret .= str_pad('Message ID', $outSizes['id'], ' ')."\n";
            foreach ($messageStatus as $mstat) {
                $ret .= '  '.str_pad($mstat['status'], $outSizes['status'], ' ').'   ';
                $ret .= str_pad($mstat['id'], $outSizes['id'], ' ')."\n";
            }
        }

        return $ret;
    }

    /**
     * Inbound text methods
     */
    

    /**
     * Check for any inbound messages, using $_GET by default.
     *
     * This will set the current message to the inbound
     * message allowing for a future reply() call.
     */
    public function inboundText($data = null)
    {
        if (!$data) {
            $data = $_GET;
        }

        if (!isset($data['text'], $data['msisdn'], $data['to'])) {
            return false;
        }

        // Get the relevant data
        $this->to = $data['to'];
        $this->from = $data['msisdn'];
        $this->text = $data['text'];
        $this->network = (isset($data['network-code'])) ? $data['network-code'] : '';
        $this->messageId = $data['messageId'];

        // Flag that we have an inbound message
        $this->inboundMessage = true;

        return true;
    }


    /**
     * Reply the current message if one is set.
     */
    public function reply($message)
    {
        // Make sure we actually have a text to reply to
        if (!$this->inboundMessage) {
            return false;
        }

        return $this->sendText($this->from, $this->to, $message);
    }
}
