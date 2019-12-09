<?php

    // Some constants
    define('ROOT', dirname(__FILE__));

    // Include our WebSocketServer class
    require_once(ROOT . '/WebSocketClient.php');

    //
    $haSocket = @websocket_open('websocket.home.lan', 8080, '/api/websocket', '', $errstr);
    $haResponse = @websocket_read($haSocket, 0.1, $errstr);

    // Check if we got response from websocket server
    if(empty($haResponse) == false && isset($haResponse['type']))
    {
        // Process websocket server response
        switch($haResponse['type'])
        {
            case 'close':
            {
                @socket_close($haSocket);
                break;
            }
            case 'text':
            {
                var_dump($haResponse['payload']);
                break;
            }
        }
    }

    // Send hello world message
    websocket_write($haSocket, 'Hello world');

    // Close socket
    @socket_close($haSocket);

?>