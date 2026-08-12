<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class EnglishInterfaceTest extends TestCase
{
    public function test_blade_interface_contains_no_static_georgian_copy(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
        $matches = [];

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (preg_match('/[ა-ჰ]/u', $contents) === 1) {
                $matches[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $matches, 'Georgian static UI copy remains in: '.implode(', ', $matches));
    }
}
