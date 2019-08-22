<?php
declare(strict_types = 1);

/*
 * This file is part of the package t3g/intercept.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\DocumentationJar;
use App\Entity\RepositoryBlacklistEntry;
use App\Enum\DocumentationStatus;
use App\Exception\Composer\DocsComposerDependencyException;
use App\Exception\ComposerJsonInvalidException;
use App\Exception\ComposerJsonNotFoundException;
use App\Exception\DocsPackageDoNotCareBranch;
use App\Exception\DocsPackageRegisteredWithDifferentRepositoryException;
use App\Exception\UnsupportedWebHookRequestException;
use App\Extractor\ComposerJson;
use App\Form\DocsDeploymentFilterType;
use App\Form\DocumentationDeployment;
use App\Repository\DocumentationJarRepository;
use App\Repository\RepositoryBlacklistEntryRepository;
use App\Service\BambooService;
use App\Service\DocumentationBuildInformationService;
use App\Service\GitRepositoryService;
use App\Service\GraylogService;
use App\Service\RenderDocumentationService;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Show and manipulate all docs deployments managed by intercept
 */
class AdminInterfaceDocsDeploymentsController extends AbstractController
{
    /**
     * @Route("/admin/docs/deployments", name="admin_docs_deployments")
     *
     * @param Request $request
     * @param PaginatorInterface $paginator
     * @param BambooService $bambooService
     * @param GraylogService $graylogService
     * @param DocumentationJarRepository $documentationJarRepository
     * @return Response
     */
    public function index(
        Request $request,
        PaginatorInterface $paginator,
        BambooService $bambooService,
        GraylogService $graylogService,
        DocumentationJarRepository $documentationJarRepository
    ): Response {
        $recentLogsMessages = $graylogService->getRecentBambooDocsActions();
        $criteria = Criteria::create();

        $requestSortDirection = $request->query->get('direction');
        $requestSortField = $request->query->get('sort');
        $sortDirection = $requestSortDirection === 'asc' ? Criteria::ASC : Criteria::DESC;
        $sortField = in_array($requestSortField, ['packageName', 'typeLong', 'lastRenderedAt']) ? $requestSortField : 'lastRenderedAt';
        $criteria->orderBy([$sortField => $sortDirection]);

        $form = $this->createForm(DocsDeploymentFilterType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $expressionBuilder = Criteria::expr();
            $data = $form->getData();
            if ($data['type']) {
                $criteria->andWhere($expressionBuilder->eq('typeLong', $data['type']));
            }
            if ($data['status'] || $data['status'] === 0) {
                $criteria->andWhere($expressionBuilder->eq('status', $data['status']));
            }
            if ($data['search']) {
                $criteria->andWhere($expressionBuilder->contains('packageName', $data['search']));
            }
        }

        $deployments = $documentationJarRepository->matching($criteria);
        $pagination = $paginator->paginate(
            $deployments,
            $request->query->getInt('page', 1)
        );

        return $this->render(
            'docsDeployments.html.twig',
            [
                'filter' => $form->createView(),
                'pagination' => $pagination,
                'logMessages' => $recentLogsMessages,
                'docsLiveServer' => getenv('DOCS_LIVE_SERVER'),
            ]
        );
    }

    /**
     * @Route("/admin/docs/deployments/delete/{documentationJarId}/confirm", name="admin_docs_deployments_delete_view", requirements={"documentationJarId"="\d+"}, methods={"GET"})
     *
     * @param int $documentationJarId
     * @param DocumentationJarRepository $documentationJarRepository
     * @return Response
     */
    public function deleteConfirm(int $documentationJarId, DocumentationJarRepository $documentationJarRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_DOCUMENTATION_MAINTAINER');

        $jar = $documentationJarRepository->find($documentationJarId);
        if (null === $jar || !$jar->isDeletable()) {
            return $this->redirectToRoute('admin_docs_deployments');
        }

        return $this->render(
            'docsDeploymentsDelete.html.twig',
            [
                'deployment' => $jar,
                'docsLiveServer' => getenv('DOCS_LIVE_SERVER'),
            ]
        );
    }

