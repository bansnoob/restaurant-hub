<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * branches.code is a 20-character column and the prefix of every order number.
 * The form allowed 32 characters, which MySQL strict mode rejects with a 500
 * (SQLite would not, so these tests assert on validation, not on the insert).
 */
class BranchCodeValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    private function create(string $code)
    {
        return $this->actingAs($this->owner)->post('/branches', [
            'code' => $code,
            'name' => 'Second Branch',
            'is_active' => '1',
        ]);
    }

    public function test_a_code_longer_than_the_column_is_refused(): void
    {
        $this->create(str_repeat('a', 21))->assertSessionHasErrors('code');
        $this->assertSame(0, Branch::count());
    }

    public function test_a_code_at_the_column_limit_is_accepted(): void
    {
        $this->create(str_repeat('a', 20))->assertSessionHasNoErrors();
        $this->assertSame(1, Branch::count());
    }

    public function test_a_code_with_spaces_is_refused(): void
    {
        $this->create('b 2')->assertSessionHasErrors('code');
    }

    public function test_editing_applies_the_same_limit(): void
    {
        $branch = Branch::factory()->create(['code' => 'main']);

        $this->actingAs($this->owner)->put("/branches/{$branch->id}", [
            'code' => str_repeat('a', 21),
            'name' => $branch->name,
            'is_active' => '1',
        ])->assertSessionHasErrors('code');

        $this->assertSame('main', $branch->fresh()->code);
    }
}
