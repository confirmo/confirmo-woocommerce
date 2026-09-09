<?php

/**
 * Serves a chosen body from `php://input`.
 *
 * The Checkout gateway's callback handler reads the raw request with
 * `file_get_contents('php://input')`. A test cannot populate that, and the
 * alternative — reimplementing the handler's logic in the test — would assert
 * against a copy rather than the code that ships, which is exactly the bug a
 * callback test exists to catch.
 *
 * So the `php://` wrapper is replaced for the duration of one call. Only
 * `php://input` is served; anything else is refused, because a wrapper that
 * silently mishandled `php://memory` would be far harder to debug than a failure.
 */
class ConfirmoInputStream
{
    /** @var string */
    public static $body = '';

    /** @var resource|null set by PHP */
    public $context;

    /** @var int */
    private $position = 0;

    /**
     * Runs $work with `php://input` returning $body.
     *
     * @return mixed whatever $work returns
     */
    public static function with(string $body, callable $work)
    {
        self::$body = $body;

        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);

        try {
            return $work();
        } finally {
            stream_wrapper_restore('php');
            self::$body = '';
        }
    }

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        $this->position = 0;

        return strtolower($path) === 'php://input';
    }

    public function stream_read($count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek($offset, $whence = SEEK_SET): bool
    {
        $this->position = $whence === SEEK_END ? strlen(self::$body) + $offset : $offset;

        return true;
    }

    public function stream_stat()
    {
        return ['size' => strlen(self::$body)];
    }

    public function stream_write($data): int
    {
        return 0;
    }

    public function stream_close(): void
    {
    }
}
