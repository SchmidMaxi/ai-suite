<?php

declare(strict_types=1);

namespace AutoDudes\AiSuite\FormEngine\FieldControl;

use AutoDudes\AiSuite\Utility\BackendUserUtility;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class AiSeoOpenGraphDescription extends AbstractNode
{
    public function render(): array
    {
        if(!BackendUserUtility::checkPermissions('tx_aisuite_features:enable_metadata_generation')) {
            return [];
        }
        return [
            'iconIdentifier' => 'actions-document-synchronize',
            'title' => LocalizationUtility::translate('LLL:EXT:ai_suite/Resources/Private/Language/locallang.xlf:AiSuite.generation.ogDescriptionSuggestions'),
            'linkAttributes' => [
                'id' => 'og_description_generation',
                'class' => 'ai-suite-suggestions-generation-btn',
                'data-id' => $this->data['databaseRow']['uid'],
                'data-page-id' => $this->data['databaseRow']['uid'],
                'data-language-id' => $this->data['databaseRow']['sys_language_uid'],
                'data-table' => $this->data['tableName'],
                'data-field-name' => 'og_description',
                'data-field-label' => 'OgDescription',
            ],
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create('@autodudes/ai-suite/metadata/generate-suggestions.js')
            ],
        ];
    }
}
