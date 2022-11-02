<?php

namespace MixPlus\Validation;

use InvalidArgumentException;
use function array_map;
use function count;
use function explode;
use function implode;

class ValidationPair
{
    /**
     * @var array
     */
    public $patternParts;

    /**
     * @var ValidationRuleset
     */
    public $ruleset;

    public function __construct(
        string            $pattern,
        ValidationRuleset $ruleset
    )
    {
        $this->ruleset = $ruleset;
        /* explode pattern and trim all parts of pattern */
        $patternParts = array_map('trim', explode('.', $pattern));
        /* Check if pattern is valid */
        if (count($patternParts) === 0) {
            throw new InvalidArgumentException('Unable to solve empty pattern');
        }
        foreach ($patternParts as $part) {
            if ($part === '') {
                throw new InvalidArgumentException("Invalid pattern '{$pattern}'");
            }
        }
        $this->patternParts = $patternParts;
    }
}