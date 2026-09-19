<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TimeSlot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * @extends Factory<TeachingSchedule>
 */
class TeachingScheduleFactory extends Factory
{
    protected $model = TeachingSchedule::class;

    /**
     * The Kelas chosen for the schedule being built, keyed by the object id
     * of that schedule: `class_level_ids` names no column, so it is lifted
     * out of the attributes here and written to the join table once the row
     * exists (production does the same, explicitly, in
     * TeachingScheduleService).
     *
     * @var array<int, array<int, string>>
     */
    private array $classLevelIdsOf = [];

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'semester' => fake()->randomElement([1, 2]),
            'day_of_week' => fake()->randomElement(TeachingSchedule::DAYS_OF_WEEK),
            'time_slot_id' => TimeSlot::factory(),
            // The Kelas of a schedule are a set in its own table (ADR 0006),
            // not a column: newModel() below turns this key into the rows.
            'class_level_ids' => [ClassLevel::factory()],
            'subject_book_id' => SubjectBook::factory(),
            'teacher_id' => Teacher::factory(),
            'is_active' => true,
        ];
    }

    public function newModel(array $attributes = []): TeachingSchedule
    {
        $classLevels = Arr::wrap(Arr::pull($attributes, 'class_level_ids', []));

        $schedule = parent::newModel($attributes);
        $this->classLevelIdsOf[spl_object_id($schedule)] = array_map($this->resolveClassLevelId(...), $classLevels);

        return $schedule;
    }

    /**
     * @param  Collection<int, TeachingSchedule>  $results
     */
    protected function store(Collection $results)
    {
        parent::store($results);

        foreach ($results as $schedule) {
            $classLevelIds = Arr::pull($this->classLevelIdsOf, spl_object_id($schedule), []);

            if ($classLevelIds !== []) {
                $schedule->syncClassLevels($classLevelIds);
            }
        }
    }

    private function resolveClassLevelId(ClassLevel|Factory|string $classLevel): string
    {
        return match (true) {
            $classLevel instanceof ClassLevel => $classLevel->id,
            $classLevel instanceof Factory => $classLevel->create()->id,
            default => $classLevel,
        };
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
