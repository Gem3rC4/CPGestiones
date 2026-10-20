<?php

namespace App\Exceptions;

class BusinessException extends \RuntimeException
{
    public function __construct(string $message, public string $errorCode = 'BUSINESS_RULE', public int $status = 409)
    {
        parent::__construct($message);
    }
}
