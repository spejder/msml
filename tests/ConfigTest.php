<?php

declare(strict_types=1);

namespace MSML\Tests;

use MSML\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    protected string $oldCwd;

    protected string $dir;

    protected function setUp(): void
    {
        $oldCwd = getcwd();
        $this->assertIsString($oldCwd);
        $this->oldCwd = $oldCwd;

        $this->dir = sys_get_temp_dir() . '/msml-config-test-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->dir));
        $this->assertTrue(chdir($this->dir));
    }

    protected function tearDown(): void
    {
        chdir($this->oldCwd);

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testListsOverrideIsMerged(): void
    {
        file_put_contents($this->dir . '/lists.yml', <<<'YAML'
            base@example.com:
              description: Base list.
            replaced@example.com:
              description: Replaced list.
            YAML);
        file_put_contents($this->dir . '/lists.override.yml', <<<'YAML'
            override@example.com:
              description: Override list.
            replaced@example.com:
              description: Replacement list.
            YAML);

        $config = new Config();

        $this->assertSame(
            [
                'base@example.com' => ['description' => 'Base list.'],
                'replaced@example.com' => ['description' => 'Replacement list.'],
                'override@example.com' => ['description' => 'Override list.'],
            ],
            $config['lists']
        );
    }

    public function testListsWithoutOverride(): void
    {
        file_put_contents($this->dir . '/lists.yml', <<<'YAML'
            base@example.com:
              description: Base list.
            YAML);

        $config = new Config();

        $this->assertSame(
            ['base@example.com' => ['description' => 'Base list.']],
            $config['lists']
        );
    }
}
