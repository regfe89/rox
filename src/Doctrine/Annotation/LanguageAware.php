<?php

namespace App\Doctrine\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * @Annotation
 * @Target("CLASS")
 */
final class LanguageAware
{
    public $language;
}