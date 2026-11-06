<?php

declare(strict_types=1);

namespace GitConstellation\Tests;

use GitConstellation\Support\JsonFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'constellation-json-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testWriteAndReadRetainExactSafeIntegerBoundary(): void
    {
        $data = ['count' => 9_007_199_254_740_991, 'name' => 'Développeur'];
        JsonFile::write($this->path, $data);
        self::assertSame($data, JsonFile::read($this->path));
    }

    public function testInvalidJsonIsActionable(): void
    {
        file_put_contents($this->path, '{"days":');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON');
        JsonFile::read($this->path);
    }

    public function testArrayRootIsRejected(): void
    {
        file_put_contents($this->path, '[]');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('root must be an object');
        JsonFile::read($this->path);
    }

    public function testMissingFileIsActionable(): void
    {
        unlink($this->path);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read JSON file');
        JsonFile::read($this->path);
    }
}
