<?php

class UEP
{

    private $_client_id = null;
    private $_client_secret = null;
    private $_redirect_url = null;
    private $_api_auth_base = null;
    private $_access_token = null;
    private $_uid = null;

    public function __construct($client_id = null, $client_secret = null, $redirect_url = null)
    {
        $this->_api_auth_base = Configure::read("UEP.url") . "/OAuth/";

        $this->_client_id = $client_id;
        $this->_client_secret = $client_secret;
        $this->_redirect_url = $redirect_url;
    }

    public function accessTokenSet($access_token)
    {
        $this->_access_token = $access_token;
    }

    public function clientIdSet($client_id)
    {
        $this->_client_id = $client_id;
    }

    public function uidSet($uid)
    {
        $this->_uid = $uid;
    }

    public function clientSecretSet($client_secret)
    {
        $this->_client_secret = $client_secret;
    }

    public function redirectUrlSet($redirectUrl)
    {
        $this->_redirect_url = $redirectUrl;
    }

    private function check()
    {
        if ($this->_client_id === null)
            throw new Exception("No Client ID set");

        if ($this->_client_secret === null)
            throw new Exception("No Client Secret set");
    }

    public function loginURL($uid = null, $login = "0")
    {
        $this->check();

        if ($uid === null)
            throw new Exception("No uid specified");

        $base_url = $this->_api_auth_base . "authorize?uid=" . urlencode($uid) . "&login=" . $login . "&response_type=code&client_id=" . $this->_client_id . "&redirect_url=" . urlencode($this->_redirect_url);

        return $base_url;
    }

    public function accessTokenGet($code)
    {
        $this->check();

        $base_url = $this->_api_auth_base . "token?grant_type=authorization_code&code=" . $code . "&client_id=" . $this->_client_id . "&client_secret=" . $this->_client_secret . "";

        //debug($base_url);
        //exit();

        $response = file_get_contents($base_url);

        //debug($response);
        //exit();

        $response = json_decode($response, true);


        if (isset($response["access_token"]))
        {
            //## User is authed, need to save access token for future api calls
            $this->_access_token = $response["access_token"];

            return $response["access_token"];
        }
        else
        {
            Throw new CakeException("Could not get access code");
        }
    }

    public function userinfo()
    {
        $this->check();

        $base_url = $this->_api_auth_base . "userinfo?access_token=" . $this->_access_token;

        $response = file_get_contents($base_url);

        $response = json_decode($response, true);

        return $response;
    }

    public function call($method, $path, $params = array(), $debug = false)
    {

        //echo $method . ":" . $path . "\n";

        if ($this->_uid === null)
        {
            throw new Exception("UID is required");
        }

        $temp = json_encode($params);

        unset($params);

        $params = array();

        $params["input"] = $temp;

        $params["access_token"] = $this->_access_token;
        $params["uid"] = $this->_uid;
   
        if ($debug)
            debug($params);

        $baseurl = Configure::read("UEP.url") . "/1.0/";

        $url = $baseurl . ltrim($path, '/');

        if ($debug)
            debug($url);

        $query = in_array($method, array('GET', 'DELETE')) ? $params : array();
        //$payload = in_array($method, array('POST', 'PUT')) ? stripslashes(json_encode($params)) : array();
        //$payload = in_array($method, array('POST', 'PUT')) ? json_encode($params) : array();
        $payload = in_array($method, array('POST', 'PUT')) ? $params : array();
        //$request_headers = in_array($method, array('POST', 'PUT')) ? array("Content-Type: application/json; charset=utf-8", 'Expect:') : array();
        $request_headers = in_array($method, array('POST', 'PUT')) ? array() : array();

        if ($debug)
            debug($this->_access_token);

        //debug($query);
        //debug($url);
        //debug($payload);
        //$request_headers = array();
        // add auth headers
        $request_headers[] = 'X-UEP-Access-Token: ' . $this->_access_token;
        $request_headers[] = 'X-UEP-Client-ID: ' . $this->_client_id;

        if ($method == 'PUT')
            $request_headers[] = 'Authorization: Bearer ' . $this->_access_token;

        $response = $this->curlHttpApiRequest($method, $url, $query, $payload, $request_headers);

        if ($debug)
            debug($response);

        $response = json_decode($response, true);

        //debug($response);

        if (isset($response['code']))
        {
            if ($response['code'] != 200)
            {
                throw new UEPApiException($response['name'], $response['code']);
            }
        }


        //debug($this->last_response_headers['http_status_code']);
        //debug($response);

        if ($this->last_response_headers['http_status_code'] >= 400)
        {
            $error = null;
            if (isset($response['error']) && isset($response['error_description']))
            {
                //debug("A");
                throw new UEPApiException($response['error_description'], $this->last_response_headers['http_status_code']);
            }

            if (isset($response['name']))
            {
                throw new UEPApiException($response['name'], $this->last_response_headers['http_status_code']);
            }
        }


        if (isset($response['errors']) or ($this->last_response_headers['http_status_code'] >= 400))
            throw new UEPApiException($this->last_response_headers['http_status_code'], $response);

        return (is_array($response) and (count($response) > 0)) ? array_shift($response) : $response;
        //return $response;
    }

    private function curlHttpApiRequest($method, $url, $query = '', $payload = '', $request_headers = array())
    {
        $url = $this->curlAppendQuery($url, $query);


        $ch = curl_init($url);

        //debug($url);

        $request_headers[] = "Expect:";

        $this->curlSetopts($ch, $method, $payload, $request_headers);
        $response = curl_exec($ch);

        //debug($response);

        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        //debug($error);

        if ($errno)
            throw new UEPCurlException($error, $errno);
        list($message_headers, $message_body) = preg_split("/\r\n\r\n|\n\n|\r\r/", $response, 2);
        $this->last_response_headers = $this->curlParseHeaders($message_headers);

        return $message_body;
    }

    private function curlAppendQuery($url, $query)
    {
        if (empty($query))
            return $url;
        if (is_array($query))
            return "$url?" . http_build_query($query);
        else
            return "$url?$query";
    }

    private function curlSetopts($ch, $method, $payload, $request_headers)
    {
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'HAC');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 130);
        curl_setopt($ch, CURLOPT_TIMEOUT, 130);
        //curl_setopt($ch, CURLOPT_POST, TRUE);
        //curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json; charset=utf-8","Accept:application/json, text/javascript, */*; q=0.01"));
        //debug($method);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        //debug($request_headers);

        if (!empty($request_headers))
            curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);

        if ($method != 'GET' && !empty($payload))
        {
            //debug($payload);

            if (is_array($payload))
                $payload = http_build_query($payload);

            //debug($payload);
            //$payload = "jaco=1";

            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        //return $ch;
    }

    private function curlParseHeaders($message_headers)
    {
        $header_lines = preg_split("/\r\n|\n|\r/", $message_headers);
        $headers = array();
        list(, $headers['http_status_code'], $headers['http_status_message']) = explode(' ', trim(array_shift($header_lines)), 3);
        foreach ($header_lines as $header_line)
        {
            list($name, $value) = explode(':', $header_line, 2);
            $name = strtolower($name);
            $headers[$name] = trim($value);
        }

        return $headers;
    }

}

class UEPCurlException extends Exception
{
    
}

class UEPApiException extends Exception
{
    function __construct($message, $code)
    {
        parent::__construct($message, $code);
    }
}

?>
