<?php

declare(strict_types=1);

namespace DevPulseWP\Tests\Unit;

use DevPulseWP\Handler;
use PHPUnit\Framework\TestCase;

class HandlerTest extends TestCase
{
    public function test_constructor_parses_dsn_and_api_key(): void
    {
        $handler = new Handler('https://devpulse.example.com/api/ingest/my-api-key', 'production');

        $this->assertSame('https://devpulse.example.com/api/ingest', $handler->get_dsn());
        $this->assertSame('production', $handler->get_env());
    }

    public function test_constructor_trims_trailing_slash_from_dsn(): void
    {
        $handler = new Handler('https://devpulse.example.com/api/ingest/my-key/');

        $this->assertSame('https://devpulse.example.com/api/ingest', $handler->get_dsn());
    }

    public function test_env_defaults_to_production(): void
    {
        $handler = new Handler('https://host/api/ingest/key');

        $this->assertSame('production', $handler->get_env());
    }

    public function test_staging_env_is_stored(): void
    {
        $handler = new Handler('https://host/api/ingest/key', 'staging');

        $this->assertSame('staging', $handler->get_env());
    }
}
