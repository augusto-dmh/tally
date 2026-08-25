<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace HyperfTest\Unit\Domain;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @internal
 * @coversNothing
 */
class DomainLayerImportsTest extends TestCase
{
    public function testDomainPhpFilesDoNotContainHyperfNamespacePrefix(): void
    {
        $domainDir = BASE_PATH . '/app/Domain';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($domainDir, FilesystemIterator::SKIP_DOTS)
        );

        $scanned = [];
        $offenders = [];

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $pathname = $file->getPathname();
            $relative = substr($pathname, strlen(BASE_PATH) + 1);
            $scanned[] = $relative;

            $contents = file_get_contents($pathname);
            $this->assertIsString($contents, sprintf('Could not read %s', $relative));

            if (str_contains($contents, 'Hyperf\\')) {
                $offenders[] = $relative;
            }
        }

        $this->assertNotEmpty($scanned, 'Expected to scan PHP files under app/Domain');
        $this->assertSame(
            [],
            $offenders,
            sprintf(
                'Domain files must not contain Hyperf\\ (SCOPE-09). Offending files: %s',
                implode(', ', $offenders)
            )
        );
    }
}
