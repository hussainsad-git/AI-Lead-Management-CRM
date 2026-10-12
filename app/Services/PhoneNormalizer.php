<?php

namespace App\Services;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Normalizes phone input to E.164. National-format numbers are interpreted in the given
 * region (falls back to the organization default). Numbers that do not validate are
 * reported as invalid rather than guessed — the raw value is always preserved by callers.
 */
class PhoneNormalizer
{
    public static function make(): self
    {
        return new self;
    }

    /** @return array{e164: ?string, region: ?string, valid: bool, raw: string} */
    public function normalize(?string $raw, string $defaultRegion = 'PK'): array
    {
        $raw = trim((string) $raw);
        $result = ['e164' => null, 'region' => null, 'valid' => false, 'raw' => $raw];
        if ($raw === '') {
            return $result;
        }
        $input = preg_replace('/^00(?=[1-9])/', '+', preg_replace('/[^\d+]/', '', $raw) ?? '') ?? '';
        // A bare international number without "+" (e.g. "923001234567" from spreadsheets)
        if ($input !== '' && $input[0] !== '+' && strlen($input) >= 11 && ! str_starts_with($input, '0')) {
            $candidate = $this->tryParse('+'.$input, $defaultRegion);
            if ($candidate['valid']) {
                return $candidate + ['raw' => $raw];
            }
        }
        $parsed = $this->tryParse($input, $defaultRegion);

        return ['raw' => $raw] + $parsed;
    }

    private function tryParse(string $input, string $region): array
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($input, strtoupper($region));
            $valid = $util->isValidNumber($number);

            return [
                'e164' => $valid ? $util->format($number, PhoneNumberFormat::E164) : null,
                'region' => $valid ? $util->getRegionCodeForNumber($number) : null,
                'valid' => $valid,
            ];
        } catch (NumberParseException) {
            return ['e164' => null, 'region' => null, 'valid' => false];
        }
    }
}
