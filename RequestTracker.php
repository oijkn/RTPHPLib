<?php

/**
 * RTPHPLib v1.2.2
 * Copyright (C) 2012 Samuel Schmidt.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * Requires curl
 *
 * Standard request fields are documented at http://requesttracker.wikia.com/wiki/REST
 * Depending on your request type, this will determine how you create your array of values.
 * See the example script for a demonstration.
 */
class RequestTracker
{
    /**
     * The location of the REST api.
     *
     * @var string
     */
    protected $url;

    /**
     * The location of the next request.
     *
     * @var string
     */
    protected $requestUrl;

    /**
     * Username with which to authenticate.
     *
     * @var string
     */
    protected $user;

    /**
     * Password to use.
     *
     * @var string
     */
    protected $pass;

    /**
     * Current set of fields to post to RT.
     *
     * @var array
     */
    protected $postFields;

    /**
     * If false, will disable verification of SSL certificates.
     * This is not recommended for production use. If SSL is not
     * working and the RT host's cert is valid, you should verify that
     * your curl installation has a CA cert bundle installed.
     *
     * @var bool
     */
    protected $enableSslVerification = true;

    /**
     * Set the Proxy for curl_setopt.
     *
     * @var string
     */
    protected $proxy;

    /**
     * If false, access to RT is forbidden.
     *
     * @var bool
     */
    protected $login = false;

    /**
     * Set the action for ticket.
     * Reply or Comment.
     *
     * @var string
     */
    protected $action = 'correspond';

    /**
     * Create a new instance for API requests.
     *
     * @param string $rootUrl
     *                        The base URL to your request tracker installation. For example,
     *                        if your RT is located at "http://rt.example.com", your rootUrl
     *                        would be "http://rt.example.com".  There should be no trailing slash.
     * @param string $user    the username to authenticate with
     * @param string $pass    the password to authenticate with
     */
    public function __construct($rootUrl, $user, $pass)
    {
        $this->url = $rootUrl.'/REST/1.0/';
        $this->user = $user;
        $this->pass = $pass;
    }

