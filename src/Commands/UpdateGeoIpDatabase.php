<?php

namespace Oliweb\StatamicAnalytics\Commands;

use Illuminate\Console\Command;

class UpdateGeoIpDatabase extends Command
{
    protected $signature = 'analytics:update-geoip';

    protected $description = 'Télécharge et installe la base de données MaxMind GeoLite2-City.';

    public function handle(): int
    {
        $accountId  = config('statamic-analytics.geolocation.maxmind.account_id');
        $licenseKey = config('statamic-analytics.geolocation.maxmind.license_key');

        if (!$accountId || !$licenseKey) {
            $this->error('MAXMIND_ACCOUNT_ID et MAXMIND_LICENSE_KEY sont requis.');
            $this->line('');
            $this->line('Créez un compte gratuit sur https://www.maxmind.com/en/geolite2/signup');
            $this->line('Puis ajoutez dans votre .env :');
            $this->line('  MAXMIND_ACCOUNT_ID=<votre_account_id>');
            $this->line('  MAXMIND_LICENSE_KEY=<votre_licence_key>');
            return self::FAILURE;
        }

        $destPath = config(
            'statamic-analytics.geolocation.maxmind.database_path',
            storage_path('app/geoip/GeoLite2-City.mmdb')
        );
        $destDir = dirname($destPath);

        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }

        $url     = 'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz';
        $tmpFile = sys_get_temp_dir() . '/GeoLite2-City-' . time() . '.tar.gz';
        $tmpDir  = sys_get_temp_dir() . '/GeoLite2-City-extract-' . time();

        $this->info('Téléchargement de GeoLite2-City depuis MaxMind…');

        // file_get_contents ne transmet pas le header Authorization après redirect (302→CDN)
        // On utilise curl qui suit les redirections nativement.
        $exitCode = null;
        system(
            sprintf(
                'curl -fsSL -u %s:%s %s -o %s',
                escapeshellarg($accountId),
                escapeshellarg($licenseKey),
                escapeshellarg($url),
                escapeshellarg($tmpFile)
            ),
            $exitCode
        );

        if ($exitCode !== 0 || !file_exists($tmpFile) || filesize($tmpFile) < 1024) {
            $this->error('Échec du téléchargement. Vérifiez vos identifiants MaxMind.');
            return self::FAILURE;
        }

        $this->info('Extraction de l\'archive…');

        mkdir($tmpDir, 0775, true);

        $phar = new \PharData($tmpFile);
        $phar->extractTo($tmpDir);

        // Trouver le .mmdb dans le sous-dossier GeoLite2-City_YYYYMMDD/
        $mmdbFiles = glob($tmpDir . '/GeoLite2-City_*/GeoLite2-City.mmdb');

        if (empty($mmdbFiles)) {
            $this->error('Fichier .mmdb introuvable dans l\'archive.');
            $this->cleanup($tmpFile, $tmpDir);
            return self::FAILURE;
        }

        copy($mmdbFiles[0], $destPath);
        chmod($destPath, 0664);

        $this->cleanup($tmpFile, $tmpDir);

        $this->info("Base de données installée : {$destPath}");
        $this->line('');
        $this->line('Pour automatiser la mise à jour hebdomadaire (chaque mardi), ajoutez dans');
        $this->line('votre App\Console\Kernel ou routes/console.php :');
        $this->line("  \$schedule->command('analytics:update-geoip')->weekly()->tuesdays();");

        return self::SUCCESS;
    }

    protected function cleanup(string $tmpFile, string $tmpDir): void
    {
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        if (is_dir($tmpDir)) {
            $this->deleteDirectory($tmpDir);
        }
    }

    protected function deleteDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
