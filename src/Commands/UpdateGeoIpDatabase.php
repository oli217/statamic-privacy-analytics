<?php

namespace Oliweb\StatamicAnalytics\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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

        $uid     = uniqid('geolite2_', true);
        $tmpFile = sys_get_temp_dir() . '/' . $uid . '.tar.gz';
        $tmpDir  = sys_get_temp_dir() . '/' . $uid . '_extract';

        try {
            $response = Http::withBasicAuth($accountId, $licenseKey)
                ->timeout(120)
                ->withOptions(['sink' => $tmpFile])
                ->get($this->url);
        } catch (ConnectionException $e) {
            $this->error('Erreur réseau lors du téléchargement : ' . $e->getMessage());
            $this->cleanup($tmpFile, $tmpDir);
            return self::FAILURE;
        }

        if (!$response->successful() || !file_exists($tmpFile) || filesize($tmpFile) < 1024) {
            $this->error('Échec du téléchargement (HTTP ' . $response->status() . ').');
            $this->cleanup($tmpFile, $tmpDir);
            return self::FAILURE;
        }

        if (!$this->verifyChecksum($tmpFile, $accountId, $licenseKey)) {
            $this->error('La vérification d\'intégrité SHA-256 a échoué. Archive abandonnée.');
            $this->cleanup($tmpFile, $tmpDir);
            return self::FAILURE;
        }

        $this->info('Extraction de l\'archive…');

        mkdir($tmpDir, 0775, true);

        try {
            $phar = new \PharData($tmpFile);
            $phar->extractTo($tmpDir);
        } catch (\PharException | \Exception $e) {
            $this->error('Échec de l\'extraction : ' . $e->getMessage());
            $this->cleanup($tmpFile, $tmpDir);
            return self::FAILURE;
        }

        $mmdbFiles = glob($tmpDir . '/GeoLite2-City_*/GeoLite2-City.mmdb');

        if (empty($mmdbFiles)) {
            $this->error('Fichier .mmdb introuvable dans l\'archive.');
            $this->cleanup($tmpFile, $tmpDir);
            return self::FAILURE;
        }

        // Écriture atomique : copie sur le même filesystem que $destPath,
        // puis rename() atomique pour éviter toute lecture partielle concurrente.
        $stagingPath = $destPath . '.tmp';

        if (!copy($mmdbFiles[0], $stagingPath)) {
            $this->error('Échec de la copie vers le fichier temporaire.');
            $this->cleanup($tmpFile, $tmpDir);
            return self::FAILURE;
        }

        chmod($stagingPath, config('statamic-analytics.cache.file.permissions.file', 0644));

        if (!rename($stagingPath, $destPath)) {
            // rename() cross-device (storage_path sur montage réseau) — fallback non atomique.
            // Une fenêtre de lecture partielle est théoriquement possible pendant le remplacement.
            $this->warn('rename() a échoué (cross-device ?). Fallback copy() non atomique appliqué.');

            if (!copy($stagingPath, $destPath)) {
                $this->error('Échec du fallback copy() vers ' . $destPath);
                @unlink($stagingPath);
                $this->cleanup($tmpFile, $tmpDir);
                return self::FAILURE;
            }

            @unlink($stagingPath);
        }

        $this->cleanup($tmpFile, $tmpDir);

        $this->info('Base de données installée : ' . $destPath);

        return self::SUCCESS;
    }

    protected function fetchRemoteLastModified(string $accountId, string $licenseKey): ?\DateTimeImmutable
    {
        try {
            $response = Http::withBasicAuth($accountId, $licenseKey)
                ->timeout(15)
                ->head($this->url);
        } catch (ConnectionException $e) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $headerValue = $response->header('Last-Modified');

        if (!$headerValue) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC7231, $headerValue)
            ?: \DateTimeImmutable::createFromFormat('D, d M Y H:i:s T', $headerValue)
            ?: null;
    }

    protected function verifyChecksum(string $tmpFile, string $accountId, string $licenseKey): bool
    {
        $checksumUrl = $this->url . '.sha256';

        try {
            $response = Http::withBasicAuth($accountId, $licenseKey)
                ->timeout(15)
                ->get($checksumUrl);
        } catch (ConnectionException $e) {
            return false;
        }

        if (!$response->successful()) {
            return false;
        }

        // Format MaxMind : "<hash>  <filename>\n"
        $remoteHash = strtok(trim($response->body()), ' ');
        $localHash  = hash_file('sha256', $tmpFile);

        return hash_equals($remoteHash, $localHash);
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
