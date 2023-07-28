<?php
include_once __DIR__. '/vendor/autoload.php';
spl_autoload_register(function ($class_name) {

    $classs = explode('\\', $class_name);
    
    if (current($classs) != 'Payment')
        return;
    array_shift($classs);
    
    include_once DIR_SYSTEM . '/library/payment/'. (implode('/', $classs)) . '.php';
});
