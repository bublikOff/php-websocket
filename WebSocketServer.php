<?php
    
    // 
    // https://github.com/sebcode/php-websocketserver/tree/master/example
    //

    //
    class WebSocketServer
    {
        protected $rsock;
        protected $wsock;

        public $lastFrame = array();
        public $uniqid = false;

        protected $bufType = false;
        protected $buf = '';
        protected $close = false;

        const MAX_PAYLOAD_LEN = 1048576;
        const MAX_BUFFER_SIZE = 1048576;

        const OP_CONT = 0x0;
        const OP_TEXT = 0x1;
        const OP_BIN = 0x2;
        const OP_CLOSE = 0x8;
        const OP_PING = 0x9;
        const OP_PONG = 0xa;

        public function __construct ($readSocket, $writeSocket = false)
        {
            $this->rsock  = $readSocket;
            $this->wsock  = $writeSocket;
            $this->uniqid = (isset($_SERVER['TCPREMOTEIP']) ? sha1($_SERVER['TCPREMOTEIP']) . '-' . $_SERVER['TCPREMOTEPORT'] . '-' : '') . self::uniqidId();

            $i = 0;
            $requestHeaders = array();

            stream_set_blocking($this->rsock, true);
            stream_set_blocking($this->wsock, true);

            while ($data = $this->readLine()) 
            {
                $data = rtrim($data);
                if (empty($data)) break;
                @list($k, $v) = explode(':', $data, 2);
                $requestHeaders[$k] = ltrim($v);
                if ($i++ > 30) break;
            }

            if (empty($requestHeaders['Sec-WebSocket-Key'])) throw new Exception('invalid_handshake_request');

            $key = base64_encode(pack('H*', sha1($requestHeaders['Sec-WebSocket-Key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

            $response = "HTTP/1.1 101 Switching Protocols\r\n";
            $response .= "Upgrade: websocket\r\n";
            $response .= "Connection: Upgrade\r\n";
            $response .= "Sec-WebSocket-Accept: ". $key ."\r\n";

            if (isset($requestHeaders['Sec-WebSocket-Protocol'])) 
                $response .= "Sec-WebSocket-Protocol: " . $requestHeaders['Sec-WebSocket-Protocol'] . "\r\n";

            $response .= "\r\n";
   
            $this->write($response);

            stream_set_blocking($this->rsock, false);
            stream_set_blocking($this->wsock, false);

            $_SERVER['WEBSOCKET_KEY'] = $key;

            $this->onConnect();
        }

        public function write ($data)
        {
            $ret = fwrite($this->wsock, $data);

            if ($ret === false) throw new Exception('write_failed');
            if ($ret !== strlen($data)) throw new Exception('write_failed');

            return $ret;
        }

        public function read (&$data, $len)
        {
            $data = fread($this->rsock, $len);
            if ($data === false || $data === '') return false;
            if (strlen($data) !== $len) return false;
            return true;
        }

        public function readLine ()
        {
            $ret = fgets($this->rsock, 8192);
            if ($ret === false) throw new Exception('readLine_failed');
            return $ret;
        }

        public function readFrame ()
        {
            $this->lastFrame = array();

            /* read first 2 bytes */
            if($this->read($data, 2) == false) return false; 

            $b1 = ord($data[0]);
            $b2 = ord($data[1]);

            // Bit 0 of Byte 1: Indicates that this is the final fragment in a message. The first fragment MAY also be the final fragment.
            $isFin = ($b1 & (1 << 7)) != 0;

            // Bits 4-7 of Byte 1: Defines the interpretation of the payload data
            $opcode = $b1 & 0x0f;

            // Control frames are identified by opcodes where the most significant bit of the opcode is 1
            $isControl = ($b1 & (1 << 3)) != 0;

            // Bit 0 of Byte 2: If set to 1, a masking key is present in masking-key, and this is used to unmask the payload data.
            $isMasked = ($b2 & (1 << 7)) != 0;

            // Bits 1-7 of Byte 2: The length of the payload data.
            $paylen = $b2 & 0x7f;

            // read extended payload length, if applicable
            if ($paylen == 126) 
            {
                // the following 2 bytes are the actual payload len
                if($this->read($data, 2) == false) return false; 
                $unpacked = unpack('n', $data);
                $paylen = $unpacked[1];
            } 
            else if ($paylen == 127) 
            {
                // the following 8 bytes are the actual payload len
                if($this->read($data, 8) == false) return false; 
                throw new Exception('not_implemented');
            }

            if ($paylen >= self::MAX_PAYLOAD_LEN) throw new Exception('limit_violation');

            // read masking key and decode payload data
            $mask = false;
            $data = '';

            if ($isMasked) 
            {
                if($this->read($mask, 4) == false) return false; 
                if ($paylen) 
                {
                    if($this->read($data, $paylen) == false) return false; 
                    for ($i = 0, $j = 0, $l = strlen($data); $i < $l; $i++) 
                    {
                        $data[$i] = chr(ord($data[$i]) ^ ord($mask[$j]));
                        if ($j++ >= 3) $j = 0;
                    }
                }
            } 

            else if ($paylen && $this->read($data, $paylen) == false) return false; 

            $this->lastFrame['isFin'] = $isFin;
            $this->lastFrame['isControl'] = $isControl;
            $this->lastFrame['isMasked'] = $isMasked;
            $this->lastFrame['opcode'] = $opcode;
            $this->lastFrame['paylen'] = $paylen;
            $this->lastFrame['data'] = $data;

            return true;
        }

        public function processFrame ()
        {
            if(empty($this->lastFrame)) return;

            // unfragmented message
            if ($this->lastFrame['isFin'] && $this->lastFrame['opcode'] != 0) 
            {
                // unfragmented messages may represent a control frame
                if ($this->lastFrame['isControl']) {
                    $this->handleControlFrame($this->lastFrame['opcode'], $this->lastFrame['data']);
                } else {
                    $this->gotData($this->lastFrame['opcode'], $this->lastFrame['data']);
                }
            }

            // start fragmented message
            else if (!$this->lastFrame['isFin'] && $this->lastFrame['opcode'] != 0) {
                $this->bufType = $this->lastFrame['opcode'];
                $this->buf = $this->lastFrame['data'];
                $this->checkBufSize();
            }

            // continue fragmented message
            else if (!$this->lastFrame['isFin'] && $this->lastFrame['opcode'] == 0) {
                $this->buf .= $this->lastFrame['data'];
                $this->checkBufSize();
            }

            // finalize fragmented message
            else if ($this->lastFrame['isFin'] && $this->lastFrame['opcode'] == 0) {
                $this->buf .= $this->lastFrame['data'];
                $this->checkBufSize();
                $this->gotData($this->bufType, $this->buf);
                $this->bufType = false;
                $this->buf = '';
            }

            if ($this->close) return;
        }

        protected function handleControlFrame ($type, $data)
        {
            $len = strlen($data);

            if ($type == self::OP_CLOSE) 
            {
                // If there is a body, the first two bytes of the body MUST be a 2-byte unsigned integer
                if ($len !== 0 && $len === 1) {
                    throw new Exception('invalid_frame');
                }

                $statusCode = false;
                $reason = false;

                if ($len >= 2) {
                    $unpacked = unpack('n', substr($data, 0, 2));
                    $statusCode = $unpacked[1];
                    $reason = substr($data, 3);
                }

                $this->onClose($statusCode, $reason);

                // Send close frame. 0x88: 10001000 fin, opcode close
                $this->write(chr(0x88) . chr(0));
                $this->close = true;
            }
        }

        protected function checkBufSize()
        {
            if (strlen($this->buf) >= self::MAX_BUFFER_SIZE) throw new Exception('limit_violation');
        }

        protected function gotData ($type, $data)
        {
            if ($type == self::OP_TEXT) $this->gotText($data);
                else if ($type == self::OP_BIN) $this->gotBin($data);
        }

        public function sendText ($text)
        {
            $len = strlen($text);

            // extended 64bit payload not implemented yet
            if ($len > 0xffff) throw new Exception('not_implemented');

            // 0x81 = first and last bit set (fin, opcode=text)
            $header = chr(0x81);

            // extended 32bit payload
            if ($len >= 125) $header .= chr(126) . pack('n', $len); else $header .= chr($len);

            //
            $this->write($header . $text);
        }

        public function sendPing ()
        {
            $this->write(chr(0x89) . chr(0x00));
        }

        protected function gotText ($text)
        {
            /* to be implemented by child class */
        }

        protected function gotBin ($data)
        {
            /* to be implemented by child class */
        }

        protected function onConnect ()
        {
            /* to be implemented by child class */
        }

        protected function onClose ($statusCode, $reason)
        {
            /* to be implemented by child class */
        }

        private static function uniqidId ($lenght = 13) 
        {
            $bytes = null;

            if (function_exists('random_bytes')) $bytes = random_bytes(ceil($lenght / 2));
                elseif (function_exists('openssl_random_pseudo_bytes')) $bytes = openssl_random_pseudo_bytes(ceil($lenght / 2));

            if(is_null($bytes)) throw new Exception('No cryptographically secure random function available');

            return substr(bin2hex($bytes), 0, $lenght);
        }

    }

?>