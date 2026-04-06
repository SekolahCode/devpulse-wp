<?php

declare(strict_types=1);

// Minimal WordPress stubs needed for unit tests (no WordPress installation required).

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
        return $value;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string {
        return strip_tags( trim( $str ) );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( mixed $value ): mixed {
        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $option, mixed $default = false ): mixed {
        return $default;
    }
}

if ( ! function_exists( 'wp_rand' ) ) {
    function wp_rand( ?int $min = null, ?int $max = null ): int {
        if ( $min !== null && $max !== null ) {
            return random_int( $min, $max );
        }
        return random_int( 0, PHP_INT_MAX );
    }
}

require_once __DIR__ . '/../vendor/autoload.php';
