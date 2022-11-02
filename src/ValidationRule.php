<?php

namespace MixPlus\Validation;

use Closure;
use InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;
use function serialize;

class ValidationRule
{
    /** @var static[] */
    protected static $pool = [];

    /**
     * @var string
     */
    public $name;

    /**
     * @var Closure
     */
    public $closure;

    /**
     * @var array
     */
    public $args;

    public static function make(string $name, Closure $closure, array $args = [])
    {
        try {
            $methodName = (new ReflectionFunction($closure))->getName();
        } catch (ReflectionException $exception) {
            throw new InvalidArgumentException('Invalid validation attribute closure');
        }
        $hash = $args === [] ? $methodName : $methodName . ':' . serialize($args);
        return static::$pool[$hash] ?? (static::$pool[$hash] = new static($name, $closure, $args));
    }

    protected function __construct(
        string  $name,
        Closure $closure,
        array   $args = []
    )
    {
        $this->name = $name;
        $this->closure = $closure;
        $this->args = $args;
    }
}