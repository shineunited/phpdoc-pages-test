<?php

declare(strict_types=1);

/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link https://phpdoc.org
 */

namespace phpDocumentor\Descriptor\Traits;

use InvalidArgumentException;
use phpDocumentor\Descriptor\Interfaces\ClassInterface;
use phpDocumentor\Descriptor\Interfaces\ElementInterface;
use phpDocumentor\Descriptor\Interfaces\InterfaceInterface;
use phpDocumentor\Descriptor\Interfaces\TraitInterface;
use phpDocumentor\Reflection\Fqsen;
use Webmozart\Assert\Assert;

trait BelongsToClassTraitOrInterface
{
    /** @var ClassInterface|InterfaceInterface|TraitInterface|null $parent */
    protected ?ElementInterface $parent = null;

    /**
     * Registers a parent class, interface or trait.
     *
     * @throws InvalidArgumentException If anything other than a class, interface or trait was passed.
     *
     * @inheritDoc
     */
    public function setParent($parent): void
    {
        Assert::isInstanceOfAny(
            $parent,
            [ClassInterface::class, InterfaceInterface::class, TraitInterface::class]
        );

        $this->setFullyQualifiedStructuralElementName(
            new Fqsen($parent->getFullyQualifiedStructuralElementName() . '::' . $this->getName())
        );

        $this->parent = $parent;
    }

    /**
     * @return ClassInterface|InterfaceInterface|TraitInterface|null
     */
    public function getParent(): ?ElementInterface
    {
        return $this->parent;
    }
}
