<?php
declare(strict_types=1);

namespace App\Core;

class Crypt
{
    public const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const KEY_BYTES = 32;
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_BYTES));
    }

    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);
        if ($cipher === false) {
            throw new \RuntimeException('Η κρυπτογράφηση απέτυχε.');
        }

        return self::PREFIX . base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if (!self::isEncrypted($stored)) {
            return $stored;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Το κρυπτογραφημένο κείμενο είναι κατεστραμμένο.');
        }

        $plain = openssl_decrypt(
            substr($raw, self::NONCE_BYTES + self::TAG_BYTES),
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_BYTES),
            substr($raw, self::NONCE_BYTES, self::TAG_BYTES)
        );
        if ($plain === false) {
            throw new \RuntimeException('Η αποκρυπτογράφηση απέτυχε: λάθος APP_KEY ή αλλαγμένο κείμενο.');
        }

        return $plain;
    }

    public static function encryptNullable(?string $plain): ?string
    {
        return $plain === null ? null : self::encrypt($plain);
    }

    public static function decryptColumns(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (isset($row[$column])) {
                $row[$column] = self::decrypt((string) $row[$column]);
            }
        }

        return $row;
    }

    public static function decryptRows(array $rows, array $columns): array
    {
        return array_map(static fn (array $row): array => self::decryptColumns($row, $columns), $rows);
    }

    private static function key(): string
    {
        $key = base64_decode(trim((string) getenv('APP_KEY')), true);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException('Το APP_KEY λείπει από το .env ή δεν είναι 32 bytes σε base64.');
        }

        return $key;
    }
}
