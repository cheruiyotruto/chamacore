<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;

/**
 * Shared validation for signatures drawn on a canvas and submitted as a PNG
 * data URI. The membership application and guarantee acceptance flows use the
 * same rules so an invalid image can never be written to the EDMS.
 */
final class SignatureCapture
{
    public const MAX_LENGTH = 2_000_000;

    public const MAX_BINARY_BYTES = 1_500_000;

    private const PATTERN = '/^data:image\/png;base64,([A-Za-z0-9+\/=\r\n]+)$/';

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public const RULES = [
        'string',
        'max:'.self::MAX_LENGTH,
        'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=\r\n]+$/',
    ];

    /**
     * Rules for an input that may be omitted, such as a guarantee rejection.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['nullable', ...self::RULES];
    }

    public static function requireValid(?string $signature): string
    {
        if ($signature === null || trim($signature) === '') {
            throw new BusinessRuleException('A signature is required to accept this guarantee.');
        }

        return self::decode($signature);
    }

    public static function decode(string $signature): string
    {
        if (strlen($signature) > self::MAX_LENGTH) {
            throw new BusinessRuleException('The signature image is too large.');
        }

        if (! preg_match(self::PATTERN, $signature, $matches)) {
            throw new BusinessRuleException('The signature must be a valid PNG image.');
        }

        $binary = base64_decode($matches[1], true);

        if ($binary === false || $binary === '') {
            throw new BusinessRuleException('The signature image could not be decoded.');
        }

        if (! str_starts_with($binary, self::PNG_SIGNATURE)) {
            throw new BusinessRuleException('The signature must be a valid PNG image.');
        }

        if (strlen($binary) > self::MAX_BINARY_BYTES) {
            throw new BusinessRuleException('The signature image is too large.');
        }

        return $binary;
    }
}
