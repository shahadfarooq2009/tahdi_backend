<?php



namespace Tests\Unit;



use App\Services\Admin\SchoolExcelImportService;

use App\Services\Game\SchoolUnitProgressService;

use PHPUnit\Framework\TestCase;

use ReflectionMethod;



class SchoolUnitSheetNamingTest extends TestCase

{

    public function test_sheet_name_pattern_matches_classic_unit_game_formats(): void

    {

        $parse = $this->parseSheetName();



        $this->assertSame(

            ['unit_name' => 'الوحدة 1', 'game_number' => 1],

            $parse('الوحدة1-اللعبة1')

        );

        $this->assertSame(

            ['unit_name' => 'الوحدة 5', 'game_number' => 2],

            $parse('الوحدة 5 - اللعبة 2')

        );

        $this->assertSame(

            ['unit_name' => 'الوحدة 1', 'game_number' => 1],

            $parse('الوحدة١-اللعبة١')

        );

        $this->assertSame(

            ['unit_name' => 'الوحدة الأولى', 'game_number' => 1],

            $parse('الوحدة الأولى - اللعبة 1')

        );

        $this->assertSame(

            ['unit_name' => 'اسم الوحدة', 'game_number' => 1],

            $parse('اسم الوحدة - اللعبة 1')

        );

    }



    public function test_sheet_name_pattern_matches_lesson_title_formats(): void

    {

        $parse = $this->parseSheetName();



        $this->assertSame(

            ['unit_name' => 'مبادئ وقيم نظام الحكم', 'game_number' => 2],

            $parse('مبادئ وقيم نظام الحكم 2')

        );

        $this->assertSame(

            ['unit_name' => 'الوحدة الوطنية', 'game_number' => 1],

            $parse('الوحدة الوطنية 1')

        );

        $this->assertSame(

            ['unit_name' => 'دور المواطن في الديمقراطية', 'game_number' => 2],

            $parse('دور المواطن في الديمقراطية 2')

        );

        $this->assertSame(

            ['unit_name' => 'اسم الوحدة', 'game_number' => 1],

            $parse('اسم الوحدة-1')

        );

        $this->assertSame(

            ['unit_name' => 'اسم الوحدة', 'game_number' => 1],

            $parse('اسم الوحدة - 1')

        );

        $this->assertSame(

            ['unit_name' => 'عنف وتخريب في وطننا !', 'game_number' => 2],

            $parse('عنف وتخريب في وطننا ! 2')

        );

    }



    public function test_sheet_name_pattern_rejects_invalid_names(): void

    {

        $parse = $this->parseSheetName();



        $this->assertNull($parse('الفهرس'));

        $this->assertNull($parse('ورقة عشوائية'));

        $this->assertNull($parse('Sheet1'));

    }



    public function test_remaining_games_is_total_minus_completed(): void

    {

        $service = new class extends SchoolUnitProgressService

        {

            public function calc(int $total, int $completed): int

            {

                return max(0, $total - $completed);

            }

        };



        $this->assertSame(2, $service->calc(2, 0));

        $this->assertSame(1, $service->calc(2, 1));

        $this->assertSame(0, $service->calc(2, 2));

    }



    /**

     * @return callable(string): ?array{unit_name: string, game_number: int}

     */

    private function parseSheetName(): callable

    {

        $service = new SchoolExcelImportService();

        $method = new ReflectionMethod(SchoolExcelImportService::class, 'parseUnitGameFromSheetName');

        $method->setAccessible(true);



        return fn (string $sheetName): ?array => $method->invoke($service, $sheetName);

    }

}


