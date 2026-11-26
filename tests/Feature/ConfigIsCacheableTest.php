<?php

namespace Tests\Feature;

use Closure;
use Tests\TestCase;

class ConfigIsCacheableTest extends TestCase
{
    /**
     * config:cache refuses any closure in config, so a closure here would break the deploy.
     *
     * @test
     */
    public function no_config_value_is_a_closure(): void
    {
        $closurePaths = [];
        $this->collectClosurePaths(config()->all(), '', $closurePaths);

        $this->assertSame([], $closurePaths, 'Config values that config:cache cannot serialize');
    }

    /** @test */
    public function the_check_finds_a_nested_closure(): void
    {
        $closurePaths = [];
        $this->collectClosurePaths(['menu' => ['main' => [['badge' => fn () => 1]]]], '', $closurePaths);

        $this->assertSame(['menu.main.0.badge'], $closurePaths);
    }

    /**
     * @param  array<mixed>  $values
     * @param  list<string>  $closurePaths
     */
    private function collectClosurePaths(array $values, string $prefix, array &$closurePaths): void
    {
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if ($value instanceof Closure) {
                $closurePaths[] = $path;
            } elseif (is_array($value)) {
                $this->collectClosurePaths($value, $path, $closurePaths);
            }
        }
    }
}
