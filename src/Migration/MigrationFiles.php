<?php

declare(strict_types=1);

namespace Lava\Db\Migration;

use Lava\Db\Problem\InvalidMigrationFile;

/**
 * The migrations on disk: `app/Database/Migrations/<timestamp>_<description>.php`.
 *
 * The timestamp in the filename is the order. Nothing else sorts migrations —
 * not a list in a config file, not a number someone maintains by hand — which
 * means two branches can add migrations at the same time without conflicting,
 * and the order is decided by when the file was created rather than by when
 * it happened to be merged.
 *
 * Loading is a `require` and a check on what came back. See
 * {@see Migration} for why the file returns an instance instead of declaring
 * a class.
 */
final class MigrationFiles
{
    /** The directory, relative to the app root. */
    public const DIRECTORY = 'app/Database/Migrations';

    /** `<YYYY_MM_DD_HHMMSS>_<snake_case_description>` — the name, without `.php`. */
    private const NAME = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/D';

    /** @var array<string, Migration>|null loaded lazily, then reused — a file is `require`d once */
    private ?array $loaded = null;

    /** @var array<string, string> name => absolute path, built alongside the load */
    private array $paths = [];

    public function __construct(private readonly string $directory)
    {
    }

    /** The convention's directory for an app root. */
    public static function inApp(string $appDir): self
    {
        return new self(rtrim($appDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::DIRECTORY));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Every migration, keyed by name and ordered by it.
     *
     * A missing directory is not an error: an app with no migrations yet is
     * the normal state of a new app, and `db:migrate` on it should report
     * "nothing to do" rather than fail.
     *
     * @return array<string, Migration>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $this->paths = $this->scan();

        $migrations = [];
        foreach ($this->paths as $name => $path) {
            $migrations[$name] = $this->load($name, $path);
        }

        return $this->loaded = $migrations;
    }

    /** @return array<string, string> name => absolute path, ordered by name */
    public function paths(): array
    {
        $this->all();

        return $this->paths;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    /** @throws InvalidMigrationFile when the name is not one of ours */
    public function path(string $name): string
    {
        return $this->paths()[$name] ?? throw InvalidMigrationFile::badName($name);
    }

    public function get(string $name): Migration
    {
        return $this->all()[$name] ?? throw InvalidMigrationFile::badName($name);
    }

    /** @return list<string> every migration name, in application order */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * Finds the migration files, refusing any whose name is not one of ours.
     *
     * A wrongly named file is refused rather than skipped. Skipping would
     * make a typo'd timestamp invisible — the migration simply would not run,
     * and nothing would say why — and the whole point of the naming
     * convention is that it is the ordering, so a file outside it has no
     * defined place to run.
     *
     * @return array<string, string> name => path, sorted by name
     * @throws InvalidMigrationFile
     */
    private function scan(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $entries = scandir($this->directory);
        if ($entries === false) {
            return [];
        }

        $found = [];
        foreach ($entries as $entry) {
            if (!str_ends_with($entry, '.php')) {
                continue;
            }

            $name = substr($entry, 0, -4);
            if (preg_match(self::NAME, $name) !== 1) {
                throw InvalidMigrationFile::badName($this->directory . DIRECTORY_SEPARATOR . $entry);
            }

            $found[$name] = $this->directory . DIRECTORY_SEPARATOR . $entry;
        }

        // ksort, not a natural sort: the names are fixed-width, so byte order
        // and chronological order agree.
        ksort($found);

        return $found;
    }

    /**
     * @throws InvalidMigrationFile
     */
    private function load(string $name, string $path): Migration
    {
        try {
            $migration = require $path;
        } catch (\Throwable $previous) {
            throw InvalidMigrationFile::threw($path, $previous);
        }

        if (!$migration instanceof Migration) {
            throw InvalidMigrationFile::notAMigration($path, get_debug_type($migration));
        }

        return $migration;
    }
}
