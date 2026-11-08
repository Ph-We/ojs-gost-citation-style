<?php

/**
 * Adds a local GOST R 7.0.100–2018 journal-article style to the stock
 * Citation Style Language plugin. Does not modify OJS or that plugin.
 */

namespace APP\plugins\generic\gostCitationStyle;

use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use stdClass;

class GostCitationStylePlugin extends GenericPlugin
{
    public const STYLE_ID = 'gost-r-7-0-100-2018-ojs-article';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('CitationStyleLanguage::citationStyleDefaults', $this->addStyle(...));
            Hook::add('CitationStyleLanguage::citation', $this->prepareCitation(...));
        }

        return $success;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.gostCitationStyle.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.gostCitationStyle.description');
    }

    /**
     * Register the style without enabling it or changing journal settings.
     */
    public function addStyle(string $hookName, array $args): bool
    {
        $styles =& $args[0];

        foreach ($styles as $style) {
            if (($style['id'] ?? null) === self::STYLE_ID) {
                return false;
            }
        }

        $styles[] = [
            'id' => self::STYLE_ID,
            'title' => __('plugins.generic.gostCitationStyle.style'),
            'isEnabled' => false,
            'useCsl' => $this->getPluginPath()
                . '/csl/gost-r-7-0-100-2018-article.csl',
        ];

        return false;
    }

    /**
     * CSL cannot test the number of authors. Use the otherwise unused editor
     * name variable as an internal carrier for the record heading. CiteProc
     * still performs all name formatting, including Unicode initials.
     */
    public function prepareCitation(string $hookName, array $args): bool
    {
        $citationData =& $args[0];
        $citationStyle = $args[1];

        if ($citationStyle !== self::STYLE_ID
            || !$citationData instanceof stdClass
            || ($citationData->type ?? null) !== 'article-journal'
        ) {
            return false;
        }

        $authors = $citationData->author ?? [];
        $authorCount = is_array($authors) ? count($authors) : 0;

        if ($authorCount >= 1 && $authorCount <= 3) {
            $citationData->editor = [$authors[0]];
        } else {
            unset($citationData->editor);
        }

        return false;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias(
        '\APP\plugins\generic\gostCitationStyle\GostCitationStylePlugin',
        '\GostCitationStylePlugin'
    );
}
