<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Tiny pydantic-v2-like validator so request validation and 422 payloads
 * keep the FastAPI shape: {"detail": [{type, loc, msg, input}]}.
 *
 * Field spec: either a type string ('str', 'int', 'float', 'bool', 'email',
 * 'list', 'dict', 'any'; prefix '?' = nullable) meaning "required", or an array:
 *   ['str', 'default' => '', 'min' => 1, 'max' => 10, 'item' => <spec|fields>, 'fields' => [...]]
 * 'default' present => optional.
 */
class Validate
{
    private array $errors = [];

    /** @return array{0: array, 1: array<string,bool>} [data, keysPresentInInput] */
    public static function body(Request $request, array $fields): array
    {
        $v = new self;
        $raw = $request->getContent();
        if (trim($raw) === '') {
            throw new ValidationFailed([[
                'type' => 'missing', 'loc' => ['body'], 'msg' => 'Field required', 'input' => null,
            ]]);
        }
        $input = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidationFailed([[
                'type' => 'json_invalid', 'loc' => ['body', 0], 'msg' => 'JSON decode error',
                'input' => new \stdClass, 'ctx' => ['error' => json_last_error_msg()],
            ]]);
        }
        $data = $v->model($input, $fields, ['body']);
        if ($v->errors) {
            throw new ValidationFailed($v->errors);
        }

