<?php

class Session
{
    private static $_instance;

    public function __construct()
    {
        session_start();
          $_SESSION["logged_in"] = true;
    }

    public static function getInstance()
    {
        if(is_null(self::$_instance)) {
            self::$_instance = new Session();
        }

        return self::$_instance;
    }
}

?>
