<?php

namespace b8\Form\Element;

use b8\Form\Input, b8\View;

class Button extends Input
{
    public function validate()
    {
        return true;
    }

    protected function onPreRender(View &$view)
    {
        parent::onPreRender($view);
        $view->type = 'button';
    }
}
