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

namespace phpDocumentor\Descriptor;

use phpDocumentor\Descriptor\Interfaces\EnumCaseInterface;
use phpDocumentor\Descriptor\Interfaces\EnumInterface;
use phpDocumentor\Descriptor\Interfaces\FileInterface;
use phpDocumentor\Descriptor\Interfaces\MethodInterface;
use phpDocumentor\Descriptor\Interfaces\TraitInterface;
use phpDocumentor\Descriptor\Tag\ReturnDescriptor;
use phpDocumentor\Reflection\Location;
use phpDocumentor\Reflection\Type;

/**
 * Descriptor representing a Enum.
 *
 * @api
 * @package phpDocumentor\AST
 */
final class EnumDescriptor extends DescriptorAbstract implements EnumInterface
{
    use Traits\ImplementsInterfaces;
    use Traits\HasMethods;
    use Traits\UsesTraits;

    /** @var Collection<EnumCaseInterface> */
    private Collection $cases;

    private ?Type $backedType = null;

    /**
     * Initializes the all properties representing a collection with a new Collection object.
     */
    public function __construct()
    {
        parent::__construct();

        $this->setCases(new Collection());
    }

    /**
     * @return Collection<MethodInterface>
     */
    public function getInheritedMethods(): Collection
    {
        $inheritedMethods = Collection::fromInterfaceString(MethodInterface::class);

        foreach ($this->getUsedTraits() as $trait) {
            if (!$trait instanceof TraitInterface) {
                continue;
            }

            $inheritedMethods = $inheritedMethods->merge($trait->getMethods());
        }

        return $inheritedMethods;
    }

    /**
     * @return Collection<MethodInterface>
     */
    public function getMagicMethods(): Collection
    {
        $methodTags = $this->getTags()->fetch('method', new Collection())->filter(Tag\MethodDescriptor::class);

        $methods = Collection::fromInterfaceString(MethodInterface::class);

        foreach ($methodTags as $methodTag) {
            $method = new MethodDescriptor();
            $method->setName($methodTag->getMethodName());
            $method->setDescription($methodTag->getDescription());
            $method->setStatic($methodTag->isStatic());
            $method->setParent($this);
            $method->setReturnType($methodTag->getResponse()->getType());
            $method->setHasReturnByReference($methodTag->getHasReturnByReference());

            $returnTags = $method->getTags()->fetch('return', new Collection())->filter(ReturnDescriptor::class);
            $returnTags->add($methodTag->getResponse());

            foreach ($methodTag->getArguments() as $name => $argument) {
                $method->addArgument($name, $argument);
            }

            $methods->add($method);
        }

        return $methods;
    }

    /**
     * @inheritDoc
     */
    public function setPackage($package): void
    {
        parent::setPackage($package);

        foreach ($this->getCases() as $case) {
            $case->setPackage($package);
        }

        foreach ($this->getMethods() as $method) {
            $method->setPackage($package);
        }
    }

    public function setLocation(FileInterface $file, Location $startLocation): void
    {
        parent::setLocation($file, $startLocation);

        foreach ($this->getCases() as $case) {
            $case->setFile($file);
        }
    }

    /**
     * @param Collection<EnumCaseInterface> $cases
     */
    public function setCases(Collection $cases): void
    {
        $this->cases = $cases;
    }

    /**
     * @return Collection<EnumCaseInterface>
     */
    public function getCases(): Collection
    {
        return $this->cases;
    }

    public function setBackedType(?Type $type): void
    {
        $this->backedType = $type;
    }

    public function getBackedType(): ?Type
    {
        return $this->backedType;
    }
}
