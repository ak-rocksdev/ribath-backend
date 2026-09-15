<?php

namespace App\Services\Akademik;

use App\Exceptions\OutsideTeachingScopeException;

/**
 * What one user may work on in one Semester Akademik, as resolved by
 * TeachingScopeResolver: either everything (the user holds the "semua"
 * permission) or only his Cakupan Mengajar (the "milik sendiri"
 * permission alone, ADR 0004).
 */
final class TeachingScope
{
    /**
     * @param  array<string, true>|null  $classSubjectPairKeys  "<class_level_id>|<subject_book_id>" => true; null when unrestricted
     */
    private function __construct(
        private readonly ?array $classSubjectPairKeys,
    ) {}

    public static function unrestricted(): self
    {
        return new self(null);
    }

    /**
     * @param  iterable<array{class_level_id: string, subject_book_id: string}>  $classSubjectPairs
     */
    public static function limitedToClassSubjectPairs(iterable $classSubjectPairs): self
    {
        $classSubjectPairKeys = [];

        foreach ($classSubjectPairs as $classSubjectPair) {
            $classSubjectPairKeys[self::pairKey($classSubjectPair['class_level_id'], $classSubjectPair['subject_book_id'])] = true;
        }

        return new self($classSubjectPairKeys);
    }

    public function includesClassSubjectPair(string $classLevelId, string $subjectBookId): bool
    {
        return $this->classSubjectPairKeys === null
            || isset($this->classSubjectPairKeys[self::pairKey($classLevelId, $subjectBookId)]);
    }

    /**
     * @throws OutsideTeachingScopeException (403) the pair is outside the Cakupan Mengajar
     */
    public function assertIncludesClassSubjectPair(string $classLevelId, string $subjectBookId): void
    {
        if (! $this->includesClassSubjectPair($classLevelId, $subjectBookId)) {
            throw OutsideTeachingScopeException::forClassSubjectPair();
        }
    }

    private static function pairKey(string $classLevelId, string $subjectBookId): string
    {
        return $classLevelId.'|'.$subjectBookId;
    }
}
