<?php 
declare(strict_types=1);

namespace App\Core;

class Env {
    public static function load(string $path): void {
        if(!file_exists($path)) {
            throw new \RuntimeException(".env file not found at:{$path}");
        } 
        $lines =file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach( $lines as $line) {
            $line =trim($line);

            if(str_starts_with($line,'#') || $line === '') {
                continue;
            }
            if(!str_contains($line,'=')) {
                continue;
            }
            [$key,$value]=explode('=',$line ,2);
            $key=trim($key);
            $value=trim($value);
            if (str_starts_with($value,'"')&& str_ends_with($value,'"') ||
            (str_starts_with($value,"'") && str_ends_with($value,"'"))) {
                $value=substr($value, 1, -1);
            }
            putenv("{$key}={$value}");
            $_ENV[$key]=$value;
            $_SERVER[$key]=$value;
        }
    }
}