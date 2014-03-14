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
    public function __construct($command, $headers = array(), $body = '')
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
     * @var string Host (Stomp specification vhost)
     */
    protected $host;
    /**
     * @var int Port
     */
    protected $port;
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
     * @var array Arrays of received frames
     */
    private $frames = array(
        'messages' => array(),
        'receipts' => array(),
        'others'   => array(),
    );
    /**
     * @var array[int] Read timeout
     */
    private $timeout = array(
        'sec' => 1,
        'usec' => 0,
    );

    /**
     * Opens a new Stomp connection.
     *
     * @param string $broker Broker URI
     * @param string $username Username
     * @param string $password Password
     */
    public function __construct($broker, $username, $password)
    {
        $this->host = parse_url($broker, PHP_URL_HOST);
        $this->port = parse_url($broker, PHP_URL_PORT);
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
        socket_write($this->socket, $frame->render());
        $this->read();
    }

    /**
     * Store received frame in local queue
     *
     * @param StompieFrame $frame Frame
     */
    protected function storeFrame(StompieFrame $frame)
    {
        switch ($frame->command)
        {
            case 'MESSAGE':
                $this->frames['messages'][] = $frame;
                break;
            case 'RECEIPT':
                $this->frames['receipts'][] = $frame;
                break;
            default:
                $this->frames['others'][] = $frame;
                break;
        }
    }

    /**
     * Read messages from socket
     */
    protected function read()
    {
        $response = '';
        for (;;)
        {
            socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $this->timeout);
            $row = socket_read($this->socket, 2048);

            // \x00 is the message terminator, store the message
            if (preg_match('/\x00/', $response))
            {
                $responses = explode("\00", $response);
                foreach ($responses as $message)
                {
                    // Ignore one char messages
                    if (strlen($message) < 2)
                    {
                        continue;
                    }
                    $this->storeFrame(StompieFrame::fromMessage($message));
                }
                $response = '';
            }
            $response .= $row;
            // Halt when the socket returns an empty response
            if ($row == '')
            {
                break;
            }
        }
    }

    /**
     * Disconnect from the broker
     *
     * @return void
     */
    protected function disconnect()
    {
        socket_close($this->socket);
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
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_connect($this->socket, $this->host, $this->port);

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
        $this->sendFrame($frame);
        // Check for response
        foreach ($this->frames['others'] as $key => $frame)
        {
            if ($frame->command === 'CONNECTED')
            {
                $this->session_id = $frame->getHeader('session');
                unset($this->frames['others'][$key]);
                return $this->is_connected = true;
            }
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
     * NACK's a frame
     *
     * @param StompieFrame $frame Frame
     * @return bool Successful?
     */
    public function nack(StompieFrame $frame)
    {
        $ack_frame = new StompieFrame('NACK');
        $ack_frame->addHeader('message-id', $frame->getHeader('message-id'));
        $ack_frame->addHeader('subscription', 0);
        $receipt_id = self::makeReceiptId('nack');
        $ack_frame->addHeader('receipt', $receipt_id);
        $this->sendFrame($ack_frame);
        // Check for response
        foreach ($this->frames['receipts'] as $key => $receipt)
        {
            if ($receipt->getHeader('receipt-id') == $receipt_id)
            {
                unset($this->frames['receipts'][$key]);
                return true;
            }
        }
        return false;
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
        $receipt_id = self::makeReceiptId('ack');
        $ack_frame->addHeader('receipt', $receipt_id);
        $this->sendFrame($ack_frame);
        // Check for response
        foreach ($this->frames['receipts'] as $key => $receipt)
        {
            if ($receipt->getHeader('receipt-id') == $receipt_id)
            {
                unset($this->frames['receipts'][$key]);
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the current session id
     *
     * @return string Session id
     */
    public function getSessionId()
    {
        return $this->session_id;
    }

    /**
     * Sets the read timeout in seconds and microseconds
     *
     * @param int $seconds Seconds
     * @param int $microseconds Microseconds
     */
    public function setReadTimeout($seconds, $microseconds = 0)
    {
        $this->timeout = array(
            'sec' => $seconds,
            'usec' => $microseconds,
        );
    }

    /**
     * Get the current read timeout array
     *
     * @return array Timeout array
     */
    public function getReadTimeout()
    {
        return $this->timeout;
    }

    /**
     * Begins a transaction with the server
     *
     * @param string $transaction_id Transaction id
     */
    public function begin($transaction_id)
    {
        $frame = new StompieFrame('BEGIN');
        $frame->addHeader('transaction', $transaction_id);
        $this->sendFrame($frame);
    }

    /**
     * Commits a transaction with the server
     *
     * @param string $transaction_id Transaction id
     */
    public function commit($transaction_id)
    {
        $frame = new StompieFrame('COMMIT');
        $frame->addHeader('transaction', $transaction_id);
        $this->sendFrame($frame);
    }

    /**
     * Aborts a transaction with the server
     *
     * @param string $transaction_id Transaction id
     */
    public function abort($transaction_id)
    {
        $frame = new StompieFrame('ABORT');
        $frame->addHeader('transaction', $transaction_id);
        $this->sendFrame($frame);
    }

    /**
     * Register to listen to a destination.
     *
     * @param string $destination Destination queue
     */
    public function subscribe($destination)
    {
        $this->destination = $destination;
        $frame = new StompieFrame('SUBSCRIBE');
        $frame->addHeader('id', 0);
        $frame->addHeader('destination', $this->destination);
        $frame->addHeader('ack', 'client-individual');
        $this->sendFrame($frame);
    }

    /**
     * Unsubscribe from destination
     */
    public function unsubscribe()
    {
        $frame = new StompieFrame('UNSUBSCRIBE');
        $frame->addHeader('id', 0);
        $frame->addHeader('destination', $destination);
        $this->sendFrame($frame);
        $this->destination = null;
        $this->frames = array(
            'messages' => array(),
            'receipts' => array(),
            'others' => array(),
        );
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
        $receipt_id = self::makeReceiptId('send');
        $frame->addHeader('receipt', $receipt_id);
        $frame->body = $message;
        $this->sendFrame($frame);
        // Check for response
        foreach ($this->frames['receipts'] as $key => $receipt)
        {
            if ($receipt->getHeader('receipt-id') === $receipt_id)
            {
                unset($this->frames['receipts'][$key]);
                return true;
            }
        }
        return false;
    }

    /**
     * Creates a unique receipt id
     *
     * @return string Unique receipt id
     */
    private static function makeReceiptId($prefix = '')
    {
        return $prefix . uniqid();
    }

    /**
     * Checks if there are frames available in the queue.
     *
     * @return bool Returns true if there are frames to be read
     */
    public function hasFrame()
    {
        return count($this->frames['messages']) > 0;
    }

    /**
     * Reads the next frame from the queue.
     *
     * @return StompieFrame|false False if queue is empty, otherwise the next message
     */
    public function readFrame()
    {
        if ( ! $this->hasFrame())
        {
            return false;
        }
        return array_pop($this->frames['messages']);
    }
}
