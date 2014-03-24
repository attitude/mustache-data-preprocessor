<?php

namespace \attitude;

use \attitude\Elements\Singleton_Prototype;
use \attitude\Elements\DependencyContainer;

class HTMLEngine_Element extends Singleton_Prototype
{
    protected $engine = null;

    protected function __construct()
    {
        $this->engine = DependencyContainer::set('global::MustacheEngine', new Mustache_Engine(array(
        )));
    }

    public function render($data)
    {
        $template = isset($data['template']) ? $data['template'] : 'default';
    }
}
