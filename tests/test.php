<?php

require dirname(__DIR__) . '/./vendor/autoload.php';

function ip()
{
    $validator = new \MixPlus\Validation\Validator([
        'ip' => 'required|ipv4'
    ]);

    $check = $validator->validate([
        'ip' => '127.0.0.1.123'
    ]);

    var_dump($check);
}

function dd(...$var)
{
    var_dump($var);
    exit();
}

ip();
