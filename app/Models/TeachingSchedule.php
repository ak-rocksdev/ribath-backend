<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Jadwal Mengajar. Its Kelas are a set (ADR 0006), and the set is the only
 * truth: `teaching_schedule_class_levels`, reachable through `classLevels`.
 * The single `class_level_id` column it grew up with is gone.
 */
class TeachingSchedule extends Model
{
    use HasFactory, HasUuids;

    /**
     * The Kelas the next save must store, set by whoever chooses them —
     * TeachingScheduleService, or the factory in tests. Null means "leave
     * the Kelas as they are", so saving an unrelated field (`is_active`,
     * a replaced Ustadz) never touches the set.
     *
     * @var array<int, string>|null
     */
    public ?array $classLevelIdsToSync = null;

    const DAYS_OF_WEEK = [
        'monday', 'tuesday', 'wednesday', 'thursday',
        'friday', 'saturday', 'sunday',
    ];

    const EAGER_LOAD_RELATIONS = [
        'subjectBook:id,title,subject_category_id,sessions_per_week',
        'subjectBook.subjectCategory:id,name,color',
        'teacher:id,full_name,code',
        'timeSlot:id,code,label,type,start_time,end_time,sort_order',
        'classLevels:id,slug,label,category',
        'academicYear:id,name',
    ];

    protected $fillable = [
        'school_id',
        'academic_year_id',
        'semester',
        'day_of_week',
        'time_slot_id',
        'subject_book_id',
        'teacher_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function timeSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class);
    }

    /**
     * Every Kelas of this schedule, in the order the Kelas master defines —
     * "Ibtida 2 + Tsanawiyah 1" reads the same everywhere it is printed.
     */
    public function classLevels(): BelongsToMany
    {
        return $this->belongsToMany(ClassLevel::class, 'teaching_schedule_class_levels')
            ->orderBy('class_levels.sort_order')
            ->orderBy('class_levels.label');
    }

    /**
     * The ids of its Kelas, from the loaded relation when there is one.
     *
     * @return array<int, string>
     */
    public function classLevelIds(): array
    {
        if ($this->relationLoaded('classLevels')) {
            return $this->classLevels->pluck('id')->all();
        }

        return $this->classLevels()->pluck('class_levels.id')->all();
    }

    /**
     * Its Kelas named together, as every screen, PDF and message prints them:
     * "Ibtida 2 + Tsanawiyah 1", or just "Tsanawiyah 1" for the ordinary
     * single-class schedule.
     */
    public function classLevelsLabel(string $separator = ' + '): string
    {
        $this->loadMissing('classLevels');

        return $this->classLevels->pluck('label')->filter()->implode($separator);
    }

    protected static function booted(): void
    {
        // The dropped `class_level_id` column was NOT NULL: no schedule
        // could exist without a Kelas. Keep that invariant now that the
        // Kelas live in a join table written just after the insert.
        static::creating(function (TeachingSchedule $teachingSchedule) {
            if (array_filter($teachingSchedule->classLevelIdsToSync ?? []) === []) {
                throw new LogicException('Jadwal Mengajar dibuat bersama Kelas-nya: set classLevelIdsToSync sebelum save().');
            }
        });

        static::saved(function (TeachingSchedule $teachingSchedule) {
            if ($teachingSchedule->classLevelIdsToSync === null) {
                return;
            }

            $teachingSchedule->syncClassLevelRows($teachingSchedule->classLevelIdsToSync);
            $teachingSchedule->classLevelIdsToSync = null;
        });
    }

    /**
     * The Kelas ids the join table holds for this schedule right now.
     *
     * @return array<int, string>
     */
    private function storedClassLevelIds(): array
    {
        return DB::table('teaching_schedule_class_levels')
            ->where('teaching_schedule_id', $this->id)
            ->pluck('class_level_id')
            ->all();
    }

    /**
     * Make the join table hold exactly these Kelas: add what is missing,
     * drop what is gone. An empty list is read as "say nothing", never as
     * "drop them all": a schedule without a Kelas has no meaning.
     *
     * @param  array<int, string|null>  $classLevelIds
     */
    public function syncClassLevelRows(array $classLevelIds): void
    {
        $wantedIds = array_values(array_unique(array_filter($classLevelIds)));

        if ($wantedIds === []) {
            return;
        }

        $storedIds = $this->storedClassLevelIds();

        $removedIds = array_diff($storedIds, $wantedIds);

        if ($removedIds !== []) {
            DB::table('teaching_schedule_class_levels')
                ->where('teaching_schedule_id', $this->id)
                ->whereIn('class_level_id', $removedIds)
                ->delete();
        }

        $addedRows = [];
        $now = now();

        foreach (array_diff($wantedIds, $storedIds) as $addedId) {
            $addedRows[] = [
                'id' => (string) Str::uuid(),
                'school_id' => $this->school_id,
                'teaching_schedule_id' => $this->id,
                'class_level_id' => $addedId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($addedRows !== []) {
            DB::table('teaching_schedule_class_levels')->insert($addedRows);
        }

        $this->unsetRelation('classLevels');
    }

    public function subjectBook(): BelongsTo
    {
        return $this->belongsTo(SubjectBook::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
