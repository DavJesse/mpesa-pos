<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Modules\Auth\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Mpesa\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TransactionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_teller_only_sees_their_branch_transactions()
    {
        $branchA = Branch::factory()->create(['name' => 'Branch A', 'location' => 'NBI']);
        $branchB = Branch::factory()->create(['name' => 'Branch B', 'location' => 'MSA']);
        
        $contact = Contact::factory()->create(['name' => 'Test', 'phone_number' => '123']);
        
        // Transaction for Branch A
        Transaction::factory()->create([
            'branch_id' => $branchA->id,
            'contact_id' => $contact->id,
            'amount' => 100,
        ]);

        // Transaction for Branch B
        Transaction::factory()->create([
            'branch_id' => $branchB->id,
            'contact_id' => $contact->id,
            'amount' => 200,
        ]);

        $teller = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branchA->id
        ]);

        $response = $this->actingAs($teller)->getJson('/api/transactions');
        
        $response->assertStatus(200);
        
        // Should only have 1 transaction
        $this->assertCount(1, $response->json());
        
        // Ensure it's the 100 amount one
        $this->assertEquals(100, $response->json()[0]['amount']);
    }

    public function test_admin_sees_all_transactions()
    {
        $branchA = Branch::factory()->create(['name' => 'Branch A', 'location' => 'NBI']);
        $branchB = Branch::factory()->create(['name' => 'Branch B', 'location' => 'MSA']);
        
        $contact = Contact::factory()->create(['name' => 'Test', 'phone_number' => '123']);
        
        Transaction::factory()->create([
            'branch_id' => $branchA->id,
            'contact_id' => $contact->id,
            'amount' => 100,
        ]);

        Transaction::factory()->create([
            'branch_id' => $branchB->id,
            'contact_id' => $contact->id,
            'amount' => 200,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branchA->id
        ]);

        $response = $this->actingAs($admin)->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }
}
