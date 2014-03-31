<?php

namespace attitude\Mustache;

use \attitude\Elements\HTTPException;
use \attitude\Elements\Singleton_Prototype;
use \attitude\Elements\DependencyContainer;

class DataPreprocessor_Component extends Singleton_Prototype
{
    protected $engine = null;

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

    protected function is_assoc_array($array, $speedy=true) {
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
                    $data['has'.ucfirst($k)] = !empty($v);
                }
            }
        }

        return $data;
    }

    protected function expandData(array $data)
    {
        foreach ($data as $key => &$values) {
            if (is_array($values)) {
                $values_to_merge = array();
                $is_sequence_array = ! $this->is_assoc_array($values);

                foreach($values as $k => $v) {
                    if (is_string($k) && strstr($k, '()') && is_array($v)) {
                        $function = rtrim($k, '()');

                        if (isset($this->expanders[$function]) && is_callable($this->expanders[$function])) {
                            unset($values[$k]);

                            try {
                                $values_to_merge[] = $this->expanders[$function]($v);
                            } catch (HTTPException $e) { /* do nothing */ }
                        }
                    }
                }

                foreach ($values_to_merge as &$_values) {
                    $values = array_merge($values, $_values);
                }

                if (empty($values)) {
                    unset($data[$key]);
                } else if ($is_sequence_array) {
                    // Reindex array starting from 0
                    $values = array_values($values);
                }
            }

            if (is_array($values)) {
                $values = $this->expandData($values);
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

        // Add hasItems helpers next to arrays
        $data = $this->arraysHaveItems($data);

        // Pick template from data
        $template = isset($data['template']) ? $data['template'] : 'default';

        if (isset($_GET['format'])) {
            if ($_GET['format']==='json') {
                self::printData($data);

                exit;
            } elseif ($_GET['format']==='json-pretty') {
                self::printData($data, true);

                exit;
            }
        }

        try {
            $view = $this->engine->render($template, $data);
        } catch (\Mustache_Exception $e) {
            throw new HTTPException(404, $e->getMessage());
        }

        return $view;
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

    public function setPredefinedHelpers()
    {
        $this->helpers = array(
            'json' => function($v) {
                return json_encode($v);
            },
            'debug' => function($v) {
                return "<pre>\n".json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n</pre>\n";
            },
            'jsonpretty' => function($v) {
                return json_encode($v, JSON_PRETTY_PRINT);
            },
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
                return nl2br($str);
            },
            'trim' => function($str) {
                return trim($str);
            },
            'plaintext' => function($str) {
                return strip_tags($str);
            },
            'money' => function($str) {
                static $decimals        = null;
                static $dec_point       = null;
                static $thousands_sep   = null;
                static $currency_prefix = null;
                static $currency_symbol = null;

                if ($decimals===null)        { DependencyContainer::get('money::decimals', 4); }
                if ($dec_point===null)       { DependencyContainer::get('money::dec_point', '.'); }
                if ($thousands_sep===null)   { DependencyContainer::get('money::thousands_sep', ','); }
                if ($currency_prefix===null) { DependencyContainer::get('money::currency_prefix', true); }
                if ($currency_symbol===null) { DependencyContainer::get('money::currency_symbol', 'EUR'); }

                $n = number_format((float) $str, $decimals, $dec_point, $thousands_sep);

                return $currency_prefix ? "{$currency_symbol} {$n}" : "{$n} {$currency_symbol}";
            },
            'translate' => function($str) {
                // NOOP
                return $str;
            }
        );

        return $this;
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

        throw new HTTPException(500, 'HTML Engine cache must be a real path.');
    }
}
