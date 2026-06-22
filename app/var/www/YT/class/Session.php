<?php

class Session
{
    private static ?Session $_instance = null;

    private function __construct()
    {
        session_start();
    }

    public static function getInstance(): Session
    {
        if (self::$_instance === null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }
}