<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Database dump + private storage (uploads, logos, imports) in one zip under storage/app/private/backups.
 * MySQL uses mysqldump with credentials passed through the environment (never on the command line);
 * SQLite copies the file. Keeps the newest BACKUP_KEEP archives. Copy the archives off-server.
 */
class Backup extends Command
{
    protected $signature = 'leadflow:backup {--no-files : Database only}';

    protected $description = 'Create a database + storage backup archive';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('backups');
        $stamp = now()->format('Ymd-His');
        $work = $disk->path('backups/.work-'.$stamp);
        @mkdir($work, 0750, true);
        $dbFile = $work.'/database.sql';

        $conn = config('database.default');
        $cfg = config("database.connections.$conn");
        if ($conn === 'sqlite') {
            $this->dumpSqlite($dbFile); // portable SQL text: restore with `sqlite3 database.sqlite < database.sql`
        } elseif (in_array($conn, ['mysql', 'mariadb'], true)) {
            $cmd = ['mysqldump', '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4',
                '-h', $cfg['host'], '-P', (string) $cfg['port'], '-u', $cfg['username'], $cfg['database']];
            $p = new Process($cmd, null, ['MYSQL_PWD' => (string) $cfg['password']], null, 3600);
            $out = fopen($dbFile, 'wb');
            $p->run(fn ($type, $buf) => $type === Process::OUT ? fwrite($out, $buf) : null);
            fclose($out);
            if (! $p->isSuccessful()) {
                $this->error('mysqldump failed: '.trim($p->getErrorOutput()));
                $this->cleanup($work);

                return self::FAILURE;
            }
        } else {
            $this->error("Backup does not support the '$conn' connection.");

            return self::FAILURE;
        }

        $zipPath = $disk->path("backups/leadflow-$stamp.zip");
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            $this->error('Could not create the archive.');

            return self::FAILURE;
        }
        foreach (glob($work.'/*') as $f) {
            $zip->addFile($f, 'db/'.basename($f));
        }
        if (! $this->option('no-files')) {
            $root = storage_path('app/private');
            foreach (['imports', 'logos', 'kb'] as $dir) {
                if (! is_dir("$root/$dir")) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    $zip->addFile($file->getPathname(), 'files/'.substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        $zip->addFromString('MANIFEST.json', json_encode(['created_at' => now()->toIso8601ZuluString(), 'app' => config('app.name'), 'db' => $conn,
            'laravel' => app()->version(), 'migrations' => \Illuminate\Support\Facades\DB::table('migrations')->max('batch'), 'note' => 'Restore: see README → Backup & restore. APP_KEY is NOT included; keep it with your secrets.'], JSON_PRETTY_PRINT));
        $zip->close();
        $this->cleanup($work);

        $keep = max(1, (int) config('leadflow.backup_keep'));
        $all = collect($disk->files('backups'))->filter(fn ($f) => str_ends_with($f, '.zip'))->sort()->values();
        foreach ($all->slice(0, max(0, $all->count() - $keep)) as $old) {
            $disk->delete($old);
        }
        $this->info('Backup written: '.$zipPath.' ('.round(filesize($zipPath) / 1048576, 2).' MB); keeping '.min($keep, $all->count()).' archives.');

        return self::SUCCESS;
    }

    private function dumpSqlite(string $file): void
    {
        $pdo = \Illuminate\Support\Facades\DB::getPdo();
        $out = fopen($file, 'wb');
        fwrite($out, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
        $objects = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($objects as $o) {
            fwrite($out, $o['sql'].";\n");
            if ($o['type'] !== 'table') {
                continue;
            }
            $q = $pdo->query('SELECT * FROM "'.str_replace('"', '""', $o['name']).'"');
            while ($row = $q->fetch(\PDO::FETCH_ASSOC)) {
                $vals = array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), array_values($row));
                fwrite($out, 'INSERT INTO "'.str_replace('"', '""', $o['name']).'" ('.implode(',', array_map(fn ($c) => '"'.$c.'"', array_keys($row))).') VALUES ('.implode(',', $vals).");\n");
            }
        }
        fwrite($out, "COMMIT;\n");
        fclose($out);
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
