<?php

namespace MixPlus\Validation;

use Closure;
use InvalidArgumentException;
use SplFileInfo;
use SplPriorityQueue;

class ValidationRuleset
{
    protected const FLAG_SOMETIMES = 1 << 0;
    protected const FLAG_REQUIRED = 1 << 1;
    protected const FLAG_NULLABLE = 1 << 2;

    protected const PRIORITY_MAP = [
        'required' => 50,
        'numeric' => 100,
        'integer' => 100,
        'string' => 100,
        'array' => 100,
        'min' => 10,
        'max' => 10,
        'in' => 10,
        'alpha' => 10,
        'alpha_num' => 10,
        'alpha_dash' => 10,
        'ip' => 10,
        'ipv4' => 5,
        'ipv6' => 5,
        /* rule flags */
        'sometimes' => 0,
        'nullable' => 0,
        'bail' => 0,
    ];

    protected const IMPLICIT_DEPENDENCY_RULESET_MAP = [
        'alpha' => 'string',
        'alpha_num' => 'string',
        'alpha_dash' => 'string',
        'ip' => 'string',
    ];

    protected const TYPED_RULES = [
        /* note: integer should be in front of numeric,
        /* because it is more specific */
        'integer',
        'numeric',
        'string',
        'array'
    ];

    /** @var int base flags */
    protected $flags;

    /** @var ValidationRule[] */
    protected $rules;

    /** @var static[] */
    protected static $pool = [];

    /** @var Closure[] */
    protected static $closureCache = [];

    public static function make(string $ruleString)
    {
        $ruleMap = static::convertRuleStringToRuleMap($ruleString);
        $hash = static::getHashOfRuleMap($ruleMap);
        return static::$pool[$hash] ?? (static::$pool[$hash] = new static($ruleMap));
    }

    protected function __construct(array $ruleMap)
    {
        $flags = 0;
        $rules = [];

        foreach ($ruleMap as $rule => $ruleArgs) {
            switch ($rule) {
                case 'sometimes':
                    $flags |= static::FLAG_SOMETIMES;
                    break;
                case 'required':
                    $flags |= static::FLAG_REQUIRED;
                    if (!isset($ruleMap['numeric']) && !isset($ruleMap['integer'])) {
                        $rules[] = ValidationRule::make('required', static::getClosure('validateRequired' . static::fetchTypedRule($ruleMap)));
                    }
                    break;
                case 'nullable':
                    $flags |= static::FLAG_NULLABLE;
                    break;
                case 'numeric':
                    if (isset($ruleMap['array'])) {
                        throw new InvalidArgumentException("Rule 'numeric' conflicts with 'array'");
                    }
                    $rules[] = ValidationRule::make('numeric', static::getClosure('validateNumeric'));
                    break;
                case 'integer':
                    $rules[] = ValidationRule::make('integer', static::getClosure('validateInteger'));
                    break;
                case 'string':
                    if (isset($ruleMap['array'])) {
                        throw new InvalidArgumentException("Rule 'string' conflicts with 'array'");
                    }
                    $rules[] = ValidationRule::make('string', static::getClosure('validateString'));
                    break;
                case 'array':
                    $rules[] = ValidationRule::make('array', static::getClosure('validateArray'));
                    break;
                case 'min':
                case 'max':
                    if (count($ruleArgs) !== 1) {
                        throw new InvalidArgumentException("Rule '{$rule}' require 1 parameter");
                    }
                    if (!is_numeric($ruleArgs[0])) {
                        throw new InvalidArgumentException("Rule '{$rule}' require numeric parameters");
                    }
                    $ruleArgs[0] += 0;
                    $name = "{$rule}:{$ruleArgs[0]}";
                    $methodPart = $rule === 'min' ? 'Min' : 'Max';
                    $rules[] = ValidationRule::make($name, static::getClosure("validate{$methodPart}" . static::fetchTypedRule($ruleMap)), $ruleArgs);
                    break;
                case 'in':
                    if (count($ruleArgs) === 0) {
                        throw new InvalidArgumentException("Rule '{$rule}' require 1 parameter at least");
                    }
                    $name = static::implodeFullRuleName($rule, $ruleArgs);
                    $suffix = isset($ruleMap['array']) ? 'Array' : '';
                    if (count($ruleArgs) <= 5) {
                        $rules[] = ValidationRule::make($name, static::getClosure('validateInList' . $suffix), [$ruleArgs]);
                    } else {
                        $ruleArgsMap = [];
                        foreach ($ruleArgs as $ruleArg) {
                            $ruleArgsMap[$ruleArg] = true;
                        }
                        $rules[] = ValidationRule::make($name, static::getClosure('validateInMap' . $suffix), [$ruleArgsMap]);
                    }
                    break;
                case 'alpha':
                case 'alpha_num':
                case 'alpha_dash':
                    $rules[] = ValidationRule::make($rule, static::getClosure('validate' . static::upperCamelize($rule)));
                    break;
                case 'ip':
                case 'ipv4':
                case 'ipv6':
                    $rules[] = ValidationRule::make($rule, static::getClosure('validate' . strtoupper($rule)));
                    break;
                case 'bail':
                    /* compatibility */
                    break;
                default:
                    throw new InvalidArgumentException("Unknown rule '{$rule}'");
            }
        }

        $this->flags = $flags;
        $this->rules = $rules;
    }

