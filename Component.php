<?php

namespace attitude;

use \attitude\Elements\HTTPException;
use \attitude\Elements\Singleton_Prototype;
use \attitude\Elements\DependencyContainer;

class HTMLRenderEngine_Component extends Singleton_Prototype
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
        $this->setViews(DependencyContainer::get('global::mustacheViewsPath', null));
        $this->setPartials(DependencyContainer::get('global::mustachePartialsPath', null));
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

        // Validate template
        $template_path = DependencyContainer::get('global::mustacheViewsPath').'/'.$template.'.mustache';

        if (!file_exists($template_path)) {
            throw new HTTPException(404, 'Template does not exist.');
        }

                self::printData($data);

        return $this->engine->render($template, $data);
    }

    static public function printData($data)
    {
        header('Content-Type: text/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
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

        throw new HTTPException(500, 'HTML Engine cache must be a real path.');
    }

    public function setPartials($path)
    {
        if ($path===null) { return $this; }

        if (is_string($path) && strlen(trim($path))>0 && realpath(trim($path))) {
            $this->partials_loader = new \Mustache_Loader_FilesystemLoader($path);

            return $this;
        }

        throw new HTTPException(500, 'HTML Engine cache must be a real path.');
    }

    public function setHelpers($array)
    {
        if ($array===null) { return $this; }

        if (is_array($array) && sizeof($array)>0) {
            $this->helpers = $array;

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