    /**
     * @Route("/admin/docs/deployments/delete/{documentationJarId}", name="admin_docs_deployments_delete_action", requirements={"documentationJarId"="\d+"}, methods={"DELETE"})
     *
     * @param int $documentationJarId
     * @param LoggerInterface $logger
     * @param DocumentationJarRepository $documentationJarRepository
     * @param EntityManagerInterface $entityManager
     * @param DocumentationBuildInformationService $documentationBuildInformationService
     * @param BambooService $bambooService
     * @return Response
     * @throws DocsPackageDoNotCareBranch
     */
    public function delete(
        int $documentationJarId,
        LoggerInterface $logger,
        DocumentationJarRepository $documentationJarRepository,
        EntityManagerInterface $entityManager,
        DocumentationBuildInformationService $documentationBuildInformationService,
        BambooService $bambooService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_DOCUMENTATION_MAINTAINER');

        $jar = $documentationJarRepository->find($documentationJarId);

        if (null !== $jar && $jar->isDeletable()) {
            $informationFile = $documentationBuildInformationService->generateBuildInformationFromDocumentationJar($jar);
            $documentationBuildInformationService->dumpDeploymentInformationFile($informationFile);
            $bambooBuildTriggered = $bambooService->triggerDocumentationDeletionPlan($informationFile);

            $jar
                ->setBuildKey($bambooBuildTriggered->buildResultKey)
                ->setStatus(DocumentationStatus::STATUS_DELETING);
            $entityManager->persist($jar);
            $entityManager->flush();

            $logger->info(
                'Documentation deleted.',
                [
                    'type' => 'docsRendering',
                    'status' => 'packageDeleted',
                    'triggeredBy' => 'interface',
                    'repository' => $jar->getRepositoryUrl(),
                    'package' => $jar->getPackageName(),
                    'bambooKey' => $bambooBuildTriggered->buildResultKey,
                ]
            );
        }

        return $this->redirectToRoute('admin_docs_deployments');
    }

    /**
     * @Route("/admin/docs/deployments/approve/{documentationJarId}", name="admin_docs_deployments_approve_action", requirements={"documentationJarId"="\d+"})
     *
     * @param int $documentationJarId
     * @param DocumentationJarRepository $documentationJarRepository
     * @param EntityManagerInterface $entityManager
     * @param RenderDocumentationService $renderDocumentationService
     * @return Response
     * @throws DocsPackageDoNotCareBranch
     */
    public function approve(
        int $documentationJarId,
        DocumentationJarRepository $documentationJarRepository,
        EntityManagerInterface $entityManager,
        RenderDocumentationService $renderDocumentationService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_DOCUMENTATION_MAINTAINER');
        $originalJar = $documentationJarRepository->find($documentationJarId);
        $jars = $documentationJarRepository->findBy(['repositoryUrl' => $originalJar->getRepositoryUrl()]);

        foreach ($jars as $jar) {
            $jar->setApproved(true);
            $entityManager->persist($jar);
            $entityManager->flush();
            $renderDocumentationService->renderDocumentationByDocumentationJar($jar, 'interface');
        }

        $this->addFlash('success', 'Repository has been approved.');

        return $this->redirectToRoute('admin_docs_deployments');
    }