        return [$data, is_array($input) ? array_fill_keys(array_keys($input), true) : []];
    }

    /** Validate query parameters (FastAPI Query semantics). */
    public static function query(Request $request, array $fields): array
    {
        $v = new self;
        $out = [];
        foreach ($fields as $name => $spec) {
            $spec = is_array($spec) ? $spec : [$spec];
            $type = ltrim($spec[0], '?');
            $has = $request->query->has($name);
            if (! $has) {
                if (! array_key_exists('default', $spec)) {
                    $v->errors[] = ['type' => 'missing', 'loc' => ['query', $name], 'msg' => 'Field required', 'input' => null];
                } else {
                    $out[$name] = $spec['default'];
                }

                continue;
            }
            $value = $request->query($name);
            if (is_array($value)) {
                $value = end($value);
            }
            $out[$name] = $v->scalar($value, $type, $spec, ['query', $name], true);
        }
        if ($v->errors) {
            throw new ValidationFailed($v->errors);
        }

        return $out;
    }

    private function model(mixed $input, array $fields, array $loc): array
    {
        if (! is_array($input) || (array_is_list($input) && $input !== [])) {
            $this->errors[] = [
                'type' => 'model_attributes_type', 'loc' => $loc,
                'msg' => 'Input should be a valid dictionary or object to extract fields from', 'input' => $input,
            ];

            return [];
        }
        $out = [];
        foreach ($fields as $name => $spec) {
            $spec = is_array($spec) ? $spec : [$spec];
            if (! array_key_exists($name, $input)) {
                if (array_key_exists('default', $spec)) {
                    $out[$name] = $spec['default'];
                } else {
                    $this->errors[] = ['type' => 'missing', 'loc' => [...$loc, $name], 'msg' => 'Field required', 'input' => $input];
                }

                continue;
            }
            $out[$name] = $this->value($input[$name], $spec, [...$loc, $name]);
        }

        return $out;
    }

    private function value(mixed $value, array $spec, array $loc): mixed
    {
        $typeSpec = $spec[0];
        $nullable = str_starts_with($typeSpec, '?');
        $type = ltrim($typeSpec, '?');
        if ($value === null) {
            if ($nullable || $type === 'any') {
                return null;
            }
        }
        switch ($type) {
            case 'list':
                if (! is_array($value) || ! array_is_list($value)) {
                    $this->errors[] = ['type' => 'list_type', 'loc' => $loc, 'msg' => 'Input should be a valid list', 'input' => $value];

                    return null;
                }
                if (! isset($spec['item'])) {
                    return $value;
                }
                $res = [];
                foreach ($value as $i => $el) {
                    $item = $spec['item'];
                    if (isset($item['fields'])) {
                        $res[] = $this->model($el, $item['fields'], [...$loc, $i]);
                    } else {
                        $res[] = $this->value($el, is_array($item) ? $item : [$item], [...$loc, $i]);
                    }
                }

                return $res;
            case 'model':
                return $this->model($value, $spec['fields'], $loc);
            case 'dict':
                if (! is_array($value) || (array_is_list($value) && $value !== [])) {
                    $this->errors[] = ['type' => 'dict_type', 'loc' => $loc, 'msg' => 'Input should be a valid dictionary', 'input' => $value];

                    return null;
                }

                return $value;
            case 'any':
                return $value;
            default:
                return $this->scalar($value, $type, $spec, $loc, false);
        }
    }

    private function scalar(mixed $value, string $type, array $spec, array $loc, bool $fromQuery): mixed
    {
        switch ($type) {
            case 'str':
                if (! is_string($value)) {
                    $this->errors[] = ['type' => 'string_type', 'loc' => $loc, 'msg' => 'Input should be a valid string', 'input' => $value];

                    return null;
                }
                $len = mb_strlen($value);
                if (isset($spec['min']) && $len < $spec['min']) {
                    $n = $spec['min'];
                    $this->errors[] = ['type' => 'string_too_short', 'loc' => $loc,
                        'msg' => "String should have at least $n character".($n == 1 ? '' : 's'), 'input' => $value, 'ctx' => ['min_length' => $n]];
                }
                if (isset($spec['max']) && $len > $spec['max']) {
                    $n = $spec['max'];
                    $this->errors[] = ['type' => 'string_too_long', 'loc' => $loc,
                        'msg' => "String should have at most $n character".($n == 1 ? '' : 's'), 'input' => $value, 'ctx' => ['max_length' => $n]];
                }

                return $value;
            case 'email':
                if (! is_string($value)) {
                    $this->errors[] = ['type' => 'string_type', 'loc' => $loc, 'msg' => 'Input should be a valid string', 'input' => $value];

                    return null;
                }
                [$norm, $reason] = self::checkEmail($value);
                if ($norm === null) {
                    $this->errors[] = ['type' => 'value_error', 'loc' => $loc,
                        'msg' => 'value is not a valid email address: '.$reason, 'input' => $value, 'ctx' => ['reason' => $reason]];

                    return null;
                }

                return $norm;
            case 'int':
                $int = null;
                if (is_int($value)) {
                    $int = $value;
                } elseif (is_bool($value) && ! $fromQuery) {
                    $int = (int) $value;
                } elseif (is_float($value) && floor($value) == $value && is_finite($value)) {
                    $int = (int) $value;
                } elseif (is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value)) {
                    $int = (int) trim($value);
                }
                if ($int === null) {
                    $this->errors[] = ['type' => 'int_parsing', 'loc' => $loc,
                        'msg' => 'Input should be a valid integer, unable to parse string as an integer', 'input' => $value];

                    return null;
                }
                if (isset($spec['ge']) && $int < $spec['ge']) {
                    $this->errors[] = ['type' => 'greater_than_equal', 'loc' => $loc,
                        'msg' => 'Input should be greater than or equal to '.$spec['ge'], 'input' => $value, 'ctx' => ['ge' => $spec['ge']]];
                }
                if (isset($spec['le']) && $int > $spec['le']) {
                    $this->errors[] = ['type' => 'less_than_equal', 'loc' => $loc,
                        'msg' => 'Input should be less than or equal to '.$spec['le'], 'input' => $value, 'ctx' => ['le' => $spec['le']]];
                }

                return $int;
            case 'float':
                if (is_int($value) || is_float($value)) {
                    return (float) $value;
                }
                if (is_bool($value)) {
                    return (float) $value;
                }
                if (is_string($value) && is_numeric(trim($value))) {
                    return (float) trim($value);
                }
                $this->errors[] = ['type' => 'float_parsing', 'loc' => $loc,
                    'msg' => 'Input should be a valid number, unable to parse string as a number', 'input' => $value];

                return null;
            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                if ($value === 0 || $value === 1) {
                    return (bool) $value;
                }
                if (is_string($value)) {
                    $l = strtolower(trim($value));
                    if (in_array($l, ['1', 'true', 't', 'yes', 'y', 'on'], true)) {
                        return true;
                    }
                    if (in_array($l, ['0', 'false', 'f', 'no', 'n', 'off'], true)) {
                        return false;
                    }
                }
                $this->errors[] = ['type' => 'bool_parsing', 'loc' => $loc,
                    'msg' => 'Input should be a valid boolean, unable to interpret input', 'input' => $value];

                return null;
        }

        return $value;
    }

    /** Normalized address (domain lower-cased) or null. */
    public static function normalizeEmail(string $value): ?string
    {
        return self::checkEmail($value)[0];
    }

    /**
     * Subset of python-email-validator rules and messages (check_deliverability=False).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function checkEmail(string $value): array
    {
        $parts = explode('@', $value);
        if (count($parts) === 1) {
            return [null, 'An email address must have an @-sign.'];
        }
        $domain = array_pop($parts);
        $local = implode('@', $parts);
        if ($local === '') {
            return [null, 'There must be something before the @-sign.'];
        }
        if ($domain === '') {
            return [null, 'There must be something after the @-sign.'];
        }
        if (str_contains($local, '@')) {
            return [null, 'The email address is not valid. It must have exactly one @-sign.'];
        }
        if (preg_match('/\s/u', $local)) {
            return [null, 'The email address contains invalid characters before the @-sign: SPACE.'];
        }
        if (! preg_match("/^[A-Za-z0-9!#$%&'*+\/=?^_`{|}~.\-\x{80}-\x{10FFFF}]+$/u", $local)) {
            return [null, 'The email address contains invalid characters before the @-sign.'];
        }
        if (str_starts_with($local, '.')) {
            return [null, 'An email address cannot start with a period.'];
        }
        if (str_ends_with($local, '.')) {
            return [null, 'An email address cannot have a period immediately before the @-sign.'];
        }
        if (str_contains($local, '..')) {
            return [null, 'An email address cannot have two periods in a row.'];
        }
        $domainL = mb_strtolower($domain);
        if (preg_match('/\s/u', $domainL) || ! preg_match('/^[a-z0-9.\-\x{80}-\x{10FFFF}]+$/u', $domainL)) {
            return [null, 'The part after the @-sign contains invalid characters.'];
        }
        if (str_starts_with($domainL, '.')) {
            return [null, 'An email address cannot have a period immediately after the @-sign.'];
        }
        if (str_ends_with($domainL, '.')) {
            return [null, 'An email address cannot end with a period.'];
        }
        if (str_contains($domainL, '..')) {
            return [null, 'An email address cannot have two periods in a row.'];
        }
        if (! str_contains($domainL, '.')) {
            return [null, 'The part after the @-sign is not valid. It should have a period.'];
        }

        return [$local.'@'.$domainL, null];
    }
}
