<?php
/*
 * This file is part of the Sidus/EAVModelBundle package.
 *
 * Copyright (c) 2015-2019 Vincent Chalnot
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sidus\EAVModelBundle\Model;

/**
 * Type of attribute that embed an entity inside an other
 *
 * @author Vincent Chalnot <vincent@sidus.fr>
 */
class EmbedAttributeType extends AttributeType
{
    /**
     * @return bool
     */
    public function isEmbedded()
    {
        return true;
    }

    /**
     * @param AttributeInterface $attribute
     */
    public function setAttributeDefaults(AttributeInterface $attribute)
    {
        $attribute->addValidationRule(['Valid' => []]);
    }
}
