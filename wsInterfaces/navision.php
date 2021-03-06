<?php
// http://blogs.msdn.com/b/freddyk/archive/2010/01/19/connecting-to-nav-web-services-from-php.aspx

class NTLMStream
{
    private $path;
    private $mode;
    private $options;
    private $opened_path;
    private $buffer;
    private $pos;
    /**
     * Open the stream
     *
     * @param unknown_type $path
     * @param unknown_type $mode
     * @param unknown_type $options
     * @param unknown_type $opened_path
     * @return unknown
     */
    public function stream_open($path, $mode, $options, $opened_path) {
        $this->path = $path;
        $this->mode = $mode;
        $this->options = $options;
        $this->opened_path = $opened_path;
        $this->createBuffer($path);
        return true;
    }
    /**
     * Close the stream
     *
     */
    public function stream_close() {
        curl_close($this->ch);
    }
    /**
     * Read the stream
     *
     * @param int $count number of bytes to read
     * @return content from pos to count
     */
    public function stream_read($count) {
        if(strlen($this->buffer) == 0) {
            return false;
        }
        $read = substr($this->buffer,$this->pos, $count);
        $this->pos += $count;
        return $read;
    }
    /**
     * write the stream
     *
     * @param int $count number of bytes to read
     * @return content from pos to count
     */
    public function stream_write($data) {
        if(strlen($this->buffer) == 0) {
            return false;
        }
        return true;
    }
    /**
     *
     * @return true if eof else false
     */
    public function stream_eof() {
        return ($this->pos > strlen($this->buffer));
    }
    /**
     * @return int the position of the current read pointer
     */
    public function stream_tell() {
        return $this->pos;
    }
    /**
     * Flush stream data
     */
    public function stream_flush() {
        $this->buffer = null;
        $this->pos = null;
    }
    /**
     * Stat the file, return only the size of the buffer
     *
     * @return array stat information
     */
    public function stream_stat() {
        $this->createBuffer($this->path);
        $stat = array(
            'size' => strlen($this->buffer),
        );
        return $stat;
    }
    /**
     * Stat the url, return only the size of the buffer
     *
     * @return array stat information
     */
    public function url_stat($path, $flags) {
        $this->createBuffer($path);
        $stat = array(
            'size' => strlen($this->buffer),
        );
        return $stat;
    }
    /**
     * Create the buffer by requesting the url through cURL
     *
     * @param unknown_type $path
     */
    private function createBuffer($path) {
        if($this->buffer) {
            return;
        }
        $this->ch = curl_init($path);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($this->ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($this->ch, CURLOPT_USERPWD, USERPWD);
        $this->buffer = curl_exec($this->ch);
        $this->pos = 0;
    }
}

class NTLMSoapClient extends SoapClient {
    function __doRequest($request, $location, $action, $version, $one_way = 0) {
        $headers = array(
            'Method: POST',
            'Connection: Keep-Alive',
            'User-Agent: PHP-SOAP-CURL',
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "'.$action.'"',
        );
        $this->__last_request_headers = $headers;
        $ch = curl_init($location);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true );
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($ch, CURLOPT_USERPWD, USERPWD);
        $response = curl_exec($ch);
        return $response;
    }

    function __getLastRequestHeaders() {
        return implode("\n", $this->__last_request_headers)."\n";
    }
}

class Navision implements WsInterface
{
    protected $client;
    protected $api;

    public function __construct($wsConfig, $paypeApi)
    {
        stream_wrapper_unregister('http');
        stream_wrapper_register('http', 'NTLMStream') or die("Failed to register protocol");

        define('USERPWD', $wsConfig['user'].':'.$wsConfig['pwd']);

        $this->client = new NTLMSoapClient($wsConfig['location']);
        $this->api = $paypeApi;
    }

    public function getCustomers($lastSyncCustomerId)
    {
        $returnCustomers = array();

        $read = array(
            'filter' => array(
                array('Field'=>'Paype_ID', 'Criteria'=>'') // only ask ones with empty Paype ID - not synced yet
            ),
            'bookmarkKey' => null,
            'setSize' => 1000
        );

        if(!empty($lastSyncCustomerId))
        {
            $read['filter'][] = array('Field'=>'No', 'Criteria'=>$lastSyncCustomerId . '..'); // start from last synced ID
        }

        try
        {
            $customers = $this->client->ReadMultiple($read);
        }
        catch(Exception $e)
        {
            paypeLog('navision customersPush customer read fail: ' . $e->getMessage(), true);
        }

        if(empty($customers->ReadMultiple_Result->RegularCustomer))
        {
            $customers = array();
        }
        else
        {
            $customers = $customers->ReadMultiple_Result->RegularCustomer;
        }

        if(!is_array($customers))
        {
            $customers = array($customers);
        }

        foreach($customers as $customer)
        {
            if(!empty($customer->No))
            {
                $returnCustomers[] = array(
                    'first_name' => (!empty($customer->First_Name) ? $customer->First_Name : ''),
                    'last_name' => (!empty($customer->Last_Name) ? $customer->Last_Name : ''),
                    'email' => $customer->E_Mail,
                    'customer_id' => $customer->No
                );
            }
        }

        return $returnCustomers;
    }

    public function postCustomers($customers)
    {
        foreach($customers as $c)
        {
            $create = array();
            $create['RegularCustomer']['E_Mail'] = $c->email;
            $create['RegularCustomer']['First_Name'] = $c->first_name;
            $create['RegularCustomer']['Last_Name'] = $c->last_name;
            $create['RegularCustomer']['Paype_ID'] = $c->customer_id;

            try
            {
                $this->client->Create($create);
            }
            catch(Exception $e)
            {
                preg_match('/Same e-mail has already used in Regular Customer (PK[0-9]{6})/', $e->getMessage(), $navId);

                if(count($navId) == 2)
                {
                    // The error message contains RegularCustomer->No, call update on it
                    $navNo = $navId[1];
                    $this->updateCustomer($create, $navNo);
                }
                else
                {
                    paypeLog('navision customerPull create fail: ' . $e->getMessage(), true);
                }
            }
        }
    }

    protected function updateCustomer($customer, $navNo)
    {
        try
        {
            $read = array();
            $read['No'] = $navNo;

            // get customer key by their NAV No from create error message
            $navClient = $this->client->Read($read);

            // update customer sending in create object with key received from read call
            $customer['RegularCustomer']['Key'] = $navClient->RegularCustomer->Key;
            $update = $this->client->Update($customer);
            paypeLog('navision customerPull customer update: ' . json_encode($update));
        }
        catch(Exception $e)
        {
            paypeLog('navision customerPull update fail: ' . $e->getMessage(), true);
        }
    }
}