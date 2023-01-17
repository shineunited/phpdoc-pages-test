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

namespace phpDocumentor\Transformer\Writer\Twig;

use ArrayIterator;
use InvalidArgumentException;
use League\CommonMark\ConverterInterface;
use League\Uri\Uri;
use phpDocumentor\Descriptor\Collection;
use phpDocumentor\Descriptor\Descriptor;
use phpDocumentor\Descriptor\DescriptorAbstract;
use phpDocumentor\Descriptor\DocBlock\DescriptionDescriptor;
use phpDocumentor\Descriptor\EnumDescriptor;
use phpDocumentor\Descriptor\Interfaces\ArgumentInterface;
use phpDocumentor\Descriptor\Interfaces\ClassInterface;
use phpDocumentor\Descriptor\Interfaces\ConstantInterface;
use phpDocumentor\Descriptor\Interfaces\ContainerInterface;
use phpDocumentor\Descriptor\Interfaces\EnumCaseInterface;
use phpDocumentor\Descriptor\Interfaces\EnumInterface;
use phpDocumentor\Descriptor\Interfaces\FileInterface;
use phpDocumentor\Descriptor\Interfaces\FunctionInterface;
use phpDocumentor\Descriptor\Interfaces\InterfaceInterface;
use phpDocumentor\Descriptor\Interfaces\MethodInterface;
use phpDocumentor\Descriptor\Interfaces\NamespaceInterface;
use phpDocumentor\Descriptor\Interfaces\PackageInterface;
use phpDocumentor\Descriptor\Interfaces\PropertyInterface;
use phpDocumentor\Descriptor\Interfaces\TraitInterface;
use phpDocumentor\Descriptor\Interfaces\VisibilityInterface;
use phpDocumentor\Descriptor\NamespaceDescriptor;
use phpDocumentor\Descriptor\PackageDescriptor;
use phpDocumentor\Descriptor\ProjectDescriptor;
use phpDocumentor\Descriptor\TableOfContents\Entry;
use phpDocumentor\Descriptor\Tag\ExampleDescriptor;
use phpDocumentor\Descriptor\Tag\LinkDescriptor;
use phpDocumentor\Descriptor\Tag\SeeDescriptor;
use phpDocumentor\Path;
use phpDocumentor\Reflection\DocBlock\Tags\Reference;
use phpDocumentor\Reflection\Fqsen;
use phpDocumentor\Reflection\Type;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;
use Webmozart\Assert\Assert;

use function array_unshift;
use function ltrim;
use function method_exists;
use function sprintf;
use function str_replace;
use function strtolower;
use function var_export;
use function vsprintf;

/**
 * Basic extension adding phpDocumentor specific functionality for Twig
 * templates.
 *
 * Global variables:
 *
 * - *ast_node*, the current $data element
 *
 * Functions:
 *
 * - *path(string) *, converts the given relative path to be based of the projects
 *   root instead of the current directory
 *
 * Filters:
 *
 * - *markdown*, converts the associated text from Markdown formatting to HTML.
 * - *trans*, translates the given string
 * - *route*, attempts to generate a URL for a given Descriptor
 * - *sort_desc*, sorts the given objects by their Name property/getter in a descending fashion
 * - *sort_asc*, sorts the given objects by their Name property/getter in a ascending fashion
 */
final class Extension extends AbstractExtension implements ExtensionInterface, GlobalsInterface
{
    private LinkRenderer $routeRenderer;
    private ConverterInterface $markdownConverter;

    /**
     * Registers the structure and transformation with this extension.
     *
     * @param ProjectDescriptor $project Represents the complete Abstract Syntax Tree.
     */
    public function __construct(
        ProjectDescriptor $project,
        ConverterInterface $markdownConverter,
        LinkRenderer $routeRenderer
    ) {
        $this->markdownConverter = $markdownConverter;
        $this->routeRenderer     = $routeRenderer;
        $this->routeRenderer     = $this->routeRenderer->withProject($project);
    }

