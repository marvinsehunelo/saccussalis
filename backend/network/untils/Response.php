<?php
function iso_response($code, $message, $extra = [])
{
    return array_merge([
        "code" => $code,
        "message" => $message,
        "timestamp" => date('c')
    ], $extra);
}
