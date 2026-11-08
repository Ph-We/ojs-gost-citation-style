<?php

require __DIR__ . '/bootstrap.php';

use APP\plugins\generic\gostCitationStyle\GostCitationStylePlugin;
use Seboettg\CiteProc\CiteProc;

$plugin = new GostCitationStylePlugin();
$styleId = GostCitationStylePlugin::STYLE_ID;
$stylePath = dirname(__DIR__) . '/csl/gost-r-7-0-100-2018-article.csl';
$style = file_get_contents($stylePath, false, null);
$failures = 0;

/* The same markup extensions as CitationStyleLanguagePlugin in OJS 3.5. */
$additionalMarkup = [
    'DOI' => [
        'function' => static function ($item, $renderedValue) {
            $doiWithUrl = 'https://doi.org/' . $item->DOI;
            if (str_contains($renderedValue, $doiWithUrl)) {
                $doiLink = '<a href="' . $doiWithUrl . '">' . $doiWithUrl . '</a>';
                return str_replace($doiWithUrl, $doiLink, $renderedValue);
            }
            $doiLink = '<a href="' . $doiWithUrl . '">' . $item->DOI . '</a>';
            return str_replace($item->DOI, $doiLink, $renderedValue);
        },
        'affixes' => true,
    ],
    'URL' => [
        'function' => static function ($item, $renderedValue) {
            return '<a href="' . $item->URL . '">' . $renderedValue . '</a>';
        },
        'affixes' => false,
    ],
];

$goldenCases = require __DIR__ . '/golden.php';

/* OJS passes the active UI locale to CiteProc. The output must not change. */
foreach (['ru-RU', 'en-US'] as $locale) {
    foreach ($goldenCases as $case) {
        $item = clone $case['item'];
        $args = [&$item, $styleId];
        $plugin->prepareCitation('CitationStyleLanguage::citation', $args);

        $citeProc = new CiteProc($style, $locale, $additionalMarkup);
        $html = $citeProc->render([$item], 'bibliography');
        $actual = html_entity_decode(trim(strip_tags($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($actual !== $case['expected']) {
            $failures++;
            echo "FAIL {$locale} {$case['name']}\nExpected: {$case['expected']}\nActual:   {$actual}\n";
            continue;
        }

        if (isset($item->DOI)) {
            $expectedLink = '<a href="https://doi.org/' . $item->DOI . '">' . $item->DOI . '</a>';
            if (!str_contains($html, $expectedLink) || str_contains($html, $item->URL . '</a>')) {
                $failures++;
                echo "FAIL {$locale} {$case['name']}: incorrect DOI hyperlink markup\n{$html}\n";
                continue;
            }
        } else {
            $expectedLink = '<a href="' . $item->URL . '">' . $item->URL . '</a>';
            if (!str_contains($html, $expectedLink)) {
                $failures++;
                echo "FAIL {$locale} {$case['name']}: incorrect URL hyperlink markup\n{$html}\n";
                continue;
            }
        }

        echo "PASS {$locale} {$case['name']}\n";
    }
}

/* Registration must be idempotent and must not enable or promote the style. */
$styles = [['id' => 'apa', 'isPrimary' => true]];
$registrationArgs = [&$styles];
$plugin->addStyle('CitationStyleLanguage::citationStyleDefaults', $registrationArgs);
$plugin->addStyle('CitationStyleLanguage::citationStyleDefaults', $registrationArgs);
$config = $styles[1] ?? [];
$registrationValid = count($styles) === 2
    && ($config['id'] ?? null) === $styleId
    && ($config['isEnabled'] ?? null) === false
    && !array_key_exists('isPrimary', $config)
    && is_file($config['useCsl'] ?? '');

if (!$registrationValid) {
    $failures++;
    echo "FAIL style registration\n";
} else {
    echo "PASS style registration is safe and idempotent\n";
}

/* Data for other CSL styles and other resource types must remain untouched. */
$item = (object) [
    'type' => 'article-journal',
    'author' => [(object) ['family' => 'Иванов', 'given' => 'Иван Иванович']],
];
$otherStyle = 'apa';
$otherStyleArgs = [&$item, $otherStyle];
$plugin->prepareCitation('CitationStyleLanguage::citation', $otherStyleArgs);
if (isset($item->editor)) {
    $failures++;
    echo "FAIL isolation from other styles\n";
} else {
    echo "PASS isolation from other styles\n";
}

/* Reproduce the stock OJS 3.5 role filter before our hook. Translator groups
   deliberately take precedence when the same group is selected twice. */
$contributors = [
    (object) ['userGroupId' => 17, 'family' => 'Иванов', 'given' => 'Иван Иванович'],
    (object) ['userGroupId' => 17, 'family' => 'Петров', 'given' => 'Пётр Петрович'],
];
$mapStockContributors = static function (array $items, array $authorsGroups, array $translatorsGroups): stdClass {
    $data = (object) ['type' => 'article-journal'];
    foreach ($items as $contributor) {
        $current = (object) ['family' => $contributor->family, 'given' => $contributor->given];
        switch (true) {
            case in_array($contributor->userGroupId, $translatorsGroups):
                $data->translator[] = $current;
                break;
            case in_array($contributor->userGroupId, $authorsGroups):
                $data->author[] = $current;
                break;
            default:
                $data->author ??= [];
        }
    }
    return $data;
};
$roleCases = [
    'distinct author and translator groups' => [[17], [18], 2, 0, 1],
    'overlapping author and translator groups' => [[17], [17], 0, 2, 0],
    'missing author group' => [[], [18], 0, 0, 0],
];
foreach ($roleCases as $name => [$authorsGroups, $translatorsGroups, $expectedAuthors, $expectedTranslators, $expectedEditors]) {
    $item = $mapStockContributors($contributors, $authorsGroups, $translatorsGroups);
    $args = [&$item, $styleId];
    $plugin->prepareCitation('CitationStyleLanguage::citation', $args);
    $valid = count($item->author ?? []) === $expectedAuthors
        && count($item->translator ?? []) === $expectedTranslators
        && count($item->editor ?? []) === $expectedEditors;
    if (!$valid) {
        $failures++;
        echo "FAIL role pipeline: {$name}\n";
    } else {
        echo "PASS role pipeline: {$name}\n";
    }
}

/* Unsupported resource types must produce no punctuation-only citation. */
$item = (object) ['type' => 'book'];
$html = (new CiteProc($style, 'ru-RU'))->render([$item], 'bibliography');
if (trim(strip_tags($html)) !== '') {
    $failures++;
    echo "FAIL unsupported resource type returns an empty result\n";
} else {
    echo "PASS unsupported resource type returns an empty result\n";
}

if ($failures > 0) {
    echo "\n{$failures} test(s) failed.\n";
    exit(1);
}

echo "\nAll tests passed.\n";
