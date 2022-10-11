<?php

namespace App\Classes;

use \ccxt\Exchange as Exchange;

class Ccxt extends Exchange
{

    public function __construct($options = array())
    {
        parent::__construct($options);
        date_default_timezone_set('UTC');
    }

}