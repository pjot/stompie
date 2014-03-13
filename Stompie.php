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
    protected $command;
    /**
     * @var array Associative array of headers
     */
    protected $headers = array();
    /**
     * @var string Body of message
     */
    protected $body;

    /**
     * Create a new Stomp Frame
     *
     * @param string $command Stomp command
     */
    public function __construct($command)
    {
        $this->command = $command;
    }

    /**
     * Add a header to the Frame
     *
     * @param string $key Header hey
     * @param string $value Header value
     */
    public function addHeader($key, $value)
    {
        $this->headers[$key] = $value;
    }

    /**
     * Set the body of the Frame
     *
     * @param string $body Frame body
     */
    public function setBody($body)
    {
        $this->body = $body;
    }

    /**
     * Renders the headers array into a list of colon-separated key:value pairs
     *
     * @return string
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
     * Renders the Frame according to the Stomp specification
     *
     * @return string
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
     * Creates a Frame from a message received from a broker
     *
     * @param array $rows Rows in message
     * @return StompieFrame
     */
    public static function fromMessage($rows)
    {
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
        $frame->setBody(implode("\n", $rows));
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

    public $is_debug = true;
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
     * Send a Frame to the broker.
     *
     * @return array Raw broker response in an array without line feeds
     */
    protected function sendFrame(StompieFrame $frame)
    {
        // Send the frame to the socket
        fwrite($this->socket, $frame->render());
        $response = array();
        // Read lines from socket
        while ($row = fgets($this->socket))
        {
            // Remove line feeds from line
            $row = str_replace("\n", '', $row);
            // \x00 is the message terminator. If found, we are done!
            if (preg_match('/\x00/', $row))
            {
                $response[] = str_replace("\00", '', $row);
                break;
            }
            $response[] = $row;
        }
        return $response;
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
        if (preg_match('/CONNECTED/', $response[0]))
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
        if ($this->is_connected)
        {
            fclose($this->socket);
        }
    }

    /**
     * Registed to listen to a destination
     *
     * @param string $destination Destination queue
     * @return bool Was the subscription successful?
     */
    public function subscribe($destination)
    {
        $this->destination = $destination;
        $frame = new StompieFrame('SUBSCRIBE');
        $frame->addHeader('id', 0);
        $frame->addHeader('destination', $this->destination);
        $frame->addHeader('receipt', 'message-my-subscribe');
        $frame->addHeader('ack', 'client');
        $response = $this->sendFrame($frame);
        return preg_match('/message-my-subscribe/', $response[0]) === 1;
    }

    /**
     * Reads the next frame from the queue.
     *
     * @return StompieFrame|false False if queue is empty, otherwise the next message
     */
    public function readFrame()
    {
        $frame = new StompieFrame('BEGIN');
        $frame->addHeader('transaction', 'my-transaction');
        $response = $this->sendFrame($frame);
        return StompieFrame::fromMessage($response);
    }
}
$s = new Stompie('tcp://localhost:61613', 'admin', 'admin');
$s->subscribe('pjot.test');
var_dump($s->readFrame());
