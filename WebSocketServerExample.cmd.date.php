<?php

    class Date extends Thread
    {
        private $session = null;
        private $arguments = null;
        private $result = null;

        function __construct ($session, $arguments)
        {
            $this->session = $session;
            $this->arguments = trim($arguments);

            //
            if(empty($this->arguments)) $this->arguments = null;
        }

        public function run ()
        {
            $this->result = json_encode(array
                (
                    'id'=>'date',
                    'arguments'=>$this->arguments,
                    'data'=>date('Y.m.d H:i:s'),
                ));
        }

        public function getResult ()
        {
            return $this->result;
        }
    }

?>
