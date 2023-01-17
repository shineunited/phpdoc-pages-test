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

use phpDocumentor\Descriptor\Filter\Filterable;
use phpDocumentor\Descriptor\Interfaces\ElementInterface;
use phpDocumentor\Descriptor\Interfaces\InheritsFromElement;
use phpDocumentor\Descriptor\Validation\Error;
use Webmozart\Assert\Assert;

use function lcfirst;
use function strpos;
use function substr;

/**
 * Base class for descriptors containing the most used options.
 */
abstract class DescriptorAbstract implements Filterable, ElementInterface, InheritsFromElement
{
    use Traits\HasFqsen;
    use Traits\HasName;
    use Traits\HasNamespace;
    use Traits\HasPackage;
    use Traits\HasSummary;
    use Traits\HasDescription;
    use Traits\HasTags;
    use Traits\IsInFile;
    use Traits\HasErrors;
    use Traits\HasInheritance;

    /**
     * Initializes this descriptor.
     */
    public function __construct()
    {
        $this->setErrors(new Collection());
        $this->setTags(new Collection());
    }

    /**
     * Returns all errors that occur in this element.
     *
     * @return Collection<Error>
     */
    public function getErrors(): Collection
    {
        $errors = $this->errors;
        foreach ($this->tags as $tags) {
            foreach ($tags as $tag) {
                $errors = $errors->merge($tag->getErrors());
            }
        }

        foreach ($errors as $error) {
            Assert::isInstanceOf($error, Error::class);
            if ($error->getLine() !== 0) {
                continue;
            }

            $startLocation = $this->getStartLocation();
            if ($startLocation === null) {
                continue;
            }

            $error->setLine($startLocation->getLineNumber());
        }

        return $errors;
    }

    /**
     * Dynamically constructs a set of getters to retrieve tag (collections) with.
     *
     * Important: __call() is not a fast method of access; it is preferred to directly use the getTags() collection.
     * This interface is provided to allow for uniform and easy access to certain tags.
     *
     * @param array<mixed> $arguments
     *
     * @return Collection<TagDescriptor>|null
     */
    public function __call(string $name, array $arguments)
    {
        if (strpos($name, 'get') !== 0) {
            return null;
        }

        $tagName = substr($name, 3);
        $tagName = lcfirst($tagName);

        return $this->getTags()->fetch($tagName, new Collection());
    }

    /**
     * Represents this object by its unique identifier, the Fully Qualified Structural Element Name.
     */
    public function __toString(): string
    {
        return (string) $this->getFullyQualifiedStructuralElementName();
    }
}
