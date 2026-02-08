<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Auth;

final class TotpService
{
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!is_string($code) || !preg_match('/^[0-9]{6}$/', $code)) {
            return false;
        }

        $timeSlice = (int) floor(time() / 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->at($secret, $timeSlice + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function at(string $secret, int $timeSlice): string
    {
        $secretKey = $this->base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hmac = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hmac, -1)) & 0x0F;
        $chunk = substr($hmac, $offset, 4);
        $value = unpack('N', $chunk)[1] & 0x7FFFFFFF;
        $mod = $value % 1000000;

        return str_pad((string) $mod, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');

        $bits = '';
        $decoded = '';

        for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
            $value = strpos($alphabet, $secret[$i]);
            if ($value === false) {
                continue;
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        for ($i = 0, $len = strlen($bits); $i + 8 <= $len; $i += 8) {
            $decoded .= chr(bindec(substr($bits, $i, 8)));
        }

        return $decoded;
    }
}
