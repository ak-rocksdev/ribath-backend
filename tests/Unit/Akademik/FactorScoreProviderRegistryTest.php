<?php

use App\Models\AcademicSemester;
use App\Models\GradingFactor;
use App\Models\Student;
use App\Services\Akademik\FactorScores\BatchFactorScoreProvider;
use App\Services\Akademik\FactorScores\FactorScore;
use App\Services\Akademik\FactorScores\FactorScoreContext;
use App\Services\Akademik\FactorScores\FactorScoreProvider;
use App\Services\Akademik\FactorScores\FactorScoreProviderRegistry;
use App\Services\Akademik\FactorScores\FactorScoreSource;
use Illuminate\Support\Collection;

function registryFactor(string $code, string $inputType): GradingFactor
{
    return (new GradingFactor)->setRawAttributes(['code' => $code, 'input_type' => $inputType]);
}

function registryContext(array $studentIds): FactorScoreContext
{
    return new FactorScoreContext(
        academicSemester: new AcademicSemester,
        academicYearId: 'academic-year-1',
        semester: 1,
        classLevelId: 'class-level-1',
        subjectBookId: 'subject-book-1',
        students: new Collection(array_map(fn (string $id) => (new Student)->setRawAttributes(['id' => $id]), $studentIds)),
    );
}

/**
 * A provider for the "tugas" factor that knows only student "ali".
 */
function tugasProviderKnowingOnlyAli(): FactorScoreProvider
{
    return new class implements FactorScoreProvider
    {
        public function supports(GradingFactor $factor): bool
        {
            return $factor->code === 'tugas';
        }

        public function scoresFor(GradingFactor $factor, FactorScoreContext $context): array
        {
            return ['ali' => new FactorScore(87.5, null)];
        }
    };
}

test('an unsupported factor yields an empty FactorScore for every student', function () {
    $scores = (new FactorScoreProviderRegistry([]))->scoresFor(
        registryFactor('absensi', GradingFactor::INPUT_TYPE_AUTO_FROM_ATTENDANCE),
        registryContext(['ali', 'zaid']),
    );

    expect(array_keys($scores))->toBe(['ali', 'zaid']);
    expect($scores['ali'])->toEqual(new FactorScore(null, null));
    expect($scores['zaid']->isMissing())->toBeTrue();
});

test('a supporting provider supplies the scores and students it omits stay empty', function () {
    $registry = new FactorScoreProviderRegistry([tugasProviderKnowingOnlyAli()]);

    $scores = $registry->scoresFor(registryFactor('tugas', GradingFactor::INPUT_TYPE_MANUAL_PERIODIC), registryContext(['ali', 'zaid']));

    expect($scores['ali'])->toEqual(new FactorScore(87.5, null));
    expect($scores['zaid'])->toEqual(new FactorScore(null, null));
    expect($registry->supports(registryFactor('tugas', GradingFactor::INPUT_TYPE_MANUAL_PERIODIC)))->toBeTrue();
    expect($registry->supports(registryFactor('absensi', GradingFactor::INPUT_TYPE_AUTO_FROM_ATTENDANCE)))->toBeFalse();
});

test('the registry accepts any iterable of providers (e.g. tagged services)', function () {
    $providers = (function () {
        yield tugasProviderKnowingOnlyAli();
    })();

    $scores = (new FactorScoreProviderRegistry($providers))
        ->scoresFor(registryFactor('tugas', GradingFactor::INPUT_TYPE_MANUAL_PERIODIC), registryContext(['ali']));

    expect($scores['ali']->score)->toBe(87.5);
});

test('a missing reason travels with an empty score', function () {
    $score = new FactorScore(null, 'Target hafalan belum ditetapkan.');

    expect($score->isMissing())->toBeTrue();
    expect($score->missingReason)->toBe('Target hafalan belum ditetapkan.');
    expect((new FactorScore(0.0, null))->isMissing())->toBeFalse();
});

