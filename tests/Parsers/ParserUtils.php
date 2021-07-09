<?php
declare(strict_types=1);

namespace StubTests\Parsers;

use phpDocumentor\Reflection\DocBlock\Tags\Deprecated;
use phpDocumentor\Reflection\DocBlock\Tags\Since;
use RuntimeException;
use StubTests\Model\BasePHPElement;
use StubTests\Model\CommonUtils;
use StubTests\Model\PHPConst;
use StubTests\Model\PHPMethod;
use StubTests\Model\PhpVersions;
use StubTests\Model\Tags\RemovedTag;
use StubTests\TestData\Providers\PhpStormStubsSingleton;

class ParserUtils
{
    /**
     * @param Since|RemovedTag|Deprecated $tag
     * @return bool
     */
    public static function tagDoesNotHaveZeroPatchVersion($tag): bool
    {
        return (bool)preg_match('/^[1-9]+\.\d+(\.[1-9]+\d*)*$/', $tag->getVersion()); //find version like any but 7.4.0
    }

    /**
     * @throws RuntimeException
     */
    public static function getDeclaredSinceVersion(BasePHPElement $element): ?float
    {
        $allSinceVersions = self::getSinceVersionsFromPhpDoc($element);
        $allSinceVersions[] = self::getSinceVersionsFromAttribute($element);
        if ($element instanceof PHPMethod) {
            if ($element->hasInheritDocTag) {
                return null;
            }
            $allSinceVersions[] = self::getSinceVersionsFromParentClass($element);
        } elseif ($element instanceof PHPConst && !empty($element->parentName)) {
            $allSinceVersions[] = self::getSinceVersionsFromParentClass($element);
        }
        $flattenedArray = CommonUtils::flattenArray($allSinceVersions, false);
        sort($flattenedArray, SORT_DESC);
        return array_pop($flattenedArray);
    }

    /**
     * @throws RuntimeException
     */
    public static function getLatestAvailableVersion(BasePHPElement $element): ?float
    {
        $latestVersionsFromPhpDoc = self::getLatestAvailableVersionFromPhpDoc($element);
        $latestVersionsFromAttribute = self::getLatestAvailableVersionsFromAttribute($element);
        if ($element instanceof PHPMethod) {
            if ($element->hasInheritDocTag) {
                return null;
            }
            $latestVersionsFromPhpDoc[] = self::getLatestAvailableVersionsFromParentClass($element);
        } elseif ($element instanceof PHPConst && !empty($element->parentName)) {
            $latestVersionsFromPhpDoc[] = self::getLatestAvailableVersionsFromParentClass($element);
        }
        if (empty($latestVersionsFromAttribute)) {
            $flattenedArray = CommonUtils::flattenArray($latestVersionsFromPhpDoc, false);
        } else {
            $flattenedArray = CommonUtils::flattenArray($latestVersionsFromAttribute, false);
        }
        sort($flattenedArray, SORT_DESC);
        return array_pop($flattenedArray);
    }

    /**
     * @throws RuntimeException
     */
    public static function getAvailableInVersions(?BasePHPElement $element): array
    {
        if ($element !== null) {
            $firstSinceVersion = self::getDeclaredSinceVersion($element);
            if ($firstSinceVersion === null) {
                $firstSinceVersion = PhpVersions::getFirst();
            }
            $lastAvailableVersion = self::getLatestAvailableVersion($element);
            if ($lastAvailableVersion === null) {
                $lastAvailableVersion = PhpVersions::getLatest();
            }
            return array_filter(
                iterator_to_array(new PhpVersions()),
                function ($version) use ($firstSinceVersion, $lastAvailableVersion) {
                    return $version >= $firstSinceVersion && $version <= $lastAvailableVersion;
                }
            );
        } else {
            return [];
        }
    }

    /**
     * @return float[]
     */
    private static function getSinceVersionsFromPhpDoc(BasePHPElement $element): array
    {
        $allSinceVersions = [];
        if (!empty($element->sinceTags) && $element->stubBelongsToCore) {
            $allSinceVersions[] = array_map(function (Since $tag) {
                return (float)$tag->getVersion();
            }, $element->sinceTags);
        }
        return $allSinceVersions;
    }

    /**
     * @return float[]
     */
    private static function getLatestAvailableVersionFromPhpDoc(BasePHPElement $element): array
    {
        $latestAvailableVersion = [PhpVersions::getLatest()];
        if (!empty($element->removedTags) && $element->stubBelongsToCore) {
            $allRemovedVersions = array_map(function (RemovedTag $tag) {
                return (float)$tag->getVersion();
            }, $element->removedTags);
            sort($allRemovedVersions, SORT_DESC);
            $removedVersion = array_pop($allRemovedVersions);
            $allVersions = new PhpVersions();
            $indexOfRemovedVersion = array_search($removedVersion, iterator_to_array($allVersions));
            $latestAvailableVersion = [$allVersions[$indexOfRemovedVersion - 1]];
        }
        return $latestAvailableVersion;
    }

    /**
     * @param PHPMethod|PHPConst $element
     * @return float[]
     * @throws RuntimeException
     */
    private static function getSinceVersionsFromParentClass($element): array
    {
        $parentClass = PhpStormStubsSingleton::getPhpStormStubs()->getClass($element->parentName);
        if ($parentClass === null) {
            $parentClass = PhpStormStubsSingleton::getPhpStormStubs()->getInterface($element->parentName);
        }
        $allSinceVersions[] = self::getSinceVersionsFromPhpDoc($parentClass);
        $allSinceVersions[] = self::getSinceVersionsFromAttribute($parentClass);
        return $allSinceVersions;
    }

    /**
     * @param PHPMethod|PHPConst $element
     * @return float[]
     * @throws RuntimeException
     */
    public static function getLatestAvailableVersionsFromParentClass($element): array
    {
        $parentClass = PhpStormStubsSingleton::getPhpStormStubs()->getClass($element->parentName);
        if ($parentClass === null) {
            $parentClass = PhpStormStubsSingleton::getPhpStormStubs()->getInterface($element->parentName);
        }
        $latestAvailableVersionFromPhpDoc = self::getLatestAvailableVersionFromPhpDoc($parentClass);
        $latestAvailableVersionFromAttribute = self::getLatestAvailableVersionsFromAttribute($parentClass);
        return empty($latestAvailableVersionFromAttribute) ? $latestAvailableVersionFromPhpDoc : $latestAvailableVersionFromAttribute;
    }

    /**
     * @return float[]
     */
    public static function getSinceVersionsFromAttribute(BasePHPElement $element): array
    {
        $allSinceVersions = [];
        if (!empty($element->availableVersionsRangeFromAttribute)) {
            $allSinceVersions[] = $element->availableVersionsRangeFromAttribute['from'];
        }
        return $allSinceVersions;
    }

    /**
     * @return float[]
     */
    public static function getLatestAvailableVersionsFromAttribute(BasePHPElement $element): array
    {
        $latestAvailableVersions = [];
        if (!empty($element->availableVersionsRangeFromAttribute)) {
            $latestAvailableVersions[] = $element->availableVersionsRangeFromAttribute['to'];
        }
        return $latestAvailableVersions;
    }
}