    /**
     * Sends a request to your RT.
     *
     * In general, this function should not be called directly- should only
     * be used by a subclass if there is custom functionality not covered
     * by the general API functions provided.
     *
     * @param bool     $doNotUseContentField - the normal behavior of this
     *                                       function is to take the postFields and push them into a single
     *                                       content field for the POST.  If this is set to true, the postFields
     *                                       will be used as the fields for the form instead of getting pushed
     *                                       into the content field.
     * @param object[] $attachments          Attachments array to add to ticket keyed by
     *                                       filenames. The array values should be CURLFile objects for PHP > 5.5 or
     *                                       an array of strings containing the file info prepended with "@" for
     *                                       PHP < 5.5 eg:
     * @/tmp/phpK5TNJc
     * or
     * @/tmp/phpK5TNJc;type=text/plain;filename=2.txt
     *
     * From original Request Tracker API, to add attachment to ticket while doing a comment
     * we must add another attachment_1 param with raw file
     * http://requesttracker.wikia.com/wiki/REST#Ticket_History_Comment
     * After testings, one right way is to create CurlObject with file and to put into attachment_1
     * because normal POST field fails
     * More info: http://php.net/manual/en/class.curlfile.php
     *
     * @return array|bool|string
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    protected function send($doNotUseContentField = false, $attachments = [])
    {
        if ($this->login) {
            if (!empty($this->postFields) && $doNotUseContentField) {
                $fields = $this->postFields;
                $fields['user'] = $this->user;
                $fields['pass'] = $this->pass;
            } elseif (!empty($this->postFields)) {
                $fields = ['user' => $this->user, 'pass' => $this->pass, 'content' => $this->parseArray($this->postFields)];
            } else {
                $fields = ['user' => $this->user, 'pass' => $this->pass];
            }

            // If we've received attachment param, we have to add to POST params apart from 'content' and send Content-Type
            if (!empty($attachments)) {
                $i = 1;
                foreach ($attachments as $attachment) {
                    $fields['attachment_'.$i++] = $attachment;
                }
            }
            $response = $this->post($fields);
            $this->setPostFields('');

            return $response;
        }

        throw new AuthenticationException('You must login in first.');
    }

    /**
     * Login to RT.
     *
     * OK, here's how login works.  We request to see ticket 1.  We don't
     * even care if it exists.  If not, we throw exceptions: auth. failures and
     * server-side errors.
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function login()
    {
        $Url = $this->url.'ticket/1';
        $this->setRequestUrl($Url);

        $fields = ['user' => $this->user, 'pass' => $this->pass];
        $response = $this->post($fields);

        if (200 === $response['code']) {
            $this->login = true;
        }

        return $this->login;
    }

    /**
     * Logout to RT.
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function logout()
    {
        $Url = $this->url.'logout';
        $this->setRequestUrl($Url);
        $this->send(true);

        $this->login = false;

        return $this->login;
    }

    /**
     * Create a ticket.
     *
     * @param array $content     the ticket fields as fieldname=>fieldvalue array
     * @param array $attachments
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function createTicket($content, array $attachments = [])
    {
        $content['id'] = 'ticket/new';
        $Url = $this->url.'ticket/new';
        if (isset($content['Text'])) {
            $content['Text'] = str_replace("\n", "\n ", $content['Text']);
        }
        $this->setRequestUrl($Url);
        $this->setPostFields($content);
        if (!empty($attachments)) {
            $content['Attachment'] = implode("\n ", array_keys($attachments));
            $this->setPostFields($content);
            $response = $this->send(false, $attachments);
        } else {
            $this->setPostFields($content);
            $response = $this->send();
        }

        return $this->parseResponse($response);
    }

    /**
     * Edit ticket.
     *
     * @param int   $ticketId
     * @param array $content  the ticket fields as fieldname=>fieldvalue array
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function editTicket($ticketId, $content)
    {
        $Url = $this->url."ticket/$ticketId/edit";
        $this->setRequestUrl($Url);
        $this->setPostFields($content);
        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Make an action on the ticket.
     *
     * @param $ticketId
     * @param $action - take, untake, steal
     *
     * @return array|string
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function doTicketAction($ticketId, $action = 'take')
    {
        $content['Action'] = $action;
        $content['id'] = $ticketId;
        $Url = $this->url."ticket/$ticketId/take";
        $this->setRequestUrl($Url);

        $this->setPostFields($content);
        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Reply to a ticket.
     *
     * @param int   $ticketId
     * @param array $content     the ticket fields as fieldname=>fieldvalue array
     * @param array $attachments ticket attachments array keyed by filename.
     *                           For the array value see $this->send() documentation.
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function doTicketReply($ticketId, $content, array $attachments = [])
    {
        $content['Action'] = $this->action;
        $Url = $this->url."ticket/$ticketId/comment";
        if (isset($content['Text'])) {
            $content['Text'] = str_replace("\n", "\n ", $content['Text']);
        }
        $this->setRequestUrl($Url);
        if (!empty($attachments)) {
            $content['Attachment'] = implode("\n ", array_keys($attachments));
            $this->setPostFields($content);
            $response = $this->send(false, $attachments);
        } else {
            $this->setPostFields($content);
            $response = $this->send();
        }

        $this->action = 'correspond';

        return $this->parseResponse($response);
    }

    /**
     * Comment on a ticket.
     *
     * @param int   $ticketId
     * @param array $content
     * @param array $attachments ticket attachments array keyed by filename.
     *                           For the array value see $this->send() documentation.
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function doTicketComment($ticketId, $content, array $attachments = [])
    {
        $this->action = 'comment';

        return $this->doTicketReply($ticketId, $content, $attachments);
    }

    /**
     * Merge a ticket into another.
     *
     * @param int $ticketId
     * @param int $intoId
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function doTicketMerge($ticketId, $intoId)
    {
        $Url = $this->url."ticket/$ticketId/merge/$intoId";
        $this->setRequestUrl($Url);

        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Get ticket metadata.
     *
     * @param int $ticketId
     *
     * @return array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function getTicketProperties($ticketId)
    {
        $Url = $this->url."ticket/$ticketId/show";
        $this->setRequestUrl($Url);

        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Get ticket links.
     *
     * @param int $ticketId
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function getTicketLinks($ticketId)
    {
        $Url = $this->url."ticket/$ticketId/links/show";
        $this->setRequestUrl($Url);
        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Add link from one ticket to another without worrying about existing links.
     *
     * @param int    $ticket1
     * @param string $relationship - RefersTo, ReferredToBy, MemberOf, HasMember, DependsOn, DependedOnBy
     *                             Members, RunsOn, IsRunning, ComponentOf, HasComponent
     * @param int    $ticket2
     * @param bool   $unlink
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function addTicketLink($ticket1, $relationship, $ticket2, $unlink = false)
    {
        /* Note that this URL does not contain a ticket number. */
        $Url = $this->url.'ticket/link';
        $this->setRequestUrl($Url);
        $content = [
            'id' => $ticket1,
            'rel' => $relationship,
            'to' => $ticket2,
            'del' => $unlink,
        ];
        $this->setPostFields($content);

