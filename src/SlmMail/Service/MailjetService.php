<?php
/**
 * Copyright (c) 2012-2013 Jurian Sluiman.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the names of the copyright holders nor the names of the
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @author      Jurian Sluiman <jurian@juriansluiman.nl>
 * @copyright   2012-2013 Jurian Sluiman.
 * @license     http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @link        http://juriansluiman.nl
 */

namespace SlmMail\Service;

use SlmMail\Mail\Message\Mailjet as MailjetEmailMessage;
use Zend\Http\Request as HttpRequest;
use Zend\Http\Response as HttpResponse;
use Zend\Mail\Address;
use Zend\Mail\Message;
use Zend\Mime\Part;
use SimpleXMLElement;
use DateTime;
use \Mailjet\Client as MailjetClient;

class MailjetService extends AbstractMailService
{
    /**
     * API endpoint
     */
    const API_ENDPOINT = 'https://api.elasticemail.com';

    /**
     * Mailjet private API key
     *
     * @var string
     */
    protected $privateApiKey;

    /**
     * MailJet public API key
     *
     * @var string
     */
    protected $publicApiKey;

    /**
     * Mailjet PHP Client
     * @var
     */
    protected $mailjetClient;

    /**
     * @param string $username
     * @param string $apiKey
     */
    public function __construct($publicApiKey, $privateApiKey)
    {
        $this->publicApiKey = (string) $publicApiKey;
        $this->privateApiKey   = (string) $privateApiKey;
        $this->mailjetClient = new MailjetClient($this->publicApiKey, $this->privateApiKey);
    }

    /**
     * ------------------------------------------------------------------------------------------
     * MESSAGES
     * ------------------------------------------------------------------------------------------
     */

    /**
     * {@inheritDoc}
     * @link   http://elasticemail.com/api-documentation/send
     * @return string The transaction id of the email
     */
    public function send(Message $message)
    {
        if ($message instanceof MailjetMessage && $message->getTemplate()) {
            return $this->sendTemplate($message);
        }

        $to = array();
        foreach ($message->getTo() as $address) {
            $to[] = $address->toString();
        }

        $params = array(
            "method" => "POST",
            "MJ-TemplateLanguage" => true,
            'Html-part' => $message-getBody(),
            'Recipients' => $to
        );

        return $this->mailjetClient->post(Resources::$Email, ['body' => $params]);
    }

    /**
     * {@inheritDoc}
     * @link   http://elasticemail.com/api-documentation/send
     * @return string The transaction id of the email
     */
    protected function sendTemplate(Message $message)
    {
        // Throws exception if no template
        if (!$message->getTemplate()) {
            throw new Exception("You're trying to send a template email, but none is set. Use setTemplate() method to set a template.");
        }

        $to = array();
        foreach ($message->getTo() as $address) {
            $to[] = $address->toString();
        }

        $params = array(
            "method" => "POST",
            "MJ-TemplateLanguage" => true,
            'MJ-TemplateID' => $message->getTemplate(),
            "Vars" => array($message->getGlobalVariables()),
            'Recipients' => $to
        );

        return $this->mailjetClient->post(Resources::$Email, ['body' => $params]);
    }

    /**
     * Get status about an email (for instance, if it was sent correctly, if it was opened...)
     *
     * @link   http://elasticemail.com/api-documentation/status
     * @param  string $id
     * @throws Exception\RuntimeException
     * @return array
     */
    public function getEmailStatus($id)
    {
        $response = $this->prepareHttpClient('/mailer/status/' . $id)
            ->send();

        $result = $this->parseResponse($response);

        // ElasticEmail has a strange error handling method: mailer status
        // returns an XML format for a valid call, otherwise a simple message
        // is returned. So check if the message could be XML, if not: exception
        if (strpos($result, '<') !== 0) {
            throw new Exception\RuntimeException(sprintf(
                'An error occurred on ElasticEmail: %s', $result
            ));
        }

        $xml = new SimpleXMLElement($result);
        return array(
            'id'         => (string) $xml->attributes()->id,
            'status'     => (string) $xml->status,
            'recipients' => (int)    $xml->recipients,
            'failed'     => (int)    $xml->failed,
            'delivered'  => (int)    $xml->delivered,
            'pending'    => (int)    $xml->pending,
        );
    }

    /**
     * Upload an attachment to Elastic Email so it can be reused when an email is sent
     *
     * @link   http://elasticemail.com/api-documentation/attachments-upload
     * @param  Part $attachment
     * @return int The attachment id
     */
    public function uploadAttachment(Part $attachment)
    {
        $request = $this->prepareHttpClient('/attachments/upload', array('file' => $attachment->filename))
            ->setMethod(HttpRequest::METHOD_PUT)
            ->setRawBody($attachment->getRawContent())
            ->getRequest();

        // Elastic Email handles the content type of the message itself. Based on the extension of
        // the file, Elastic Email determines the content type. The attachment must be uploaded to
        // the server with always the application/x-www-form-urlencoded content type.
        //
        // More information: http://support.elasticemail.com/discussions/questions/1486-how-to-set-content-type-of-an-attachment
        $request->getHeaders()->addHeaderLine('Content-Type', 'application/x-www-form-urlencoded')
            ->addHeaderLine('Content-Length', strlen($attachment->getRawContent()));

        $response = $this->client->send($request);

        return $this->parseResponse($response);
    }

    /**
     * ------------------------------------------------------------------------------------------
     * ACCOUNTS
     * ------------------------------------------------------------------------------------------
     */

    /**
     * Get details about the user account (like left credit...)
     *
     * @link   http://elasticemail.com/api-documentation/account-details
     * @return array
     */
    public function getAccountDetails()
    {
        $response = $this->prepareHttpClient('/mailer/account-details')
            ->send();

        $xml = new SimpleXMLElement($this->parseResponse($response));
        return array(
            'id'     => (string) $xml->attributes()->id,
            'credit' => (float)  $xml->credit,
        );
    }

    /**
     * ------------------------------------------------------------------------------------------
     * CHANNELS
     * ------------------------------------------------------------------------------------------
     */


    /**
     * @param  string $uri
     * @param  array $parameters
     * @throws Exception\RuntimeException if format given is neither "xml" or "csv"
     * @return \Zend\Http\Client
     */
    private function prepareHttpClient($uri, array $parameters = array())
    {
        if (isset($parameters['format']) && !in_array($parameters['format'], array('xml', 'csv'))) {
            throw new Exception\RuntimeException(sprintf(
                'Formats supported by Elastic Email API are either "xml" or "csv", "%s" given',
                $parameters['format']
            ));
        }

        $parameters = array_merge(array('username' => $this->username, 'api_key' => $this->apiKey), $parameters);

        return $this->getClient()->resetParameters()
            ->setMethod(HttpRequest::METHOD_GET)
            ->setUri(self::API_ENDPOINT . $uri)
            ->setParameterGet($this->filterParameters($parameters));
    }

    /**
     * Note that currently, ElasticEmail API only returns 200 status, hence making error handling nearly
     * impossible. That's why as of today, we only return the content body without any error handling. If you
     * have any idea to solve this issue, please add a PR.
     *
     * @param  HttpResponse $response
     * @throws Exception\InvalidCredentialsException
     * @return array
     */
    private function parseResponse(HttpResponse $response)
    {
        $result = $response->getBody();

        if ($result !== 'Unauthorized: ') {
            return $result;
        }

        throw new Exception\InvalidCredentialsException(
            'Authentication error: missing or incorrect Elastic Email API key'
        );
    }
}
