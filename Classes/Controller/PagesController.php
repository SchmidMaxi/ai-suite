<?php

/***
 *
 * This file is part of the "ai_suite" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *
 ***/

namespace AutoDudes\AiSuite\Controller;

use AutoDudes\AiSuite\Domain\Model\Dto\PageStructureInput;
use AutoDudes\AiSuite\Domain\Model\Dto\ServerRequest\ServerRequest;
use AutoDudes\AiSuite\Domain\Repository\PagesRepository;
use AutoDudes\AiSuite\Domain\Repository\RequestsRepository;
use AutoDudes\AiSuite\Enumeration\GenerationLibrariesEnumeration;
use AutoDudes\AiSuite\Exception\AiSuiteServerException;
use AutoDudes\AiSuite\Factory\PageStructureFactory;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuite\Utility\ModelUtility;
use AutoDudes\AiSuite\Utility\PromptTemplateUtility;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class PagesController extends AbstractBackendController
{
    protected SendRequestService $requestService;

    protected RequestsRepository $requestsRepository;
    protected PageStructureFactory $pageStructureFactory;
    protected PagesRepository $pagesRepository;
    protected DataHandler $dataHandler;

    public function __construct(
        array $extConf,
        SendRequestService $requestService,
        RequestsRepository $requestsRepository,
        PageStructureFactory $pageStructureFactory,
        PagesRepository $pagesRepository,
        DataHandler $dataHandler
    ) {
        parent::__construct($extConf);
        $this->extConf = $extConf;
        $this->requestService = $requestService;
        $this->requestsRepository = $requestsRepository;
        $this->pageStructureFactory = $pageStructureFactory;
        $this->pagesRepository = $pagesRepository;
        $this->dataHandler = $dataHandler;
    }

    public function overviewAction(): ResponseInterface
    {
        $this->view->assignMultiple([
            'sectionActive' => 'pages',
        ]);
        $this->moduleTemplate->setContent($this->view->render());
        return $this->htmlResponse($this->moduleTemplate->renderContent());
    }

    /**
     * @throws AiSuiteServerException
     */
    public function pageStructureAction(): ResponseInterface
    {
        $this->pageRenderer->loadRequireJsModule('TYPO3/CMS/AiSuite/Pages/Creation');
        $librariesAnswer = $this->requestService->sendRequest(
            new ServerRequest(
                $this->extConf,
                'generationLibraries',
                [
                    'library_types' => GenerationLibrariesEnumeration::PAGETREE,
                    'target_endpoint' => 'pageTree',
                    'keys' => ModelUtility::fetchKeysByModelType($this->extConf,['text'])
                ]
            )
        );
        if ($librariesAnswer->getType() === 'Error') {
            $this->addFlashMessage(
                $librariesAnswer->getResponseData()['message'],
                LocalizationUtility::translate('aiSuite.module.errorFetchingLibraries.title', 'ai_suite'),
                AbstractMessage::ERROR
            );
            return $this->redirect('overview');
        }

        if ($librariesAnswer->getType() === 'Error') {
            $this->moduleTemplate->addFlashMessage($librariesAnswer->getResponseData()['message'], LocalizationUtility::translate('aiSuite.module.errorFetchingLibraries.title', 'ai_suite'), AbstractMessage::ERROR);
            $this->view->assign('error', true);
            $this->moduleTemplate->setContent($this->view->render());
            return $this->htmlResponse($this->moduleTemplate->renderContent());
        }
        $this->view->assignMultiple([
            'input' => PageStructureInput::createEmpty(),
            'pagesSelect' => $this->getPagesInWebMount(),
            'sectionActive' => 'pages',
            'textGenerationLibraries' => $librariesAnswer->getResponseData()['textGenerationLibraries'],
            'paidRequestsAvailable' => $librariesAnswer->getResponseData()['paidRequestsAvailable'],
            'promptTemplates' => PromptTemplateUtility::getAllPromptTemplates('pageTree'),
        ]);
        $this->moduleTemplate->setContent($this->view->render());
        return $this->htmlResponse($this->moduleTemplate->renderContent());
    }

    public function validatePageStructureResultAction(PageStructureInput $input): ResponseInterface
    {
        $textAi = !empty($this->request->getParsedBody()['libraries']['textGenerationLibrary']) ? $this->request->getParsedBody()['libraries']['textGenerationLibrary'] : '';

        $site = $this->request->getAttribute('site');
        $defaultLanguageIsoCode = $site->getDefaultLanguage()->getTwoLetterIsoCode();

        $answer = $this->requestService->sendRequest(
            new ServerRequest(
                $this->extConf,
                'pageTree',
                [
                    'keys' => ModelUtility::fetchKeysByModel($this->extConf, [$textAi])
                ],
                $input->getPlainPrompt(),
                $defaultLanguageIsoCode,
                [
                    'text' => $textAi,
                ],
            )
        );
        if ($answer->getType() === 'Error') {
            $this->addFlashMessage(
                $answer->getResponseData()['message'],
                LocalizationUtility::translate('aiSuite.module.errorFetchingPagetreeResponse.title', 'ai_suite'),
                AbstractMessage::ERROR
            );
            return $this->redirect('pageStructure');
        }
        if(array_key_exists('free_requests', $answer->getResponseData()) && array_key_exists('free_requests', $answer->getResponseData())) {
            $this->requestsRepository->setRequests($answer->getResponseData()['free_requests'], $answer->getResponseData()['paid_requests']);
            BackendUtility::setUpdateSignal('updateTopbar');
        }
        $input->setAiResult($answer->getResponseData()['pagetreeResult']);
        $this->view->assignMultiple([
            'input' => $input,
            'pagesSelect' => $this->getPagesInWebMount(),
            'sectionActive' => 'pages',
            'textGenerationLibraries' => json_decode($input->getTextGenerationLibraries(), true),
        ]);
        $this->pageRenderer->loadRequireJsModule('TYPO3/CMS/AiSuite/Pages/Validation');
        $this->addFlashMessage(
            LocalizationUtility::translate('aiSuite.module.fetchingDataSuccessful.message', 'ai_suite'),
            LocalizationUtility::translate('aiSuite.module.fetchingDataSuccessful.title', 'ai_suite'),
        );
        $this->moduleTemplate->setContent($this->view->render());
        return $this->htmlResponse($this->moduleTemplate->renderContent());
    }

    public function createValidatedPageStructureAction(PageStructureInput $input): ResponseInterface
    {
        $selectedPageTreeContent = $this->request->getParsedBody()['tx_aisuite_web_aisuiteaisuite']['selectedPageTreeContent'] ?? '';
        $input->setAiResult(json_decode($selectedPageTreeContent, true));
        $this->pageStructureFactory->createFromArray($input->getAiResult(), $input->getStartStructureFromPid());
        BackendUtility::setUpdateSignal('updatePageTree');
        $this->addFlashMessage(
            LocalizationUtility::translate('aiSuite.module.pagetreeGenerationSuccessful.title', 'ai_suite'),
            LocalizationUtility::translate('aiSuite.module.pagetreeGenerationSuccessful.title', 'ai_suite'),
        );
        return $this->redirect('overview');
    }

    private function getPagesInWebMount(): array
    {
        $foundPages = $this->pagesRepository->findAiStructurePages('uid');
        $pagesSelect = [];
        foreach ($foundPages as $page) {
            $pageInWebMount = $this->getBackendUser()->isInWebMount($page['uid']);
            if($pageInWebMount !== null) {
                $pagesSelect[$page['uid']] = $page['title'];
            }
        }
        return $pagesSelect;
    }
}
