<?php
namespace AtaneNL\SignRequest;

use anlutro\cURL\cURL;

// TODO move requests to individual classes

class Client {

    const API_BASEURL = "https://[SUBDOMAIN]signrequest.com/api/v1";

    public static $defaultLanguage = 'nl';

    /* @var $curl \anlutro\cURL\cURL */
    private $curl;
    private $token;
    private $subdomain; // the subdomain

    public function __construct($token, $subdomain = null) {
        $this->token = $token;
        $this->subdomain = $subdomain;
        $this->curl = new cURL();
    }

    /**
     * Send a document to SignRequest.
     * @param string $file The absolute path to a file.
     * @param string $identifier
     * @param string $callbackUrl
     * @return CreateDocumentResponse
     */
    public function createDocument($file, $identifier, $callbackUrl = null) {
        $file = curl_file_create($file);
        $response = $this->newRequest("documents")
                ->setHeader("Content-Type", "multipart/form-data")
                ->setData([
                    'file'=>$file,
                    'external_id'=>$identifier,
                    'events_callback_url'=>$callbackUrl
                    ])
                ->send();
        if ($this->hasErrors($response)) {
            throw new Exceptions\SendSignRequestException($response);
        }
        return new CreateDocumentResponse($response);
    }

    /**
     * Send a sign request for a created document.
     * @param uuid $documentId
     * @param string $sender Senders e-mail address
     * @param array $recipients
     * @param string $message
     * @return uuid The document id
     */
    public function sendSignRequest($documentId, $sender, $recipients, $message = null) {
        foreach ( $recipients as &$r ) {
            if (!array_key_exists('language', $r)) {
                $r['language'] = self::$defaultLanguage;
            }
        }
        $response = $this->newRequest("signrequests")
                ->setHeader("Content-Type", "application/json")
                ->setData(json_encode([
                    "document"=>self::API_BASEURL . "/documents/" . $documentId . "/",
                    "from_email"=>$sender,
                    "message"=>$message,
                    "signers"=>$recipients,
                    "disable_text"=>true,
                    "disable_attachments"=>true,
                    "disable_date"=>true
                    ]))
                ->send();
        if ($this->hasErrors($response)) {
            throw new Exceptions\SendSignRequestException($response);
        }
        $responseObj = json_decode($response->body);
        return $responseObj->uuid;
    }

    /**
     * Send a reminder to all recipients who have not signed yet.
     * @param uuid $signRequestId
     * @return \stdClass response
     * @throws Exceptions\RemoteException
     */
    public function sendSignRequestReminder($signRequestId) {
        $response = $this->newRequest("signrequests/{$signRequestId}/resend_signrequest_email", "post")
                ->setHeader("Content-Type", "application/json")
                ->send();
        if ($this->hasErrors($response)) {
            throw new Exceptions\RemoteException($response);
        }
        $responseObj = json_decode($response->body);
        return $responseObj;
    }

    /**
     * Gets the current status for a sign request.
     * @param uuid $signRequestId
     * @return \stdClass response
     * @throws Exceptions\RemoteException
     */
    public function getSignRequestStatus($signRequestId) {
        $response = $this->newRequest("signrequests/{$signRequestId}", "get")->send();
        if ($this->hasErrors($response)) {
            throw new Exceptions\RemoteException($response);
        }
        $responseObj = json_decode($response->body);
        return $responseObj;
    }

    /**
     * Get a file.
     * @param uuid $documentId
     * @return \stdClass response
     * @throws Exceptions\RemoteException
     */
    public function getDocument($documentId) {
        $response = $this->newRequest("documents/{$documentId}", "get")->send();
        if ($this->hasErrors($response)) {
            throw new Exceptions\RemoteException($response);
        }
        $responseObj = json_decode($response->body);
        return $responseObj;
    }

    /**
     * Create a new team.
     * The client should be initialized *without* a subdomain for this method to function properly!!!
     * @param string $name
     * @param slug $subdomain
     * @param string $callbackUrl
     * @throws Exceptions\RemoteException
     */
    public function createTeam($name, $subdomain, $callbackUrl = null) {
        if ($this->subdomain !== null) {
            throw new Exceptions\LocalException("This request cannot be sent to a subdomain. Initialize the client without a subdomain.");
        }
        $response = $this->newRequest("teams")
                ->setHeader("Content-Type", "application/json")
                ->setData(json_encode([
                    "name"=>$name,
                    "subdomain"=>$subdomain
                    ]))
                ->send();

        if ($this->hasErrors($response)) {
            throw new Exceptions\RemoteException("Unable to create team $name: ".$response);
        }
        $responseObj = json_decode($response->body);
        return $responseObj->subdomain;
    }

    /**
     * Setup a base request object.
     * @param string $action
     * @param string $method post,put,get,delete,option
     * @return \anlutro\cURL\Request
     */
    private function newRequest($action, $method = 'post') {
        $baseRequest = $this->curl->newRawRequest($method, $this->getApiUrl() . "/" . $action . "/")
            ->setHeader("Authorization", "Token " . $this->token);
        return $baseRequest;
    }

    /**
     * Set the API url based on the subdomain.
     * @return string API url
     */
    private function getApiUrl() {
        return preg_replace('/\[SUBDOMAIN\]/', ltrim($this->subdomain .".", "."), self::API_BASEURL);
    }

    /**
     * Check for error in status headers.
     * @param type $response
     */
    private function hasErrors($response) {
        return !preg_match('/^20\d$/', $response->statusCode);
    }

}