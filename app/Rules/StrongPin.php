<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * PIN policy for the partner app.
 *
 * A 6-digit PIN is 1,000,000 combinations, but the ones humans pick are not
 * uniformly distributed — sequences, repeats and dates account for a large
 * share of real-world choices, and against a shared-device threat model a
 * guessable PIN is the realistic compromise rather than a brute-force run.
 *
 * Rejected:
 *   - anything shorter than the configured minimum (6) or not all digits
 *   - all one digit          111111
 *   - ascending/descending runs  123456 / 654321
 *   - a repeated short block     121212 / 123123
 *
 * Deliberately NOT rejecting dates (e.g. 070895): birthdays are weak, but the
 * check produces false positives on legitimate PINs and this list has to stay
 * explainable to a non-technical partner in a one-line error message.
 */
class StrongPin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pin = (string) $value;
        $min = (int) config('partner.pin.min_length', 6);
        $max = (int) config('partner.pin.max_length', 10);

        if (! preg_match('/^\d+$/', $pin)) {
            $fail('Your PIN must be numbers only.');

            return;
        }

        if (strlen($pin) < $min) {
            $fail("Your PIN must be at least {$min} digits.");

            return;
        }

        if (strlen($pin) > $max) {
            $fail("Your PIN can be at most {$max} digits.");

            return;
        }

        if (preg_match('/^(\d)\1+$/', $pin)) {
            $fail('Your PIN cannot be the same digit repeated.');

            return;
        }

        if ($this->isRun($pin)) {
            $fail('Your PIN cannot be a simple sequence like 123456.');

            return;
        }

        if ($this->isRepeatedBlock($pin)) {
            $fail('Your PIN cannot be a short pattern repeated, like 121212.');
        }
    }

    /** 123456 / 654321 — each digit exactly one more or one less than the last. */
    private function isRun(string $pin): bool
    {
        foreach ([1, -1] as $step) {
            $isRun = true;

            for ($i = 1, $len = strlen($pin); $i < $len; $i++) {
                if ((int) $pin[$i] - (int) $pin[$i - 1] !== $step) {
                    $isRun = false;
                    break;
                }
            }

            if ($isRun) {
                return true;
            }
        }

        return false;
    }

    /** 121212 (block of 2) or 123123 (block of 3) — any block tiling the PIN. */
    private function isRepeatedBlock(string $pin): bool
    {
        $len = strlen($pin);

        for ($block = 1; $block <= intdiv($len, 2); $block++) {
            if ($len % $block !== 0) {
                continue;
            }

            if (str_repeat(substr($pin, 0, $block), intdiv($len, $block)) === $pin) {
                return true;
            }
        }

        return false;
    }
}