test('the recap source follows the factor input type (R4)', function (string $inputType, FactorScoreSource $expectedSource) {
    expect(FactorScoreSource::forInputType($inputType))->toBe($expectedSource);
})->with([
    [GradingFactor::INPUT_TYPE_MANUAL_ONCE, FactorScoreSource::Manual],
    [GradingFactor::INPUT_TYPE_END_OF_SEMESTER_BULK, FactorScoreSource::Manual],
    [GradingFactor::INPUT_TYPE_MANUAL_PERIODIC, FactorScoreSource::Tugas],
    [GradingFactor::INPUT_TYPE_AUTO_FROM_ATTENDANCE, FactorScoreSource::Absensi],
    [GradingFactor::INPUT_TYPE_AUTO_FROM_LOG, FactorScoreSource::Hafalan],
]);

test('the source values are the recap API strings', function () {
    expect(array_map(fn (FactorScoreSource $source) => $source->value, FactorScoreSource::cases()))
        ->toBe(['manual', 'tugas', 'absensi', 'hafalan']);
});

test('scoresForFactors calls a batch provider once with all its factors and a plain provider once per factor', function () {
    $batchProvider = new class implements BatchFactorScoreProvider
    {
        /** @var array<int, array<int, string>> */
        public array $batchCalls = [];

        public int $singleCalls = 0;

        public function supports(GradingFactor $factor): bool
        {
            return $factor->input_type === GradingFactor::INPUT_TYPE_AUTO_FROM_LOG;
        }

        public function scoresFor(GradingFactor $factor, FactorScoreContext $context): array
        {
            $this->singleCalls++;

            return [];
        }

        public function scoresForFactors(array $factors, FactorScoreContext $context): array
        {
            $this->batchCalls[] = array_map(fn (GradingFactor $factor) => $factor->code, $factors);

            return [
                'target_hafalan' => ['ali' => new FactorScore(50.0, null)],
                'murajaah' => ['ali' => new FactorScore(70.0, null)],
            ];
        }
    };
    $plainProvider = new class implements FactorScoreProvider
    {
        /** @var array<int, string> */
        public array $calls = [];

        public function supports(GradingFactor $factor): bool
        {
            return $factor->input_type === GradingFactor::INPUT_TYPE_MANUAL_PERIODIC;
        }

        public function scoresFor(GradingFactor $factor, FactorScoreContext $context): array
        {
            $this->calls[] = $factor->code;

            return ['zaid' => new FactorScore(90.0, null)];
        }
    };

    $scoresByCode = (new FactorScoreProviderRegistry([$plainProvider, $batchProvider]))->scoresForFactors(
        collect([
            registryFactor('target_hafalan', GradingFactor::INPUT_TYPE_AUTO_FROM_LOG),
            registryFactor('tugas', GradingFactor::INPUT_TYPE_MANUAL_PERIODIC),
            registryFactor('kualitas_setoran', GradingFactor::INPUT_TYPE_AUTO_FROM_LOG),
            registryFactor('absensi', GradingFactor::INPUT_TYPE_AUTO_FROM_ATTENDANCE),
            registryFactor('murajaah', GradingFactor::INPUT_TYPE_AUTO_FROM_LOG),
            registryFactor('tugas_2', GradingFactor::INPUT_TYPE_MANUAL_PERIODIC),
        ]),
        registryContext(['ali', 'zaid']),
    );

    expect($batchProvider->batchCalls)->toBe([['target_hafalan', 'kualitas_setoran', 'murajaah']]);
    expect($batchProvider->singleCalls)->toBe(0);
    expect($plainProvider->calls)->toBe(['tugas', 'tugas_2']);

    // Given factor order; every santri present; anything not supplied is FactorScore(null, null).
    expect(array_keys($scoresByCode))->toBe(['target_hafalan', 'tugas', 'kualitas_setoran', 'absensi', 'murajaah', 'tugas_2']);
    expect($scoresByCode['target_hafalan'])->toEqual(['ali' => new FactorScore(50.0, null), 'zaid' => new FactorScore(null, null)]);
    expect($scoresByCode['kualitas_setoran'])->toEqual(['ali' => new FactorScore(null, null), 'zaid' => new FactorScore(null, null)]);
    expect($scoresByCode['absensi'])->toEqual(['ali' => new FactorScore(null, null), 'zaid' => new FactorScore(null, null)]);
    expect($scoresByCode['tugas_2'])->toEqual(['ali' => new FactorScore(null, null), 'zaid' => new FactorScore(90.0, null)]);
});