    /**
     * Initialize series of globals used by the writers to set the context
     *
     * @return array<string, (true|string[]|null)>
     */
    public function getGlobals(): array
    {
        return [
            'project' => null,
            'documentationSet' => null,
            'node' => null,
            'usesNamespaces' => true,
            'usesPackages' => true,
            'destinationPath' => null,
            'parameter' => [],
            'env' => null,
        ];
    }

    /**
     * @return list<TwigTest>
     */
    public function getTests(): array
    {
        return [
            new TwigTest('element container', static fn (Descriptor $el) => $el instanceof ContainerInterface),
            new TwigTest('namespace', static fn (Descriptor $el) => $el instanceof NamespaceInterface),
            new TwigTest('package', static fn (Descriptor $el) => $el instanceof PackageInterface),
            new TwigTest('file', static fn (Descriptor $el) => $el instanceof FileInterface),
            new TwigTest('class', static fn (Descriptor $el) => $el instanceof ClassInterface),
            new TwigTest('interface', static fn (Descriptor $el) => $el instanceof InterfaceInterface),
            new TwigTest('enum', static fn (Descriptor $el) => $el instanceof EnumInterface),
            new TwigTest('enumCase', static fn (Descriptor $el) => $el instanceof EnumCaseInterface),
            new TwigTest('trait', static fn (Descriptor $el) => $el instanceof TraitInterface),
            new TwigTest('property', static fn (Descriptor $el) => $el instanceof PropertyInterface),
            new TwigTest('method', static fn (Descriptor $el) => $el instanceof MethodInterface),
            new TwigTest('argument', static fn (Descriptor $el) => $el instanceof ArgumentInterface),
            new TwigTest('function', static fn (Descriptor $el) => $el instanceof FunctionInterface),
            new TwigTest('constant', static fn (Descriptor $el) => $el instanceof ConstantInterface),
        ];
    }

    /**
     * Returns a listing of all functions that this extension adds.
     *
     * This method is automatically used by Twig upon registering this
     * extension (which is done automatically by phpDocumentor) to determine
     * an additional list of functions.
     *
     * See the Class' DocBlock for a listing of functionality added by this
     * Extension.
     *
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'renderBaseUrlHeader',
                function (array $context): string {
                    /* TODO: This line has some odd side effects on the router state...
                        I'm not sure if we should keep it this way
                    */
                    $this->routeRenderer = $this->contextRouteRenderer($context)->doNotConvertUrlsToRootPath();

                    $absolutePath = $this->routeRenderer->convertToRootPath('/', true);
                    if (!$absolutePath) {
                        return '';
                    }

