<?php

namespace Tests\Feature\RequestText;

use App\Services\RequestText\PlaceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

class PlaceResolverTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocationsAndHospitals(withUpazilas: false);
    }

    private function resolve(string $text): array
    {
        $r = app(PlaceResolver::class)->resolve($text);

        return [$r['hospital']?->name, $r['district']?->name, $r['warnings']];
    }

    public function test_bangla_names_with_case_endings(): void
    {
        [$hospital, $district] = $this->resolve('আজ রাতে ঢাকা মেডিকেলে ২ ব্যাগ রক্ত লাগবে');
        $this->assertSame('Dhaka Medical College Hospital', $hospital);
        $this->assertSame('ঢাকা', $district);

        [$hospital] = $this->resolve('এবি+ রক্ত লাগবে, স্কয়ারে');
        $this->assertSame('Square Hospital', $hospital);
    }

    public function test_district_comes_from_the_hospital_when_the_text_names_none(): void
    {
        [$hospital, $district] = $this->resolve('Need O+ at Evercare');
        $this->assertSame('Evercare Hospital Dhaka', $hospital);
        $this->assertSame('ঢাকা', $district, 'needs the seeder fix: Evercare used to sit in Cumilla');
    }

    public function test_short_english_name_and_spelling_variants(): void
    {
        $this->assertSame('Sylhet MAG Osmani Medical College Hospital', $this->resolve('O+ 1 unit, Sylhet MAG Osmani Medical College')[0]);
        $this->assertSame('Mugda Medical College and Hospital', $this->resolve('dengue patient at Mugda Medical College')[0]);
        // হসপিটাল and হাসপাতাল are the same word
        $this->assertSame('Bangabandhu Sheikh Mujib Medical University', $this->resolve('আর্জেন্ট! পিজি হাসপাতাল')[0]);
    }

    public function test_hospital_in_another_district_than_the_text_says_is_dropped(): void
    {
        [$hospital, $district, $warnings] = $this->resolve('AB+ rokto lagbe 1 bag, Evercare Chattogram');

        $this->assertNull($hospital, 'the seeded Evercare is in Dhaka; this message is about Chattogram');
        $this->assertSame('চট্টগ্রাম', $district);
        $this->assertNotEmpty($warnings);
    }

    public function test_english_district_aliases(): void
    {
        $this->assertSame('বগুড়া', $this->resolve('O+ needed in Bogra')[1]);
        $this->assertSame('যশোর', $this->resolve('B- needed, Jashore')[1]);
        $this->assertSame('চট্টগ্রাম', $this->resolve('2 bag AB- blood dorkar, Chittagong')[1]);
    }

    public function test_two_districts_and_unknown_places_resolve_to_nothing(): void
    {
        [, $district, $warnings] = $this->resolve('রোগী নাটোর থেকে, এখন পাবনা সদরে ভর্তি');
        $this->assertNull($district);
        $this->assertStringContainsString('several districts', $warnings[0]);

        // An area of Dhaka is not a district, and an unknown clinic is not a seeded hospital.
        $this->assertSame([null, null], array_slice($this->resolve('A+ blood lagbe, Kidney Foundation, Mirpur'), 0, 2));
    }

    public function test_english_names_need_word_boundaries(): void
    {
        $this->assertNull($this->resolve('we are reunited at last')[0], '"united" inside a word is not United Hospital');
    }
}
