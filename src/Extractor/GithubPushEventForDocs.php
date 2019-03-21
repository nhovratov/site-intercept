<?php
declare(strict_types = 1);

/*
 * This file is part of the package t3g/intercept.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Extractor;

use App\Exception\DoNotCareException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Extract information from a github push event hook
 * needed to trigger a bamboo docs render build
 */
class GithubPushEventForDocs
{
    /**
     * @var string A tag or a branch name
     */
    public $versionNumber = '';

    /**
     * @var string Repository url to clone, eg. 'https://github.com/TYPO3-Documentation/TYPO3CMS-Reference-Typoscript.git'
     */
    public $repositoryUrl = '';

    /**
     * Path to composer.json in repository
     *
     * @var string
     */
    public $composerFile = '';

    /**
     * Extract information needed by docs trigger from a github
     * push event or throw an exception if not responsible
     *
     * @param RequestStack $requestStack
     * @throws DoNotCareException
     */
    public function __construct(RequestStack $requestStack)
    {
        $payload = json_decode($requestStack->getCurrentRequest()->getContent(), true);
        $this->versionNumber = $this->getVersionNumberFromRef($payload['ref']);
        $this->repositoryUrl = $payload['repository']['clone_url'];
        $repositoryName = $this->extractRepositoryNameFromUrl($this->repositoryUrl);
        $this->composerFile = 'https://raw.githubusercontent.com/' . $repositoryName . '/' . $this->versionNumber . '/composer.json';
        if (empty($this->versionNumber) || empty($this->repositoryUrl)) {
            throw new DoNotCareException();
        }
    }

    /**
     * Find branch or tag name
     *
     * @param string $ref
     * @return string
     * @throws DoNotCareException
     */
    private function getVersionNumberFromRef(string $ref): string
    {
        if (strpos($ref, 'refs/tags/') === 0) {
            return str_replace('refs/tags/', '', $ref);
        }
        if (strpos($ref, 'refs/heads/') === 0) {
            return str_replace('refs/heads/', '', $ref);
        }
        throw new DoNotCareException();
    }

    /**
     * @param $repositoryUrl
     * @return string
     */
    private function extractRepositoryNameFromUrl($repositoryUrl): string
    {
        // Extract repository name from URL
        $path = trim(parse_url($repositoryUrl, PHP_URL_PATH), '/');

        // Remove .git suffix
        $path = substr($path, 0, -4);

        return $path ?: '';
    }
}
