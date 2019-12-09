<?php

//
// https://github.com/paragi/PHP-websocket-client/blob/master/websocket_client.php
//

/*----------------------------------------------------------------------------*\
  Websocket client

  By Paragi 2013, Simon Riget MIT license.

  This is a demonstration of a websocket clinet.

  If you find flaws in it, please let me know at simon.riget (at) gmail

  Websockets use hybi10 frame encoding:

        0                   1                   2                   3
        0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
       +-+-+-+-+-------+-+-------------+-------------------------------+
       |F|R|R|R| opcode|M| Payload len |    Extended payload length    |
       |I|S|S|S|  (4)  |A|     (7)     |             (16/63)           |
       |N|V|V|V|       |S|             |   (if payload len==126/127)   |
       | |1|2|3|       |K|             |                               |
       +-+-+-+-+-------+-+-------------+ - - - - - - - - - - - - - - - +
       |     Extended payload length continued, if payload len == 127  |
       + - - - - - - - - - - - - - - - +-------------------------------+
       |                               |Masking-key, if MASK set to 1  |
       +-------------------------------+-------------------------------+
       | Masking-key (continued)       |          Payload Data         |
       +-------------------------------- - - - - - - - - - - - - - - - +
       :                     Payload Data continued ...                :
       + - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - +
       |                     Payload Data continued ...                |
       +---------------------------------------------------------------+

  See: https://tools.ietf.org/rfc/rfc6455.txt
  or:  http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10#section-4.2

\*----------------------------------------------------------------------------*/

//
// Open websocket connection
//
function websocket_open ($host='', $port=80, $path='/', $headers='', &$error_string='', $timeout=10, $ssl=false)
{
    // Generate a key (to convince server that the update is not random)
    // The key is for the server to prove it i websocket aware. (We know it is)
    $key=base64_encode(openssl_random_pseudo_bytes(16));

    $header = "GET $path HTTP/1.1\r\n"
        ."Host: $host\r\n"
        ."pragma: no-cache\r\n"
        ."Upgrade: WebSocket\r\n"
        ."Connection: Upgrade\r\n"
        ."Sec-WebSocket-Key: $key\r\n"
        ."Sec-WebSocket-Version: 13\r\n";

    // Add extra headers
    if(!empty($headers)) foreach($headers as $h) $header.=$h."\r\n";

    // Add end of header marker
    $header.="\r\n";

    // Connect to server
    $host = $host ? $host : "127.0.0.1";
    $port = $port <1 ? 80 : $port;
    $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    $result = @socket_connect($socket, $host, $port); //$errno, $errstr, $timeout);

    if($result == false)
    {
        $errno = socket_last_error($socket);
        $errstr = socket_strerror($errno);
        $error_string = "Unable to connect to websocket server: $errstr ($errno)";
        return false;
    }

    //socket_set_block($socket);

    $bytes = @socket_write($socket, $header, strlen($header));
    if ($bytes === false) 
    {
        $errno = socket_last_error($socket);
        $errstr = socket_strerror($errno);
        $error_string = "Unable to connect to websocket server: $errstr ($errno)";
        return false;
    }

    // Read response headers
    $reaponse_header = @socket_read($socket, 1024);
    if ($reaponse_header === "" || $reaponse_header === false)
    {
        $errno = socket_last_error($socket);
        $errstr = socket_strerror($errno);
        $error_string = "Unable to connect to websocket server: $errstr ($errno)";
        @socket_close($socket); 
        return false;
    }

    //    
    socket_set_nonblock($socket);

    // status code 101 indicates that the WebSocket handshake has completed.
    if(!strpos($reaponse_header," 101 ")|| !strpos($reaponse_header,'Sec-WebSocket-Accept: '))
    {
        $error_string = 'Server did not accept to upgrade connection to websocket.' . $reaponse_header. E_USER_ERROR;
        return false;
    }

    return $socket;
}

// Write data frame to websocket connection
function websocket_write ($socket, $data, $type = 'text', $masked = false)
{
    $buffer = websocket_hybi10Encode($socket, $data, $type, $masked);
    $bytes = @socket_write($socket, $buffer, strlen($buffer));
    return ($bytes !== false);
}

