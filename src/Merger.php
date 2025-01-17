<?php

declare(strict_types=1);

namespace Yiisoft\Config;

use ErrorException;
use Yiisoft\Arrays\ArrayHelper;
use Yiisoft\Config\Modifier\RecursiveMerge;
use Yiisoft\Config\Modifier\RemoveKeysFromVendor;
use Yiisoft\Config\Modifier\ReverseMerge;

use function array_key_exists;
use function array_flip;
use function array_map;
use function array_merge;
use function implode;
use function is_array;
use function is_int;
use function sprintf;
use function substr_count;
use function usort;

/**
 * @internal
 */
final class Merger
{
    private ConfigPaths $paths;
    private array $recursiveMergeGroupsIndex;
    private array $reverseMergeGroupsIndex;

    /**
     * @psalm-var array<string,array<string, mixed>>
     */
    private array $removeFromVendorKeysIndex;

    /**
     * @psalm-var array<int, array>
     */
    private array $cacheKeys = [];

    /**
     * @param ConfigPaths $configPaths The config paths instance.
     * @param object[] $modifiers Modifiers that affect merge process.
     */
    public function __construct(ConfigPaths $configPaths, array $modifiers = [])
    {
        $this->paths = $configPaths;

        $reverseMergeGroups = [];
        $recursiveMergeGroups = [];
        $this->removeFromVendorKeysIndex = [];

        foreach ($modifiers as $modifier) {
            if ($modifier instanceof ReverseMerge) {
                $reverseMergeGroups = array_merge($reverseMergeGroups, $modifier->getGroups());
            }

            if ($modifier instanceof RecursiveMerge) {
                $recursiveMergeGroups = array_merge($recursiveMergeGroups, $modifier->getGroups());
            }

            if ($modifier instanceof RemoveKeysFromVendor) {
                $configPaths = [];
                if ($modifier->getPackages() === []) {
                    $configPaths[] = '*';
                } else {
                    foreach ($modifier->getPackages() as $configPath) {
                        $package = array_shift($configPath);
                        if ($configPath === []) {
                            $configPaths[] = $package . '~*';
                        } else {
                            foreach ($configPath as $group) {
                                $configPaths[] = $package . '~' . $group;
                            }
                        }
                    }
                }
                foreach ($modifier->getKeys() as $keyPath) {
                    foreach ($configPaths as $configPath) {
                        $this->removeFromVendorKeysIndex[$configPath] ??= [];
                        ArrayHelper::setValue($this->removeFromVendorKeysIndex[$configPath], $keyPath, true);
                    }
                }
            }
        }

        $this->reverseMergeGroupsIndex = array_flip($reverseMergeGroups);
        $this->recursiveMergeGroupsIndex = array_flip($recursiveMergeGroups);
    }

    public function reset(): void
    {
        $this->cacheKeys = [];
    }

    /**
     * Merges two or more arrays into one recursively.
     *
     * @param Context $context Context containing the name of the file, package, group and environment.
     * @param array $arrayA First array to merge.
     * @param array $arrayB Second array to merge.
     *
     * @throws ErrorException If an error occurred during the merge.
     *
     * @return array The merged array.
     */
    public function merge(Context $context, array $arrayA, array $arrayB): array
    {
        $isRecursiveMerge = array_key_exists($context->group(), $this->recursiveMergeGroupsIndex);
        $isReverseMerge = array_key_exists($context->group(), $this->reverseMergeGroupsIndex);

        if ($isReverseMerge) {
            $arrayB = $this->prepareArrayForReverse($context, [], $arrayB, $isRecursiveMerge);
        }

        return $this->performMerge(
            $context,
            [],
            $isReverseMerge ? $arrayB : $arrayA,
            $isReverseMerge ? $arrayA : $arrayB,
            $isRecursiveMerge,
            $isReverseMerge,
        );
    }