        /*
         * Use $doNotUseContentField = true for the send($doNotUseContentField)
         * function so that the fields won't get pushed into the content field.
         */
        $response = $this->send(true);

        return $this->parseResponse($response);
    }

    /**
     * Modify links on a ticket.
     *
     * @param int   $ticketId
     * @param array $content
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function editTicketLinks($ticketId, $content)
    {
        $Url = $this->url."ticket/$ticketId/links";
        $this->setRequestUrl($Url);
        $this->setPostFields($content);
        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Get a list of attachments on a ticket.
     *
     * @param int $ticketId
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function getTicketAttachments($ticketId)
    {
        $Url = $this->url."ticket/$ticketId/attachments";
        $this->setRequestUrl($Url);

        $response = $this->send();
        $response = $this->parseResponse($response);
        if (!empty($response['Attachments'])) {
            // Turn Attachments to an array keyed by attachment id:
            $attachments = explode(chr(10), $response['Attachments']);
            $response = $this->parseResponseBody($attachments);
        }

        return $response;
    }

    /**
     * Get a specific attachment's metadata on a ticket.
     *
     * @param int $ticketId
     * @param int $attachmentId
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function getAttachment($ticketId, $attachmentId)
    {
        $Url = $this->url."ticket/$ticketId/attachments/$attachmentId";
        $this->setRequestUrl($Url);

        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Get the content of an attachment.
     *
     * @param int $ticketId
     * @param int $attachmentId
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function getAttachmentContent($ticketId, $attachmentId)
    {
        $Url = $this->url."ticket/$ticketId/attachments/$attachmentId/content";
        $this->setRequestUrl($Url);

        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Get the history of a ticket.
     *
     * @param int  $ticketId
     * @param bool $longFormat Whether to return all data of each history node
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function getTicketHistory($ticketId, $longFormat = true)
    {
        $Url = $this->url."ticket/$ticketId/history?format=l";
        if (!$longFormat) {
            $Url = $this->url."ticket/$ticketId/history";
        }

        $this->setRequestUrl($Url);

        $response = $this->send();

        return $longFormat ? $this->parseLongTicketHistoryResponse($response) : $this->parseResponse($response);
    }

    /**
     * Get the long form data of a specific ticket history node.
     *
     * @param int $ticketId
     * @param int $historyId
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function getTicketHistoryNode($ticketId, $historyId)
    {
        $Url = $this->url."ticket/$ticketId/history/id/$historyId";

        $this->setRequestUrl($Url);

        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Convenience wrapper to search for tickets.
     *
     * @param $query
     * @param $orderby
     * @param string $format
     *
     * @return array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     *
     * @see $this->search()
     */
    public function searchTickets($query, $orderby, $format = 's')
    {
        return $this->search($query, $orderby, $format);
    }

    /**
     * General search based on a query.
     *
     * Extend the Request Tracker class and implement custom search functions there
     * by passing $query and $orderBy to this function
     *
     * @param string $query   the query to run
     * @param string $orderBy how to order the query
     * @param string $format  the format type (i,s,l)
     * @param string $type    search for: 'ticket', 'queue', 'group' or 'user'?
     * @param array  $fields  fields to return
     *
     * @return array
     *               's' = ticket-id=>ticket-subject
     *               'i' = $key=>ticket/ticket-id
     *               'l' = a multi-line format implemented
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function search($query, $orderBy, $format = 's', $type = 'ticket', $fields = [])
    {
        $Url = $this->url."search/$type?query=".urlencode($query);
        if (!empty($fields)) {
            $Url .= '&fields='.urlencode(implode(',', $fields));
        }
        $Url .= "&orderby=$orderBy&format=$format";

        $this->setRequestUrl($Url);

        $response = $this->send();

        $responseArray = [];

        if ('s' === $format) {
            $responseArray = $this->parseResponse($response);
        } elseif ('i' === $format) {
            $responseArray = $this->parseResponse($response);
        } elseif ('l' === $format) {
            $responseArray = $this->parseLongFormatSearchResponse($response);
        }

        return $responseArray;
    }

    /**
     * @param $response
     *
     * @return array|string
     */
    protected function parseResponse($response)
    {
        $response = explode(chr(10), $response['body']);
        $response = $this->cleanResponseBody($response);

        return $this->parseResponseBody($response);
    }

    /**
     * @param $response
     * @param string $delimiter
     *
     * @return array
     */
    private function parseLongFormatSearchResponse($response, $delimiter = ':')
    {
        $resultNodes = [];
        $resultStrings = preg_split('/(?=^id: )/m', $response['body'], null);
        // First item contains RT version and newline, remove it.
        unset($resultStrings[0]);
        foreach ($resultStrings as $resultString) {
            $node = explode(chr(10), $resultString);
            // remove empty line in the end.
            array_pop($node);
            $resultNodes[] = $this->parseResponseBody($node, $delimiter);
        }

        return $resultNodes;
    }

    /**
     * @param $response
     * @param string $delimiter
     *
     * @return array
     */
    private function parseLongTicketHistoryResponse($response, $delimiter = ':')
    {
        $historyNodes = [];
        $historyNodeStrings = preg_split('/\# (\d*)\/(\d*) \(id\/(\d*)\/total\)/', $response['body']);
        // First item contains RT version and newline, remove it.
        unset($historyNodeStrings[0]);
        foreach ($historyNodeStrings as $historyNodeString) {
            $node = explode(chr(10), $historyNodeString);
            $node = $this->cleanResponseBody($node);
            $node = $this->parseResponseBody($node, $delimiter);
            if (!empty($node['Attachments'])) {
                $node['Attachments'] = $this->parseResponseBody(explode(chr(10), $node['Attachments']), $delimiter);
            } else {
                // Normalize to an array.
                $node['Attachments'] = [];
            }
            $historyNodes[] = $node;
        }

        return $historyNodes;
    }

    /**
     * @param array $response
     *
     * @return array
     */
    private function cleanResponseBody(array $response)
    {
        array_shift($response); //skip RT status response
        array_shift($response); //skip blank line
        array_pop($response); //remove empty blank line in the end

        return $response;
    }

    /**
     * @param array  $response
     * @param string $delimiter
     *
     * @return array|string
     */
    private function parseResponseBody(array $response, $delimiter = ':')
    {
        $responseArray = [];
        $fields = [];
        $combined = [];
        $lastKey = null;
        foreach ($response as $line) {
            //RT will always preface a multiline with the length of the last key + length of $delimiter + one space)
            if (null !== $lastKey && preg_match('/^\s{'.(strlen($lastKey) + strlen($delimiter) + 1).'}(.*)$/', $line, $matches)) {
                $responseArray[$lastKey] .= "\n".$matches[1];
            } elseif (null !== $lastKey && 0 === strlen($line)) {
                $lastKey = null;
            } elseif (preg_match('/^#/', $line, $matches)) {
                $responseArray[$line] = '';
            } elseif (preg_match('/^([a-zA-Z0-9]+|CF\.{[^}]+})'.$delimiter.'\s(.*)$/', $line, $matches)) {
                $lastKey = $matches[1];
                $responseArray[$lastKey] = $matches[2];
            } elseif ((bool) $line && null !== $lastKey) {
                if (preg_match('/\s{4}/', $line)) {
                    $line = preg_replace('/\s{4}/', '', $line);
                }
                if (null !== $lastKey) {
                    $responseArray[$lastKey] .= PHP_EOL.$line;
                }
            } elseif (null === $lastKey && preg_match('/\t/', $line)) {
                foreach ($response as $lines) {
                    if (preg_match('/\t/', $lines)) {
                        if (null === $lastKey) {
                            ++$lastKey;
                            $fields = explode("\t", $lines);
                        } elseif (null !== $lastKey) {
                            $result = explode("\t", $lines);
                            foreach ($fields as $key => $val) {
                                $combined[$val] = $result[$key];
                            }
                            $responseArray[] = $combined;
                        }
                    }
                }
            }
            /*
             * Condition for function getAttachmentContent
             * remove last two keys (empty) and set $responseArray with the 'Content' response
             * check with preg_match if we are not using $this->search() format 'i' : ticket/<ticket-id>
             */
            elseif (empty($responseArray) && 0 !== stripos($line, 'ticket')) {
                $splice = array_splice($response, 0, -2);
                $responseArray = implode('', $splice);
            }
            /*
             * Condition when we use $this->search() with the format 'i'
             */
            elseif (0 === stripos($line, 'ticket')) {
                $responseArray = $response;
            }
        }

        return $responseArray;
    }

    /**
     * @param $contentArray
     *
     * @return string
     */
    private function parseArray($contentArray)
    {
        $content = '';
        foreach ($contentArray as $key => $value) {
            $content .= "$key: $value".chr(10);
        }

        return $content;
    }

    /**
     * Get metadata for a user.
     *
     * @param int|string $userId either the user id or the user login
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function getUserProperties($userId)
    {
        $Url = $this->url."user/$userId";

        $this->setRequestUrl($Url);

        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Create a user.
     *
     * @param array $content the user fields as fieldname=>fieldvalue array
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function createUser($content)
    {
        $content['id'] = 'user/new';
        $Url = $this->url.'user/new';

        $this->setRequestUrl($Url);
        $this->setPostFields($content);

        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Edit user.
     *
     * @param int   $userId
     * @param array $content the user fields as fieldname=>fieldvalue array
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function editUser($userId, $content)
    {
        $Url = $this->url."user/$userId/edit";

        $this->setRequestUrl($Url);
        $this->setPostFields($content);

        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Convenience wrapper to search for users.
     *
     * @param string $query
     * @param string $orderby
     * @param string $format
     *
     * @return array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     *
     * @see $this->search()
     */
    public function searchUsers($query = '', $orderby = '', $format = 's')
    {
        return $this->search($query, $orderby, $format, 'user');
    }

    /**
     * Get metadata of a queue.
     *
     * @param int $queueId
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function getQueueProperties($queueId)
    {
        $Url = $this->url."queue/$queueId";

        $this->setRequestUrl($Url);

        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Convenience wrapper to search for queues.
     *
     * @param string $query
     * @param string $orderby
     * @param string $format
     *
     * @return array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     *
     * @see $this->search()
     */
    public function searchQueues($query = '', $orderby = '', $format = 's')
    {
        return $this->search($query, $orderby, $format, 'queue');
    }

    /**
     * Get metadata of a group.
     *
     * @param int   $groupId
     * @param array $fields
     *                       Ask for specific fields to retrieve. (eg Members or Name)
     *
     * @return array key=>value response pair array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    public function getGroupProperties($groupId, array $fields = [])
    {
        $Url = $this->url."group/$groupId";
        if ($fields) {
            $Url .= '?fields='.implode(',', $fields);
        }

        $this->setRequestUrl($Url);

        $response = $this->send();

        return $this->parseResponse($response);
    }

    /**
     * Convenience wrapper to search for groups.
     *
     * @param string $query
     * @param string $orderby
     * @param string $format
     *
     * @return array
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     *
     * @see $this->search()
     */
    public function searchGroups($query = '', $orderby = '', $format = 's')
    {
        return $this->search($query, $orderby, $format, 'group');
    }

    /**
     * Toggles SSL certificate verification.
     *
     * @param $verify boolean false to turn off verification, true to enable
     */
    public function verifySslCertificates($verify)
    {
        $this->enableSslVerification = $verify;
    }

    /**
     * @param string $proxy example: 10.0.0.5:80
     */
    public function setProxy($proxy)
    {
        $this->proxy = $proxy;
    }

    /**
     * @param $url
     */
    protected function setRequestUrl($url)
    {
        $this->requestUrl = $url;
    }

    /**
     * @param $data
     */
    protected function setPostFields($data)
    {
        $this->postFields = $data;
    }

    /**
     * @param $data
     * @param null $contentType
     *
     * @return array|bool|string
     *
     * @throws AuthenticationException
     * @throws HttpException
     * @throws RequestTrackerException
     */
    private function post($data, $contentType = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->requestUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        if ('' != $this->proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        if (!empty($contentType)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-type: $contentType"]);
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->enableSslVerification ? 0 : 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->enableSslVerification ? 0 : 1);

        array_unshift($data, '');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = '';
        if (false === $response) {
            $error = curl_error($ch);
        }
        curl_close($ch);

        if (false === $response) {
            throw new RequestTrackerException('A fatal error occurred when communicating with RT :: '.$error);
        }

        // Fix for situations in which RT replies like this:
        //  RT/4.4.1 401 Credentials required
        //
        //  Your username or password is incorrect
        if (200 === $code && preg_match('#^RT/\d+(?:\S+) (\d+) ([\w\s]+)$#', $response, $matches)) {
            $code = $matches[1];
        }

        if (401 === $code) {
            throw new AuthenticationException('The user credentials were refused.');
        }

        if (200 !== $code) {
            throw new HttpException("An error occurred : [$code] :: $response");
        }

        return ['code' => $code, 'body' => $response];
    }

    /**
     * Dont save any stateful information when serializing.
     */
    public function __sleep()
    {
        return ['url', 'user', 'pass', 'enableSslVerification'];
    }
}

class RequestTrackerException extends Exception
{
}

class AuthenticationException extends RequestTrackerException
{
}

class HttpException extends RequestTrackerException
{
}
