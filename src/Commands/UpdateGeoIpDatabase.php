<?php

namespace Oliweb\StatamicAnalytics\Commands;

use Illuminate\Console\Command;

class UpdateGeoIpDatabase extends Command
{
    protected $signature = 'analytics:update-geoip
                            {--force : Télécharge même si la base locale est déjà à jour}';

    protected $description = 'Télécharge GeoLite2-City.mmdb si une version plus récente est disponible.';

    private string $url = 'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz';

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

        // Vérifier la date de la release distante avant de télécharger
        $remoteDate = $this->fetchRemoteLastModified($accountId, $licenseKey);

        if ($remoteDate === null) {
            $this->error('Impossible de contacter le serveur MaxMind. Vérifiez vos identifiants et votre accès réseau.');
            return self::FAILURE;
        }

        if (!$this->option('force') && file_exists($destPath)) {
            $localDate = new \DateTimeImmutable('@' . filemtime($destPath));

            if ($remoteDate <= $localDate) {
                $this->info('Base déjà à jour (' . $localDate->format('Y-m-d') . '). Aucun téléchargement nécessaire.');
                return self::SUCCESS;
            }
        }

        $this->info('Nouvelle version disponible (' . $remoteDate->format('Y-m-d') . '). Téléchargement…');

        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }

        $tmpFile = sys_get_temp_dir() . '/GeoLite2-City-' . time() . '.tar.gz';
        $tmpDir  = sys_get_temp_dir() . '/GeoLite2-City-extract-' . time();

        $exitCode = null;
        system(
            sprintf(
                'curl -fsSL -u %s:%s %s -o %s',
                escapeshellarg($accountId),
                escapeshellarg($licenseKey),
                escapeshellarg($this->url),
                escapeshellarg($tmpFile)
            ),
            $exitCode
        );

        if ($exitCode !== 0 || !file_exists($tmpFile) || filesize($tmpFile) < 1024) {
            $this->error('Échec du téléchargement.');
            return self::FAILURE;
        }

        $this->info('Extraction de l\'archive…');

        mkdir($tmpDir, 0775, true);

        $phar = new \PharData($tmpFile);
        $phar->extractTo($tmpDir);

        $mmdbFiles = glob($tmpDir . '/GeoLite2-City_*/GeoLite2-City.mmdb');

        if (empty($mmdbFiles)) {
            $this->error('Fichier .mmdb introuvable dans l\'archive.');
            $this->cleanup($tmpFile, $tmpDir);
            return self::FAILURE;
        }

        copy($mmdbFiles[0], $destPath);
        chmod($destPath, 0664);

        $this->cleanup($tmpFile, $tmpDir);

        $this->info('Base de données installée : ' . $destPath);

        return self::SUCCESS;
    }

    protected function fetchRemoteLastModified(string $accountId, string $licenseKey): ?\DateTimeImmutable
    {
        $output   = [];
        $exitCode = null;

        exec(
            sprintf(
                'curl -sI -L -u %s:%s %s 2>/dev/null',
                escapeshellarg($accountId),
                escapeshellarg($licenseKey),
                escapeshellarg($this->url)
            ),
            $output,
            $exitCode
        );

        if ($exitCode !== 0) {
            return null;
        }

        foreach ($output as $line) {
            if (stripos($line, 'Last-Modified:') === 0) {
                $dateStr = trim(substr($line, strlen('Last-Modified:')));
                $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC7231, $dateStr)
                    ?: \DateTimeImmutable::createFromFormat('D, d M Y H:i:s T', $dateStr);
                return $date ?: null;
            }
        }

        return null;
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