    /**
     * @param Context $context Context containing the name of the file, package, group and environment.
     * @param string[] $recursiveKeyPath The key path for recursive merging of arrays in configuration files.
     * @param array $arrayA First array to merge.
     * @param array $arrayB Second array to merge.
     * @param bool $isRecursiveMerge
     * @param bool $isReverseMerge
     *
     * @throws ErrorException If an error occurred during the merge.
     *
     * @return array The merged array.
     */
    private function performMerge(
        Context $context,
        array $recursiveKeyPath,
        array $arrayA,
        array $arrayB,
        bool $isRecursiveMerge,
        bool $isReverseMerge
    ): array {
        $result = $arrayA;

        /** @psalm-var mixed $v */
        foreach ($arrayB as $k => $v) {
            if (is_int($k)) {
                if (array_key_exists($k, $result) && $result[$k] !== $v) {
                    /** @var mixed */
                    $result[] = $v;
                } else {
                    /** @var mixed */
                    $result[$k] = $v;
                }
                continue;
            }

            $fullKeyPath = array_merge($recursiveKeyPath, [$k]);

            if (
                $isRecursiveMerge
                && is_array($v)
                && (
                    !array_key_exists($k, $result)
                    || is_array($result[$k])
                )
            ) {
                /** @var array $array */
                $array = $result[$k] ?? [];
                $this->setValue(
                    $context,
                    $fullKeyPath,
                    $result,
                    $k,
                    $this->performMerge($context, $fullKeyPath, $array, $v, $isRecursiveMerge, $isReverseMerge)
                );
                continue;
            }

            $existKey = array_key_exists($k, $result);

            if ($existKey && !$isReverseMerge) {
                /** @var string|null $file */
                $file = ArrayHelper::getValue(
                    $this->cacheKeys,
                    array_merge([$context->level()], $fullKeyPath)
                );

                if ($file !== null) {
                    $this->throwException($this->getDuplicateErrorMessage($fullKeyPath, [$file, $context->file()]));
                }
            }

            if (!$isReverseMerge || !$existKey) {
                $isSet = $this->setValue($context, $fullKeyPath, $result, $k, $v);

                if ($isSet && !$isReverseMerge && !$context->isVariable()) {
                    /** @psalm-suppress MixedPropertyTypeCoercion */
                    ArrayHelper::setValue(
                        $this->cacheKeys,
                        array_merge([$context->level()], $fullKeyPath),
                        $context->file()
                    );
                }
            }
        }

        return $result;
    }

    /**
     * @param string[] $recursiveKeyPath
     *
     * @throws ErrorException If an error occurred during prepare.
     */
    private function prepareArrayForReverse(
        Context $context,
        array $recursiveKeyPath,
        array $array,
        bool $isRecursiveMerge
    ): array {
        $result = [];

        /** @var mixed $value */
        foreach ($array as $key => $value) {
            if (is_int($key)) {
                /** @var mixed */
                $result[$key] = $value;
                continue;
            }

            if ($this->shouldRemoveKeyFromVendor($context, array_merge($recursiveKeyPath, [$key]))) {
                continue;
            }

            if ($isRecursiveMerge && is_array($value)) {
                $result[$key] = $this->prepareArrayForReverse(
                    $context,
                    array_merge($recursiveKeyPath, [$key]),
                    $value,
                    $isRecursiveMerge
                );
                continue;
            }

            if ($context->isVariable()) {
                /** @var mixed */
                $result[$key] = $value;
                continue;
            }

            $recursiveKeyPath[] = $key;

            /** @var string|null $file */
            $file = ArrayHelper::getValue(
                $this->cacheKeys,
                array_merge([$context->level()], $recursiveKeyPath)
            );

            if ($file !== null) {
                $this->throwException($this->getDuplicateErrorMessage($recursiveKeyPath, [$file, $context->file()]));
            }

            /** @var mixed */
            $result[$key] = $value;

            /** @psalm-suppress MixedPropertyTypeCoercion */
            ArrayHelper::setValue(
                $this->cacheKeys,
                array_merge([$context->level()], $recursiveKeyPath),
                $context->file()
            );
        }

        return $result;
    }

    /**
     * @param mixed $value
     *
     * @psalm-param non-empty-array<array-key, string> $keyPath
     */
    private function setValue(Context $context, array $keyPath, array &$array, string $key, $value): bool
    {
        if ($this->shouldRemoveKeyFromVendor($context, $keyPath)) {
            return false;
        }

        /** @var mixed */
        $array[$key] = $value;

        return true;
    }

    /**
     * @psalm-param non-empty-array<array-key, string> $keyPath
     */
    private function shouldRemoveKeyFromVendor(Context $context, array $keyPath): bool
    {
        if (!$context->isVendor()) {
            return false;
        }

        $configPaths = [
            '*',
            $context->package() . '~*',
            $context->package() . '~' . $context->group(),
        ];

        foreach ($configPaths as $configPath) {
            if (ArrayHelper::getValue($this->removeFromVendorKeysIndex[$configPath] ?? [], $keyPath) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns a duplicate key error message.
     *
     * @param string[] $recursiveKeyPath The key path for recursive merging of arrays in configuration files.
     * @param string[] $absoluteFilePaths The absolute paths to the files in which duplicates are found.
     *
     * @return string The duplicate key error message.
     */
    private function getDuplicateErrorMessage(array $recursiveKeyPath, array $absoluteFilePaths): string
    {
        $filePaths = array_map(
            fn (string $filePath) => ' - ' . $this->paths->relative($filePath),
            $absoluteFilePaths,
        );

        usort($filePaths, static function (string $a, string $b) {
            $countDirsA = substr_count($a, '/');
            $countDirsB = substr_count($b, '/');
            return $countDirsA === $countDirsB ? $a <=> $b : $countDirsA <=> $countDirsB;
        });

        return sprintf(
            "Duplicate key \"%s\" in configs:\n%s",
            implode(' => ', $recursiveKeyPath),
            implode("\n", $filePaths),
        );
    }

    /**
     * @param string $message
     *
     * @throws ErrorException
     */
    private function throwException(string $message): void
    {
        throw new ErrorException($message, 0, E_USER_ERROR);
    }
}
