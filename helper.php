<?php

/**
 * Helper
 */
function generateRandomString($length = 10) 
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
    $charactersLength = strlen($characters);
    $randomString = '';

    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    
    return $randomString;
}

function response($param) 
{
    header('Content-Type: application/json');
    echo json_encode($param);
    exit;
}