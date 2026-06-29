<?php

namespace Database\Factories;

use App\Models\OrchestrationRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrchestrationRunFactory extends Factory
{
    protected $model = OrchestrationRun::class;

    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'prompt'     => $this->faker->sentence(10),
            'status'     => OrchestrationRun::STATUS_QUEUED,
        ];
    }

    public function complete(): static
    {
        return $this->state([
            'status'            => OrchestrationRun::STATUS_COMPLETE,
            'output_html'       => '<h2>Report</h2><p>Generated.</p>',
            'executive_summary' => 'Summary of findings.',
            'total_duration_ms' => $this->faker->numberBetween(1000, 30000),
        ]);
    }
}
