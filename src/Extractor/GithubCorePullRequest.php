<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/intercept.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Extractor;

use App\Exception\DoNotCareException;

/**
 * Extract information from a GitHub push event hook
 * that was triggered by a new GitHub pull request on
 * https://github.com/TYPO3/TYPO3.CMS.
 * Throws exceptions if data is incomplete or not responsible.
 */
readonly class GithubCorePullRequest
{
    /**
     * @var string Target PR branch, e.g. 'main'
     */
    public string $branch;

    /**
     * @var string Diff URL, e.g. 'https://github.com/psychomieze/TYPO3.CMS/pull/1.diff'
     */
    public string $diffUrl;

    /**
     * @var string URL to github user, e.g. 'https://api.github.com/users/psychomieze'
     */
    public string $userUrl;

    /**
     * @var string URL to pr "issue", e.g. 'https://api.github.com/repos/psychomieze/TYPO3.CMS/issues/1'
     */
    public string $issueUrl;

    /**
     * @var string URL to pull request, e.g. 'https://api.github.com/repos/psychomieze/TYPO3.CMS/pulls/1'
     */
    public string $pullRequestUrl;

    /**
     * @var string URL to pull request comments, e.g. 'https://api.github.com/repos/psychomieze/TYPO3.CMS/issues/1/comments'
     */
    public string $commentsUrl;

    /**
     * Extract information needed by pull request controller from a GitHub
     * PR or throw an exception if not responsible.
     *
     * @throws DoNotCareException
     */
    public function __construct(string $payload)
    {
        $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $action = $payload['action'] ?? '';
        if ('opened' !== $action) {
            throw new DoNotCareException('action is not opened, it\'s not my job');
        }

        $this->branch = $payload['pull_request']['base']['ref'] ?? '';
        $this->diffUrl = $payload['pull_request']['diff_url'] ?? '';
        $this->userUrl = $payload['pull_request']['user']['url'] ?? '';
        $this->issueUrl = $payload['pull_request']['issue_url'] ?? '';
        $this->pullRequestUrl = $payload['pull_request']['url'] ?? '';
        $this->commentsUrl = $payload['pull_request']['comments_url'];

        if (empty($this->branch) || empty($this->diffUrl) || empty($this->userUrl)
            || empty($this->issueUrl) || empty($this->pullRequestUrl) || empty($this->commentsUrl)
        ) {
            throw new DoNotCareException('Do not care if pr information is not complete for whatever reason');
        }
    }
}
