define([
    "TYPO3/CMS/Backend/Notification",
    "TYPO3/CMS/Backend/Severity",
    "TYPO3/CMS/Backend/MultiStepWizard",
    "TYPO3/CMS/AiSuite/Helper/General",
    "TYPO3/CMS/AiSuite/Helper/Ajax",
    "TYPO3/CMS/AiSuite/Helper/Metadata",
    "TYPO3/CMS/AiSuite/Helper/Image/ResponseHandling",
    "TYPO3/CMS/AiSuite/Helper/Image/StatusHandling",
    "TYPO3/CMS/AiSuite/Helper/Image/SaveHandling",
    "TYPO3/CMS/AiSuite/Helper/Generation",
], function(
    Notification,
    Severity,
    MultiStepWizard,
    General,
    Ajax,
    Metadata,
    ResponseHandling,
    StatusHandling,
    SaveHandling,
    Generation
) {
    addEventListener();

    function addEventListener() {
        document.querySelectorAll('.ai-suite-suggestions-generation-btn').forEach(function(button) {
            button.addEventListener("click", function(ev) {
                ev.preventDefault();
                let fieldName = this.getAttribute('data-field-name');
                let fieldLabel = this.getAttribute('data-field-label');
                let id = parseInt(this.getAttribute('data-id'));
                let pageId = parseInt(this.getAttribute('data-page-id'));
                let languageId = parseInt(this.getAttribute('data-language-id'));
                let table = this.getAttribute('data-table');
                let sysFileId = this.getAttribute('data-sys-file-id');
                let postData = {
                    id: id,
                    pageId: pageId,
                    languageId: languageId,
                    table: table,
                    fieldName: fieldName,
                    fieldLabel: fieldLabel,
                    sysFileId: sysFileId ?? 0,
                };
                addMetadataWizard(postData);
            });
        });
    }

    function addMetadataWizard(postData) {
        MultiStepWizard.setup.settings['postData'] = postData;
        MultiStepWizard.addSlide('ai-suite-metadata-generation-step-1', TYPO3.lang['aiSuite.module.modal.metaDataGeneration'], '', Severity.notice, TYPO3.lang['aiSuite.module.modal.metaDataGenerationSlideOne'], async function (slide, settings) {
            let modalContent = MultiStepWizard.setup.$carousel.closest('.t3js-modal');
            if (modalContent !== null) {
                modalContent.addClass('aisuite-modal');
                modalContent.removeClass('modal-size-default');
                modalContent.addClass('modal-size-large');
            }
            MultiStepWizard.blurCancelStep();
            MultiStepWizard.lockNextStep();
            MultiStepWizard.lockPrevStep();
            const res = await Ajax.sendAjaxRequest('aisuite_metadata_generation_slide_one', settings['postData']);
            slide.html(res.output);
            let modal = MultiStepWizard.setup.$carousel.closest('.modal');
            let aiSuiteGenerateButton = modal.find('.modal-body button#aiSuiteGenerateMetadataBtn');
            let postData = settings['postData'];
            aiSuiteGenerateButton.on('click', async function (ev) {
                let textAiModel = modal.find('.modal-body input[name="libraries[textGenerationLibrary]"]:checked').val() ?? '';
                let newsDetailPlugin = modal.find('.modal-body select#newsDetailPlugin');
                if(modal.find('.modal-body select#newsDetailPlugin') && newsDetailPlugin.val() === '') {
                    Notification.warning(TYPO3.lang['AiSuite.notification.generation.newsDetailPlugin.missingSelection'], TYPO3.lang['AiSuite.notification.generation.newsDetailPlugin.missingSelectionInfo'], 8);
                    return;
                }
                postData.uuid = ev.target.getAttribute('data-uuid');
                postData.textAiModel = textAiModel;
                postData.newsDetailPlugin = newsDetailPlugin.val();
                settings['postData'] = postData;
                MultiStepWizard.unlockNextStep().trigger('click');
            });
        });
        MultiStepWizard.addSlide('ai-suite-metadata-generation-step-2', TYPO3.lang['aiSuite.module.modal.metaDataGeneration'], '', Severity.notice, TYPO3.lang['aiSuite.module.modal.metaDataGenerationSlideTwo'], async function (slide, settings) {
            let modalContent = MultiStepWizard.setup.$carousel.closest('.t3js-modal');
            if (modalContent !== null) {
                modalContent.addClass('aisuite-modal');
                modalContent.removeClass('modal-size-default');
                modalContent.addClass('modal-size-large');
            }
            MultiStepWizard.blurCancelStep();
            MultiStepWizard.lockNextStep();
            MultiStepWizard.lockPrevStep();
            slide.html(Generation.showSpinnerModal(TYPO3.lang['aiSuite.module.modal.metaDataGenerationInProcess'], 665));

            let modal = MultiStepWizard.setup.$carousel.closest('.modal');
            modal.find('.spinner-wrapper').css('overflow', 'hidden');
            StatusHandling.fetchStatus(settings['postData'], modal)
            generateMetaData(settings['postData'])
                .then((res) => {
                    StatusHandling.stopInterval();
                    ResponseHandling.handleResponse(res, TYPO3.lang['aiSuite.module.modal.metaDataError']);
                    slide.html(settings['generatedData']);
                    modal = MultiStepWizard.setup.$carousel.closest('.modal');
                    Metadata.addSelectionEventListeners(modal, settings['postData'], slide);
                })
                .catch(error => {
                    StatusHandling.stopInterval();
                });
        });
        MultiStepWizard.show();
    }

    function generateMetaData(data) {
        return new Promise(async (resolve, reject) => {
            let res = await Ajax.sendAjaxRequest('aisuite_metadata_generation_slide_two', data);
            resolve(res);
        });
    }
});
