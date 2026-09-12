<?php

use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\SubjectCategorySeeder;
use Database\Seeders\TahfizhSubjectBookSeeder;

beforeEach(function () {
    $this->seed(SchoolSeeder::class);
    $this->seed(ClassLevelSeeder::class);
});

test('subject category seeder creates the tahfizh fann', function () {
    $this->seed(SubjectCategorySeeder::class);

    $school = School::where('is_active', true)->firstOrFail();
    $tahfizh = SubjectCategory::where('school_id', $school->id)->where('slug', 'tahfizh')->first();

    expect($tahfizh)->not->toBeNull()
        ->and($tahfizh->name)->toBe('Tahfizh')
        ->and($tahfizh->color)->toBe('bg-emerald-100');
});

test('subject category seeder is idempotent', function () {
    $this->seed(SubjectCategorySeeder::class);
    $this->seed(SubjectCategorySeeder::class);

    $school = School::where('is_active', true)->firstOrFail();

    expect(SubjectCategory::where('school_id', $school->id)->where('slug', 'tahfizh')->count())->toBe(1);
});

test('tahfizh subject book seeder creates the Tahfizh Al-Qur\'an book for all class levels', function () {
    $this->seed(SubjectCategorySeeder::class);
    $this->seed(TahfizhSubjectBookSeeder::class);

    $school = School::where('is_active', true)->firstOrFail();
    $tahfizhCategory = SubjectCategory::where('school_id', $school->id)->where('slug', 'tahfizh')->firstOrFail();
    $book = SubjectBook::where('school_id', $school->id)->where('title', "Tahfizh Al-Qur'an")->first();

    $allClassLevelSlugs = ClassLevel::where('school_id', $school->id)->pluck('slug')->sort()->values()->all();

    expect($book)->not->toBeNull()
        ->and($book->subject_category_id)->toBe($tahfizhCategory->id)
        ->and(collect($book->class_levels)->sort()->values()->all())->toBe($allClassLevelSlugs)
        ->and($book->semesters)->toBe([1, 2])
        ->and($book->sessions_per_week)->toBe(6);
});

test('tahfizh subject book seeder is idempotent', function () {
    $this->seed(SubjectCategorySeeder::class);
    $this->seed(TahfizhSubjectBookSeeder::class);
    $this->seed(TahfizhSubjectBookSeeder::class);

    $school = School::where('is_active', true)->firstOrFail();

    expect(SubjectBook::where('school_id', $school->id)->where('title', "Tahfizh Al-Qur'an")->count())->toBe(1);
});