    public function isDefinitelyRequired(): bool
    {
        return ($this->flags & static::FLAG_REQUIRED) && !($this->flags & static::FLAG_SOMETIMES);
    }

    /**
     * @return ValidationRule[]
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * @return string[] Error attribute names
     */
    public function check($data): array
    {
        if (($this->flags & static::FLAG_NULLABLE) && $data === null) {
            return [];
        }

        $errors = [];

        foreach ($this->rules as $rule) {
            $closure = $rule->closure;
            $valid = $closure($data, ...$rule->args);
            if (!$valid) {
                $errors[] = $rule->name;
                /* Always bail here, for example:
                 * if we have a rule like `integer|max:255`,
                 * then user input a string `x`, it's not even an integer,
                 * continue to check its' length is meaningless,
                 * in Laravel validation, it may violate `max:255` when
                 * string length is longer than 255, how fool it is. */
                break;
            }
        }

        return $errors;
    }

    protected static function getHashOfRuleMap(array $ruleMap): string
    {
        $hashSlots = [];
        ksort($ruleMap);
        foreach ($ruleMap as $rule => $ruleArgs) {
            if ($ruleArgs === []) {
                $hashSlots[] = $rule;
            } else {
                $hashSlots[] = sprintf('%s:%s', $rule, implode(', ', $ruleArgs));
            }
        }
        return implode('|', $hashSlots);
    }

    protected static function convertRuleStringToRuleMap(string $ruleString, bool $solvePriority = true): array
    {
        $rules = array_map('trim', explode('|', $ruleString));

        $tmpRuleMap = [];
        foreach ($rules as $rule) {
            $ruleParts = explode(':', $rule, 2);
            $rule = strtolower(trim($ruleParts[0]));
            if (!isset(static::PRIORITY_MAP[$rule])) {
                throw new InvalidArgumentException("Unknown rule '{$rule}'");
            }
            if (($ruleParts[1] ?? '') !== '') {
                $ruleArgs = array_map('trim', explode(',', $ruleParts[1]));
            } else {
                $ruleArgs = [];
            }
            $tmpRuleMap[$rule] = $ruleArgs;
        }
        foreach ($tmpRuleMap as $rule => $_) {
            $implicitDependencyRuleset = static::IMPLICIT_DEPENDENCY_RULESET_MAP[$rule] ?? null;
            if ($implicitDependencyRuleset === null) {
                continue;
            }
            $extraRuleMap = static::convertRuleStringToRuleMap($implicitDependencyRuleset, false);
            foreach ($extraRuleMap as $extraRule => $extraRuleArgs) {
                if (!isset($tmpRuleMap[$extraRule])) {
                    $tmpRuleMap[$extraRule] = $extraRuleArgs;
                }
            }
        }

        if (!$solvePriority) {
            return $tmpRuleMap;
        }

        $ruleQueue = new SplPriorityQueue();
        foreach ($tmpRuleMap as $rule => $ruleArgs) {
            $ruleQueue->insert([$rule, $ruleArgs], static::PRIORITY_MAP[$rule]);
        }
        $ruleMap = [];
        while (!$ruleQueue->isEmpty()) {
            [$rule, $ruleArgs] = $ruleQueue->extract();
            if (isset($ruleMap[$rule])) {
                throw new InvalidArgumentException("Duplicated rule '{$rule}' in ruleset '{$ruleString}'");
            }
            $ruleMap[$rule] = $ruleArgs;
        }

        return $ruleMap;
    }

