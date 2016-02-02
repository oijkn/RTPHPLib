<?php

/**
 *
 *
 * @author Sam Schmidt <samuel@dersam.net>
 * @since 2016-02-01
 */
class RequestTrackerTest extends PHPUnit_Framework_TestCase
{
    public function getRequestTracker()
    {
        return new RequestTracker(
            'http://192.168.99.100:8080/',
            'root',
            'password'
        );
    }

    public function testCreateTicket()
    {
        $rt = $this->getRequestTracker();
        $content = array(
            'Queue'=>'General',
            'Requestor'=>'test@example.com',
            'Subject'=>'Lorem Ipsum',
            'Text'=>'dolor sit amet'
        );
        $response = $rt->createTicket($content);

        $this->assertRegExp('/^# Ticket\b \d+\b created\.$/', key($response));
    }

    private function getTicketIdFromCreateResponse($resp)
    {
        $matches = array();
        preg_match(
            '/^# Ticket\b (\d+)\b created\.$/',
            key($resp),
            $matches
        );

        return array_pop($matches);
    }

    public function testEditTicket()
    {
        $rt = $this->getRequestTracker();
        $content = array(
            'Queue'=>'General',
            'Requestor'=>'test@example.com',
            'Subject'=>'Lorem Ipsum',
            'Text'=>'dolor sit amet',
            'Priority'=>1
        );

        $response = $rt->createTicket($content);
        $ticketId = $this->getTicketIdFromCreateResponse($response);

        $response = $rt->editTicket($ticketId, array('Priority'=>22));
        $this->assertRegExp('/^# Ticket\b \d+\b updated\.$/', key($response));

        $response = $rt->getTicketProperties($ticketId);
        $this->assertEquals(22, $response['Priority']);
    }

    public function testTicketReply()
    {
        $rt = $this->getRequestTracker();
        $content = array(
            'Queue'=>'General',
            'Requestor'=>'test@example.com',
            'Subject'=>'Lorem Ipsum',
            'Text'=>'dolor sit amet',
            'Priority'=>1
        );

        $response = $rt->createTicket($content);
        $ticketId = $this->getTicketIdFromCreateResponse($response);

        $response = $rt->doTicketReply($ticketId, array(
            'Text'=>'This is a test reply.'
        ));

        $this->assertEquals('# Correspondence added', key($response));

        $history = $rt->getTicketHistory($ticketId);

        $node = $history[2];
        $this->assertEquals($ticketId, $node['Ticket']);
        $this->assertEquals('This is a test reply.', $node['Content']);
        $this->assertEquals('Correspond', $node['Type']);

        $node = $rt->getTicketHistoryNode($ticketId, $node['id']);
        $this->assertEquals($ticketId, $node['Ticket']);
        $this->assertEquals('This is a test reply.', $node['Content']);
        $this->assertEquals('Correspond', $node['Type']);
    }
}
