<?php

namespace Peralta\AgentKit\Tests;

use Illuminate\Database\Migrations\Migration;

final class PackageMigrations
{
    /**
     * Fresh instances of the package migrations in one directory, in file-name order.
     *
     * @param  string  $directory  relative to the package root, e.g. "database/migrations"
     * @return list<Migration>
     */
    public static function in(string $directory): array
    {
        $files = glob(dirname(__DIR__) . '/' . trim($directory, '/') . '/*.php') ?: [];
        sort($files);

        return array_map(static fn (string $file): Migration => require $file, $files);
    }
}