    protected static function upperCamelize(string $uncamelized_words, string $separator = '_'): string
    {
        $uncamelized_words = str_replace($separator, ' ', strtolower($uncamelized_words));
        return ltrim(str_replace(' ', '', ucwords($uncamelized_words)), $separator);
    }

    protected static function fetchTypedRule(array $ruleMap): string
    {
        foreach (static::TYPED_RULES as $typedRule) {
            if (isset($ruleMap[$typedRule])) {
                return static::upperCamelize($typedRule);
            }
        }

        return '';
    }

    protected static function implodeFullRuleName(string $rule, array $ruleArgs): string
    {
        if (count($ruleArgs) === 0) {
            return $rule;
        }

        return $rule . ':' . implode(',', $ruleArgs);
    }

    protected static function getClosure(string $method): Closure
    {
        return static::$closureCache[$method] ??
            (static::$closureCache[$method] = Closure::fromCallable([static::class, $method]));
    }

    protected static function validateRequired($value): bool
    {
        if (is_null($value)) {
            return false;
        }
        if (is_string($value) && ($value === '' || ctype_space($value))) {
            return false;
        }
        if (is_array($value) && count($value) === 0) {
            return false;
        }
        if ($value instanceof SplFileInfo) {
            return $value->getPath() !== '';
        }

        return true;
    }

    protected static function validateRequiredString($value): bool
    {
        return $value !== '' && !ctype_space($value);
    }

    protected static function validateRequiredArray(array $value): bool
    {
        return count($value) !== 0;
    }

    protected static function validateRequiredFile(SplFileInfo $value): bool
    {
        return $value->getPath() !== '';
    }

    protected static function validateNumeric(&$value): bool
    {
        if (!is_numeric($value)) {
            return false;
        }
        $value += 0;
        return true;
    }

    protected static function validateInteger(&$value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return false;
        }
        $value = (int)$value;
        return true;
    }

    protected static function validateString($value): bool
    {
        return is_string($value);
    }

    protected static function validateArray($value): bool
    {
        return is_array($value);
    }

    protected static function getLength($value): int
    {
        if (is_numeric($value)) {
            return $value + 0;
        }

        if (is_string($value)) {
            return mb_strlen($value);
        }

        if (is_array($value)) {
            return count($value);
        }

        if ($value === null) {
            return 0;
        }

        if ($value instanceof SplFileInfo) {
            return $value->getSize();
        }

        return mb_strlen((string)$value);
    }

    protected static function validateMin($value, $min): bool
    {
        // TODO: file min support b, kb, mb, gb ...
        return static::getLength($value) >= $min;
    }

    protected static function validateMax($value, $max): bool
    {
        // TODO: file max support b, kb, mb, gb ...
        return static::getLength($value) <= $max;
    }

    protected static function validateMinInteger(int $value, $min): bool
    {
        return $value >= $min;
    }

    protected static function validateMaxInteger(int $value, $max): bool
    {
        return $value <= $max;
    }

    protected static function validateMinNumeric($value, $min): bool
    {
        return $value >= $min;
    }

    protected static function validateMaxNumeric($value, $max): bool
    {
        return $value <= $max;
    }

    protected static function validateMinString(string $value, $min): bool
    {
        return mb_strlen($value) >= $min;
    }

    protected static function validateMaxString(string $value, $max): bool
    {
        return mb_strlen($value) <= $max;
    }

    protected static function validateInList($value, array $list): bool
    {
        if (!is_array($value)) {
            return in_array((string)$value, $list, true);
        }

        return static::validateInListArray($value, $list);
    }

    protected static function validateInListArray(array $value, array $list): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                return false;
            }
            if (!in_array((string)$item, $list, true)) {
                return false;
            }
        }
        return true;
    }

    protected static function validateInMap($value, array $map): bool
    {
        if (!is_array($value)) {
            return $map[(string)$value] ?? false;
        }

        return static::validateInMapArray($value, $map);
    }

    protected static function validateInMapArray(array $value, array $map): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                return false;
            }
            if (!isset($map[(string)$item])) {
                return false;
            }
        }
        return true;
    }

    public static function validateAlpha(string $value): bool
    {
        return preg_match('/^[\pL\pM]+$/u', $value);
    }

    public static function validateAlphaNum(string $value): bool
    {
        return preg_match('/^[\pL\pM\pN]+$/u', $value) > 0;
    }

    public static function validateAlphaDash(string $value): bool
    {
        return preg_match('/^[\pL\pM\pN_-]+$/u', $value) > 0;
    }

    public static function validateIP(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    public static function validateIPV4(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public static function validateIPV6(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }
}