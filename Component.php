<?php

namespace attitude\Mustache;

use \attitude\Elements\HTTPException;
use \attitude\Elements\Singleton_Prototype;
use \attitude\Elements\DependencyContainer;

class DataPreprocessor_Component extends Singleton_Prototype
{
    protected $engine = null;

    //-- Whether to protect emails from harvesters using Hivelogic Encoder
    protected $antispam = true;

    //-- Mustache Settings
    protected $cache  = null;
    protected $loader = null;
    protected $partials_loader = null;
    protected $helpers = array();
    protected $expanders = array();

    protected function __construct()
    {
        $this->setCache(DependencyContainer::get('global::mustacheCachePath', null));
        $this->setViews(DependencyContainer::get('global::mustacheViews', null));
        $this->setPartials(DependencyContainer::get('global::mustachePartials', null));

        $this->setPredefinedHelpers();
        $this->setHelpers(DependencyContainer::get('global::mustacheHelpers', null));
        $this->setExpanders(DependencyContainer::get('global::dataExpanders', null));

        $this->antispam = !! DependencyContainer::get('global::antispamEnabled', true);

        $this->engine = new \Mustache_Engine(array(
            'cache' => $this->cache,
            'loader' => $this->loader,
            'partials_loader' => $this->partials_loader,
            'helpers' => $this->helpers
        ));

        DependencyContainer::set('global::MustacheEngine', $this->engine);
    }

