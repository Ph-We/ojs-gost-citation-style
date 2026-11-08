<?php

/**
 * Standalone test bootstrap. Runtime dependencies are deliberately not shipped
 * with the plugin: OJS already provides them through Citation Style Language.
 */

namespace PKP\plugins {
    if (!class_exists(GenericPlugin::class)) {
        class GenericPlugin
        {
            public function getPluginPath(): string
            {
                return dirname(__DIR__);
            }
        }

        class Hook
        {
        }
    }
}

namespace {
    define('PKP_STRICT_MODE', true);

    if (!function_exists('__')) {
        function __(string $key): string
        {
            return $key;
        }
    }

    if (!class_exists(\Seboettg\CiteProc\CiteProc::class)) {
        $ojsRoot = getenv('OJS_ROOT');
        $runtime = getenv('CITEPROC_RUNTIME');

        if ($ojsRoot) {
            require $ojsRoot . '/plugins/generic/citationStyleLanguage/lib/vendor/autoload.php';
        } elseif ($runtime) {
            $prefixes = [
                'Seboettg\\CiteProc\\' => $runtime . '/citeproc/src/',
                'Seboettg\\Collection\\' => $runtime . '/collection/src/',
                'MyCLabs\\Enum\\' => $runtime . '/php-enum/src/',
            ];
            spl_autoload_register(static function (string $class) use ($prefixes): void {
                foreach ($prefixes as $prefix => $directory) {
                    if (str_starts_with($class, $prefix)) {
                        $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                        if (is_file($file)) {
                            require $file;
                        }
                        return;
                    }
                }
            });
            require $runtime . '/citeproc/src/functions.php';
            require $runtime . '/collection/src/ArrayList/Functions.php';
        } else {
            throw new RuntimeException('Set OJS_ROOT or CITEPROC_RUNTIME to run the tests.');
        }
    }

    require dirname(__DIR__) . '/GostCitationStylePlugin.php';
}
