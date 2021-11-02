<?php


class Events
{
    /**
     * @var \EventBase $eventBase
     */
    protected $eventBase;

    protected $events = [];

    private static $_instance = null;

    public static function getInstance()
    {
        if (!self::$_instance instanceof self) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct()
    {
        $this->eventBase = new \EventBase();
    }

    public function add($fd, int $what, callable $cb, $arg = null)
    {
        $event = new \Event($this->eventBase, $fd, $what, $cb, $arg);
        $ret = $event->add();
        $this->events[(int)$fd][$what] = $event;

    }

    public function del($fd, int $what)
    {
        $event = $this->events[(int)$fd][$what];
        $event->del();
        unset($this->events[(int)$fd][$what]);
        if (empty($this->events[(int)$fd])) {
            unset($this->events[(int)$fd]);
        }
    }

    public function loop()
    {
        $this->eventBase->loop();
    }
}

class Connections
{
    protected $connections;

    private static $_instance = null;

    public static function getInstance()
    {
        if (!self::$_instance instanceof self) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function add($fd)
    {
        $this->connections[(int)$fd] = $fd;

    }

    public function del($fd)
    {
        unset($this->connections[(int)$fd]);
    }

}

class Buffer
{
    public $_currentBuffer;

    public $_maxBufferSize;

    public $_currentBufferSize;

    public static $status = [];

    public $_headers;

    const STATUS_READY = 0;
    const STATUS_HANDSHAKE = 1;
    const STATUS_CONNECTING = 2;
    const STATUS_CLOSED = 3;


    private static $_instance = null;

