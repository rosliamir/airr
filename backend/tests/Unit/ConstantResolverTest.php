<?php

namespace Tests\Unit;

use App\Models\Constant;
use App\Models\Project;
use App\Models\User;
use App\Services\ConstantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConstantResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_system_date_time_year(): void
    {
        $resolver = new ConstantResolver();

        $this->assertSame(now()->format('d/m/Y'), $resolver->resolve('{{SYSTEM:DATE}}'));
        $this->assertSame(now()->format('Y'), $resolver->resolve('{{SYSTEM:YEAR}}'));
    }

    public function test_resolves_system_user_name_and_email(): void
    {
        $resolver = new ConstantResolver();
        $user = User::factory()->create(['name' => 'Aisyah', 'email' => 'aisyah@airr.technology']);

        $text = $resolver->resolve('Hello {{SYSTEM:USER_NAME}} ({{SYSTEM:USER_EMAIL}})', null, $user);

        $this->assertSame('Hello Aisyah (aisyah@airr.technology)', $text);
    }

    public function test_page_and_total_page_no_pass_through_for_pdf_renderer(): void
    {
        $resolver = new ConstantResolver();

        $this->assertSame('{{PAGE}}', $resolver->resolve('{{SYSTEM:PAGE}}'));
        $this->assertSame('{{TOTAL_PAGE_NO}}', $resolver->resolve('{{SYSTEM:TOTAL_PAGE_NO}}'));
    }

    public function test_resolves_global_constant_value(): void
    {
        Constant::create(['scope' => 'global', 'type' => 'text', 'key' => 'COMPANY', 'label' => 'Company', 'value' => 'AIRR']);
        $resolver = new ConstantResolver();

        $this->assertSame('AIRR', $resolver->resolve('{{GLOBAL:COMPANY}}'));
    }

    public function test_resolves_project_scoped_constant_only_for_its_project(): void
    {
        $project = Project::create(['code' => 'P1', 'name' => 'P', 'status' => 'active']);
        Constant::create(['scope' => 'project', 'project_id' => $project->id, 'type' => 'text', 'key' => 'REF', 'label' => 'Ref', 'value' => 'PRJ-REF']);
        $resolver = new ConstantResolver();

        $this->assertSame('PRJ-REF', $resolver->resolve('{{PROJECT:REF}}', $project->id));
        $this->assertSame('', $resolver->resolve('{{PROJECT:REF}}', null));
    }

    public function test_resolves_data_constant_against_row_context(): void
    {
        Constant::create([
            'scope' => 'global', 'type' => 'data', 'key' => 'CUSTOMER', 'label' => 'Customer',
            'data_column' => 'customer_name',
        ]);
        $resolver = new ConstantResolver();

        $text = $resolver->resolve('{{DATA:CUSTOMER}}', null, null, ['customer_name' => 'Lembaga Zakat Selangor']);

        $this->assertSame('Lembaga Zakat Selangor', $text);
    }

    public function test_image_constant_renders_img_tag(): void
    {
        Constant::create(['scope' => 'system', 'type' => 'image', 'key' => 'LOGO', 'label' => 'Logo', 'image_path' => 'constant_images/logo.png']);
        $resolver = new ConstantResolver();

        $html = $resolver->resolve('{{SYSTEM:LOGO}}');

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('constants/', $html); // route path segment
    }
}
