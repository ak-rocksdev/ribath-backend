<?php

use App\Models\GradingTemplate;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;

function runBackfillSubjectBooksGradingTemplateIdMigration(): void
{
    (require database_path('migrations/2026_09_13_200004_backfill_subject_books_grading_template_id.php'))->up();
}

test('backfill assigns teori_kitab to a book without a template', function () {
    $school = School::factory()->create(['is_active' => true]);
    $teoriKitab = GradingTemplate::create(['school_id' => $school->id, 'code' => 'teori_kitab', 'name' => 'Teori/Kitab']);
    GradingTemplate::create(['school_id' => $school->id, 'code' => 'tahfizh', 'name' => 'Tahfizh']);

    $category = SubjectCategory::factory()->create(['school_id' => $school->id, 'slug' => 'nahwu']);
    $book = SubjectBook::factory()->create(['school_id' => $school->id, 'subject_category_id' => $category->id]);

    runBackfillSubjectBooksGradingTemplateIdMigration();

    expect($book->fresh()->grading_template_id)->toBe($teoriKitab->id);
});

test('backfill assigns tahfizh to a book under the tahfizh fann', function () {
    $school = School::factory()->create(['is_active' => true]);
    GradingTemplate::create(['school_id' => $school->id, 'code' => 'teori_kitab', 'name' => 'Teori/Kitab']);
    $tahfizhTemplate = GradingTemplate::create(['school_id' => $school->id, 'code' => 'tahfizh', 'name' => 'Tahfizh']);

    $tahfizhCategory = SubjectCategory::factory()->create(['school_id' => $school->id, 'slug' => 'tahfizh']);
    $book = SubjectBook::factory()->create(['school_id' => $school->id, 'subject_category_id' => $tahfizhCategory->id, 'title' => 'Some Tahfizh Book']);

    runBackfillSubjectBooksGradingTemplateIdMigration();

    expect($book->fresh()->grading_template_id)->toBe($tahfizhTemplate->id);
});

test('backfill assigns tahfizh to the book literally titled Tahfizh Al-Qur\'an regardless of category', function () {
    $school = School::factory()->create(['is_active' => true]);
    GradingTemplate::create(['school_id' => $school->id, 'code' => 'teori_kitab', 'name' => 'Teori/Kitab']);
    $tahfizhTemplate = GradingTemplate::create(['school_id' => $school->id, 'code' => 'tahfizh', 'name' => 'Tahfizh']);

    $otherCategory = SubjectCategory::factory()->create(['school_id' => $school->id, 'slug' => 'fiqh']);
    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $otherCategory->id,
        'title' => "Tahfizh Al-Qur'an",
    ]);

    runBackfillSubjectBooksGradingTemplateIdMigration();

    expect($book->fresh()->grading_template_id)->toBe($tahfizhTemplate->id);
});

test('backfill leaves books untouched for a school without grading_templates', function () {
    $school = School::factory()->create(['is_active' => true]);
    $category = SubjectCategory::factory()->create(['school_id' => $school->id]);
    $book = SubjectBook::factory()->create(['school_id' => $school->id, 'subject_category_id' => $category->id]);

    runBackfillSubjectBooksGradingTemplateIdMigration();

    expect($book->fresh()->grading_template_id)->toBeNull();
});

test('backfill does not overwrite a book that already has a template', function () {
    $school = School::factory()->create(['is_active' => true]);
    $teoriKitab = GradingTemplate::create(['school_id' => $school->id, 'code' => 'teori_kitab', 'name' => 'Teori/Kitab']);
    $tahfizhTemplate = GradingTemplate::create(['school_id' => $school->id, 'code' => 'tahfizh', 'name' => 'Tahfizh']);

    $category = SubjectCategory::factory()->create(['school_id' => $school->id]);
    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'grading_template_id' => $tahfizhTemplate->id,
    ]);

    runBackfillSubjectBooksGradingTemplateIdMigration();

    expect($book->fresh()->grading_template_id)->toBe($tahfizhTemplate->id);
});

test('backfill scopes updates per school', function () {
    $schoolA = School::factory()->create(['is_active' => true]);
    $schoolB = School::factory()->create(['is_active' => false]);

    $teoriKitabA = GradingTemplate::create(['school_id' => $schoolA->id, 'code' => 'teori_kitab', 'name' => 'Teori/Kitab']);
    GradingTemplate::create(['school_id' => $schoolB->id, 'code' => 'teori_kitab', 'name' => 'Teori/Kitab']);

    $categoryA = SubjectCategory::factory()->create(['school_id' => $schoolA->id]);
    $categoryB = SubjectCategory::factory()->create(['school_id' => $schoolB->id]);

    $bookA = SubjectBook::factory()->create(['school_id' => $schoolA->id, 'subject_category_id' => $categoryA->id]);
    $bookB = SubjectBook::factory()->create(['school_id' => $schoolB->id, 'subject_category_id' => $categoryB->id]);

    runBackfillSubjectBooksGradingTemplateIdMigration();

    $teoriKitabB = GradingTemplate::where('school_id', $schoolB->id)->where('code', 'teori_kitab')->firstOrFail();

    expect($bookA->fresh()->grading_template_id)->toBe($teoriKitabA->id)
        ->and($bookB->fresh()->grading_template_id)->toBe($teoriKitabB->id);
});
