<?php

/*
 * This file is part of the package t3g/intercept.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Extractor;

/**
 * Holds the environment information required for deployment
 */
class DeploymentInformation
{
    /**
     * @var array
     */
    private static $typeMap = [
        'typo3-cms-documentation' => ['m' => 'manual'],
        'typo3-cms-framework' => ['c' => 'core-extension'],
        'typo3-cms-extension' => ['p' => 'extension'],
        '__default' => ['p' => 'package']
    ];

    /**
     * The vendor of a package, e.g. "georgringer"
     *
     * @var string
     */
    private $vendor;

    /**
     * The plain name of a package, e.g. "news"
     *
     * @var string
     */
    private $name;

    /**
     *
     *
     * @var string The branch or tag of the repository supposed to be checked out, eg. '1.2.3', '1.2', 'master', 'latest'
     */
    private $branch;

    /**
     * @var string The target directory on the documentation server, typically identical to $branch, except for $branch='latest', this is deployed to 'master'
     */
    private $targetBranchDirectory;

    /**
     * The long type name of a composer package, e.g. "manual" or "package"
     *
     * @var string
     */
    private $typeLong;

    /**
     * The short type name of a composer package, e.g. "m" or "p"
     * @var string
     */
    private $typeShort;

    /**
     * Constructor
     *
     * @param array $composerJson
     * @param string $branch
     */
    public function __construct(array $composerJson, string $branch)
    {
        $packageName = $this->determinePackageName($composerJson);
        $packageType = $this->determinePackageType($composerJson);

        $this->vendor = key($packageName);
        $this->name = current($packageName);
        $this->branch = $this->normalizeBranchName($branch);
        $this->targetBranchDirectory = $this->normalizeTargetBranchDirectory($this->branch);
        $this->typeLong = current($packageType);
        $this->typeShort = key($packageType);
    }

    /**
     * @return string
     */
    public function getVendor(): string
    {
        return $this->vendor;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getPackageName(): string
    {
        return $this->vendor . '/' . $this->name;
    }

    /**
     * @return string
     */
    public function getBranch(): string
    {
        return $this->branch;
    }

    /**
     * @return string
     */
    public function getTargetBranchDirectory(): string
    {
        return $this->targetBranchDirectory;
    }

    /**
     * @return string
     */
    public function getTypeLong(): string
    {
        return $this->typeLong;
    }

    /**
     * @return string
     */
    public function getTypeShort(): string
    {
        return $this->typeShort;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'vendor' => $this->vendor,
            'name' => $this->name,
            'branch' => $this->branch,
            'target_branch_directory' => $this->targetBranchDirectory,
            'type_long' => $this->typeLong,
            'type_short' => $this->typeShort,
        ];
    }

    /**
     * Check whether given version matches expected format and remove patch level from version
     *
     * @param string $branch
     * @return string
     */
    private function normalizeBranchName(string $branch): string
    {
        if (!preg_match('/^(master|latest|(?:v?\d+.\d+.\d+))$/', $branch)) {
            throw new \InvalidArgumentException('Invalid format given, expected either "latest", "master" or \d.\d.\d.', 1553257961);
        }

        $branch = ltrim($branch, 'v');

        // Remove patch level
        return implode('.', array_slice(explode('.', $branch), 0, 2));
    }

    /**
     * Check whether given version matches expected format and remove patch level from version
     *
     * @param string $branch
     * @return string
     */
    private function normalizeTargetBranchDirectory(string $branch): string
    {
        if ($branch === 'latest') {
            return 'master';
        } else {
            return (string)$branch;
        }
    }

    /**
     * @param array $composerJson
     * @return array
     * @throws \InvalidArgumentException
     */
    private function determinePackageType(array $composerJson): array
    {
        if (empty($composerJson['type'])) {
            throw new \InvalidArgumentException('No package type defined in composer.json', 1553081747);
        }

        return self::$typeMap[$composerJson['type']] ?? self::$typeMap['__default'];
    }

    /**
     * @param array $composerJson
     * @return array
     */
    private function determinePackageName(array $composerJson): array
    {
        if (empty($composerJson['name'])) {
            throw new \InvalidArgumentException('No package name defined in composer.json', 1553082362);
        }

        if (!preg_match('/^[\w-]+\/[\w-]+$/', $composerJson['name'])) {
            throw new \InvalidArgumentException('Invalid package name ' . $composerJson['name'] . ' provided', 1553082490);
        }

        [$vendor, $name] = explode('/', $composerJson['name']);
        return [$vendor => $name];
    }
}
