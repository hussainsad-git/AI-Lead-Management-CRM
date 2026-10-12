<?php

namespace Tests\Unit;

use App\Services\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            'PK national' => ['0300 1234567', 'PK', '+923001234567'],
            'PK no plus' => ['923001234567', 'PK', '+923001234567'],
            'UK mobile' => ['07911 123456', 'GB', '+447911123456'],
            'US' => ['(201) 555-0123', 'US', '+12015550123'],
            'Saudi' => ['050 123 4567', 'SA', '+966501234567'],
            'UAE 00 prefix' => ['00971 50 123 4567', 'PK', '+971501234567'],
            'international overrides region' => ['+44 7911 123456', 'PK', '+447911123456'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_normalizes(string $raw, string $region, string $e164): void
    {
        $r = (new PhoneNormalizer)->normalize($raw, $region);
        $this->assertTrue($r['valid']);
        $this->assertSame($e164, $r['e164']);
        $this->assertSame($raw, $r['raw']);
    }

    public function test_invalid_numbers_are_not_guessed(): void
    {
        $r = (new PhoneNormalizer)->normalize('12345', 'PK');
        $this->assertFalse($r['valid']);
        $this->assertNull($r['e164']);
    }
}
