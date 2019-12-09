#!/usr/bin/env php
<?php

    declare(ticks = 1);

    // Allow to start out server only from cli
    if(php_sapi_name() != 'cli') exit(header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true, 404));

    // Some constants
    define('ROOT', dirname(__FILE__));

    // Include our WebSocketServer class
    require_once(ROOT . '/../libs/WebSocketServer.php');

    // Some helpful function
    function microtime_float()
    {
        list($usec, $sec) = explode(' ', microtime());
        return ((float)$usec + (float)$sec);
    }

    // Our Gatway class (WebSocket server)
    class Gateway extends WebSocketServer
    {
        public $close = false;
        private $threads = array();

        // A client has connected to our websocket server
        protected function onConnect ()
        {
            $this->sendText(json_encode(array('id'=>'hello', 'data'=>$this->uniqid)));
        }

        //
        protected function onClose ($code, $reason)
        {
            $this->sendText(json_encode(array('id'=>'bye', 'data'=>'Good bye')));
            $this->close = true;
        }

        // Got text frame from client
        protected function gotText($text)
        {
            $params = explode (' ', $text, 2);
            $command = preg_replace('/[^a-z0-9]+/i', '', strtolower(trim(array_shift($params))));
            $className = ucfirst($command);

            // Check if requestet command is exist and return error if not
            if(file_exists(ROOT . "/commands/api.cmd.{$command}.php") == false)
            {
                $this->sendText(json_encode(array('id'=>$command, 'error'=>'Unknown command')));
            }
            else
            {
                // Include command class if it was not loaded before
                if(class_exists($className) == false) require_once(ROOT . "/commands/api.cmd.{$command}.php");

                // Create command thread and start it
                $thread = new $className((object)array(), array_shift($params));
                $thread->start();

                // Store new thread info in our threads stack
                $this->threads[] = array('id'=>$command, 'thread'=>$thread);
            }
        }

        // Process finished threads
        public function processThreads ($limit = 999)
        {
            $reIndex = false;

            foreach($this->threads as $idx => $item)
            {
                if(isset($item['thread']) && $item['thread'] instanceOf Thread && $item['thread']->isRunning() == false)
                {
                    $result = $this->threads[$idx]['thread']->getResult();
                    if(is_string($result)) $this->sendText($result);
                    unset($this->threads[$idx]);
                    $reIndex = true;
                    $limit--;
                }
                if($limit <= 0) break;
            }

            if($reIndex) $this->threads = array_values($this->threads);
        }
    }

    //
    $exit         = false;
    $ping         = false;
    $gateway      = new Gateway(STDIN, STDOUT);
    $loopStart    = microtime_float();
    $lastActivity = microtime(true);

    // Our server loop
    while($exit == false && $gateway->close == false)
    {
        // Its time to stop our server
        if(feof(STDIN) || feof(STDOUT)) break;

        // Lets try to read new WebSocket frame
        if($gateway->readFrame())
        {
            // We got PONG request
            if($gateway->lastFrame['opcode'] == 10) $ping = false;

            // Process prame
            $gateway->processFrame();

            // Store last activity time in case its not a PING frame
            if($ping == false) $lastActivity = microtime(true);
        }

        // Process commands result and limit it to max 3 results at one time
        $gateway->processThreads(3);

        // Lets ping our client if last activity was more than 30 sec ago
        if((microtime(true) - $lastActivity) >= 30)
        {
            // Did not receive PONG frame
            if($ping) break;

            /// Store last activity time
            $lastActivity = microtime(true);

            // Lets send PING request to client and wait for PONG fram back
            $gateway->sendPing();
            $ping = true;
        }

        // Calculate loop execution time
        $loopTime = microtime_float() - $loopStart;

        // Let it sleep a bit and have a rest
        if($loopTime < (250 * 100)) usleep((250 * 100) - $loopTime);

        // Store new loop start time
        $loopStart = microtime_float();
    }

?>