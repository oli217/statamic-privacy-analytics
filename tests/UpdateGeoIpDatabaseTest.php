<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests d'intégration pour analytics:update-geoip.
 *
 * Stratégie :
 *   - Http::fake() intercepte les requêtes réseau ; le sinkStubHandler de
 *     Laravel 12 écrit bien le corps de la réponse dans le fichier sink, ce
 *     qui rend testable le flux complet (HEAD → GET download → GET checksum).
 *   - Une archive .tar.gz minimale est construite dynamiquement dans setUp()
 *     via PharData pour éviter de committer des binaires propriétaires.
 *   - Tous les artefacts temporaires sont détruits dans tearDown().
 */
class UpdateGeoIpDatabaseTest extends TestCase
{
    private string $tempDir;
    private string $destPath;
    private string $mmdbContent;
    private string $archiveContent;
    private string $archiveHash;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir  = sys_get_temp_dir() . '/analytics_geoip_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->destPath = $this->tempDir . '/geoip/GeoLite2-City.mmdb';

        // Contenu aléatoire de 4 Ko : gzip n'arrivera pas à comprimer en-dessous
        // de 1 Ko, ce qui satisfait la garde filesize($tmpFile) < 1024 de la commande.
        $this->mmdbContent = random_bytes(4096);

        // Construction du fixture .tar.gz avec PharData
        $tarPath = $this->tempDir . '/fixture.tar';
        $phar    = new \PharData($tarPath);
        $phar->addFromString('GeoLite2-City_20240615/GeoLite2-City.mmdb', $this->mmdbContent);
        $compressed = $phar->compress(\Phar::GZ);
        unset($phar, $compressed); // libère les handles fichiers

        $archivePath          = $tarPath . '.gz';
        $this->archiveContent = file_get_contents($archivePath);
        $this->archiveHash    = hash('sha256', $this->archiveContent);

        config([
            'statamic-analytics.geolocation.maxmind.account_id'   => 'test-account',
            'statamic-analytics.geolocation.maxmind.license_key'   => 'test-license',
            'statamic-analytics.geolocation.maxmind.database_path' => $this->destPath,
            'statamic-analytics.cache.file.permissions.file'       => 0644,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->deleteDir($this->tempDir);
        }
        parent::tearDown();
    }

    private function deleteDir(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Configure Http::fake() pour un flux complet (HEAD + GET download + GET sha256).
     *
     * Les patterns sont volontairement distincts :
     *   - '*suffix=tar.gz.sha256*' cible l'URL checksum (contient '.sha256')
     *   - '*suffix=tar.gz'         cible l'URL principale via un séquenceur
     *     (premier appel = HEAD, second = GET download)
     * L'URL principale ne contient pas '.sha256', donc les deux patterns ne
     * se recoupent pas (Str::is() utilise preg_quote + remplacement de \*).
     */
    private function fakeFullDownload(string $lastModified, string $hashLine): void
    {
        Http::fake([
            '*suffix=tar.gz.sha256*' => Http::response($hashLine, 200),
            '*suffix=tar.gz'         => Http::sequence()
                ->push('', 200, ['Last-Modified' => $lastModified])
                ->push($this->archiveContent, 200),
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. Credentials absentes → FAILURE, zéro requête HTTP
    // -------------------------------------------------------------------------

    #[Test]
    public function test_credentials_absentes_echoue_proprement(): void
    {
        Http::fake();

        config([
            'statamic-analytics.geolocation.maxmind.account_id' => null,
            'statamic-analytics.geolocation.maxmind.license_key' => null,
        ]);

        $exit = Artisan::call('analytics:update-geoip');

        $this->assertSame(\Illuminate\Console\Command::FAILURE, $exit);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 2. Téléchargement et installation réussis
    // -------------------------------------------------------------------------

    #[Test]
    public function test_telechargement_et_installation_reussis(): void
    {
        $this->fakeFullDownload(
            'Mon, 01 Jan 2030 00:00:00 GMT',
            $this->archiveHash . '  GeoLite2-City.mmdb'
        );

        $exit = Artisan::call('analytics:update-geoip');

        $this->assertSame(\Illuminate\Console\Command::SUCCESS, $exit);
        $this->assertFileExists(
            $this->destPath,
            'Le fichier .mmdb doit être installé au chemin configuré.'
        );
        $this->assertSame(
            $this->mmdbContent,
            file_get_contents($this->destPath),
            'Le contenu du .mmdb installé doit correspondre au fixture.'
        );
    }

    // -------------------------------------------------------------------------
    // 3. Checksum invalide → FAILURE, ancien fichier intact
    // -------------------------------------------------------------------------

    #[Test]
    public function test_checksum_invalide_rejette_le_telechargement(): void
    {
        // Fichier local valide préexistant
        mkdir(dirname($this->destPath), 0775, true);
        file_put_contents($this->destPath, 'ORIGINAL_CONTENT');

        $this->fakeFullDownload(
            'Mon, 01 Jan 2030 00:00:00 GMT',
            str_repeat('0', 64) . '  GeoLite2-City.mmdb' // hash délibérément faux
        );

        $exit = Artisan::call('analytics:update-geoip');

        $this->assertSame(\Illuminate\Console\Command::FAILURE, $exit);
        $this->assertFileExists($this->destPath, 'L\'ancien .mmdb doit être préservé.');
        $this->assertSame(
            'ORIGINAL_CONTENT',
            file_get_contents($this->destPath),
            'Le contenu de l\'ancien .mmdb ne doit pas être altéré.'
        );
    }

    // -------------------------------------------------------------------------
    // 4. Base déjà à jour → pas de téléchargement, seul le HEAD est envoyé
    // -------------------------------------------------------------------------

    #[Test]
    public function test_base_deja_a_jour_ne_retelecharge_pas(): void
    {
        // Fichier local dont le mtime est maintenant (2026) ; date remote = 2024
        mkdir(dirname($this->destPath), 0775, true);
        file_put_contents($this->destPath, 'UP_TO_DATE_CONTENT');

        Http::fake([
            '*suffix=tar.gz' => Http::response('', 200, ['Last-Modified' => 'Mon, 01 Jan 2024 00:00:00 GMT']),
        ]);

        $exit = Artisan::call('analytics:update-geoip');

        $this->assertSame(\Illuminate\Console\Command::SUCCESS, $exit);
        Http::assertSentCount(1); // uniquement le HEAD, aucun GET de téléchargement
    }

    // -------------------------------------------------------------------------
    // 5. --force retélécharge même si la base locale est à jour
    // -------------------------------------------------------------------------

    #[Test]
    public function test_option_force_retelecharge_meme_si_a_jour(): void
    {
        mkdir(dirname($this->destPath), 0775, true);
        file_put_contents($this->destPath, 'UP_TO_DATE_CONTENT');

        $this->fakeFullDownload(
            'Mon, 01 Jan 2024 00:00:00 GMT', // date ancienne — sans --force, aucun download
            $this->archiveHash . '  GeoLite2-City.mmdb'
        );

        $exit = Artisan::call('analytics:update-geoip', ['--force' => true]);

        $this->assertSame(\Illuminate\Console\Command::SUCCESS, $exit);

        // Le GET sur l'URL principale (sans .sha256) doit avoir été envoyé
        Http::assertSent(
            fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), 'suffix=tar.gz')
        );
    }
}
