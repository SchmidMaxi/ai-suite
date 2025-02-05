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

use AutoDudes\AiSuite\Domain\Model\Dto\PageContent;
use AutoDudes\AiSuite\Enumeration\GenerationLibrariesEnumeration;
use AutoDudes\AiSuite\Factory\PageContentFactory;
use AutoDudes\AiSuite\Service\ContentService;
use AutoDudes\AiSuite\Service\RichTextElementService;
use AutoDudes\AiSuite\Utility\LibraryUtility;
use AutoDudes\AiSuite\Utility\PromptTemplateUtility;
use AutoDudes\AiSuite\Utility\UuidUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class ContentController extends AbstractBackendController
{
    protected ContentService $contentService;
    protected Context $context;
    protected PageContentFactory $pageContentFactory;

    public function __construct(
        ContentService     $contentService,
        Context            $context,
        PageContentFactory $pageContentFactory
    ) {
        parent::__construct();
        $this->contentService = $contentService;
        $this->context = $context;
        $this->pageContentFactory = $pageContentFactory;
        $this->pageRenderer->addCssFile('EXT:ai_suite/Resources/Public/Css/backend-basics-styles.css');
    }

    public function initializeCreateContentAction(): void
    {
        $this->request = $this->request->withArgument('request', $this->request);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function createContentAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->moduleData = $request->getAttribute('moduleData');
        $request = $request->withAttribute('extbase', new ExtbaseRequestParameters(ContentController::class));
        $extbaseRequest = new Request($request);
        $extbaseRequest = $extbaseRequest->withControllerName('Content');
        $extbaseRequest = $extbaseRequest->withControllerActionName('createContent');
        $extbaseRequest = $extbaseRequest->withControllerExtensionName('AiSuite');
        $this->moduleTemplate = $this->moduleTemplateFactory->create($extbaseRequest);
        $this->moduleTemplate->setTitle('AI Suite');
        $this->moduleTemplate->setModuleId('aiSuite');

        $table = array_key_first($request->getQueryParams()['edit']);
        $librariesAnswer = $this->requestService->sendLibrariesRequest(GenerationLibrariesEnumeration::CONTENT, 'createContentElement', ['text', 'image']);
        if ($librariesAnswer->getType() === 'Error') {
            $this->moduleTemplate->addFlashMessage($librariesAnswer->getResponseData()['message'], LocalizationUtility::translate('aiSuite.module.errorFetchingLibraries.title', 'ai_suite'), AbstractMessage::ERROR);
            $this->moduleTemplate->assign('error', true);
            return $this->htmlResponse($this->moduleTemplate->render());
        }

        $content = PageContent::createEmpty();
        $defVals = [];
        if (array_key_exists('defVals', $request->getQueryParams())) {
            $defVals = $request->getQueryParams()['defVals'];
            $content->setSysLanguageUid((int)$request->getQueryParams()['defVals'][$table]['sys_language_uid']);
            $content->setColPos((int)$request->getQueryParams()['defVals'][$table]['colPos']);
            $content->setPid((int)$request->getQueryParams()['defVals'][$table]['pid']);
            $content->setCType($request->getQueryParams()['defVals'][$table]['CType'] ?? 'text');
            $txContainerParent = isset($request->getQueryParams()['defVals'][$table]['tx_container_parent']) ? (int)$request->getQueryParams()['defVals'][$table]['tx_container_parent'] : 0;
            $content->setContainerParentUid($txContainerParent);
        } else {
            $content->setPid((int)$request->getQueryParams()['pid']);
            $content->setCType($request->getQueryParams()['recordType'] ?? '');
        }
        $content->setReturnUrl($request->getQueryParams()['returnUrl'] ?? '');
        if (array_key_exists('edit', $request->getQueryParams()) && array_key_exists($table, $request->getQueryParams()['edit'])) {
            $content->setUidPid(key($request->getQueryParams()['edit'][$table]) ?? $request->getQueryParams()['id']);
        } else {
            $content->setUidPid($request->getQueryParams()['id']);
        }

        $requestFields = $this->contentService->fetchRequestFields($request, $defVals, $content->getCType(), $content->getPid(), $table);
        $content->setAvailableTcaColumns($requestFields);

        if (isset($request->getQueryParams()['selectedTcaColumns'])) {
            $selectedTcaColumns = json_decode($request->getQueryParams()['selectedTcaColumns'], true);
        } else {
            $selectedTcaColumns = $requestFields;
        }

        $moduleName = 'web_aisuite';
        $uriParameters = [
            'id' => $content->getPid(),
            'action' => 'requestContent',
            'controller' => 'Content',
            'table' => $table,
        ];
        $actionUri = (string)$this->backendUriBuilder->buildUriFromRoute($moduleName, $uriParameters);

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:ai_suite/Resources/Private/Language/locallang.xlf');
        $this->pageRenderer->loadJavaScriptModule('@autodudes/ai-suite/content/creation.js');

        $textAi = $request->getQueryParams()['textGenerationLibraryKey'] ?? '';
        $imageAi = $request->getQueryParams()['imageGenerationLibraryKey'] ?? '';
        $additionalImageSettings = $request->getQueryParams()['additionalImageSettings'] ?? '';
        $this->moduleTemplate->assignMultiple([
            'content' => $content,
            'actionUri' => $actionUri,
            'textGenerationLibraries' => LibraryUtility::prepareLibraries($librariesAnswer->getResponseData()['textGenerationLibraries'], $textAi),
            'imageGenerationLibraries' => LibraryUtility::prepareLibraries($librariesAnswer->getResponseData()['imageGenerationLibraries'], $imageAi),
            'additionalImageSettings' => LibraryUtility::prepareAdditionalImageSettings($additionalImageSettings),
            'paidRequestsAvailable' => $librariesAnswer->getResponseData()['paidRequestsAvailable'],
            'promptTemplates' => PromptTemplateUtility::getAllPromptTemplates(
                count($defVals) > 0 ? 'contentElement' : 'newsRecord',
                count($defVals) > 0 ? $request->getQueryParams()['defVals'][$table]['CType'] : '',
                $content->getSysLanguageUid()
            ),
            'initialPrompt' => $request->getQueryParams()['initialPrompt'] ?? '',
            'selectedTcaColumns' => $selectedTcaColumns,
            'defVals' => $defVals,
            'showMaxImageHint' => true,
            'uuid' => UuidUtility::generateUuid(),
            'contentTypeTitle' => $content->getCType() === '0' ? 'news' : $content->getCType()
        ]);

        return $this->htmlResponse($this->moduleTemplate->render('Content/CreateContent'));
    }

    public function initializeRequestContentAction(): void
    {
        if (!$this->request->hasArgument('content')) {
            $this->request = $this->request->withArgument('content', PageContent::createEmpty());
        }
    }

    /**
     * @throws AspectNotFoundException
     * @throws SiteNotFoundException
     */
    public function requestContentAction(PageContent $content): ResponseInterface
    {
        $table = $this->request->getQueryParams()['table'];
        $selectedTcaColumns = $this->request->getParsedBody()['content']['selectedTcaColumns'] ?? [];
        $availableTcaColumns = json_decode($this->request->getParsedBody()['content']['availableTcaColumns'], true) ?? [];
        $defVals = json_decode($this->request->getParsedBody()['defVals'], true) ?? [];
        $additionalImageSettings = $this->request->getParsedBody()['additionalImageSettings'] ?? '';
        $textAi = !empty($this->request->getParsedBody()['libraries']['textGenerationLibrary']) ? $this->request->getParsedBody()['libraries']['textGenerationLibrary'] : '';
        $imageAi = !empty($this->request->getParsedBody()['libraries']['imageGenerationLibrary']) ? $this->request->getParsedBody()['libraries']['imageGenerationLibrary'] : '';

        $uriParams = [
            'edit' => [
                $table => [
                    $content->getUidPid() => 'new',
                ],
            ],
            'returnUrl' => $content->getReturnUrl(),
            'defVals' => $defVals,
            'initialPrompt' => $content->getInitialPrompt(),
            'selectedTcaColumns' => json_encode($selectedTcaColumns),
            'textGenerationLibraryKey' => $textAi,
            'imageGenerationLibraryKey' => $imageAi,
            'additionalImageSettings' => $additionalImageSettings,
        ];
        if ($table === 'tx_news_domain_model_news') {
            $uriParams['recordType'] = '0';
            $uriParams['recordTable'] = 'tx_news_domain_model_news';
            $uriParams['pid'] = $content->getPid();
        }

        $regenerateActionUri = (string)$this->backendUriBuilder->buildUriFromRoute('ai_suite_record_edit', $uriParams);
        $content->setRegenerateReturnUrl($regenerateActionUri);
        $this->moduleTemplate->assign('regenerateActionUri', $regenerateActionUri);

        if ($content->getPid() === 0 && $content->getUid() == 0 && $content->getUidPid() === 0) {
            $this->moduleTemplate->assign('error', true);
            $this->moduleTemplate->addFlashMessage(
                LocalizationUtility::translate('aiSuite.module.errorMissingArguments.message', 'ai_suite'),
                LocalizationUtility::translate('aiSuite.module.errorMissingArguments.title', 'ai_suite'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->htmlResponse($this->moduleTemplate->render('Content/RequestContent'));
        }

        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $site = $siteFinder->getSiteByPageId($content->getPid());
            $siteLanguage = $site->getLanguageById($content->getSysLanguageUid());
            $langIsoCode = $siteLanguage->getLocale()->getLanguageCode();
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            $this->addFlashMessage(
                $exception->getMessage(),
                LocalizationUtility::translate('aiSuite.module.cannotSendRequest.title', 'ai_suite'),
                ContextualFeedbackSeverity::ERROR
            );
            $this->moduleTemplate->assign('error', true);
            return $this->htmlResponse($this->moduleTemplate->render('Content/RequestContent'));
        }

        $requestFields = [];
        foreach ($selectedTcaColumns as $type => $fields) {
            $requestFields[$type] = [
                'label' => $availableTcaColumns[$type]['label'],
            ];
            if (array_key_exists('foreignField', $availableTcaColumns[$type])) {
                $requestFields[$type]['foreignField'] = $availableTcaColumns[$type]['foreignField'];
            }
            if (array_key_exists('text', $fields)) {
                foreach ($fields['text'] as $fieldName => $renderType) {
                    if ($renderType !== '') {
                        $requestFields[$type]['text'][$fieldName] = $availableTcaColumns[$type]['text'][$fieldName];
                    }
                }
            }
            if (array_key_exists('image', $fields)) {
                foreach ($fields['image'] as $fieldName => $renderType) {
                    if ($renderType !== '') {
                        $requestFields[$type]['image'][$fieldName] = $availableTcaColumns[$type]['image'][$fieldName];
                    }
                }
            }
        }
        $content->setSelectedTcaColumns($requestFields);
        $models = $this->contentService->checkRequestModels($requestFields, ['text' => $textAi, 'image' => $imageAi]);
        $answer = $this->requestService->sendDataRequest(
            'createContentElement',
            [
                'request_fields' => json_encode($requestFields),
                'c_type' => $content->getCType(),
                'additional_image_settings' => $additionalImageSettings,
                'uuid' => $this->request->getParsedBody()['uuid'] ?? '',
            ],
            $content->getInitialPrompt(),
            strtoupper($langIsoCode),
            $models
        );
        if ($answer->getType() === 'Error') {
            $this->moduleTemplate->addFlashMessage(
                $answer->getResponseData()['message'],
                LocalizationUtility::translate('aiSuite.module.errorValidContentElementResponse.title', 'ai_suite'),
                ContextualFeedbackSeverity::ERROR
            );
            $this->moduleTemplate->assign('error', true);
            return $this->htmlResponse($this->moduleTemplate->render('Content/RequestContent'));
        }
        $contentElementData = json_decode($answer->getResponseData()['contentElementData'], true);
        foreach ($contentElementData as $tableName => $fields) {
            foreach ($fields as $key => $field) {
                if (is_array($field) && array_key_exists('text', $field)) {
                    foreach ($field['text'] as $fieldName => $renderType) {
                        if (array_key_exists('rteConfig', $contentElementData[$tableName][$key]['text'][$fieldName])) {
                            $rteConfigData = is_array($contentElementData[$tableName][$key]['text'][$fieldName]['rteConfig'])
                                ? $contentElementData[$tableName][$key]['text'][$fieldName]['rteConfig']
                                : json_decode($contentElementData[$tableName][$key]['text'][$fieldName]['rteConfig'], true);
                            $richTextElementService = GeneralUtility::makeInstance(RichTextElementService::class, $rteConfigData);
                            $value = $contentElementData[$tableName][$key]['text'][$fieldName]['content'] ?? '';
                            $contentElementData[$tableName][$key]['text'][$fieldName]['rteConfig'] = $richTextElementService->fetchRteConfig($value);
                        }
                    }
                }
            }
        }
        $content->setContentElementData($contentElementData);
        $this->moduleTemplate->assign('content', $content);
        $this->moduleTemplate->assign('initialImageAi', $imageAi);
        $this->moduleTemplate->assign('uuid', UuidUtility::generateUuid());

        $this->pageRenderer->loadJavaScriptModule('@autodudes/ai-suite/content/validation.js');
        $moduleName = 'web_aisuite';
        $uriParameters = [
            'id' => $content->getPid(),
            'action' => 'createPageContent',
            'controller' => 'Content'
        ];
        $actionUri = (string)$this->backendUriBuilder->buildUriFromRoute($moduleName, $uriParameters);
        $this->moduleTemplate->assign('actionUri', $actionUri);
        $this->moduleTemplate->addFlashMessage(
            LocalizationUtility::translate('aiSuite.module.fetchingDataSuccessful.message', 'ai_suite'),
            LocalizationUtility::translate('aiSuite.module.fetchingDataSuccessful.title', 'ai_suite')
        );
        return $this->htmlResponse($this->moduleTemplate->render('Content/RequestContent'));
    }

    public function createPageContentAction(PageContent $content): ResponseInterface
    {
        try {
            $selectedTcaColumns = json_decode($this->request->getParsedBody()['content']['selectedTcaColumns'], true) ?? [];
            $contentElementTextData = $this->request->getParsedBody()['content']['contentElementData'] ?? [];
            $contentElementImageData = [];

            $parsedBody = $this->request->getParsedBody() ?? [];
            if (array_key_exists('fileData', $parsedBody)) {
                foreach ($parsedBody['fileData']['content']['contentElementData'] as $table => $fieldsArray) {
                    foreach ($fieldsArray as $key => $fields) {
                        foreach ($fields as $fieldName => $fieldData) {
                            if (array_key_exists('newImageUrl', $fieldData)) {
                                $contentElementImageData[$table][$key][$fieldName]['newImageUrl'] = $fieldData['newImageUrl'];
                                $imageTitle = '';
                                if (!empty($fieldData['imageTitle'])) {
                                    $imageTitle = $fieldData['imageTitle'];
                                }
                                if (!empty($fieldData['imageTitleFreeText'])) {
                                    $imageTitle = $fieldData['imageTitleFreeText'];
                                }
                                $contentElementImageData[$table][$key][$fieldName]['imageTitle'] = $imageTitle;
                            }
                        }
                    }
                }
            }

            $contentElementIrreFields = [];
            foreach ($selectedTcaColumns as $table => $fields) {
                if (array_key_exists('foreignField', $fields)) {
                    $contentElementIrreFields[$table] = $fields['foreignField'];
                }
            }
            $this->pageContentFactory->createContentElementData($content, $contentElementTextData, $contentElementImageData, $contentElementIrreFields);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            $this->addFlashMessage(
                $exception->getMessage(),
                LocalizationUtility::translate('aiSuite.module.errorPageContentNotCreated.title', 'ai_suite'),
                ContextualFeedbackSeverity::ERROR
            );
            $this->moduleTemplate->assign('errorActionUri', $content->getRegenerateReturnUrl());
            return $this->htmlResponse($this->moduleTemplate->render('Content/CreatePageContent'));
        }
        return $this->redirectToUri($content->getReturnUrl());
    }
}