    /**
     * @Route("/admin/docs/deployments/blacklist/{documentationJarId}", name="admin_docs_deployments_blacklist_action", requirements={"documentationJarId"="\d+"})
     *
     * @param int $documentationJarId
     * @param DocumentationJarRepository $documentationJarRepository
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function blacklist(
        int $documentationJarId,
        DocumentationJarRepository $documentationJarRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_DOCUMENTATION_MAINTAINER');
        $originalJar = $documentationJarRepository->find($documentationJarId);
        $jars = $documentationJarRepository->findBy(['repositoryUrl' => $originalJar->getRepositoryUrl()]);

        $blacklistEntry = new RepositoryBlacklistEntry();
        $blacklistEntry->setRepositoryUrl($originalJar->getRepositoryUrl());
        $entityManager->persist($blacklistEntry);

        foreach ($jars as $jar) {
            $entityManager->remove($jar);
        }
        $entityManager->flush();
        $this->addFlash('success', 'Repository has been blacklisted.');

        return $this->redirectToRoute('admin_docs_deployments');
    }

    /**
     * @Route("/admin/docs/deployments/blacklist", name="admin_docs_deployments_blacklist_index")
     *
     * @param Request $request
     * @param PaginatorInterface $paginator
     * @param RepositoryBlacklistEntryRepository $repositoryBlacklistEntryRepository
     * @return Response
     */
    public function blacklistIndex(
        Request $request,
        PaginatorInterface $paginator,
        RepositoryBlacklistEntryRepository $repositoryBlacklistEntryRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_DOCUMENTATION_MAINTAINER');
        $entries = $repositoryBlacklistEntryRepository->findAll();

        $pagination = $paginator->paginate(
            $entries,
            $request->query->getInt('page', 1)
        );

        return $this->render('repository_blacklist/index.html.twig', ['pagination' => $pagination]);
    }

    /**
     * @Route("/admin/docs/deployments/blacklist/delete/{entryId}", name="admin_docs_deployments_blacklist_delete_action", requirements={"entryId"="\d+"})
     *
     * @param int $entryId
     * @param RepositoryBlacklistEntryRepository $repositoryBlacklistEntryRepository
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function blacklistDelete(
        int $entryId,
        RepositoryBlacklistEntryRepository $repositoryBlacklistEntryRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_DOCUMENTATION_MAINTAINER');
        $entry = $repositoryBlacklistEntryRepository->find($entryId);

        if (null !== $entry) {
            $entityManager->remove($entry);
            $entityManager->flush();
            $this->addFlash('success', 'Blacklist entry deleted.');
        }

        return $this->redirectToRoute('admin_docs_deployments_blacklist_index');
    }

    /**
     * @Route("/admin/docs/deployments/add", name="admin_docs_deployments_add")
     *
     * @param Request $request
     * @param RepositoryBlacklistEntryRepository $repositoryBlacklistEntryRepository
     * @return Response
     */
    public function addConfiguration(Request $request, RepositoryBlacklistEntryRepository $repositoryBlacklistEntryRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_DOCUMENTATION_MAINTAINER');
        $documentationJar = new DocumentationJar();
        $repositoryUrl = $request->get('documentation_deployment')['repositoryUrl'] ?? '';
        $documentationJar->setRepositoryUrl($repositoryUrl);

        $form = $this->createForm(DocumentationDeployment::class, $documentationJar);
        $form->get('repositoryType')->setData($request->get('documentation_deployment')['repositoryType']);
        $form->handleRequest($request);
        if (!empty($repositoryUrl)) {
            if (preg_match(DocumentationJar::VALID_REPOSITORY_URL_REGEX, $repositoryUrl) !== 1) {
                $error = new FormError('A repository url must be a valid https git url. Staring with \'https://\' and ending with \'.git\'.');
                $form->addError($error);
            } elseif ($repositoryBlacklistEntryRepository->isBlacklisted($repositoryUrl)) {
                $error = new FormError('This repository has been blacklisted and cannot be added.');
                $form->addError($error);
            } else {
                return $this->forward(self::class . '::addConfigurationStep2');
            }
        }

        return $this->render('docs_deployments/addConfiguration.html.twig', [
            'redirect' => $documentationJar,
            'form' => $form->createView(),
            'showPrev' => false,
        ]);
    }