// Read data frame from websocket connection
function websocket_read ($socket, $timeout=3, &$error_string=NULL)
{
    $buffer = "";
    $start  = microtime(true);

    $header = @socket_read($socket, 2);
    if ($header === "" || $header === false)
    {
        if($header === '') socket_close($socket);
        return false;
    }

    $mask   = '';
    $opcode = ord($header[0]) & 0x0F;
    $final  = ord($header[0]) & 0x80;
    $masked = ord($header[1]) & 0x80;
    $payloadLength = ord($header[1]) & 0x7F;
    $decodedData = array('type'=>'unknown');

    // Get payload length extensions
    $extLength = 0;
    if($payloadLength >= 0x7E)
    {
        $extLength = 2;
        if($payloadLength == 0x7F) $extLength = 8;
        $header=@socket_read($socket, $extLength);
        if(!$header)
        {
            $error_string = "Reading header extension from websocket failed.";
            return false;
        }
        // Set extented paylod length
        $payloadLength= 0;
        for($i=0; $i<$extLength; $i++) $payloadLength += ord($header[$i]) << ($extLength-$i-1)*8;
    }

    // Get Mask key
    if($masked)
    {
        $mask=@socket_read($socket, 4);
        if(!$mask)
        {
            $error_string = "Reading header mask from websocket failed.";
            return false;
        }
    }

    // Get payload
    $frame_data='';
    do
    {
        if((microtime(true) - $start) >= $timeout)
        {
            $error_string = "Reading from websocket timeout.";
            return false;
        }
        $frame = @socket_read($socket, $payloadLength);
        if(!$frame)
        {
            $error_string = "Reading from websocket failed.";
            return false;
        }
        $payloadLength -= strlen($frame);
        $frame_data .= $frame;
    }
    while($payloadLength > 0);
    
    //
    switch ($opcode) 
    {
        // continuation frame:
        case 0:
            $decodedData['type'] = 'continuation';
            break;
        // text frame:
        case 1:
            $decodedData['type'] = 'text';
            break;
        // binary frame:
        case 2:
            $decodedData['type'] = 'binary';
            break;
        // connection close frame:
        case 8:
            $decodedData['type'] = 'close';
            break;
        // ping frame:
        case 9:
            //
            $decodedData['type'] = 'ping';

            // Handle ping requests (sort of) send pong and continue to read
            // Assamble header: FINal 0x80 | Opcode 0x0A + Mask on 0x80 with zero payload
            //@socket_write($socket, chr(0x8A) . chr(0x80) . pack("N", rand(1,0x7FFFFFFF)));

            break;
        // pong frame:
        case 10:
            $decodedData['type'] = 'pong';
            break;
        default:
            // Close stream on unknown opcode:
            websocket_close($socket, 1003);
            break;
    }

    if ($opcode < 3)
    {
        $decodedData['payload'] = '';
        $dataLength = strlen($frame_data);
        if(!$masked) $decodedData['payload'] .= $frame_data;
        else
        {
            for ($i = 0; $i < $dataLength; $i++) 
            {
                $decodedData['payload'] .= $frame_data[$i] ^ $mask[$i % 4];
            }
        }        
    }

    return $decodedData;
}

function websocket_close ($socket, $statusCode = 1000)
{
    $payload = pack('n', $statusCode);
    $reason = null;
    $masked = true;

    switch ($statusCode) 
    {
        case 1000: //self::STATUS_CLOSE_NORMAL:
            $reason = 'normal closure';
            break;
        case 1001: //self::STATUS_CLOSE_GOING_AWAY:
            $reason = 'going away';
            break;
        case 1002: //self::STATUS_CLOSE_PROTOCOL_ERROR:
            $reason = 'protocol error';
            break;
        case 1003: //self::STATUS_CLOSE_UNSUPPORTED:
            $reason = 'unknown data (opcode)';
            break;
        case 1004:
            $reason = 'frame too large';
            break;
        case 1007:
            $reason = 'utf8 expected';
            break;
        case 1008:
            $reason = 'message violates server policy';
            break;
    }

    websocket_write($socket, websocket_hybi10Encode($socket, $payload . $reason, 'close', $masked));
}

//
function websocket_hybi10Encode($sp, $payload, $type = 'text', $masked = false)
{
    $frameHead = array();
    $frame = '';
    $payloadLength = strlen($payload);

    switch ($type) 
    {
        case 'text':
            // first byte indicates FIN, Text-Frame (10000001):
            $frameHead[0] = 129;
            break;
        case 'close':
            // first byte indicates FIN, Close Frame(10001000):
            $frameHead[0] = 136;
            break;
        case 'ping':
            // first byte indicates FIN, Ping frame (10001001):
            $frameHead[0] = 137;
            break;
        case 'pong':
            // first byte indicates FIN, Pong frame (10001010):
            $frameHead[0] = 138;
            break;
    }
    
    // set mask and payload length (using 1, 3 or 9 bytes)
    if ($payloadLength > 65535) 
    {
        $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
        $frameHead[1] = ($masked === true) ? 255 : 127;

        for ($i = 0; $i < 8; $i++) $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);

        // most significant bit MUST be 0 (close connection if frame too big)
        if ($frameHead[2] > 127) 
        {
            websocket_close($socket, 1004);
            return false;
        }
    } 
    elseif ($payloadLength > 125) 
    {
        $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
        $frameHead[1] = ($masked === true) ? 254 : 126;
        $frameHead[2] = bindec($payloadLengthBin[0]);
        $frameHead[3] = bindec($payloadLengthBin[1]);
    } 
    else 
    {
        $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
    }
    
    // convert frame-head to string:
    foreach (array_keys($frameHead) as $i) $frameHead[$i] = chr($frameHead[$i]);

    if ($masked === true) 
    {
        // generate a random mask:
        $mask = array();
        for ($i = 0; $i < 4; $i++) $mask[$i] = chr(rand(0, 255));
        $frameHead = array_merge($frameHead, $mask);
    }
    
    $frame = implode('', $frameHead);
    
    // append payload to frame:
    $framePayload = array();

    for ($i = 0; $i < $payloadLength; $i++) $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];

    return $frame;
}

?>