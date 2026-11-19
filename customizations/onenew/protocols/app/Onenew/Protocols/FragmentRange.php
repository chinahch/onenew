<?php

namespace App\Onenew\Protocols;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class FragmentRange implements ValidationRule
{
    public function __construct(private int $minimum, private int $maximum) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!TlsFragment::validRange($value, $this->minimum, $this->maximum)) {
            $fail('分片参数须为 '.$this->minimum.'–'.$this->maximum.' 范围内的整数或递增区间（例如 10-20）。');
        }
    }
}