    /**
     * @Route("/admin/docs/deployments/add/step2", name="admin_docs_deployments_add_step2")
     *
     * @param Request $request
     * @return Response
     */
    public function addConfigurationStep2(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_DOCUMENTATION_MAINTAINER');
        $documentationJar = new DocumentationJar();

        $repositoryUrl = $request->get('documentation_deployment')['repositoryUrl'] ?? '';
        $documentationJar->setRepositoryUrl($repositoryUrl);
        $branch = $request->get('documentation_deployment')['branch'] ?? '';
        $documentationJar->setBranch($branch);

        $form = $this->createForm(DocumentationDeployment::class, $documentationJar, ['step2' => true, 'entity_manager' => $this->getDoctrine()->getManager()]);
        $form->setData($documentationJar);
        $form->get('repositoryType')->setData($request->get('documentation_deployment')['repositoryType']);
        if ($branch !== '') {
            return $this->forward(self::class . '::addConfigurationStep3');
        }

        return $this->render('docs_deployments/addConfiguration.html.twig', [
            'redirect' => $documentationJar,
            'form' => $form->createView(),
            'showPrev' => true,
        ]);
    }

    /**
     * @Route("/admin/docs/deployments/add/step3", name="admin_docs_deployments_add_step3")
     *
     * @param Request $request
     * @param DocumentationBuildInformationService $documentationBuildInformationService
     * @param BambooService $bambooService
     * @return Response
     * @throws DocsPackageDoNotCareBranch
     * @throws \App\Exception\Composer\DocsComposerMissingValueException
     */
    public function addConfigurationStep3(Request $request, DocumentationBuildInformationService $documentationBuildInformationService, BambooService $bambooService, DocumentationJarRepository $documentationJarRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_DOCUMENTATION_MAINTAINER');
        $documentationJar = new DocumentationJar();
        $repositoryUrl = $request->get('documentation_deployment')['repositoryUrl'] ?? '';
        $documentationJar->setRepositoryUrl($repositoryUrl);
        $branch = $request->get('documentation_deployment')['branch'] ?? '';
        $documentationJar->setBranch($branch);
        $publicComposerJsonUrl = $request->get('documentation_deployment')['publicComposerJsonUrl'] ?? '';
        $repositoryType = $request->get('documentation_deployment')['repositoryType'] ?? '';
        $documentationJar->setPublicComposerJsonUrl($publicComposerJsonUrl);

        if ($publicComposerJsonUrl === '') {
            // public composer url not set yet, try to resolve it
            $publicComposerJsonUrl = $this->resolveComposerJsonUrl($repositoryUrl, $branch, $repositoryType);
        }
        $documentationJar->setPublicComposerJsonUrl($publicComposerJsonUrl);
        $requestData = $request->request->get('documentation_deployment');
        $requestData['publicComposerJsonUrl'] = $publicComposerJsonUrl;
        $request->request->set('documentation_deployment', $requestData);

        $deploymentInformation = null;
        if ($publicComposerJsonUrl !== '') {
            try {
                $composerJsonObject = $this->resolveComposerJson($documentationBuildInformationService, $publicComposerJsonUrl);
                $documentationBuildInformationService->assertComposerJsonContainsNecessaryData($composerJsonObject);
                $documentationJar
                    ->setPackageName($composerJsonObject->getName())
                    ->setPackageType($composerJsonObject->getType())
                    ->setExtensionKey($composerJsonObject->getExtensionKey())
                    ->setMinimumTypoVersion($composerJsonObject->getMinimumTypoVersion())
                    ->setMaximumTypoVersion($composerJsonObject->getMaximumTypoVersion());
                $existWithDifferentUrl = $documentationJarRepository
                    ->createQueryBuilder('d')
                    ->where('d.repositoryUrl <> :repositoryUrl')
                    ->andWhere('d.packageName = :packageName')
                    ->setParameter('repositoryUrl', $repositoryUrl)
                    ->setParameter('packageName', $composerJsonObject->getName())
                    ->getQuery()
                    ->getResult();
                if (!empty($existWithDifferentUrl)) {
                    throw new DocsPackageRegisteredWithDifferentRepositoryException('This package already exists with a different repository url', 1558697388);
                }
                $deploymentInformation = $documentationBuildInformationService
                    ->generateBuildInformationFromDocumentationJar($documentationJar);
            } catch (ComposerJsonNotFoundException | ComposerJsonInvalidException | DocsComposerDependencyException | DocsPackageDoNotCareBranch | DocsPackageRegisteredWithDifferentRepositoryException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        $form = $this->createForm(DocumentationDeployment::class, $documentationJar, ['step3' => true]);
        $form->handleRequest($request);
        if ($deploymentInformation !== null && $form->isValid()) {
            $documentationJar->setRepositoryUrl($deploymentInformation->repositoryUrl)
                ->setStatus(DocumentationStatus::STATUS_RENDERING)
                ->setBuildKey('')
                ->setPublicComposerJsonUrl($deploymentInformation->publicComposerJsonUrl)
                ->setVendor($deploymentInformation->vendor)
                ->setName($deploymentInformation->name)
                ->setPackageName($deploymentInformation->packageName)
                ->setExtensionKey($deploymentInformation->extensionKey)
                ->setBranch($deploymentInformation->sourceBranch)
                ->setTargetBranchDirectory($deploymentInformation->targetBranchDirectory)
                ->setTypeLong($deploymentInformation->typeLong)
                ->setTypeShort($deploymentInformation->typeShort)
                ->setMinimumTypoVersion($deploymentInformation->minimumTypoVersion)
                ->setMaximumTypoVersion($deploymentInformation->maximumTypoVersion)
                ->setReRenderNeeded(false);

            // Check if this repository is entirely new (aka, no branches at all known)
            // And mark it as new if needed
            $branchExists = $documentationJarRepository->findBy([
                'repositoryUrl' => $deploymentInformation->repositoryUrl,
                'packageName' => $deploymentInformation->packageName,
            ]);
            if (count($branchExists) === 0) {
                $documentationJar->setNew(true);
            } else {
                $documentationJar->setNew(false);
            }

            $approved = $documentationJarRepository->findBy([
                'repositoryUrl' => $deploymentInformation->repositoryUrl,
                'packageName' => $deploymentInformation->packageName,
                'approved' => true,
            ]);

            if (count($approved) > 0) {
                $informationFile = $documentationBuildInformationService->generateBuildInformationFromDocumentationJar($documentationJar);
                $documentationBuildInformationService->dumpDeploymentInformationFile($informationFile);
                $bambooBuildTriggered = $bambooService->triggerDocumentationPlan($informationFile);
                $documentationJar->setBuildKey($bambooBuildTriggered->buildResultKey);
                $documentationJar->setApproved(true);
            } else {
                $documentationJar
                    ->setApproved(false)
                    ->setStatus(DocumentationStatus::STATUS_AWAITING_APPROVAL);
            }

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($documentationJar);
            $entityManager->flush();
            $this->addFlash('success', 'Configuration added');
            return $this->redirectToRoute('admin_docs_deployments');
        }

        return $this->render('docs_deployments/addConfiguration.html.twig', [
            'redirect' => $documentationJar,
            'form' => $form->createView(),
            'showPrev' => true,
        ]);
    }

    /**
     * @Route("/admin/docs/render", name="admin_docs_render")
     * @param Request $request
     * @param LoggerInterface $logger
     * @param DocumentationJarRepository $documentationJarRepository
     * @param RenderDocumentationService $renderDocumentationService
     * @return Response
     */
    public function renderDocs(
        Request $request,
        LoggerInterface $logger,
        DocumentationJarRepository $documentationJarRepository,
        RenderDocumentationService $renderDocumentationService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_DOCUMENTATION_MAINTAINER');

        try {
            $documentationJarId = (int)$request->get('documentation');
            $documentationJar = $documentationJarRepository->find($documentationJarId);
            if ($documentationJar === null) {
                throw new \InvalidArgumentException('no documentationJar given', 1557930900);
            }

            $buildInformation = $renderDocumentationService->renderDocumentationByDocumentationJar($documentationJar, 'interface');
            $this->addFlash('success', 'A re-rendering was triggered.');
            return $this->redirectToRoute('admin_docs_deployments');
        } catch (UnsupportedWebHookRequestException $e) {
            // Hook payload could not be identified as hook that should trigger rendering
            $logger->warning(
                'Can not render documentation: ' . $e->getMessage(),
                [
                    'type' => 'docsRendering',
                    'status' => 'unsupportedHook',
                    'headers' => $request->headers,
                    'payload' => $request->getContent(),
                    'triggeredBy' => 'api',
                    'exceptionCode' => $e->getCode(),
                ]
            );
            // 412: precondition failed
            return Response::create('Invalid hook payload. See https://intercept.typo3.com for more information.', 412);
        } catch (DocsPackageDoNotCareBranch $e) {
            $logger->warning(
                'Can not render documentation: ' . $e->getMessage(),
                [
                    'type' => 'docsRendering',
                    'status' => 'noRelevantBranchOrTag',
                    'triggeredBy' => 'api',
                    'exceptionCode' => $e->getCode(),
                    'exceptionMessage' => $e->getMessage(),
                    'repository' => $documentationJar->getRepositoryUrl(),
                    'sourceBranch' => $documentationJar->getBranch(),
                ]
            );
            return Response::create('Branch or tag name ignored for documentation rendering. See https://intercept.typo3.com for more information.', 412);
        }
    }

    protected function resolveComposerJsonUrl(string $repositoryUrl, string $branch, string $repositoryType = null): string
    {
        $repoService = '';
        $parameters = [];
        if ($repositoryType === GitRepositoryService::SERVICE_BITBUCKET_SERVER) {
            $repoService = GitRepositoryService::SERVICE_BITBUCKET_SERVER;
            $repositoryUrl = str_replace('.git', '', $repositoryUrl);
            $packageParts = explode('/', $repositoryUrl);
            $package = array_pop($packageParts);
            $project = array_pop($packageParts);
            $tag = false;
            if (preg_match('/^v?(\d+.\d+.\d+)$/', $branch)) {
                $tag = true;
            }
            $parameters = [
                '{baseUrl}' => 'https://' . explode('/', str_replace('https://', '', $repositoryUrl))[0],
                '{package}' => $package,
                '{project}' => $project,
                '{version}' => $branch,
                '{type}' => $tag ? 'tags' : 'heads',
            ];
        }
        if (strpos($repositoryUrl, 'https://github.com') !== false) {
            $repoService = GitRepositoryService::SERVICE_GITHUB;
            $packageParts = explode('/', str_replace(['https://github.com/', '.git'], '', $repositoryUrl));
            $parameters = [
                '{repoName}' => $packageParts[0] . '/' . $packageParts[1],
                '{version}' => $branch,
            ];
        }
        if (strpos($repositoryUrl, 'https://gitlab.com') !== false) {
            $repoService = GitRepositoryService::SERVICE_GITLAB;
            $parameters = [
                '{baseUrl}' => str_replace('.git', '', $repositoryUrl),
                '{version}' => $branch,
            ];
        }
        if (strpos($repositoryUrl, 'https://bitbucket.org') !== false) {
            $repoService = GitRepositoryService::SERVICE_BITBUCKET_CLOUD;
            $packageParts = explode('/', str_replace('https://bitbucket.org/', '', $repositoryUrl));
            $parameters = [
                '{baseUrl}' => 'https://bitbucket.org',
                '{repoName}' => $packageParts[0] . '/' . str_replace('.git', '', $packageParts[1]),
                '{version}' => $branch,
            ];
        }
        if ($repoService !== '') {
            return (new GitRepositoryService())->resolvePublicComposerJsonUrl($repoService, $parameters);
        }
        return '';
    }

    /**
     * @param DocumentationBuildInformationService $documentationBuildInformationService
     * @param string $publicComposerJsonUrl
     * @return ComposerJson
     * @throws ComposerJsonNotFoundException
     * @throws ComposerJsonInvalidException
     */
    protected function resolveComposerJson(
        DocumentationBuildInformationService $documentationBuildInformationService,
        string $publicComposerJsonUrl
    ): ComposerJson {
        $composerJson = $documentationBuildInformationService->fetchRemoteComposerJson($publicComposerJsonUrl);
        return $documentationBuildInformationService->getComposerJsonObject($composerJson);
    }
}
