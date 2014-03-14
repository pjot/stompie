<?php

/**
 * Class that represents a Stomp Frame. It has functionality to both create
 * itself from a message received from the server and serialize itself into
 * a message to be sent to the server.
 *
 * @author Peter Bergström <peter@redpill-linpro.com>
 */
class StompieFrame
{
    /**
     * @var string Stomp command
     */
    public $command;
    /**
     * @var array Associative array of headers
     */
    public $headers = array();
    /**
     * @var string Body of message
     */
    public $body;

    /**
     * Create a new Stomp frame
     *
     * @param string $command Stomp command
     */
    public function __construct($command, $headers = array(), $body = ''))
    {
        $this->command = $command;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * Get the value for a header
     *
     * @param string $key Header key
     * @return string|false False if header doesn't exist
     */
    public function getHeader($key)
    {
        if (isset($this->headers[$key]))
        {
            return $this->headers[$key];
        }
        return false;
    }

    /**
     * Add a header to the frame
     *
     * @param string $key Header hey
     * @param string $value Header value
     */
    public function addHeader($key, $value)
    {
        $this->headers[$key] = $value;
    }

    /**
     * Remove a header from the frame
     *
     * @param string $key Header key
     */
    public function removeHeader($key)
    {
        if (isset($this->headers[$key]))
        {
            unset($this->headers[$key]);
        }
    }

    /**
     * Renders the headers array into a list of colon-separated key:value pairs
     *
     * @return string Rendered headers
     */
    protected function renderHeaders()
    {
        $headers = array();
        foreach ($this->headers as $key => $value)
        {
            $headers[] = sprintf('%s:%s', $key, str_replace(':', '\c', $value));
        }
        return implode("\n", $headers);
    }

    /**
     * Renders the frame according to the Stomp specification:
     *
     * COMMAND
     * key:value
     * key:value
     *
     * Body body body\00
     *
     * @return string Rendered frame
     */
    public function render()
    {
        return sprintf("%s\n%s\n\n%s\00",
            $this->command,
            $this->renderHeaders(),
            $this->body
        );
    }

    /**
     * Creates a frame from a message received from a server
     *
     * @param string $message Raw message from server
     * @return StompieFrame
     */
    public static function fromMessage($message)
    {
        $rows = explode("\n", $message);
        // First line is command
        $frame = new self(array_shift($rows));
        // Read headers
        while ($row = array_shift($rows))
        {
            // First empty line is divider between header and body
            if (empty($row))
            {
                break;
            }
            // Parse header
            preg_match('/([^:]*):(.*)/', $row, $matches);
            $frame->addHeader($matches[1], str_replace('\c', ':', $matches[2]));
        }
        // Read body
        $frame->body = implode("\n", $rows);
        return $frame;
    }
}

/**
 * Class that's meant to mimic the PHP Stomp Extension class \Stomp, although
 * not all functionality is yet implemented.
 * See: http://www.php.net/manual/en/class.stomp.php
 *
 * This implementation assumes Stomp v1.1.
 * See: http://stomp.github.io/stomp-specification-1.1.html
 *
 * It is tested against ActiveMQ.
 *
 * @author Peter Bergström <peter@redpill-linpro.com>
 */
class Stompie
{
    /**
     * @var string Broker URI
     */
    protected $broker;
    /**
     * @var string Host (Stomp specification vhost)
     */
    protected $host;
    /**
     * @var string Username of connection
     */
    protected $username;
    /**
     * @var string Password of connection
     */
    protected $password;
    /**
     * @var string Destination (queue)
     */
    protected $destination;
    /**
     * @var resource Socket
     */
    private $socket;
    /**
     * @var bool If the client is connected
     */
    private $is_connected = false;
    /**
     * @var string Session ID
     */
    private $session;
    /**
     * @var array Received messages
     */
    private $messages = array();

    /**
     * Opens a new Stomp connection.
     *
     * @param string $broker Broker URI
     * @param string $username Username
     * @param string $password Password
     */
    public function __construct($broker, $username, $password)
    {
        $this->broker = $broker;
        $this->host = parse_url($broker, PHP_URL_HOST);
        $this->username = $username;
        $this->password = $password;
        $this->connect();
    }

    /**
     * Send a frame to the broker. Note that this method reconnects first.
     *
     * @param StompieFrame $frame Frame to send
     * @return StompieFrame|false Response frame from server or false if no response
     */
    protected function sendFrame(StompieFrame $frame)
    {
        // Reconnect to flush the socket
        if ($this->is_connected)
        {
            $this->reconnect();
        }
        return $this->rawSend($frame);
    }