                    return '<base href="' . $absolutePath . '">';
                },
                ['is_safe' => ['all'], 'needs_context' => true]
            ),
            new TwigFunction(
                'path',
                function (array $context, string $url): string {
                    $path = $this->contextRouteRenderer($context)->convertToRootPath($url);

                    Assert::notNull($path);

                    return $path;
                },
                ['needs_context' => true]
            ),
            new TwigFunction(
                'link',
                function (array $context, object $element): string {
                    if (
                        !$element instanceof Fqsen
                        && !$element instanceof Uri
                        && !$element instanceof Descriptor
                    ) {
                        return '';
                    }

                    return $this->contextRouteRenderer($context)->link($element);
                },
                ['needs_context' => true]
            ),
            new TwigFunction(
                'breadcrumbs',
                static function (DescriptorAbstract $baseNode): array {
                    $results   = [];
                    $namespace = $baseNode instanceof NamespaceDescriptor
                        ? $baseNode->getParent()
                        : $baseNode->getNamespace();
                    while ($namespace instanceof NamespaceDescriptor && $namespace->getName() !== '\\') {
                        array_unshift($results, $namespace);
                        $namespace = $namespace->getParent();
                    }

                    return $results;
                }
            ),
            new TwigFunction(
                'packages',
                static function (DescriptorAbstract $baseNode): array {
                    $results = [];
                    $package = $baseNode instanceof PackageDescriptor
                        ? $baseNode->getParent()
                        : $baseNode->getPackage();
                    while ($package instanceof PackageDescriptor && $package->getName() !== '\\') {
                        array_unshift($results, $package);
                        $package = $package->getParent();
                    }

                    return $results;
                }
            ),
            new TwigFunction(
                'methods',
                static function (DescriptorAbstract $descriptor): Collection {
                    $methods = new Collection();
                    if (method_exists($descriptor, 'getInheritedMethods')) {
                        $methods = $methods->merge($descriptor->getInheritedMethods());
                    }

                    if (method_exists($descriptor, 'getMagicMethods')) {
                        $methods = $methods->merge($descriptor->getMagicMethods());
                    }

                    if (method_exists($descriptor, 'getMethods')) {
                        $methods = $methods->merge($descriptor->getMethods());
                    }

                    return $methods;
                }
            ),
            new TwigFunction(
                'properties',
                static function (DescriptorAbstract $descriptor): Collection {
                    $properties = new Collection();
                    if (method_exists($descriptor, 'getInheritedProperties')) {
                        $properties = $properties->merge($descriptor->getInheritedProperties());
                    }

                    if (method_exists($descriptor, 'getMagicProperties')) {
                        $properties = $properties->merge($descriptor->getMagicProperties());
                    }

                    if (method_exists($descriptor, 'getProperties')) {
                        $properties = $properties->merge($descriptor->getProperties());
                    }

                    return $properties;
                }
            ),
            new TwigFunction(
                'constants',
                static function (DescriptorAbstract $descriptor): Collection {
                    $constants = new Collection();
                    if (method_exists($descriptor, 'getInheritedConstants')) {
                        $constants = $constants->merge($descriptor->getInheritedConstants());
                    }

                    if (method_exists($descriptor, 'getMagicConstants')) {
                        $constants = $constants->merge($descriptor->getMagicConstants());
                    }

                    if (method_exists($descriptor, 'getConstants')) {
                        $constants = $constants->merge($descriptor->getConstants());
                    }

                    return $constants;
                }
            ),
            new TwigFunction(
                'cases',
                static function (DescriptorAbstract $descriptor): Collection {
                    if ($descriptor instanceof EnumDescriptor) {
                        return $descriptor->getCases();
                    }

                    return new Collection();
                }
            ),
            new TwigFunction(
                'toc',
                static function (
                    Environment $env,
                    Entry $entry,
                    string $template,
                    ?int $maxDepth = null,
                    int $depth = 0
                ): string {
                    if ($maxDepth === $depth) {
                        return '';
                    }

                    return $env->render(
                        $template,
                        [
                            'entry' => $entry,
                            'depth' => ++$depth,
                            'maxDepth' => $maxDepth,
                        ]
                    );
                },
                [
                    'needs_environment' => true,
                    'is_safe' => ['html'],
                ]
            ),
        ];
    }

    /**
     * Returns a list of all filters that are exposed by this extension.
     *
     * @return TwigFilter[]
     */
    public function getFilters(): array
    {
        return [
            'markdown' => new TwigFilter(
                'markdown',
                function (?string $value): string {
                    return str_replace(
                        ['<pre>', '<code>'],
                        ['<pre class="prettyprint">', '<code class="prettyprint">'],
                        $this->markdownConverter->convert($value ?? '')->getContent()
                    );
                },
                ['is_safe' => ['all']]
            ),
            'trans' => new TwigFilter(
                'trans',
                static function ($value) {
                    return $value;
                }
            ),
            'route' => new TwigFilter(
                'route',
                function ($value, string $presentation = LinkRenderer::PRESENTATION_NORMAL) {
                    return $this->routeRenderer->render($value, $presentation);
                },
                ['is_safe' => ['all']]
            ),
            'sort' => new TwigFilter('sort_*', [$this, 'sort']),
            'sortByVisibility' => new TwigFilter('sortByVisibility', [$this, 'sortByVisibility']),
            'export' => new TwigFilter(
                'export',
                static function ($var) {
                    return var_export($var, true);
                }
            ),
            'description' => new TwigFilter(
                'description',
                function (array $context, ?DescriptionDescriptor $description) {
                    if ($description === null || $description->getBodyTemplate() === '') {
                        return '';
                    }

                    $tagStrings = [];
                    foreach ($description->getTags() as $tag) {
                        if ($tag instanceof SeeDescriptor) {
                            $tagStrings[] = $this->renderRoute(
                                $context,
                                $tag->getReference(),
                                LinkRenderer::PRESENTATION_CLASS_SHORT
                            );
                        } elseif ($tag instanceof LinkDescriptor) {
                            $tagStrings[] = sprintf(
                                '[%s](%s)',
                                (string) $tag->getDescription(),
                                $tag->getLink()
                            );
                        } elseif ($tag instanceof ExampleDescriptor) {
                            $tagStrings[] = $tag->getDescription() . "\n"
                                . '```php' . "\n" . $tag->getExample() . "\n" . '```';
                        } else {
                            $tagStrings[] = (string) $tag;
                        }
                    }

                    return vsprintf($description->getBodyTemplate(), $tagStrings);
                },
                ['needs_context' => true]
            ),
            'shortFQSEN' => new TwigFilter(
                'shortFQSEN',
                static function (string $fqsenOrTitle) {
                    try {
                        return (new Fqsen($fqsenOrTitle))->getName();
                    } catch (InvalidArgumentException $e) {
                    }

                    return $fqsenOrTitle;
                }
            ),
        ];
    }

    /**
     * @param mixed[] $context
     * @param array<Type>|Type|DescriptorAbstract|Fqsen|Reference\Reference|Path|string|iterable<mixed> $value
     *
     * @return string[]|string
     */
    public function renderRoute(array $context, $value, string $presentation)
    {
        $routeRenderer = $this->contextRouteRenderer($context);

        return $routeRenderer->render($value, $presentation);
    }

    /**
     * @param mixed[] $context
     */
    private function contextRouteRenderer(array $context): LinkRenderer
    {
        return $this->routeRenderer
            ->withDestination(ltrim($context['destinationPath'] ?? $context['env']->getCurrentFileDestination(), '/\\'))
            ->withProject($context['project']);
    }

    /**
     * @param Collection<Descriptor> $collection
     *
     * @return ArrayIterator<array-key, Descriptor>
     */
    public function sort(string $direction, Collection $collection): ArrayIterator
    {
        $iterator = $collection->getIterator();
        $iterator->uasort(
            static function (Descriptor $a, Descriptor $b) use ($direction) {
                $aElem = strtolower($a->getName());
                $bElem = strtolower($b->getName());
                if ($aElem === $bElem) {
                    return 0;
                }

                if (
                    ($direction === 'asc' && $aElem > $bElem) ||
                    ($direction === 'desc' && $aElem < $bElem)
                ) {
                    return 1;
                }

                return -1;
            }
        );

        return $iterator;
    }

    /**
     * @param Collection<Descriptor> $collection
     *
     * @return ArrayIterator<array-key, Descriptor>
     */
    public function sortByVisibility(Collection $collection): ArrayIterator
    {
        $visibilityOrder = [
            'public' => 0,
            'protected' => 1,
            'private' => 2,
        ];
        $iterator = $collection->getIterator();
        $iterator->uasort(
            static function (Descriptor $a, Descriptor $b) use ($visibilityOrder): int {
                $prio = 0;
                if ($a instanceof VisibilityInterface && $b instanceof VisibilityInterface) {
                    $visibilityPriorityA = $visibilityOrder[$a->getVisibility()] ?? 0;
                    $visibilityPriorityB = $visibilityOrder[$b->getVisibility()] ?? 0;
                    $prio = $visibilityPriorityA <=> $visibilityPriorityB;
                }

                if ($prio !== 0) {
                    return $prio;
                }

                $aElem = strtolower($a->getName());
                $bElem = strtolower($b->getName());

                return $aElem <=> $bElem;
            }
        );

        return $iterator;
    }
}