    protected function translate_data(array $data, $language = null)
    {
        if (! is_string($language)) {
            return $data;
        }

        // Fast decision
        if (isset($data[$language])) {
            return $data[$language];
        }

        $keys = array_keys($data);

        $passed = 2;
        foreach ($keys as $key) {
            // Is translatable but fast decision failed to find tranlation
            if (is_string($key) && preg_match(DependencyContainer::get('global::languageRegex'), $key, $devnull)) {
                $passed--;

                if ($passed===0) {
                    return null;
                }
            }
        }

        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $value = $this->translate_data($value, $language);
            }
        }

        return $data;
    }

    protected function is_assoc_array(array $array, $speedy=true) {
        if ($speedy) {
            return ($array !== array_values($array));
        }

        // More memory efficient
        return $array = array_keys($array); return ($array !== array_keys($array));
    }

    protected function arraysHaveItems(array $data)
    {
        foreach ($data as $k => &$v) {
            if (is_array($v)) {
                $v = $this->arraysHaveItems($v);
                if (! $this->is_assoc_array($v)) {
                    $count = count($v);
                    $data['__has'.ucfirst($k)] = empty($v) ? false : $count;
                }
            }
        }

        return $data;
    }

    protected function expandData($data, $level = 0)
    {
        if (!is_array($data)) {
            return $data;
        }

        $level++;

        foreach ($data as $key => &$values) {
            if (is_array($values)) {
                $values_to_merge = array();
                $is_sequence_array = ! $this->is_assoc_array($values);

                foreach($values as $k => $v) {
                    if (is_string($k) && strstr($k, '()')) {
                        $function = rtrim($k, '()');

                        if (isset($this->expanders[$function]) && is_callable($this->expanders[$function])) {
                            unset($values[$k]);

                            if ($level <= 2) {
                                try {
                                    $values_to_merge[] =
                                        is_object($v) ?
                                        $this->expandData($this->expanders[$function]->__invoke((array) $v), $level)
                                        :
                                        $this->expandData($this->expanders[$function]->__invoke($v), $level)
                                    ;
                                } catch (HTTPException $e) { /* do nothing */ }
                            }
                        }
                    }
                }

                foreach ($values_to_merge as &$_values) {
                    if (is_array($_values)) {
                        $values = array_merge($values, $_values);
                    } else {
                        $values = $_values;
                    }
                }

                if (empty($values)) {
                    unset($data[$key]);
                } elseif ($is_sequence_array) {
                    // Reindex array starting from 0
                    $values = array_values($values);
                }

                if (is_array($values)) {
                    $values = $this->expandData($values);
                }
            }
        }

        return $data;
    }

    private function fixMissingKeys($data)
    {
        if (is_array($data)) {
            foreach ($data as &$values) {
                $values = $this->fixMissingKeys($values);

                if (is_array($values) && !$this->is_assoc_array($values)) {
                    $empty_keys = array();
                    foreach ($values as &$v) {
                        if (is_array($v) && $this->is_assoc_array($v)) {
                            foreach ($v as $empty_key => $empty_value) {
                                $empty_keys[$empty_key] = (is_array($empty_value)) ? array() : null;
                            }
                        }
                    }

                    foreach ($values as &$v) {
                        foreach ($empty_keys as $empty_key => $empty_value) {
                            if (!isset($v[$empty_key])) {
                                $v[$empty_key] = $empty_value;
                            }
                        }
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Remove any keys with `__` prefix
     *
     * @param $data array
     * @return array Filtered $data
     *
     */
    private function removeHiddenKeys($data)
    {
        // Show all private data
        if (DependencyContainer::get('global::showAllHiddenAttrs', false)) {
            return $data;
        }

        // Keep at least keys with one `_` prefixed
        $one = DependencyContainer::get('global::showBasicHiddenAttrs', false);

        if (is_array($data)) {
            foreach ($data as $k => &$v) {
                if (($one && substr($k, 0, 2) === '__') || (!$one && substr($k, 0, 1) === '_')) {
                    unset($data[$k]);

                    continue;
                }

                $v = $this->removeHiddenKeys($v);
            }
        }

        return $data;
    }

    public function render($data, $language = null)
    {
        // Enhance data for templates
        if (!empty($this->expanders)) {
            $data = $this->expandData($data);
        }

        // Translate
        if ($language) {
            $data = $this->translate_data($data, $language);
        }

        // Fix Missing context and prevent context stack lookups
        $data = $this->fixMissingKeys($data);

        // Add hasItems helpers next to arrays
        $data = $this->arraysHaveItems($data);

        // Pick template from data
        $template = isset($data['data']['template']) ? $data['data']['template'] : 'default';

        // Determine Accept HTTP header
        $accept = array();
        try {
            $request = DependencyContainer::get('global::request');
            $accept = $request->getAccept();
        } catch(HTTPException $e) {}

        if (in_array('json', $accept) || (isset($_GET['format']) && strstr($_GET['format'], 'json'))) {
            $data = $this->removeHiddenKeys($data);
            self::printData($data, $_GET['format'] === 'json-pretty');

            exit;
        }

        try {
            $view = $this->engine->render($template, $data);
        } catch (\Mustache_Exception $e) {
            throw new HTTPException(404, $e->getMessage());
        }

        return $this->antispam ? str_replace('[[[at]]]', '@', self::antispam($view)) : $view;
    }

    static public function printData($data, $pretty=false)
    {
        header('Content-Type: text/json');
        echo $pretty ? json_encode($data, JSON_PRETTY_PRINT) : json_encode($data);
    }

    public function setCache($path)
    {
        if ($path===null) { return $this; }

        if (is_string($path) && strlen(trim($path))>0 && realpath(trim($path))) {
            $this->cache = $path;

            return $this;
        }

        throw new HTTPException(500, 'HTML Engine cache must be a real path.');
    }

    public function setViews($path)
    {
        if ($path===null) { return $this; }

        if (is_string($path) && strlen(trim($path))>0 && realpath(trim($path))) {
            $this->loader = new \Mustache_Loader_FilesystemLoader($path);

            return $this;
        }

        if ($path instanceof \Mustache_Loader) {
            return $this->setViewsLoader($path);
        }

        throw new HTTPException(500, 'HTML Engine cache must be a real path or an instance implementing Mustache_Loader interface.');
    }

    public function setViewsLoader(\Mustache_Loader $loader) {
        $this->loader = $loader;
    }

    public function setPartials($path)
    {
        if ($path===null) { return $this; }

        if (is_string($path) && strlen(trim($path))>0 && realpath(trim($path))) {
            $this->partials_loader = new \Mustache_Loader_FilesystemLoader($path);

            return $this;
        }

        if ($path instanceof \Mustache_Loader) {
            return $this->setPartialsLoader($path);
        }

        throw new HTTPException(500, 'HTML Engine cache must be a real path or an instance implementing Mustache_Loader interface.');
    }

    public function setPartialsLoader(\Mustache_Loader $loader) {
        $this->partials_loader = $loader;
    }

    public static function antispam($str) {
        static $encoder = null;

        // init
        if ($encoder===null) {
            require_once dirname(__FILE__).'/vendor/jnicol/standalone-phpenkoder/StandalonePHPEnkoder.php';
            $encoder = new \StandalonePHPEnkoder();

            $encoder->enkode_msg = DependencyContainer::get('i18l::translate', function($str){ return $str; })->__invoke($encoder->enkode_msg);
        }

        return $encoder->enkodeAllEmails($str);
    }

    public function setPredefinedHelpers()
    {
        $this->helpers = array(
            // Development
            'json' => function($v) {
                return json_encode($v);
            },
            'debug' => function($v) {
                return "<pre>\n".json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n</pre>\n";
            },
            'jsonpretty' => function($v) {
                return json_encode($v, JSON_PRETTY_PRINT);
            },

            // Strings
            'slug' => function($str) {
                return \generate_slug($str);
            },
            'uppercase' => function($str) {
                return strtoupper(trim($str));
            },
            'lowercase' => function($str) {
                return strtolower(trim($str));
            },
            'titlecase' => function($str) {
                return ucwords(trim($str));
            },
            'sentencecase' => function($str) {
                return ucfirst(trim($str));
            },
            'md5' => function($str) {
                return md5($str);
            },
            'nl2br' => function($str) {
                return str_replace("\n", '', nl2br($str));
            },
            'trim' => function($str) {
                return trim($str);
            },
            'plaintext' => function($str) {
                return strip_tags($str);
            },
            'stripp' => function($str) {
                return str_replace(array('<p>', '</p>'),'', $str);
            },
            'antispam' => function($str) {
                return self::antispam($str);
            },

            // Numbers
            'money' => function($str) {
                static $decimals        = null;
                static $dec_point       = null;
                static $thousands_sep   = null;
                static $currency_prefix = null;
                static $currency_symbol = null;

                if ($decimals===null)        { $decimals        = DependencyContainer::get('money::decimals', 2); }
                if ($dec_point===null)       { $dec_point       = DependencyContainer::get('money::dec_point', '.'); }
                if ($thousands_sep===null)   { $thousands_sep   = DependencyContainer::get('money::thousands_sep', ','); }
                if ($currency_prefix===null) { $currency_prefix = DependencyContainer::get('money::currency_prefix', true); }
                if ($currency_symbol===null) { $currency_symbol = DependencyContainer::get('money::currency_symbol', 'EUR'); }

                $n = number_format((float) $str, $decimals, $dec_point, $thousands_sep);

                return $currency_prefix ? "{$currency_symbol} {$n}" : "{$n} {$currency_symbol}";
            },

            // Arrays
            'count' => function($v) {
                return is_array($v) ? count($v) : null;
            },

            'reverse' => function($v) {
                return is_array($v) || is_object($v) ? (array) array_reverse($v) : strrev($v);
            },

            'orderby' => function($v) {
                if (!is_array($v)) {
                    return $v;
                }

                $this->defaultOrderHelper($v);

                return $v;
            },

            // Alias of `orderby`
            'orderbyasc' => function($v) {
                if (!is_array($v)) {
                    return $v;
                }

                $this->defaultOrderHelper($v);

                return $v;
            },

            'orderbydesc' => function($v) {
                if (!is_array($v)) {
                    return $v;
                }

                $this->defaultOrderHelper($v);

                return array_reverse($v);
            },

            // Translations (i18n, l10n)
            '__' => function($str, $lambda_helper) {
                return DependencyContainer::get('i18l::translate', function($str){ return $str; })->__invoke($str);
            },
            '_n' => function($str, $lambda_helper) {
                // Have arguments
                if ($args = json_decode($str, true)) {
                    // Bad format
                    if (!isset($args['var']) || !isset($args['one']) || !isset($args['other'])) {
                        return '<!--Bad format for: _n('.$str.')-->';
                    }

                    $var = $lambda_helper->render('{{'.$args['var'].'}}');
                    $var = strstr($var,'.') || strstr($var, ',') ? (float) $var : (int) $var;

                    /** @TODO: Accept offset parameter as AngularJS **/

                    return str_replace(
                        // Replace {} placeholders
                        '{}',
                        '{{'.$args['var'].'}}',
                        // In returned translation form
                        DependencyContainer::get(
                            // Dependency key
                            'i18l::translate',
                            // Default behaviour without dependency set
                            // NOOP example function to return translation.
                            function($one, $other, $count=0, $offset=0) {
                                if ($count===1) {
                                    return $one;
                                }

                                return $other;
                            }
                        )->__invoke($args['one'], $args['other'], $var/*, $offset */)
                    );
                }

                return '<!--Malformated: _n('.$str.')-->';
            },
            'dateformated' => function($str, $lambda_helper = null) {
                return $str;
            }
        );

        return $this;
    }

    private function defaultOrderHelper(&$v)
    {
        usort($v, function($a, $b) {
            $orderA = isset($a['order']) ? $a['order'] : null;
            $orderB = isset($b['order']) ? $b['order'] : null;

            if (isset($orderB) && is_numeric($orderB) && $orderA === null) {
                $orderA = $orderB + 1;
            }

            if (isset($orderA) && is_numeric($orderA) && $orderB === null) {
                $orderB = $orderA + 1;
            }

            if ($orderA == $orderB) {
                return 0;
            }

            return ($orderA < $orderB) ? -1 : 1;
        });
    }

    public function setHelpers($array)
    {
        if ($array===null) { return $this; }

        if (is_array($array) && sizeof($array)>0) {
            foreach ($array as $k => &$f) {
                $this->helpers[$k] = $f;
            }

            return $this;
        }

        throw new HTTPException(500, 'HTML Engine cache must be a real path.');
    }

    public function setExpanders($array)
    {
        if ($array===null) { return $this; }

        if (is_array($array) && sizeof($array)>0) {
            $this->expanders = $array;

            return $this;
        }

        throw new HTTPException(500, 'HTML Engine expanders must be an array.');
    }
}