    public static function getInstance()
    {
        if (!self::$_instance instanceof self) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function init($fd)
    {
        $fd = (int)$fd;
        $this->_currentBuffer[$fd] = '';
        $this->_currentBufferSize[$fd] = 0;
        $this->_headers[$fd] = [];
        self::$status[$fd] = self::STATUS_READY;
    }

    public function addBuffer($fd, $buffer)
    {
        $fd = (int)$fd;
        $this->_currentBuffer[$fd] .= $buffer;
        $this->_currentBufferSize[$fd] += strlen($buffer);
    }

    public function clear($fd)
    {
        close($fd);
        $fd = (int)$fd;
        unset($this->_currentBuffer[$fd]);
        unset($this->_currentBufferSize[$fd]);
        unset($this->_headers[$fd]);
        unset(self::$status[$fd]);
    }

    public function checkHttpHeader($fd)
    {
        $fd = (int)$fd;
        if (strpos($this->_currentBuffer[$fd], "\r\n\r\n") === false) {
            return false;
        }

        $this->parseHeader($fd);
        return true;
    }

    public function checkFrame($fd)
    {
        if ($this->_currentBufferSize[(int)$fd] < 2) {
            return false;
        }
        $buffer = $this->_currentBuffer[(int)$fd];

        $headerLen = 0;
        $bodyLen = 0;

        $headerLen += 2;
        $firstByte = ord($buffer[0]);
        $secondByte = ord($buffer[1]);

        $fin = $firstByte >> 7;
        $opcode = $firstByte & 0xf;
        if ($opcode == 0x8) {
            fprintf(STDOUT, "收到客户端%d的Close帧\r\n", $fd);
            send($fd, pack('n', 1000) . "Closed Successfully!", 0x8);
            self::$status[(int)$fd] = self::STATUS_CLOSED;
            $this->clear($fd);
            return false;
        }

        $masked = $secondByte >> 7;
        if (!$masked) {
            self::$status[(int)$fd] = self::STATUS_CLOSED;
            $this->clear($fd);
            return false;
        }

        $payloadLen = $secondByte & 127;
        if ($payloadLen < 126) {
            $bodyLen = $payloadLen;
        } else if ($payloadLen == 126) {
            $headerLen += 2;
            $bodyLen = unpack("n/nlen", $buffer)['len'];
        } elseif ($payloadLen == 127) {
            $headerLen += 8;
            $bodyLen = unpack("n/Jlen", $buffer)['len'];
        }

        $headerLen += 4;
        if ($this->_currentBufferSize[(int)$fd] < $headerLen + $bodyLen) {
            return false;
        }

        $maskKey = substr($buffer, $headerLen - 4, 4);
//        $maskKey[0] = $buffer[$headerLen - 4];
//        $maskKey[1] = $buffer[$headerLen - 3];
//        $maskKey[2] = $buffer[$headerLen - 2];
//        $maskKey[3] = $buffer[$headerLen - 1];
        $payload = substr($buffer, $headerLen, $payloadLen);

        for ($i = 0; $i < $payloadLen; $i++) {
            $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
        }


        $this->_currentBufferSize[(int)$fd] -= ($headerLen + $payloadLen);
        $this->_currentBuffer[(int)$fd] = substr($this->_currentBuffer[(int)$fd], $headerLen + $payloadLen);

        if ($opcode == 0x9) {
            fprintf(STDOUT, "收到客户端%d的Ping帧\r\n", $fd);
            $pong = chr(0x80 | 0xa) . chr(0);
            socket_send($fd, $pong, strlen($pong), 0);
            return false;
        }

        if ($opcode == 0xA) {
            fprintf(STDOUT, "收到客户端%d的Pong帧\r\n", $fd);
            return false;
        }

        fprintf(STDOUT, "收到客户端%d的数据: %s\r\n", $fd, $payload);
        return true;

        /**
         * 0                   1                   2                   3
         * 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
         * +-+-+-+-+-------+-+-------------+-------------------------------+
         * |F|R|R|R| opcode|M| Payload len |    Extended payload length    |
         * |I|S|S|S|  (4)  |A|     (7)     |             (16/64)           |
         * |N|V|V|V|       |S|             |   (if payload len==126/127)   |
         * | |1|2|3|       |K|             |                               |
         * +-+-+-+-+-------+-+-------------+ - - - - - - - - - - - - - - - +
         * |     Extended payload length continued, if payload len == 127  |
         * + - - - - - - - - - - - - - - - +-------------------------------+
         * |                               |Masking-key, if MASK set to 1  |
         * +-------------------------------+-------------------------------+
         * | Masking-key (continued)       |          Payload Data         |
         * +-------------------------------- - - - - - - - - - - - - - - - +
         * :                     Payload Data continued ...                :
         * + - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - +
         * |                     Payload Data continued ...                |
         * +---------------------------------------------------------------+
         */
    }


    public function parseHeader($fd)
    {
        $fd = (int)$fd;
        $array = explode("\r\n", $this->_currentBuffer[$fd]);
        unset($array[0]);
        foreach ($array as $string) {
            if (empty($string)) {
                continue;
            }
            list($key, $value) = explode(": ", $string, 2);
            $this->_headers[$fd][$key] = $value;
        }

        $this->_currentBuffer[$fd] = '';
        $this->_currentBufferSize[$fd] = 0;
    }

    public function handshake($fd)
    {
        self::$status[(int)$fd] = self::STATUS_HANDSHAKE;
        $secKey = $this->_headers[(int)$fd]['Sec-WebSocket-Key'];
        $accept = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $data = "HTTP/1.1 101 Switching Protocols\r\nConnection:Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Accept: $accept\r\n\r\n";
        socket_send($fd, $data, strlen($data), 0);
        self::$status[(int)$fd] = self::STATUS_CONNECTING;
    }
}


$socket = socket_create(AF_INET, SOCK_STREAM, IPPROTO_IP);

socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1);

socket_bind($socket, '0.0.0.0', 9701);
socket_listen($socket, 1024);
socket_set_nonblock($socket);

Events::getInstance()->add($socket, \Event::READ | \Event::PERSIST, 'recvServer');

function recvServer($fd, $what, $arg)
{
    $client = socket_accept($fd);
    if (is_resource($client)) {
        socket_set_nonblock($client);
        Connections::getInstance()->add($client);
        Events::getInstance()->add($client, \Event::READ | \Event::PERSIST, 'recvClient');
        Buffer::getInstance()->init((int)$client);
    }
}

function recvClient($fd, $what, $arg)
{
    $len = socket_recv($fd, $data, 65535, 0);
//    fprintf(STDOUT, "fd=%d len=%d data=%s", $fd, $len, $data);
    if (!$len) {
        close($fd);
    }
    Buffer::getInstance()->addBuffer((int)$fd, $data);


    if (Buffer::$status[(int)$fd] == Buffer::STATUS_READY) {
        while (Buffer::getInstance()->checkHttpHeader((int)$fd)) {
            Buffer::getInstance()->handshake($fd);
        }
    }

    if (Buffer::$status[(int)$fd] == Buffer::STATUS_CONNECTING) {
        while (Buffer::getInstance()->checkFrame($fd)) {
            $msg = (string)time();
            send($fd, $msg);
        }
    }
}

function send($fd, $msg, $opcode = 0x1)
{
    $len = strlen($msg);
    $firstByte = pack("C", 0x80 | $opcode);
    if ($len <= 125) {
        $buffer = $firstByte . chr($len);
    } else {
        if ($len <= 65535) {
            $buffer = $firstByte . \chr(126) . \pack("n", $len);
        } else {
            $buffer = $firstByte . \chr(127) . \pack("J", $len);
        }
    }

    $buffer .= $msg;
    if (is_resource($fd)) {
        socket_send($fd, $buffer, strlen($buffer), 0);
    }
}

function close($fd)
{
    echo "客户端已经关闭\r\n";
    socket_close($fd);
    Events::getInstance()->del($fd, \Event::READ | \Event::PERSIST);
    Connections::getInstance()->del($fd);
}

Events::getInstance()->loop();

