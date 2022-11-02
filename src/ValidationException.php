<?php

namespace MixPlus\Validation;

class ValidationException extends \Exception
{
    /**
     * @var array
     */
    protected $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct('The given data was invalid');
    }

    public function errors(): array
    {
        return $this->errors;
    }
}