<?php

namespace Tests\Feature;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as Monolog;
use Tests\TestCase;

class LoggingConfigTest extends TestCase
{
    public function test_stderr_writes_one_json_object_per_line(): void
    {
        $this->assertInstanceOf(JsonFormatter::class, $this->formatter('stderr'));
    }

    public function test_the_log_file_stays_plain_text(): void
    {
        $this->assertNotInstanceOf(JsonFormatter::class, $this->formatter('single'));
    }

    private function formatter(string $channel): FormatterInterface
    {
        /** @var Logger $logger */
        $logger = Log::channel($channel);
        /** @var Monolog $monolog */
        $monolog = $logger->getLogger();
        $handler = $monolog->getHandlers()[0];
        $this->assertInstanceOf(FormattableHandlerInterface::class, $handler);

        return $handler->getFormatter();
    }
}