    protected function rawSend(StompieFrame $frame, $timeout = 1)
    {
        // Send the frame to the socket
        fwrite($this->socket, $frame->render());
        stream_set_timeout($this->socket, $timeout);
        $response = '';
        // Read lines from socket
        for (;;)
        {
            $row = fgets($this->socket);
            // \x00 is the message terminator. If found, we are done!
            if (preg_match('/\x00/', $row))
            {
                $response .= str_replace("\00", '', $row);
                break;
            }
            $response .= $row;
            // Halt when the socket returns an empty response
            if ($row == '')
            {
                break;
            }
        }
        return StompieFrame::fromMessage($response);
    }

    /**
     * Disconnect from the broker
     *
     * @return void
     */
    protected function disconnect()
    {
        fclose($this->socket);
        $this->is_connected = false;
    }

    /**
     *  Reconnect to the broker
     *
     *  @return bool Successful?
     */
    protected function reconnect()
    {
        $this->disconnect();
        return $this->connect();
    }

    /**
     * Connect to the broker
     *
     * @return bool Was the connection successful?
     */
    protected function connect()
    {
        $this->socket = stream_socket_client($this->broker, $error_code, $error_string, 5);
        if ( ! $this->socket)
        {
            // Unable to connect to socket
            return $this->is_connected = false;
        }
        // Create connect frame
        $frame = new StompieFrame('CONNECT');
        $frame->addHeader('accept-version', '1.1');
        $frame->addHeader('host', $this->host);
        // Add login credentials
        if ( ! empty($this->username) && ! empty($this->password))
        {
            $frame->addHeader('login', $this->username);
            $frame->addHeader('passcode', $this->password);
        }
        $response = $this->sendFrame($frame);
        // Server returns CONNECTED if the login was successful
        if ($response->command === 'CONNECTED')
        {
            return $this->is_connected = true;
        }
        return false;
    }

    /**
     * Close the socket gracefully.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * ACK's a frame
     *
     * @param StompieFrame $frame Frame
     * @return bool Successful?
     */
    public function ack(StompieFrame $frame)
    {
        $ack_frame = new StompieFrame('ACK');
        $ack_frame->addHeader('message-id', $frame->getHeader('message-id'));
        $ack_frame->addHeader('subscription', 0);
        $ack_frame->addHeader('receipt', 'ack-frame');
        // Send frame without reconnecting to preserve the active subscription
        $response = $this->rawSend($ack_frame);
        return $response->command === 'RECEIPT'
            && $response->getHeader('receipt-id') === 'ack-frame';
    }

    /**
     * Register to listen to a destination.
     *
     * @param string $destination Destination queue
     */
    public function subscribe($destination)
    {
        $this->destination = $destination;
    }

    /**
     * Send a message to a destination
     *
     * @param string $destination Destination
     * @param string $message Message body
     * @param array $headers Headers
     * @return bool Returns true if the message was successfully queued
     */
    public function send($destination, $message, $headers = array())
    {
        $frame = new StompieFrame('SEND');
        foreach ($headers as $key => $value)
        {
            $frame->addHeader($key, $value);
        }
        $frame->addHeader('destination', $destination);
        $frame->addHeader('content-length', strlen($message));
        $frame->addHeader('content-type', 'text/plain');
        $frame->addHeader('receipt', 'send-frame');
        $frame->body = $message;
        $response = $this->sendFrame($frame);
        return $response->command == 'RECEIPT'
            && $response->getHeader('receip-id') === 'send-frame';
    }

    /**
     * Checks if there are frames available in the queue by
     * resubscribing which seems to be the only way.
     *
     * @return bool Returns true if there are frames to be read
     */
    public function hasFrame()
    {
        $frame = new StompieFrame('SUBSCRIBE');
        $frame->addHeader('id', 0);
        $frame->addHeader('destination', $this->destination);
        $frame->addHeader('ack', 'client-individual');
        $response = $this->sendFrame($frame);
        // If we got any messages when we subscribed to the queue, we've
        // got frames to read
        return $response !== false
            && $response->command === 'MESSAGE';
    }

    /**
     * Reads the next frame from the queue.
     *
     * @return StompieFrame|false False if queue is empty, otherwise the next message
     */
    public function readFrame()
    {
        $frame = new StompieFrame('SUBSCRIBE');
        $frame->addHeader('id', 0);
        $frame->addHeader('destination', $this->destination);
        $frame->addHeader('ack', 'client-individual');
        return $this->sendFrame($frame);
    }
}

$s = new Stompie('tcp://localhost:61613', 'admin', 'admin');
$s->send('pjot.test', 'eeeeipen schnaur', array('priority' => 4, 'persistant' => true));
