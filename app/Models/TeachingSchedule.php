<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Jadwal Mengajar. Its Kelas are a set (ADR 0006), and the set is the only
 * truth: `teaching_schedule_class_levels`, reachable through `classLevels`.
 * The single `class_level_id` column it grew up with is gone.
 */
class TeachingSchedule extends Model
{
    use HasFactory, HasUuids;

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

    /**
     * @deprecated Jendela deploy saja: `class_level` mengulang Kelas pertama
     * jadwal ini supaya SPA lama tidak rusak saat backend naik lebih dulu.
     * Dihapus pada rilis berikutnya, setelah frontend rilis — pembacanya
     * yang benar adalah `class_levels`.
     *
     * @var array<int, string>
     */
    protected $appends = ['class_level'];

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
            ->using(TeachingScheduleClassLevel::class)
            ->withTimestamps()
            ->inMasterOrder();
    }

    /**
     * The ids of its Kelas. Loads the relation once when the caller did not
     * eager-load it, so asking again — several times in one request — costs
     * nothing.
     *
     * @return array<int, string>
     */
    public function classLevelIds(): array
    {
        $this->loadMissing('classLevels');

        return $this->classLevels->pluck('id')->all();
    }

    /**
     * Its Kelas named together, as every screen, PDF and message prints them:
     * "Ibtida 2 + Tsanawiyah 1", or just "Tsanawiyah 1" for the ordinary
     * single-class schedule.
     */
    public function classLevelsLabel(): string
    {
        $this->loadMissing('classLevels');

        return ClassLevel::joinedLabel($this->classLevels);
    }

    /**
     * Makes the schedule hold exactly these Kelas. Called right after
     * save(), inside the same transaction, by whoever chose them — a
     * schedule without a Kelas has no meaning, so an empty list is refused
     * before it ever reaches here (TeachingScheduleService::resolveClassLevelIds()).
     *
     * @param  array<int, string>  $classLevelIds
     */
    public function syncClassLevels(array $classLevelIds): void
    {
        $this->classLevels()->sync(array_fill_keys(
            array_values(array_unique(array_filter($classLevelIds))),
            ['school_id' => $this->school_id],
        ));

        $this->unsetRelation('classLevels');
    }

    /**
     * @deprecated Jendela deploy saja (lihat $appends): Kelas pertama jadwal
     * ini dalam bentuk lama `class_level`. Setiap jalur yang menyerialkan
     * jadwal sudah memuat `classLevels`, jadi ini tidak menambah kueri.
     *
     * @return array<string, mixed>|null
     */
    public function getClassLevelAttribute(): ?array
    {
        $this->loadMissing('classLevels');

        return $this->classLevels->first()?->summary();
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
